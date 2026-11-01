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

/*
 * What the chat bubble of the back office talks to.
 *
 * Every answer is JSON and every request is one move on a conversation: load
 * it, send a message, run the next step, answer an approval. A move makes at
 * most one model call, so the page drives a long answer step by step instead
 * of one request running past the host's time limit.
 *
 * It lives behind a hidden back office tab, like the consent and deposit
 * screens: PrestaShop decides who the visitor is and hands out the token that
 * guards these requests, tied to this controller and this employee.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSomcpAssistantController extends ModuleAdminController
{
    /**
     * @var Somcp the module instance, narrowed from the generic Module the
     *            parent declares so static analysis can resolve its public API
     */
    public $module;

    /** @var array<int, int> The shops this employee may chat about from the page open, read before the request acts as one of them. */
    private $chatShops = [];

    /** @var bool Whether a new conversation starts by picking its shop, read at the same time. */
    private $picksShop = false;

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        $module = Module::getInstanceByName('somcp');
        if ($module instanceof Somcp) {
            $this->module = $module;
        }
    }

    /**
     * The permission slug of this controller, unchanged from the parent.
     *
     * Declared for the same reason as on the consent screen: PrestaShop 9
     * reflects on THIS method to decide whether viewAccess below is consulted
     * at all. Without it every profile but the super administrator gets a 403.
     *
     * @return string
     */
    public function getTabSlug()
    {
        return parent::getTabSlug();
    }

    /**
     * Open to the employees the merchant allowed to use the built-in
     * assistant: the chat acts with the employee's own rights, so the question
     * is only whether this profile may use it at all.
     *
     * @param bool $disable
     *
     * @return bool
     */
    public function viewAccess($disable = false)
    {
        Somcp::bootClasses();

        return SomcpAssistant::mayUse((int) $this->context->employee->id_profile);
    }

    /**
     * Handles the move and answers, before PrestaShop draws any page.
     */
    public function postProcess()
    {
        Somcp::bootClasses();

        // Checked here and not left to the core: depending on the version, an
        // ajax request to a legacy controller may reach this point without the
        // token having been compared at all.
        if (!$this->checkToken()) {
            SomcpHttp::json(['error' => 'invalid_token'], 403);
        }
        $employee = $this->context->employee;
        $shops = SomcpAssistantChat::available() ? SomcpAssistantChat::shopsFor($employee) : [];
        if ($shops === []) {
            SomcpHttp::json(['error' => 'unavailable'], 404);
        }

        // A model call takes seconds. Holding the PHP session through it would
        // make every other back office page of this employee - a reload, a
        // click elsewhere - wait for it, since the session file stays locked
        // until the request ends. Nothing here writes to the session, so it is
        // released now; and the move goes on to the end if the page that asked
        // for it is reloaded or left, the chat picking up its answer on return.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        SomcpAssistantChat::allowTime();

        $action = (string) Tools::getValue('somcp_action');
        $threadId = (string) Tools::getValue('thread_id');
        // Read before the request acts as one shop: the back office context
        // then narrows to that shop alone.
        $this->chatShops = $shops;
        $this->picksShop = SomcpAssistantChat::picksShop($shops);
        $idShop = $this->shopOf($shops, $action, $threadId);

        // The whole request then runs as that shop: the tools read the shop
        // of the context, and so do the assistants, the budget and the
        // settings, whatever the back office is set to.
        Somcp::actAsShop($idShop);
        $chat = new SomcpAssistantChat($employee, $idShop, $shops);
        $chat->openOn((string) Tools::getValue('page'));

        $thread = null;
        if ($threadId !== '') {
            $thread = $chat->thread($threadId);
            if ($thread === null) {
                SomcpHttp::json(['error' => 'unknown_thread'], 404);
            }
        }

        switch ($action) {
            case 'new':
                $thread = $chat->newThread((int) Tools::getValue('id_assistant'));
                break;
            case 'send':
                $thread = $thread ?? $chat->newThread((int) Tools::getValue('id_assistant'));
                $this->answer($chat, $chat->send($thread, (string) Tools::getValue('text'), self::uploadedFiles(), (string) Tools::getValue('prompt')));
                break;
            case 'step':
                if ($thread !== null) {
                    $this->answer($chat, $chat->step($thread));
                }
                break;
            case 'decide':
                if ($thread !== null) {
                    $decisions = json_decode((string) Tools::getValue('decisions'), true);
                    $this->answer($chat, $chat->decide($thread, is_array($decisions) ? array_map('strval', $decisions) : []));
                }
                break;
            case 'assistant':
                if ($thread !== null) {
                    $thread = $chat->useAssistant($thread, (int) Tools::getValue('id_assistant'));
                }
                break;
            case 'delete':
                if ($thread !== null) {
                    $chat->deleteThread($thread);
                    $thread = null;
                }
                break;
        }

        if ($thread === null) {
            $latest = $chat->threads()[0] ?? null;
            $thread = $latest !== null ? $chat->thread((string) $latest['thread_id']) : null;
        }

        $this->answer($chat, $thread !== null ? $chat->view($thread) : null);
    }

    /**
     * The shop a request acts on: the conversation's own, or the one picked
     * for a new conversation, or the latest conversation's for a plain load.
     *
     * A conversation keeps the shop it started on: reopened from another back
     * office context, it still acts on that shop and no other.
     *
     * @param array<int, int> $shops the shops this employee may chat about here
     * @param string $action
     * @param string $threadId
     *
     * @return int
     */
    private function shopOf(array $shops, string $action, string $threadId): int
    {
        $idEmployee = (int) $this->context->employee->id;
        if ($threadId !== '') {
            $idShop = SomcpAssistantChat::threadShop($idEmployee, $threadId, $shops);
            if ($idShop <= 0) {
                SomcpHttp::json(['error' => 'unknown_thread'], 404);
            }

            return $idShop;
        }

        $current = Somcp::currentShopId();
        $fallback = in_array($current, $shops, true) ? $current : $shops[0];
        if ($action === 'new' || $action === 'send') {
            $picked = (int) Tools::getValue('id_shop');
            if (in_array($picked, $shops, true)) {
                return $picked;
            }
            // Several shops to pick from and none picked: guessing one would
            // let a conversation act on a shop nobody chose.
            if ($this->picksShop) {
                SomcpHttp::json(['error' => 'choose_shop'], 400);
            }

            return $fallback;
        }

        $latest = SomcpAssistantChat::latestShop($idEmployee, $shops);

        return $latest > 0 ? $latest : $fallback;
    }

    /**
     * The files sent with a message, one entry per file, from PHP's
     * upload array that groups them by field instead.
     *
     * @return array<int, array{name:string, tmp_name:string, size:int, error:int}>
     */
    private static function uploadedFiles(): array
    {
        $raw = $_FILES['files'] ?? null;
        if (!is_array($raw) || !is_array($raw['name'] ?? null)) {
            return [];
        }

        $files = [];
        foreach (array_keys($raw['name']) as $index) {
            if ((int) ($raw['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name' => (string) $raw['name'][$index],
                'tmp_name' => (string) ($raw['tmp_name'][$index] ?? ''),
                'size' => (int) ($raw['size'][$index] ?? 0),
                'error' => (int) ($raw['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            ];
        }

        return $files;
    }

    /**
     * Sends the conversation, with what the chat needs around it.
     *
     * @param SomcpAssistantChat $chat
     * @param array<string, mixed>|null $view
     */
    private function answer(SomcpAssistantChat $chat, ?array $view): void
    {
        $threads = [];
        foreach ($chat->threads() as $row) {
            $threads[] = [
                'thread_id' => (string) $row['thread_id'],
                'title' => (string) $row['title'],
                'date' => (string) $row['date_upd'],
                'id_shop' => (int) $row['id_shop'],
            ];
        }

        $assistants = [];
        foreach (SomcpAssistant::active() as $assistant) {
            // Only the assistants that can lead a conversation are offered in
            // the picker; an image one is reached through its role.
            if ($assistant['level'] === SomcpAssistant::LEVEL_IMAGE) {
                continue;
            }
            $assistants[] = [
                'id' => (int) $assistant['id_assistant'],
                'label' => (string) $assistant['label'],
                'role' => (string) $assistant['role'],
                'default' => (bool) $assistant['is_default'],
            ];
        }

        SomcpHttp::json([
            'view' => $view,
            'threads' => $threads,
            'assistants' => $assistants,
            'prompts' => $chat->prompts(),
            // Every shop of the list, for the badge of each conversation, and
            // whether a new conversation starts by picking one of them.
            'shops' => Shop::isFeatureActive() ? SomcpAssistantChat::describeShops($this->chatShops) : [],
            'pick_shop' => $this->picksShop,
        ]);
    }
}
