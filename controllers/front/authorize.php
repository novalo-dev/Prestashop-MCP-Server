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
 * The authorization endpoint, from the request to the merchant's answer.
 *
 * It does the OAuth work - identify the client, check its return address,
 * PKCE and the requested scopes - then parks the request and asks, on this
 * same page, for the back office email and password of the employee who is
 * connecting the assistant, and then for their consent.
 *
 * Nothing on this flow leads into the back office: no page or redirect of it
 * names the administration folder, whose name is what keeps the back office
 * login out of sight.
 *
 * The request waits in the database and the browser carries nothing but an
 * opaque cookie. The employee who signs in is recorded on the request, so the
 * consent can only be given by that account, with its own rights, on the shop
 * the assistant was sent to.
 *
 * The page is a bare document rather than a page of the shop's theme: a
 * password field has no business next to the theme's scripts and trackers.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpAuthorizeModuleFrontController extends ModuleFrontController
{
    /** @var Somcp the module instance, narrowed from the generic Module the parent declares */
    public $module;

    /** @var bool The visitor is a merchant, not a customer: no shop session needed here. */
    public $auth = false;

    /** @var bool */
    public $ssl = true;

    /**
     * @var array{ok:bool, redirectable:bool, hidden:bool, error:string, description:string, data:array<string,mixed>}|null the validated request, worked out in init()
     */
    private $check;

    /** @var array<string, string> */
    private $query = [];

    /** @var bool */
    private $open = false;

    /**
     * The MCP endpoints answer even while the shop is closed.
     *
     * Maintenance mode hides the storefront from visitors. These addresses are
     * not the storefront: they are the merchant's own, guarded by an OAuth
     * token, and a shop being prepared is exactly when an assistant gets
     * connected. Left alone, PrestaShop answers 503 here before the controller
     * ever runs, leaving a connection broken halfway with nothing to explain
     * it. The setting that closes this server is the one in the module, which
     * says so plainly.
     */
    protected function displayMaintenancePage()
    {
    }

    /**
     * Validates a new request before the shop page is set up.
     *
     * A return address on a host the merchant has not allowed gets the
     * shop's "page not found", and that page can only be produced before the
     * parent init (see SomcpHttp::notFoundPage()).
     */
    public function init()
    {
        Somcp::bootClasses();

        $this->open = SomcpConfig::isOn(SomcpConfig::ENABLED) && SomcpConfig::isOn(SomcpConfig::OAUTH_ENABLED);

        if ($this->open && !$this->isPost()) {
            $this->query = [
                'client_id' => (string) Tools::getValue('client_id', ''),
                'redirect_uri' => (string) Tools::getValue('redirect_uri', ''),
                'response_type' => (string) Tools::getValue('response_type', ''),
                'scope' => (string) Tools::getValue('scope', ''),
                'state' => (string) Tools::getValue('state', ''),
                'code_challenge' => (string) Tools::getValue('code_challenge', ''),
                'code_challenge_method' => (string) Tools::getValue('code_challenge_method', ''),
                'resource' => (string) Tools::getValue('resource', ''),
            ];
            $this->check = SomcpOAuthServer::validateAuthorizationRequest($this->query);

            if ($this->check['hidden']) {
                SomcpLogger::log(
                    'redirect_host_refused',
                    SomcpLogger::OUTCOME_FAILURE,
                    'return address host is not allowed: ' . Tools::substr((string) parse_url($this->query['redirect_uri'], PHP_URL_HOST), 0, 100),
                    $this->query['client_id']
                );
                SomcpHttp::notFoundPage();
            }
        }

        parent::init();
    }

    /**
     * Routes the visit: a new request, the sign-in form or the consent form.
     */
    public function initContent()
    {
        Somcp::bootClasses();

        if (!$this->open) {
            $this->renderPage('message', ['message' => $this->module->l10n(
                'This shop is not accepting connections from assistants right now.')]);
        }

        if ($this->isPost()) {
            $request = SomcpOAuthServer::loadPendingRequest($this->requestSecret());
            if ($request === null) {
                $this->renderExpired();
            }
            if (Tools::getValue('somcp_step') === 'consent') {
                $this->answer($request);
            }
            $this->signIn($request);
        }

        if ($this->check !== null && $this->query['client_id'] !== '') {
            $this->start($this->check);
        }

        // Opened again without the request in the address: a reload, or the
        // page reached from history. The cookie says where the visitor was.
        $request = SomcpOAuthServer::loadPendingRequest($this->requestSecret());
        if ($request === null) {
            $this->renderPage('message', ['message' => $this->module->l10n(
                'This connection request is not valid, so nothing was sent back to the application that made it.')]);
        }
        if ((int) $request['id_employee'] > 0) {
            $this->renderConsent($request);
        }
        $this->renderSignIn($request);
    }

    /**
     * Parks a new request and shows the sign-in form.
     *
     * @param array{ok:bool, redirectable:bool, hidden:bool, error:string, description:string, data:array<string,mixed>} $check
     */
    private function start(array $check): never
    {
        if (!$check['ok']) {
            SomcpLogger::log(
                'authorization_refused',
                SomcpLogger::OUTCOME_FAILURE,
                $check['error'] . ': ' . $check['description'],
                $this->query['client_id']
            );

            if ($check['redirectable']) {
                $this->sendBack($check['data'], ['error' => $check['error'], 'error_description' => $check['description']]);
            }

            // Nothing is sent to an address that has not been verified as
            // belonging to the client: that is what an open redirect is.
            $this->renderPage('message', ['message' => $this->module->l10n(
                'This connection request is not valid, so nothing was sent back to the application that made it.')]);
        }

        if (!SomcpGuard::allow('authorize')) {
            SomcpLogger::log('authorization_throttled', SomcpLogger::OUTCOME_FAILURE, '', $this->query['client_id']);
            $this->renderTooMany();
        }

        $secret = SomcpOAuthServer::createPendingRequest($check['data']);
        $this->rememberRequest($secret);

        SomcpLogger::log(
            'authorization_requested',
            SomcpLogger::OUTCOME_INFO,
            '',
            (string) $check['data']['client']['client_id']
        );

        $request = SomcpOAuthServer::loadPendingRequest($secret);
        if ($request === null) {
            $this->renderExpired();
        }
        $this->renderSignIn($request);
    }

    /**
     * Checks the employee's email and password against the back office.
     *
     * Every refusal reads the same, whatever its reason: a wrong password, an
     * account switched off, a profile not allowed to connect assistants or an
     * account not attached to this shop. A page open to anyone holding the link
     * must not tell them which of their guesses came close. The real reason
     * goes to the module's log, where the merchant can read it.
     *
     * @param array<string, mixed> $request
     */
    private function signIn(array $request): never
    {
        // Before anything else, the password included: once an address is
        // blocked, the right password is refused too, or guessing goes on.
        if (SomcpGuard::signinBlocked()) {
            SomcpLogger::log('signin_blocked', SomcpLogger::OUTCOME_FAILURE);
            $this->renderTooMany();
        }
        if (!$this->csrfMatches($request)) {
            SomcpLogger::log('signin_csrf_failed', SomcpLogger::OUTCOME_FAILURE);
            $this->renderSignIn($request);
        }

        $email = trim((string) Tools::getValue('somcp_email', ''));
        $password = (string) Tools::getValue('somcp_password', '');
        $client = SomcpOAuthClient::findById((int) $request['id_client']);
        $clientId = $client !== null ? (string) $client['client_id'] : '';

        if (!SomcpGuard::solved((string) Tools::getValue('somcp_altcha', ''))) {
            $this->refuseSignIn($request, 'anti-robot check not solved', $clientId);
        }

        $employee = false;
        if (Validate::isEmail($email) && $password !== '' && Tools::strlen($password) <= 255) {
            $employee = (new Employee())->getByEmail($email, $password);
        }
        if (!$employee instanceof Employee || (int) $employee->id <= 0) {
            $this->refuseSignIn($request, 'wrong email or password, or account switched off', $clientId);
        }

        $refusal = $this->refusalFor($employee, (int) $request['id_shop']);
        if ($refusal !== '') {
            $this->refuseSignIn($request, $refusal, $clientId, (int) $employee->id);
        }

        SomcpOAuthServer::attachEmployee((int) $request['id_request'], (int) $employee->id);
        SomcpLogger::log('signin_succeeded', SomcpLogger::OUTCOME_SUCCESS, '', $clientId, (int) $employee->id);

        // Sent to the same page by GET, so a reload of the consent screen
        // does not post the password a second time.
        SomcpHttp::redirect($this->context->link->getModuleLink('somcp', 'authorize', [], true));
    }

    /**
     * Counts a failed sign-in, logs its real reason and shows the form again,
     * or the block once the address has failed too often.
     *
     * @param array<string, mixed> $request
     * @param string $reason
     * @param string $clientId
     * @param int $idEmployee
     */
    private function refuseSignIn(array $request, string $reason, string $clientId, int $idEmployee = 0): never
    {
        SomcpGuard::signinFailed();
        SomcpLogger::log('signin_refused', SomcpLogger::OUTCOME_FAILURE, $reason, $clientId, $idEmployee);
        if (SomcpGuard::signinBlocked()) {
            $this->renderTooMany();
        }
        $this->renderSignIn($request, true);
    }

    /**
     * Reads the merchant's answer on the consent form.
     *
     * @param array<string, mixed> $request
     */
    private function answer(array $request): never
    {
        $idEmployee = (int) $request['id_employee'];
        if ($idEmployee <= 0) {
            $this->renderSignIn($request);
        }
        if (!$this->csrfMatches($request)) {
            // The consent form is the one step that hands out access, so it
            // carries its own token rather than relying on the cookie alone.
            SomcpLogger::log('consent_csrf_failed', SomcpLogger::OUTCOME_FAILURE, '', '', $idEmployee);
            $this->renderConsent($request);
        }

        $client = SomcpOAuthClient::findById((int) $request['id_client']);
        // The host may have been taken off the list since the request came in.
        if ($client === null || !SomcpOAuthClient::redirectHostAllowed((string) $request['redirect_uri'])) {
            $this->renderExpired();
        }

        SomcpOAuthServer::consumePendingRequest((int) $request['id_request']);
        $this->forgetRequest();

        if (Tools::getValue('decision') !== 'allow') {
            SomcpLogger::log('consent_denied', SomcpLogger::OUTCOME_INFO, '', (string) $client['client_id'], $idEmployee);
            $this->leaveTo($request, ['error' => 'access_denied', 'error_description' => 'the merchant refused']);
        }

        // Checked again: the profile or the shop associations may have changed
        // between the sign-in and the click.
        $employee = new Employee($idEmployee);
        $refusal = Validate::isLoadedObject($employee) && $employee->active
            ? $this->refusalFor($employee, (int) $request['id_shop'])
            : 'employee account no longer active';
        if ($refusal !== '') {
            SomcpLogger::log('consent_refused', SomcpLogger::OUTCOME_FAILURE, $refusal, (string) $client['client_id'], $idEmployee);
            $this->leaveTo($request, ['error' => 'access_denied', 'error_description' => 'this account may not grant access']);
        }

        $granted = SomcpScope::toString(self::grantedScopes($request, $idEmployee));
        SomcpOAuthServer::recordConsent((int) $client['id_client'], $idEmployee, $granted, (int) $request['id_shop']);
        $code = SomcpOAuthToken::issueCode($request, $idEmployee, $granted);

        SomcpLogger::log('consent_granted', SomcpLogger::OUTCOME_SUCCESS, '', (string) $client['client_id'], $idEmployee);
        SomcpNotify::newAssistant($client, $employee, (int) $request['id_shop'], (string) $request['redirect_uri']);

        $this->leaveTo($request, ['code' => $code]);
    }

    /**
     * Why this employee may not connect an assistant to that shop, or ''.
     *
     * Two conditions, and the second is not the first. The profile says what
     * kind of account may connect an assistant at all; the shop says whether
     * this account belongs to the store being connected. Back office
     * permissions are the same on every shop, so without the second an
     * employee of one store could point an assistant at another store's
     * address and be handed its orders.
     *
     * @param Employee $employee
     * @param int $idShop
     *
     * @return string
     */
    private function refusalFor(Employee $employee, int $idShop): string
    {
        if (!SomcpBackOffice::mayGrant((int) $employee->id_profile)) {
            return 'employee profile is not allowed to connect assistants';
        }
        if (!SomcpPermission::employeeIsUsable((int) $employee->id, $idShop)) {
            return 'employee is not attached to shop ' . $idShop;
        }

        return '';
    }

    /**
     * What this employee is about to grant.
     *
     * What the assistant asked for, plus everything that employee could put to
     * use - a client that only knows how to ask for a session would otherwise
     * come away with a token that reaches no tool, and the merchant would have
     * to disconnect and reconnect it every time the shop gains a permission.
     * Widening is allowed as long as the token response says what was granted,
     * and it does. Nothing is granted that the employee does not hold
     * themselves: the list comes from the tools their own rights let them use.
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
     * @param array<string, mixed> $request
     * @param bool $refused whether the last sign-in attempt was refused
     */
    private function renderSignIn(array $request, bool $refused = false): never
    {
        $client = SomcpOAuthClient::findById((int) $request['id_client']);
        if ($client === null) {
            $this->renderExpired();
        }

        $this->renderPage('signin', [
            'client_name' => SomcpOAuthClient::displayName($client),
            'return_host' => self::host((string) $request['redirect_uri']),
            'csrf' => (string) $request['csrf_token'],
            'refused' => $refused,
            'challenge' => SomcpGuard::challenge(),
            'altcha_js' => $this->module->getPathUri() . 'views/js/altcha/altcha.i18n.min.js',
        ]);
    }

    /**
     * @param array<string, mixed> $request
     */
    private function renderConsent(array $request): never
    {
        $client = SomcpOAuthClient::findById((int) $request['id_client']);
        $employee = new Employee((int) $request['id_employee']);
        if ($client === null || !Validate::isLoadedObject($employee)) {
            $this->renderExpired();
        }

        $scopes = [];
        foreach (self::grantedScopes($request, (int) $employee->id) as $scope) {
            $scopes[] = ['name' => $scope, 'label' => SomcpScope::describe($scope, $this->module)];
        }

        $this->renderPage('consent', [
            'client_name' => SomcpOAuthClient::displayName($client),
            'client_uri' => (string) $client['client_uri'],
            'client_id' => (string) $client['client_id'],
            'client_origin' => $this->originLabel((string) $client['origin']),
            'return_host' => self::host((string) $request['redirect_uri']),
            'redirect_uri' => (string) $request['redirect_uri'],
            'scopes' => $scopes,
            'employee' => (string) $employee->email,
            // The shop the assistant was sent to, which on a multistore
            // installation is not necessarily the one shown by default.
            'shop' => self::shopName((int) $request['id_shop']),
            'csrf' => (string) $request['csrf_token'],
        ]);
    }

    /**
     * The answer once an address has asked or failed too often.
     */
    private function renderTooMany(): never
    {
        $this->renderPage('message', ['message' => $this->module->l10n(
            'Too many attempts from this address. Wait 15 minutes, then try again.')], 429);
    }

    private function renderExpired(): never
    {
        $this->renderPage('message', ['message' => $this->module->l10n(
            'This connection request has expired or has already been answered. Start the connection again from your assistant.')]);
    }

    /**
     * Writes the page and stops the script.
     *
     * @param string $state signin, consent or message
     * @param array<string, mixed> $vars
     * @param int $status HTTP status of the page
     */
    private function renderPage(string $state, array $vars, int $status = 200): never
    {
        $module = $this->module;
        $this->context->smarty->assign([
            'somcp_state' => $state,
            'somcp' => $vars,
            'somcp_shop_name' => (string) Configuration::get('PS_SHOP_NAME'),
            'somcp_lang' => (string) $this->context->language->iso_code,
            'somcp_action' => $this->context->link->getModuleLink('somcp', 'authorize', [], true),
            'somcp_text' => [
                'title' => $module->l10n('Connect an assistant'),
                'intro' => $module->l10n('An application is asking to connect to your shop on your behalf.'),
                'signin_intro' => $module->l10n('Sign in with your back office account to see what it asks for and answer.'),
                'email' => $module->l10n('Email address'),
                'password' => $module->l10n('Password'),
                'signin' => $module->l10n('Sign in'),
                'refused' => $module->l10n('These sign-in details are not accepted.'),
                'return_to' => $module->l10n('Answers will be sent to'),
                'return_check' => $module->l10n('Only continue if this address belongs to the assistant you are connecting yourself. If you did not start this connection, close this page.'),
                'application' => $module->l10n('Application'),
                'identifier' => $module->l10n('Identifier'),
                'signed_in_as' => $module->l10n('Signed in as'),
                'shop' => $module->l10n('Shop'),
                'sent_back' => $module->l10n('Will be sent back to'),
                'abilities' => $module->l10n('What it will be able to do'),
                'rights' => $module->l10n('The application never gets more rights than your own account. You can disconnect it at any time from the module settings.'),
                'refuse' => $module->l10n('Refuse'),
                'allow' => $module->l10n('Allow the connection'),
            ],
        ]);

        $html = $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'somcp/views/templates/front/authorize.tpl');

        SomcpHttp::pageHeaders();
        if (!headers_sent()) {
            http_response_code($status);
            // Nothing but the module's own files on this page. The anti-robot
            // widget starts its workers from a data: or blob: address and
            // writes its own styles, hence those two exceptions.
            header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; worker-src 'self' blob: data:; connect-src 'self'; base-uri 'none'; object-src 'none'");
            header('Referrer-Policy: no-referrer');
            header('Cache-Control: no-store');
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $html;
        exit;
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
     * @param string $uri
     *
     * @return string
     */
    private static function host(string $uri): string
    {
        return Tools::strtolower((string) parse_url($uri, PHP_URL_HOST));
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
     * @param array<string, mixed> $request
     *
     * @return bool
     */
    private function csrfMatches(array $request): bool
    {
        return hash_equals((string) $request['csrf_token'], (string) Tools::getValue('somcp_csrf', ''));
    }

    /**
     * @return bool
     */
    private function isPost(): bool
    {
        return Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    }

    /**
     * @return string
     */
    private function requestSecret(): string
    {
        return (string) ($_COOKIE[SomcpOAuthServer::REQUEST_COOKIE] ?? '');
    }

    /**
     * Stores the pending request identifier in the browser.
     *
     * Lax rather than Strict: the visitor arrives here from the assistant, so a
     * Strict cookie would not be sent back on the navigation that follows.
     *
     * @param string $secret
     */
    private function rememberRequest(string $secret): void
    {
        setcookie(SomcpOAuthServer::REQUEST_COOKIE, $secret, [
            'expires' => time() + SomcpConfig::REQUEST_TTL,
            'path' => $this->cookiePath(),
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Drops the cookie that pointed at the pending request.
     */
    private function forgetRequest(): void
    {
        setcookie(SomcpOAuthServer::REQUEST_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => $this->cookiePath(),
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * The path the cookie is bound to.
     *
     * The shop's base path, because the same page answers under two shapes of
     * address: /module/somcp/authorize with friendly URLs, index.php?fc=module
     * without, and a cookie bound to the first is never sent to the second.
     *
     * @return string
     */
    private function cookiePath(): string
    {
        return $this->context->shop->getBaseURI();
    }

    /**
     * Sends the browser back to the client with an OAuth error.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $params
     */
    private function sendBack(array $data, array $params): never
    {
        if (!empty($data['state'])) {
            $params['state'] = (string) $data['state'];
        }

        SomcpHttp::redirect(SomcpOAuthServer::buildRedirect(
            (string) $data['redirect_uri'],
            $params,
            (int) $this->context->shop->id
        ));
    }

    /**
     * Sends the browser back to the assistant with the answer.
     *
     * @param array<string, mixed> $request
     * @param array<string, string> $params
     */
    private function leaveTo(array $request, array $params): never
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
