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
 * somcp_audit_product_seo - the products whose search engine fields have a
 * problem, with the problem named, per language.
 *
 * The counts cover the whole catalogue, not one page, so the catalogue is
 * read entirely - in batches of products, with plain SQL on product,
 * product_shop, product_lang and image_lang of this shop, rather than one
 * Product object each: a catalogue of 50,000 products has to stay fast. The
 * duplicate checks are left to the database, grouping by value, so its own
 * collation decides what "the same title" is, as it does for the shop's URLs.
 *
 * Nothing is ever written: corrections go through somcp_update_product.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolAuditProductSeo
{
    /** @var int Entries returned when the caller does not say. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Most a single call will return, whatever it asks for. */
    private const MAX_LIMIT = 100;

    /** @var int Products read per query while scanning the catalogue. */
    private const BATCH = 200;

    /** @var int Beyond this, search engines cut the title in their results. */
    private const TITLE_MAX = 60;

    /** @var int Under this, a description gives search engines little to show. */
    private const DESCRIPTION_MIN = 70;

    /** @var int Beyond this, search engines cut the description in their results. */
    private const DESCRIPTION_MAX = 160;

    /** @var int Under this many words, a product page has little text to rank on. */
    private const WORDS_MIN = 150;

    /** @var array<int, string> Every check, in the order they are reported. */
    private const CHECKS = [
        'meta_title_missing',
        'meta_title_too_long',
        'meta_title_duplicate',
        'meta_description_missing',
        'meta_description_short',
        'meta_description_long',
        'link_rewrite_duplicate',
        'description_short',
        'image_legend_missing',
        'no_image',
        'no_brand',
        'no_gtin',
        'inactive_without_redirect',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_audit_product_seo',
            Somcp::t('Audit the SEO of products'),
            'Lists the products of this shop whose search engine fields have a problem, one entry per product '
                . 'and language, each with its issues: the check that failed and the value at fault. Checks: '
                . 'meta_title_missing, meta_title_too_long (over 60 characters), meta_title_duplicate (another '
                . 'product has it in that language), meta_description_missing, meta_description_short (under 70 '
                . 'characters), meta_description_long (over 160), link_rewrite_duplicate (another product has '
                . 'the same friendly URL in that language), description_short (the description holds under 150 '
                . 'words, tags removed), image_legend_missing (an image without its alternative text in that '
                . 'language; value lists the image ids), no_image, no_brand, no_gtin (no EAN-13, UPC nor ISBN), '
                . 'and inactive_without_redirect (an inactive product whose page sends no redirection; checked '
                . 'only with active: false). The product-wide checks (no_image, no_brand, no_gtin, '
                . 'inactive_without_redirect) are repeated on each language entry. counts gives, per check, the '
                . 'entries raising it over the whole catalogue, and total_count the entries with at least one '
                . 'issue: read the next page with offset rather than concluding from one page. Only active '
                . 'products by default. Read only. Do not use it to read or change one product: '
                . 'somcp_get_product and somcp_update_product do that.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one language of the shop, for example en. Every language of '
                            . 'the shop when left out.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true (the default) for active products only - hidden products rank for '
                            . 'nothing - false to include inactive ones too.',
                    ],
                    'checks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'enum' => self::CHECKS,
                        ],
                        'description' => 'Only these checks; all of them when left out.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many entries to return, 20 by default and 100 at most.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'How many entries to skip, to read the next page.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_READ],
            [self::class, 'handle'],
            // The search engine fields of a product are on its page in the
            // catalogue, and reading them takes the view right there.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminProducts')
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

        $languages = self::languages($arguments, $idShop);
        if ($languages === null) {
            return SomcpTool::text(
                'This language is not one of the shop\'s. Give the ISO code of one of its languages, or leave '
                    . 'language out for all of them.',
                true
            );
        }

        $checks = self::checks($arguments);
        if ($checks === null) {
            return SomcpTool::text(
                'checks must be a list of these names: ' . implode(', ', self::CHECKS) . '.',
                true
            );
        }

        $activeOnly = !(array_key_exists('active', $arguments) && $arguments['active'] === false);
        // Only an inactive product can lack a redirection, so the check is
        // left out unless inactive products are audited too.
        if ($activeOnly) {
            $checks = array_values(array_diff($checks, ['inactive_without_redirect']));
        }

        $limit = isset($arguments['limit']) && is_numeric($arguments['limit']) ? (int) $arguments['limit'] : self::DEFAULT_LIMIT;
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = isset($arguments['offset']) && is_numeric($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;

        $idLangs = array_keys($languages);
        $duplicateTitles = in_array('meta_title_duplicate', $checks, true)
            ? self::duplicates('meta_title', $idShop, $idLangs, $activeOnly)
            : [];
        $duplicateUrls = in_array('link_rewrite_duplicate', $checks, true)
            ? self::duplicates('link_rewrite', $idShop, $idLangs, $activeOnly)
            : [];

        $counts = array_fill_keys($checks, 0);
        $entries = [];
        $total = 0;

        foreach (array_chunk(self::productIds($idShop, $activeOnly), self::BATCH) as $batch) {
            $images = self::images($batch, $idShop, $idLangs);

            foreach (self::rows($batch, $idShop, $idLangs) as $row) {
                $idProduct = (int) $row['id_product'];
                $idLang = (int) $row['id_lang'];
                $key = $idProduct . '-' . $idLang;

                $issues = self::issues($row, $checks, $images[$idProduct] ?? null, $idLang, isset($duplicateTitles[$key]), isset($duplicateUrls[$key]));
                if ($issues === []) {
                    continue;
                }

                foreach ($issues as $issue) {
                    ++$counts[$issue['check']];
                }
                // Only the page asked for is kept, so a large catalogue is
                // counted without being held in memory.
                if ($total >= $offset && count($entries) < $limit) {
                    $entries[] = [
                        'id_product' => $idProduct,
                        'name' => (string) $row['name'],
                        'language' => $languages[$idLang],
                        'issues' => $issues,
                    ];
                }
                ++$total;
            }
        }

        return SomcpTool::json([
            'products' => $entries,
            'returned' => count($entries),
            // So the assistant knows there is more instead of concluding from
            // one page that it has seen everything.
            'total_count' => $total,
            'offset' => $offset,
            // An object even when no check is left, so a client reads one shape.
            'counts' => $counts === [] ? new stdClass() : $counts,
            'languages' => array_values($languages),
            'active_only' => $activeOnly,
        ]);
    }

    /**
     * The issues of one product in one language.
     *
     * @param array<string, mixed> $row
     * @param array<int, string> $checks
     * @param array{count: int, missing: array<int, array<int, int>>}|null $images
     * @param int $idLang
     * @param bool $duplicateTitle
     * @param bool $duplicateUrl
     *
     * @return array<int, array<string, mixed>>
     */
    private static function issues(array $row, array $checks, ?array $images, int $idLang, bool $duplicateTitle, bool $duplicateUrl): array
    {
        $title = trim((string) $row['meta_title']);
        $description = trim((string) $row['meta_description']);
        $words = self::words((string) $row['description']);
        $imageCount = $images['count'] ?? 0;
        $withoutLegend = $images['missing'][$idLang] ?? [];
        $redirect = (string) $row['redirect_type'];

        $found = [
            'meta_title_missing' => $title === '' ? '' : null,
            'meta_title_too_long' => Tools::strlen($title) > self::TITLE_MAX ? $title : null,
            'meta_title_duplicate' => $duplicateTitle ? $title : null,
            'meta_description_missing' => $description === '' ? '' : null,
            'meta_description_short' => $description !== '' && Tools::strlen($description) < self::DESCRIPTION_MIN ? $description : null,
            'meta_description_long' => Tools::strlen($description) > self::DESCRIPTION_MAX ? $description : null,
            'link_rewrite_duplicate' => $duplicateUrl ? (string) $row['link_rewrite'] : null,
            // The value is the number of words, the thing to raise.
            'description_short' => $words < self::WORDS_MIN ? $words : null,
            'image_legend_missing' => $withoutLegend !== [] ? $withoutLegend : null,
            'no_image' => $imageCount === 0 ? '' : null,
            'no_brand' => (int) $row['id_manufacturer'] <= 0 ? '' : null,
            'no_gtin' => trim((string) $row['ean13']) === '' && trim((string) $row['upc']) === '' && trim((string) $row['isbn']) === '' ? '' : null,
            // A 301 or 302 sends the visitor and the search engine to another
            // page; 200-displayed (PrestaShop 9) keeps the page shown. Anything
            // else - 404, 410, the shop default - leaves a dead address.
            'inactive_without_redirect' => !(bool) $row['active'] && !preg_match('/^(301|302)-|^200-displayed$/', $redirect) ? $redirect : null,
        ];

        $issues = [];
        foreach ($checks as $check) {
            if ($found[$check] !== null) {
                $issues[] = ['check' => $check, 'value' => $found[$check]];
            }
        }

        return $issues;
    }

    /**
     * How many words a description holds, once its tags are removed.
     *
     * @param string $html
     *
     * @return int
     */
    private static function words(string $html): int
    {
        // A tag next to a word must not glue two words into one.
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $words = preg_split('/[\s\x{00A0}]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? count($words) : 0;
    }

    /**
     * The products of this shop to audit, in order.
     *
     * @param int $idShop
     * @param bool $activeOnly
     *
     * @return array<int, int>
     */
    private static function productIds(int $idShop, bool $activeOnly): array
    {
        $query = new DbQuery();
        $query->select('p.`id_product`');
        $query->from('product', 'p');
        // The shop comes from the token, and a product not associated with it
        // is not part of its catalogue.
        $query->innerJoin('product_shop', 'ps', 'ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop);
        // A product the back office is still creating has no page yet.
        $query->where('p.`state` = ' . (int) Product::STATE_SAVED);
        if ($activeOnly) {
            $query->where('ps.`active` = 1');
        }
        $query->orderBy('p.`id_product` ASC');

        return array_map('intval', array_column(Db::getInstance()->executeS($query) ?: [], 'id_product'));
    }

    /**
     * The fields of a batch of products, one row per product and language.
     *
     * @param array<int, int> $ids
     * @param int $idShop
     * @param array<int, int> $idLangs
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rows(array $ids, int $idShop, array $idLangs): array
    {
        $query = new DbQuery();
        $query->select('p.`id_product`, pl.`id_lang`, pl.`name`, pl.`meta_title`, pl.`meta_description`, pl.`link_rewrite`');
        $query->select('pl.`description`, ps.`active`, ps.`redirect_type`, p.`id_manufacturer`, p.`ean13`, p.`upc`, p.`isbn`');
        $query->from('product', 'p');
        $query->innerJoin('product_shop', 'ps', 'ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop);
        $query->innerJoin(
            'product_lang',
            'pl',
            'pl.`id_product` = p.`id_product` AND pl.`id_shop` = ' . $idShop
                . ' AND pl.`id_lang` IN (' . implode(',', array_map('intval', $idLangs)) . ')'
        );
        $query->where('p.`id_product` IN (' . implode(',', array_map('intval', $ids)) . ')');
        $query->orderBy('p.`id_product` ASC, pl.`id_lang` ASC');

        return Db::getInstance()->executeS($query) ?: [];
    }

    /**
     * The images of a batch of products in this shop, and per language the
     * ones without an alternative text.
     *
     * @param array<int, int> $ids
     * @param int $idShop
     * @param array<int, int> $idLangs
     *
     * @return array<int, array{count: int, missing: array<int, array<int, int>>}>
     */
    private static function images(array $ids, int $idShop, array $idLangs): array
    {
        $query = new DbQuery();
        $query->select('i.`id_product`, i.`id_image`, l.`id_lang`, il.`legend`');
        $query->from('image', 'i');
        // An image not associated with this shop is not shown there.
        $query->innerJoin('image_shop', 'ims', 'ims.`id_image` = i.`id_image` AND ims.`id_shop` = ' . $idShop);
        // Every language asked for, even one the image has no row in: a
        // missing row is a missing legend.
        $query->innerJoin('lang', 'l', 'l.`id_lang` IN (' . implode(',', array_map('intval', $idLangs)) . ')');
        $query->leftJoin('image_lang', 'il', 'il.`id_image` = i.`id_image` AND il.`id_lang` = l.`id_lang`');
        $query->where('i.`id_product` IN (' . implode(',', array_map('intval', $ids)) . ')');
        $query->orderBy('i.`id_product` ASC, i.`position` ASC');

        $images = [];
        $seen = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $idProduct = (int) $row['id_product'];
            $idImage = (int) $row['id_image'];
            if (!isset($images[$idProduct])) {
                $images[$idProduct] = ['count' => 0, 'missing' => []];
            }
            if (!isset($seen[$idImage])) {
                $seen[$idImage] = true;
                ++$images[$idProduct]['count'];
            }
            if (trim((string) $row['legend']) === '') {
                $images[$idProduct]['missing'][(int) $row['id_lang']][] = $idImage;
            }
        }

        return $images;
    }

    /**
     * The products whose value of a field another product of this shop has
     * too, in the same language, keyed "id_product-id_lang".
     *
     * @param string $field meta_title or link_rewrite, never from the caller
     * @param int $idShop
     * @param array<int, int> $idLangs
     * @param bool $activeOnly
     *
     * @return array<string, true>
     */
    private static function duplicates(string $field, int $idShop, array $idLangs, bool $activeOnly): array
    {
        $column = bqSQL($field);
        $langs = implode(',', array_map('intval', $idLangs));
        $audited = 'INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = pl.`id_product` AND p.`state` = '
            . (int) Product::STATE_SAVED . '
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = pl.`id_product` AND ps.`id_shop` = '
            . $idShop . ($activeOnly ? ' AND ps.`active` = 1' : '');

        // Compared among the products audited, by the database's own
        // collation, as the shop compares its friendly URLs.
        $rows = Db::getInstance()->executeS(
            'SELECT pl.`id_product`, pl.`id_lang`
             FROM `' . _DB_PREFIX_ . 'product_lang` pl
             ' . $audited . '
             INNER JOIN (
                SELECT pl.`id_lang`, TRIM(pl.`' . $column . '`) AS `value`
                FROM `' . _DB_PREFIX_ . 'product_lang` pl
                ' . $audited . '
                WHERE pl.`id_shop` = ' . $idShop . ' AND pl.`id_lang` IN (' . $langs . ')
                  AND TRIM(pl.`' . $column . '`) <> \'\'
                GROUP BY pl.`id_lang`, TRIM(pl.`' . $column . '`)
                HAVING COUNT(*) > 1
             ) d ON d.`id_lang` = pl.`id_lang` AND d.`value` = TRIM(pl.`' . $column . '`)
             WHERE pl.`id_shop` = ' . $idShop
        ) ?: [];

        $keys = [];
        foreach ($rows as $row) {
            $keys[(int) $row['id_product'] . '-' . (int) $row['id_lang']] = true;
        }

        return $keys;
    }

    /**
     * The languages to audit, id => ISO code, or null for one the shop lacks.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     *
     * @return array<int, string>|null
     */
    private static function languages(array $arguments, int $idShop): ?array
    {
        $all = [];
        foreach (Language::getLanguages(true, $idShop) as $language) {
            $all[(int) $language['id_lang']] = (string) $language['iso_code'];
        }

        if (!array_key_exists('language', $arguments) || $arguments['language'] === null || $arguments['language'] === '') {
            return $all !== [] ? $all : null;
        }
        if (!is_string($arguments['language'])) {
            return null;
        }

        $iso = Tools::strtolower(trim($arguments['language']));
        foreach ($all as $idLang => $code) {
            if (Tools::strtolower($code) === $iso) {
                return [$idLang => $code];
            }
        }

        return null;
    }

    /**
     * The checks asked for, all of them by default, or null for a bad list.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<int, string>|null
     */
    private static function checks(array $arguments): ?array
    {
        if (!array_key_exists('checks', $arguments) || $arguments['checks'] === null) {
            return self::CHECKS;
        }
        if (!is_array($arguments['checks']) || $arguments['checks'] === []) {
            return null;
        }
        foreach ($arguments['checks'] as $check) {
            if (!is_string($check) || !in_array($check, self::CHECKS, true)) {
                return null;
            }
        }

        // Kept in the order of the list, so every answer reads the same way.
        return array_values(array_intersect(self::CHECKS, $arguments['checks']));
    }
}
