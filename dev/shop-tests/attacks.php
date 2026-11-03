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
 * Shop test: the module's public addresses attacked over real HTTP.
 *
 * run.php calls the tools from inside PrestaShop and checks the permissions
 * there. What it cannot see is the door in front of them: how a request with
 * a stolen, forged, revoked or misplaced credential is answered by the shop
 * once PrestaShop's dispatcher, the headers and the controllers are involved.
 * This script holds the keys - it mints them through the module's own classes
 * - and then knocks on every door with the wrong one.
 *
 * Every attack must be refused. A refusal only means something if the right
 * key opens the same door, so each group starts with a call that must work.
 *
 * Usage: php dev/shop-tests/attacks.php <prestashop root> <shop url> [token file]
 * The shop must be served at <shop url>. With a token file, a fresh token with
 * every scope is written there for the scanner that runs next.
 * Exit code 0 when every attack was refused, 1 otherwise.
 */

$psRoot = realpath($argv[1] ?? '');
$shopUrl = rtrim($argv[2] ?? '', '/');
if ($psRoot === false || !is_file($psRoot . '/config/config.inc.php') || $shopUrl === '') {
    fwrite(STDERR, "Usage: php dev/shop-tests/attacks.php <prestashop root> <shop url> [token file]\n");
    exit(1);
}

require __DIR__ . '/boot.php';

if (!Module::isInstalled('somcp') || !Module::getInstanceByName('somcp') instanceof Somcp) {
    fwrite(STDERR, "The somcp module is not installed in this shop.\n");
    exit(1);
}
Somcp::bootClasses();

$db = Db::getInstance();
$prefix = _DB_PREFIX_;
$idEmployee = 1;

// The addresses without URL rewriting: the built-in PHP server the CI uses
// has no rewrite rules, and the dispatcher reaches the same controllers.
$endpoint = static function (string $controller, string $query = '') use ($shopUrl): string {
    return $shopUrl . '/index.php?fc=module&module=somcp&controller=' . $controller . ($query !== '' ? '&' . $query : '');
};
$mcpUrl = $endpoint('mcp');
$tokenUrl = $endpoint('oauth', 'a=token');

/**
 * @param array<int, string> $headers
 *
 * @return array{status:int, headers:array<string, string>, body:string, json:mixed}
 */
$http = static function (string $method, string $url, array $headers = [], ?string $body = null): array {
    $received = [];
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        // A redirect is part of the answer being judged, never followed.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $received[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $text = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $text = is_string($text) ? $text : '';

    return ['status' => $status, 'headers' => $received, 'body' => $text, 'json' => json_decode($text, true)];
};

$json = ['Content-Type: application/json', 'Accept: application/json, text/event-stream'];
$bearer = static function (string $token) use ($json): array {
    return array_merge($json, ['Authorization: Bearer ' . $token]);
};
$rpc = static function (string $method, array $params = []): string {
    return (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
};
$ping = $rpc('tools/call', ['name' => 'somcp_ping', 'arguments' => (object) []]);
$form = ['Content-Type: application/x-www-form-urlencoded'];

$failures = [];
$checks = 0;
$expect = static function (string $what, array $response, int $status) use (&$failures, &$checks): bool {
    ++$checks;
    if ($response['status'] === $status) {
        printf("  ok    %s (%d)\n", $what, $status);

        return true;
    }
    printf("  FAIL  %s: expected %d, got %d\n", $what, $status, $response['status']);
    $failures[] = sprintf('%s: expected HTTP %d, got %d %s', $what, $status, $response['status'], substr($response['body'], 0, 200));

    return false;
};
$section = static function (string $title): void {
    echo "\n" . $title . "\n";
};

// The keys. Two clients, so a code of one can be presented by the other.
$clients = [];
foreach (['A', 'B'] as $name) {
    $client = SomcpOAuthClient::register([
        'client_name' => 'Attack test ' . $name,
        'redirect_uris' => ['http://127.0.0.1:9/callback'],
    ]);
    if ($client === null) {
        fwrite(STDERR, "Could not register a test client.\n");
        exit(1);
    }
    $clients[$name] = $client;
}
$all = SomcpScope::toString(SomcpScope::supported());
$readOnly = SomcpScope::ACCESS . ' ' . SomcpScope::CATALOG_READ;

$pair = static function (string $scopes, string $resource = '') use ($clients, $idEmployee): array {
    $family = SomcpRandom::familyId();
    $body = SomcpOAuthToken::issuePair((int) $clients['A']['id_client'], $idEmployee, $scopes, $resource, $family);
    $body['family'] = $family;

    return $body;
};
$expireAccess = static function (string $token) use ($db): void {
    $db->update('somcp_oauth_access_token', ['expires_at' => '2000-01-01 00:00:00'], "`token_hash` = '" . pSQL(SomcpRandom::hash($token)) . "'");
};
$verifier = SomcpRandom::token(48);
$challenge = SomcpRandom::base64UrlEncode(hash('sha256', $verifier, true));
$code = static function () use ($clients, $idEmployee, $all, $challenge): string {
    return SomcpOAuthToken::issueCode([
        'id_shop' => Somcp::currentShopId(),
        'id_client' => (int) $clients['A']['id_client'],
        'redirect_uri' => 'http://127.0.0.1:9/callback',
        'scopes' => $all,
        'resource' => '',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ], $idEmployee);
};
$redeem = static function (string $code, string $clientId, string $codeVerifier, string $redirectUri = 'http://127.0.0.1:9/callback') use ($http, $tokenUrl, $form): array {
    return $http('POST', $tokenUrl, $form, http_build_query([
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'code' => $code,
        'code_verifier' => $codeVerifier,
        'redirect_uri' => $redirectUri,
    ]));
};
$clientA = (string) $clients['A']['client_id'];
$clientB = (string) $clients['B']['client_id'];

printf("PrestaShop %s, PHP %s, %s\n", _PS_VERSION_, PHP_VERSION, $shopUrl);

// ---------------------------------------------------------------------------
$section('MCP endpoint');
$valid = $pair($all);
if (!$expect('a valid token is served', $http('POST', $mcpUrl, $bearer($valid['access_token']), $ping), 200)) {
    // Every refusal below would pass against a shop that refuses everything.
    echo "::error title=Attacks not run::the MCP endpoint does not answer a valid token, so no refusal can be trusted\n";
    exit(1);
}
$expect('GET is not allowed', $http('GET', $mcpUrl), 405);
$missing = $http('POST', $mcpUrl, $json, $ping);
$expect('no token', $missing, 401);
if (stripos($missing['headers']['www-authenticate'] ?? '', 'Bearer') !== 0) {
    $failures[] = 'no token: the 401 does not tell the client where to sign in (WWW-Authenticate)';
}
$expect('forged token', $http('POST', $mcpUrl, $bearer(SomcpRandom::token()), $ping), 401);
$expect('token in the address instead of the header', $http('POST', $mcpUrl . '&access_token=' . urlencode($valid['access_token']), $json, $ping), 401);
$expect('token sent as Basic', $http('POST', $mcpUrl, array_merge($json, ['Authorization: Basic ' . $valid['access_token']]), $ping), 401);

$revoked = $pair($all);
SomcpOAuthToken::revokeFamily($revoked['family']);
$expect('revoked token', $http('POST', $mcpUrl, $bearer($revoked['access_token']), $ping), 401);

$expired = $pair($all);
$expireAccess($expired['access_token']);
$expect('expired token', $http('POST', $mcpUrl, $bearer($expired['access_token']), $ping), 401);

$foreign = $pair($all, 'https://attacker.example/module/somcp/mcp');
$expect('token minted for another server', $http('POST', $mcpUrl, $bearer($foreign['access_token']), $ping), 401);

$elsewhere = $pair($all);
$db->update('somcp_oauth_access_token', ['id_shop' => 999], "`token_hash` = '" . pSQL(SomcpRandom::hash($elsewhere['access_token'])) . "'");
$expect('token of another shop', $http('POST', $mcpUrl, $bearer($elsewhere['access_token']), $ping), 401);

$expect('page of another site calling with a valid token', $http('POST', $mcpUrl, array_merge($bearer($valid['access_token']), ['Origin: https://attacker.example']), $ping), 403);

$reader = $pair($readOnly);
$expect('read-only token calling a write tool', $http('POST', $mcpUrl, $bearer($reader['access_token']), $rpc('tools/call', [
    'name' => 'somcp_create_category',
    'arguments' => ['name' => 'Created by an attack'],
])), 403);
$expect('body that is not JSON', $http('POST', $mcpUrl, $bearer($valid['access_token']), '{"jsonrpc":'), 400);
$expect('batch of calls', $http('POST', $mcpUrl, $bearer($valid['access_token']), '[' . $ping . ',' . $ping . ']'), 400);

// ---------------------------------------------------------------------------
$section('Token endpoint');
$replayed = $code();
$issued = $redeem($replayed, $clientA, $verifier);
if ($expect('a code is exchanged by its client', $issued, 200)) {
    $issuedToken = (string) ($issued['json']['access_token'] ?? '');
    $expect('the token it gave is served', $http('POST', $mcpUrl, $bearer($issuedToken), $ping), 200);
    // The second use of a code is either a replay or a thief who got there
    // first: everything issued from it must stop working.
    $expect('replayed code', $redeem($replayed, $clientA, $verifier), 400);
    $expect('token issued before the replay is revoked', $http('POST', $mcpUrl, $bearer($issuedToken), $ping), 401);
}

$expect('code of another client', $redeem($code(), $clientB, $verifier), 400);
$expect('wrong PKCE verifier', $redeem($wrong = $code(), $clientA, SomcpRandom::token(48)), 400);
$expect('right verifier after a wrong one', $redeem($wrong, $clientA, $verifier), 400);
$expect('another redirect address', $redeem($code(), $clientA, $verifier, 'http://127.0.0.1:9/elsewhere'), 400);
$old = $code();
$db->update('somcp_oauth_code', ['expires_at' => '2000-01-01 00:00:00'], "`code_hash` = '" . pSQL(SomcpRandom::hash($old)) . "'");
$expect('expired code', $redeem($old, $clientA, $verifier), 400);
$expect('unknown client', $redeem($code(), 'somcp-' . SomcpRandom::token(16), $verifier), 401);

$refresh = static function (string $token) use ($http, $tokenUrl, $form, $clientA): array {
    return $http('POST', $tokenUrl, $form, http_build_query(['grant_type' => 'refresh_token', 'client_id' => $clientA, 'refresh_token' => $token]));
};
$start = $pair($all);
$next = $refresh($start['refresh_token']);
if ($expect('a refresh token is exchanged', $next, 200) && SomcpConfig::isOn(SomcpConfig::REFRESH_ROTATION)) {
    $expect('rotated refresh token presented again', $refresh($start['refresh_token']), 400);
    $expect('token issued after the reuse is revoked', $http('POST', $mcpUrl, $bearer((string) ($next['json']['access_token'] ?? '')), $ping), 401);
}

// ---------------------------------------------------------------------------
$section('Sign-in and registration');
$authorize = $http('GET', $endpoint('authorize', http_build_query([
    'response_type' => 'code',
    'client_id' => $clientA,
    'redirect_uri' => 'https://attacker.example/callback',
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
    'state' => 'attack',
])));
++$checks;
$location = $authorize['headers']['location'] ?? '';
if (stripos($location, 'attacker.example') !== false) {
    $failures[] = 'sign-in page redirects to an address the client never registered: ' . $location;
    echo "  FAIL  sign-in page with an unregistered redirect address: sent to $location\n";
} else {
    echo "  ok    sign-in page with an unregistered redirect address ({$authorize['status']}, not sent there)\n";
}
++$checks;
if (strtoupper($authorize['headers']['x-frame-options'] ?? '') !== 'DENY') {
    $failures[] = 'sign-in page can be shown inside a frame by another site (no X-Frame-Options: DENY)';
    echo "  FAIL  sign-in page refuses to be framed\n";
} else {
    echo "  ok    sign-in page refuses to be framed\n";
}
$expect('client registering a plain-http address on the internet', $http('POST', $endpoint('oauth', 'a=register'), $json, (string) json_encode([
    'client_name' => 'Attack test',
    'redirect_uris' => ['http://attacker.example/callback'],
])), 400);

// ---------------------------------------------------------------------------
$section('Relay');
$relayUrl = static function (string $action) use ($endpoint): string {
    return $endpoint('relay', 'action=' . $action);
};
$pairingCode = 'ATTACK23';
$db->insert('somcp_relay', [
    'id_shop' => Somcp::currentShopId(),
    'id_shop_group' => 0,
    'code_hash' => pSQL(SomcpRandom::hash($pairingCode)),
    'code' => '',
    'code_expires' => date('Y-m-d H:i:s', time() + 600),
    'date_add' => date('Y-m-d H:i:s'),
]);
$relayBody = static function (string $code): string {
    return (string) json_encode(['code' => $code, 'relay' => ['version' => '0.0.0', 'os' => 'attack']]);
};
$expect('wrong pairing code', $http('POST', $relayUrl('pair'), $json, $relayBody('ZZZZZZZZ')), 400);
$paired = $http('POST', $relayUrl('pair'), $json, $relayBody($pairingCode));
if ($expect('right pairing code', $paired, 200)) {
    $relayToken = (string) ($paired['json']['token'] ?? '');
    $expect('pairing code used a second time', $http('POST', $relayUrl('pair'), $json, $relayBody($pairingCode)), 400);
    $hello = (string) json_encode(['ai' => ['software' => 'attack', 'models' => []]]);
    $expect('relay with its token', $http('POST', $relayUrl('hello'), $bearer($relayToken), $hello), 200);
    $expect('relay without a token', $http('POST', $relayUrl('hello'), $json, $hello), 401);
    $expect('relay with a forged token', $http('POST', $relayUrl('hello'), $bearer(SomcpRandom::token()), $hello), 401);
    $expect('waiting for work without a token', $http('GET', $relayUrl('next') . '&wait=1'), 401);
    $expect('answering without a token', $http('POST', $relayUrl('result'), $json, '{"id":"x","status":200,"response":{}}'), 401);
}

// ---------------------------------------------------------------------------
$section('Files shared in the chat');
$hash = hash('sha256', 'attack');
$expect('forged signature', $http('GET', $endpoint('file', http_build_query(['f' => $hash, 'e' => time() + 600, 's' => hash('sha256', 'forged')]))), 404);
$expect('expired link', $http('GET', $endpoint('file', http_build_query([
    'f' => $hash,
    'e' => time() - 60,
    's' => SomcpAssistantChat::signature($hash, time() - 60),
]))), 404);

// ---------------------------------------------------------------------------
if (isset($argv[3])) {
    file_put_contents($argv[3], $pair($all)['access_token']);
}

printf("\n%d checks, %d failed\n", $checks, count($failures));
foreach ($failures as $failure) {
    echo '::error title=Attack not refused::' . str_replace(["\r", "\n", '%'], [' ', ' ', '%25'], $failure) . "\n";
}
exit($failures === [] ? 0 : 1);
