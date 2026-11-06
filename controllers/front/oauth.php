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
 * The machine-facing OAuth endpoints: token, revocation and registration.
 *
 * One controller, three actions chosen by ?a=, because PrestaShop routes one
 * URL per front controller and three near-empty controllers would only spread
 * the same shape over three files.
 *
 * As with the MCP endpoint, the strings in the responses are protocol values
 * read by software, not by the merchant, and are not translated.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpOauthModuleFrontController extends ModuleFrontController
{
    /** @var Somcp the module instance, narrowed from the generic Module the parent declares */
    public $module;

    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ssl = true;

    /** @var bool */
    public $display_header = false;

    /** @var bool */
    public $display_footer = false;

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
     * Hands a browser over to the shop's "page not found" before anything else.
     *
     * This address is for programs; a person who opens it, or a link built to
     * see what the shop runs, gets the same page as for any dead link. It has
     * to happen before the parent init (see SomcpHttp::notFoundPage()).
     */
    public function init()
    {
        Somcp::bootClasses();

        if (Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET' && SomcpHttp::isBrowser()) {
            SomcpHttp::notFoundPage();
        }

        parent::init();
    }

    /**
     * Routes to the requested action and stops the script.
     */
    public function initContent()
    {
        Somcp::bootClasses();
        Somcp::ensureSchema();

        if (!SomcpConfig::isOn(SomcpConfig::ENABLED) || !SomcpConfig::isOn(SomcpConfig::OAUTH_ENABLED)) {
            SomcpHttp::json(['error' => 'temporarily_unavailable'], 503);
        }

        if (Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            SomcpHttp::noContent(405, ['Allow' => 'POST']);
        }

        switch ((string) Tools::getValue('a', '')) {
            case 'token':
                $this->handleToken();
                break;
            case 'revoke':
                $this->handleRevoke();
                break;
            case 'register':
                $this->handleRegister();
                break;
            default:
                SomcpHttp::json(['error' => 'invalid_request', 'error_description' => 'unknown action'], 404);
        }
    }

    /**
     * Exchanges a code or a refresh token for an access token.
     */
    private function handleToken(): void
    {
        $post = $this->formFields();
        $basic = $this->basicCredentials();
        if ($basic !== null) {
            // A client authenticating with the Basic header wins over anything
            // repeated in the body, and the two must not disagree.
            if (($post['client_id'] ?? '') !== '' && $post['client_id'] !== $basic['client_id']) {
                SomcpHttp::json(['error' => 'invalid_client'], 401);
            }
            $post['client_id'] = $basic['client_id'];
            $post['client_secret'] = $basic['client_secret'];
        }

        $result = SomcpOAuthServer::handleTokenRequest($post);
        SomcpHttp::json($result['body'], $result['status']);
    }

    /**
     * Revokes a token, RFC 7009.
     */
    private function handleRevoke(): void
    {
        $post = $this->formFields();
        $client = SomcpOAuthClient::resolve(trim($post['client_id'] ?? ''));
        $token = trim($post['token'] ?? '');

        if ($client !== null && $token !== ''
            && SomcpOAuthClient::authenticate($client, trim($post['client_secret'] ?? ''))) {
            SomcpOAuthToken::revokeByValue($token, (int) $client['id_client']);
            SomcpLogger::log('token_revoked', SomcpLogger::OUTCOME_SUCCESS, '', (string) $client['client_id']);
        }

        // RFC 7009 asks for 200 whatever happened: telling a caller that a
        // token it presented was unknown would turn this into an oracle.
        SomcpHttp::noContent(200);
    }

    /**
     * Registers a client that cannot identify itself with a metadata document.
     *
     * Dynamic registration is deprecated as of the 2026-07-28 revision, and the
     * assistants that matter most use metadata documents instead. It stays here
     * because some clients implement nothing else, and behind a setting because
     * it is an endpoint anyone on the internet can call.
     */
    private function handleRegister(): void
    {
        if (!SomcpConfig::isOn(SomcpConfig::DCR_ENABLED)) {
            SomcpHttp::json(['error' => 'access_denied', 'error_description' => 'registration is disabled'], 403);
        }

        // Every attempt counts, the refused ones too: a script trying to fill
        // the table is stopped whether or not its addresses pass.
        if (!SomcpGuard::allow('register')) {
            SomcpLogger::log('client_registration_throttled', SomcpLogger::OUTCOME_FAILURE);
            SomcpHttp::json(['error' => 'too_many_requests', 'error_description' => 'too many registrations from this address, try again later'], 429, ['Retry-After' => '3600']);
        }

        $metadata = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($metadata)) {
            SomcpHttp::json(['error' => 'invalid_client_metadata', 'error_description' => 'invalid JSON body'], 400);
        }

        $client = SomcpOAuthClient::register($metadata);
        if ($client === null) {
            SomcpHttp::json([
                'error' => 'invalid_redirect_uri',
                'error_description' => 'no acceptable redirect_uris were provided',
            ], 400);
        }

        SomcpLogger::log('client_registered', SomcpLogger::OUTCOME_SUCCESS, '', (string) $client['client_id']);

        $body = [
            'client_id' => (string) $client['client_id'],
            'client_id_issued_at' => time(),
            'redirect_uris' => $client['redirect_uris'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => (string) $client['token_auth_method'],
        ];
        if (!empty($client['plain_secret'])) {
            // The only time this value exists outside the client's own storage.
            $body['client_secret'] = (string) $client['plain_secret'];
        }

        SomcpHttp::json($body, 201);
    }

    /**
     * The form-encoded body of the request.
     *
     * @return array<string, string>
     */
    private function formFields(): array
    {
        $fields = [];
        foreach ($_POST as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * Client credentials presented in an Authorization: Basic header.
     *
     * @return array{client_id:string, client_secret:string}|null
     */
    private function basicCredentials(): ?array
    {
        $header = SomcpHttp::header('Authorization');
        if ($header === '' || !preg_match('/^Basic\s+(.+)$/i', $header, $m)) {
            return null;
        }

        $decoded = base64_decode(trim($m[1]), true);
        if ($decoded === false || strpos($decoded, ':') === false) {
            return null;
        }

        [$id, $secret] = explode(':', $decoded, 2);

        return ['client_id' => urldecode($id), 'client_secret' => urldecode($secret)];
    }
}
