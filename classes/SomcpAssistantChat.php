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
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\XAI\Grok;
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
            . ' WHERE `id_employee` = ' . (int) $this->employee->id
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

        return $this->advance($thread, static function (Agent $agent) use ($content): AgentState {
            return $agent->chat(new UserMessage($content));
        });
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

        return Context::getContext()->link->getModuleLink('somcp', 'file', [
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
        $host = (string) parse_url(Context::getContext()->shop->getBaseURL(true), PHP_URL_HOST);
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
        return $this->advance($thread, function (Agent $agent, array $assistant): ?AgentState {
            $run = $agent->inspect();
            if ($run === null || !$run->interrupt instanceof ToolResultsRequest) {
                return null;
            }

            $done = $run->interrupt->getResults();
            $results = [];
            foreach ($run->interrupt->getToolCalls() as $call) {
                $callId = (string) $call->getCallId();
                if ($callId !== '' && !isset($done[$callId])) {
                    $results[$callId] = $this->execute($call, $assistant);
                }
            }

            return $agent->submitToolResults($results)->run();
        });
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
        } catch (Throwable $e) {
            SomcpLogger::log('assistant_failed', SomcpLogger::OUTCOME_FAILURE, Tools::substr($e->getMessage(), 0, 200), '', (int) $this->employee->id);
        }

        return [
            'thread_id' => (string) $thread['thread_id'],
            'id_assistant' => (int) $thread['id_assistant'],
            'assistant' => $assistant !== null ? (string) $assistant['label'] : '',
            'messages' => $messages,
            'status' => $status,
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
     * @param array<string, mixed> $thread
     * @param callable $move function(Agent $agent, array $assistant): ?AgentState
     *
     * @return array<string, mixed>
     */
    private function advance(array $thread, callable $move): array
    {
        $assistant = $this->assistantOf($thread);
        if ($assistant === null) {
            return $this->view($thread, self::t('No assistant is switched on. Add one in the module settings.'));
        }
        if (SomcpAssistant::overBudget()) {
            return $this->view($thread, self::t('The monthly spending cap is reached. Raise it in the module settings to keep chatting.'));
        }

        try {
            $state = $move($this->agent($thread, $assistant), $assistant);
            if ($state instanceof AgentState) {
                $this->account($state->getSteps(), $assistant);
            }
        } catch (RunInFlightException $e) {
            return $this->view($thread, self::t('Answer the change waiting for your approval first.'));
        } catch (Throwable $e) {
            SomcpLogger::log(
                'assistant_failed',
                SomcpLogger::OUTCOME_FAILURE,
                $assistant['provider'] . ': ' . Tools::substr($e->getMessage(), 0, 300),
                '',
                (int) $this->employee->id
            );

            return $this->view($thread, self::t('The assistant could not answer. Check its key in the module settings, then try again.'));
        }

        Db::getInstance()->update(
            'somcp_assistant_thread',
            ['date_upd' => date('Y-m-d H:i:s')],
            '`id_thread` = ' . (int) $thread['id_thread']
        );

        return $this->view($thread);
    }

    /**
     * Runs one tool call the model asked for.
     *
     * @param ToolCall $call
     * @param array<string, mixed> $assistant the assistant leading the conversation
     *
     * @return array{result?:string, error?:string}
     */
    private function execute(ToolCall $call, array $assistant): array
    {
        $name = $call->getName();
        $arguments = $call->getInputs();

        if ($name === self::DELEGATE_TOOL) {
            return $this->delegate($arguments, $assistant);
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

        // A file attached in the chat is still shown by the conversation: the
        // tool gets a copy to redeem, under its own reference.
        if (is_string($arguments[SomcpUpload::ARG_REF] ?? null) && $arguments[SomcpUpload::ARG_REF] !== '') {
            $copy = SomcpUpload::copyForTool($arguments[SomcpUpload::ARG_REF], $idEmployee);
            if ($copy !== '') {
                $arguments[SomcpUpload::ARG_REF] = $copy;
            }
        }

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
     * Hands a task to another assistant and returns its answer.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $leader
     *
     * @return array{result?:string, error?:string}
     */
    private function delegate(array $arguments, array $leader): array
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
                ->setAiProvider($this->provider($target))
                ->setInstructions($this->delegateInstructions($target));
            $state = $agent->chat(new UserMessage(Tools::substr($task, 0, 20000)));
            $this->account($state->getSteps(), $target);
            $answer = (string) ($state->getMessage() ? $state->getMessage()->getContent() : '');
        } catch (Throwable $e) {
            SomcpLogger::log('assistant_failed', SomcpLogger::OUTCOME_FAILURE, $target['provider'] . ': ' . Tools::substr($e->getMessage(), 0, 300), '', (int) $this->employee->id);

            return ['error' => $target['label'] . ' could not answer.'];
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
        $agent = Agent::make()
            ->setThreadId((string) $thread['thread_id'])
            ->setMessageStore($this->store())
            ->setPersistence(new DatabasePersistence(self::pdo(), _DB_PREFIX_ . 'somcp_assistant_run'));

        if ($assistant !== []) {
            $agent->setAiProvider($this->provider($assistant))
                ->setInstructions($this->instructions($assistant))
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
    private function provider(array $assistant): AIProviderInterface
    {
        if (self::$providerOverride !== null) {
            return self::$providerOverride;
        }

        $key = SomcpAssistant::key($assistant);
        $model = SomcpAssistant::model($assistant);
        if ($model === '') {
            throw new RuntimeException('no model resolved for assistant ' . (int) $assistant['id_assistant']);
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
            case 'ollama':
                return new Ollama(SomcpAssistant::ollamaBase($key), $model);
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

            return array_map($clean, $node);
        };

        return is_array($plain) ? $clean($plain) : ['type' => 'object'];
    }

    /**
     * @param array<string, mixed> $assistant
     *
     * @return string
     */
    private function instructions(array $assistant): string
    {
        $shop = Context::getContext()->shop;
        $language = Language::getLanguage((int) $this->employee->id_lang);

        $lines = [
            'You are the assistant built into the back office of the PrestaShop shop "' . $shop->name . '".',
            'You work for ' . trim($this->employee->firstname . ' ' . $this->employee->lastname) . ', an employee of the shop, with exactly their back office permissions, through the tools you are given.',
            'Answer in ' . (is_array($language) ? $language['name'] : 'English') . '. Be concise and concrete.',
            'Files the employee attached are named with an upload_ref. A tool that accepts an upload_ref argument can use the file itself: pass it that reference.',
            'Read real data with the tools rather than guessing. Any tool that changes the shop is shown to the employee for approval before it runs: when the request is clear, call it directly instead of asking for permission in text.',
            'When a tool returns an error, explain it plainly and suggest what the employee can do.',
            'Environment: PrestaShop ' . _PS_VERSION_ . ', PHP ' . PHP_VERSION . ', shop address ' . $shop->getBaseURL(true) . ', today is ' . date('Y-m-d') . '.',
        ];

        if ($assistant['role'] !== '') {
            $lines[] = 'Your own role in this shop: ' . $assistant['role'] . '.';
        }

        $others = $this->colleagues($assistant);
        if ($others !== []) {
            $lines[] = 'Other assistants of this shop, each with a role. When part of a request falls under one of these roles, hand that part over with ' . self::DELEGATE_TOOL . ', then use the answer:';
            foreach ($others as $other) {
                $lines[] = '- id ' . $other['id_assistant'] . ', ' . $other['label'] . ': ' . ($other['role'] !== '' ? $other['role'] : 'general help');
            }
        }

        $custom = trim(SomcpAssistant::instructions());
        if ($custom !== '') {
            $lines[] = 'Instructions from the shop: ' . $custom;
        }

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
            'You help the assistant of a PrestaShop shop with one task. You cannot use tools and you do not see the conversation: work only from the task you are given, and answer with the result itself.',
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

        return $picked !== null && $picked['active'] ? $picked : SomcpAssistant::leader();
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
            return null;
        }
        if ($message instanceof ToolCallMessage) {
            $calls = [];
            foreach ($message->getToolCalls() as $call) {
                $state = $call->getApprovalState();
                $calls[] = [
                    'title' => $titles[$call->getName()] ?? $call->getName(),
                    'approval' => $state instanceof ApprovalState ? $state->value : '',
                ];
            }

            return ['role' => 'tools', 'text' => (string) $message->getContent(), 'tools' => $calls];
        }
        if ($message instanceof UserMessage) {
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

            return $text === '' ? null : ['role' => 'assistant', 'text' => $text];
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
