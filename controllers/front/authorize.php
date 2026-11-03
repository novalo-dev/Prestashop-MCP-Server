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
 * The authorization endpoint.
 *
 * It does the OAuth work - identify the client, check its redirect URI, check
 * PKCE and the requested scopes - then parks the request and hands the browser
 * over to the back office, where PrestaShop decides who the visitor is.
 *
 * The request is parked rather than carried in the URL because PrestaShop 8
 * keeps only a controller name when it sends a visitor through the login form:
 * anything in the query string would be lost between the click and the consent
 * screen. It also keeps the parameters out of the login page and out of the
 * browser history.
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
     * The MCP endpoints answer even while the shop is closed.
     *
     * Maintenance mode hides the storefront from visitors. These addresses are
     * not the storefront: they are the merchant's own, guarded by an OAuth
     * token, and a shop being prepared is exactly when an assistant gets
     * connected. Left alone, PrestaShop answers 503 here before the controller
     * ever runs - and it would do so unevenly, since PS_MAINTENANCE_ALLOW_ADMINS
     * lets the merchant's browser through the consent screen while every
     * machine call still fails, leaving a connection broken halfway with
     * nothing to explain it. The setting that closes this server is the one in
     * the module, which says so plainly.
     */
    protected function displayMaintenancePage()
    {
    }

    /**
     * Validates the request and moves the visitor on.
     */
    public function initContent()
    {
        parent::initContent();

        Somcp::bootClasses();
        SomcpHttp::pageHeaders();

        if (!SomcpConfig::isOn(SomcpConfig::ENABLED) || !SomcpConfig::isOn(SomcpConfig::OAUTH_ENABLED)) {
            $this->refuse($this->module->l10n(
                'This shop is not accepting connections from assistants right now.'));

            return;
        }

        $query = [
            'client_id' => (string) Tools::getValue('client_id', ''),
            'redirect_uri' => (string) Tools::getValue('redirect_uri', ''),
            'response_type' => (string) Tools::getValue('response_type', ''),
            'scope' => (string) Tools::getValue('scope', ''),
            'state' => (string) Tools::getValue('state', ''),
            'code_challenge' => (string) Tools::getValue('code_challenge', ''),
            'code_challenge_method' => (string) Tools::getValue('code_challenge_method', ''),
            'resource' => (string) Tools::getValue('resource', ''),
        ];

        $check = SomcpOAuthServer::validateAuthorizationRequest($query);

        if (!$check['ok']) {
            SomcpLogger::log(
                'authorization_refused',
                SomcpLogger::OUTCOME_FAILURE,
                $check['error'] . ': ' . $check['description'],
                $query['client_id']
            );

            if ($check['redirectable']) {
                // sendBack() redirects and stops the script.
                $this->sendBack($check['data'], ['error' => $check['error'], 'error_description' => $check['description']]);
            }

            // Nothing is sent to an address that has not been verified as
            // belonging to the client: that is what an open redirect is.
            $this->refuse($this->module->l10n(
                'This connection request is not valid, so nothing was sent back to the application that made it.'));

            return;
        }

        $consentUrl = SomcpBackOffice::consentUrl();
        if ($consentUrl === '') {
            $this->refuse($this->module->l10n(
                'The module cannot find the administration area of this shop yet. Open the module settings once and save them, then try again.'));

            return;
        }

        $secret = SomcpOAuthServer::createPendingRequest($check['data']);
        $this->rememberRequest($secret);

        SomcpLogger::log(
            'authorization_requested',
            SomcpLogger::OUTCOME_INFO,
            '',
            (string) $check['data']['client']['client_id']
        );

        SomcpHttp::redirect($consentUrl);
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
            'path' => $this->context->shop->getBaseURI(),
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Sends the browser back to the client with an OAuth error.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $params
     */
    private function sendBack(array $data, array $params): void
    {
        if (!empty($data['state'])) {
            $params['state'] = (string) $data['state'];
        }

        // Stated rather than left to the context: the shop is the same one here,
        // and saying so keeps both callers of buildRedirect reading alike.
        SomcpHttp::redirect(SomcpOAuthServer::buildRedirect(
            (string) $data['redirect_uri'],
            $params,
            (int) $this->context->shop->id
        ));
    }

    /**
     * Shows the merchant why nothing happened.
     *
     * @param string $message
     */
    private function refuse(string $message): void
    {
        $this->context->smarty->assign([
            'somcp_message' => $message,
            'somcp_shop_name' => (string) Configuration::get('PS_SHOP_NAME'),
        ]);

        $this->setTemplate('module:somcp/views/templates/front/authorize_error.tpl');
    }
}
