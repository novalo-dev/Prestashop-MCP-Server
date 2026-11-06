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
 * somcp_audit_product_entities - how complete each product's data is for an
 * answer engine, the least complete first, with the missing fields named.
 *
 * Eleven fields of the product carry the facts an assistant needs to answer a
 * buyer: brand, a GTIN, MPN, features, description, summary, images with a
 * caption, weight, condition shown, a real category and a meta description.
 * Each is worth the same; the score is how many of the eleven are filled.
 *
 * The whole catalogue is scored, not one page, so it is read with plain SQL
 * on product, product_shop, product_lang, feature_product and the image
 * tables of this shop and language - one set query per group of criteria,
 * never one Product object per product: a catalogue of thousands of products
 * has to answer in time. Nothing is ever written: corrections go through
 * somcp_update_product.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolAuditProductEntities
{
    /** @var int Entries returned when the caller does not say. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Most a single call will return. */
    private const MAX_LIMIT = 100;

    /** @var int Products read per query while scanning the catalogue. */
    private const BATCH = 500;

    /** @var int Features a product needs to count as described for an answer engine. */
    private const FEATURES_MIN = 3;

    /** @var int Images a product needs, each with a caption. */
    private const IMAGES_MIN = 3;

    /** @var int Words a description needs, tags stripped, to say enough about the product. */
    private const WORDS_MIN = 150;

    /** @var array<int, string> Every criterion, in the order they are reported, each worth the same. */
    private const CRITERIA = [
        'brand',
        'gtin',
        'mpn',
        'features',
        'description',
        'summary',
        'images',
        'weight',
        'condition',
        'category',
        'meta_description',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_audit_product_entities',
            Somcp::t('Audit how complete products are for answer engines'),
            'Scores how complete each product is for an assistant answering a buyer, least complete first, '
                . 'naming what is missing with the labels of the product page. Eleven criteria of equal weight, score '
                . '100 x filled / 11: brand, GTIN (EAN-13, UPC or ISBN), MPN, 3 or more features, a description of '
                . '150 words or more, summary, 3 or more images each with a caption, weight, condition shown, a '
                . 'category below Home, meta description. Texts in one language. Read only. To fill the gaps use '
                . 'somcp_update_product; what the page prints: somcp_audit_product_schema; meta texts in detail: '
                . 'somcp_audit_product_seo.',
            [
                'type' => 'object',
                'properties' => [
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true (the default) for active products only, false for disabled products '
                            . 'only. The same Status filter as somcp_search_products.',
                    ],
                    'max_score' => [
                        'type' => 'integer',
                        'description' => 'Only products scoring at or under this, from 0 to 100. All of them when '
                            . 'left out.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one language of the shop, for example en. The shop default '
                            . 'language when left out.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many products to return, 20 by default and 100 at most.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'How many products to skip, to read the next page.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_READ],
            [self::class, 'handle'],
            // The data scored is on the product page of the catalogue, which
            // the view right there already lets the employee read.
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

        // Every mistake found before reading anything is reported in one
        // answer, so the call is corrected in one go (the shape of #169).
        $problems = [];
        $allowed = ['active', 'max_score', 'language', 'limit', 'offset'];
        foreach (array_keys($arguments) as $field) {
            if (!in_array($field, $allowed, true)) {
                $problems[] = '- ' . $field . ': not a field of this tool';
            }
        }

        $asked = array_key_exists('language', $arguments) && $arguments['language'] !== null && $arguments['language'] !== '';
        // One language asked for is looked up; none asked for falls back on
        // the shop default, which readLanguage() returns for an absent key.
        $language = SomcpTool::readLanguage($asked ? $arguments : [], $idShop);
        if ($asked && $language === null) {
            $problems[] = '- language: not a language of this shop, which has '
                . implode(', ', SomcpTool::activeLanguages($idShop));
        }

        $maxScore = null;
        if (array_key_exists('max_score', $arguments) && $arguments['max_score'] !== null) {
            if (!is_int($arguments['max_score']) || $arguments['max_score'] < 0 || $arguments['max_score'] > 100) {
                $problems[] = '- max_score: a whole number from 0 to 100';
            } else {
                $maxScore = (int) $arguments['max_score'];
            }
        }

        if (array_key_exists('limit', $arguments) && $arguments['limit'] !== null
            && (!is_int($arguments['limit']) || $arguments['limit'] < 1 || $arguments['limit'] > self::MAX_LIMIT)) {
            $problems[] = '- limit: a whole number from 1 to ' . self::MAX_LIMIT;
        }
        if (array_key_exists('offset', $arguments) && $arguments['offset'] !== null
            && (!is_int($arguments['offset']) || $arguments['offset'] < 0)) {
            $problems[] = '- offset: a whole number of 0 or more';
        }

        if ($problems !== []) {
            return SomcpTool::text(
                "Nothing was read. Correct these and call again:\n" . implode("\n", array_unique($problems)),
                true
            );
        }
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;

        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : self::DEFAULT_LIMIT;
        $offset = isset($arguments['offset']) ? (int) $arguments['offset'] : 0;
        $activeOnly = !(array_key_exists('active', $arguments) && $arguments['active'] === false);

        $met = self::score($idShop, $idLang, $activeOnly);

        // The score of every audited product, so the list is ordered by it
        // and the average covers the whole catalogue, not one page.
        $scored = [];
        $sum = 0;
        foreach ($met as $idProduct => $criteria) {
            $hits = count($criteria);
            $score = (int) round(100 * $hits / count(self::CRITERIA));
            $sum += $score;
            if ($maxScore !== null && $score > $maxScore) {
                continue;
            }
            $scored[$idProduct] = $score;
        }
        $audited = count($met);

        // Least complete first; the id breaks a tie, so paging is stable.
        uksort($scored, static function ($a, $b) use ($scored): int {
            return $scored[$a] <=> $scored[$b] ?: $a <=> $b;
        });

        $total = count($scored);
        $page = array_slice($scored, $offset, $limit, true);
        $names = self::names(array_keys($page), $idShop, $idLang);

        $products = [];
        foreach ($page as $idProduct => $score) {
            $missing = [];
            foreach (self::CRITERIA as $criterion) {
                if (!isset($met[$idProduct][$criterion])) {
                    $missing[] = ['field' => self::field($criterion), 'on_screen' => self::label($criterion)];
                }
            }
            $products[] = [
                'id_product' => $idProduct,
                'name' => $names[$idProduct] ?? '',
                'score' => $score,
                'missing' => $missing,
            ];
        }

        return SomcpTool::json([
            'products' => $products,
            'returned' => count($products),
            // So the assistant reads the next page with offset instead of
            // concluding from one page that it has seen everything.
            'total_count' => $total,
            'offset' => $offset,
            // Over the whole catalogue audited, not the filtered page, so it
            // reads the shop's completeness even with max_score set.
            'average_score' => $audited > 0 ? (int) round($sum / $audited) : 0,
            'language' => $iso,
        ]);
    }

    /**
     * For every audited product of this shop, the set of criteria it meets,
     * keyed by id_product then by criterion name.
     *
     * One set query per group of criteria rather than one object per product,
     * so a large catalogue stays fast.
     *
     * @param int $idShop
     * @param int $idLang
     * @param bool $activeOnly
     *
     * @return array<int, array<string, true>>
     */
    private static function score(int $idShop, int $idLang, bool $activeOnly): array
    {
        $root = (int) Configuration::get('PS_ROOT_CATEGORY');
        $shop = new Shop($idShop);
        // The shop's own home, as the catalogue tools read it; a product left
        // on Home or on the root is not sorted into the catalogue.
        $home = Validate::isLoadedObject($shop) && (int) $shop->id_category > 0
            ? (int) $shop->id_category
            : (int) Configuration::get('PS_HOME_CATEGORY');

        $met = [];
        foreach (self::productIds($idShop, $activeOnly) as $idProduct) {
            $met[$idProduct] = [];
        }
        if ($met === []) {
            return [];
        }

        // Group A - the fields on product and product_shop, read in one pass.
        foreach (array_chunk(array_keys($met), self::BATCH) as $batch) {
            $query = new DbQuery();
            $query->select('p.`id_product`, p.`id_manufacturer`, p.`ean13`, p.`upc`, p.`isbn`, p.`mpn`, p.`weight`');
            $query->select('ps.`condition`, ps.`show_condition`, ps.`id_category_default`');
            $query->from('product', 'p');
            $query->innerJoin('product_shop', 'ps', 'ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop);
            $query->where('p.`id_product` IN (' . implode(',', array_map('intval', $batch)) . ')');
            foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
                $id = (int) $row['id_product'];
                if ((int) $row['id_manufacturer'] > 0) {
                    $met[$id]['brand'] = true;
                }
                if (trim((string) $row['ean13']) !== '' || trim((string) $row['upc']) !== '' || trim((string) $row['isbn']) !== '') {
                    $met[$id]['gtin'] = true;
                }
                if (trim((string) $row['mpn']) !== '') {
                    $met[$id]['mpn'] = true;
                }
                if ((float) $row['weight'] > 0) {
                    $met[$id]['weight'] = true;
                }
                if (trim((string) $row['condition']) !== '' && (bool) $row['show_condition']) {
                    $met[$id]['condition'] = true;
                }
                $category = (int) $row['id_category_default'];
                if ($category > 0 && $category !== $root && $category !== $home) {
                    $met[$id]['category'] = true;
                }
            }

            // Group B - the texts on product_lang of this shop and language.
            $query = new DbQuery();
            $query->select('pl.`id_product`, pl.`description`, pl.`description_short`, pl.`meta_description`');
            $query->from('product_lang', 'pl');
            $query->where('pl.`id_shop` = ' . $idShop . ' AND pl.`id_lang` = ' . $idLang);
            $query->where('pl.`id_product` IN (' . implode(',', array_map('intval', $batch)) . ')');
            foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
                $id = (int) $row['id_product'];
                if (SomcpToolAuditProductSeo::words((string) $row['description']) >= self::WORDS_MIN) {
                    $met[$id]['description'] = true;
                }
                if (trim((string) $row['description_short']) !== '') {
                    $met[$id]['summary'] = true;
                }
                if (trim((string) $row['meta_description']) !== '') {
                    $met[$id]['meta_description'] = true;
                }
            }

            // Group C - the features, counted distinct per product.
            $query = new DbQuery();
            $query->select('fp.`id_product`, COUNT(DISTINCT fp.`id_feature`) AS `features`');
            $query->from('feature_product', 'fp');
            $query->where('fp.`id_product` IN (' . implode(',', array_map('intval', $batch)) . ')');
            $query->groupBy('fp.`id_product`');
            foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
                if ((int) $row['features'] >= self::FEATURES_MIN) {
                    $met[(int) $row['id_product']]['features'] = true;
                }
            }

            // Group D - the images of this shop, and those without a caption
            // in this language.
            $query = new DbQuery();
            $query->select('i.`id_product`, COUNT(DISTINCT i.`id_image`) AS `images`');
            $query->select('SUM(CASE WHEN TRIM(COALESCE(il.`legend`, \'\')) = \'\' THEN 1 ELSE 0 END) AS `no_caption`');
            $query->from('image', 'i');
            $query->innerJoin('image_shop', 'ims', 'ims.`id_image` = i.`id_image` AND ims.`id_shop` = ' . $idShop);
            $query->leftJoin('image_lang', 'il', 'il.`id_image` = i.`id_image` AND il.`id_lang` = ' . $idLang);
            $query->where('i.`id_product` IN (' . implode(',', array_map('intval', $batch)) . ')');
            $query->groupBy('i.`id_product`');
            foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
                if ((int) $row['images'] >= self::IMAGES_MIN && (int) $row['no_caption'] === 0) {
                    $met[(int) $row['id_product']]['images'] = true;
                }
            }
        }

        return $met;
    }

    /**
     * The products of this shop to audit, smallest id first.
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
     * The names of the products on the page, in this language.
     *
     * @param array<int, int> $ids
     * @param int $idShop
     * @param int $idLang
     *
     * @return array<int, string>
     */
    private static function names(array $ids, int $idShop, int $idLang): array
    {
        if ($ids === []) {
            return [];
        }
        $query = new DbQuery();
        $query->select('pl.`id_product`, pl.`name`');
        $query->from('product_lang', 'pl');
        $query->where('pl.`id_shop` = ' . $idShop . ' AND pl.`id_lang` = ' . $idLang);
        $query->where('pl.`id_product` IN (' . implode(',', array_map('intval', $ids)) . ')');

        $names = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $names[(int) $row['id_product']] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * The field of somcp_get_product a criterion stands for, named in missing.
     *
     * @param string $criterion
     *
     * @return string
     */
    private static function field(string $criterion): string
    {
        $fields = [
            'brand' => 'id_manufacturer',
            'gtin' => 'ean13',
            'mpn' => 'mpn',
            'features' => 'features',
            'description' => 'description',
            'summary' => 'description_short',
            'images' => 'images',
            'weight' => 'weight',
            'condition' => 'condition',
            'category' => 'id_category_default',
            'meta_description' => 'meta_description',
        ];

        return $fields[$criterion] ?? $criterion;
    }

    /**
     * The label of a criterion on the product page, in the words of the
     * shop's version.
     *
     * @param string $criterion
     *
     * @return string
     */
    private static function label(string $criterion): string
    {
        $nine = version_compare(_PS_VERSION_, '9.0.0', '>=');
        $labels = [
            'brand' => 'Brand',
            // The barcode field is one line on 8.0.1, renamed on 9.0.0.
            'gtin' => $nine ? 'GTIN (EAN, JA, ITF or UCC code)' : 'EAN-13 or JAN barcode',
            'mpn' => 'MPN',
            'features' => 'Features',
            'description' => 'Description',
            'summary' => 'Summary',
            'images' => 'Images',
            'weight' => 'Package weight',
            'condition' => 'Condition',
            'category' => 'Default category',
            'meta_description' => 'Meta description',
        ];

        return $labels[$criterion] ?? $criterion;
    }
}
