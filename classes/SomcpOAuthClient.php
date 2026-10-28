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
 * SomcpOAuthClient - who is asking.
 *
 * Three ways a client can be known, tried in the order the specification lays
 * down: a client the merchant registered by hand, a client identified by a
 * metadata document it publishes, and as a last resort a client that registered
 * itself. Dynamic registration is deprecated as of the 2026-07-28 revision but
 * is still the only mechanism some assistants implement, so it stays available
 * behind a setting.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpOAuthClient
{
    public const ORIGIN_CIMD = 'cimd';
    public const ORIGIN_DCR = 'dcr';
    public const ORIGIN_STATIC = 'static';

    /**
     * Return-address hosts of the clients the module is known to work with,
     * offered as one-click suggestions on the settings page. Checked against
     * each client's published redirect URI: Claude (claude.ai and claude.com),
     * ChatGPT, Make (www.make.com/oauth/cb/mcp), n8n Cloud
     * (oauth.n8n.cloud/oauth2/callback), VS Code for the web (vscode.dev/redirect)
     * and Cursor's web agents (www.cursor.com). Desktop clients and coding agents
     * return to the loopback address, which is always allowed, and a
     * self-hosted n8n returns to its own domain, which only the merchant knows.
     *
     * @var array<string, string> host => client name
     */
    public const KNOWN_HOSTS = [
        'claude.ai' => 'Claude',
        'claude.com' => 'Claude',
        'chatgpt.com' => 'ChatGPT',
        'www.make.com' => 'Make',
        'oauth.n8n.cloud' => 'n8n Cloud',
        'vscode.dev' => 'VS Code',
        'www.cursor.com' => 'Cursor',
    ];

    /** @var int Seconds a fetched metadata document is trusted without refetching. */
    private const CIMD_CACHE_TTL = 3600;

    /**
     * Finds the client behind a client_id, fetching its metadata document when
     * the identifier is a URL and the local copy has gone stale.
     *
     * @param string $clientId
     *
     * @return array<string, mixed>|null null when the client cannot be identified
     */
    public static function resolve(string $clientId): ?array
    {
        if ($clientId === '' || Tools::strlen($clientId) > 255) {
            return null;
        }

        $stored = self::findByClientId($clientId);
        if ($stored !== null && $stored['origin'] !== self::ORIGIN_CIMD) {
            return (int) $stored['is_active'] === 1 ? $stored : null;
        }

        if (!self::looksLikeMetadataUrl($clientId)) {
            return null;
        }

        if (!SomcpConfig::isOn(SomcpConfig::CIMD_ENABLED)) {
            return null;
        }

        if ($stored !== null && !empty($stored['cache_expires_at'])
            && strtotime((string) $stored['cache_expires_at']) > time()) {
            return (int) $stored['is_active'] === 1 ? $stored : null;
        }

        $fetched = self::fetchMetadataDocument($clientId);
        if ($fetched === null) {
            // A document that cannot be refetched right now is still better
            // than nothing if we already had one: a network hiccup at the
            // hosting of the assistant should not log the merchant out.
            return $stored !== null && (int) $stored['is_active'] === 1 ? $stored : null;
        }

        return self::store($fetched, self::ORIGIN_CIMD, self::CIMD_CACHE_TTL);
    }

    /**
     * A client_id that is a Client ID Metadata Document URL.
     *
     * @param string $clientId
     *
     * @return bool
     */
    public static function looksLikeMetadataUrl(string $clientId): bool
    {
        $parts = parse_url($clientId);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && !empty($parts['host'])
            && !isset($parts['fragment'])
            && !isset($parts['user']);
    }

    /**
     * Fetches and validates a Client ID Metadata Document.
     *
     * The checks that matter: the document must be reachable at exactly the
     * URL used as the identifier, and it must claim that same URL as its own
     * client_id. Without the second check, anyone able to host a JSON file
     * could claim the identity of a well-known assistant.
     *
     * @param string $clientId
     *
     * @return array<string, mixed>|null
     */
    private static function fetchMetadataDocument(string $clientId): ?array
    {
        $response = SomcpHttp::fetch($clientId, true);
        if ($response['status'] !== 200 || $response['body'] === '') {
            SomcpLogger::log(
                'client_metadata_fetch',
                SomcpLogger::OUTCOME_FAILURE,
                'HTTP ' . $response['status'] . ' ' . $response['error'],
                $clientId
            );

            return null;
        }

        $document = json_decode($response['body'], true);
        if (!is_array($document)) {
            SomcpLogger::log('client_metadata_fetch', SomcpLogger::OUTCOME_FAILURE, 'invalid JSON', $clientId);

            return null;
        }

        if (($document['client_id'] ?? '') !== $clientId) {
            SomcpLogger::log(
                'client_metadata_fetch',
                SomcpLogger::OUTCOME_FAILURE,
                'client_id does not match the document URL',
                $clientId
            );

            return null;
        }

        $redirectUris = self::sanitizeRedirectUris($document['redirect_uris'] ?? []);
        if ($redirectUris === []) {
            SomcpLogger::log('client_metadata_fetch', SomcpLogger::OUTCOME_FAILURE, 'no usable redirect_uris', $clientId);

            return null;
        }

        SomcpLogger::log('client_metadata_fetch', SomcpLogger::OUTCOME_SUCCESS, '', $clientId);

        return [
            'client_id' => $clientId,
            'client_name' => (string) ($document['client_name'] ?? ''),
            'client_uri' => (string) ($document['client_uri'] ?? ''),
            'logo_uri' => (string) ($document['logo_uri'] ?? ''),
            'redirect_uris' => $redirectUris,
            'application_type' => ($document['application_type'] ?? '') === 'web' ? 'web' : 'native',
            'token_auth_method' => 'none',
            'client_secret' => '',
        ];
    }

    /**
     * Registers a client that presented itself at the registration endpoint.
     *
     * @param array<string, mixed> $metadata body of the registration request
     *
     * @return array<string, mixed>|null the stored row, with `plain_secret` when one was issued
     */
    public static function register(array $metadata): ?array
    {
        $redirectUris = self::sanitizeRedirectUris($metadata['redirect_uris'] ?? []);
        if ($redirectUris === []) {
            return null;
        }

        $applicationType = ($metadata['application_type'] ?? '') === 'web' ? 'web' : 'native';
        $authMethod = (string) ($metadata['token_endpoint_auth_method'] ?? 'none');
        if (!in_array($authMethod, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            $authMethod = 'none';
        }

        $plainSecret = '';
        $secretHash = '';
        if ($authMethod !== 'none') {
            $plainSecret = SomcpRandom::token();
            $secretHash = SomcpRandom::hash($plainSecret);
        }

        $row = self::store([
            'client_id' => 'somcp-' . SomcpRandom::token(16),
            'client_name' => (string) ($metadata['client_name'] ?? ''),
            'client_uri' => (string) ($metadata['client_uri'] ?? ''),
            'logo_uri' => (string) ($metadata['logo_uri'] ?? ''),
            'redirect_uris' => $redirectUris,
            'application_type' => $applicationType,
            'token_auth_method' => $authMethod,
            'client_secret' => $secretHash,
        ], self::ORIGIN_DCR, 0);

        if ($row === null) {
            return null;
        }

        $row['plain_secret'] = $plainSecret;

        return $row;
    }

    /**
     * Checks the credential a client presented at the token endpoint.
     *
     * @param array<string, mixed> $client
     * @param string $secret
     *
     * @return bool
     */
    public static function authenticate(array $client, string $secret): bool
    {
        if (($client['token_auth_method'] ?? 'none') === 'none') {
            // Public client: it proves possession of the grant with PKCE, not
            // with a secret it would have to ship inside a desktop binary.
            return true;
        }

        if ($secret === '' || (string) $client['client_secret'] === '') {
            return false;
        }

        return SomcpRandom::equals((string) $client['client_secret'], SomcpRandom::hash($secret));
    }

    /**
     * Decides whether a redirect URI may be used by this client.
     *
     * Exact string comparison, with one deliberate exception. Desktop and
     * command-line assistants listen on a loopback port picked at startup and
     * cannot register it in advance, so RFC 8252 section 7.3 asks servers to
     * ignore the port there. Everything else about the URI - scheme, host,
     * path, query - still has to match exactly, and the exception never applies
     * to a public host.
     *
     * @param array<string, mixed> $client
     * @param string $redirectUri
     *
     * @return bool
     */
    public static function allowsRedirectUri(array $client, string $redirectUri): bool
    {
        $registered = is_array($client['redirect_uris']) ? $client['redirect_uris'] : [];

        foreach ($registered as $candidate) {
            if (hash_equals((string) $candidate, $redirectUri)) {
                return true;
            }
            if (self::loopbackMatches((string) $candidate, $redirectUri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $registered
     * @param string $requested
     *
     * @return bool
     */
    private static function loopbackMatches(string $registered, string $requested): bool
    {
        $a = parse_url($registered);
        $b = parse_url($requested);
        if (!is_array($a) || !is_array($b)) {
            return false;
        }
        if (!self::isLoopbackHost((string) ($a['host'] ?? '')) || !self::isLoopbackHost((string) ($b['host'] ?? ''))) {
            return false;
        }

        return ($a['scheme'] ?? '') === ($b['scheme'] ?? '')
            && ($a['host'] ?? '') === ($b['host'] ?? '')
            && ($a['path'] ?? '') === ($b['path'] ?? '')
            && ($a['query'] ?? '') === ($b['query'] ?? '');
    }

    /**
     * @param string $host
     *
     * @return bool
     */
    private static function isLoopbackHost(string $host): bool
    {
        return in_array($host, ['127.0.0.1', '[::1]', '::1', 'localhost'], true);
    }

    /**
     * Keeps the redirect URIs the module is willing to send a merchant to.
     *
     * A plain http:// URI is only accepted on the loopback address, and the
     * merchant can narrow public hosts further with the allowed hosts setting.
     *
     * @param mixed $uris
     *
     * @return array<int, string>
     */
    public static function sanitizeRedirectUris($uris): array
    {
        if (!is_array($uris)) {
            return [];
        }

        $allowedHosts = self::allowedHosts();
        $clean = [];

        foreach ($uris as $uri) {
            if (!is_string($uri) || Tools::strlen($uri) > 500) {
                continue;
            }
            $parts = parse_url($uri);
            if (!is_array($parts) || empty($parts['scheme']) || isset($parts['fragment'])) {
                continue;
            }

            $scheme = (string) $parts['scheme'];
            $host = (string) ($parts['host'] ?? '');

            if ($scheme === 'http') {
                if (!self::isLoopbackHost($host)) {
                    continue;
                }
            } elseif ($scheme === 'https') {
                if ($host === '' || ($allowedHosts !== [] && !in_array(Tools::strtolower($host), $allowedHosts, true))) {
                    continue;
                }
            } else {
                // Private-use schemes (com.example.app:/callback) are allowed by
                // RFC 8252 for native apps but give no host to check against,
                // so the module does not accept them for now.
                continue;
            }

            $clean[] = $uri;
        }

        return array_values(array_unique($clean));
    }

    /**
     * @return array<int, string>
     */
    private static function allowedHosts(): array
    {
        $raw = SomcpConfig::get(SomcpConfig::REDIRECT_HOSTS);
        if (trim($raw) === '') {
            return [];
        }

        $hosts = preg_split('/[\s,;]+/', Tools::strtolower($raw)) ?: [];

        return array_values(array_filter($hosts, static function ($host) {
            return $host !== '';
        }));
    }

    /**
     * @param string $clientId
     *
     * @return array<string, mixed>|null
     */
    public static function findByClientId(string $clientId): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . "somcp_oauth_client`
             WHERE `client_id` = '" . pSQL($clientId) . "'
               AND `id_shop` = " . Somcp::currentShopId()
        );

        return is_array($row) && $row !== [] ? self::hydrate($row) : null;
    }

    /**
     * @param int $idClient
     *
     * @return array<string, mixed>|null
     */
    public static function findById(int $idClient): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'somcp_oauth_client` WHERE `id_client` = ' . (int) $idClient
        );

        return is_array($row) && $row !== [] ? self::hydrate($row) : null;
    }

    /**
     * All clients of the current shop, most recent first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'somcp_oauth_client`
             WHERE `id_shop` = ' . Somcp::currentShopId() . '
             ORDER BY `id_client` DESC'
        ) ?: [];

        return array_map([self::class, 'hydrate'], $rows);
    }

    /**
     * Drops a client and everything granted to it.
     *
     * @param int $idClient
     */
    public static function revokeAndDelete(int $idClient): void
    {
        $db = Db::getInstance();
        foreach (['somcp_oauth_access_token', 'somcp_oauth_refresh_token', 'somcp_oauth_code',
            'somcp_oauth_request', 'somcp_oauth_consent'] as $table) {
            $db->execute('DELETE FROM `' . _DB_PREFIX_ . $table . '` WHERE `id_client` = ' . (int) $idClient);
        }
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'somcp_oauth_client` WHERE `id_client` = ' . (int) $idClient);
    }

    /**
     * Inserts or refreshes a client row.
     *
     * @param array<string, mixed> $data
     * @param string $origin
     * @param int $cacheTtl seconds, 0 when the row is not a cache
     *
     * @return array<string, mixed>|null
     */
    private static function store(array $data, string $origin, int $cacheTtl): ?array
    {
        $idShop = Somcp::currentShopId();
        $cacheExpires = $cacheTtl > 0 ? date('Y-m-d H:i:s', time() + $cacheTtl) : null;

        $fields = [
            'id_shop' => $idShop,
            'client_id' => pSQL((string) $data['client_id']),
            'origin' => pSQL($origin),
            'client_name' => pSQL(Tools::substr((string) $data['client_name'], 0, 255)),
            'client_uri' => pSQL(Tools::substr((string) $data['client_uri'], 0, 255)),
            'logo_uri' => pSQL(Tools::substr((string) $data['logo_uri'], 0, 255)),
            'redirect_uris' => pSQL((string) json_encode($data['redirect_uris'])),
            'token_auth_method' => pSQL((string) $data['token_auth_method']),
            'client_secret' => pSQL((string) $data['client_secret']),
            'application_type' => pSQL((string) $data['application_type']),
            // Written as a raw NULL rather than passed as a PHP null with the
            // $null_values flag: that flag turns EVERY empty string into NULL
            // too, and client_uri, logo_uri and client_secret are legitimately
            // empty for a client identified by a metadata document - three
            // NOT NULL columns, and the whole insert refused.
            'cache_expires_at' => $cacheExpires !== null
                ? pSQL($cacheExpires)
                : ['type' => 'sql', 'value' => 'NULL'],
            'is_active' => 1,
        ];

        $existing = self::findByClientId((string) $data['client_id']);
        if ($existing !== null) {
            unset($fields['client_id'], $fields['id_shop']);
            $ok = Db::getInstance()->update(
                'somcp_oauth_client',
                $fields,
                '`id_client` = ' . (int) $existing['id_client']
            );
        } else {
            $ok = Db::getInstance()->insert('somcp_oauth_client', $fields);
        }

        if (!$ok) {
            // Without this line a refused write is indistinguishable from an
            // unknown client, and the merchant only sees "this request is not
            // valid" with nothing to act on.
            SomcpLogger::log(
                'client_store_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr((string) Db::getInstance()->getMsgError(), 0, 200),
                (string) $data['client_id']
            );

            return null;
        }

        return self::findByClientId((string) $data['client_id']);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function hydrate(array $row): array
    {
        $decoded = json_decode((string) $row['redirect_uris'], true);
        $row['redirect_uris'] = is_array($decoded) ? $decoded : [];

        return $row;
    }

    /**
     * Name to show on the consent screen. Falls back to the identifier when the
     * client did not give itself one, so the merchant always sees something.
     *
     * @param array<string, mixed> $client
     *
     * @return string
     */
    public static function displayName(array $client): string
    {
        $name = trim((string) ($client['client_name'] ?? ''));

        return $name !== '' ? $name : (string) $client['client_id'];
    }
}
