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
 * The AI Agent page of the back office.
 *
 * A menu entry with permissions of its own, unlike the module's two hidden
 * pages: who may talk to the assistant is decided per profile, in Team >
 * Permissions, like any other back office page. What the assistant may then
 * do is still bounded by that employee's other permissions, tool by tool.
 *
 * The page draws itself once; everything after that - sending a message,
 * approving a change, opening an older conversation - goes through the ajax
 * actions below, each one short (see SomcpAgent for why).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSomcpAgentController extends ModuleAdminController
{
    /**
     * @var Somcp the module instance, narrowed from the generic Module the
     *            parent declares so static analysis can resolve its public API
     */
    public $module;

    /** @var int Longest message an employee can send, in characters. */
    private const MAX_MESSAGE = 20000;

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
     * Renders the page.
     */
    public function initContent()
    {
        Somcp::bootClasses();

        // Here rather than in a cron: a model released this week shows up the
        // next time someone opens the page, with nothing to schedule.
        SomcpAgentConfig::refreshStaleModels();

        $agents = SomcpAgentConfig::agents();
        $roles = SomcpAgentConfig::roles();
        $choices = [];
        foreach ($agents as $agent) {
            $choices[] = [
                'value' => $agent['provider'] . ':' . $agent['model'],
                'label' => $agent['provider_label'] . ' - ' . $agent['label'],
                'role' => count($roles) > 1 ? ($roles[$agent['provider']] ?? '') : '',
            ];
        }

        $this->context->smarty->assign([
            'somcp_agents' => $choices,
            'somcp_conversations' => SomcpAgentStore::conversations((int) $this->context->employee->id, Somcp::currentShopId()),
            'somcp_ajax_url' => $this->context->link->getAdminLink(SomcpBackOffice::AGENT_CONTROLLER, true, [], ['ajax' => 1]),
            // Only an administrator reaches the settings, so only they are
            // sent there; anyone else is told who to ask.
            'somcp_settings_url' => $this->context->employee->isSuperAdmin()
                ? $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => 'somcp', 'somcp_tab' => 'agent'])
                : '',
        ]);

        $this->content = $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'somcp/views/templates/admin/somcp/agent.tpl'
        );

        parent::initContent();
    }

    /**
     * Sends a message, starting a conversation when there is none yet.
     */
    public function ajaxProcessSend()
    {
        Somcp::bootClasses();

        $text = trim((string) Tools::getValue('text'));
        if ($text === '') {
            $this->answer(['error' => 'empty']);
        }
        $text = Tools::substr($text, 0, self::MAX_MESSAGE);

        $agent = $this->chosenAgent();
        if ($agent === null) {
            $this->answer(['error' => 'unavailable']);
        }

        $idConversation = (int) Tools::getValue('id_conversation');
        if ($idConversation > 0) {
            $this->ownConversation($idConversation);
        } else {
            $title = (string) preg_replace('/\s+/', ' ', $text);
            $idConversation = SomcpAgentStore::start(
                (int) $this->context->employee->id,
                Somcp::currentShopId(),
                Tools::strlen($title) > 80 ? Tools::substr($title, 0, 79) . '…' : $title,
                $agent['provider'],
                $agent['model']
            );
            if ($idConversation === 0) {
                $this->answer(['error' => 'storage']);
            }
        }

        // Writing again while a change waits for approval means "not that":
        // the change is refused, and the model reads why before the new message.
        if (SomcpAgent::isWaiting(SomcpAgentStore::turns($idConversation))) {
            SomcpAgent::decide($idConversation, false, $this->context->employee);
        }

        SomcpAgentStore::add($idConversation, 'user', ['text' => $text]);

        $this->runTurn($idConversation, $agent);
    }

    /**
     * Lets the model carry on after its tools ran.
     */
    public function ajaxProcessContinue()
    {
        Somcp::bootClasses();

        $idConversation = (int) Tools::getValue('id_conversation');
        $this->ownConversation($idConversation);

        $agent = $this->chosenAgent();
        if ($agent === null) {
            $this->answer(['error' => 'unavailable'] + $this->state($idConversation));
        }

        // Asked again while a change waits - two tabs open on one
        // conversation - the model must not be called with a call unanswered.
        if (SomcpAgent::isWaiting(SomcpAgentStore::turns($idConversation))) {
            $this->answer(['state' => 'confirm'] + $this->state($idConversation));
        }

        $this->runTurn($idConversation, $agent);
    }

    /**
     * The employee approves or refuses the changes waiting.
     */
    public function ajaxProcessDecide()
    {
        Somcp::bootClasses();

        $idConversation = (int) Tools::getValue('id_conversation');
        $this->ownConversation($idConversation);

        SomcpAgent::decide($idConversation, (string) Tools::getValue('approve') === '1', $this->context->employee);

        $this->answer(['state' => 'continue'] + $this->state($idConversation));
    }

    /**
     * Opens a conversation.
     */
    public function ajaxProcessOpen()
    {
        Somcp::bootClasses();

        $idConversation = (int) Tools::getValue('id_conversation');
        $conversation = $this->ownConversation($idConversation);
        $turns = SomcpAgentStore::turns($idConversation);

        $this->answer([
            'state' => SomcpAgent::isWaiting($turns) ? 'confirm' : 'done',
            'agent' => $conversation['provider'] . ':' . $conversation['model'],
        ] + $this->state($idConversation));
    }

    /**
     * Deletes a conversation.
     */
    public function ajaxProcessRemove()
    {
        Somcp::bootClasses();

        $idConversation = (int) Tools::getValue('id_conversation');
        $this->ownConversation($idConversation);
        SomcpAgentStore::delete($idConversation);

        $this->answer(['conversations' => $this->conversations()]);
    }

    /**
     * Runs one turn of the model and answers with the conversation.
     *
     * @param int $idConversation
     * @param array{provider:string, model:string} $agent
     */
    private function runTurn(int $idConversation, array $agent): void
    {
        // A model can think for a minute and a tool can be slow; the default
        // thirty seconds would cut the answer off with nothing saved.
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $outcome = SomcpAgent::turn($idConversation, $agent['provider'], $agent['model'], $this->context->employee);

        $this->answer([
            'state' => $outcome['state'],
            'error' => $outcome['error'],
            'detail' => $outcome['detail'],
        ] + $this->state($idConversation));
    }

    /**
     * What every answer carries: the conversation and the list beside it.
     *
     * @param int $idConversation
     *
     * @return array<string, mixed>
     */
    private function state(int $idConversation): array
    {
        return [
            'id_conversation' => $idConversation,
            'items' => SomcpAgent::view(SomcpAgentStore::turns($idConversation)),
            'conversations' => $this->conversations(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function conversations(): array
    {
        return SomcpAgentStore::conversations((int) $this->context->employee->id, Somcp::currentShopId());
    }

    /**
     * The conversation asked for, or the request ends: an id belonging to
     * someone else is answered exactly like one that does not exist.
     *
     * @param int $idConversation
     *
     * @return array{id_conversation:int, title:string, provider:string, model:string}
     */
    private function ownConversation(int $idConversation): array
    {
        $conversation = SomcpAgentStore::conversation($idConversation, (int) $this->context->employee->id, Somcp::currentShopId());
        if ($conversation === null) {
            $this->answer(['error' => 'not_found', 'conversations' => $this->conversations()], 404);
        }

        return $conversation;
    }

    /**
     * The agent picked in the page, if it is still switched on.
     *
     * @return array{provider:string, provider_label:string, model:string, label:string, role:string}|null
     */
    private function chosenAgent(): ?array
    {
        $parts = explode(':', (string) Tools::getValue('agent'), 2);
        if (count($parts) !== 2) {
            return null;
        }

        return SomcpAgent::agentFor($parts[0], $parts[1]);
    }

    /**
     * Sends a JSON answer and ends the request.
     *
     * @param array<string, mixed> $payload
     * @param int $status
     */
    private function answer(array $payload, int $status = 200): never
    {
        SomcpHttp::json($payload + ['state' => 'done', 'error' => '', 'detail' => ''], $status);

        // json() already ends the request; said again so the type holds.
        exit;
    }
}
