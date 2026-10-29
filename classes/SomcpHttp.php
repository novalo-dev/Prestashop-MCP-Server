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
 * SomcpHttp - everything the module does over HTTP, in one place.
 *
 * Incoming: reading headers that the hosting may have mangled, and writing JSON
 * responses. Outgoing: one fetch for documents on other sites and for the
 * diagnostic, with the guards that fetch needs, and one for the shop's own
 * public pages, kept to the shop's domains. Both go through the same request.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpHttp
{
    /** @var int Largest remote document the module will read, in bytes. */
    private const MAX_FETCH_BYTES = 65536;

    /** @var int Seconds before an outgoing request is abandoned. */
    private const FETCH_TIMEOUT = 5;

    /**
     * Reads a request header whatever the server hands us.
     *
     * getallheaders() is not defined on every SAPI, and header names arrive in
     * $_SERVER upper-cased with dashes turned into underscores.
     *
     * @param string $name header name, e.g. MCP-Protocol-Version
     *
     * @return string empty when the header was not sent
     */
    public static function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return trim((string) $_SERVER[$key]);
        }

        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $header => $value) {
                if (strcasecmp((string) $header, $name) === 0) {
                    return trim((string) $value);
                }
            }
        }

        return '';
    }

    /**
     * The bearer token presented on this request.
     *
     * The Authorization header is the one header a PHP-FPM or CGI setup drops:
     * Apache only forwards it when a rewrite rule copies it into the
     * environment, and PrestaShop writes that rule into its .htaccess only when
     * the CGI webservice option is enabled. Hence the three lookups - and the
     * line in the diagnostic that tells the merchant when none of them works.
     *
     * @return string empty when no bearer credential was presented
     */
    public static function bearerToken(): string
    {
        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION'] ?? '',
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
        ];

        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $header => $value) {
                if (strcasecmp((string) $header, 'Authorization') === 0) {
                    $candidates[] = (string) $value;
                }
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && preg_match('/^Bearer\s+(.+)$/i', $candidate, $m)) {
                return trim($m[1]);
            }
        }

        return '';
    }

    /**
     * True when the request carries an Authorization header at all, whatever
     * its scheme. The diagnostic uses it to tell "no credential sent" from
     * "credential eaten by the hosting".
     *
     * @return bool
     */
    public static function hasAuthorizationHeader(): bool
    {
        return self::header('Authorization') !== '' || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    /**
     * Sends a JSON body and stops the request.
     *
     * @param array<string, mixed> $payload
     * @param int $status
     * @param array<string, string> $headers
     */
    public static function json(array $payload, int $status = 200, array $headers = []): void
    {
        self::emit($payload, $status, $headers, false);
        exit;
    }

    /**
     * Sends a JSON body, closes the connection, and lets the request go on.
     *
     * For work that outlives the answer: the client has what it needs at once,
     * and leaving the page no longer stops the work. Some servers - LiteSpeed
     * among them - end PHP as soon as the visitor goes, whatever
     * ignore_user_abort() says; once the response is finished, nobody is left
     * to go.
     *
     * @param array<string, mixed> $payload
     */
    public static function jsonAndContinue(array $payload): void
    {
        self::emit($payload, 200, [], true);
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    /**
     * Writes a JSON response.
     *
     * @param array<string, mixed> $payload
     * @param int $status
     * @param array<string, string> $headers
     * @param bool $detach true to tell the client the response is complete,
     *                     so it stops waiting while the request goes on
     */
    private static function emit(array $payload, int $status, array $headers, bool $detach): void
    {
        $body = self::encode($payload);

        if ($body !== null && json_last_error() !== JSON_ERROR_NONE) {
            // Encoded, but something in the payload had to be substituted or
            // dropped to get there. The client gets a usable answer; the
            // merchant gets a line saying the answer was not complete.
            SomcpLogger::log(
                'json_encode_degraded',
                SomcpLogger::OUTCOME_FAILURE,
                json_last_error_msg()
            );
        }

        if ($body === null) {
            // An unencodable payload used to leave the caller with an empty
            // body and nothing anywhere to say why. A malformed answer is
            // unavoidable at that point; a silent one is not.
            SomcpLogger::log(
                'json_encode_failed',
                SomcpLogger::OUTCOME_FAILURE,
                json_last_error_msg()
            );

            $status = 500;
            $body = (string) json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => SomcpJsonRpc::INTERNAL_ERROR, 'message' => 'Response could not be encoded'],
            ]);
        }

        // Anything PrestaShop or a third-party module printed before this point
        // - a notice on a shop running in debug mode, a stray newline in an
        // included file - would sit in front of the JSON and make the whole
        // response unreadable to a client.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if ($detach) {
            // Without a length and a close, a server without a finish
            // function keeps the client waiting until the script ends.
            $headers['Content-Length'] = (string) strlen($body);
            $headers['Connection'] = 'close';
        }
        self::sendHeaders($status, 'application/json; charset=utf-8', $headers);
        echo $body;
        if ($detach) {
            flush();
        }
    }

    /**
     * Encodes a response body.
     *
     * Invalid UTF-8 is substituted rather than fatal: a shop name, an employee
     * name or a country label carrying one bad byte - which happens on shops
     * migrated from older encodings - must not cost the whole answer.
     *
     * @param array<string, mixed> $payload
     *
     * @return string|null null when the payload cannot be encoded at all
     */
    private static function encode(array $payload): ?string
    {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return $body === false ? null : $body;
    }

    /**
     * Sends an empty response and stops the request.
     *
     * @param int $status
     * @param array<string, string> $headers
     */
    public static function noContent(int $status, array $headers = []): void
    {
        self::sendHeaders($status, '', $headers);
        exit;
    }

    /**
     * The WWW-Authenticate challenge of RFC 6750, carrying the pointer to the
     * protected resource metadata and the scopes the call needs.
     *
     * @param string $scope space separated scopes required for the operation
     * @param string $error OAuth error code, empty on a plain 401
     * @param string $description human readable detail, optional
     *
     * @return string
     */
    public static function challenge(string $scope, string $error = '', string $description = ''): string
    {
        $parts = ['resource_metadata="' . SomcpConfig::prmUrl() . '"'];
        if ($error !== '') {
            $parts[] = 'error="' . $error . '"';
        }
        if ($scope !== '') {
            $parts[] = 'scope="' . $scope . '"';
        }
        if ($description !== '') {
            // Quoted-string: a stray quote would split the header.
            $parts[] = 'error_description="' . str_replace('"', "'", $description) . '"';
        }

        return 'Bearer ' . implode(', ', $parts);
    }

    /**
     * Sends the browser somewhere else and stops the script.
     *
     * PrestaShop's own Tools::redirect() and Tools::redirectAdmin() are
     * replaced when profiling is enabled, by a version that hands the URL to
     * the current controller and then carries on. An authorization flow cannot
     * have "kept running after the redirect" among its possible outcomes, and
     * the settings page runs inside a Symfony controller that has no
     * setRedirectAfter() to hand anything to.
     *
     * @param string $url absolute URL, already validated by the caller
     */
    public static function redirect(string $url): void
    {
        if (!headers_sent()) {
            header('Location: ' . $url);
        }

        exit;
    }

    /**
     * User-Agent sent on every outgoing request, so the module is identifiable
     * in the logs of whoever hosts a client metadata document.
     *
     * @return string
     */
    public static function userAgent(): string
    {
        $module = Module::getInstanceByName('somcp');
        $version = ($module && !empty($module->version)) ? (string) $module->version : '0.0.0';

        $shopName = (string) Configuration::get('PS_SHOP_NAME');
        $shopName = trim((string) preg_replace('/[^a-zA-Z0-9._-]+/', '-', $shopName), '-');
        $shopName = Tools::substr($shopName, 0, 40);
        if ($shopName === '') {
            $shopName = 'unknown';
        }

        return sprintf(
            'Novalo-OSoMCPServer/%s (%s; PrestaShop %s; PHP %s)',
            $version,
            $shopName,
            _PS_VERSION_,
            PHP_VERSION
        );
    }

    /**
     * Fetches a document over HTTPS.
     *
     * With $guard true - the setting used for anything a client asked us to
     * fetch - the request is pinned to an address that has been checked to be
     * public, redirects are refused and the body is capped. A client metadata
     * document is fetched because an unauthenticated caller named a URL, which
     * is the textbook shape of a server-side request forgery; the guard is what
     * keeps that from reaching the shop's own network.
     *
     * The diagnostic calls the shop's own URLs, which legitimately resolve to a
     * private address behind a load balancer, and passes false.
     *
     * @param string $url
     * @param bool $guard
     * @param array<int, string> $extraHeaders
     * @param string|null $jsonBody when given, the request is a POST carrying this JSON
     * @param int $maxBytes largest body read; 0 keeps the default cap
     *
     * @return array{status:int, body:string, headers:array<string,string>, error:string}
     */
    public static function fetch(string $url, bool $guard = true, array $extraHeaders = [], ?string $jsonBody = null, int $maxBytes = 0): array
    {
        $limit = $maxBytes > 0 ? $maxBytes : self::MAX_FETCH_BYTES;
        $fail = static function (string $message): array {
            return ['status' => 0, 'body' => '', 'headers' => [], 'error' => $message];
        };

        if (!function_exists('curl_init')) {
            return $fail('curl_missing');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return $fail('invalid_url');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return $fail('credentials_in_url');
        }

        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? 443);
        $resolve = [];

        if ($guard) {
            $addresses = self::resolvePublic($host);
            if ($addresses === []) {
                return $fail('host_not_public');
            }
            // Pins the address that was checked, so a name that answers with a
            // public address now and a private one a millisecond later - DNS
            // rebinding - cannot move the request onto the internal network.
            $resolve[] = $host . ':' . $port . ':' . $addresses[0];
        }

        $headers = $jsonBody !== null
            ? array_merge(['Accept: application/json', 'Content-Type: application/json'], $extraHeaders)
            : array_merge(['Accept: application/json'], $extraHeaders);

        return self::exchange($url, $resolve, $headers, $jsonBody, $limit, self::FETCH_TIMEOUT);
    }

    /**
     * Fetches a public page of the shop itself, as a visitor's browser would.
     *
     * The address must be http or https on one of the hosts given, which the
     * caller takes from the shop's own domains: that list, and not a check of
     * the resolved address, is what keeps the request on the shop. A shop's
     * own name may resolve to a private address behind a load balancer, as
     * the diagnostic already knows, so a public-address guard would refuse
     * the shop it is meant to read. Redirects are not followed here: each hop
     * goes back through this method and its host check.
     *
     * No cookie and no credential is sent, so the page is the one a visitor
     * without an account gets, never the employee's.
     *
     * @param string $url
     * @param array<int, string> $hosts lower-case host[:port] the page may be on
     * @param int $timeout seconds, at least 1
     * @param int $maxBytes largest body read
     *
     * @return array{status:int, body:string, headers:array<string,string>, error:string, truncated:bool}
     */
    public static function fetchShopPage(string $url, array $hosts, int $timeout, int $maxBytes): array
    {
        $fail = static function (string $message): array {
            return ['status' => 0, 'body' => '', 'headers' => [], 'error' => $message, 'truncated' => false];
        };

        if (!function_exists('curl_init')) {
            return $fail('curl_missing');
        }

        $authority = self::authority($url);
        if ($authority === null) {
            return $fail('invalid_url');
        }
        if (!in_array($authority, $hosts, true)) {
            return $fail('not_this_shop');
        }

        $response = self::exchange(
            $url,
            [],
            ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5'],
            null,
            $maxBytes,
            max(1, $timeout)
        );
        $truncated = strlen($response['body']) > $maxBytes;
        if ($truncated) {
            $response['body'] = substr($response['body'], 0, $maxBytes);
        }
        $response['truncated'] = $truncated;

        return $response;
    }

    /**
     * The lower-case host of an http or https address, with its port when it
     * is not the default one of its scheme - the form ShopUrl stores a domain
     * in. Null for any other scheme, an address carrying credentials, or one
     * that is not absolute.
     *
     * @param string $url
     *
     * @return string|null
     */
    public static function authority(string $url): ?string
    {
        // parse_url() and curl do not split every odd address the same way: a
        // backslash or a space can make them disagree on the host, and the
        // host checked has to be the host reached.
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $authority = strtolower((string) $parts['host']);
        $port = (int) ($parts['port'] ?? 0);
        if ($port > 0 && $port !== ($scheme === 'https' ? 443 : 80)) {
            $authority .= ':' . $port;
        }

        return $authority;
    }

    /**
     * One request, without following redirects, its body capped.
     *
     * Lengths are counted in bytes with strlen(): curl hands the write
     * callback raw bytes and aborts the transfer when the count returned
     * differs. Tools::strlen() counts characters after decoding HTML
     * entities, so it cut every body holding an accent or an &amp; short.
     *
     * @param string $url
     * @param array<int, string> $resolve CURLOPT_RESOLVE entries pinning the checked address
     * @param array<int, string> $headers
     * @param string|null $jsonBody when given, the request is a POST carrying this JSON
     * @param int $limit largest body read, in bytes
     * @param int $timeout seconds
     *
     * @return array{status:int, body:string, headers:array<string,string>, error:string}
     */
    private static function exchange(string $url, array $resolve, array $headers, ?string $jsonBody, int $limit, int $timeout): array
    {
        $received = '';
        $responseHeaders = [];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => self::userAgent(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$received, $limit) {
                $received .= $chunk;
                if (strlen($received) > $limit) {
                    // Returning less than the chunk length aborts the transfer.
                    return 0;
                }

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, $line) use (&$responseHeaders) {
                $length = strlen($line);
                $pair = explode(':', $line, 2);
                if (count($pair) === 2) {
                    $responseHeaders[strtolower(trim($pair[0]))] = trim($pair[1]);
                }

                return $length;
            },
        ]);
        if ($resolve !== []) {
            curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
        }
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        curl_exec($ch);
        $error = (string) curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [
            'status' => $status,
            'body' => $received,
            'headers' => $responseHeaders,
            'error' => $error !== '' && $status === 0 ? 'transport' : '',
        ];
    }

    /**
     * Resolves a host name and keeps only addresses that are routable on the
     * public internet.
     *
     * @param string $host
     *
     * @return array<int, string>
     */
    private static function resolvePublic(string $host): array
    {
        $candidates = [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $candidates[] = $host;
        } else {
            foreach ((array) @dns_get_record($host, DNS_A) as $record) {
                if (!empty($record['ip'])) {
                    $candidates[] = (string) $record['ip'];
                }
            }
            foreach ((array) @dns_get_record($host, DNS_AAAA) as $record) {
                if (!empty($record['ipv6'])) {
                    $candidates[] = (string) $record['ipv6'];
                }
            }
        }

        $public = [];
        foreach ($candidates as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $public[] = $address;
            }
        }

        // One private answer among the public ones is enough to refuse: a name
        // that resolves both ways is exactly what an attacker sets up.
        return count($public) === count($candidates) ? $public : [];
    }

    /**
     * @param int $status
     * @param string $contentType
     * @param array<string, string> $headers
     */
    private static function sendHeaders(int $status, string $contentType, array $headers): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($status);
        if ($contentType !== '') {
            header('Content-Type: ' . $contentType);
        }
        // Nothing the module returns may sit in a shared cache: tokens,
        // consent screens and error details are all per-request.
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }
}
