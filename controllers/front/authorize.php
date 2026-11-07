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
     * Decides, before the shop page is set up, whether this visit gets the
     * module's page at all.
     *
     * Only a connection request an assistant really made, or the rest of one
     * already started in this browser, does. Anything else - the address
     * opened by hand, an unknown application, a return address off the list -
     * gets the shop's "page not found", like every other address of the
     * module, so nothing confirms the module is there. That page can only be
     * produced before the parent init (see SomcpHttp::notFoundPage()). The
     * diagnostic carries a pass of the shop's own to see the page anyway.
     *
     * While the consent page is switched off in the settings - its default -
     * every visit is that 404, a sign-in already under way included.
     */
    public function init()
    {
        Somcp::bootClasses();

        $this->open = SomcpConfig::isOn(SomcpConfig::ENABLED) && SomcpConfig::isOn(SomcpConfig::OAUTH_ENABLED);

        if (!SomcpGuard::isProbe((string) Tools::getValue('somcp_probe', ''))
            && (!SomcpConfig::isOn(SomcpConfig::CONSENT_PAGE) || (!$this->isPost() && !$this->isRealVisit()))) {
            SomcpHttp::notFoundPage();
        }

        parent::init();
    }

    /**
     * Whether a GET comes from an assistant's request or from a sign-in in
     * progress. Sets the request check initContent() goes on with.
     */
    private function isRealVisit(): bool
    {
        if (!$this->open) {
            return false;
        }

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

        if ($this->query['client_id'] === '') {
            // A reload, or the page reached from history: the cookie says
            // where the visitor was.
            return SomcpOAuthServer::loadPendingRequest($this->requestSecret()) !== null;
        }

        $this->check = SomcpOAuthServer::validateAuthorizationRequest($this->query);
        if ($this->check['hidden']) {
            SomcpLogger::log(
                'redirect_host_refused',
                SomcpLogger::OUTCOME_FAILURE,
                'return address host is not allowed: ' . Tools::substr((string) parse_url($this->query['redirect_uri'], PHP_URL_HOST), 0, 100),
                $this->query['client_id']
            );

            return false;
        }
        if (!$this->check['ok'] && !$this->check['redirectable']) {
            // Nothing can be sent back to an address that was not verified as
            // the client's, and nothing is shown either: the reason is logged.
            SomcpLogger::log(
                'authorization_refused',
                SomcpLogger::OUTCOME_FAILURE,
                $this->check['error'] . ': ' . $this->check['description'],
                $this->query['client_id']
            );

            return false;
        }

        return true;
    }

    /**
     * Routes the visit: a new request, the sign-in form, the code e-mailed
     * after it, or the consent form.
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
            $step = (string) Tools::getValue('somcp_step');
            if ($step === 'consent') {
                $this->answer($request);
            }
            if ($step === 'otp') {
                $this->verifyCode($request);
            }
            if ($step === 'resend') {
                $this->resendCode($request);
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
        $this->resume($request);
    }

    /**
     * Shows the step a pending request has reached.
     *
     * @param array<string, mixed> $request
     */
    private function resume(array $request): never
    {
        if ((int) $request['id_employee'] <= 0) {
            $this->renderSignIn($request);
        }
        if ((int) $request['otp_verified'] !== 1) {
            $this->renderCode($request);
        }
        $this->renderConsent($request);
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
        // Signed in already: a second sign-in would be a way round the wait
        // between codes and the limit of three.
        if ((int) $request['id_employee'] > 0) {
            $this->resume($request);
        }
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

        // The same answer as a blocked address, so the page never says
        // whether it is the address or the account that is blocked.
        if (SomcpGuard::accountBlocked($email)) {
            SomcpLogger::log('signin_blocked', SomcpLogger::OUTCOME_FAILURE, 'too many failed sign-ins on this account', $clientId);
            $this->renderTooMany();
        }

        // Not counted against the account: anyone who knows an employee's
        // e-mail could otherwise block it without solving a single check.
        if (!SomcpGuard::solved((string) Tools::getValue('somcp_altcha', ''))) {
            $this->refuseSignIn($request, 'anti-robot check not solved', $clientId);
        }

        $employee = false;
        if (Validate::isEmail($email) && $password !== '' && Tools::strlen($password) <= 255) {
            $employee = (new Employee())->getByEmail($email, $password);
        }
        if (!$employee instanceof Employee || (int) $employee->id <= 0) {
            $this->refuseSignIn($request, 'wrong email or password, or account switched off', $clientId, 0, $email);
        }

        $refusal = $this->refusalFor($employee, (int) $request['id_shop']);
        if ($refusal !== '') {
            $this->refuseSignIn($request, $refusal, $clientId, (int) $employee->id, $email);
        }

        SomcpOAuthServer::attachEmployee((int) $request['id_request'], (int) $employee->id);
        SomcpLogger::log('signin_succeeded', SomcpLogger::OUTCOME_SUCCESS, '', $clientId, (int) $employee->id);
        $this->sendCode($request, $employee);

        // Sent to the same page by GET, so a reload of the next step does not
        // post the password a second time.
        $this->backToPage();
    }

    /**
     * E-mails a new code to the employee signed in on the request.
     *
     * The password alone does not open the consent form: whoever stole it
     * still needs the employee's mailbox, and the e-mail tells the employee
     * their password is known if they did not ask for it.
     *
     * @param array<string, mixed> $request
     * @param Employee $employee
     */
    private function sendCode(array $request, Employee $employee): void
    {
        $code = SomcpOAuthServer::issueOtp((int) $request['id_request']);
        SomcpNotify::code($employee, $code, (int) $request['id_shop']);
        SomcpLogger::log('code_sent', SomcpLogger::OUTCOME_INFO, '', '', (int) $employee->id);
    }

    /**
     * Reads the code typed on the page.
     *
     * A wrong code counts against the address and the account like a wrong
     * password. A code that no longer works - expired, used, or mistyped five
     * times - can be replaced twice; once the third is spent, the request is
     * closed and the assistant has to start again.
     *
     * @param array<string, mixed> $request
     */
    private function verifyCode(array $request): never
    {
        $employee = new Employee((int) $request['id_employee']);
        if ((int) $request['id_employee'] <= 0 || !Validate::isLoadedObject($employee)) {
            $this->renderSignIn($request);
        }
        if ((int) $request['otp_verified'] === 1) {
            $this->backToPage();
        }
        if (SomcpGuard::signinBlocked() || SomcpGuard::accountBlocked((string) $employee->email)) {
            SomcpLogger::log('signin_blocked', SomcpLogger::OUTCOME_FAILURE, '', '', (int) $employee->id);
            $this->renderTooMany();
        }
        if (!$this->csrfMatches($request)) {
            SomcpLogger::log('code_csrf_failed', SomcpLogger::OUTCOME_FAILURE, '', '', (int) $employee->id);
            $this->renderCode($request);
        }

        // One field per digit, or a single one where a client posts it whole.
        $typed = Tools::getValue('somcp_otp', '');
        $code = (string) preg_replace('/\D/', '', is_array($typed) ? implode('', array_map('strval', $typed)) : (string) $typed);

        $result = SomcpOAuthServer::checkOtp($request, $code);
        if ($result === 'ok') {
            SomcpLogger::log('code_verified', SomcpLogger::OUTCOME_SUCCESS, '', '', (int) $employee->id);
            $this->backToPage();
        }

        SomcpGuard::signinFailed((string) $employee->email);
        SomcpLogger::log('code_refused', SomcpLogger::OUTCOME_FAILURE, $result === 'wrong' ? 'wrong code' : 'code spent', '', (int) $employee->id);
        $request = SomcpOAuthServer::loadPendingRequest($this->requestSecret()) ?? $request;
        if ($result === 'spent' && SomcpOAuthServer::otpWait($request) === -1) {
            $this->closeRequest($request);
        }
        $this->renderCode($request, $result);
    }

    /**
     * Sends another code, once the wait after the last one is over.
     *
     * @param array<string, mixed> $request
     */
    private function resendCode(array $request): never
    {
        $employee = new Employee((int) $request['id_employee']);
        if ((int) $request['id_employee'] <= 0 || !Validate::isLoadedObject($employee)) {
            $this->renderSignIn($request);
        }
        if (!$this->csrfMatches($request)) {
            SomcpLogger::log('code_csrf_failed', SomcpLogger::OUTCOME_FAILURE, '', '', (int) $employee->id);
            $this->renderCode($request);
        }

        $wait = SomcpOAuthServer::otpWait($request);
        if ($wait === -1) {
            $this->closeRequest($request);
        }
        // Checked here and not only by the page's countdown, which anyone can
        // skip by posting the form themselves.
        if ($wait === 0) {
            $this->sendCode($request, $employee);
        }
        $this->backToPage();
    }

    /**
     * Closes a request whose codes are all spent. Nothing is sent back to the
     * assistant: the link is dead, and the visitor starts over or goes away.
     *
     * @param array<string, mixed> $request
     */
    private function closeRequest(array $request): never
    {
        SomcpOAuthServer::consumePendingRequest((int) $request['id_request']);
        $this->forgetRequest();
        SomcpLogger::log('code_exhausted', SomcpLogger::OUTCOME_FAILURE, '', '', (int) $request['id_employee']);
        $this->renderPage('message', ['message' => $this->module->l10n(
            'Every code of this connection has been used up. It is closed: start it again from your assistant.')], 403);
    }

    /**
     * Back to this page by GET, which shows the step the request has reached.
     */
    private function backToPage(): never
    {
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
     * @param string $email the account tried, counted once the anti-robot check passed
     */
    private function refuseSignIn(array $request, string $reason, string $clientId, int $idEmployee = 0, string $email = ''): never
    {
        SomcpGuard::signinFailed($email);
        SomcpLogger::log('signin_refused', SomcpLogger::OUTCOME_FAILURE, $reason, $clientId, $idEmployee);
        if (SomcpGuard::signinBlocked() || ($email !== '' && SomcpGuard::accountBlocked($email))) {
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
        // The consent form only follows a code typed right.
        if ((int) $request['otp_verified'] !== 1) {
            $this->renderCode($request);
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
     * The page asking for the code e-mailed after the password.
     *
     * @param array<string, mixed> $request
     * @param string $notice '', 'wrong' or 'spent': what became of the last entry
     */
    private function renderCode(array $request, string $notice = ''): never
    {
        $client = SomcpOAuthClient::findById((int) $request['id_client']);
        $employee = new Employee((int) $request['id_employee']);
        if ($client === null || !Validate::isLoadedObject($employee)) {
            $this->renderExpired();
        }

        $wait = SomcpOAuthServer::otpWait($request);
        $spent = (string) $request['otp_hash'] === '' || strtotime((string) $request['otp_expires_at']) < time()
            || (int) $request['otp_tries'] >= SomcpConfig::OTP_TRIES;
        if ($spent && $wait === -1) {
            $this->closeRequest($request);
        }

        $this->renderPage('otp', [
            'client_name' => SomcpOAuthClient::displayName($client),
            'return_host' => self::host((string) $request['redirect_uri']),
            'csrf' => (string) $request['csrf_token'],
            'email' => self::maskEmail((string) $employee->email),
            'notice' => $notice !== '' ? $notice : ($spent ? 'spent' : ''),
            'wait' => $wait,
            'last' => $wait === -1,
            'otp_js' => $this->module->getPathUri() . 'views/js/authorize-otp.js',
        ]);
    }

    /**
     * An address shown on a page anyone with the link could reach once the
     * password was given: enough for the employee to recognise it.
     *
     * @param string $email
     *
     * @return string
     */
    private static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at < 1) {
            return '***';
        }

        return Tools::substr($email, 0, 1) . '***' . Tools::substr($email, $at);
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
            $scopes[] = [
                'name' => $scope,
                'title' => SomcpScope::label($scope, $this->module),
                'label' => SomcpScope::describe($scope, $this->module),
                // Changes are drawn apart from reading, so what can alter the
                // shop stands out in a long list.
                'write' => substr($scope, -6) === '.write',
            ];
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
            'Too many attempts. Wait 15 minutes, then try again.')], 429);
    }

    private function renderExpired(): never
    {
        $this->renderPage('message', ['message' => $this->module->l10n(
            'This connection request has expired or has already been answered. Start the connection again from your assistant.')]);
    }

    /**
     * Writes the page and stops the script.
     *
     * @param string $state signin, otp, consent or message
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
            'somcp_brand' => (string) $module->displayName,
            'somcp_logo' => $module->getPathUri() . 'logo.png',
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
                'otp_title' => $module->l10n('Check your e-mail'),
                'otp_intro' => $module->l10n('Enter the 6-digit code sent to'),
                'otp_code' => $module->l10n('Verification code'),
                'otp_digit' => $module->l10n('Digit'),
                'verify' => $module->l10n('Verify'),
                'otp_rule' => $module->l10n('The code works for %minutes% minutes and only once.', ['%minutes%' => (string) intdiv(SomcpConfig::OTP_TTL, 60)]),
                'otp_wrong' => $module->l10n('This code is not right.'),
                'otp_spent' => $module->l10n('This code no longer works. Ask for a new one.'),
                'otp_last' => $module->l10n('This is the last code: no other will be sent.'),
                'resend' => $module->l10n('Send a new code'),
                'resend_in' => $module->l10n('Send a new code in %time%'),
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
