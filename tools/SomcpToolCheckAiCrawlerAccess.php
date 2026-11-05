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
 * somcp_check_ai_crawler_access - whether the main AI crawlers may read the
 * shop: for each crawler, whether the robots.txt lets it fetch the home, a
 * product and a category page, and whether those pages tell it not to index.
 *
 * The robots.txt is read and matched with the parser of somcp_get_robots_txt,
 * never a second copy, so the two tools can never disagree on what a rule
 * blocks: the same samples(), parse(), rule() and matches(). The pages are
 * fetched and their robots meta read with the code of somcp_audit_page
 * (follow(), load(), meta() and the X-Robots-Tag header), for the same
 * reason. Nothing is written: the robots.txt is changed by
 * somcp_generate_robots_txt.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolCheckAiCrawlerAccess
{
    /** @var int Seconds allowed to fetch one page, redirects included, as somcp_audit_page. */
    private const TIMEOUT = 10;

    /**
     * The crawlers checked, in the order they are reported, each with the user
     * agent token it is known by and one line on what it does. These are the
     * tokens the crawlers document; a robots.txt names them the same way.
     *
     * @var array<int, array{name: string, purpose: string}>
     */
    private const CRAWLERS = [
        ['name' => 'GPTBot', 'purpose' => 'OpenAI crawler that gathers pages to train its models'],
        ['name' => 'OAI-SearchBot', 'purpose' => 'OpenAI crawler that builds the results shown in ChatGPT search'],
        ['name' => 'ChatGPT-User', 'purpose' => 'fetches a page when a ChatGPT user asks about it'],
        ['name' => 'ClaudeBot', 'purpose' => 'Anthropic crawler that gathers pages to train its models'],
        ['name' => 'Claude-SearchBot', 'purpose' => 'Anthropic crawler that builds the results Claude shows in search'],
        ['name' => 'PerplexityBot', 'purpose' => 'Perplexity crawler that builds the answers of its answer engine'],
        ['name' => 'Google-Extended', 'purpose' => 'token that controls use of the shop for Google Gemini and Vertex AI training'],
        ['name' => 'Applebot-Extended', 'purpose' => 'token that controls use of the shop for Apple Intelligence training'],
        ['name' => 'CCBot', 'purpose' => 'Common Crawl crawler, whose open archive trains many models'],
        ['name' => 'Bingbot', 'purpose' => 'Microsoft Bing crawler, which also feeds Copilot answers'],
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_check_ai_crawler_access',
            Somcp::t('Check whether AI crawlers can read the shop'),
            'Says whether the main AI crawlers (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, Bingbot and '
                . 'more) may read the shop: for each, whether its robots.txt lets it fetch the home, a product and '
                . 'a category page, with the rule deciding it, and whether those pages tell it not to index them '
                . '(robots meta or header). Reads the file and the pages the way somcp_get_robots_txt and '
                . 'somcp_audit_page do. Read only; reporting only, opening the shop to a crawler stays the '
                . 'merchant\'s choice. Use somcp_get_robots_txt for the whole file, somcp_generate_robots_txt to '
                . 'write it, somcp_audit_page for a page\'s SEO.',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_READ],
            [self::class, 'handle'],
            // The robots.txt is generated from Shop Parameters > Traffic & SEO,
            // and reading what it allows takes the view right there, as
            // somcp_get_robots_txt does.
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
        // Every mistake found before reading anything is reported in one
        // answer, so the call is corrected in one go (the shape of #169).
        $problems = [];
        foreach (array_keys($arguments) as $field) {
            $problems[] = '- ' . $field . ': not a field of this tool';
        }
        if ($problems !== []) {
            return SomcpTool::text(
                "Nothing was read. Correct these and call again:\n" . implode("\n", array_unique($problems)),
                true
            );
        }

        $shop = Somcp::currentShop();
        $idShop = (int) $shop->id;

        $file = _PS_ROOT_DIR_ . '/robots.txt';
        $present = is_file($file) && is_readable($file);
        $groups = [];
        if ($present) {
            $groups = SomcpToolGetRobotsTxt::parse(self::content($file))['groups'];
        }

        $warnings = [];
        // Search engines only ask for /robots.txt at the root of a host: a
        // shop in a folder serves this file where nobody reads it.
        if ((string) $shop->physical_uri !== '/') {
            $warnings[] = [
                'code' => 'not_at_domain_root',
                'why' => 'The shop is installed in the folder ' . (string) $shop->physical_uri . ', so its robots.txt '
                    . 'is served there and not at the root of the domain, where crawlers look for it. The file at '
                    . 'the root of the domain is the one that applies.',
            ];
        }
        if (!$present) {
            $warnings[] = [
                'code' => 'no_robots_txt',
                'why' => 'No robots.txt at the root of the shop: no rule blocks any crawler, so every page below is '
                    . 'allowed for all of them.',
            ];
        }

        $hosts = SomcpToolAuditPage::hosts($idShop);
        $pages = self::pages($idShop, $hosts);

        $crawlers = [];
        foreach (self::CRAWLERS as $crawler) {
            $group = self::applicableGroup($groups, $crawler['name']);
            $perPage = [];
            $blockedTypes = [];
            $fetchedCount = 0;
            foreach ($pages as $page) {
                $rule = $group === null ? null : SomcpToolGetRobotsTxt::rule($group, $page['path']);
                $allowed = $rule === null || strpos($rule, 'Allow:') === 0;
                $noindex = $page['fetched']
                    && self::noindexFor($page['meta_robots'], $page['x_robots_tag'], $crawler['name']);
                if ($page['fetched']) {
                    ++$fetchedCount;
                }
                if (!$allowed) {
                    $blockedTypes[$page['type']] = true;
                }
                $perPage[] = [
                    'type' => $page['type'],
                    'language' => $page['language'],
                    'allowed' => $allowed,
                    'rule' => $rule,
                    'noindex' => $noindex,
                ];
            }

            // A crawler refused on every page of the shop, and then the
            // narrower refusals, so the most serious is said once.
            $blockedEverywhere = $perPage !== [] && !self::anyAllowed($perPage);
            if ($blockedEverywhere) {
                $warnings[] = [
                    'code' => 'blocked_everywhere',
                    'why' => $crawler['name'] . ' is refused on every page checked.',
                    'name' => $crawler['name'],
                ];
            } else {
                if (isset($blockedTypes['product'])) {
                    $warnings[] = [
                        'code' => 'blocks_products',
                        'why' => $crawler['name'] . ' is refused on a product page of the shop.',
                        'name' => $crawler['name'],
                    ];
                }
                if (isset($blockedTypes['category'])) {
                    $warnings[] = [
                        'code' => 'blocks_categories',
                        'why' => $crawler['name'] . ' is refused on a category page of the shop.',
                        'name' => $crawler['name'],
                    ];
                }
            }

            $crawlers[] = [
                'name' => $crawler['name'],
                'purpose' => $crawler['purpose'],
                'group' => $group === null ? null : $group['user_agents'],
                'pages' => $perPage,
            ];
        }

        // A page that tells every crawler not to index it is worth one warning
        // for the page, over and above each crawler's own noindex flag.
        foreach ($pages as $page) {
            if ($page['fetched'] && self::noindexForAll($page['meta_robots'], $page['x_robots_tag'])) {
                $warnings[] = [
                    'code' => 'noindex_page',
                    'why' => 'This page tells crawlers not to index it, so even a crawler allowed to fetch it keeps '
                        . 'it out of its index.',
                    'url' => $page['url'],
                    'language' => $page['language'],
                ];
            }
        }

        $reported = [];
        foreach ($pages as $page) {
            $entry = [
                'type' => $page['type'],
                'url' => $page['url'],
                'language' => $page['language'],
                'meta_robots' => $page['meta_robots'],
                'x_robots_tag' => $page['x_robots_tag'],
                'fetched' => $page['fetched'],
            ];
            if (!$page['fetched']) {
                $entry['error'] = $page['error'];
            }
            $reported[] = $entry;
        }

        return SomcpTool::json([
            'robots_txt_present' => $present,
            'pages' => $reported,
            'crawlers' => $crawlers,
            'warnings' => $warnings,
        ]);
    }

    /**
     * The part of the robots.txt crawlers read, the byte order mark dropped,
     * read the same way somcp_get_robots_txt reads it.
     *
     * @param string $file
     *
     * @return string
     */
    private static function content(string $file): string
    {
        // 500 KiB: what Google reads of a robots.txt, as somcp_get_robots_txt.
        $content = (string) file_get_contents($file, false, null, 0, 512000);
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
            $content = substr($content, 3);
        }

        return $content;
    }

    /**
     * The home, product and category pages checked, in each active language:
     * the product and category pages of somcp_get_robots_txt::samples(), plus
     * the home page of each language, each fetched once and its robots meta
     * and X-Robots-Tag header read with the code of somcp_audit_page.
     *
     * @param int $idShop
     * @param array<int, string> $hosts the shop's active domains
     *
     * @return array<int, array{type: string, url: string, path: string, language: string, fetched: bool, error: string|null, meta_robots: string|null, x_robots_tag: string|null}>
     */
    private static function pages(int $idShop, array $hosts): array
    {
        $targets = self::homePages($idShop);
        foreach (SomcpToolGetRobotsTxt::samples($idShop) as $sample) {
            $targets[] = $sample;
        }

        $pages = [];
        foreach ($targets as $target) {
            $read = self::readPage((string) $target['url'], $hosts);
            $pages[] = [
                'type' => $target['type'],
                'url' => $target['url'],
                'path' => $target['path'],
                'language' => $target['language'],
                'fetched' => $read['fetched'],
                'error' => $read['error'],
                'meta_robots' => $read['meta_robots'],
                'x_robots_tag' => $read['x_robots_tag'],
            ];
        }

        return $pages;
    }

    /**
     * The home page of the shop in each of its active languages, in the shape
     * of somcp_get_robots_txt::samples() so the two sit in one list.
     *
     * @param int $idShop
     *
     * @return array<int, array{type: string, url: string, path: string, language: string}>
     */
    private static function homePages(int $idShop): array
    {
        $link = Somcp::legacyContext()->link ?? new Link();
        $pages = [];
        foreach (Language::getLanguages(true, $idShop) as $language) {
            $idLang = (int) $language['id_lang'];
            $url = (string) $link->getPageLink('index', null, $idLang, null, false, $idShop);
            $path = (string) parse_url($url, PHP_URL_PATH);
            $query = parse_url($url, PHP_URL_QUERY);
            $pages[] = [
                'type' => 'home',
                'url' => $url,
                'path' => ($path === '' ? '/' : $path) . (is_string($query) && $query !== '' ? '?' . $query : ''),
                'language' => (string) $language['iso_code'],
            ];
        }

        return $pages;
    }

    /**
     * Fetches one page of the shop and reads its robots meta tag and
     * X-Robots-Tag header with the code of somcp_audit_page, so the two tools
     * read a page the same way.
     *
     * @param string $url an address of the shop
     * @param array<int, string> $hosts the shop's active domains
     *
     * @return array{fetched: bool, error: string|null, meta_robots: string|null, x_robots_tag: string|null}
     */
    private static function readPage(string $url, array $hosts): array
    {
        $empty = ['fetched' => false, 'error' => null, 'meta_robots' => null, 'x_robots_tag' => null];
        if ($hosts === []) {
            $empty['error'] = 'This shop has no active domain to fetch its pages from.';

            return $empty;
        }
        if (!in_array(SomcpHttp::authority($url), $hosts, true)) {
            $empty['error'] = 'This page is not on one of the shop\'s domains.';

            return $empty;
        }

        $fetched = SomcpToolAuditPage::follow($url, $hosts, self::TIMEOUT);
        $response = $fetched['response'];
        if ($response === null || $response['status'] === 0) {
            $empty['error'] = 'The page did not answer within ' . self::TIMEOUT . ' seconds.';

            return $empty;
        }
        if ($fetched['outside'] !== null) {
            $empty['error'] = 'The page redirects to an address outside the shop, which was not fetched.';

            return $empty;
        }
        if ($response['status'] !== 200) {
            $empty['error'] = 'The page answers ' . $response['status'] . ' instead of 200.';

            return $empty;
        }

        $xRobots = (string) ($response['headers']['x-robots-tag'] ?? '');
        $metaRobots = null;
        $type = strtolower((string) ($response['headers']['content-type'] ?? ''));
        if ($type === '' || strpos($type, 'html') !== false) {
            $dom = SomcpToolAuditPage::load($response['body']);
            if ($dom !== null) {
                $metaRobots = SomcpToolAuditPage::meta(new DOMXPath($dom), 'robots');
            }
        }

        return [
            'fetched' => true,
            'error' => null,
            'meta_robots' => $metaRobots,
            'x_robots_tag' => $xRobots === '' ? null : $xRobots,
        ];
    }

    /**
     * The robots.txt group that applies to a crawler: the group naming its
     * token (case-insensitive), else the group for *, else none, as RFC 9309.
     *
     * @param array<int, array{user_agents: array<int, string>, allow: array<int, string>, disallow: array<int, string>}> $groups
     * @param string $crawler the crawler's user agent token
     *
     * @return array{user_agents: array<int, string>, allow: array<int, string>, disallow: array<int, string>}|null
     */
    private static function applicableGroup(array $groups, string $crawler): ?array
    {
        $star = null;
        foreach ($groups as $group) {
            foreach ($group['user_agents'] as $agent) {
                if (strcasecmp(trim($agent), $crawler) === 0) {
                    // Its own group always wins over the catch-all one.
                    return $group;
                }
                if (trim($agent) === '*' && $star === null) {
                    $star = $group;
                }
            }
        }

        return $star;
    }

    /**
     * Whether a crawler is allowed on at least one of the pages checked.
     *
     * @param array<int, array{allowed: bool}> $perPage
     *
     * @return bool
     */
    private static function anyAllowed(array $perPage): bool
    {
        foreach ($perPage as $page) {
            if ($page['allowed']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the robots meta tag or the X-Robots-Tag header of a page tells
     * this crawler not to index it: a noindex or none directive with no crawler
     * named (every robot), or one named for this crawler (as in GPTBot:
     * noindex).
     *
     * @param string|null $metaRobots
     * @param string|null $xRobots
     * @param string $crawler
     *
     * @return bool
     */
    private static function noindexFor(?string $metaRobots, ?string $xRobots, string $crawler): bool
    {
        foreach (self::directives($metaRobots . ',' . $xRobots) as $directive) {
            if (($directive['agent'] === null || strcasecmp($directive['agent'], $crawler) === 0)
                && in_array($directive['value'], ['noindex', 'none'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a page tells every crawler not to index it: a noindex or none
     * directive naming no crawler.
     *
     * @param string|null $metaRobots
     * @param string|null $xRobots
     *
     * @return bool
     */
    private static function noindexForAll(?string $metaRobots, ?string $xRobots): bool
    {
        foreach (self::directives($metaRobots . ',' . $xRobots) as $directive) {
            if ($directive['agent'] === null && in_array($directive['value'], ['noindex', 'none'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The robots directives of a meta robots value or an X-Robots-Tag header,
     * each with the crawler it names (null for every robot) and its lower case
     * value: "noindex, nofollow" gives two directives for every robot,
     * "GPTBot: noindex" one for GPTBot.
     *
     * @param string $value the two joined by a comma
     *
     * @return array<int, array{agent: string|null, value: string}>
     */
    private static function directives(string $value): array
    {
        $directives = [];
        foreach (explode(',', $value) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }
            $agent = null;
            $colon = strpos($piece, ':');
            if ($colon !== false) {
                $agent = trim(substr($piece, 0, $colon));
                $piece = trim(substr($piece, $colon + 1));
            }
            if ($piece === '') {
                continue;
            }
            $directives[] = ['agent' => $agent === '' ? null : $agent, 'value' => strtolower($piece)];
        }

        return $directives;
    }
}
