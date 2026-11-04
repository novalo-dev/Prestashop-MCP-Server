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
 * somcp_audit_sitemap - the XML sitemaps the Google sitemap module (gsitemap)
 * generated for the shop, checked against the active catalogue, robots.txt
 * and a sample of their addresses fetched as a visitor.
 *
 * The files are read from disk, where the module writes them at the root of
 * the PrestaShop installation, never over HTTP: what search engines are given
 * is the file itself, and reading it locally costs the shop no request.
 *
 * Product addresses are built with Link::getProductLink() in each language,
 * the call the module makes when it writes them, so a friendly URL and its
 * language prefix are compared as written rather than guessed.
 *
 * Only the sample is fetched, through the code somcp_audit_page shares with
 * somcp_check_links: only the shop's own domains are requested.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolAuditSitemap
{
    /** Addresses fetched when sample is left out. */
    private const DEFAULT_SAMPLE = 20;

    /** Addresses fetched at most, so one call stays a bounded load on the shop. */
    private const MAX_SAMPLE = 100;

    /** The sitemap protocol's limit per file: anything larger is not read. */
    private const MAX_FILE_BYTES = 52428800;

    /** Seconds for all the sample requests together. */
    private const TIME_LIMIT = 60;

    /** Products named in missing_products and inactive_listed at most. */
    private const MAX_LISTED = 20;

    /** The namespace of the <loc> of a page, as opposed to the one of an image. */
    private const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** How old a generation may be for each frequency of the module's screen, in seconds. */
    private const FREQUENCIES = [
        'hourly' => 3600,
        'daily' => 86400,
        'weekly' => 604800,
        'monthly' => 2678400,
        'yearly' => 31622400,
    ];

    /** The arguments the schema declares: anything else is a mistake to report, not to ignore. */
    private const FIELDS = ['sample', 'language'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_audit_sitemap',
            Somcp::t('Audit the shop\'s sitemaps'),
            'Reads the XML sitemaps the Google sitemap module generated for this shop: files and address counts, '
                . 'frequency and excluded pages of its screen, whether robots.txt announces the index, active '
                . 'products missing, disabled products still listed, and a sample of addresses fetched as a '
                . 'visitor. Read only; never generates the sitemap. For robots.txt itself, use '
                . 'somcp_get_robots_txt; for the links of the pages, somcp_check_links.',
            [
                'type' => 'object',
                'properties' => [
                    'sample' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'maximum' => self::MAX_SAMPLE,
                        'description' => 'Listed addresses to fetch and check, ' . self::DEFAULT_SAMPLE
                            . ' by default, 0 to ' . self::MAX_SAMPLE . '.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of a shop language: only its sitemap files. Every language '
                            . 'when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_READ],
            [self::class, 'handle'],
            // The same right as the other SEO reads: what search engines are
            // given is managed from Shop Parameters > Traffic & SEO.
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

        // Every mistake is reported in one answer, so the call can be
        // corrected in one go.
        $problems = [];
        foreach (array_keys($arguments) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                $problems[] = '- ' . $field . ': not a field of this tool';
            }
        }

        $sample = $arguments['sample'] ?? self::DEFAULT_SAMPLE;
        if (!is_int($sample) || $sample < 0 || $sample > self::MAX_SAMPLE) {
            $problems[] = '- sample: a whole number from 0 to ' . self::MAX_SAMPLE;
            $sample = self::DEFAULT_SAMPLE;
        }

        $languages = SomcpTool::activeLanguages($idShop);
        if (array_key_exists('language', $arguments) && $arguments['language'] !== null && $arguments['language'] !== '') {
            $language = SomcpTool::readLanguage($arguments, $idShop);
            if ($language === null) {
                $problems[] = '- language: not a language of this shop, which has ' . implode(', ', $languages);
            } else {
                $languages = [$language[0] => $language[1]];
            }
        }

        if ($problems !== []) {
            return SomcpTool::text(
                "Nothing was read. Correct these and call again:\n" . implode("\n", array_unique($problems)),
                true
            );
        }

        return SomcpTool::json(self::audit($idShop, $languages, $sample));
    }

    /**
     * @param int $idShop
     * @param array<int, string> $languages the languages whose files are read, keyed by id_lang
     * @param int $sample
     *
     * @return array<string, mixed>
     */
    private static function audit(int $idShop, array $languages, int $sample): array
    {
        $active = Module::isInstalled('gsitemap') && Module::isEnabled('gsitemap');
        $warnings = [];
        if (!$active) {
            $warnings[] = [
                'code' => 'module_inactive',
                'why' => 'The Google sitemap module is not installed and enabled on this shop: no sitemap is '
                    . 'generated, and search engines only find the pages they reach by links.',
            ];
        }

        $isos = array_map('Tools::strtolower', array_values($languages));
        $sitemaps = [];
        $listed = [];
        foreach (self::files($idShop) as $file) {
            $iso = preg_match('/^\d+_([a-z]{2,3}(?:-[a-z]+)?)_\d+_sitemap\.xml$/i', $file, $match)
                ? Tools::strtolower($match[1]) : null;
            if ($iso !== null && !in_array($iso, $isos, true)) {
                continue;
            }
            $path = _PS_ROOT_DIR_ . '/' . $file;
            $exists = is_file($path) && is_readable($path);
            $urls = $exists ? self::locations($path) : null;
            $mtime = $exists ? filemtime($path) : false;
            $sitemaps[] = [
                'file' => $file,
                'url' => self::address($file, $idShop),
                'language' => $iso,
                'url_count' => $urls === null ? null : count($urls),
                'last_modified' => $mtime === false ? null : date('c', $mtime),
                'exists' => $exists,
            ];
            if (!$exists) {
                $warnings[] = [
                    'code' => 'file_missing',
                    'file' => $file,
                    'why' => 'The module lists this file but it is not on the server: search engines get an error '
                        . 'for it. Generate the sitemap again.',
                ];
            }
            foreach ($urls ?? [] as $url) {
                $listed[$url] = true;
            }
        }

        $indexFile = $idShop . '_index_sitemap.xml';
        $robots = SomcpToolGetRobotsTxt::sitemaps();
        $announced = false;
        foreach ($robots as $line) {
            if (basename((string) parse_url($line, PHP_URL_PATH)) === $indexFile) {
                $announced = true;
            }
        }
        $indexExists = is_file(_PS_ROOT_DIR_ . '/' . $indexFile);
        if ($indexExists && !$announced) {
            $warnings[] = [
                'code' => 'index_not_in_robots_txt',
                'why' => 'robots.txt has no Sitemap line for the index: search engines are not told where it is. '
                    . 'Generate robots.txt again, after the sitemap.',
            ];
        }

        $hasFile = false;
        foreach ($sitemaps as $sitemap) {
            $hasFile = $hasFile || $sitemap['exists'];
        }
        if (!$hasFile) {
            $warnings[] = [
                'code' => 'no_sitemap',
                'why' => 'No sitemap file has been generated for this shop: search engines are not given the list '
                    . 'of its pages.',
            ];
        }

        $lastExport = (string) Configuration::get('GSITEMAP_LAST_EXPORT', null, null, $idShop);
        $lastTime = $lastExport === '' ? false : strtotime($lastExport);
        $frequency = Configuration::get('GSITEMAP_FREQUENCY', null, null, $idShop);
        $frequency = is_string($frequency) && $frequency !== '' ? $frequency : null;
        if ($hasFile && $lastTime !== false && $frequency !== null && isset(self::FREQUENCIES[$frequency])
            && time() - $lastTime > self::FREQUENCIES[$frequency]) {
            $warnings[] = [
                'code' => 'stale',
                'why' => 'The sitemap was last generated on ' . date('Y-m-d', $lastTime) . ', longer ago than the '
                    . 'frequency set on the module\'s screen (' . $frequency . '): products added since are missing.',
            ];
        }

        $products = $listed === [] ? ['missing' => ['count' => 0, 'products' => []], 'inactive' => []]
            : self::products($idShop, $languages, $listed);

        return [
            'module_active' => $active,
            'sitemaps' => $sitemaps,
            'index' => [
                'file' => $indexFile,
                'url' => self::address($indexFile, $idShop),
                'exists' => $indexExists,
                'announced' => $announced,
            ],
            'last_generated' => $lastTime === false ? null : date('c', $lastTime),
            'frequency' => $frequency,
            'excluded_pages' => self::excluded($idShop),
            'robots_txt_sitemaps' => $robots,
            'missing_products' => $products['missing'],
            'inactive_listed' => $products['inactive'],
            'sample_errors' => $sample > 0 ? self::check(array_keys($listed), $sample, $idShop) : [],
            'warnings' => $warnings,
        ];
    }

    /**
     * The sitemap file names the module recorded for the shop. A name that is
     * not a plain file name is left out, so nothing outside the root is read.
     *
     * @param int $idShop
     *
     * @return array<int, string>
     */
    private static function files(int $idShop): array
    {
        $db = Db::getInstance();
        $table = _DB_PREFIX_ . 'gsitemap_sitemap';
        // The table only exists while the module is installed.
        if (!$db->executeS('SHOW TABLES LIKE \'' . pSQL($table) . '\'')) {
            return [];
        }
        $files = [];
        foreach ($db->executeS('SELECT `link` FROM `' . bqSQL($table) . '` WHERE `id_shop` = ' . (int) $idShop
            . ' ORDER BY `link` ASC') ?: [] as $row) {
            $file = (string) $row['link'];
            if (preg_match('/^[A-Za-z0-9_.-]+\.xml$/', $file) && strpos($file, '..') === false
                && !in_array($file, $files, true)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * The page addresses a sitemap file lists, image addresses left out.
     * Null when the file is too large or not valid XML.
     *
     * @param string $path
     *
     * @return array<int, string>|null
     */
    private static function locations(string $path): ?array
    {
        if ((int) filesize($path) > self::MAX_FILE_BYTES) {
            return null;
        }
        $reader = new XMLReader();
        // No network access: a sitemap never needs anything beyond itself.
        if (!$reader->open($path, null, LIBXML_NONET)) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $urls = [];
        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'loc'
                && $reader->namespaceURI === self::SITEMAP_NS) {
                $url = trim($reader->readString());
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }
        $reader->close();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $urls;
    }

    /**
     * The address of a file of the installation root on the shop's main domain.
     *
     * @param string $file
     * @param int $idShop
     *
     * @return string|null
     */
    private static function address(string $file, int $idShop): ?string
    {
        $shop = new Shop($idShop);

        return SomcpToolAuditPage::absolute(rtrim((string) $shop->physical_uri, '/') . '/' . $file, $idShop);
    }

    /**
     * The pages unticked on the module's screen, by their page name.
     *
     * @param int $idShop
     *
     * @return array<int, string>
     */
    private static function excluded(int $idShop): array
    {
        $ids = array_filter(array_map('intval', explode(',', (string) Configuration::get('GSITEMAP_DISABLE_LINKS', null, null, $idShop))));
        if ($ids === []) {
            return [];
        }
        $pages = [];
        foreach (Db::getInstance()->executeS('SELECT `page` FROM `' . _DB_PREFIX_ . 'meta` WHERE `id_meta` IN ('
            . implode(',', $ids) . ') ORDER BY `page` ASC') ?: [] as $row) {
            $pages[] = (string) $row['page'];
        }

        return $pages;
    }

    /**
     * The address compared: path and query, decoded, so the scheme, the host
     * and the way accents are written do not hide a match.
     *
     * @param string $url
     *
     * @return string
     */
    private static function key(string $url): string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $query = parse_url($url, PHP_URL_QUERY);

        return ($path === '' ? '/' : $path) . (is_string($query) && $query !== '' ? '?' . $query : '');
    }

    /**
     * Active products absent from every sitemap, and disabled ones still listed.
     *
     * @param int $idShop
     * @param array<int, string> $languages
     * @param array<string, bool> $listed
     *
     * @return array{missing: array{count: int, products: array<int, array{id_product: int, name: string}>}, inactive: array<int, array{url: string, id_product: int}>}
     */
    private static function products(int $idShop, array $languages, array $listed): array
    {
        $keys = [];
        foreach (array_keys($listed) as $url) {
            $keys[self::key($url)] = $url;
        }

        $idDefault = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $rows = Db::getInstance()->executeS(
            'SELECT ps.`id_product`, ps.`active`, ps.`visibility`, pl.`name` FROM `' . _DB_PREFIX_ . 'product_shop` ps'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = ps.`id_product`'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = ps.`id_product`'
            . ' AND pl.`id_shop` = ps.`id_shop` AND pl.`id_lang` = ' . (int) $idDefault
            . ' WHERE ps.`id_shop` = ' . (int) $idShop . ' AND p.`state` = 1'
            . ' ORDER BY ps.`id_product` ASC'
        ) ?: [];

        $link = Somcp::legacyContext()->link ?? new Link();
        $count = 0;
        $missing = [];
        $inactive = [];
        foreach ($rows as $row) {
            $idProduct = (int) $row['id_product'];
            $isActive = (int) $row['active'] === 1;
            // A product kept out of the catalogue is not expected in the sitemap.
            if ($isActive && (string) $row['visibility'] === 'none') {
                continue;
            }
            $found = null;
            foreach (array_keys($languages) as $idLang) {
                $key = self::key((string) $link->getProductLink($idProduct, null, null, null, $idLang, $idShop));
                if (isset($keys[$key])) {
                    $found = $keys[$key];
                    break;
                }
            }
            if ($isActive && $found === null) {
                ++$count;
                if (count($missing) < self::MAX_LISTED) {
                    $missing[] = ['id_product' => $idProduct, 'name' => (string) $row['name']];
                }
            } elseif (!$isActive && $found !== null && count($inactive) < self::MAX_LISTED) {
                $inactive[] = ['url' => $found, 'id_product' => $idProduct];
            }
        }

        return ['missing' => ['count' => $count, 'products' => $missing], 'inactive' => $inactive];
    }

    /**
     * The sampled addresses answering other than 200. The sample is spread
     * over the whole list, so it does not only check the home and the
     * categories the module writes first.
     *
     * @param array<int, string> $urls
     * @param int $sample
     * @param int $idShop
     *
     * @return array<int, array{url: string, status: int, final_url: string}>
     */
    private static function check(array $urls, int $sample, int $idShop): array
    {
        $total = count($urls);
        if ($total === 0) {
            return [];
        }
        $picked = [];
        $size = min($sample, $total);
        for ($i = 0; $i < $size; ++$i) {
            $picked[] = $urls[(int) floor($i * $total / $size)];
        }

        $hosts = SomcpToolAuditPage::hosts($idShop);
        $started = microtime(true);
        $errors = [];
        foreach ($picked as $url) {
            // An address outside the shop's domains is never requested.
            if (!in_array(SomcpHttp::authority($url), $hosts, true)) {
                $errors[] = ['url' => $url, 'status' => 0, 'final_url' => $url];
                continue;
            }
            $left = (int) floor(self::TIME_LIMIT - (microtime(true) - $started));
            if ($left < 1) {
                break;
            }
            $fetched = SomcpToolAuditPage::follow($url, $hosts, min(SomcpToolAuditPage::TIMEOUT, $left));
            $status = $fetched['response'] === null ? 0 : (int) $fetched['response']['status'];
            if ($status !== 200) {
                $errors[] = ['url' => $url, 'status' => $status, 'final_url' => $fetched['final_url']];
            }
        }

        return $errors;
    }
}
