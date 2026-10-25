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
 * O'So MCP Server - turns a PrestaShop shop into an MCP server.
 *
 * An assistant connects to the shop the way any OAuth client connects to any
 * service: it discovers the endpoints, the merchant signs in with their usual
 * PrestaShop account, approves the connection, and the assistant receives a
 * token that carries no more rights than the employee who granted it.
 *
 * This version builds the foundation only - transport, authorization, discovery
 * and the registry tools plug into. The tools that read products, orders or
 * customers come next, one at a time, each with the scope that guards it.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Somcp extends Module
{
    /** @var array<int, string> Hooks without which the module cannot work. */
    private const ESSENTIAL_HOOKS = ['moduleRoutes'];

    /** @var array<int, string> Hooks the module works without, in a reduced way. */
    private const OPTIONAL_HOOKS = ['displayBackOfficeHeader', 'displayBackOfficeTop'];

    /** @var string Key the settings page leaves a message under, for the page after the redirect. */
    private const FLASH_KEY = 'somcp_flash';

    /** @var array<int, string> Classes loaded together, in dependency order. */
    private const CLASSES = [
        'SomcpConfig',
        'SomcpRandom',
        'SomcpLogger',
        'SomcpHttp',
        'SomcpScope',
        'SomcpPermission',
        'SomcpMetadata',
        'SomcpOAuthClient',
        'SomcpOAuthToken',
        'SomcpOAuthServer',
        'SomcpBackOffice',
        'SomcpJsonRpc',
        'SomcpProtocol',
        'SomcpRequestState',
        'SomcpElicitation',
        'SomcpUpload',
        'SomcpTool',
        'SomcpToolRegistry',
        'SomcpPrompt',
        'SomcpPromptRegistry',
        'SomcpContract',
        'SomcpDiagnostic',
        'SomcpUpdate',
        'SomcpAssistant',
        'SomcpAssistantChat',
    ];

    /** @var array<int, string> */
    private $confirmations = [];

    /** @var array<int, string> */
    private $pageErrors = [];

    public function __construct()
    {
        $this->name = 'somcp';
        $this->tab = 'administration';
        $this->version = '1.1.0';
        $this->author = 'Novalo';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('O\'So MCP Server', [], 'Modules.Somcp.Admin');
        $this->description = $this->trans(
            'Lets AI assistants such as Claude, ChatGPT or Cursor connect to this shop, with each employee approving what they may do.',
            [],
            'Modules.Somcp.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Uninstalling deletes every connected assistant, approval and token. Assistants connected right now lose their access immediately. Continue?',
            [],
            'Modules.Somcp.Admin'
        );
    }

    /**
     * Loads the module classes.
     *
     * There is no autoloader and no Composer dependency, following the same
     * choice as the other Novalo modules: one require per file, called from the
     * entry points that need them.
     */
    public static function bootClasses(): void
    {
        foreach (self::CLASSES as $class) {
            if (!class_exists($class, false)) {
                require_once _PS_MODULE_DIR_ . 'somcp/classes/' . $class . '.php';
            }
        }
    }

    /**
     * The legacy context this module instance was built with.
     *
     * Module already holds it in a protected property, so the module has no
     * reason to ask the core for it a second time - which PrestaShop 9 flags,
     * being in the middle of moving that context behind Symfony services.
     *
     * @return Context
     */
    public function getModuleContext(): Context
    {
        return $this->context;
    }

    /**
     * The legacy context, for the classes and tools around the module.
     *
     * One way in, and it goes through the module instance. The day this has to
     * become a service lookup, there is one method to change rather than every
     * class that needs to know which shop is answering.
     *
     * @return Context
     *
     * @throws PrestaShopException when the module itself cannot be loaded
     */
    public static function legacyContext(): Context
    {
        $module = Module::getInstanceByName('somcp');
        if (!$module instanceof self) {
            throw new PrestaShopException('The somcp module could not be loaded.');
        }

        return $module->getModuleContext();
    }

    /**
     * Translates from a static context.
     *
     * Tools declare themselves in a static define(), with no module instance to
     * hand. Rather than let their titles stay in English in a back office the
     * merchant reads in their own language, they go through here.
     *
     * Falls back to the source string instead of throwing: a missing catalogue
     * must not take the tool listing down with it.
     *
     * @param string $source
     * @param array<string, string> $params
     *
     * @return string
     */
    public static function t(string $source, array $params = []): string
    {
        $module = Module::getInstanceByName('somcp');

        return $module instanceof self ? $module->l10n($source, $params) : $source;
    }

    /**
     * The shop answering the current request.
     *
     * @return Shop
     */
    public static function currentShop(): Shop
    {
        return self::legacyContext()->shop;
    }

    /**
     * Identifier of the shop answering the current request.
     *
     * @return int
     */
    public static function currentShopId(): int
    {
        return (int) self::legacyContext()->shop->id;
    }

    /**
     * Translates one of the module's own strings.
     *
     * Module::trans() is protected, so the classes and controllers around the
     * module cannot call it. They come through here, which also keeps the
     * translation domain written in exactly one place.
     *
     * @param string $source English source string, as it appears in the XLF files
     * @param array<string, string> $params placeholders, e.g. ['%status%' => '404']
     *
     * @return string
     */
    public function l10n(string $source, array $params = []): string
    {
        return $this->trans($source, $params, 'Modules.Somcp.Admin');
    }

    /**
     * @return bool
     */
    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        self::bootClasses();

        foreach (self::ESSENTIAL_HOOKS as $hook) {
            if (!$this->registerHook($hook)) {
                $this->_errors[] = $this->trans(
                    'The %hook% hook could not be registered, and the module cannot answer without it.',
                    ['%hook%' => $hook],
                    'Modules.Somcp.Admin'
                );

                return false;
            }
        }

        foreach (self::OPTIONAL_HOOKS as $hook) {
            // A shop that refuses one of these still works; it only loses the
            // convenience the hook provides.
            $this->registerHook($hook);
        }

        if (!$this->installDatabase()) {
            $this->_errors[] = $this->trans('The module tables could not be created.', [], 'Modules.Somcp.Admin');

            return false;
        }

        if (!$this->installTabs()) {
            $this->_errors[] = $this->trans('The module pages could not be installed.', [], 'Modules.Somcp.Admin');

            return false;
        }

        $this->declareToolHook();
        SomcpConfig::seed();
        SomcpBackOffice::rememberAdminDir();

        return true;
    }

    /**
     * @return bool
     */
    public function uninstall(): bool
    {
        self::bootClasses();

        // Data first, then PrestaShop's own cleanup: doing it the other way
        // round leaves the module gone and its tables behind.
        $this->uninstallTabs();
        $this->uninstallDatabase();
        $this->undeclareToolHook();
        SomcpUpload::removeStore();
        SomcpConfig::purge();
        SomcpUpdate::purge();
        SomcpAssistant::purge();

        return parent::uninstall();
    }

    /**
     * Declares the routes that cannot live under /module/somcp/.
     *
     * Only one address is in that situation: the authorization server document,
     * which RFC 8414 anchors at the root of the domain. PrestaShop matches
     * these routes only when friendly URLs are enabled, and a shop installed in
     * a subdirectory cannot reach the domain root at all - the diagnostic in
     * the settings reports both cases by calling the address for real.
     *
     * @return array<string, array<string, mixed>>
     */
    public function hookModuleRoutes(): array
    {
        self::bootClasses();

        return [
            'module-somcp-wellknown-as' => [
                'controller' => 'wellknown',
                'rule' => '.well-known/oauth-authorization-server/' . SomcpConfig::ISSUER_PATH,
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => 'somcp',
                    'controller' => 'wellknown',
                ],
            ],
        ];
    }

    /**
     * Keeps track of the administration folder name, and puts the chat button
     * of the built-in assistant on every back office page.
     *
     * A front controller has no _PS_ADMIN_DIR_, so the authorization endpoint
     * could not build the address of the consent screen without this. The value
     * is refreshed on every back office page because PrestaShop renames that
     * folder by itself the first time a shop is used.
     *
     * The chat is written straight into the page head rather than added through
     * the controller's media list: pages built on Symfony have no such list,
     * and this hook output reaches the head of both kinds of page.
     *
     * @return string
     */
    public function hookDisplayBackOfficeHeader(): string
    {
        self::bootClasses();
        SomcpBackOffice::rememberAdminDir();

        $employee = $this->context->employee;
        if (!$employee instanceof Employee || (int) $employee->id <= 0
            || !SomcpAssistantChat::available()
            || SomcpAssistant::leader() === null
            || !SomcpAssistant::mayUse((int) $employee->id_profile)) {
            return '';
        }

        $config = [
            'endpoint' => $this->context->link->getAdminLink(SomcpBackOffice::ASSISTANT_CONTROLLER, true, [], ['ajax' => 1]),
            'neuron' => 'https://github.com/neuron-core/neuron-ai',
            // Symfony pages keep their legacy name here too, e.g. AdminOrders.
            'page' => isset($this->context->controller->controller_name) ? (string) $this->context->controller->controller_name : (string) Tools::getValue('controller'),
            'maxFiles' => SomcpAssistantChat::MAX_FILES,
            'maxFileBytes' => SomcpAssistantChat::MAX_FILE_BYTES,
            // The gear in the chat header opens the module settings, in a new
            // tab so the page the employee is on is not lost.
            'settingsUrl' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => 'somcp']),
            'text' => [
                'title' => $this->l10n('Built-in assistant'),
                'subtitle' => $this->l10n('Acts with your rights and asks before any change'),
                'open' => $this->l10n('Open the built-in assistant'),
                'button' => $this->l10n('Assistant'),
                'enlarge' => $this->l10n('Enlarge'),
                'reduce' => $this->l10n('Reduce'),
                'mistakes' => $this->l10n('This is an AI and can make mistakes.'),
                'close' => $this->l10n('Close'),
                'settings' => $this->l10n('Module settings'),
                'placeholder' => $this->l10n('Write your message...'),
                'send' => $this->l10n('Send'),
                'hello' => $this->l10n('Hello! What can I do for the shop?'),
                'newThread' => $this->l10n('New conversation'),
                'threads' => $this->l10n('Conversations'),
                'deleteThread' => $this->l10n('Delete this conversation'),
                'defaultAssistant' => $this->l10n('Default assistant'),
                'approvalTitle' => $this->l10n('Confirmation requested'),
                'approve' => $this->l10n('Approve'),
                'refuse' => $this->l10n('Refuse'),
                'approved' => $this->l10n('Approved'),
                'refused' => $this->l10n('Refused'),
                'working' => $this->l10n('Working...'),
                'spent' => $this->l10n('This month: %spent%'),
                'spentOf' => $this->l10n('This month: %spent% of %budget%'),
                'failed' => $this->l10n('Something went wrong. Try again.'),
                'poweredBy' => $this->l10n('Powered by'),
                'attach' => $this->l10n('Attach files from your computer'),
                'removeFile' => $this->l10n('Remove this file'),
                'fileTooBig' => $this->l10n('This file cannot be sent: only images, PDF and text files up to 5 MB are accepted.'),
            ],
        ];

        $json = (string) json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $base = $this->_path . 'views/';
        $version = urlencode($this->version);

        return '<link rel="stylesheet" href="' . htmlspecialchars($base . 'css/assistant.css?v=' . $version, ENT_QUOTES) . '">'
            . '<script>window.somcpAssistant = ' . $json . ';</script>'
            . '<script src="' . htmlspecialchars($base . 'js/assistant.js?v=' . $version, ENT_QUOTES) . '" defer></script>';
    }

    /**
     * The button that opens the built-in assistant, in the back office header.
     *
     * displayBackOfficeTop is printed inside the header bar on legacy pages and
     * on Symfony pages alike, on PrestaShop 8 and 9, so the button is written
     * by the server where the header is built rather than slipped in by a
     * script looking for its place. The hook prints it at the end of the bar;
     * the chat script then sets it right after "View my shop", where the
     * merchant looks for it, and leaves it where it is if that link is absent.
     *
     * @return string
     */
    public function hookDisplayBackOfficeTop(): string
    {
        self::bootClasses();

        $employee = $this->context->employee;
        if (!$employee instanceof Employee || (int) $employee->id <= 0
            || !SomcpAssistantChat::available()
            || SomcpAssistant::leader() === null
            || !SomcpAssistant::mayUse((int) $employee->id_profile)) {
            return '';
        }

        $label = htmlspecialchars($this->l10n('Assistant'), ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($this->l10n('Open the built-in assistant'), ENT_QUOTES, 'UTF-8');

        return '<div class="component" id="somcp-chat-component">'
            . '<a href="#" id="somcp-chat-button" class="somcp-header-link" role="button" title="' . $title . '">'
            . '<i class="material-icons">forum</i><span>' . $label . '</span></a></div>';
    }

    /**
     * The settings page.
     *
     * Everything lives here, in one page split into tabs. Every action posts
     * back to this page and is redirected, so a reload never repeats it.
     *
     * @return string
     */
    public function getContent(): string
    {
        self::bootClasses();
        SomcpBackOffice::rememberAdminDir();

        // A second chance for the deposits to be cleared. The purge otherwise
        // runs only when a new file request is opened, so a shop where nobody
        // asks again would keep the last expired one on disk for ever.
        SomcpUpload::purge();

        // Before the forms are read, not merely before the page is drawn: an
        // employee who rebuilt the form by hand would otherwise still be able
        // to change the settings, disconnect somebody else's assistant or empty
        // the log. Connecting an assistant is a separate decision and stays
        // open to the profiles the administrator allowed.
        if (!$this->context->employee->isSuperAdmin()) {
            return $this->renderRestrictedNotice();
        }

        $diagnostic = [];

        if (Tools::isSubmit('submitSomcpSettings')) {
            $this->saveSettings();
            $this->redirectToSelf();
        }
        if (Tools::isSubmit('submitSomcpWithdraw')) {
            SomcpOAuthServer::withdrawConsent((int) Tools::getValue('id_consent'));
            $this->flash($this->trans('The assistant has been disconnected.', [], 'Modules.Somcp.Admin'));
            $this->redirectToSelf();
        }
        if (Tools::isSubmit('submitSomcpClearLog')) {
            SomcpLogger::clear();
            $this->flash($this->trans('The log has been emptied.', [], 'Modules.Somcp.Admin'));
            $this->redirectToSelf();
        }
        if (Tools::isSubmit('submitSomcpAssistant')) {
            $this->saveAssistant();
        }
        if (Tools::isSubmit('submitSomcpAssistantTest')) {
            $tested = SomcpAssistant::get((int) Tools::getValue('id_assistant'));
            $model = $tested !== null ? SomcpAssistant::test($tested) : '';
            if ($model !== '') {
                $this->flash($this->trans('The key works. Model in use: %model%.', ['%model%' => $model], 'Modules.Somcp.Admin'));
            } else {
                $this->flash($this->trans('The provider refused the key or did not answer. Check the key and try again.', [], 'Modules.Somcp.Admin'), true);
            }
            $this->redirectToSelf();
        }
        if (Tools::isSubmit('submitSomcpAssistantDelete')) {
            SomcpAssistant::delete((int) Tools::getValue('id_assistant'));
            $this->flash($this->trans('The assistant has been removed.', [], 'Modules.Somcp.Admin'));
            $this->redirectToSelf();
        }
        if (Tools::isSubmit('submitSomcpAssistantDefault')) {
            SomcpAssistant::makeDefault((int) Tools::getValue('id_assistant'));
            $this->flash($this->trans('Settings saved.', [], 'Modules.Somcp.Admin'));
            $this->redirectToSelf();
        }
        if (Tools::isSubmit('submitSomcpAssistantSettings')) {
            SomcpAssistant::saveSettings(
                (float) str_replace(',', '.', (string) Tools::getValue('SOMCP_ASSISTANT_BUDGET')),
                (string) Tools::getValue('SOMCP_ASSISTANT_INSTRUCTIONS'),
                (int) Tools::getValue('SOMCP_ASSISTANT_PROFILE')
            );
            $this->flash($this->trans('Settings saved.', [], 'Modules.Somcp.Admin'));
            $this->redirectToSelf();
        }
        if (Tools::isSubmit('submitSomcpUpdateSwitch')) {
            SomcpUpdate::setOn(Tools::getValue(SomcpUpdate::CHECK) === '1');
            $this->flash($this->trans('Settings saved.', [], 'Modules.Somcp.Admin'));
            $this->redirectToSelf();
        }
        if (Tools::isSubmit('submitSomcpUpdateCheck')) {
            $checked = SomcpUpdate::state($this->version, true);
            if ($checked['failed']) {
                $this->flash($this->trans('GitHub could not be reached. Try again later.', [], 'Modules.Somcp.Admin'));
            } elseif ($checked['available']) {
                $this->flash($this->trans('Version %version% is available.', ['%version%' => $checked['latest']], 'Modules.Somcp.Admin'));
            } else {
                $this->flash($this->trans('You have the latest version.', [], 'Modules.Somcp.Admin'));
            }
            $this->redirectToSelf();
        }
        if (Tools::isSubmit('submitSomcpDiagnostic')) {
            // Redirected like every other action on this page, with the chosen
            // shop carried in the address. Running the checks straight from the
            // posted form left the browser asking to send it again on every
            // reload - and the checks only read, so replaying them costs
            // nothing and the result survives a refresh.
            // The shop picker only exists on a multistore installation that is
            // not already narrowed to one shop, so most of the time nothing is
            // posted here. Read on its own that gave zero, the redirect asked
            // for shop zero, and the page came back with no diagnostic at all -
            // the button appeared to do nothing but reload.
            $asked = (int) Tools::getValue('somcp_diag_shop');
            $this->redirectToSelf([
                'somcp_diag' => $asked > 0 ? $asked : Somcp::currentShopId(),
            ]);
        }

        $diagnosticShop = (int) Tools::getValue('somcp_diag');
        if ($diagnosticShop > 0) {
            $diagnostic = SomcpDiagnostic::run($this, $diagnosticShop);
        } else {
            $diagnosticShop = (int) $this->context->shop->id;
        }

        $this->readFlash();

        $update = SomcpUpdate::state($this->version);

        // The model list for the Custom level: read from the provider only when
        // an existing assistant with a key is open for editing, so the settings
        // page never calls a provider just to be shown.
        $editing = $this->assistantBeingEdited();
        $editModels = ($editing !== null && !empty($editing['has_key']) && (int) $editing['id_assistant'] > 0)
            ? SomcpAssistant::models($editing)
            : [];

        $this->context->smarty->assign([
            'somcp_form_action' => $this->selfUrl(),
            'somcp_name' => $this->displayName,
            'somcp_version' => $this->version,
            'somcp_repository' => SomcpUpdate::REPOSITORY,
            'somcp_update' => $update,
            'somcp_assistants' => SomcpAssistant::all(),
            'somcp_assistant_costs' => SomcpAssistant::monthCosts(),
            'somcp_assistant_spent' => array_sum(SomcpAssistant::monthCosts()),
            'somcp_assistant_budget' => SomcpAssistant::budget(),
            'somcp_assistant_instructions' => SomcpAssistant::instructions(),
            'somcp_assistant_profile' => (int) Configuration::get(SomcpAssistant::PROFILE, null, null, (int) self::currentShopId()),
            'somcp_assistant_providers' => SomcpAssistant::providers(),
            'somcp_assistant_image_providers' => implode(' ', SomcpAssistant::IMAGE_PROVIDERS),
            'somcp_assistant_edit' => $editing,
            'somcp_assistant_models' => $editModels,
            'somcp_assistant_ready' => SomcpAssistantChat::available(),
            // Formatted here, in the employee's language, rather than in the
            // template: Smarty's date modifier knows nothing of the back
            // office date formats.
            'somcp_update_checked' => $update['checked'] > 0
                ? date((string) $this->context->language->date_format_full, $update['checked'])
                : '',
            'somcp_settings' => $this->currentSettings(),
            'somcp_profiles' => Profile::getProfiles((int) $this->context->language->id),
            'somcp_diagnostic' => $diagnostic,
            // One shop is diagnosed at a time: the checks are real HTTP calls,
            // and a merchant reads the result of a shop they chose.
            'somcp_diag_shops' => SomcpConfig::contextEndpoints(),
            'somcp_diag_shop' => $diagnosticShop,
            'somcp_diag_pick' => Shop::isFeatureActive() && Shop::getContext() !== Shop::CONTEXT_SHOP,
            'somcp_endpoints' => SomcpConfig::contextEndpoints(),
            'somcp_issuer' => SomcpConfig::issuer(),
            'somcp_multistore' => Shop::isFeatureActive(),
            'somcp_tools' => $this->toolList(),
            'somcp_prompts' => $this->promptList(),
            'somcp_inheritable' => SomcpConfig::inheritableContext(),
            'somcp_inherit' => $this->inheritanceMap(),
            'somcp_consents' => SomcpOAuthServer::consents(),
            'somcp_logs' => SomcpLogger::recent(),
            'somcp_confirmations' => $this->confirmations,
            'somcp_errors' => $this->pageErrors,
        ]);

        return $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'somcp/views/templates/admin/somcp/configure.tpl'
        );
    }

    /**
     * Saves the assistant form of the Built-in assistant tab.
     */
    private function saveAssistant(): void
    {
        $idAssistant = SomcpAssistant::save((int) Tools::getValue('id_assistant'), [
            'provider' => (string) Tools::getValue('somcp_assistant_provider'),
            'api_key' => (string) Tools::getValue('somcp_assistant_key'),
            'level' => (string) Tools::getValue('somcp_assistant_level'),
            'model' => (string) Tools::getValue('somcp_assistant_model'),
            'role' => (string) Tools::getValue('somcp_assistant_role'),
            'active' => Tools::getValue('somcp_assistant_active') === '1',
        ]);

        // Tested right away: a key pasted with a stray space or for the wrong
        // provider is better found here than on the first message in the chat.
        $saved = SomcpAssistant::get($idAssistant);
        $model = $saved !== null ? SomcpAssistant::test($saved) : '';
        if ($model !== '') {
            $this->flash($this->trans('Assistant saved. The key works. Model in use: %model%.', ['%model%' => $model], 'Modules.Somcp.Admin'));
        } else {
            $this->flash($this->trans('Assistant saved, but the provider refused the key or did not answer. Check the key.', [], 'Modules.Somcp.Admin'), true);
        }
        $this->redirectToSelf();
    }

    /**
     * The assistant open in the form: an existing one when the merchant clicked
     * Edit, a blank one when they clicked Add, null when the form is closed.
     *
     * @return array<string, mixed>|null
     */
    private function assistantBeingEdited(): ?array
    {
        if (Tools::getValue('somcp_assistant_add') === '1') {
            return ['id_assistant' => 0, 'provider' => 'anthropic', 'has_key' => false, 'level' => SomcpAssistant::LEVEL_ECONOMY, 'model' => '', 'role' => '', 'active' => true];
        }
        $idAssistant = (int) Tools::getValue('somcp_assistant_edit');

        return $idAssistant > 0 ? SomcpAssistant::get($idAssistant) : null;
    }

    /**
     * Page shown to an employee who is not an administrator of the shop.
     *
     * @return string
     */
    private function renderRestrictedNotice(): string
    {
        $mayGrant = SomcpBackOffice::mayGrant((int) $this->context->employee->id_profile);

        $this->context->smarty->assign([
            'somcp_restricted_title' => $this->l10n('Settings reserved for the shop administrator'),
            // Two messages rather than one covering both cases: an employee who
            // is shown the address has nothing to ask an administrator for, and
            // reading "if the administrator allowed it" under an address they
            // can already use only makes them doubt it.
            'somcp_restricted_message' => $mayGrant
                ? $this->l10n('Only an administrator of this shop can change the O\'So MCP Server settings.')
                : $this->l10n(
                    'Only an administrator of this shop can change the O\'So MCP Server settings,'
                    . ' and your account is not allowed to connect an assistant. Ask an administrator if you need one.'
                ),
            // The address is only shown to an employee who could actually use
            // it. Handing it to someone whose profile is not allowed would
            // only lead them to a refusal at the end of the connection.
            'somcp_may_grant' => $mayGrant,
            // One address per shop of the context, exactly as on the settings
            // page: an employee working on several shops connects an assistant
            // to each of them separately.
            'somcp_endpoints' => $mayGrant ? SomcpConfig::contextEndpoints() : [],
            'somcp_multistore' => Shop::isFeatureActive(),
        ]);

        return $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'somcp/views/templates/admin/somcp/restricted.tpl'
        );
    }

    /**
     * Where each setting's current value comes from, for the form.
     *
     * @return array<string, array{source:string, own:bool}>
     */
    private function inheritanceMap(): array
    {
        $map = [];
        foreach (array_keys(SomcpConfig::defaults()) as $key) {
            $map[$key] = SomcpConfig::level($key);
        }

        return $map;
    }

    /**
     * Reads the settings form.
     */
    private function saveSettings(): void
    {
        $inheritable = SomcpConfig::inheritableContext();
        $ownFields = [];
        foreach (array_keys(SomcpConfig::defaults()) as $key) {
            // A field whose "own value" box is unchecked is not saved at all:
            // its row at this level is removed and the value comes from above.
            if ($inheritable && $key !== SomcpConfig::ADMIN_DIR
                && Tools::getValue('somcp_own_' . $key) !== '1') {
                SomcpConfig::inherit($key);

                continue;
            }
            $ownFields[$key] = true;
        }

        $switches = [
            SomcpConfig::ENABLED,
            SomcpConfig::OAUTH_ENABLED,
            SomcpConfig::REFRESH_ROTATION,
            SomcpConfig::CIMD_ENABLED,
            SomcpConfig::DCR_ENABLED,
            SomcpConfig::LOG_ENABLED,
        ];
        foreach ($switches as $key) {
            if (isset($ownFields[$key])) {
                SomcpConfig::set($key, Tools::getValue($key) === '1' ? '1' : '0');
            }
        }

        if (isset($ownFields[SomcpConfig::ACCESS_TTL])) {
            SomcpConfig::set(SomcpConfig::ACCESS_TTL, (string) max(60, (int) Tools::getValue(SomcpConfig::ACCESS_TTL)));
        }
        if (isset($ownFields[SomcpConfig::REFRESH_TTL])) {
            SomcpConfig::set(SomcpConfig::REFRESH_TTL, (string) max(600, (int) Tools::getValue(SomcpConfig::REFRESH_TTL)));
        }
        if (isset($ownFields[SomcpConfig::REQUIRED_PROFILE])) {
            SomcpConfig::set(SomcpConfig::REQUIRED_PROFILE, (string) max(0, (int) Tools::getValue(SomcpConfig::REQUIRED_PROFILE)));
        }

        if (isset($ownFields[SomcpConfig::TOOLS_TTL])) {
            // Bounds repeated here rather than trusted from the form: the
            // endpoint clamps the value it announces anyway, and a field left
            // saying 0 while the shop announces 10 would read as a bug.
            SomcpConfig::set(
                SomcpConfig::TOOLS_TTL,
                (string) max(10, min(3600, (int) Tools::getValue(SomcpConfig::TOOLS_TTL)))
            );
        }

        if (isset($ownFields[SomcpConfig::TOOLS_OFF])) {
            SomcpConfig::set(SomcpConfig::TOOLS_OFF, $this->readToolSwitches());
        }

        if (isset($ownFields[SomcpConfig::PROMPTS_OFF])) {
            SomcpConfig::set(SomcpConfig::PROMPTS_OFF, $this->readPromptSwitches());
        }

        if (isset($ownFields[SomcpConfig::REDIRECT_HOSTS])) {
            $hosts = (string) Tools::getValue(SomcpConfig::REDIRECT_HOSTS, '');
            // Host names only: anything else here would silently widen what the
            // module accepts as a return address.
            $hosts = (string) preg_replace('/[^a-zA-Z0-9.\-\s,;]/', '', $hosts);
            SomcpConfig::set(SomcpConfig::REDIRECT_HOSTS, Tools::substr(trim($hosts), 0, 500));
        }

        $this->flash($this->trans('Settings saved.', [], 'Modules.Somcp.Admin'));
    }

    /**
     * The tools left switched off by the form, as the setting stores them.
     *
     * Which tools exist comes from the registry, never from the posted form:
     * the browser only says which boxes are ticked, so a hand-made post cannot
     * put an arbitrary string into the setting.
     *
     * A tool already switched off whose module is not loaded right now is kept
     * off. Dropping it would quietly switch it back on the moment that module
     * was enabled again, which is not what the merchant asked for.
     *
     * @return string
     */
    private function readToolSwitches(): string
    {
        $registry = SomcpToolRegistry::getInstance();
        $ticked = Tools::getValue('somcp_tool_on');
        $ticked = is_array($ticked) ? $ticked : [];

        $off = [];
        foreach (SomcpToolRegistry::disabled() as $name) {
            if (!$registry->has($name)) {
                $off[] = $name;
            }
        }

        foreach (array_keys($registry->all()) as $name) {
            if (!isset($ticked[$name])) {
                $off[] = $name;
            }
        }

        return implode(' ', array_unique($off));
    }

    /**
     * The prompts left switched off by the form, as the setting stores them.
     *
     * Same shape as the tools, and same reason for each part of it: the list
     * of what exists comes from the registry, and a prompt already off whose
     * module is not loaded right now stays off.
     *
     * @return string
     */
    private function readPromptSwitches(): string
    {
        $registry = SomcpPromptRegistry::getInstance();
        $ticked = Tools::getValue('somcp_prompt_on');
        $ticked = is_array($ticked) ? $ticked : [];

        $off = [];
        foreach (SomcpPromptRegistry::disabled() as $name) {
            if ($registry->get($name) === null) {
                $off[] = $name;
            }
        }

        foreach (array_keys($registry->all()) as $name) {
            if (!isset($ticked[$name])) {
                $off[] = $name;
            }
        }

        return implode(' ', array_unique($off));
    }

    /**
     * Every prompt the shop could offer, and whether it is on here.
     *
     * @return array<int, array<string, mixed>>
     */
    private function promptList(): array
    {
        $own = self::ownPromptNames();
        $list = [];
        foreach (SomcpPromptRegistry::getInstance()->all() as $name => $prompt) {
            $dash = strpos($name, '-');
            $asks = [];
            foreach ($prompt->getArguments() as $argument) {
                $asks[] = $argument['name'];
            }

            $list[] = [
                'name' => $name,
                'title' => $prompt->getTitle(),
                'description' => $prompt->getDescription(),
                // This module's own prompts hold dashes in their names too, so
                // the module is only read off a name the registry qualified.
                'module' => !in_array($name, $own, true) && $dash !== false ? Tools::substr($name, 0, $dash) : '',
                'asks' => implode(', ', $asks),
                'permission' => SomcpPermission::label($prompt->getRequiredPermissions()),
                'on' => SomcpPromptRegistry::isEnabled($name),
            ];
        }

        return $list;
    }

    /**
     * The names of the prompts shipped in this module's own prompts/ folder.
     *
     * @return array<int, string>
     */
    private static function ownPromptNames(): array
    {
        $names = [];
        foreach (glob(_PS_MODULE_DIR_ . 'somcp/prompts/*', GLOB_ONLYDIR) ?: [] as $folder) {
            $names[] = basename($folder);
        }

        return $names;
    }

    /**
     * Every tool the shop could offer, and whether it is switched on here.
     *
     * A tool published by another module carries that module's folder in front
     * of its name, so the list can say where each one comes from without the
     * registry having to keep a second record of it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function toolList(): array
    {
        $list = [];
        foreach (SomcpToolRegistry::getInstance()->all() as $tool) {
            $name = $tool->getName();
            $dash = strpos($name, '-');
            $described = $tool->describe();

            $list[] = [
                'name' => $name,
                'title' => $described['title'],
                'description' => $described['description'],
                'module' => $dash === false ? '' : Tools::substr($name, 0, $dash),
                'scopes' => implode(' ', $tool->getRequiredScopes()),
                // The merchant's own words here, not the controller name: this
                // table is read next to the Team, Permissions page, and the
                // two have to say the same thing.
                'permission' => SomcpPermission::label($tool->getRequiredPermissions()),
                'writes' => ($described['annotations']['readOnlyHint'] ?? true) === false,
                'on' => SomcpToolRegistry::isEnabled($name),
            ];
        }

        return $list;
    }

    /**
     * @return array<string, string>
     */
    private function currentSettings(): array
    {
        $values = [];
        foreach (array_keys(SomcpConfig::defaults()) as $key) {
            $values[$key] = SomcpConfig::get($key);
        }

        return $values;
    }

    /**
     * Stores a message for the page that follows the redirect.
     *
     * @param string $message
     */
    private function flash(string $message, bool $isError = false): void
    {
        // The employee cookie is capped at 4096 bytes, so a long message is cut
        // rather than left to break the whole cookie. A leading E or S carries
        // the kind across the redirect, so a failure lands in a red banner
        // rather than the green one every message used to get.
        // Through the magic accessor explicitly: the key is the module's own,
        // so it is not declared on Cookie and static analysis rightly says so.
        $this->context->cookie->__set(self::FLASH_KEY, ($isError ? 'E' : 'S') . Tools::substr($message, 0, 500));
        $this->context->cookie->write();
    }

    /**
     * Reads and clears the message left before the redirect.
     */
    private function readFlash(): void
    {
        $stored = (string) $this->context->cookie->__get(self::FLASH_KEY);
        if ($stored !== '') {
            $message = Tools::substr($stored, 1);
            if (Tools::substr($stored, 0, 1) === 'E') {
                $this->pageErrors[] = $message;
            } else {
                $this->confirmations[] = $message;
            }
            $this->context->cookie->__unset(self::FLASH_KEY);
            $this->context->cookie->write();
        }
    }

    /**
     * @param array<string, int|string> $extra extra query parameters
     *
     * @return string
     */
    private function selfUrl(array $extra = []): string
    {
        return $this->context->link->getAdminLink('AdminModules', true, [], array_merge([
            'configure' => $this->name,
            'tab_module' => $this->tab,
            'module_name' => $this->name,
        ], $extra));
    }

    /**
     * Sends the browser back to the settings page after a form was handled, so
     * that reloading does not repeat the action.
     *
     * @param array<string, int|string> $extra parameters to carry over
     */
    private function redirectToSelf(array $extra = []): void
    {
        SomcpHttp::redirect($this->selfUrl($extra));
    }

    /**
     * Creates the module tables. Every statement is CREATE TABLE IF NOT EXISTS,
     * so an upgrade script calls this to add the tables of a new version
     * without touching the ones a shop already has.
     *
     * @return bool
     */
    public function installDatabase(): bool
    {
        return $this->runSqlFile('install.sql');
    }

    /**
     * @return bool
     */
    private function uninstallDatabase(): bool
    {
        return $this->runSqlFile('uninstall.sql');
    }

    /**
     * Runs one of the module SQL files, statement by statement.
     *
     * @param string $file
     *
     * @return bool
     */
    private function runSqlFile(string $file): bool
    {
        $path = _PS_MODULE_DIR_ . 'somcp/sql/' . $file;
        if (!file_exists($path)) {
            return false;
        }

        $sql = (string) Tools::file_get_contents($path);
        $sql = str_replace(['PREFIX_', 'ENGINE=InnoDB'], [_DB_PREFIX_, 'ENGINE=' . _MYSQL_ENGINE_], $sql);

        // Comment lines are stripped before the file is split: a statement
        // introduced by a comment would otherwise be dropped whole.
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);

        // array_filter has already dropped the empty pieces left by the split.
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if (!Db::getInstance()->execute($statement)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The back office pages reached from outside the menu.
     *
     * The consent screen, where an assistant is allowed in, and the deposit
     * screen, where a file is handed to one. Neither belongs in the menu -
     * nobody opens them on purpose, they are the destination of a flow - but
     * both need a tab, because a controller without one has no security token
     * and PrestaShop refuses the page.
     *
     * A method rather than a class constant, and that is not a style choice.
     * PrestaShop reflects on this class to validate the module, and
     * ReflectionClass::getConstants() evaluates every constant at once -
     * including one naming a class that bootClasses() has not loaded yet. The
     * module then fails to install with "Class SomcpBackOffice not found".
     * Do not turn this back into a constant.
     *
     * @return array<int, string>
     */
    private static function hiddenTabs(): array
    {
        return [
            SomcpBackOffice::CONSENT_CONTROLLER,
            SomcpBackOffice::UPLOAD_CONTROLLER,
            SomcpBackOffice::ASSISTANT_CONTROLLER,
        ];
    }

    /**
     * Installs the hidden tabs the module's own pages need.
     *
     * @return bool
     */
    public function installTabs(): bool
    {
        foreach (self::hiddenTabs() as $controller) {
            if (Tab::getIdFromClassName($controller)) {
                continue;
            }

            $tab = new Tab();
            $tab->class_name = $controller;
            $tab->module = $this->name;
            $tab->active = true;
            // -1 keeps it out of the menu while leaving it reachable.
            $tab->id_parent = -1;
            $tab->name = [];
            foreach (Language::getLanguages(false) as $language) {
                $tab->name[(int) $language['id_lang']] = 'O\'So MCP Server';
            }

            if (!$tab->add()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    private function uninstallTabs(): bool
    {
        $removed = true;

        foreach (self::hiddenTabs() as $controller) {
            $idTab = (int) Tab::getIdFromClassName($controller);
            if ($idTab <= 0) {
                continue;
            }

            try {
                $tab = new Tab($idTab);
                $removed = (bool) $tab->delete() && $removed;
            } catch (Throwable $e) {
                // Best effort, and the loop carries on: a tab that refuses to go
                // must not leave the next one behind as well, nor block the rest
                // of the uninstall - the merchant would be left with a
                // half-removed module.
                $removed = false;
            }
        }

        return $removed;
    }

    /**
     * Creates the hook other modules use to publish their own MCP tools.
     *
     * A hook a module dispatches has to exist in the shop before another module
     * can attach to it from its own settings, so it is declared here rather
     * than left to appear by accident.
     */
    private function declareToolHook(): void
    {
        self::declareHook(SomcpToolRegistry::HOOK_REGISTER, 'Register MCP tools',
            'Lets a module publish its own tools to O\'So MCP Server.');
        self::declareHook(SomcpPromptRegistry::HOOK_REGISTER, 'Register MCP prompts',
            'Lets a module publish its own prompts to O\'So MCP Server.');
    }

    /**
     * Creates one of the hooks other modules attach to.
     *
     * A hook a module dispatches has to exist in the shop before another
     * module can attach to it from its own install, so both are declared
     * here rather than left to appear by accident.
     *
     * @param string $name
     * @param string $title
     * @param string $description
     */
    /**
     * Takes back the hooks install() created, when nothing else needs them.
     *
     * parent::uninstall() unregisters this module from the hooks it listens
     * to. It knows nothing of the two this module declares for other modules
     * to publish through, so they stayed in the hook table after the module
     * that created them was gone.
     *
     * A hook another module is still attached to is left alone: deleting it
     * takes that module's registration with it, and the module would come
     * back from its own reinstall with nothing listening. A hook in use is
     * not a leftover.
     */
    private function undeclareToolHook(): void
    {
        self::undeclareHook(SomcpToolRegistry::HOOK_REGISTER);
        self::undeclareHook(SomcpPromptRegistry::HOOK_REGISTER);
    }

    /**
     * @param string $name
     */
    private static function undeclareHook(string $name): void
    {
        $idHook = (int) Hook::getIdByName($name);
        if ($idHook <= 0) {
            return;
        }

        $attached = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'hook_module` WHERE `id_hook` = ' . $idHook
        );
        if ($attached > 0) {
            return;
        }

        try {
            $hook = new Hook($idHook);
            if (Validate::isLoadedObject($hook)) {
                $hook->delete();
            }
        } catch (Throwable $e) {
            // Best effort, like the tabs above: a row that refuses to go is a
            // stray row, not a reason to stop an uninstall halfway through.
        }
    }

    private static function declareHook(string $name, string $title, string $description): void
    {
        if (Hook::getIdByName($name)) {
            return;
        }

        try {
            $hook = new Hook();
            $hook->name = $name;
            $hook->title = $title;
            $hook->description = $description;
            $hook->position = false;
            $hook->active = true;
            $hook->add();
        } catch (Throwable $e) {
            // The module still serves its own tools and prompts without it;
            // only the extension point is missing, and a later install can
            // add it.
        }
    }
}
