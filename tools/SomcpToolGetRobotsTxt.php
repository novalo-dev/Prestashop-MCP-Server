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
 * somcp_get_robots_txt - the robots.txt of the shop, its rules grouped by
 * user agent, and what they block that a shop should not block.
 *
 * The file read is the one Tools::generateRobotsFile() writes, at the root of
 * the PrestaShop installation: there is one for every shop of a multistore
 * installation, holding the rules of each shop's folder.
 *
 * "Blocks product pages" is not guessed from the look of a rule: the address
 * of one real product and one real category of the shop is built in each of
 * its languages and run through each group's rules the way search engines
 * match them (RFC 9309: longest pattern wins, Allow wins a tie, * and $).
 *
 * Nothing is ever written: regenerating the file is somcp_generate_robots_txt.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetRobotsTxt
{
    /**
     * Google reads the first 500 KiB of a robots.txt and ignores the rest, so
     * reading further would report rules no search engine applies.
     */
    private const MAX_BYTES = 512000;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_robots_txt',
            Somcp::t('Read the robots.txt file'),
            'Reads the shop\'s robots.txt, the file at the root of the PrestaShop installation that tells search '
                . 'engines which addresses not to crawl, and says what it blocks. Returns present, content (the file '
                . 'as it is), groups (one per group of the file: user_agents, allow and disallow rules in their '
                . 'order), sitemaps (the Sitemap lines), size in bytes, last_modified, and warnings: disallow_all '
                . '(a group blocking the whole site), blocks_products and blocks_categories (a rule blocking the '
                . 'address of a real product or category of the shop, with the rule, the address and its language), '
                . 'no_sitemap, not_at_domain_root (the shop is in a folder, so search engines never read this '
                . 'file), too_large, no_robots_txt. On a multistore installation the file is shared by every shop. '
                . 'Read only. Do not use it for AI crawlers specifically (somcp_check_ai_crawler_access), or to '
                . 'write the file again (somcp_generate_robots_txt).',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_READ],
            [self::class, 'handle'],
            // The file is generated from Shop Parameters > Traffic & SEO, and
            // reading it takes the view right there.
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
        $file = _PS_ROOT_DIR_ . '/robots.txt';
        $shop = Somcp::currentShop();

        $warnings = [];
        // Search engines only ever ask for /robots.txt at the root of a host:
        // a shop installed in a folder serves this file where nobody reads it.
        if ((string) $shop->physical_uri !== '/') {
            $warnings[] = [
                'code' => 'not_at_domain_root',
                'why' => 'The shop is installed in the folder ' . (string) $shop->physical_uri . ', so this file is '
                    . 'served there and not at the root of the domain, where search engines look for it. The '
                    . 'robots.txt at the root of the domain is the one that applies.',
            ];
        }

        if (!is_file($file) || !is_readable($file)) {
            $warnings[] = [
                'code' => 'no_robots_txt',
                'why' => 'No robots.txt at the root of the shop: search engines crawl the cart, account and search '
                    . 'pages as well.',
            ];

            return SomcpTool::json([
                'present' => false,
                'content' => null,
                'groups' => [],
                'sitemaps' => [],
                'size' => null,
                'last_modified' => null,
                'warnings' => $warnings,
            ]);
        }

        $size = (int) filesize($file);
        $mtime = filemtime($file);
        $content = (string) file_get_contents($file, false, null, 0, self::MAX_BYTES);
        if ($size > self::MAX_BYTES) {
            $warnings[] = [
                'code' => 'too_large',
                'why' => 'The file is over 500 KiB: search engines ignore everything after that, and so does this '
                    . 'reading.',
            ];
        }
        // A byte order mark left by an editor would glue itself to the first
        // field name and hide the first line.
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
            $content = substr($content, 3);
        }

        $parsed = self::parse($content);
        $warnings = array_merge($warnings, self::blocking($parsed['groups'], (int) $shop->id));
        if ($parsed['sitemaps'] === []) {
            $warnings[] = [
                'code' => 'no_sitemap',
                'why' => 'No Sitemap line: search engines are not told where the list of the shop\'s pages is. '
                    . 'PrestaShop adds it when the file is generated after the sitemap module built its index.',
            ];
        }

        return SomcpTool::json([
            'present' => true,
            'content' => $content,
            'groups' => $parsed['groups'],
            'sitemaps' => $parsed['sitemaps'],
            'size' => $size,
            'last_modified' => $mtime === false ? null : date('c', $mtime),
            'warnings' => $warnings,
        ]);
    }

    /**
     * The groups and the Sitemap lines of the file, as RFC 9309 reads them.
     *
     * @param string $content
     *
     * @return array{groups: array<int, array{user_agents: array<int, string>, allow: array<int, string>, disallow: array<int, string>}>, sitemaps: array<int, string>}
     */
    private static function parse(string $content): array
    {
        $groups = [];
        $sitemaps = [];
        $current = null;
        // A user-agent line right after another one joins the same group; one
        // after a rule starts a new group.
        $collectingAgents = false;

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $hash = strpos($line, '#');
            if ($hash !== false) {
                $line = substr($line, 0, $hash);
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $field = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            if ($field === 'user-agent') {
                if (!$collectingAgents || $current === null) {
                    if ($current !== null) {
                        $groups[] = $current;
                    }
                    $current = ['user_agents' => [], 'allow' => [], 'disallow' => []];
                }
                $current['user_agents'][] = $value;
                $collectingAgents = true;
                continue;
            }

            if ($field === 'sitemap') {
                // Sitemap lines belong to the file, not to the group they sit in.
                if ($value !== '') {
                    $sitemaps[] = $value;
                }
                continue;
            }

            if ($field === 'allow' || $field === 'disallow') {
                $collectingAgents = false;
                // A rule before any user-agent line belongs to no group, and
                // crawlers ignore it.
                if ($current !== null) {
                    $current[$field][] = $value;
                }
                continue;
            }

            // Crawl-delay, Host, Clean-param...: not rules of the standard,
            // but they do not end the list of user agents either.
        }
        if ($current !== null) {
            $groups[] = $current;
        }

        return ['groups' => $groups, 'sitemaps' => $sitemaps];
    }

    /**
     * The groups that block the whole site, or a real product or category page.
     *
     * @param array<int, array{user_agents: array<int, string>, allow: array<int, string>, disallow: array<int, string>}> $groups
     * @param int $idShop
     *
     * @return array<int, array<string, mixed>>
     */
    private static function blocking(array $groups, int $idShop): array
    {
        $samples = self::samples($idShop);

        $warnings = [];
        foreach ($groups as $group) {
            // Disallow: / and its wildcard spellings are the whole site; a
            // pattern such as /$ blocks the home page alone and is left to
            // the checks below.
            $root = self::rule($group, '/');
            if (in_array($root, ['Disallow: /', 'Disallow: /*', 'Disallow: *'], true)) {
                $warnings[] = [
                    'code' => 'disallow_all',
                    'why' => 'This group blocks every address of the site for the crawlers it names.',
                    'user_agents' => $group['user_agents'],
                    'rule' => $root,
                ];
                // Every page is blocked already: listing a product and a
                // category on top would say the same thing three times.
                continue;
            }

            foreach ($samples as $sample) {
                $rule = self::rule($group, $sample['path']);
                if ($rule === null || strpos($rule, 'Disallow:') !== 0) {
                    continue;
                }
                $warnings[] = [
                    'code' => $sample['type'] === 'product' ? 'blocks_products' : 'blocks_categories',
                    'why' => $sample['type'] === 'product'
                        ? 'A rule of this group blocks a product page of the shop.'
                        : 'A rule of this group blocks a category page of the shop.',
                    'user_agents' => $group['user_agents'],
                    'rule' => $rule,
                    'url' => $sample['url'],
                    'language' => $sample['language'],
                ];
            }
        }

        return $warnings;
    }

    /**
     * The rule of a group that decides for one path, null when none matches.
     *
     * @param array{user_agents: array<int, string>, allow: array<int, string>, disallow: array<int, string>} $group
     * @param string $path
     *
     * @return string|null
     */
    private static function rule(array $group, string $path): ?string
    {
        $best = null;
        $bestLength = -1;
        foreach (['disallow' => 'Disallow', 'allow' => 'Allow'] as $key => $label) {
            foreach ($group[$key] as $pattern) {
                // An empty Disallow allows everything: it is no rule at all.
                if ($pattern === '' || !self::matches($pattern, $path)) {
                    continue;
                }
                $length = strlen($pattern);
                // The longest pattern decides; on a tie Allow wins, which is
                // why the Allow rules are read second and also take a tie.
                if ($length > $bestLength || ($length === $bestLength && $key === 'allow')) {
                    $best = $label . ': ' . $pattern;
                    $bestLength = $length;
                }
            }
        }

        return $best;
    }

    /**
     * Whether a robots.txt path pattern matches a path, from its start.
     *
     * @param string $pattern
     * @param string $path
     *
     * @return bool
     */
    private static function matches(string $pattern, string $path): bool
    {
        $anchored = substr($pattern, -1) === '$';
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $regex = str_replace('\*', '.*', preg_quote($pattern, '#'));

        return (bool) preg_match('#^' . $regex . ($anchored ? '$' : '') . '#', $path);
    }

    /**
     * The address of one displayed product and one displayed category of the
     * shop, in each of its active languages.
     *
     * @param int $idShop
     *
     * @return array<int, array{type: string, url: string, path: string, language: string}>
     */
    private static function samples(int $idShop): array
    {
        $db = Db::getInstance();
        $idProduct = (int) $db->getValue(
            'SELECT ps.`id_product` FROM `' . _DB_PREFIX_ . 'product_shop` ps'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = ps.`id_product`'
            . ' WHERE ps.`id_shop` = ' . (int) $idShop . ' AND ps.`active` = 1'
            . " AND ps.`visibility` <> 'none' AND p.`state` = 1"
            . ' ORDER BY ps.`id_product` ASC'
        );
        // The root has no parent and a Home category is the front page: the
        // same categories somcp_audit_category_seo leaves out.
        $idCategory = (int) $db->getValue(
            'SELECT c.`id_category` FROM `' . _DB_PREFIX_ . 'category` c'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.`id_category` = c.`id_category`'
            . ' WHERE cs.`id_shop` = ' . (int) $idShop . ' AND c.`active` = 1'
            . ' AND c.`id_parent` > 0 AND c.`is_root_category` = 0'
            . ' ORDER BY c.`level_depth` ASC, c.`id_category` ASC'
        );

        $link = Somcp::legacyContext()->link ?? new Link();
        $samples = [];
        foreach (Language::getLanguages(true, $idShop) as $language) {
            $idLang = (int) $language['id_lang'];
            $urls = [];
            if ($idProduct > 0) {
                $urls['product'] = (string) $link->getProductLink($idProduct, null, null, null, $idLang, $idShop);
            }
            if ($idCategory > 0) {
                $urls['category'] = (string) $link->getCategoryLink($idCategory, null, $idLang, null, $idShop);
            }
            foreach ($urls as $type => $url) {
                $path = (string) parse_url($url, PHP_URL_PATH);
                $query = parse_url($url, PHP_URL_QUERY);
                $samples[] = [
                    'type' => $type,
                    'url' => $url,
                    'path' => ($path === '' ? '/' : $path) . (is_string($query) && $query !== '' ? '?' . $query : ''),
                    'language' => (string) $language['iso_code'],
                ];
            }
        }

        return $samples;
    }
}
