<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to obtain
 * it through the world-wide-web, please send an email to
 * contact@novalo.fr so we can send you a copy immediately.
 *
 * @author    Novalo <contact@novalo.fr>
 * @copyright 2026 Novalo
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License v3.0 (AFL-3.0)
 */
declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\SQLMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Cohere\Cohere;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\OpenAI\Image\OpenAIImage;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\XAI\Grok;
use NeuronAI\RAG\RAG;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Persistence\DatabasePersistence;

/*
 * SomcpAssistantChat - one employee's conversations with the assistant built
 * into the back office, run on Neuron AI.
 *
 * Three rules shape everything here.
 *
 * One model call per HTTP request. Shared hosting cuts a PHP request after
 * thirty seconds or so, and a model that calls three tools in a row would run
 * past that. So every tool of the shop is handed to Neuron as a deferred tool:
 * when the model asks for one, the run pauses, the browser asks for the next
 * step, and that request runs the tool and makes the next model call. The
 * chat shows the work going on while the steps follow each other.
 *
 * Nothing changes the shop without a click. A tool that is not read-only is
 * gated: the run pauses on an approval request, the chat shows what would be
 * done, and only an explicit approval lets the call through. Neuron treats
 * silence as a refusal, which is the right default.
 *
 * The employee's own rights, nothing more. The tools offered are those the
 * employee could use through an assistant connected over MCP, checked again
 * when a call runs, and each conversation belongs to the employee who opened
 * it: Neuron keys everything by thread id and checks no ownership, so this
 * class never opens a thread it did not find under the employee's id.
 *
 * Other assistants the merchant switched on are reachable through one tool,
 * which hands them a piece of work and brings back their answer. They see the
 * task and nothing else - not the conversation, not the shop's tools - so a
 * change still goes through the leading assistant and its approval.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpAssistantChat
{
    /** @var string The tool that hands work to another assistant. */
    public const DELEGATE_TOOL = 'somcp_ask_assistant';

    /** @var int Tool calls one answer may chain, a guard against a model going round in circles. */
    private const MAX_TOOL_RUNS = 25;

    /**
     * @var int Tokens of history kept before the model call. Past this, the
     *          oldest messages are summarized rather than dropped.
     */
    private const CONTEXT_WINDOW = 64000;

    /** @var int History size that triggers a summary of the oldest messages. */
    private const SUMMARIZE_AT = 48000;

    /** @var int Conversations listed in the chat. */
    private const MAX_THREADS = 30;

    /** @var int Files one message may carry. */
    public const MAX_FILES = 5;

    /** @var int Largest file, in bytes. Images and PDFs may travel base64-encoded, a third larger. */
    public const MAX_FILE_BYTES = 5242880;

    /**
     * @var int Seconds an attached file is kept, and its signed link valid.
     *
     * A conversation sends its whole history to the model on every turn,
     * links included, and a provider that finds one dead refuses the request.
     * A week covers a conversation picked up again a few days later; past it,
     * the employee starts a new one.
     */
    public const FILE_TTL = 604800;

    /** @var int Characters of a text file handed to the model. */
    private const MAX_TEXT_CHARS = 200000;

    /** @var string Opens the text block that names an attached file, so the chat can draw it as a file. */
    private const FILE_MARKER = '[File: ';

    /** @var string Names an image another assistant generated, in the result handed back. */
    private const GENERATED_MARKER = '[Generated image: ';

    /**
     * @var string How Neuron AI's Summarization middleware opens the message it
     *             puts in place of the oldest ones. It is stored as a message
     *             of the employee, so the chat must recognise it to draw it as
     *             a note rather than as words the employee never wrote.
     */
    private const SUMMARY_MARKER = '## Previous conversation summary:';

    /**
     * @var int Seconds a conversation stays marked as busy. A move clears the
     *          mark when it ends; this only frees a conversation whose PHP
     *          process died mid-move.
     */
    private const BUSY_TTL = 300;

    /**
     * What an employee may attach, and how each kind reaches the model. The
     * deposit store checks every file against its bytes, as it does for the
     * deposit page: this list only narrows what the chat offers.
     *
     * @var array<string, string>
     */
    private const ATTACHABLE = [
        'jpg' => 'image',
        'jpeg' => 'image',
        'png' => 'image',
        'gif' => 'image',
        'webp' => 'image',
        'pdf' => 'pdf',
        'txt' => 'text',
        'csv' => 'text',
        'json' => 'text',
        'xml' => 'text',
    ];

    /**
     * @var array<string, array<int, string>> Providers that fetch a file from a link
     *                                        themselves, by kind. The others get the bytes.
     */
    private const LINKABLE = [
        'image' => ['anthropic', 'openai', 'mistral'],
        'pdf' => ['anthropic'],
    ];

    /** @var Employee */
    private $employee;

    /** @var int */
    private $idShop;

    /** @var string Back office controller the chat is open on, e.g. AdminOrders. */
    private $page = '';

    /** @var PDO|null */
    private static $pdo;

    /** @var AIProviderInterface|null Stands in for every provider, in the shop tests only. */
    private static $providerOverride;

    /**
     * @param Employee $employee
     * @param int $idShop
     */
    public function __construct(Employee $employee, int $idShop)
    {
        $this->employee = $employee;
        $this->idShop = $idShop;
    }

    /**
     * Tells the model which back office page the employee has open, so "this
     * order" or "here" has a meaning. Sent by the browser, hence kept to the
     * shape of a controller name.
     *
     * @param string $page
     */
    public function openOn(string $page): void
    {
        $this->page = preg_match('/^[A-Za-z0-9_]{1,64}$/', $page) === 1 ? $page : '';
    }

    /**
     * Makes every assistant answer through the given provider. The shop tests
     * drive whole conversations with Neuron AI's fake provider this way, with
     * no network and no key; nothing else calls it.
     *
     * @param AIProviderInterface|null $provider null to go back to the real ones
     */
    public static function overrideProvider(?AIProviderInterface $provider): void
    {
        self::$providerOverride = $provider;
    }

    /**
     * Whether the framework is there. A copy of the module taken straight from
     * the repository has no vendor folder until Composer runs; the chat then
     * stays hidden instead of failing on the first message.
     *
     * @return bool
     */
    public static function available(): bool
    {
        return class_exists(Agent::class);
    }

    /**
     * The employee's latest conversations, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function threads(): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `thread_id`, `title`, `id_assistant`, `date_upd` FROM `' . _DB_PREFIX_ . 'somcp_assistant_thread`'
            . ' WHERE `id_employee` = ' . (int) $this->employee->id . ' AND `id_shop` = ' . (int) $this->idShop
            . ' ORDER BY `date_upd` DESC, `id_thread` DESC LIMIT ' . self::MAX_THREADS
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * A conversation of this employee, or null - whether it does not exist or
     * belongs to somebody else, the answer is the same.
     *
     * @param string $threadId
     *
     * @return array<string, mixed>|null
     */
    public function thread(string $threadId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $threadId)) {
            return null;
        }

        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'somcp_assistant_thread`'
            . ' WHERE `thread_id` = \'' . pSQL($threadId) . '\' AND `id_employee` = ' . (int) $this->employee->id
            . ' AND `id_shop` = ' . (int) $this->idShop
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param int $idAssistant 0 for whichever assistant leads
     *
     * @return array<string, mixed>
     */
    public function newThread(int $idAssistant): array
    {
        $threadId = bin2hex(random_bytes(16));
        Db::getInstance()->insert('somcp_assistant_thread', [
            'thread_id' => pSQL($threadId),
            'id_employee' => (int) $this->employee->id,
            'id_shop' => $this->idShop,
            'id_assistant' => max(0, $idAssistant),
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);

        return (array) $this->thread($threadId);
    }

    /**
     * Deletes a conversation of this employee, with what Neuron kept for it.
     *
     * @param array<string, mixed> $thread
     */
    public function deleteThread(array $thread): void
    {
        // Read before the messages go: the files are named in them.
        $references = $this->references($thread);
        try {
            $this->agent($thread, $this->assistantOf($thread) ?? [])->resetConversation();
        } catch (Throwable $e) {
            // The rows below go anyway: a conversation the framework cannot
            // reset must still disappear from the employee's list.
        }
        Db::getInstance()->delete(
            'somcp_assistant_thread',
            '`thread_id` = \'' . pSQL((string) $thread['thread_id']) . '\' AND `id_employee` = ' . (int) $this->employee->id
        );

        // What the conversation left elsewhere goes with it: the files attached
        // or generated in it, which would otherwise wait a week for the purge,
        // and what the long-term memory kept of it.
        foreach ($references as $reference) {
            $deposit = SomcpUpload::find($reference, (int) $this->employee->id);
            if ($deposit !== null) {
                SomcpUpload::drop($deposit);
            }
        }
        SomcpAssistantMemory::forget($this->idShop, (string) $thread['thread_id']);
    }

    /**
     * The deposits a conversation refers to: the images generated in it, from
     * the thread's own list, and the files attached to its messages, which the
     * stored messages name as "upload_ref: <reference>".
     *
     * @param array<string, mixed> $thread
     *
     * @return array<int, string>
     */
    private function references(array $thread): array
    {
        $references = array_map(static function (array $file): string {
            return (string) $file['ref'];
        }, $this->generated($thread));

        $texts = [];
        $rows = Db::getInstance()->executeS(
            'SELECT `content` FROM `' . _DB_PREFIX_ . 'somcp_assistant_message`'
            . ' WHERE `thread_id` = \'' . pSQL((string) $thread['thread_id']) . '\''
        );
        foreach (is_array($rows) ? $rows : [] as $row) {
            $texts[] = (string) $row['content'];
        }

        foreach ($texts as $text) {
            if (preg_match_all('/' . preg_quote(SomcpUpload::ARG_REF, '/') . '"?\s*[:=]\s*"?([A-Za-z0-9_-]{16,})/', $text, $found)) {
                $references = array_merge($references, $found[1]);
            }
        }

        return array_values(array_unique($references));
    }

    /**
     * Changes the assistant a conversation talks to.
     *
     * @param array<string, mixed> $thread
     * @param int $idAssistant
     *
     * @return array<string, mixed>
     */
    public function useAssistant(array $thread, int $idAssistant): array
    {
        Db::getInstance()->update(
            'somcp_assistant_thread',
            ['id_assistant' => max(0, $idAssistant)],
            '`id_thread` = ' . (int) $thread['id_thread']
        );

        return (array) $this->thread((string) $thread['thread_id']);
    }

    /**
     * The merchant's message, and the model's first move.
     *
     * @param array<string, mixed> $thread
     * @param string $text
     * @param array<int, array{name:string, tmp_name:string, size:int, error:int}> $files attached from the employee's computer
     *
     * @return array<string, mixed> the conversation as the chat draws it
     */
    public function send(array $thread, string $text, array $files = []): array
    {
        $text = trim($text);
        if ($text === '' && $files === []) {
            return $this->view($thread);
        }

        $refused = '';
        $blocks = $this->attachments($files, $this->assistantOf($thread), $refused);
        if ($refused !== '') {
            return $this->view($thread, $refused);
        }
        if ($text === '') {
            $text = '(files attached)';
        }
        if ((string) $thread['title'] === '') {
            Db::getInstance()->update(
                'somcp_assistant_thread',
                ['title' => pSQL(Tools::substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 80))],
                '`id_thread` = ' . (int) $thread['id_thread']
            );
        }

        $content = array_merge([new TextContent(Tools::substr($text, 0, 20000))], $blocks);
        $names = [];
        foreach ($files as $file) {
            $names[] = Tools::substr(basename(str_replace('\\', '/', (string) $file['name'])), 0, 120);
        }

        return $this->advance($thread, static function (Agent $agent) use ($content): AgentState {
            return $agent->chat(new UserMessage($content));
        }, false, ['text' => $text === '(files attached)' ? '' : Tools::substr($text, 0, 20000), 'files' => $names]);
    }

    /**
     * The files of a message, kept on the shop and shown to the model.
     *
     * Every file becomes a deposit in the store the deposit page uses - same
     * formats, same check of the bytes, same purge - with a reference a tool
     * can be given to use the file itself. Then the model is shown it: an
     * image or a PDF through a signed link when the shop is reachable from the
     * internet and the provider fetches links, as bytes otherwise; a text file
     * as text, which every provider reads. Each file is preceded by its name
     * and its reference. One file refused stops the whole message: a file the
     * employee meant to send and that silently did not go would be worse than
     * an error.
     *
     * @param array<int, array{name:string, tmp_name:string, size:int, error:int}> $files
     * @param array<string, mixed>|null $assistant the assistant that will read them
     * @param string $refused set to a sentence for the employee when a file is refused
     *
     * @return array<int, TextContent|ImageContent|FileContent>
     */
    private function attachments(array $files, ?array $assistant, string &$refused = ''): array
    {
        $refused = '';
        if (count($files) > self::MAX_FILES) {
            $refused = self::t('Up to 5 files per message.');

            return [];
        }

        $idEmployee = (int) $this->employee->id;
        $provider = (string) ($assistant['provider'] ?? '');
        $blocks = [];
        foreach ($files as $file) {
            $name = Tools::substr(basename(str_replace('\\', '/', (string) $file['name'])), 0, 120);
            $extension = Tools::strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            $kind = self::ATTACHABLE[$extension] ?? '';
            $refusal = self::t('This file cannot be sent: only images, PDF and text files up to 5 MB are accepted.') . ' ' . $name;
            if ($kind === '') {
                $refused = $refusal;

                return [];
            }

            $reference = SomcpUpload::invite(
                $idEmployee,
                'Attached in the built-in assistant',
                [$extension],
                SomcpUpload::CHAT_MODULE,
                ['files' => 1, 'bytes' => self::MAX_FILE_BYTES],
                self::FILE_TTL
            );
            $invitation = $reference !== '' ? SomcpUpload::find($reference, $idEmployee) : null;
            $problem = $invitation !== null ? SomcpUpload::accept($invitation, $file) : 'no_store';
            $stored = $problem === '' ? SomcpUpload::peek($reference, $idEmployee) : null;
            if ($stored === null) {
                if ($invitation !== null) {
                    SomcpUpload::drop($invitation);
                }
                $refused = $refusal;

                return [];
            }

            $blocks[] = new TextContent(self::FILE_MARKER . $name . ', ' . SomcpUpload::ARG_REF . ': ' . $reference . ']');
            $linked = in_array($provider, self::LINKABLE[$kind] ?? [], true) && self::shopIsPublic();
            if ($kind === 'image') {
                $blocks[] = $linked
                    ? new ImageContent(self::fileUrl($reference), SourceType::URL, $stored['mimeType'])
                    : new ImageContent(base64_encode($stored['contents']), SourceType::BASE64, $stored['mimeType']);
            } elseif ($kind === 'pdf') {
                $blocks[] = $linked
                    ? new FileContent(self::fileUrl($reference), SourceType::URL, $stored['mimeType'], $name)
                    : new FileContent(base64_encode($stored['contents']), SourceType::BASE64, $stored['mimeType'], $name);
            } else {
                // Invalid UTF-8 would make the whole request unreadable to
                // the provider: a file saved in an older encoding is
                // converted rather than refused.
                $text = $stored['contents'];
                if (!mb_check_encoding($text, 'UTF-8')) {
                    $text = (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
                }
                $blocks[] = new TextContent(Tools::substr($text, 0, self::MAX_TEXT_CHARS));
            }
        }

        return $blocks;
    }

    /**
     * The signed link a provider fetches an attached file from.
     *
     * It carries the hash of the file's reference, never the reference that
     * redeems it, and expires with the file. The signature is what makes it
     * unguessable; without it, the address answers nothing.
     *
     * @param string $reference
     *
     * @return string
     */
    private static function fileUrl(string $reference): string
    {
        $hash = SomcpRandom::hash($reference);
        $expires = time() + self::FILE_TTL;

        return Somcp::legacyContext()->link->getModuleLink('somcp', 'file', [
            'f' => $hash,
            'e' => $expires,
            's' => self::signature($hash, $expires),
        ], true);
    }

    /**
     * @param string $hash
     * @param int $expires
     *
     * @return string
     */
    public static function signature(string $hash, int $expires): string
    {
        return hash_hmac('sha256', $hash . '|' . $expires, self::linkKey());
    }

    /**
     * The key file links are signed with, minted the first time one is
     * needed and kept for all shops. Its own key, so that emptying it kills
     * every link at once and nothing else.
     *
     * @return string
     */
    private static function linkKey(): string
    {
        $key = (string) Configuration::getGlobalValue(SomcpAssistant::LINK_KEY);
        if (strlen($key) < 32) {
            $key = SomcpRandom::token(32);
            Configuration::updateGlobalValue(SomcpAssistant::LINK_KEY, $key);
        }

        return $key;
    }

    /**
     * Whether a provider's servers can reach this shop: not a local name, and
     * every address it resolves to on the public internet. A shop behind a
     * tunnel or a firewall may still be unreachable; the provider then says
     * so, and the employee sees it.
     *
     * @return bool
     */
    private static function shopIsPublic(): bool
    {
        $host = (string) parse_url(Somcp::legacyContext()->shop->getBaseURL(true), PHP_URL_HOST);
        if ($host === '' || $host === 'localhost' || preg_match('/\.(local|localhost|test|internal|lan)$/', $host)) {
            return false;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return $addresses !== [];
    }

    /**
     * The next step of a paused run: the tools the model asked for are run
     * here, with the employee's rights, and their results go back to it.
     *
     * @param array<string, mixed> $thread
     *
     * @return array<string, mixed>
     */
    public function step(array $thread): array
    {
        return $this->advance($thread, function (Agent $agent, array $assistant) use ($thread): ?AgentState {
            $run = $agent->inspect();
            if ($run === null || !$run->interrupt instanceof ToolResultsRequest) {
                return null;
            }

            $done = $run->interrupt->getResults();
            $results = [];
            foreach ($run->interrupt->getToolCalls() as $call) {
                $callId = (string) $call->getCallId();
                if ($callId !== '' && !isset($done[$callId])) {
                    $results[$callId] = $this->execute($call, $assistant, $thread);
                }
            }

            return $agent->submitToolResults($results)->run();
        }, true);
    }

    /**
     * The merchant's answer to an approval request.
     *
     * @param array<string, mixed> $thread
     * @param array<string, string> $decisions call id => approve or reject
     *
     * @return array<string, mixed>
     */
    public function decide(array $thread, array $decisions): array
    {
        $clean = [];
        foreach ($decisions as $callId => $decision) {
            $clean[(string) $callId] = $decision === 'approve' ? 'approve' : ['reject', 'The employee refused this change.'];
        }

        return $this->advance($thread, static function (Agent $agent) use ($clean): ?AgentState {
            return $clean === [] ? null : $agent->submitApprovalDecisions($clean)->run();
        });
    }

    /**
     * The conversation as the chat draws it: the messages, and what the run
     * waits for.
     *
     * @param array<string, mixed> $thread
     * @param string $error a message for the employee, shown under the conversation
     *
     * @return array<string, mixed>
     */
    public function view(array $thread, string $error = ''): array
    {
        $assistant = $this->assistantOf($thread);
        $titles = $this->toolTitles();

        $messages = [];
        foreach ($this->store()->loadAll((string) $thread['thread_id'], 300) as $message) {
            $drawn = $this->draw($message, $titles);
            if ($drawn !== null) {
                $messages[] = $drawn;
            }
        }

        $status = 'idle';
        $actions = [];
        $running = $this->running($thread);
        $busy = $running !== null;
        // The framework stores the message with the answer, at the end of the
        // move: until then a page reloaded meanwhile would show the answer
        // coming without the question. The copy kept on the conversation fills
        // the gap, unless the stored one is already the last thing said - the
        // same text sent twice in a row then shows once until the answer.
        $asked = $running['pending'] ?? null;
        if ($asked !== null) {
            $last = end($messages);
            if (!is_array($last) || $last['role'] !== 'user' || $last['text'] !== $asked['text']) {
                $messages[] = ['role' => 'user', 'text' => $asked['text'], 'files' => $asked['files']];
            }
        }
        try {
            // Reading the run needs no provider: no model is called here.
            $run = $this->agent($thread, [])->inspect();
            if ($run !== null && $run->interrupt instanceof ApprovalRequest) {
                $status = 'approval';
                foreach ($run->interrupt->getActions() as $action) {
                    if ($action->isPending()) {
                        $actions[] = [
                            'id' => $action->id,
                            'title' => $titles[$action->name] ?? $action->name,
                            'inputs' => $action->inputs,
                        ];
                    }
                }
            } elseif ($run !== null && $run->interrupt instanceof ToolResultsRequest) {
                $status = 'working';
            }
            // A move still running - from before a page reload, or in another
            // tab - is working too, whatever the stored run said before it.
            if ($status !== 'approval' && $busy) {
                $status = 'working';
            }
        } catch (Throwable $e) {
            SomcpLogger::log('assistant_failed', SomcpLogger::OUTCOME_FAILURE, Tools::substr($e->getMessage(), 0, 200), '', (int) $this->employee->id);
        }

        return [
            'thread_id' => (string) $thread['thread_id'],
            'id_assistant' => (int) $thread['id_assistant'],
            'assistant' => $assistant !== null ? (string) $assistant['label'] : '',
            'messages' => $messages,
            'status' => $status,
            // Tells the chat to wait before asking again, rather than ask for
            // the next step at once.
            'busy' => $busy,
            'actions' => $actions,
            'error' => $error,
            'spent' => round(array_sum(SomcpAssistant::monthCosts()), 2),
            'budget' => SomcpAssistant::budget(),
        ];
    }

    /**
     * Runs one move on the conversation and answers with its new state. Every
     * failure ends up as a sentence for the employee: the chat must never be
     * left spinning on an error it was not told about.
     *
     * Only one move runs on a conversation at a time. The chat keeps going
     * after a page reload or in a second tab, and asking for the next step
     * while the previous one is still with the model would pay for the same
     * answer twice and write it twice; a step asked meanwhile just reports
     * that the conversation is busy, and the chat asks again a moment later.
     *
     * @param array<string, mixed> $thread
     * @param callable $move function(Agent $agent, array $assistant): ?AgentState
     * @param bool $quiet true for a step the chat asks for by itself: finding
     *                    the conversation busy is then no error to show
     * @param array{text:string, files:array<int, string>}|null $asked the message this move answers,
     *                                                                 kept on the conversation while it runs
     *
     * @return array<string, mixed>
     */
    private function advance(array $thread, callable $move, bool $quiet = false, ?array $asked = null): array
    {
        $assistant = $this->assistantOf($thread);
        if ($assistant === null) {
            return $this->view($thread, self::t('No assistant is switched on. Add one in the module settings.'));
        }
        if (SomcpAssistant::overBudget()) {
            return $this->view($thread, self::t('The monthly spending cap is reached. Raise it in the module settings to keep chatting.'));
        }
        if (!$this->claim($thread)) {
            return $this->view($thread, $quiet ? '' : self::t('The assistant is still answering. Wait for its reply.'));
        }
        if ($asked !== null) {
            Db::getInstance()->update(
                'somcp_assistant_thread',
                ['pending' => pSQL((string) json_encode($asked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true)],
                '`id_thread` = ' . (int) $thread['id_thread'],
                0,
                true
            );
        }

        try {
            $error = $this->move($thread, $move, $assistant);
        } finally {
            Db::getInstance()->update(
                'somcp_assistant_thread',
                ['busy_until' => null, 'pending' => null],
                '`id_thread` = ' . (int) $thread['id_thread'],
                0,
                true
            );
        }

        // Drawn once the mark is cleared, or the answer would read as busy.
        return $this->view($thread, $error);
    }

    /**
     * Marks the conversation busy, unless a move already holds it. One UPDATE
     * both checks and takes, so two requests arriving together cannot both win.
     *
     * @param array<string, mixed> $thread
     *
     * @return bool
     */
    private function claim(array $thread): bool
    {
        $db = Db::getInstance();
        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'somcp_assistant_thread`'
            . ' SET `busy_until` = DATE_ADD(NOW(), INTERVAL ' . (int) self::BUSY_TTL . ' SECOND)'
            . ' WHERE `id_thread` = ' . (int) $thread['id_thread']
            . ' AND (`busy_until` IS NULL OR `busy_until` < NOW())'
        );

        return (int) $db->Affected_Rows() === 1;
    }

    /**
     * The move running on the conversation right now, with the message it
     * answers, or null when none is.
     *
     * @param array<string, mixed> $thread
     *
     * @return array{pending: array{text:string, files:array<int, string>}|null}|null
     */
    private function running(array $thread): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT `pending` FROM `' . _DB_PREFIX_ . 'somcp_assistant_thread`'
            . ' WHERE `id_thread` = ' . (int) $thread['id_thread'] . ' AND `busy_until` >= NOW()'
        );
        if (!is_array($row)) {
            return null;
        }
        $pending = json_decode((string) $row['pending'], true);
        if (!is_array($pending) || !isset($pending['text'])) {
            return ['pending' => null];
        }

        return ['pending' => [
            'text' => (string) $pending['text'],
            'files' => array_values(array_map('strval', (array) ($pending['files'] ?? []))),
        ]];
    }

    /**
     * The move itself, once the conversation is held.
     *
     * @param array<string, mixed> $thread
     * @param callable $move
     * @param array<string, mixed> $assistant
     *
     * @return string the sentence for the employee when it failed, '' otherwise
     */
    private function move(array $thread, callable $move, array $assistant): string
    {
        try {
            $state = $move($this->agent($thread, $assistant), $assistant);
            if ($state instanceof AgentState) {
                $this->account($state->getSteps(), $assistant);
                $this->remember($thread, $this->keepImages($state->getSteps()));
            }
        } catch (RunInFlightException $e) {
            return self::t('Answer the change waiting for your approval first.');
        } catch (Throwable $e) {
            SomcpLogger::log(
                'assistant_failed',
                SomcpLogger::OUTCOME_FAILURE,
                $assistant['provider'] . ': ' . Tools::substr($e->getMessage(), 0, 300),
                '',
                (int) $this->employee->id
            );

            return self::t('The assistant could not answer. Check its key in the module settings, then try again.');
        }

        Db::getInstance()->update(
            'somcp_assistant_thread',
            ['date_upd' => date('Y-m-d H:i:s')],
            '`id_thread` = ' . (int) $thread['id_thread']
        );
        $this->rememberMemory($thread);

        return '';
    }

    /**
     * Stores in the long-term memory the new messages of a conversation since
     * the last time, so a later conversation can recall them. Only the plain
     * exchange is kept - the employee's words and the assistant's answers, not
     * tool calls nor the summary that stands for older messages - and the
     * thread keeps the id of the last message written, so nothing is embedded
     * or paid for twice. Off unless memory is set up.
     *
     * The mark is a message id, not a count: a summary replaces the oldest
     * messages with one, and a count taken before it then pointed past
     * messages never written, which were lost to the memory for good.
     *
     * @param array<string, mixed> $thread
     */
    private function rememberMemory(array $thread): void
    {
        if (!SomcpAssistantMemory::enabled($this->idShop)) {
            return;
        }

        // Read the mark from the row, not the array passed in: it may have been
        // loaded before an earlier move of the same turn advanced it, and a
        // stale mark would embed - and pay for - the same messages twice.
        $last = (string) Db::getInstance()->getValue(
            'SELECT `memory_last` FROM `' . _DB_PREFIX_ . 'somcp_assistant_thread` WHERE `id_thread` = ' . (int) $thread['id_thread']
        );
        $messages = array_values($this->store()->loadAll((string) $thread['thread_id'], 1000));

        // Everything after the last message written. When that message is no
        // longer there, a summary took it away with the rest of the older part,
        // so every message still in the thread came after it.
        $from = 0;
        if ($last !== '') {
            foreach ($messages as $index => $message) {
                if ($message->getId() === $last) {
                    $from = $index + 1;
                    break;
                }
            }
        }
        $fresh = array_slice($messages, $from);
        if ($fresh === []) {
            return;
        }

        $new = [];
        foreach ($fresh as $message) {
            if ($message instanceof UserMessage) {
                if (strpos((string) $message->getContent(), self::SUMMARY_MARKER) === 0) {
                    continue;
                }
                $text = $this->userText($message);
                if ($text !== '') {
                    $new[] = ['role' => 'user', 'text' => $text];
                }
            } elseif ($message instanceof AssistantMessage) {
                $text = trim((string) $message->getContent());
                if ($text !== '') {
                    $new[] = ['role' => 'assistant', 'text' => $text];
                }
            }
        }

        SomcpAssistantMemory::write($this->idShop, (string) $thread['thread_id'], $new);
        Db::getInstance()->update(
            'somcp_assistant_thread',
            ['memory_last' => pSQL(end($fresh)->getId())],
            '`id_thread` = ' . (int) $thread['id_thread']
        );
    }

    /**
     * The plain words of a user message, without the file names that ride along
     * with an attachment.
     *
     * @param UserMessage $message
     *
     * @return string
     */
    private function userText(UserMessage $message): string
    {
        $skipNext = false;
        foreach ($message->getContentBlocks() as $block) {
            if ($block instanceof TextContent && strpos($block->content, self::FILE_MARKER) === 0) {
                $skipNext = true;
                continue;
            }
            if ($skipNext) {
                $skipNext = false;
                continue;
            }
            if ($block instanceof TextContent && $block->content !== '' && $block->content !== '(files attached)') {
                return trim($block->content);
            }
        }

        return '';
    }

    /**
     * The other conversations of this employee on this shop, newest first: the
     * memory reads only these, so one employee never retrieves another's. The
     * one being answered is left out - it is already in the history - so
     * whatever the memory brings back comes from an earlier conversation.
     *
     * @param string $current the id of the conversation being answered
     *
     * @return array<int, string>
     */
    private function employeeThreadIds(string $current): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `thread_id` FROM `' . _DB_PREFIX_ . 'somcp_assistant_thread`'
            . ' WHERE `id_employee` = ' . (int) $this->employee->id . ' AND `id_shop` = ' . (int) $this->idShop
            . ' AND `thread_id` <> \'' . pSQL($current) . '\''
            . ' ORDER BY `date_upd` DESC LIMIT ' . self::MAX_THREADS
        );

        return is_array($rows) ? array_map(static function (array $row): string {
            return (string) $row['thread_id'];
        }, $rows) : [];
    }

    /**
     * Runs one tool call the model asked for.
     *
     * @param ToolCall $call
     * @param array<string, mixed> $assistant the assistant leading the conversation
     * @param array<string, mixed> $thread
     *
     * @return array{result?:string, error?:string}
     */
    private function execute(ToolCall $call, array $assistant, array $thread): array
    {
        $name = $call->getName();
        $arguments = $call->getInputs();

        if ($name === self::DELEGATE_TOOL) {
            return $this->delegate($arguments, $assistant, $thread);
        }

        $tool = SomcpToolRegistry::getInstance()->get($name);
        $idEmployee = (int) $this->employee->id;

        // Checked again although the tool was only offered if both held: the
        // run may have paused for minutes, and a tool switched off or a right
        // taken away in the meantime must not go through.
        if ($tool === null || !SomcpToolRegistry::isEnabled($name)) {
            return ['error' => 'This tool is switched off for this shop.'];
        }
        if (!$tool->isAvailableTo($this->scopes(), $idEmployee)) {
            SomcpLogger::log('permission_refused', SomcpLogger::OUTCOME_FAILURE, 'assistant: tool ' . $name, '', $idEmployee);

            return ['error' => 'This account does not have the back office permission this tool requires.'];
        }

        // A file attached or generated in the chat is still shown by the
        // conversation: the tool gets a copy to redeem, under its own
        // reference - wherever the reference sits, an image of a product
        // carries its own.
        $arguments = $this->copiesFor($arguments, $idEmployee);

        $result = SomcpToolRegistry::run($tool, $arguments, [
            'id_shop' => $this->idShop,
            'id_employee' => $idEmployee,
            'id_lang' => (int) $this->employee->id_lang,
            'id_client' => 0,
            'scopes' => $this->scopes(),
            'responses' => [],
            'resumed' => [],
            // No client capability: the chat cannot open a form or a file
            // picker in the middle of a step, and a tool that needs one is
            // told so through the empty list, as an older MCP client would.
            'capabilities' => [],
            'arguments' => $arguments,
        ]);

        if (SomcpTool::isInputRequired($result)) {
            return ['error' => 'This step needs the employee to answer a form, which the built-in assistant cannot show yet. Suggest doing it from an assistant connected over MCP, or in the back office.'];
        }

        $text = self::resultText($result);

        return !empty($result['isError']) ? ['error' => $text !== '' ? $text : 'The tool failed.'] : ['result' => $text];
    }

    /**
     * The arguments with every upload_ref replaced by a single-use copy.
     *
     * @param array<array-key, mixed> $arguments
     * @param int $idEmployee
     *
     * @return array<array-key, mixed>
     */
    private function copiesFor(array $arguments, int $idEmployee): array
    {
        foreach ($arguments as $key => $value) {
            if ($key === SomcpUpload::ARG_REF && is_string($value) && $value !== '') {
                $copy = SomcpUpload::copyForTool($value, $idEmployee);
                if ($copy !== '') {
                    $arguments[$key] = $copy;
                }
            } elseif (is_array($value)) {
                $arguments[$key] = $this->copiesFor($value, $idEmployee);
            }
        }

        return $arguments;
    }

    /**
     * Keeps the images a provider generated, each as a deposit of the
     * employee: the chat shows them, and a tool that takes a file - the image
     * of a product - can be given their upload_ref. Nothing the model wrote
     * goes anywhere: these are bytes the provider answered with, checked like
     * any upload.
     *
     * @param array<int, Message> $messages
     *
     * @return array<int, array{name:string, ref:string}>
     */
    private function keepImages(array $messages): array
    {
        $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $kept = [];
        foreach ($messages as $message) {
            if (!$message instanceof AssistantMessage) {
                continue;
            }
            foreach ($message->getContentBlocks() as $block) {
                if (!$block instanceof ImageContent || $block->sourceType !== SourceType::BASE64) {
                    continue;
                }
                $extension = $extensions[(string) $block->mediaType] ?? 'png';
                $bytes = base64_decode($block->content, true);
                $name = 'image-' . date('Ymd-His') . '-' . (count($kept) + 1) . '.' . $extension;
                $reference = is_string($bytes)
                    ? SomcpUpload::keep((int) $this->employee->id, $name, $bytes, $extension, SomcpUpload::CHAT_MODULE, self::FILE_TTL)
                    : '';
                if ($reference !== '') {
                    $kept[] = ['name' => $name, 'ref' => $reference];
                }
            }
        }

        return $kept;
    }

    /**
     * Adds generated files to what a conversation remembers, so the model is
     * told their references on the next turns.
     *
     * The references are kept in the employee's own thread row. One only
     * redeems a file for this employee, on this shop, before it expires.
     *
     * @param array<string, mixed> $thread
     * @param array<int, array{name:string, ref:string}> $files
     */
    private function remember(array $thread, array $files): void
    {
        if ($files === []) {
            return;
        }
        $known = $this->generated($thread);
        Db::getInstance()->update(
            'somcp_assistant_thread',
            ['files' => pSQL((string) json_encode(array_slice(array_merge($known, $files), -50)))],
            '`id_thread` = ' . (int) $thread['id_thread']
        );
    }

    /**
     * @param array<string, mixed> $thread
     *
     * @return array<int, array{name:string, ref:string}>
     */
    private function generated(array $thread): array
    {
        $row = (string) Db::getInstance()->getValue(
            'SELECT `files` FROM `' . _DB_PREFIX_ . 'somcp_assistant_thread` WHERE `id_thread` = ' . (int) $thread['id_thread']
        );
        $files = json_decode($row, true);

        return is_array($files) ? array_values(array_filter($files, static function ($file): bool {
            return is_array($file) && isset($file['name'], $file['ref']);
        })) : [];
    }

    /**
     * Hands a task to another assistant and returns its answer.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $leader
     * @param array<string, mixed> $thread
     *
     * @return array{result?:string, error?:string}
     */
    private function delegate(array $arguments, array $leader, array $thread): array
    {
        $target = SomcpAssistant::get((int) ($arguments['assistant'] ?? 0));
        $task = trim((string) ($arguments['task'] ?? ''));
        if ($target === null || !$target['active'] || (int) $target['id_assistant'] === (int) $leader['id_assistant'] || $task === '') {
            return ['error' => 'No such assistant is available, or the task is empty.'];
        }
        if (SomcpAssistant::overBudget()) {
            return ['error' => 'The monthly spending cap of the shop is reached.'];
        }

        try {
            $agent = Agent::make()
                ->setThreadId('delegate-' . bin2hex(random_bytes(8)))
                ->setAiProvider($this->provider($target, true))
                ->setInstructions($this->delegateInstructions($target));
            $state = $agent->chat(new UserMessage(Tools::substr($task, 0, 20000)));
            $this->account($state->getSteps(), $target);
            $answer = (string) ($state->getMessage() ? $state->getMessage()->getContent() : '');
            $images = $this->keepImages($state->getMessage() ? [$state->getMessage()] : []);
            $this->remember($thread, $images);
        } catch (Throwable $e) {
            SomcpLogger::log('assistant_failed', SomcpLogger::OUTCOME_FAILURE, $target['provider'] . ': ' . Tools::substr($e->getMessage(), 0, 300), '', (int) $this->employee->id);

            return ['error' => $target['label'] . ' could not answer.'];
        }

        // The references go back in the result, which the conversation keeps:
        // the leading model reads them there, and the chat draws the images.
        foreach ($images as $image) {
            $answer .= "\n" . self::GENERATED_MARKER . $image['name'] . ', ' . SomcpUpload::ARG_REF . ': ' . $image['ref'] . ']';
        }

        return ['result' => $target['label'] . ' answered: ' . $answer];
    }

    /**
     * The agent for a conversation, rebuilt on every request from what is
     * stored: Neuron keeps the run in the database, the module keeps nothing
     * in memory between two requests.
     *
     * @param array<string, mixed> $thread
     * @param array<string, mixed> $assistant
     *
     * @return Agent
     */
    private function agent(array $thread, array $assistant): Agent
    {
        // With memory on, the leader is a RAG agent: before answering it reads
        // the matching passages of this employee's past conversations and the
        // model works from them. It is used only when actually answering (an
        // assistant is given): reading a run's state needs no retrieval, so the
        // inspect path below stays a plain agent with no embeddings call.
        // enabled() first: it honours the on/off switch, which retrieval() does
        // not - a provider and a borrowed key are enough for retrieval to build,
        // so without this the memory would read even while switched off.
        $memory = ($assistant !== [] && SomcpAssistantMemory::enabled($this->idShop))
            ? SomcpAssistantMemory::retrieval($this->idShop, $this->employeeThreadIds((string) $thread['thread_id']))
            : null;

        $agent = ($memory !== null ? RAG::make() : Agent::make())
            ->setThreadId((string) $thread['thread_id'])
            ->setMessageStore($this->store())
            ->setPersistence(new DatabasePersistence(self::pdo(), _DB_PREFIX_ . 'somcp_assistant_run'))
            // The history is kept to a token window, and past a threshold the
            // oldest messages are summarized by the model rather than cut, so a
            // long conversation keeps its gist without resending everything.
            // The delegate sub-agent is built on its own below and gets none of
            // this - it is one shot with no history to summarize.
            ->setContextWindow(self::CONTEXT_WINDOW);
        $agent->addMiddleware(InferenceNode::class, new Summarization(null, self::SUMMARIZE_AT));

        if ($memory !== null && $agent instanceof RAG) {
            // The retrieval already carries its own store and embeddings and is
            // scoped to this employee's conversations, so this is all it needs.
            $agent->setRetrieval($memory);
        }

        if ($assistant !== []) {
            $agent->setAiProvider($this->provider($assistant))
                ->setInstructions($this->instructions($assistant, $thread, $memory !== null))
                ->setTools($this->tools($assistant));
        }
        $agent->toolMaxRuns(self::MAX_TOOL_RUNS);

        return $agent;
    }

    /**
     * @return SQLMessageStore
     */
    private function store(): SQLMessageStore
    {
        return new SQLMessageStore(self::pdo(), _DB_PREFIX_ . 'somcp_assistant_message');
    }

    /**
     * The provider of an assistant, with the model its level resolves to.
     *
     * @param array<string, mixed> $assistant
     *
     * @return AIProviderInterface
     */
    private function provider(array $assistant, bool $delegated = false): AIProviderInterface
    {
        if (self::$providerOverride !== null) {
            return self::$providerOverride;
        }

        $key = SomcpAssistant::key($assistant);
        $model = SomcpAssistant::model($assistant);
        if ($model === '') {
            throw new RuntimeException('no model resolved for assistant ' . (int) $assistant['id_assistant']);
        }

        // OpenAI's image models answer on their own API, without tools or a
        // conversation: fine for a task handed over, which is one prompt.
        if ($delegated && $assistant['provider'] === 'openai' && preg_match('/^(gpt-image|dall-e)/', $model)) {
            return new OpenAIImage($key, $model);
        }

        switch ($assistant['provider']) {
            case 'openai':
                return new OpenAI($key, $model);
            case 'gemini':
                return new Gemini($key, $model);
            case 'mistral':
                return new Mistral($key, $model);
            case 'deepseek':
                return new Deepseek($key, $model);
            case 'xai':
                return new Grok($key, $model);
            case 'cohere':
                return new Cohere($key, $model);
            default:
                return new Anthropic($key, $model, '2023-06-01', 4096);
        }
    }

    /**
     * The shop's tools, as the employee may use them, handed to Neuron.
     *
     * Read from the registry on every request, so a tool added by an update or
     * by another module reaches the chat the moment it is registered, with
     * nothing to wire here. A tool that is not read-only asks for approval.
     *
     * @param array<string, mixed> $assistant
     *
     * @return array<int, FrontendTool>
     */
    private function tools(array $assistant): array
    {
        $tools = [];
        $scopes = $this->scopes();
        $idEmployee = (int) $this->employee->id;

        foreach (SomcpToolRegistry::getInstance()->all() as $tool) {
            if (!SomcpToolRegistry::isEnabled($tool->getName()) || !$tool->isAvailableTo($scopes, $idEmployee)) {
                continue;
            }
            $described = $tool->describe();

            try {
                $offered = new FrontendTool(
                    (string) $described['name'],
                    (string) $described['description'],
                    self::plainSchema((array) $described['inputSchema'])
                );
            } catch (Throwable $e) {
                // A schema the framework cannot map - a oneOf, a $ref - leaves
                // this one tool out of the chat. It stays available over MCP.
                SomcpLogger::log('assistant_tool_skipped', SomcpLogger::OUTCOME_FAILURE, $tool->getName() . ': ' . Tools::substr($e->getMessage(), 0, 150), '', $idEmployee);

                continue;
            }

            if (($described['annotations']['readOnlyHint'] ?? false) !== true) {
                $offered->requireApproval();
            }
            $tools[] = $offered;
        }

        $others = $this->colleagues($assistant);
        if ($others !== []) {
            $tools[] = new FrontendTool(
                self::DELEGATE_TOOL,
                'Hand a piece of work to another assistant of this shop and get its answer back. Use it when part of the request falls under the role of another assistant. The other assistant sees only the task you write, not this conversation and not the shop tools: put in the task everything it needs.',
                [
                    'type' => 'object',
                    'properties' => [
                        'assistant' => [
                            'type' => 'string',
                            'description' => 'Id of the assistant to ask, from the list in your instructions.',
                            'enum' => array_map(static function (array $other): string {
                                return (string) $other['id_assistant'];
                            }, $others),
                        ],
                        'task' => [
                            'type' => 'string',
                            'description' => 'The work to do, with all the data it needs.',
                        ],
                    ],
                    'required' => ['assistant', 'task'],
                ]
            );
        }

        return $tools;
    }

    /**
     * A tool schema as Neuron AI reads it: arrays only.
     *
     * The tools write an empty object as stdClass, so that MCP sends {} and
     * not []; Neuron's schema reader wants arrays. An empty property list is
     * dropped rather than turned into a list, which a provider would refuse:
     * an object schema without properties means the same thing.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function plainSchema(array $schema): array
    {
        $plain = json_decode((string) json_encode($schema), true);

        $clean = static function ($node) use (&$clean) {
            if (!is_array($node)) {
                return $node;
            }
            if (array_key_exists('properties', $node) && $node['properties'] === []) {
                unset($node['properties']);
            }
            // Gemini's function-declaration schema rejects additionalProperties.
            // Neuron already strips it for structured output but not for tools,
            // so it is taken out here, where the tool schema is normalised. Our
            // tool schemas never carry a property of that name, so a plain
            // unset at every level is safe.
            unset($node['additionalProperties']);

            return array_map($clean, $node);
        };

        return is_array($plain) ? $clean($plain) : ['type' => 'object'];
    }

    /**
     * @param array<string, mixed> $assistant
     * @param array<string, mixed> $thread
     * @param bool $memory true when the memory feeds this turn
     *
     * @return string
     */
    private function instructions(array $assistant, array $thread = [], bool $memory = false): string
    {
        // Kept terse on purpose: this text is paid for on every model call.
        $shop = Somcp::legacyContext()->shop;
        $language = Language::getLanguage((int) $this->employee->id_lang);
        $module = Module::getInstanceByName('somcp');
        // The links follow what is installed, so the model reads the code and
        // the docs of this very shop rather than of whatever version is newest.
        $version = $module instanceof Module ? (string) $module->version : '';
        $major = (int) _PS_VERSION_;

        $lines = [
            'You are an e-commerce expert running a shop on the PrestaShop CMS, built into its back office.',
            // The shop id is stated because a tool call in a multistore install
            // runs against this shop, and the model must not guess another.
            'Shop #' . $this->idShop . ' "' . $shop->name . '"' . (Shop::isFeatureActive() ? ' (one shop of a multistore install)' : '') . ', ' . $shop->getBaseURL(true) . '.',
            'PrestaShop ' . _PS_VERSION_ . ': code https://github.com/PrestaShop/PrestaShop/tree/' . _PS_VERSION_
                . ', docs https://github.com/PrestaShop/docs/tree/' . $major . '.x',
            'Module O\'So MCP Server ' . $version . ': ' . SomcpUpdate::REPOSITORY . '/tree/v' . $version,
            'Employee: ' . trim($this->employee->firstname . ' ' . $this->employee->lastname) . ', with exactly their back office rights, through your tools.',
        ];
        if ($this->page !== '') {
            $lines[] = 'Chat opened on page ' . $this->page . '.';
        }
        $lines[] = 'Reply in ' . (is_array($language) ? $language['name'] : 'English') . ', short and concrete. Read real data with tools, never guess.';
        $lines[] = 'Tools that change the shop ask the employee for approval: call them directly.';
        $lines[] = 'Explain tool errors plainly with a next step.';
        $lines[] = 'Attached files carry an upload_ref: pass it to any tool accepting one.';

        $others = $this->colleagues($assistant);
        if ($others !== [] || $assistant['role'] !== '') {
            $lines[] = 'Assistants (you = #' . (int) $assistant['id_assistant'] . '):';
            foreach (array_merge([$assistant], $others) as $one) {
                $image = $one['level'] === SomcpAssistant::LEVEL_IMAGE;
                $role = $one['role'] !== '' ? $one['role'] : ($image ? 'generates images' : 'general help');
                $lines[] = '#' . (int) $one['id_assistant'] . ' ' . $one['label'] . ': ' . $role
                    . ($image && $one['role'] !== '' ? ' (generates images)' : '');
            }
            if ($others !== []) {
                $lines[] = 'Hand a task to the one whose role fits with ' . self::DELEGATE_TOOL . ', then use its answer. Describe any image fully.';
            }
        }

        $generated = $thread !== [] ? $this->generated($thread) : [];
        if ($generated !== []) {
            $lines[] = 'Images generated here, usable via upload_ref (e.g. as a product image):';
            foreach ($generated as $file) {
                $lines[] = '- ' . $file['name'] . ', upload_ref: ' . $file['ref'];
            }
        }

        if ($memory) {
            // The framework hands the recalled passages over with the message,
            // labelled only with a source type and a thread id. Unframed, the
            // model read them as the conversation in progress and carried on an
            // old topic in a new conversation.
            $lines[] = 'Text in <EXTRA-CONTEXT> with Source Type conversation is recalled from your past conversations with this employee, not this one. Use it only when their message needs it, say it comes from an earlier exchange, and never resume a past topic on your own.';
        }

        $custom = trim(SomcpAssistant::instructions());
        if ($custom !== '') {
            $lines[] = 'Instructions from the shop: ' . $custom;
        }

        // Last, because it changes every minute: whatever comes before it stays
        // the same from one call to the next, which the providers that cache the
        // start of a prompt bill for less. PrestaShop sets PHP's time zone to
        // the shop's, so date() gives the merchant's own clock.
        $lines[] = 'Now ' . date('l Y-m-d H:i') . ' (' . date_default_timezone_get() . '). Suit your tone to the time of day; greet only to open a conversation, never again within it.';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $assistant
     *
     * @return string
     */
    private function delegateInstructions(array $assistant): string
    {
        $lines = [
            'You help the assistant of a PrestaShop shop with one task. You cannot use tools and you do not see the conversation: work only from the task you are given, and answer with the result itself. When the task asks for an image and you can generate images, generate it.',
        ];
        if ($assistant['role'] !== '') {
            $lines[] = 'Your role in this shop: ' . $assistant['role'] . '.';
        }
        $custom = trim(SomcpAssistant::instructions());
        if ($custom !== '') {
            $lines[] = 'Instructions from the shop: ' . $custom;
        }

        return implode("\n", $lines);
    }

    /**
     * The other assistants switched on, the ones the leader may ask.
     *
     * @param array<string, mixed> $assistant
     *
     * @return array<int, array<string, mixed>>
     */
    private function colleagues(array $assistant): array
    {
        return array_values(array_filter(SomcpAssistant::active(), static function (array $other) use ($assistant): bool {
            return (int) $other['id_assistant'] !== (int) $assistant['id_assistant'];
        }));
    }

    /**
     * The assistant leading a conversation: the one picked in the chat while it
     * stays switched on, the default one otherwise.
     *
     * @param array<string, mixed> $thread
     *
     * @return array<string, mixed>|null
     */
    private function assistantOf(array $thread): ?array
    {
        $picked = (int) $thread['id_assistant'] > 0 ? SomcpAssistant::get((int) $thread['id_assistant']) : null;

        // An image assistant cannot lead: it does not call the shop's tools.
        return $picked !== null && $picked['active'] && $picked['level'] !== SomcpAssistant::LEVEL_IMAGE
            ? $picked
            : SomcpAssistant::leader();
    }

    /**
     * Records what the model calls of a run consumed.
     *
     * @param array<int, Message> $steps
     * @param array<string, mixed> $assistant
     */
    private function account(array $steps, array $assistant): void
    {
        $model = SomcpAssistant::model($assistant);
        $input = 0;
        $output = 0;
        foreach ($steps as $message) {
            $usage = $message->getUsage();
            if ($usage !== null) {
                $input += $usage->inputTokens;
                $output += $usage->outputTokens;
            }
        }

        SomcpAssistant::record(
            (int) $assistant['id_assistant'],
            $input,
            $output,
            SomcpAssistant::price((string) $assistant['provider'], $model, $input, $output)
        );
    }

    /**
     * Every scope the employee could grant an assistant: the chat stands where
     * an assistant connected with everything allowed would stand, and the
     * employee's back office rights do the limiting.
     *
     * @return string
     */
    private function scopes(): string
    {
        return SomcpScope::toString(SomcpScope::grantableFor((int) $this->employee->id, $this->idShop));
    }

    /**
     * Tool titles by name, for the chat to say what is happening in words the
     * employee reads on the settings page.
     *
     * @return array<string, string>
     */
    private function toolTitles(): array
    {
        $titles = [self::DELEGATE_TOOL => self::t('Ask another assistant')];
        foreach (SomcpToolRegistry::getInstance()->all() as $tool) {
            $described = $tool->describe();
            $titles[$tool->getName()] = (string) ($described['title'] ?? $tool->getName());
        }

        return $titles;
    }

    /**
     * One stored message as the chat draws it, or null for what it does not
     * show: tool results are for the model, the employee sees what they led to.
     *
     * @param Message $message
     * @param array<string, string> $titles
     *
     * @return array<string, mixed>|null
     */
    private function draw(Message $message, array $titles): ?array
    {
        if ($message instanceof ToolResultMessage) {
            // Shown only for the images another assistant generated, drawn
            // from their deposits through signed links.
            $images = [];
            foreach ($message->getToolCalls() as $call) {
                if ($call->getName() !== self::DELEGATE_TOOL || !$call->hasResult()) {
                    continue;
                }
                $result = (string) $call->getResult();
                if (preg_match_all('/' . preg_quote(self::GENERATED_MARKER, '/') . '([^,\]]+), ' . SomcpUpload::ARG_REF . ': ([A-Za-z0-9_-]+)\]/', $result, $found, PREG_SET_ORDER)) {
                    foreach ($found as $match) {
                        $images[] = ['name' => $match[1], 'src' => self::fileUrl($match[2])];
                    }
                }
            }

            return $images === [] ? null : ['role' => 'images', 'text' => '', 'images' => $images];
        }
        if ($message instanceof ToolCallMessage) {
            $calls = [];
            foreach ($message->getToolCalls() as $call) {
                $state = $call->getApprovalState();
                $title = $titles[$call->getName()] ?? $call->getName();
                // Name the assistant a delegation went to, so the step reads
                // "Ask another assistant (Gemini)" rather than hiding who did it.
                if ($call->getName() === self::DELEGATE_TOOL) {
                    $inputs = $call->getInputs();
                    $target = isset($inputs['assistant']) ? SomcpAssistant::get((int) $inputs['assistant']) : null;
                    if ($target !== null) {
                        $title .= ' (' . $target['label'] . ')';
                    }
                }
                $calls[] = [
                    'title' => $title,
                    'approval' => $state instanceof ApprovalState ? $state->value : '',
                ];
            }

            return ['role' => 'tools', 'text' => (string) $message->getContent(), 'tools' => $calls];
        }
        if ($message instanceof UserMessage) {
            $first = (string) $message->getContent();
            if (strpos($first, self::SUMMARY_MARKER) === 0) {
                return ['role' => 'summary', 'text' => trim(substr($first, strlen(self::SUMMARY_MARKER)))];
            }
            // The employee's words, and the files by name: the content of an
            // attached file is for the model, the chat shows that it went.
            $text = '';
            $files = [];
            $skipNext = false;
            foreach ($message->getContentBlocks() as $block) {
                if ($block instanceof TextContent && strpos($block->content, self::FILE_MARKER) === 0) {
                    $named = rtrim(Tools::substr($block->content, Tools::strlen(self::FILE_MARKER)), ']');
                    $cut = strpos($named, ', ' . SomcpUpload::ARG_REF . ':');
                    $files[] = $cut === false ? $named : substr($named, 0, $cut);
                    $skipNext = true;
                    continue;
                }
                if ($skipNext) {
                    $skipNext = false;
                    continue;
                }
                if ($block instanceof TextContent && $text === '') {
                    $text = $block->content === '(files attached)' ? '' : $block->content;
                }
            }

            return ['role' => 'user', 'text' => $text, 'files' => $files];
        }
        if ($message instanceof AssistantMessage) {
            $text = (string) $message->getContent();
            $images = [];
            foreach ($message->getContentBlocks() as $block) {
                if ($block instanceof ImageContent && $block->sourceType === SourceType::BASE64
                    && in_array((string) $block->mediaType, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)
                    && preg_match('/^[A-Za-z0-9+\/=]+$/', $block->content)) {
                    $images[] = ['name' => '', 'src' => 'data:' . $block->mediaType . ';base64,' . $block->content];
                }
            }

            return $text === '' && $images === [] ? null : ['role' => 'assistant', 'text' => $text, 'images' => $images];
        }

        return null;
    }

    /**
     * The text of a tool result, for the model.
     *
     * @param array<string, mixed> $result a CallToolResult
     *
     * @return string
     */
    private static function resultText(array $result): string
    {
        $parts = [];
        foreach ((array) ($result['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $parts[] = (string) $block['text'];
            }
        }
        if ($parts === [] && isset($result['structuredContent'])) {
            $parts[] = (string) json_encode($result['structuredContent']);
        }

        return implode("\n", $parts);
    }

    /**
     * One of the module's strings, in the employee's language.
     *
     * @param string $source
     *
     * @return string
     */
    private static function t(string $source): string
    {
        $module = Module::getInstanceByName('somcp');

        return $module instanceof Somcp ? $module->l10n($source) : $source;
    }

    /**
     * A connection of the module's own for Neuron AI, which talks PDO.
     *
     * PrestaShop's connection is not handed out on every version, and the
     * framework needs two things of its connection that a shop may not have
     * set: exceptions on every error, and the strict SQL mode, without which
     * MySQL would truncate a stored run in silence instead of refusing it.
     * Both are set here, on this connection only.
     *
     * @return PDO
     */
    private static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $server = (string) _DB_SERVER_;
        $dsn = 'mysql:dbname=' . _DB_NAME_ . ';charset=utf8mb4';
        if (preg_match('/^(.*):(\d+)$/', $server, $parts)) {
            $dsn .= ';host=' . $parts[1] . ';port=' . $parts[2];
        } elseif (preg_match('/^(.*):(\/.+)$/', $server, $parts)) {
            $dsn .= ';host=' . $parts[1] . ';unix_socket=' . $parts[2];
        } else {
            $dsn .= ';host=' . $server;
        }

        $pdo = new PDO($dsn, _DB_USER_, _DB_PASSWD_, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->query(
            "SET SESSION sql_mode = IF(@@SESSION.sql_mode = '', 'STRICT_TRANS_TABLES',"
            . " IF(LOCATE('STRICT_', @@SESSION.sql_mode) > 0, @@SESSION.sql_mode, CONCAT(@@SESSION.sql_mode, ',STRICT_TRANS_TABLES')))"
        );

        return self::$pdo = $pdo;
    }
}
