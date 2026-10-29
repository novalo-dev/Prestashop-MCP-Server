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
 * somcp_audit_page - one public page of the shop, fetched as a visitor gets
 * it, and what a search engine reads on it.
 *
 * The audit tools of this section read the fields stored in the database; the
 * theme decides what is actually printed, and a module can add or remove tags
 * on the way. Only the page itself says what search engines are given.
 *
 * Every request, the redirects included, stays on the shop's own domains: an
 * address on any other host is refused before anything is sent, so the tool
 * cannot be pointed at another site or at the shop's internal network.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolAuditPage
{
    /** Seconds for the whole fetch, redirects included. */
    private const TIMEOUT = 10;

    /** Largest page read, in bytes. */
    private const MAX_BYTES = 2097152;

    /** Redirects followed before giving up: browsers stop around 20, search engines sooner. */
    private const MAX_REDIRECTS = 5;

    /** Beyond this, search results cut the title (characters), as the audit tools of this section count. */
    private const TITLE_MAX = 60;

    /** Beyond this, search results cut the description (characters). */
    private const DESCRIPTION_MAX = 160;

    /** A page slower than this, in milliseconds, is reported. */
    private const SLOW_MS = 2000;

    /** Headings returned at most, so a mega menu cannot flood the answer. */
    private const MAX_HEADINGS = 100;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_audit_page',
            Somcp::t('Audit a public page of the shop'),
            'Fetches one public page of this shop as a visitor without an account gets it, and returns what a '
                . 'search engine reads on it: status, final_url, redirects (each hop with its status), time_ms, '
                . 'title, meta_description, meta_robots, x_robots_tag, canonical, hreflang (language and url of '
                . 'each alternate), headings (h1 to h3 in order), images (count, without_alt), links (internal '
                . 'and external counts), json_ld (each block\'s @type and main fields), open_graph, word_count '
                . 'and warnings (each a code and why): no_h1, several_h1, canonical_elsewhere, noindex, '
                . 'missing_hreflang, no_title, title_too_long (over 60 characters), no_meta_description, '
                . 'description_too_long (over 160 characters), redirect_chain, redirect_outside_shop, '
                . 'too_many_redirects, slow_page (over 2 seconds), not_ok, not_html, truncated. Only addresses of '
                . 'this shop are fetched: url is a full address on one of its domains, or a path such as '
                . '/en/3-clothes; anything else is refused. Read only, GET only. Use it after a change to check '
                . 'what the theme really prints. Do not use it for many pages at once (somcp_check_links), or '
                . 'for the fields stored in the database (somcp_audit_product_seo, somcp_audit_category_seo, '
                . 'somcp_audit_cms_seo, somcp_audit_brand_seo, somcp_list_meta_pages).',
            [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'An address of this shop, for example https://shop.example/en/3-clothes, '
                            . 'or a path from the root of its domain such as /en/3-clothes. / is the home page.',
                    ],
                ],
                'required' => ['url'],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_READ],
            [self::class, 'handle'],
            // What search engines read is set from Shop Parameters > Traffic &
            // SEO, the page the other SEO reads of this section ask for.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminMeta')
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public static function handle(array $arguments, array $context): array
    {
        $idShop = Somcp::currentShopId();
        $hosts = self::hosts($idShop);
        if ($hosts === []) {
            return SomcpTool::text('Nothing was fetched: this shop has no active domain.', true);
        }

        $requested = is_string($arguments['url'] ?? null) ? trim($arguments['url']) : '';
        $url = self::absolute($requested, $idShop);
        if ($url === null) {
            return SomcpTool::text(
                'Nothing was fetched: url must be a full http or https address of this shop, or a path starting '
                    . 'with a single /, such as /en/3-clothes.',
                true
            );
        }
        if (!in_array(SomcpHttp::authority($url), $hosts, true)) {
            return SomcpTool::text(
                'Nothing was fetched: this is not an address of this shop. Its domains are: '
                    . implode(', ', $hosts) . '.',
                true
            );
        }

        $warnings = [];
        $redirects = [];
        $started = microtime(true);
        $current = $url;
        $response = null;
        $stopped = false;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            // What is left of the time allowed, so the redirects cannot add
            // up to more than it.
            $left = (int) floor(self::TIMEOUT - (microtime(true) - $started));
            if ($left < 1) {
                break;
            }
            $response = SomcpHttp::fetchShopPage($current, $hosts, $left, self::MAX_BYTES);
            if ($response['status'] < 300 || $response['status'] >= 400) {
                break;
            }

            $location = self::resolve((string) ($response['headers']['location'] ?? ''), $current);
            $redirects[] = ['url' => $current, 'status' => $response['status'], 'location' => $location];
            if ($location === null) {
                // A redirect without a usable Location is where the visitor
                // stays, and so is the answer.
                break;
            }
            if (!in_array(SomcpHttp::authority($location), $hosts, true)) {
                $warnings[] = [
                    'code' => 'redirect_outside_shop',
                    'why' => 'The page sends visitors to an address outside the shop\'s domains, which was not '
                        . 'fetched.',
                    'location' => $location,
                ];
                $stopped = true;
                break;
            }
            if ($hop === self::MAX_REDIRECTS) {
                $warnings[] = [
                    'code' => 'too_many_redirects',
                    'why' => 'More than ' . self::MAX_REDIRECTS . ' redirects in a row: search engines give up '
                        . 'before reaching the page.',
                ];
                $stopped = true;
                break;
            }
            $current = $location;
            $response = null;
        }
        $timeMs = (int) round((microtime(true) - $started) * 1000);

        if ($response === null || $response['status'] === 0) {
            return SomcpTool::text(
                'The page could not be fetched: the shop did not answer'
                    . ($response === null ? ' within ' . self::TIMEOUT . ' seconds' : '')
                    . ($response !== null && $response['error'] !== '' ? ' (' . $response['error'] . ')' : '')
                    . '. Check that ' . $current . ' opens in a browser.',
                true
            );
        }

        if (count($redirects) > 1) {
            $warnings[] = [
                'code' => 'redirect_chain',
                'why' => 'The address goes through ' . count($redirects) . ' redirects: each one costs crawl time, '
                    . 'and links should point to the final address.',
            ];
        }
        if ($timeMs > self::SLOW_MS) {
            $warnings[] = [
                'code' => 'slow_page',
                'why' => 'The page took ' . $timeMs . ' ms to arrive, over 2 seconds: slow pages are crawled less '
                    . 'and rank lower.',
            ];
        }
        // A redirect the fetch stopped at has its own warning above; one
        // without a Location leaves the visitor there, and is reported here.
        if ($response['status'] !== 200 && !$stopped) {
            $warnings[] = [
                'code' => 'not_ok',
                'why' => 'The page answers ' . $response['status'] . ' instead of 200: search engines do not index '
                    . 'it.',
            ];
        }
        if ($response['truncated']) {
            $warnings[] = [
                'code' => 'truncated',
                'why' => 'The page is over 2 MB: only its first 2 MB were read.',
            ];
        }

        $xRobots = (string) ($response['headers']['x-robots-tag'] ?? '');
        $page = [
            'status' => $response['status'],
            'final_url' => $current,
            'redirects' => $redirects,
            'time_ms' => $timeMs,
            'title' => null,
            'meta_description' => null,
            'meta_robots' => null,
            'x_robots_tag' => $xRobots === '' ? null : $xRobots,
            'canonical' => null,
            'hreflang' => [],
            'headings' => [],
            'images' => ['count' => 0, 'without_alt' => 0],
            'links' => ['internal' => 0, 'external' => 0],
            'json_ld' => [],
            'open_graph' => new stdClass(),
            'word_count' => 0,
        ];

        $type = strtolower((string) ($response['headers']['content-type'] ?? ''));
        $isHtml = $type === '' || strpos($type, 'html') !== false;
        if (!$isHtml) {
            $warnings[] = [
                'code' => 'not_html',
                'why' => 'The address answers ' . $type . ', not a web page.',
            ];
            $page['warnings'] = $warnings;

            return SomcpTool::json($page);
        }

        $dom = self::load($response['body']);
        if ($dom === null) {
            $page['warnings'] = $warnings;

            return SomcpTool::json($page);
        }
        $xpath = new DOMXPath($dom);

        $page = array_merge($page, self::read($xpath, $current, $hosts));
        // An error page is read for what it says, but judged only by its
        // status: its missing description is not the page's problem.
        $page['warnings'] = $response['status'] === 200
            ? array_merge($warnings, self::checks($page, $current, $idShop))
            : $warnings;

        return SomcpTool::json($page);
    }

    /**
     * The hosts of the shop's active domains, as SomcpHttp::authority() writes
     * them, both the http and the https one.
     *
     * @param int $idShop
     *
     * @return array<int, string>
     */
    private static function hosts(int $idShop): array
    {
        $hosts = [];
        foreach (SomcpToolGetSeoSettings::domains($idShop) as $domain) {
            if (empty($domain['active'])) {
                continue;
            }
            foreach (['domain' => 'http', 'domain_ssl' => 'https'] as $field => $scheme) {
                $authority = SomcpHttp::authority($scheme . '://' . (string) $domain[$field] . '/');
                if ($authority !== null && !in_array($authority, $hosts, true)) {
                    $hosts[] = $authority;
                }
            }
        }

        return $hosts;
    }

    /**
     * The address to fetch: a full address as given, or a path on the shop's
     * main domain, in the scheme the shop is served in. Null when it is
     * neither.
     *
     * @param string $requested
     * @param int $idShop
     *
     * @return string|null
     */
    private static function absolute(string $requested, int $idShop): ?string
    {
        // The fragment never reaches the server.
        $hash = strpos($requested, '#');
        if ($hash !== false) {
            $requested = substr($requested, 0, $hash);
        }
        // Accented friendly URLs and spaces are typed as they read; on the
        // wire they are percent-encoded, as a browser sends them.
        $requested = (string) preg_replace_callback('/[\x80-\xff ]/', static function (array $byte): string {
            return rawurlencode($byte[0]);
        }, $requested);

        if (preg_match('#^https?://#i', $requested)) {
            return SomcpHttp::authority($requested) === null ? null : $requested;
        }
        // A path from the root. Two slashes would name another host.
        if ($requested === '' || $requested[0] !== '/' || strpos($requested, '//') === 0) {
            return null;
        }

        $https = (bool) Configuration::get('PS_SSL_ENABLED', null, (int) Shop::getGroupFromShop($idShop), $idShop);
        foreach (SomcpToolGetSeoSettings::domains($idShop) as $domain) {
            // The main domain comes first; an inactive one is not served.
            if (empty($domain['active'])) {
                continue;
            }
            $host = (string) ($https ? $domain['domain_ssl'] : $domain['domain']);

            return ($https ? 'https://' : 'http://') . $host . $requested;
        }

        return null;
    }

    /**
     * An address found on a page or in a Location header, made absolute
     * against the address it was found on. Null for what is not http or
     * https: mailto:, tel:, javascript:, an empty value.
     *
     * @param string $reference
     * @param string $base
     *
     * @return string|null
     */
    private static function resolve(string $reference, string $base): ?string
    {
        $reference = trim($reference);
        $hash = strpos($reference, '#');
        if ($hash !== false) {
            $reference = substr($reference, 0, $hash);
        }
        if ($reference === '' && $hash === false) {
            return null;
        }

        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $origin = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = (string) ($parts['path'] ?? '/');

        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $reference, $m)) {
            $other = strtolower($m[1]);

            return $other === 'http' || $other === 'https' ? $reference : null;
        }
        if (strpos($reference, '//') === 0) {
            return $scheme . ':' . $reference;
        }
        if ($reference === '') {
            return $base;
        }
        if ($reference[0] === '/') {
            return $origin . $reference;
        }
        if ($reference[0] === '?') {
            return $origin . $path . $reference;
        }
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin . ($directory === '' ? '/' : $directory) . $reference;
    }

    /**
     * The page as a DOM, its own errors kept away from PHP's: HTML5 tags and
     * the odd unclosed element are normal on a storefront and say nothing
     * about its SEO.
     *
     * @param string $html
     *
     * @return DOMDocument|null
     */
    private static function load(string $html): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // libxml reads HTML as Latin-1 unless told otherwise, which turns
        // every accent of a UTF-8 page into two characters. LIBXML_NONET
        // keeps the parser from reaching for anything on the network.
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $dom : null;
    }

    /**
     * Everything the page tells a search engine.
     *
     * @param DOMXPath $xpath
     * @param string $url the address the page was read from
     * @param array<int, string> $hosts
     *
     * @return array<string, mixed>
     */
    private static function read(DOMXPath $xpath, string $url, array $hosts): array
    {
        $title = self::first($xpath, '//title');
        $canonical = self::attribute($xpath, '//link[translate(@rel, "CANONICAL", "canonical")="canonical"]', 'href');

        $hreflang = [];
        foreach (self::elements($xpath, '//link[@hreflang][contains(translate(@rel, "ALTERNATE", "alternate"), "alternate")]') as $node) {
            $hreflang[] = [
                'language' => $node->getAttribute('hreflang'),
                'url' => self::resolve($node->getAttribute('href'), $url),
            ];
        }

        $headings = [];
        foreach (self::nodes($xpath, '//h1 | //h2 | //h3') as $node) {
            if (count($headings) >= self::MAX_HEADINGS) {
                break;
            }
            $headings[] = [
                'level' => (int) substr(strtolower($node->nodeName), 1),
                'text' => self::clean($node->textContent, 200),
            ];
        }

        $images = ['count' => 0, 'without_alt' => 0];
        foreach (self::elements($xpath, '//img') as $node) {
            ++$images['count'];
            if (trim($node->getAttribute('alt')) === '') {
                ++$images['without_alt'];
            }
        }

        $links = ['internal' => 0, 'external' => 0];
        foreach (self::elements($xpath, '//a[@href]') as $node) {
            $target = self::resolve($node->getAttribute('href'), $url);
            if ($target === null) {
                continue;
            }
            ++$links[in_array(SomcpHttp::authority($target), $hosts, true) ? 'internal' : 'external'];
        }

        $jsonLd = [];
        foreach (self::nodes($xpath, '//script[translate(@type, "LDJSON", "ldjson")="application/ld+json"]') as $node) {
            $decoded = json_decode(trim($node->textContent), true);
            if (!is_array($decoded)) {
                $jsonLd[] = ['@type' => null, 'error' => 'invalid_json'];
                continue;
            }
            foreach (self::items($decoded) as $item) {
                $jsonLd[] = self::summary($item, true);
            }
        }

        $openGraph = [];
        foreach (self::elements($xpath, '//meta[starts-with(@property, "og:")]') as $node) {
            $openGraph[$node->getAttribute('property')] = self::clean($node->getAttribute('content'), 500);
        }

        $words = 0;
        $text = self::nodes(
            $xpath,
            '//body//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript) and not(ancestor::template)]'
        );
        foreach ($text as $node) {
            $words += (int) preg_match_all('/[\p{L}\p{N}]+(?:[\'\x{2019}-][\p{L}\p{N}]+)*/u', $node->textContent);
        }

        return [
            'title' => $title === null ? null : self::clean($title, 500),
            'meta_description' => self::meta($xpath, 'description'),
            'meta_robots' => self::meta($xpath, 'robots'),
            'canonical' => $canonical === null ? null : self::resolve($canonical, $url),
            'hreflang' => $hreflang,
            'headings' => $headings,
            'images' => $images,
            'links' => $links,
            'json_ld' => $jsonLd,
            'open_graph' => $openGraph === [] ? new stdClass() : $openGraph,
            'word_count' => $words,
        ];
    }

    /**
     * What on the page costs it in search results.
     *
     * @param array<string, mixed> $page
     * @param string $url the address the page was read from
     * @param int $idShop
     *
     * @return array<int, array<string, mixed>>
     */
    private static function checks(array $page, string $url, int $idShop): array
    {
        $warnings = [];

        $h1 = count(array_filter($page['headings'], static function (array $heading): bool {
            return $heading['level'] === 1;
        }));
        if ($h1 === 0) {
            $warnings[] = ['code' => 'no_h1', 'why' => 'The page has no h1 heading: search engines read it as the '
                . 'subject of the page.'];
        } elseif ($h1 > 1) {
            $warnings[] = ['code' => 'several_h1', 'why' => 'The page has ' . $h1 . ' h1 headings: the subject of '
                . 'the page is less clear.'];
        }

        if ($page['canonical'] !== null && $page['canonical'] !== $url) {
            $warnings[] = [
                'code' => 'canonical_elsewhere',
                'why' => 'The canonical link points to another address: search engines index that one instead of '
                    . 'this page. Expected on a filtered or paginated address, not on the page itself.',
                'canonical' => $page['canonical'],
            ];
        }

        // Directives are comma separated, and the header may name a crawler
        // first (googlebot: noindex). Matched whole, since max-image-preview
        // takes none as a value without meaning noindex.
        $robots = (string) $page['meta_robots'] . ',' . (string) $page['x_robots_tag'];
        if (preg_match('/(?:^|[,:])\s*(?:noindex|none)\s*(?:,|$)/i', $robots)) {
            $warnings[] = ['code' => 'noindex', 'why' => 'The page asks search engines not to index it, through '
                . 'its robots meta tag or the X-Robots-Tag header.'];
        }

        $title = (string) $page['title'];
        if ($title === '') {
            $warnings[] = ['code' => 'no_title', 'why' => 'The page has no title: search engines make one up.'];
        } elseif (Tools::strlen($title) > self::TITLE_MAX) {
            $warnings[] = ['code' => 'title_too_long', 'why' => 'The title holds ' . Tools::strlen($title)
                . ' characters: search results cut it after about ' . self::TITLE_MAX . '.'];
        }
        $description = (string) $page['meta_description'];
        if ($description === '') {
            $warnings[] = ['code' => 'no_meta_description', 'why' => 'The page has no meta description: search '
                . 'engines show a piece of its text instead.'];
        } elseif (Tools::strlen($description) > self::DESCRIPTION_MAX) {
            $warnings[] = ['code' => 'description_too_long', 'why' => 'The meta description holds '
                . Tools::strlen($description) . ' characters: search results cut it after about '
                . self::DESCRIPTION_MAX . '.'];
        }

        // An alternate per language only means something when the shop has
        // more than one: a single-language shop has nothing to point to.
        $languages = Language::getLanguages(true, $idShop);
        if (count($languages) > 1) {
            $declared = [];
            foreach ($page['hreflang'] as $alternate) {
                $code = strtolower((string) $alternate['language']);
                $declared[$code] = true;
                $declared[explode('-', $code)[0]] = true;
            }
            foreach ($languages as $language) {
                $iso = strtolower((string) $language['iso_code']);
                $code = strtolower((string) ($language['language_code'] ?? ''));
                if (!isset($declared[$iso]) && ($code === '' || !isset($declared[$code]))) {
                    $warnings[] = [
                        'code' => 'missing_hreflang',
                        'why' => 'No hreflang alternate for this active language: search engines may show the '
                            . 'page in the wrong language.',
                        'language' => $iso,
                    ];
                }
            }
        }

        return $warnings;
    }

    /**
     * The objects of a JSON-LD block: one object, a list of them, or a graph.
     *
     * @param array<mixed> $decoded
     *
     * @return array<int, array<mixed>>
     */
    private static function items(array $decoded): array
    {
        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            $decoded = $decoded['@graph'];
        } elseif (!array_is_list($decoded)) {
            return [$decoded];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * The type of a JSON-LD object and its plain fields; nested objects give
     * their own type and plain fields, one level down, and lists their size.
     *
     * @param array<mixed> $item
     * @param bool $nested whether objects inside are summed up too
     *
     * @return array<string, mixed>
     */
    private static function summary(array $item, bool $nested): array
    {
        $summary = ['@type' => $item['@type'] ?? null];
        foreach ($item as $key => $value) {
            $key = (string) $key;
            if ($key === '@type' || $key === '@context') {
                continue;
            }
            if (is_string($value)) {
                $summary[$key] = self::clean($value, 200);
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $summary[$key] = $value;
            } elseif (is_array($value) && $nested) {
                $summary[$key] = array_is_list($value)
                    ? (count($value) === 1 && is_array($value[0]) ? self::summary($value[0], false) : ['count' => count($value)])
                    : self::summary($value, false);
            }
        }

        return $summary;
    }

    /**
     * @param DOMXPath $xpath
     * @param string $query
     *
     * @return array<int, DOMElement>
     */
    private static function elements(DOMXPath $xpath, string $query): array
    {
        $found = [];
        foreach (self::nodes($xpath, $query) as $node) {
            if ($node instanceof DOMElement) {
                $found[] = $node;
            }
        }

        return $found;
    }

    /**
     * @param DOMXPath $xpath
     * @param string $query
     *
     * @return array<int, DOMNode>
     */
    private static function nodes(DOMXPath $xpath, string $query): array
    {
        $list = $xpath->query($query);
        if ($list === false) {
            return [];
        }

        $nodes = [];
        foreach ($list as $node) {
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * @param DOMXPath $xpath
     * @param string $query
     *
     * @return string|null the text of the first match
     */
    private static function first(DOMXPath $xpath, string $query): ?string
    {
        $nodes = self::nodes($xpath, $query);

        return $nodes === [] ? null : $nodes[0]->textContent;
    }

    /**
     * @param DOMXPath $xpath
     * @param string $query
     * @param string $name
     *
     * @return string|null the attribute of the first element matched
     */
    private static function attribute(DOMXPath $xpath, string $query, string $name): ?string
    {
        $elements = self::elements($xpath, $query);

        return $elements === [] ? null : $elements[0]->getAttribute($name);
    }

    /**
     * The content of a named meta tag, whatever the case of its name.
     *
     * @param DOMXPath $xpath
     * @param string $name lower case
     *
     * @return string|null
     */
    private static function meta(DOMXPath $xpath, string $name): ?string
    {
        $content = self::attribute(
            $xpath,
            '//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="' . $name . '"]',
            'content'
        );

        return $content === null ? null : self::clean($content, 1000);
    }

    /**
     * Whitespace collapsed and the length capped, so one runaway value cannot
     * take the whole answer.
     *
     * @param string $text
     * @param int $max characters
     *
     * @return string
     */
    private static function clean(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return Tools::strlen($text) > $max ? Tools::substr($text, 0, $max) . '...' : $text;
    }
}
