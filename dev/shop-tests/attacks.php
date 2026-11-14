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
// The anti-robot check is solved below with the module's own library.
require_once _PS_MODULE_DIR_ . 'somcp/vendor/autoload.php';

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
                $name = strtolower(trim($parts[0]));
                // PrestaShop sets its own cookie on the same answer: every
                // Set-Cookie is kept, or the module's would be overwritten.
                $received[$name] = $name === 'set-cookie' && isset($received[$name])
                    ? $received[$name] . "\n" . trim($parts[1])
                    : trim($parts[1]);
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
$expect('GET opening a stream is not allowed', $http('GET', $mcpUrl, ['Accept: text/event-stream']), 405);
$expect('a browser gets the page not found', $http('GET', $mcpUrl, ['Accept: text/html,application/xhtml+xml']), 404);
// The other addresses meant for programs answer a browser the same way.
$browser = ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'];
foreach (['oauth' => 'a=token', 'relay' => 'action=next', 'prm' => '', 'wellknown' => ''] as $controller => $query) {
    $expect('a browser on ' . $controller . ' gets the page not found', $http('GET', $endpoint($controller, $query), $browser), 404);
}
$expect('a program still reads the resource document', $http('GET', $endpoint('prm'), ['Accept: application/json']), 200);
$expect('a program still reads the authorization server document', $http('GET', $endpoint('wellknown'), ['Accept: application/json']), 200);
$expect('a program sending GET to the token address', $http('GET', $endpoint('oauth', 'a=token'), ['Accept: application/json']), 405);
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
$section('Compact mode');
// The runners must refuse what a direct call refuses, and the search must show
// no more than the full list would: each is checked on what comes back, not
// only on the HTTP status, which is 200 for a tool error.
$compactWas = (string) Configuration::get(SomcpConfig::TOOLS_COMPACT);
Configuration::updateValue(SomcpConfig::TOOLS_COMPACT, '1');
$see = static function (string $what, bool $held, array $response) use (&$failures, &$checks): void {
    ++$checks;
    printf("  %s  %s\n", $held ? 'ok   ' : 'FAIL ', $what);
    if (!$held) {
        $failures[] = $what . ': ' . substr($response['body'], 0, 300);
    }
};
$said = static function (array $response): string {
    return (string) ($response['json']['result']['content'][0]['text'] ?? '');
};
$failed = static function (array $response): bool {
    return ($response['json']['result']['isError'] ?? false) === true;
};
$run = static function (string $runner, string $tool, array $arguments = []) use ($rpc): string {
    return $rpc('tools/call', ['name' => $runner, 'arguments' => ['name' => $tool, 'arguments' => (object) $arguments]]);
};

$listed = $http('POST', $mcpUrl, $bearer($valid['access_token']), $rpc('tools/list'));
$names = array_column((array) ($listed['json']['result']['tools'] ?? []), 'name');
sort($names);
$see('the list carries the pinned tools and the three tools of compact mode', $names === [
    'somcp_download_file', 'somcp_ping', SomcpCompact::READ_TOOL, SomcpCompact::WRITE_TOOL, SomcpCompact::SEARCH_TOOL, 'somcp_upload_file',
], $listed);
$upload = array_values(array_filter((array) ($listed['json']['result']['tools'] ?? []), static function ($tool): bool {
    return is_array($tool) && ($tool['name'] ?? '') === 'somcp_upload_file';
}))[0] ?? [];
$see('the upload tool tells a client it takes a file of the conversation', ($upload['_meta']['openai/fileParams'] ?? null) === ['file'], $listed);

$searched = $http('POST', $mcpUrl, $bearer($reader['access_token']), $rpc('tools/call', [
    'name' => SomcpCompact::SEARCH_TOOL,
    'arguments' => ['names' => ['somcp_create_category', 'somcp_list_categories']],
]));
$answer = json_decode($said($searched), true);
$see('a search hides a tool the connection may not use', is_array($answer)
    && in_array('somcp_create_category', (array) ($answer['unknown'] ?? []), true)
    && !in_array('somcp_create_category', array_column((array) ($answer['tools'] ?? []), 'name'), true), $searched);

$wrongRunner = $http('POST', $mcpUrl, $bearer($valid['access_token']), $run(SomcpCompact::READ_TOOL, 'somcp_create_category', ['name' => 'Created by an attack']));
$see('the read runner refuses a tool that changes the shop', $failed($wrongRunner)
    && strpos($said($wrongRunner), SomcpCompact::WRITE_TOOL) !== false, $wrongRunner);
$expect('read-only token running a change through the write runner', $http('POST', $mcpUrl, $bearer($reader['access_token']), $run(
    SomcpCompact::WRITE_TOOL,
    'somcp_create_category',
    ['name' => 'Created by an attack']
)), 403);
$guessed = $http('POST', $mcpUrl, $bearer($valid['access_token']), $run(SomcpCompact::READ_TOOL, 'somcp_made_up_tool'));
$see('a made-up name is refused and pointed to the search', $failed($guessed)
    && strpos($said($guessed), SomcpCompact::SEARCH_TOOL) !== false, $guessed);
$looped = $http('POST', $mcpUrl, $bearer($valid['access_token']), $run(SomcpCompact::READ_TOOL, SomcpCompact::WRITE_TOOL));
$see('a runner refuses to run a runner', $failed($looped), $looped);
$read = $http('POST', $mcpUrl, $bearer($valid['access_token']), $run(SomcpCompact::READ_TOOL, 'somcp_list_order_statuses'));
$see('the read runner runs a read tool', $read['status'] === 200 && !$failed($read) && $said($read) !== '', $read);

$guide = $http('POST', $mcpUrl, $bearer($valid['access_token']), $rpc('prompts/get', ['name' => 'somcp-order-lookup']));
$see('a guide starts by saying how to run its tools', strpos(
    (string) ($guide['json']['result']['messages'][0]['content']['text'] ?? ''),
    '## How to call the tools of this guide'
) === 0, $guide);
$catalogue = $http('POST', $mcpUrl, $bearer($valid['access_token']), $rpc('prompts/get', ['name' => 'somcp-catalogue-manage']));
$see('a guide that sends files says the file tools are called directly', strpos(
    (string) ($catalogue['json']['result']['messages'][0]['content']['text'] ?? ''),
    'directly, as they are in your list: somcp_ping, somcp_upload_file, somcp_download_file'
) !== false, $catalogue);

Configuration::updateValue(SomcpConfig::TOOLS_COMPACT, '0');
$full = $http('POST', $mcpUrl, $bearer($valid['access_token']), $rpc('tools/list'));
$fullNames = array_column((array) ($full['json']['result']['tools'] ?? []), 'name');
$see('compact mode off lists the tools themselves', count($fullNames) > 6
    && !in_array(SomcpCompact::SEARCH_TOOL, $fullNames, true), $full);
Configuration::updateValue(SomcpConfig::TOOLS_COMPACT, $compactWas);

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
// The consent page is off by default: then even a valid request is a 404.
SomcpConfig::set(SomcpConfig::CONSENT_PAGE, '0');
$expect('valid request while the consent page is switched off', $http('GET', $endpoint('authorize', http_build_query([
    'response_type' => 'code',
    'client_id' => $clientA,
    'redirect_uri' => 'http://127.0.0.1:9/callback',
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
])), ['Accept: text/html']), 404);
SomcpConfig::set(SomcpConfig::CONSENT_PAGE, '1');
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
$expect('sign-in page with an unregistered redirect address', $authorize, 404);
// Opened by hand, the address is a dead link like any other: nothing says the
// module is there. The diagnostic's pass, which only the shop can write, is
// what still shows the module's page - and a forged or stale one does not.
$expect('sign-in page opened with no request', $http('GET', $endpoint('authorize'), ['Accept: text/html']), 404);
$pass = SomcpGuard::probe();
$stale = (string) (time() - 1);
$staleSignature = hash_hmac('sha256', 'probe|' . $stale, (string) Configuration::get(SomcpConfig::PROBE_KEY));
$withPass = static function (string $pass) use ($http, $endpoint): array {
    return $http('GET', $endpoint('authorize', 'somcp_probe=' . rawurlencode($pass)), ['Accept: text/html']);
};
$expect('sign-in page opened with a forged diagnostic pass', $withPass((time() + 60) . '.' . str_repeat('0', 64)), 404);
$expect('sign-in page opened with an expired diagnostic pass', $withPass($stale . '.' . $staleSignature), 404);
$probed = $withPass($pass);
// The diagnostic's Sign-in page line looks for this marker: if the template
// loses it, every shop reports a false error.
++$checks;
if ($probed['status'] !== 200 || strpos($probed['body'], 'id="somcp-authorize"') === false) {
    $failures[] = 'the sign-in page opened with the diagnostic pass does not show the module page: ' . $probed['status'];
    echo "  FAIL  sign-in page opened with the diagnostic pass ({$probed['status']})\n";
} else {
    echo "  ok    sign-in page opened with the diagnostic pass\n";
}
++$checks;
if (strtoupper($probed['headers']['x-frame-options'] ?? '') !== 'DENY') {
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
$section('Allowed return addresses');
$adminFolder = basename((string) _PS_ADMIN_DIR_);
$hostsBefore = SomcpConfig::get(SomcpConfig::REDIRECT_HOSTS);
$registerHttps = static function (string $uri) use ($http, $endpoint, $json): array {
    return $http('POST', $endpoint('oauth', 'a=register'), $json, (string) json_encode([
        'client_name' => 'Attack test',
        'redirect_uris' => [$uri],
    ]));
};
$expect('client registering a host that is not on the list', $registerHttps('https://attacker.example/callback'), 400);
SomcpConfig::set(SomcpConfig::REDIRECT_HOSTS, SomcpConfig::clean(SomcpConfig::REDIRECT_HOSTS, ''));
$expect('client registering a known host while the list is empty', $registerHttps('https://claude.ai/api/mcp/auth_callback'), 400);
// A client registered while its host was allowed, then the host taken off
// the list: the check at sign-in time is what stops it, not the registration.
SomcpConfig::set(SomcpConfig::REDIRECT_HOSTS, 'partner.example');
$partner = SomcpOAuthClient::register([
    'client_name' => 'Attack test partner',
    'redirect_uris' => ['https://partner.example/callback'],
]);
SomcpConfig::set(SomcpConfig::REDIRECT_HOSTS, $hostsBefore);
if ($partner === null) {
    ++$checks;
    $failures[] = 'could not register the partner test client while its host was allowed';
    echo "  FAIL  partner test client registered\n";
} else {
    $offList = $http('GET', $endpoint('authorize', http_build_query([
        'response_type' => 'code',
        'client_id' => (string) $partner['client_id'],
        'redirect_uri' => 'https://partner.example/callback',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'state' => 'attack',
    ])), ['Accept: text/html']);
    $expect('sign-in page for a return address taken off the list', $offList, 404);
    // The shop's own 404 repeats the address it was asked for in its links
    // (languages, canonical), so the host typed by the caller may show up:
    // what must never show up is anything the caller did not already know.
    ++$checks;
    if ($adminFolder !== '' && stripos($offList['body'] . implode(' ', $offList['headers']), $adminFolder) !== false) {
        $failures[] = 'the refusal for a return address off the list names the administration folder';
        echo "  FAIL  refusal says nothing\n";
    } else {
        echo "  ok    refusal says nothing\n";
    }
}

// ---------------------------------------------------------------------------
$section('Signing in on the authorization page');
// An employee whose password the test knows, removed at the end.
$signinPassword = 'Attack-' . bin2hex(random_bytes(8));
$signinEmployee = new Employee();
$signinEmployee->firstname = 'Attack';
$signinEmployee->lastname = 'Signin';
$signinEmployee->email = 'attack-signin-' . bin2hex(random_bytes(4)) . '@example.com';
$signinEmployee->passwd = password_hash($signinPassword, PASSWORD_BCRYPT);
$signinEmployee->id_profile = (int) _PS_ADMIN_PROFILE_;
$signinEmployee->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
$signinEmployee->active = true;
$signinEmployee->add();

$cookieOf = static function (array $response): string {
    $line = (string) ($response['headers']['set-cookie'] ?? '');

    return preg_match('/' . preg_quote(SomcpOAuthServer::REQUEST_COOKIE, '/') . '=([^;]+)/', $line, $m) ? $m[1] : '';
};
$csrfOf = static function (array $response): string {
    return preg_match('/name="somcp_csrf" value="([^"]+)"/', $response['body'], $m) ? html_entity_decode($m[1]) : '';
};
$adminLeaks = [];
$noAdmin = static function (string $what, array $response) use (&$adminLeaks, $adminFolder): void {
    if ($adminFolder !== '' && stripos($response['body'] . ' ' . implode(' ', $response['headers']), $adminFolder) !== false) {
        $adminLeaks[] = $what;
    }
};
// The anti-robot check, solved the way the visitor's browser does.
$solve = static function (array $page): string {
    if (!preg_match('/challenge="([^"]+)"/', $page['body'], $m)) {
        return '';
    }
    $challenge = AltchaOrg\Altcha\Challenge::fromArray((array) json_decode(html_entity_decode($m[1]), true));
    $altcha = new AltchaOrg\Altcha\Altcha();
    $solution = $altcha->solveChallenge(new AltchaOrg\Altcha\SolveChallengeOptions(
        algorithm: new AltchaOrg\Altcha\Algorithm\Pbkdf2(),
        challenge: $challenge,
    ));

    return $solution === null ? '' : (new AltchaOrg\Altcha\Payload($challenge, $solution))->toBase64();
};
// Counters left by an earlier run would block the sign-ins below.
$db->execute('DELETE FROM `' . _DB_PREFIX_ . 'somcp_rate_limit`');
$authorizeUrl = $endpoint('authorize');
$started = $http('GET', $endpoint('authorize', http_build_query([
    'response_type' => 'code',
    'client_id' => $clientA,
    'redirect_uri' => 'http://127.0.0.1:9/callback',
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
    'state' => 'signin',
])), ['Accept: text/html']);
$noAdmin('sign-in page', $started);
$requestCookie = $cookieOf($started);
$csrf = $csrfOf($started);
++$checks;
if ($started['status'] !== 200 || $requestCookie === '' || $csrf === '' || strpos($started['body'], 'somcp_password') === false) {
    $failures[] = sprintf(
        'the authorization page does not show the sign-in form for a valid request (HTTP %d, request cookie %s, form token %s, password field %s)',
        $started['status'],
        $requestCookie === '' ? 'missing' : 'set',
        $csrf === '' ? 'missing' : 'present',
        strpos($started['body'], 'somcp_password') === false ? 'missing' : 'present'
    );
    echo "  FAIL  sign-in form shown\n";
} else {
    echo "  ok    sign-in form shown\n";
    $withCookie = array_merge($form, ['Cookie: ' . SomcpOAuthServer::REQUEST_COOKIE . '=' . $requestCookie]);
    // The page the last answer showed: its anti-robot challenge is the one to
    // solve for the next attempt.
    $lastPage = $started;
    $signin = static function (string $email, string $password, string $token, ?string $captcha = null) use ($http, $authorizeUrl, $withCookie, $solve, &$lastPage): array {
        $response = $http('POST', $authorizeUrl, $withCookie, http_build_query([
            'somcp_step' => 'signin',
            'somcp_csrf' => $token,
            'somcp_email' => $email,
            'somcp_password' => $password,
            'somcp_altcha' => $captcha ?? $solve($lastPage),
        ]));
        if (strpos($response['body'], 'challenge="') !== false) {
            $lastPage = $response;
        }

        return $response;
    };
    $refusedPage = static function (string $what, array $response) use (&$failures, &$checks): void {
        ++$checks;
        if ($response['status'] !== 200 || isset($response['headers']['location']) || strpos($response['body'], 'somcp_password') === false) {
            $failures[] = $what . ': not refused on the sign-in page (HTTP ' . $response['status'] . ')';
            echo "  FAIL  $what\n";
        } else {
            echo "  ok    $what\n";
        }
    };
    $wrong = $signin((string) $signinEmployee->email, 'not-the-password', $csrf);
    $refusedPage('wrong password', $wrong);
    $noAdmin('wrong password', $wrong);
    $unknown = $signin('nobody-' . bin2hex(random_bytes(4)) . '@example.com', $signinPassword, $csrf);
    $refusedPage('unknown email', $unknown);
    // The two refusals must read the same, or the page tells which emails exist.
    ++$checks;
    // The form token and the anti-robot challenge change on every page.
    if (preg_replace('/(value|challenge)="[^"]*"/', '', $wrong['body']) !== preg_replace('/(value|challenge)="[^"]*"/', '', $unknown['body'])) {
        $failures[] = 'a wrong password and an unknown email are not answered with the same page';
        echo "  FAIL  same answer for a wrong password and an unknown email\n";
    } else {
        echo "  ok    same answer for a wrong password and an unknown email\n";
    }
    $refusedPage('right password without the form token', $signin((string) $signinEmployee->email, $signinPassword, 'forged'));
    $refusedPage('right password without the anti-robot check', $signin((string) $signinEmployee->email, $signinPassword, $csrf, ''));
    // Three failures so far; the counter starts again so the checks below
    // stay under the block, which has its own check further down.
    $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'somcp_rate_limit`');
    $usedOnce = $solve($lastPage);
    $refusedPage('wrong password with a solved check', $signin((string) $signinEmployee->email, 'still-not-it', $csrf, $usedOnce));
    $refusedPage('right password with a check already used', $signin((string) $signinEmployee->email, $signinPassword, $csrf, $usedOnce));

    $requiredBefore = SomcpConfig::get(SomcpConfig::REQUIRED_PROFILE);
    $otherProfile = (int) Db::getInstance()->getValue('SELECT `id_profile` FROM `' . _DB_PREFIX_ . 'profile` WHERE `id_profile` <> ' . (int) _PS_ADMIN_PROFILE_);
    if ($otherProfile > 0) {
        // A super administrator always passes the required profile, so the
        // test employee is moved to another profile for this one check.
        $signinEmployee->id_profile = $otherProfile;
        $signinEmployee->update();
        SomcpConfig::set(SomcpConfig::REQUIRED_PROFILE, (string) _PS_ADMIN_PROFILE_);
        $refusedPage('right password on a profile not allowed to connect assistants', $signin((string) $signinEmployee->email, $signinPassword, $csrf));
        SomcpConfig::set(SomcpConfig::REQUIRED_PROFILE, $requiredBefore);
        $signinEmployee->id_profile = (int) _PS_ADMIN_PROFILE_;
        $signinEmployee->update();
    }

    $signedIn = $signin((string) $signinEmployee->email, $signinPassword, $csrf);
    $expect('right email and password', $signedIn, 302);
    $noAdmin('after signing in', $signedIn);

    // The password leads to the code e-mailed to the employee, not to the
    // consent. The test cannot read the e-mail, so it writes a code of its
    // own over the one sent - what only someone with the database could do.
    $codePage = $http('GET', $authorizeUrl, $withCookie);
    ++$checks;
    if ($codePage['status'] !== 200 || strpos($codePage['body'], 'data-somcp-state="otp"') === false || strpos($codePage['body'], 'value="allow"') !== false) {
        $failures[] = 'after the password, the page does not ask for the e-mailed code (HTTP ' . $codePage['status'] . ')';
        echo "  FAIL  the password leads to the code page, not the consent\n";
    } else {
        echo "  ok    the password leads to the code page, not the consent\n";
    }
    $codeCsrf = $csrfOf($codePage);
    $pending = SomcpOAuthServer::loadPendingRequest($requestCookie);
    $setCode = static function (string $code) use ($db, $pending): void {
        $db->update('somcp_oauth_request', [
            'otp_hash' => pSQL(SomcpOAuthServer::otpHash((int) $pending['id_request'], $code)),
            'otp_expires_at' => pSQL(date('Y-m-d H:i:s', time() + 600)),
            'otp_tries' => 0,
        ], '`id_request` = ' . (int) $pending['id_request']);
    };
    $typeCode = static function (string $code, string $token, string $step = 'otp') use ($http, $authorizeUrl, $withCookie): array {
        return $http('POST', $authorizeUrl, $withCookie, http_build_query([
            'somcp_step' => $step,
            'somcp_csrf' => $token,
            'somcp_otp' => str_split($code),
        ]));
    };
    $setCode('482913');
    $expect('consent posted before the code was typed', $http('POST', $authorizeUrl, $withCookie, http_build_query([
        'somcp_step' => 'consent',
        'somcp_csrf' => $codeCsrf,
        'decision' => 'allow',
    ])), 200);
    $wrongCode = $typeCode('000000', $codeCsrf);
    ++$checks;
    if ($wrongCode['status'] !== 200 || strpos($wrongCode['body'], 'data-somcp-state="otp"') === false || isset($wrongCode['headers']['location'])) {
        $failures[] = 'a wrong code is not refused on the code page (HTTP ' . $wrongCode['status'] . ')';
        echo "  FAIL  wrong code refused\n";
    } else {
        echo "  ok    wrong code refused\n";
    }
    $expect('a new code asked for before the wait is over', $typeCode('', $codeCsrf, 'resend'), 302);
    ++$checks;
    if ((int) $db->getValue('SELECT `otp_sends` FROM `' . _DB_PREFIX_ . 'somcp_oauth_request` WHERE `id_request` = ' . (int) $pending['id_request']) !== 1) {
        $failures[] = 'a new code was sent before the wait was over';
        echo "  FAIL  no new code before the wait is over\n";
    } else {
        echo "  ok    no new code before the wait is over\n";
    }
    $expect('right code', $typeCode('482913', $codeCsrf), 302);
    $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'somcp_rate_limit`');

    $consentPage = $http('GET', $authorizeUrl, $withCookie);
    $noAdmin('consent page', $consentPage);
    $consentCsrf = $csrfOf($consentPage);
    ++$checks;
    if ($consentPage['status'] !== 200 || strpos($consentPage['body'], 'value="allow"') === false || strpos($consentPage['body'], '127.0.0.1') === false) {
        $failures[] = 'after signing in, the consent page with the return host is not shown (HTTP ' . $consentPage['status'] . ')';
        echo "  FAIL  consent page shown with the return host\n";
    } else {
        echo "  ok    consent page shown with the return host\n";
    }
    ++$checks;
    if ($consentCsrf === '' || hash_equals($csrf, $consentCsrf) || hash_equals($codeCsrf, $consentCsrf)) {
        $failures[] = 'the form token is not renewed at sign-in and again at the code';
        echo "  FAIL  form token renewed at sign-in\n";
    } else {
        echo "  ok    form token renewed at sign-in\n";
    }
    $consent = static function (string $token) use ($http, $authorizeUrl, $withCookie): array {
        return $http('POST', $authorizeUrl, $withCookie, http_build_query([
            'somcp_step' => 'consent',
            'somcp_csrf' => $token,
            'decision' => 'allow',
        ]));
    };
    $stale = $consent($csrf);
    ++$checks;
    if (isset($stale['headers']['location'])) {
        $failures[] = 'the consent was accepted with the form token shown before signing in';
        echo "  FAIL  consent with the token from before the sign-in\n";
    } else {
        echo "  ok    consent with the token from before the sign-in\n";
    }
    $logBefore = (int) $db->getValue('SELECT MAX(`id_log`) FROM `' . _DB_PREFIX_ . 'somcp_log`');
    $granted = $consent($consentCsrf);
    // The e-mail about the new connection: whether the shop can deliver it
    // depends on its mail settings, but building it must not fail.
    $mailError = (string) $db->getValue('SELECT `message` FROM `' . _DB_PREFIX_ . 'somcp_log`'
        . " WHERE `event` = 'new_assistant_mail_failed' AND `id_log` > " . $logBefore
        . " AND `message` <> 'the shop did not send the e-mail'");
    ++$checks;
    if ($mailError !== '') {
        $failures[] = 'the e-mail about a new connection could not be built: ' . $mailError;
        echo "  FAIL  e-mail about the new connection built\n";
    } else {
        echo "  ok    e-mail about the new connection built\n";
    }
    $location = (string) ($granted['headers']['location'] ?? '');
    ++$checks;
    if ($granted['status'] !== 302 || strpos($location, 'http://127.0.0.1:9/callback?') !== 0 || strpos($location, 'code=') === false) {
        $failures[] = 'allowing the connection does not send a code back to the assistant: ' . $location;
        echo "  FAIL  code sent back to the assistant\n";
    } else {
        echo "  ok    code sent back to the assistant\n";
        parse_str((string) parse_url($location, PHP_URL_QUERY), $back);
        $expect('code from the page redeemed with its verifier', $redeem((string) ($back['code'] ?? ''), $clientA, $verifier), 200);
    }
    $replayed = $consent($consentCsrf);
    ++$checks;
    if (isset($replayed['headers']['location'])) {
        $failures[] = 'the same consent was accepted twice';
        echo "  FAIL  consent sent a second time\n";
    } else {
        echo "  ok    consent sent a second time\n";
    }

    // Guessing: five wrong passwords, then the right one is refused too.
    $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'somcp_rate_limit`');
    $again = $http('GET', $endpoint('authorize', http_build_query([
        'response_type' => 'code',
        'client_id' => $clientA,
        'redirect_uri' => 'http://127.0.0.1:9/callback',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'state' => 'guess',
    ])), ['Accept: text/html']);
    $withCookie = array_merge($form, ['Cookie: ' . SomcpOAuthServer::REQUEST_COOKIE . '=' . $cookieOf($again)]);
    $lastPage = $again;
    $guessCsrf = $csrfOf($again);
    // $forwarded is the X-Forwarded-For a proxy in front of the shop would
    // pass on: what the visitor wrote, then the address the proxy saw.
    $guess = static function (string $password, string $forwarded = '') use ($http, $authorizeUrl, &$withCookie, $solve, &$lastPage, $guessCsrf, $signinEmployee): array {
        $headers = $forwarded === '' ? $withCookie : array_merge($withCookie, ['X-Forwarded-For: ' . $forwarded]);
        $response = $http('POST', $authorizeUrl, $headers, http_build_query([
            'somcp_step' => 'signin',
            'somcp_csrf' => $guessCsrf,
            'somcp_email' => (string) $signinEmployee->email,
            'somcp_password' => $password,
            'somcp_altcha' => $solve($lastPage),
        ]));
        if (strpos($response['body'], 'challenge="') !== false) {
            $lastPage = $response;
        }

        return $response;
    };
    for ($i = 1; $i <= SomcpGuard::SIGNIN_ATTEMPTS - 1; ++$i) {
        $guess('guess-' . $i);
    }
    $expect('fifth wrong password blocks the address', $guess('guess-5'), 429);
    $blocked = $guess($signinPassword);
    $expect('right password from a blocked address', $blocked, 429);
    $noAdmin('blocked address', $blocked);

    // The shop sees these requests from its own network, as behind a proxy.
    // The address the visitor writes at the front of X-Forwarded-For changes
    // with each guess; the one the proxy appended does not, and is counted.
    $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'somcp_rate_limit`');
    for ($i = 1; $i <= SomcpGuard::SIGNIN_ATTEMPTS - 1; ++$i) {
        $guess('spoof-' . $i, '81.56.20.' . $i . ', 81.56.10.9');
    }
    $expect('fifth wrong password with a new forged address in front', $guess('spoof-5', '81.56.20.5, 81.56.10.9'), 429);

    // An IPv6 connection holds a whole /64: moving inside it is no new address.
    $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'somcp_rate_limit`');
    for ($i = 1; $i <= SomcpGuard::SIGNIN_ATTEMPTS - 1; ++$i) {
        $guess('six-' . $i, '2a01:e0a:1:2::' . $i);
    }
    $expect('fifth wrong password from another address of the same IPv6 /64', $guess('six-5', '2a01:e0a:1:2::ff'), 429);

    // A new address for each guess, as through rotating proxies: the account
    // tried is what stops it, and then even the right password is refused.
    $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'somcp_rate_limit`');
    for ($i = 1; $i <= SomcpGuard::ACCOUNT_ATTEMPTS - 1; ++$i) {
        $guess('rotate-' . $i, '81.56.30.' . $i);
    }
    $expect('tenth wrong password on one account from ten addresses', $guess('rotate-10', '81.56.30.10'), 429);
    $expect('right password on a blocked account from a fresh address', $guess($signinPassword, '81.56.31.1'), 429);

    // Three codes, no fourth: the last one mistyped five times closes the
    // request, and its link leads nowhere after that.
    $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'somcp_rate_limit`');
    $third = $http('GET', $endpoint('authorize', http_build_query([
        'response_type' => 'code',
        'client_id' => $clientA,
        'redirect_uri' => 'http://127.0.0.1:9/callback',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'state' => 'codes',
    ])), ['Accept: text/html']);
    $thirdCookie = $cookieOf($third);
    $thirdHeaders = array_merge($form, ['Cookie: ' . SomcpOAuthServer::REQUEST_COOKIE . '=' . $thirdCookie]);
    $http('POST', $authorizeUrl, $thirdHeaders, http_build_query([
        'somcp_step' => 'signin',
        'somcp_csrf' => $csrfOf($third),
        'somcp_email' => (string) $signinEmployee->email,
        'somcp_password' => $signinPassword,
        'somcp_altcha' => $solve($third),
    ]));
    $thirdRequest = SomcpOAuthServer::loadPendingRequest($thirdCookie);
    $db->update('somcp_oauth_request', [
        'otp_hash' => pSQL(SomcpOAuthServer::otpHash((int) ($thirdRequest['id_request'] ?? 0), '135790')),
        'otp_expires_at' => pSQL(date('Y-m-d H:i:s', time() + 600)),
        'otp_sends' => 3,
        'otp_tries' => 0,
    ], '`id_request` = ' . (int) ($thirdRequest['id_request'] ?? 0));
    $thirdCsrf = $csrfOf($http('GET', $authorizeUrl, $thirdHeaders));
    $lastTry = [];
    for ($i = 1; $i <= SomcpConfig::OTP_TRIES; ++$i) {
        $lastTry = $http('POST', $authorizeUrl, $thirdHeaders, http_build_query([
            'somcp_step' => 'otp',
            'somcp_csrf' => $thirdCsrf,
            'somcp_otp' => str_split('00000' . $i),
        ]));
    }
    $expect('fifth wrong entry of the last code closes the request', $lastTry, 403);
    $expect('right code once the request is closed', $http('POST', $authorizeUrl, $thirdHeaders, http_build_query([
        'somcp_step' => 'otp',
        'somcp_csrf' => $thirdCsrf,
        'somcp_otp' => str_split('135790'),
    ])), 200);
    $expect('the link of a closed request opened again', $http('GET', $authorizeUrl, $thirdHeaders), 404);
    $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'somcp_rate_limit`');
}
++$checks;
if ($adminLeaks !== []) {
    $failures[] = 'the authorization pages name the administration folder: ' . implode(', ', $adminLeaks);
    echo "  FAIL  administration folder never named\n";
} else {
    echo "  ok    administration folder never named\n";
}
$signinEmployee->delete();

$section('Registering in a loop');
$db->execute('DELETE FROM `' . _DB_PREFIX_ . 'somcp_rate_limit`');
$registered = 0;
for ($i = 1; $i <= 10; ++$i) {
    $one = $http('POST', $endpoint('oauth', 'a=register'), $json, (string) json_encode([
        'client_name' => 'Attack loop ' . $i,
        'redirect_uris' => ['http://127.0.0.1:9/callback'],
    ]));
    $registered += $one['status'] === 201 ? 1 : 0;
}
++$checks;
if ($registered !== 10) {
    $failures[] = 'ten registrations from one address were not all accepted (' . $registered . ' were)';
    echo "  FAIL  ten registrations accepted\n";
} else {
    echo "  ok    ten registrations accepted\n";
}
$expect('eleventh registration within the hour', $http('POST', $endpoint('oauth', 'a=register'), $json, (string) json_encode([
    'client_name' => 'Attack loop 11',
    'redirect_uris' => ['http://127.0.0.1:9/callback'],
])), 429);
$db->execute('DELETE FROM `' . _DB_PREFIX_ . 'somcp_rate_limit`');

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
$section('Files sent by an assistant');
// The direct address of a deposit, posted to the way an assistant's code tool
// posts: multipart, one file in the field the answer names.
$post = static function (string $url, string $name, string $bytes) use (&$failures): array {
    $file = tempnam(sys_get_temp_dir(), 'somcp');
    file_put_contents((string) $file, $bytes);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => [SomcpUpload::DIRECT_FIELD => new CURLFile((string) $file, 'application/octet-stream', $name)],
    ]);
    $text = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    @unlink((string) $file);
    $text = is_string($text) ? $text : '';

    return ['status' => $status, 'headers' => [], 'body' => $text, 'json' => json_decode($text, true)];
};
$fileToken = $pair($all)['access_token'];
$openDeposit = static function () use ($http, $mcpUrl, $bearer, $fileToken, $rpc, $endpoint): array {
    $answer = $http('POST', $mcpUrl, $bearer($fileToken), $rpc('tools/call', [
        'name' => 'somcp_upload_file',
        'arguments' => ['for' => 'somcp_create_manufacturer', 'field' => 'logo'],
    ]));
    $opened = json_decode((string) ($answer['json']['result']['content'][0]['text'] ?? ''), true);
    $query = [];
    parse_str((string) parse_url((string) ($opened['send_to']['url'] ?? ''), PHP_URL_QUERY), $query);
    // Rebuilt on the address the test serves, as the other doors are: the
    // shop's own domain is not the built-in server's.
    $door = $endpoint('deposit', http_build_query(array_intersect_key($query, ['u' => 1, 'e' => 1, 's' => 1])));

    return ['ref' => (string) ($opened['upload_ref'] ?? ''), 'door' => $door, 'query' => $query];
};
// A real 1x1 PNG: the content is checked against the extension.
$png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$deposit = $openDeposit();
$see('a deposit is opened with a reference and a signed address', $deposit['ref'] !== '' && isset($deposit['query']['s']), ['body' => json_encode($deposit)]);
$expect('the door read with GET', $http('GET', $deposit['door']), 404);
$expect('a forged signature', $post($endpoint('deposit', http_build_query(['u' => $deposit['query']['u'] ?? '', 'e' => $deposit['query']['e'] ?? 0, 's' => hash('sha256', 'forged')])), 'logo.png', $png), 404);
$expect('the signature of a chat file link', $post($endpoint('deposit', http_build_query([
    'u' => $deposit['query']['u'] ?? '',
    'e' => $deposit['query']['e'] ?? 0,
    's' => SomcpAssistantChat::signature((string) ($deposit['query']['u'] ?? ''), (int) ($deposit['query']['e'] ?? 0)),
])), 'logo.png', $png), 404);
$expect('a later expiry than the one signed', $post($endpoint('deposit', http_build_query([
    'u' => $deposit['query']['u'] ?? '',
    'e' => (int) ($deposit['query']['e'] ?? 0) + 3600,
    's' => $deposit['query']['s'] ?? '',
])), 'logo.png', $png), 404);
$expect('a script named as an image', $post($deposit['door'], 'logo.png', '<?php echo 1;'), 400);
$expect('a script under its own name', $post($deposit['door'], 'logo.php', '<?php echo 1;'), 400);
$expect('a format the field does not take', $post($deposit['door'], 'logo.pdf', "%PDF-1.4\n%%EOF"), 400);
$expect('the image the field takes', $post($deposit['door'], 'logo.png', $png), 200);
$expect('a second file on a deposit opened for one', $post($deposit['door'], 'other.png', $png), 400);
$see('the file is in the deposit and nowhere under the web root', count(SomcpUpload::deposited((int) (SomcpUpload::find($deposit['ref'], $idEmployee)['id_upload'] ?? 0))) === 1, ['body' => '']);
$late = $openDeposit();
$db->update('somcp_upload', ['expires_at' => '2000-01-01 00:00:00'], "`reference_hash` = '" . pSQL(SomcpRandom::hash($late['ref'])) . "'");
$expect('a deposit that expired', $post($late['door'], 'logo.png', $png), 404);
$refused = $http('POST', $mcpUrl, $bearer($pair($readOnly)['access_token']), $rpc('tools/call', [
    'name' => 'somcp_upload_file',
    'arguments' => ['for' => 'somcp_create_manufacturer'],
]));
$see('no deposit for a tool the connection may not call', $failed($refused) && strpos($said($refused), 'no tool of that name is available') !== false, $refused);
$forged = $http('POST', $mcpUrl, $bearer($fileToken), $rpc('tools/call', [
    'name' => 'somcp_download_file',
    'arguments' => ['download_ref' => 'invoice.1.' . (time() + 600) . '.' . hash('sha256', 'forged')],
]));
$see('no file for a forged download_ref', $failed($forged) && strpos($said($forged), 'not a reference made for this account') !== false, $forged);
$other = SomcpDownload::reference('invoice', 1, $idEmployee + 1000, Somcp::currentShopId());
$stolen = $http('POST', $mcpUrl, $bearer($fileToken), $rpc('tools/call', [
    'name' => 'somcp_download_file',
    'arguments' => ['download_ref' => $other['download_ref']],
]));
$see('no file for a download_ref made for another account', $failed($stolen) && strpos($said($stolen), 'not a reference made for this account') !== false, $stolen);
// The right reference opens the same door: the PDF comes back inside the
// answer, when the demo data has an invoice to build it from.
$idInvoice = (int) $db->getValue('SELECT oi.`id_order_invoice` FROM `' . _DB_PREFIX_ . 'order_invoice` oi'
    . ' INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = oi.`id_order` WHERE o.`id_shop` = ' . Somcp::currentShopId()
    . ' ORDER BY oi.`id_order_invoice` ASC');
if ($idInvoice > 0) {
    $own = SomcpDownload::reference('invoice', $idInvoice, $idEmployee, Somcp::currentShopId());
    $given = $http('POST', $mcpUrl, $bearer($fileToken), $rpc('tools/call', [
        'name' => 'somcp_download_file',
        'arguments' => ['download_ref' => $own['download_ref']],
    ]));
    $blob = (string) base64_decode((string) ($given['json']['result']['content'][1]['resource']['blob'] ?? ''));
    $see('the invoice comes back inside the answer, as a PDF', !$failed($given) && strncmp($blob, '%PDF', 4) === 0, $given);
    $about = json_decode((string) ($given['json']['result']['content'][0]['text'] ?? ''), true);
    $see('an attached invoice comes with no link to hand out instead', is_array($about)
        && !array_key_exists('url', $about) && !array_key_exists('page', $about), $given);
    // A file too large to attach goes out at a shared address: the same door,
    // opened here on the invoice and rebuilt on the address the test serves,
    // as the deposit door is.
    $shared = SomcpUpload::share($idEmployee, 'invoice.pdf', $blob);
    $read = [];
    parse_str((string) parse_url((string) ($shared['url'] ?? ''), PHP_URL_QUERY), $read);
    $fetched = $http('GET', $endpoint('file', http_build_query(array_intersect_key($read, ['f' => 1, 'e' => 1, 's' => 1]))));
    $see('the invoice is read from a shared address too, as the same PDF', $fetched['status'] === 200 && $fetched['body'] === $blob, $fetched);
    $expect('the address of the invoice with a forged signature', $http('GET', $endpoint('file', http_build_query(['f' => $read['f'] ?? '', 'e' => $read['e'] ?? 0, 's' => hash('sha256', 'forged')]))), 404);
    $expect('the address of the invoice used to deposit', $post($endpoint('deposit', http_build_query(['u' => $read['f'] ?? '', 'e' => $read['e'] ?? 0, 's' => $read['s'] ?? ''])), 'x.pdf', $blob), 404);
    $narrow = $http('POST', $mcpUrl, $bearer($pair($readOnly)['access_token']), $rpc('tools/call', [
        'name' => 'somcp_download_file',
        'arguments' => ['download_ref' => $own['download_ref']],
    ]));
    $see('no invoice for a connection without the scope that reads orders', $failed($narrow) && strpos($said($narrow), 'may not read somcp_get_order_document') !== false, $narrow);
}

// Every way an assistant brings a file in ends in the same check: the bytes
// as a data URI, and a file its client passes from the conversation.
$entered = static function (array $arguments) use ($http, $mcpUrl, $bearer, $fileToken, $rpc): array {
    return $http('POST', $mcpUrl, $bearer($fileToken), $rpc('tools/call', [
        'name' => 'somcp_upload_file',
        'arguments' => ['for' => 'somcp_create_manufacturer', 'field' => 'logo'] + $arguments,
    ]));
};
$byData = $entered(['data' => 'data:image/png;base64,' . base64_encode($png)]);
$see('an image sent as a data URI is deposited', !$failed($byData) && strpos($said($byData), '"deposited": true') !== false, $byData);
$scriptData = $entered(['data' => 'data:image/png;base64,' . base64_encode('<?php echo 1;')]);
$see('a script sent as a data URI named as an image is refused', $failed($scriptData) && strpos($said($scriptData), 'Nothing was deposited') !== false, $scriptData);
$typeData = $entered(['data' => 'data:application/x-php;base64,' . base64_encode('<?php echo 1;')]);
$see('a data URI of a type the shop never stores is refused', $failed($typeData) && strpos($said($typeData), 'Nothing was deposited') !== false, $typeData);
$privateFile = $entered(['file' => ['download_url' => 'https://127.0.0.1/logo.png', 'file_id' => 'file_1', 'file_name' => 'logo.png']]);
$see('a client file at a private address is refused like any address', $failed($privateFile) && strpos($said($privateFile), 'private, local or reserved address') !== false, $privateFile);
$archiveByFile = $http('POST', $mcpUrl, $bearer($fileToken), $rpc('tools/call', [
    'name' => 'somcp_upload_file',
    'arguments' => ['for' => 'somcp_upload_module', 'file' => ['download_url' => 'https://example.com/module.zip', 'file_id' => 'file_2']],
]));
$see('a module archive is never fetched from a client address either', $failed($archiveByFile) && strpos($said($archiveByFile), 'never fetched from an address') !== false, $archiveByFile);

// A file found by a read tool leaves through the same door as a document,
// an image as an image.
$idImage = (int) $db->getValue('SELECT `id_image` FROM `' . _DB_PREFIX_ . 'image_shop` WHERE `id_shop` = ' . Somcp::currentShopId() . ' ORDER BY `id_image` ASC');
if ($idImage > 0 && SomcpDownload::exists('product_image', (string) $idImage)) {
    $photo = SomcpDownload::reference('product_image', $idImage, $idEmployee, Somcp::currentShopId());
    $shown = $http('POST', $mcpUrl, $bearer($fileToken), $rpc('tools/call', [
        'name' => 'somcp_download_file',
        'arguments' => ['download_ref' => $photo['download_ref']],
    ]));
    $see('a product photo comes back as an image', !$failed($shown) && ($shown['json']['result']['content'][1]['type'] ?? '') === 'image', $shown);
    $otherPhoto = SomcpDownload::reference('product_image', $idImage, $idEmployee + 1000, Somcp::currentShopId());
    $stolenPhoto = $http('POST', $mcpUrl, $bearer($fileToken), $rpc('tools/call', [
        'name' => 'somcp_download_file',
        'arguments' => ['download_ref' => $otherPhoto['download_ref']],
    ]));
    $see('no photo for a download_ref made for another account', $failed($stolenPhoto) && strpos($said($stolenPhoto), 'not a reference made for this account') !== false, $stolenPhoto);
}
// An attachment keeps the type its uploader's browser sent: what goes out is
// read from its bytes instead.
$disguised = 'somcp' . bin2hex(random_bytes(8));
file_put_contents(_PS_DOWNLOAD_DIR_ . $disguised, '<html><script>alert(1)</script></html>');
$attachment = new Attachment();
$attachment->file = $disguised;
$attachment->file_name = 'photo.png';
$attachment->mime = 'image/png';
$attachment->name = [(int) Configuration::get('PS_LANG_DEFAULT') => 'Somcp disguised file'];
if ($attachment->add()) {
    $sent = $http('POST', $mcpUrl, $bearer($fileToken), $rpc('tools/call', [
        'name' => 'somcp_download_file',
        'arguments' => ['download_ref' => SomcpDownload::reference('attachment', (int) $attachment->id, $idEmployee, Somcp::currentShopId())['download_ref']],
    ]));
    $told = json_decode((string) ($sent['json']['result']['content'][0]['text'] ?? ''), true);
    $see('a page stored as a PNG attachment goes out under no type, never as an image', !$failed($sent)
        && ($told['mime_type'] ?? '') === 'application/octet-stream'
        && ($sent['json']['result']['content'][1]['type'] ?? '') === 'resource', $sent);
    $attachment->delete();
}
@unlink(_PS_DOWNLOAD_DIR_ . $disguised);
$traversal = SomcpDownload::reference('shop_logo', '..', $idEmployee, Somcp::currentShopId());
$climbed = $http('POST', $mcpUrl, $bearer($fileToken), $rpc('tools/call', [
    'name' => 'somcp_download_file',
    'arguments' => ['download_ref' => $traversal['download_ref']],
]));
$see('a reference naming a path is not a file', $failed($climbed), $climbed);

// ---------------------------------------------------------------------------
if (isset($argv[3])) {
    file_put_contents($argv[3], $pair($all)['access_token']);
    // A registered application, so the scan reaches the sign-in page itself
    // rather than the shop's 404 an unknown one gets.
    file_put_contents($argv[3] . '-client', $clientA);
}

printf("\n%d checks, %d failed\n", $checks, count($failures));
foreach ($failures as $failure) {
    echo '::error title=Attack not refused::' . str_replace(["\r", "\n", '%'], [' ', ' ', '%25'], $failure) . "\n";
}
exit($failures === [] ? 0 : 1);
