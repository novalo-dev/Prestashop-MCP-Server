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
 * somcp_audit_category_seo - the categories whose search engine fields have
 * a problem, per language.
 *
 * Built like somcp_audit_product_seo, whose language rule, word count and
 * length limits it reuses, so the two audits judge a title the same way. The
 * counts cover every category, so they are read in batches with plain SQL on
 * category, category_shop and category_lang of this shop, and the duplicates
 * are grouped by the database, whose collation decides what "the same title"
 * is.
 *
 * Nothing is ever written: corrections go through somcp_update_category.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolAuditProductSeo.php';

class SomcpToolAuditCategorySeo
{
    /** @var int Entries returned when the caller does not say. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Most a single call will return, whatever it asks for. */
    private const MAX_LIMIT = 100;

    /** @var int Categories read per query while scanning the shop. */
    private const BATCH = 500;

    /** @var array<int, string> Every check, in the order they are reported. */
    private const CHECKS = [
        'meta_title_missing',
        'meta_title_too_long',
        'meta_title_duplicate',
        'meta_description_missing',
        'meta_description_long',
        'description_missing',
        'link_rewrite_duplicate',
        'empty_category',
        'no_cover_image',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_audit_category_seo',
            Somcp::t('Audit the SEO of categories'),
            'Lists the categories of this shop whose search engine fields have a problem, one entry per '
                . 'category and language, each with its issues: the check that failed and the value at fault. '
                . 'Checks: meta_title_missing, meta_title_too_long (over 60 characters), meta_title_duplicate '
                . '(another category has it in that language), meta_description_missing, meta_description_long '
                . '(over 160 characters), description_missing (the category page has no text to rank on), '
                . 'link_rewrite_duplicate (another category has the same friendly URL in that language), '
                . 'empty_category (a displayed category holding no active product of this shop) and '
                . 'no_cover_image. The value is the title, description or friendly URL at fault, and an empty '
                . 'string for the checks that report an absence. empty_category and no_cover_image concern the '
                . 'whole category and are repeated on each language entry. The root and Home categories are left out: they have no '
                . 'page of their own. counts gives, per check, the entries raising it over every category, and '
                . 'total_count the entries with at least one issue: read the next page with offset rather than '
                . 'concluding from one page. Read only. Do not use it to change a category: '
                . 'somcp_update_category does that.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one language of the shop, for example en. Every language of '
                            . 'the shop when left out.',
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
            // The search engine fields of a category are on its page in the
            // catalogue, and reading them takes the view right there.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminCategories')
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

        $languages = SomcpToolAuditProductSeo::languages($arguments, $idShop);
        if ($languages === null) {
            return SomcpTool::text(
                'This language is not one of the shop\'s. Give the ISO code of one of its languages, or leave '
                    . 'language out for all of them.',
                true
            );
        }

        $limit = isset($arguments['limit']) && is_numeric($arguments['limit']) ? (int) $arguments['limit'] : self::DEFAULT_LIMIT;
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = isset($arguments['offset']) && is_numeric($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;

        $idLangs = array_keys($languages);
        $duplicateTitles = self::duplicates('meta_title', $idShop, $idLangs);
        $duplicateUrls = self::duplicates('link_rewrite', $idShop, $idLangs);

        $counts = array_fill_keys(self::CHECKS, 0);
        $entries = [];
        $total = 0;

        foreach (array_chunk(self::categoryIds($idShop), self::BATCH) as $batch) {
            $products = self::productCounts($batch, $idShop);

            foreach (self::rows($batch, $idShop, $idLangs) as $row) {
                $idCategory = (int) $row['id_category'];
                $idLang = (int) $row['id_lang'];
                $key = $idCategory . '-' . $idLang;

                $issues = self::issues($row, $products[$idCategory] ?? 0, isset($duplicateTitles[$key]), isset($duplicateUrls[$key]));
                if ($issues === []) {
                    continue;
                }

                foreach ($issues as $issue) {
                    ++$counts[$issue['check']];
                }
                // Only the page asked for is kept, so every category is
                // counted without being held in memory.
                if ($total >= $offset && count($entries) < $limit) {
                    $entries[] = [
                        'id_category' => $idCategory,
                        'name' => (string) $row['name'],
                        'language' => $languages[$idLang],
                        'active' => (bool) $row['active'],
                        'issues' => $issues,
                    ];
                }
                ++$total;
            }
        }

        return SomcpTool::json([
            'categories' => $entries,
            'returned' => count($entries),
            // So the assistant knows there is more instead of concluding from
            // one page that it has seen everything.
            'total_count' => $total,
            'offset' => $offset,
            'counts' => $counts,
            'languages' => array_values($languages),
        ]);
    }

    /**
     * The issues of one category in one language.
     *
     * @param array<string, mixed> $row
     * @param int $activeProducts
     * @param bool $duplicateTitle
     * @param bool $duplicateUrl
     *
     * @return array<int, array<string, mixed>>
     */
    private static function issues(array $row, int $activeProducts, bool $duplicateTitle, bool $duplicateUrl): array
    {
        $title = trim((string) $row['meta_title']);
        $description = trim((string) $row['meta_description']);
        $idCategory = (int) $row['id_category'];

        $found = [
            'meta_title_missing' => $title === '' ? '' : null,
            'meta_title_too_long' => Tools::strlen($title) > SomcpToolAuditProductSeo::TITLE_MAX ? $title : null,
            'meta_title_duplicate' => $duplicateTitle ? $title : null,
            'meta_description_missing' => $description === '' ? '' : null,
            'meta_description_long' => Tools::strlen($description) > SomcpToolAuditProductSeo::DESCRIPTION_MAX ? $description : null,
            // Counted as words, tags removed: a description holding only
            // markup shows the visitor nothing either.
            'description_missing' => SomcpToolAuditProductSeo::words((string) $row['description']) === 0 ? '' : null,
            'link_rewrite_duplicate' => $duplicateUrl ? (string) $row['link_rewrite'] : null,
            // Only a displayed category has a page a search engine can find
            // empty; a hidden one answers 404 whatever it holds.
            'empty_category' => (bool) $row['active'] && $activeProducts === 0 ? '' : null,
            // The cover is the file the back office category form shows, the
            // same path on PrestaShop 8 and 9.
            'no_cover_image' => !file_exists(_PS_CAT_IMG_DIR_ . $idCategory . '.jpg') ? '' : null,
        ];

        $issues = [];
        foreach (self::CHECKS as $check) {
            if ($found[$check] !== null) {
                $issues[] = ['check' => $check, 'value' => $found[$check]];
            }
        }

        return $issues;
    }

    /**
     * The categories of this shop to audit, in order.
     *
     * @param int $idShop
     *
     * @return array<int, int>
     */
    private static function categoryIds(int $idShop): array
    {
        $query = new DbQuery();
        $query->select('c.`id_category`');
        $query->from('category', 'c');
        // The shop comes from the token, and a category not associated with
        // it is not part of its catalogue.
        $query->innerJoin('category_shop', 'cs', 'cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $idShop);
        $query->where(self::audited());
        $query->orderBy('c.`id_category` ASC');

        return array_map('intval', array_column(Db::getInstance()->executeS($query) ?: [], 'id_category'));
    }

    /**
     * The fields of a batch of categories, one row per category and language.
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
        $query->select('c.`id_category`, c.`active`, cl.`id_lang`, cl.`name`, cl.`meta_title`, cl.`meta_description`');
        $query->select('cl.`link_rewrite`, cl.`description`');
        $query->from('category', 'c');
        $query->innerJoin(
            'category_lang',
            'cl',
            'cl.`id_category` = c.`id_category` AND cl.`id_shop` = ' . $idShop
                . ' AND cl.`id_lang` IN (' . implode(',', array_map('intval', $idLangs)) . ')'
        );
        $query->where('c.`id_category` IN (' . implode(',', array_map('intval', $ids)) . ')');
        $query->orderBy('c.`id_category` ASC, cl.`id_lang` ASC');

        return Db::getInstance()->executeS($query) ?: [];
    }

    /**
     * How many active products of this shop each category of a batch holds.
     *
     * @param array<int, int> $ids
     * @param int $idShop
     *
     * @return array<int, int>
     */
    private static function productCounts(array $ids, int $idShop): array
    {
        $query = new DbQuery();
        $query->select('cp.`id_category`, COUNT(DISTINCT cp.`id_product`) AS `products`');
        $query->from('category_product', 'cp');
        // A product still being created, or hidden in this shop, does not
        // fill the category page.
        $query->innerJoin('product', 'p', 'p.`id_product` = cp.`id_product` AND p.`state` = ' . (int) Product::STATE_SAVED);
        $query->innerJoin('product_shop', 'ps', 'ps.`id_product` = cp.`id_product` AND ps.`id_shop` = ' . $idShop . ' AND ps.`active` = 1');
        $query->where('cp.`id_category` IN (' . implode(',', array_map('intval', $ids)) . ')');
        $query->groupBy('cp.`id_category`');

        $counts = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $counts[(int) $row['id_category']] = (int) $row['products'];
        }

        return $counts;
    }

    /**
     * The categories whose value of a field another audited category of this
     * shop has too, in the same language, keyed "id_category-id_lang".
     *
     * @param string $field meta_title or link_rewrite, never from the caller
     * @param int $idShop
     * @param array<int, int> $idLangs
     *
     * @return array<string, true>
     */
    private static function duplicates(string $field, int $idShop, array $idLangs): array
    {
        $column = bqSQL($field);
        $langs = implode(',', array_map('intval', $idLangs));
        $audited = 'INNER JOIN `' . _DB_PREFIX_ . 'category` c ON c.`id_category` = cl.`id_category` AND ' . self::audited() . '
             INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.`id_category` = cl.`id_category` AND cs.`id_shop` = ' . $idShop;

        // Compared among the categories audited, by the database's own
        // collation, as the shop compares its friendly URLs.
        $rows = Db::getInstance()->executeS(
            'SELECT cl.`id_category`, cl.`id_lang`
             FROM `' . _DB_PREFIX_ . 'category_lang` cl
             ' . $audited . '
             INNER JOIN (
                SELECT cl.`id_lang`, TRIM(cl.`' . $column . '`) AS `value`
                FROM `' . _DB_PREFIX_ . 'category_lang` cl
                ' . $audited . '
                WHERE cl.`id_shop` = ' . $idShop . ' AND cl.`id_lang` IN (' . $langs . ')
                  AND TRIM(cl.`' . $column . '`) <> \'\'
                GROUP BY cl.`id_lang`, TRIM(cl.`' . $column . '`)
                HAVING COUNT(*) > 1
             ) d ON d.`id_lang` = cl.`id_lang` AND d.`value` = TRIM(cl.`' . $column . '`)
             WHERE cl.`id_shop` = ' . $idShop
        ) ?: [];

        $keys = [];
        foreach ($rows as $row) {
            $keys[(int) $row['id_category'] . '-' . (int) $row['id_lang']] = true;
        }

        return $keys;
    }

    /**
     * The condition leaving out the categories without a page of their own.
     *
     * @return string
     */
    private static function audited(): string
    {
        // The root has no parent, and a Home category (is_root_category) is
        // the shop's front page, not a category page: neither has fields a
        // search engine reads as a category.
        return 'c.`id_parent` > 0 AND c.`is_root_category` = 0';
    }
}
