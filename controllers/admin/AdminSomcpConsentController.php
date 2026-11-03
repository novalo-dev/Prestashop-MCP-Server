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
 * The consent screen.
 *
 * It lives in the back office for one reason: PrestaShop decides who the
 * visitor is. An employee who is not signed in is sent to the usual login form
 * by PrestaShop itself, and comes back here on an address PrestaShop built,
 * carrying a valid security token. Nothing about that is reimplemented here,
 * and no core method is overridden to get around it.
 *
 * The OAuth request is not in the URL - it waits in the database, and the
 * browser carries nothing but an opaque cookie.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSomcpConsentController extends ModuleAdminController
{
    /**
     * @var Somcp the module instance, narrowed from the generic Module the
     *            parent declares so static analysis can resolve its public API
     */
    public $module;

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        // Checked rather than assigned straight away: getInstanceByName()
        // returns false when the module is missing, which the typed property
        // does not accept.
        $module = Module::getInstanceByName('somcp');
        if ($module instanceof Somcp) {
            $this->module = $module;
        }
    }

    /**
     * The permission slug of this controller, unchanged from the parent.
     *
     * Declared here for one reason. PrestaShop 9 works out whether a legacy
     * controller carries its own permission rules by reflecting on THIS method
     * (LegacyController::isMethodOverridden, l. 272-282), even though the
     * comment above that call names viewAccess. Without a declaration here,
     * viewAccess() below is never consulted, the request falls through to the
     * tab permissions, and every employee outside the super administrator
     * profile gets a 403 on the consent screen. Remove it the day PrestaShop
     * reflects on the method it actually means.
     *
     * @return string
     */
    public function getTabSlug()
    {
        return parent::getTabSlug();
    }

    /**
     * Any signed-in employee may answer for themselves.
     *
     * The tab is hidden and has no permissions of its own, so the usual check
     * would refuse every profile except the super administrator. What an
     * employee grants here never exceeds what that same employee can already do
     * in the back office, so the decision is theirs to make.
     *
     * @param bool $disable
     *
     * @return bool
     */
    public function viewAccess($disable = false)
    {
        return true;
    }

    /**
     * Reads the merchant's answer before anything is rendered.
     */
    public function postProcess()
    {
        Somcp::bootClasses();

        if (!Tools::isSubmit('submitSomcpConsent')) {
            return parent::postProcess();
        }

        $request = SomcpOAuthServer::loadPendingRequest($this->requestSecret());
        if ($request === null) {
            return parent::postProcess();
        }

        if (!hash_equals((string) $request['csrf_token'], (string) Tools::getValue('somcp_csrf', ''))) {
            // The consent form is the one state-changing step of the flow, so
            // it carries its own token rather than relying on the URL.
            SomcpLogger::log('consent_csrf_failed', SomcpLogger::OUTCOME_FAILURE);

            return parent::postProcess();
        }

        $idEmployee = (int) $this->context->employee->id;
        $client = SomcpOAuthClient::findById((int) $request['id_client']);
        if ($client === null) {
            return parent::postProcess();
        }

        SomcpOAuthServer::consumePendingRequest((int) $request['id_request']);
        $this->forgetRequest();

        if (Tools::getValue('decision') !== 'allow') {
            SomcpLogger::log(
                'consent_denied',
                SomcpLogger::OUTCOME_INFO,
                '',
                (string) $client['client_id'],
                $idEmployee
            );

            $this->leaveTo($request, ['error' => 'access_denied', 'error_description' => 'the merchant refused']);
        }

        if (!$this->employeeMayGrant((int) $request['id_shop'])) {
            // Which of the two refused it, because they are fixed in different
            // places: one is the module's required profile, the other is the
            // employee's shop associations in Team, Employees.
            $onThisShop = SomcpPermission::employeeIsUsable($idEmployee, (int) $request['id_shop']);

            SomcpLogger::log(
                $onThisShop ? 'consent_refused_profile' : 'consent_refused_shop',
                SomcpLogger::OUTCOME_FAILURE,
                $onThisShop
                    ? 'employee profile is not allowed to connect assistants'
                    : 'employee is not attached to shop ' . (int) $request['id_shop'],
                (string) $client['client_id'],
                $idEmployee
            );

            $this->leaveTo($request, ['error' => 'access_denied', 'error_description' => 'this account may not grant access']);
        }

        $granted = SomcpScope::toString(self::grantedScopes($request, $idEmployee));

        SomcpOAuthServer::recordConsent(
            (int) $client['id_client'],
            $idEmployee,
            $granted,
            (int) $request['id_shop']
        );
        $code = SomcpOAuthToken::issueCode($request, $idEmployee, $granted);

        SomcpLogger::log(
            'consent_granted',
            SomcpLogger::OUTCOME_SUCCESS,
            '',
            (string) $client['client_id'],
            $idEmployee
        );

        $this->leaveTo($request, ['code' => $code]);
    }

    /**
     * Renders the screen.
     */
    public function initContent()
    {
        Somcp::bootClasses();
        SomcpHttp::pageHeaders();

        $this->context->smarty->assign($this->templateVariables());
        // PrestaShop 9 no longer offers setRedirectAfter on this path, and the
        // template has to be rendered before the parent builds the page.
        $this->content = $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'somcp/views/templates/admin/somcp/consent.tpl'
        );

        parent::initContent();
    }

    /**
     * Everything the template needs.
     *
     * @return array<string, mixed>
     */
    private function templateVariables(): array
    {
        $request = SomcpOAuthServer::loadPendingRequest($this->requestSecret());

        if ($request === null) {
            return [
                'somcp_state' => 'expired',
                'somcp_title' => $this->module->l10n('Nothing to approve'),
                'somcp_message' => $this->module->l10n(
                    'This connection request has expired or has already been answered. Start the connection again from your assistant.'),
            ];
        }

        $client = SomcpOAuthClient::findById((int) $request['id_client']);
        if ($client === null) {
            return [
                'somcp_state' => 'expired',
                'somcp_title' => $this->module->l10n('Nothing to approve'),
                'somcp_message' => $this->module->l10n(
                    'The application behind this request is no longer known to the shop.'),
            ];
        }

        $scopes = [];
        foreach (self::grantedScopes($request, (int) $this->context->employee->id) as $scope) {
            $scopes[] = ['name' => $scope, 'label' => SomcpScope::describe($scope, $this->module)];
        }

        return [
            'somcp_state' => 'ask',
            'somcp_title' => $this->module->l10n('Connect an assistant'),
            'somcp_client_name' => SomcpOAuthClient::displayName($client),
            'somcp_client_uri' => (string) $client['client_uri'],
            'somcp_client_id' => (string) $client['client_id'],
            'somcp_client_origin' => $this->originLabel((string) $client['origin']),
            'somcp_redirect_uri' => (string) $request['redirect_uri'],
            'somcp_scopes' => $scopes,
            'somcp_employee' => $this->context->employee->email,
            // The shop the assistant was sent to, which on a multistore
            // installation is not the one the back office has in context.
            'somcp_shop' => self::shopName((int) $request['id_shop']),
            'somcp_may_grant' => $this->employeeMayGrant((int) $request['id_shop']),
            'somcp_shop_warning' => $this->module->l10n(
                'Your account is not attached to this shop, so it cannot connect an assistant to it. Ask an administrator to give you access to that shop.'),
            'somcp_on_this_shop' => SomcpPermission::employeeIsUsable(
                (int) $this->context->employee->id,
                (int) $request['id_shop']
            ),
            'somcp_profile_warning' => $this->module->l10n(
                'Your account is not allowed to connect assistants to this shop. Ask an administrator to change the required profile in the module settings.'),
            'somcp_csrf' => (string) $request['csrf_token'],
            'somcp_form_action' => $this->context->link->getAdminLink(SomcpBackOffice::CONSENT_CONTROLLER),
        ];
    }

    /**
     * What this employee is about to grant.
     *
     * What the assistant asked for, plus everything that employee could put to
     * use - a client that only knows how to ask for a session would otherwise
     * come away with a token that reaches no tool, and the merchant would have
     * to disconnect and reconnect it every time the shop gains a permission.
     * Widening is allowed as long as the token response says what was granted,
     * and it does.
     *
     * Nothing is granted that the employee does not hold themselves: the list
     * comes from the tools their own back office rights let them use.
     *
     * @param array<string, mixed> $request
     * @param int $idEmployee
     *
     * @return array<int, string>
     */
    private static function grantedScopes(array $request, int $idEmployee): array
    {
        $asked = SomcpScope::parse((string) $request['scopes']);
        $usable = SomcpScope::grantableFor($idEmployee, (int) $request['id_shop']);

        return array_values(array_unique(array_merge($asked, $usable)));
    }

    /**
     * Name of a shop, for the consent screen.
     *
     * @param int $idShop
     *
     * @return string
     */
    private static function shopName(int $idShop): string
    {
        $shop = new Shop($idShop);

        return Validate::isLoadedObject($shop)
            ? (string) $shop->name
            : (string) Configuration::get('PS_SHOP_NAME');
    }

    /**
     * Whether the signed-in employee is allowed to connect an assistant to
     * the shop this request was sent to.
     *
     * Two conditions, and the second is not the first. The profile says what
     * kind of account may connect an assistant at all; the shop says whether
     * this account belongs to the store being connected. Back office
     * permissions are the same on every shop, so without the second an
     * employee of one store could point an assistant at another store's
     * address and be handed its orders.
     *
     * @param int $idShop
     *
     * @return bool
     */
    private function employeeMayGrant(int $idShop): bool
    {
        $idEmployee = (int) $this->context->employee->id;

        return SomcpBackOffice::mayGrant((int) $this->context->employee->id_profile)
            && SomcpPermission::employeeIsUsable($idEmployee, $idShop);
    }

    /**
     * How the client was identified, in words the merchant can act on.
     *
     * @param string $origin
     *
     * @return string
     */
    private function originLabel(string $origin): string
    {
        switch ($origin) {
            case SomcpOAuthClient::ORIGIN_CIMD:
                return $this->module->l10n('Identified by the description it publishes at that address');
            case SomcpOAuthClient::ORIGIN_STATIC:
                return $this->module->l10n('Registered by hand in the module settings');
            default:
                return $this->module->l10n('Registered itself automatically');
        }
    }

    /**
     * @return string
     */
    private function requestSecret(): string
    {
        return (string) ($_COOKIE[SomcpOAuthServer::REQUEST_COOKIE] ?? '');
    }

    /**
     * Drops the cookie that pointed at the pending request.
     */
    private function forgetRequest(): void
    {
        setcookie(SomcpOAuthServer::REQUEST_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => $this->context->shop->getBaseURI(),
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Sends the browser back to the assistant.
     *
     * The destination belongs to the client, not to the back office, so the
     * redirect is sent as it stands: Tools::redirectAdmin would run it through
     * sanitizeAdminUrl(), and under profiling would not stop the script at all.
     *
     * @param array<string, mixed> $request
     * @param array<string, string> $params
     */
    private function leaveTo(array $request, array $params): void
    {
        if ((string) $request['state'] !== '') {
            $params['state'] = (string) $request['state'];
        }

        SomcpHttp::redirect(SomcpOAuthServer::buildRedirect(
            (string) $request['redirect_uri'],
            $params,
            (int) $request['id_shop']
        ));
    }
}
