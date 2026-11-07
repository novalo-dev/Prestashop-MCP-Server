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
 * SomcpOAuthServer - the authorization server itself.
 *
 * The authorization request is validated on the shop front, parked in the
 * database, and the browser is sent to the back office carrying nothing but an
 * opaque identifier in a cookie. That detour exists for one reason: PrestaShop 8
 * keeps only a controller name when it bounces a visitor through the login form,
 * so OAuth parameters placed in the URL would not survive the merchant signing
 * in. Parking them also keeps the query string out of the login page and out of
 * the browser history.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpOAuthServer
{
    /** @var string Name of the cookie that links a browser to its pending request. */
    public const REQUEST_COOKIE = 'somcp_authreq';

    /**
     * Checks an authorization request.
     *
     * Two kinds of failure, deliberately kept apart. As long as the client or
     * its redirect URI is in doubt, nothing is sent back to that URI - an error
     * redirect to an unverified address is an open redirect. Once both are
     * established, further errors do go back to the client, as OAuth requires.
     *
     * @param array<string, string> $query
     *
     * A third kind is not answered at all: a return address on a host the
     * merchant has not allowed. Whoever sends a merchant such a link is most
     * likely trying to have a code delivered to their own server, and the page
     * that answers them is the shop's ordinary "page not found" - nothing that
     * confirms the module is there or says what to change.
     *
     * @return array{ok:bool, redirectable:bool, hidden:bool, error:string, description:string, data:array<string,mixed>}
     */
    public static function validateAuthorizationRequest(array $query): array
    {
        $refuse = static function (string $error, string $description, bool $redirectable, array $data = [], bool $hidden = false): array {
            return [
                'ok' => false,
                'redirectable' => $redirectable,
                'hidden' => $hidden,
                'error' => $error,
                'description' => $description,
                'data' => $data,
            ];
        };

        $clientId = trim($query['client_id'] ?? '');
        if ($clientId === '') {
            return $refuse('invalid_request', 'client_id is missing', false);
        }

        $client = SomcpOAuthClient::resolve($clientId);
        if ($client === null) {
            return $refuse('invalid_client', 'unknown client', false);
        }

        $redirectUri = trim($query['redirect_uri'] ?? '');
        if ($redirectUri === '') {
            return $refuse('invalid_request', 'redirect_uri is missing', false);
        }
        if (!SomcpOAuthClient::allowsRedirectUri($client, $redirectUri)) {
            SomcpLogger::log(
                'redirect_uri_refused',
                SomcpLogger::OUTCOME_FAILURE,
                'redirect URI is not registered for this client',
                $clientId
            );

            return $refuse('invalid_request', 'redirect_uri is not registered', false);
        }
        if (!SomcpOAuthClient::redirectHostAllowed($redirectUri)) {
            return $refuse('access_denied', 'the return address is not on the list of allowed hosts', false, [], true);
        }

        // From here on the client and its address are known, so errors travel
        // back to it instead of being shown to the merchant.
        $data = ['client' => $client, 'redirect_uri' => $redirectUri, 'state' => (string) ($query['state'] ?? '')];

        if (($query['response_type'] ?? '') !== 'code') {
            return $refuse('unsupported_response_type', 'only the authorization code flow is supported', true, $data);
        }

        $challenge = trim($query['code_challenge'] ?? '');
        $method = trim($query['code_challenge_method'] ?? '');
        if ($challenge === '') {
            return $refuse('invalid_request', 'code_challenge is required', true, $data);
        }
        if ($method !== 'S256') {
            return $refuse('invalid_request', 'code_challenge_method must be S256', true, $data);
        }
        if (!SomcpRandom::isValidChallenge($challenge)) {
            return $refuse('invalid_request', 'code_challenge is malformed', true, $data);
        }

        $resource = trim($query['resource'] ?? '');
        if ($resource !== '' && !self::resourceMatches($resource)) {
            // The client asked for a token meant for another server. Issuing it
            // would hand the merchant's credentials to a third party.
            return $refuse('invalid_target', 'resource does not identify this MCP server', true, $data);
        }

        $scopes = SomcpScope::parse((string) ($query['scope'] ?? ''));
        if ($scopes === []) {
            $scopes = SomcpScope::defaults();
        }
        $unknown = SomcpScope::unknown($scopes);
        if ($unknown !== []) {
            return $refuse('invalid_scope', 'unsupported scope: ' . implode(' ', $unknown), true, $data);
        }

        $data['scopes'] = $scopes;
        $data['resource'] = $resource !== '' ? $resource : SomcpConfig::resourceUri();
        $data['code_challenge'] = $challenge;
        $data['code_challenge_method'] = $method;

        return ['ok' => true, 'redirectable' => true, 'hidden' => false, 'error' => '', 'description' => '', 'data' => $data];
    }

    /**
     * Compares a requested resource with this server's canonical URI.
     *
     * A trailing slash is tolerated because clients differ on it; nothing else
     * is, since the comparison is what binds the token to this shop.
     *
     * @param string $resource
     *
     * @return bool
     */
    public static function resourceMatches(string $resource): bool
    {
        $canonical = SomcpConfig::resourceUri();

        return rtrim($resource, '/') === rtrim($canonical, '/');
    }

    /**
     * Parks a validated request and returns the secret the browser will carry.
     *
     * @param array<string, mixed> $data output of validateAuthorizationRequest
     *
     * @return string the clear request identifier, to be put in the cookie
     */
    public static function createPendingRequest(array $data): string
    {
        $secret = SomcpRandom::token();

        Db::getInstance()->insert('somcp_oauth_request', [
            'request_id' => pSQL(SomcpRandom::hash($secret)),
            'id_shop' => Somcp::currentShopId(),
            'id_client' => (int) $data['client']['id_client'],
            'redirect_uri' => pSQL((string) $data['redirect_uri']),
            'scopes' => pSQL(SomcpScope::toString($data['scopes'])),
            'state' => pSQL(Tools::substr((string) $data['state'], 0, 500)),
            'code_challenge' => pSQL((string) $data['code_challenge']),
            'code_challenge_method' => pSQL((string) $data['code_challenge_method']),
            'resource' => pSQL((string) $data['resource']),
            'csrf_token' => pSQL(SomcpRandom::token(16)),
            'expires_at' => pSQL(date('Y-m-d H:i:s', time() + SomcpConfig::REQUEST_TTL)),
        ]);

        return $secret;
    }

    /**
     * Loads a pending request from the secret held by the browser.
     *
     * @param string $secret
     *
     * @return array<string, mixed>|null
     */
    public static function loadPendingRequest(string $secret): ?array
    {
        if ($secret === '') {
            return null;
        }

        // Looked up by the secret alone, never by the administration context.
        // The request belongs to the shop that received it, and an administrator
        // working on all shops has the default shop in context - which is not
        // the shop an assistant was sent to. Filtering on it lost every request
        // made on any other shop.
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . "somcp_oauth_request`
             WHERE `request_id` = '" . pSQL(SomcpRandom::hash($secret)) . "'"
        );

        if (!is_array($row) || $row === []) {
            return null;
        }
        if (!empty($row['consumed_at']) || strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        return $row;
    }

    /**
     * Records which employee signed in to answer a pending request.
     *
     * The form token is renewed at the same time: the one shown before the
     * sign-in was on a page anyone holding the link could open, and only the
     * consent form, shown once the password is checked, gets the new one.
     *
     * @param int $idRequest
     * @param int $idEmployee
     *
     * @return string the new form token
     */
    public static function attachEmployee(int $idRequest, int $idEmployee): string
    {
        $csrf = SomcpRandom::token(16);
        Db::getInstance()->update(
            'somcp_oauth_request',
            ['id_employee' => (int) $idEmployee, 'csrf_token' => pSQL($csrf)],
            '`id_request` = ' . (int) $idRequest . ' AND `consumed_at` IS NULL'
        );

        return $csrf;
    }

    /**
     * Writes a new code for the employee signed in on a pending request and
     * returns it, to be e-mailed. The one sent before, if any, stops working.
     *
     * Six digits are few, which is why a code lives ten minutes, survives five
     * wrong entries, and only three are ever sent for one request: an attacker
     * holding the password gets fifteen guesses in a million.
     *
     * @param int $idRequest
     *
     * @return string the code, never stored as such
     */
    public static function issueOtp(int $idRequest): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . "somcp_oauth_request`
             SET `otp_hash` = '" . pSQL(self::otpHash($idRequest, $code)) . "',
                 `otp_expires_at` = '" . pSQL(date('Y-m-d H:i:s', time() + SomcpConfig::OTP_TTL)) . "',
                 `otp_sent_at` = '" . pSQL(date('Y-m-d H:i:s')) . "',
                 `otp_sends` = `otp_sends` + 1,
                 `otp_tries` = 0
             WHERE `id_request` = " . (int) $idRequest . ' AND `consumed_at` IS NULL'
        );

        return $code;
    }

    /**
     * Seconds before another code may be sent for this request: 0 when it may
     * be now, -1 when no other will be.
     *
     * @param array<string, mixed> $request
     *
     * @return int
     */
    public static function otpWait(array $request): int
    {
        $sends = (int) $request['otp_sends'];
        if (!isset(SomcpConfig::OTP_WAITS[$sends])) {
            return -1;
        }

        return max(0, strtotime((string) $request['otp_sent_at']) + SomcpConfig::OTP_WAITS[$sends] - time());
    }

    /**
     * Checks a code typed on the page. A right code is spent at once - it
     * opens the consent form one time only - and renews the form token.
     *
     * @param array<string, mixed> $request
     * @param string $code
     *
     * @return string 'ok', 'wrong' or 'spent' (expired, used, or too many wrong entries)
     */
    public static function checkOtp(array $request, string $code): string
    {
        $idRequest = (int) $request['id_request'];
        if ((string) $request['otp_hash'] === ''
            || strtotime((string) $request['otp_expires_at']) < time()
            || (int) $request['otp_tries'] >= SomcpConfig::OTP_TRIES) {
            return 'spent';
        }

        // Counted before the comparison and in the same statement as the
        // limit, so two entries posted at once cannot both slip under it.
        $db = Db::getInstance();
        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'somcp_oauth_request` SET `otp_tries` = `otp_tries` + 1
             WHERE `id_request` = ' . $idRequest . ' AND `otp_tries` < ' . (int) SomcpConfig::OTP_TRIES
        );
        if ($db->Affected_Rows() < 1) {
            return 'spent';
        }

        if (!preg_match('/^\d{6}$/', $code) || !hash_equals((string) $request['otp_hash'], self::otpHash($idRequest, $code))) {
            return (int) $request['otp_tries'] + 1 >= SomcpConfig::OTP_TRIES ? 'spent' : 'wrong';
        }

        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . "somcp_oauth_request`
             SET `otp_verified` = 1, `otp_hash` = '', `csrf_token` = '" . pSQL(SomcpRandom::token(16)) . "'
             WHERE `id_request` = " . $idRequest . " AND `otp_hash` = '" . pSQL((string) $request['otp_hash']) . "'"
        );

        // Only the request that emptied the hash wins a code posted twice.
        return $db->Affected_Rows() === 1 ? 'ok' : 'spent';
    }

    /**
     * What is stored of a code. Public for the tests, which cannot read the
     * e-mail and write a code of their own in its place.
     *
     * @param int $idRequest
     * @param string $code
     *
     * @return string
     */
    public static function otpHash(int $idRequest, string $code): string
    {
        return hash('sha256', $idRequest . '|' . $code);
    }

    /**
     * Marks a pending request as dealt with, whatever the merchant answered.
     *
     * @param int $idRequest
     */
    public static function consumePendingRequest(int $idRequest): void
    {
        Db::getInstance()->update(
            'somcp_oauth_request',
            ['consumed_at' => pSQL(date('Y-m-d H:i:s'))],
            '`id_request` = ' . (int) $idRequest
        );
    }

    /**
     * Records that an employee granted a set of scopes to a client, so the
     * merchant can see and withdraw the grant later.
     *
     * @param int $idClient
     * @param int $idEmployee
     * @param string $scopes
     * @param int $idShop the shop the request was made on, not the one in context
     */
    public static function recordConsent(int $idClient, int $idEmployee, string $scopes, int $idShop): void
    {
        $existing = Db::getInstance()->getValue(
            'SELECT `id_consent` FROM `' . _DB_PREFIX_ . 'somcp_oauth_consent`
             WHERE `id_client` = ' . (int) $idClient . '
               AND `id_employee` = ' . (int) $idEmployee . '
               AND `id_shop` = ' . $idShop
        );

        if ($existing) {
            Db::getInstance()->update(
                'somcp_oauth_consent',
                ['scopes' => pSQL($scopes)],
                '`id_consent` = ' . (int) $existing
            );

            return;
        }

        Db::getInstance()->insert('somcp_oauth_consent', [
            'id_shop' => $idShop,
            'id_client' => (int) $idClient,
            'id_employee' => (int) $idEmployee,
            'scopes' => pSQL($scopes),
        ]);
    }

    /**
     * Consents granted on this shop, for the settings page.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function consents(): array
    {
        return Db::getInstance()->executeS(
            'SELECT co.*, c.`client_id`, c.`client_name`,
                    e.`email`, e.`firstname`, e.`lastname`, e.`active` AS `employee_active`,
                    pl.`name` AS `profile_name`, s.`name` AS `shop_name`
             FROM `' . _DB_PREFIX_ . 'somcp_oauth_consent` co
             INNER JOIN `' . _DB_PREFIX_ . 'somcp_oauth_client` c ON c.`id_client` = co.`id_client`
             LEFT JOIN `' . _DB_PREFIX_ . 'employee` e ON e.`id_employee` = co.`id_employee`
             LEFT JOIN `' . _DB_PREFIX_ . 'profile_lang` pl
                    ON pl.`id_profile` = e.`id_profile`
                   AND pl.`id_lang` = ' . (int) Somcp::legacyContext()->language->id . '
             LEFT JOIN `' . _DB_PREFIX_ . 'shop` s ON s.`id_shop` = co.`id_shop`
             WHERE co.`id_shop` ' . SomcpLogger::contextShopCondition() . '
             ORDER BY co.`id_consent` DESC'
        ) ?: [];
    }

    /**
     * Withdraws a consent and kills the tokens that came with it.
     *
     * @param int $idConsent
     */
    public static function withdrawConsent(int $idConsent): void
    {
        // Restricted to the context, not merely filtered for display: without
        // this, an administrator working on one group could cut a connection
        // belonging to another simply by passing its identifier.
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'somcp_oauth_consent`
             WHERE `id_consent` = ' . (int) $idConsent . '
               AND `id_shop` ' . SomcpLogger::contextShopCondition()
        );
        if (!is_array($row) || $row === []) {
            return;
        }

        SomcpOAuthToken::revokeGrant(
            (int) $row['id_client'],
            (int) $row['id_employee'],
            (int) $row['id_shop']
        );
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'somcp_oauth_consent` WHERE `id_consent` = ' . (int) $idConsent
        );
        SomcpLogger::log(
            'consent_withdrawn',
            SomcpLogger::OUTCOME_SUCCESS,
            '',
            '',
            (int) $row['id_employee']
        );
    }

    /**
     * Builds the URL the browser is sent back to.
     *
     * The issuer is always attached: RFC 9207 lets the client check which
     * authorization server answered, which is what stops a mix-up attack when
     * it talks to several shops at once.
     *
     * @param string $redirectUri
     * @param array<string, string> $params
     * @param int|null $idShop the shop the authorization request was made on
     *
     * @return string
     */
    public static function buildRedirect(string $redirectUri, array $params, ?int $idShop = null): string
    {
        // The issuer of the shop the client started from, which is not the one
        // in context when this runs from the back office: an administrator
        // working on all shops has the default shop there. A client checks this
        // value against the server it began with and rejects the whole response
        // when it differs, so getting it from the context silently broke every
        // connection to any shop but the default one.
        $shop = null;
        if ($idShop > 0) {
            $candidate = new Shop((int) $idShop);
            if (Validate::isLoadedObject($candidate)) {
                $shop = $candidate;
            }
        }

        $params['iss'] = SomcpConfig::issuer($shop);
        $separator = strpos($redirectUri, '?') === false ? '?' : '&';

        return $redirectUri . $separator . http_build_query($params);
    }

    /**
     * Handles a call to the token endpoint.
     *
     * @param array<string, string> $post
     *
     * @return array{status:int, body:array<string, mixed>}
     */
    public static function handleTokenRequest(array $post): array
    {
        $error = static function (string $code, string $description, int $status = 400): array {
            return ['status' => $status, 'body' => ['error' => $code, 'error_description' => $description]];
        };

        $grantType = trim($post['grant_type'] ?? '');
        $clientId = trim($post['client_id'] ?? '');
        if ($clientId === '') {
            return $error('invalid_client', 'client_id is missing', 401);
        }

        $client = SomcpOAuthClient::resolve($clientId);
        if ($client === null) {
            return $error('invalid_client', 'unknown client', 401);
        }
        if (!SomcpOAuthClient::authenticate($client, trim($post['client_secret'] ?? ''))) {
            SomcpLogger::log('client_auth_failed', SomcpLogger::OUTCOME_FAILURE, '', $clientId);

            return $error('invalid_client', 'client authentication failed', 401);
        }

        $resource = trim($post['resource'] ?? '');
        if ($resource !== '' && !self::resourceMatches($resource)) {
            return $error('invalid_target', 'resource does not identify this MCP server');
        }

        if ($grantType === 'authorization_code') {
            return self::grantAuthorizationCode($post, $client);
        }

        if ($grantType === 'refresh_token') {
            $refreshToken = trim($post['refresh_token'] ?? '');
            if ($refreshToken === '') {
                return $error('invalid_request', 'refresh_token is missing');
            }

            $result = SomcpOAuthToken::refresh($refreshToken, (int) $client['id_client']);
            if ($result['body'] === null) {
                return $error($result['error'], 'the refresh token is not usable');
            }

            SomcpLogger::log('token_refreshed', SomcpLogger::OUTCOME_SUCCESS, '', $clientId);

            return ['status' => 200, 'body' => $result['body']];
        }

        return $error('unsupported_grant_type', 'unsupported grant_type');
    }

    /**
     * @param array<string, string> $post
     * @param array<string, mixed> $client
     *
     * @return array{status:int, body:array<string, mixed>}
     */
    private static function grantAuthorizationCode(array $post, array $client): array
    {
        $error = static function (string $code, string $description): array {
            return ['status' => 400, 'body' => ['error' => $code, 'error_description' => $description]];
        };

        $code = trim($post['code'] ?? '');
        $verifier = trim($post['code_verifier'] ?? '');
        $redirectUri = trim($post['redirect_uri'] ?? '');

        if ($code === '' || $verifier === '') {
            return $error('invalid_request', 'code and code_verifier are required');
        }

        $consumed = SomcpOAuthToken::consumeCode($code);
        $row = $consumed['row'];
        if ($row === null) {
            return $error($consumed['error'], 'the authorization code is not usable');
        }

        if ((int) $row['id_client'] !== (int) $client['id_client']) {
            SomcpOAuthToken::revokeFamily((string) $row['id_family']);

            return $error('invalid_grant', 'the code was issued to another client');
        }
        if ($redirectUri !== '' && !hash_equals((string) $row['redirect_uri'], $redirectUri)) {
            return $error('invalid_grant', 'redirect_uri does not match the authorization request');
        }
        if (!SomcpRandom::verifyPkce($verifier, (string) $row['code_challenge'])) {
            SomcpLogger::log(
                'pkce_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'code_verifier does not match the challenge',
                (string) $client['client_id']
            );

            return $error('invalid_grant', 'the code verifier does not match');
        }

        $body = SomcpOAuthToken::issuePair(
            (int) $row['id_client'],
            (int) $row['id_employee'],
            (string) $row['scopes'],
            (string) $row['resource'],
            (string) $row['id_family']
        );

        SomcpLogger::log(
            'token_issued',
            SomcpLogger::OUTCOME_SUCCESS,
            '',
            (string) $client['client_id'],
            (int) $row['id_employee']
        );

        return ['status' => 200, 'body' => $body];
    }
}
