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
 * somcp_audit_brand_seo - the brands whose search engine fields or
 * descriptions have a problem, per language.
 *
 * Built like somcp_audit_product_seo, whose language rule and word count it
 * reuses. A shop holds a few hundred brands at most, so they are read in two
 * queries on manufacturer, manufacturer_shop and manufacturer_lang of this
 * shop and returned whole, without paging. products_count counts the products
 * of this shop exactly as somcp_get_manufacturer does, so the two tools never
 * give a brand two different figures.
 *
 * Nothing is ever written: corrections go through somcp_update_manufacturer.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolAuditProductSeo.php';

class SomcpToolAuditBrandSeo
{
    /** @var array<int, string> Every check, in the order they are reported. */
    private const CHECKS = [
        'meta_title_missing',
        'meta_description_missing',
        'description_missing',
        'no_logo',
        'inactive_with_products',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_audit_brand_seo',
            Somcp::t('Audit the SEO of brands'),
            'Lists the brands of this shop whose search engine fields or descriptions have a problem, one '
                . 'entry per brand and language, each with its issues: the check that failed and the value at '
                . 'fault. Checks: meta_title_missing, meta_description_missing, description_missing (the brand '
                . 'page has no description, tags removed), no_logo, and inactive_with_products (a disabled brand '
                . 'whose page is gone while products of the shop still carry it; value is the number of '
                . 'products). no_logo and inactive_with_products concern the whole brand and are repeated on '
                . 'each language entry. products_count is the number of products of this shop attached to the '
                . 'brand. Every brand is listed, enabled or not, with active saying which; counts gives the '
                . 'entries raising each check. Read only. Do not use it to change a brand: '
                . 'somcp_update_manufacturer does that.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one language of the shop, for example en. Every language of '
                            . 'the shop when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_READ],
            [self::class, 'handle'],
            // The search engine fields of a brand are in its form under
            // Catalog > Brands, and reading them takes the view right there.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminManufacturers')
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

        $products = self::productCounts($idShop);
        $counts = array_fill_keys(self::CHECKS, 0);
        $brands = [];

        foreach (self::rows($idShop, array_keys($languages)) as $row) {
            $idManufacturer = (int) $row['id_manufacturer'];
            $productsCount = $products[$idManufacturer] ?? 0;

            $issues = self::issues($row, $productsCount);
            if ($issues === []) {
                continue;
            }

            foreach ($issues as $issue) {
                ++$counts[$issue['check']];
            }
            $brands[] = [
                'id_manufacturer' => $idManufacturer,
                'name' => (string) $row['name'],
                'language' => $languages[(int) $row['id_lang']],
                'active' => (bool) $row['active'],
                'products_count' => $productsCount,
                'issues' => $issues,
            ];
        }

        return SomcpTool::json([
            'brands' => $brands,
            'total_count' => count($brands),
            'counts' => $counts,
            'languages' => array_values($languages),
        ]);
    }

    /**
     * The issues of one brand in one language.
     *
     * @param array<string, mixed> $row
     * @param int $productsCount
     *
     * @return array<int, array<string, mixed>>
     */
    private static function issues(array $row, int $productsCount): array
    {
        $idManufacturer = (int) $row['id_manufacturer'];

        $found = [
            'meta_title_missing' => trim((string) $row['meta_title']) === '' ? '' : null,
            'meta_description_missing' => trim((string) $row['meta_description']) === '' ? '' : null,
            // Counted as words, tags removed: a description holding only
            // markup shows the visitor nothing either.
            'description_missing' => SomcpToolAuditProductSeo::words((string) $row['description']) === 0 ? '' : null,
            // The logo is the file the back office brand form shows, as
            // somcp_get_manufacturer reads it.
            'no_logo' => !is_file(_PS_MANU_IMG_DIR_ . $idManufacturer . '.jpg') ? '' : null,
            // A disabled brand has no page, so the links its products show to
            // it lead nowhere. The value is how many products carry it.
            'inactive_with_products' => !(bool) $row['active'] && $productsCount > 0 ? $productsCount : null,
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
     * The fields of every brand of this shop, one row per brand and language.
     *
     * @param int $idShop
     * @param array<int, int> $idLangs
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rows(int $idShop, array $idLangs): array
    {
        $query = new DbQuery();
        $query->select('m.`id_manufacturer`, m.`name`, m.`active`, l.`id_lang`');
        $query->select('ml.`meta_title`, ml.`meta_description`, ml.`description`');
        $query->from('manufacturer', 'm');
        // The shop comes from the token, and a brand not associated with it
        // is not one of its brands.
        $query->innerJoin('manufacturer_shop', 'ms', 'ms.`id_manufacturer` = m.`id_manufacturer` AND ms.`id_shop` = ' . $idShop);
        // Every language asked for, even one the brand has no row in: a
        // missing row is a missing title, description and meta description.
        $query->innerJoin('lang', 'l', 'l.`id_lang` IN (' . implode(',', array_map('intval', $idLangs)) . ')');
        // The texts of a brand are the same in every shop: manufacturer_lang
        // has no id_shop.
        $query->leftJoin('manufacturer_lang', 'ml', 'ml.`id_manufacturer` = m.`id_manufacturer` AND ml.`id_lang` = l.`id_lang`');
        $query->orderBy('m.`name` ASC, m.`id_manufacturer` ASC, l.`id_lang` ASC');

        return Db::getInstance()->executeS($query) ?: [];
    }

    /**
     * How many products of this shop each brand carries.
     *
     * @param int $idShop
     *
     * @return array<int, int>
     */
    private static function productCounts(int $idShop): array
    {
        // The count of somcp_get_manufacturer: the brand is on the product
        // itself, not per shop, so only the products of this shop are counted.
        $rows = Db::getInstance()->executeS(
            'SELECT p.`id_manufacturer`, COUNT(DISTINCT p.`id_product`) AS `products`
             FROM `' . _DB_PREFIX_ . 'product` p
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
             WHERE p.`id_manufacturer` > 0
             GROUP BY p.`id_manufacturer`'
        ) ?: [];

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id_manufacturer']] = (int) $row['products'];
        }

        return $counts;
    }
}
