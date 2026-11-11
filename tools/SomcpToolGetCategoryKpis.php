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
 * somcp_get_category_kpis - the four figures above the category list.
 *
 * Catalog > Categories is headed by four numbers the merchant reads at a
 * glance: how many categories are disabled, how many hold no product, which
 * category sold the most over the last month, and the average number of
 * products per category. No other tool answers them: somcp_list_categories
 * gives identifiers and names, somcp_get_category one category at a time. An
 * assistant asked "which categories are empty" or "what sold best this month"
 * would otherwise read every category one by one.
 *
 * The figures are computed the way AdminStatsController computes them for the
 * KPI row, with two differences that the entry spells out: the top category is
 * restricted to the shop of the token (the back office query is not), and each
 * sold line is divided by its own order's conversion rate before summing
 * (the back office divides a product's whole sum by one order's rate). On a
 * single-currency shop both differences leave the figures unchanged.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetCategoryKpis
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_category_kpis',
            Somcp::t('Category KPIs'),
            'Returns the four figures shown above the category list in Catalog > Categories: how many '
                . 'categories are disabled, how many hold no product, the best selling category of the last month '
                . 'and the average number of products per category. Computed as the back office counts them, for '
                . 'this shop. The best category counts every invoiced order line of the period whatever its current '
                . 'status, as the back office does. For the list of categories use somcp_list_categories, and for one '
                . 'category and its product count use somcp_get_category.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of the language to read the top category name in, '
                            . 'for example fr. The shop default is used otherwise.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // The page a merchant opens to look at these figures.
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

        // Every mistake is reported in one answer, so the call is corrected in
        // one go (the envelope of #169).
        $problems = [];
        foreach (array_keys($arguments) as $field) {
            if ($field !== 'language') {
                $problems[] = '- ' . $field . ': not a field of this tool';
            }
        }

        $asked = array_key_exists('language', $arguments) && $arguments['language'] !== null && $arguments['language'] !== '';
        $language = SomcpTool::readLanguage($asked ? $arguments : [], $idShop);
        if ($asked && $language === null) {
            $problems[] = '- language: not a language of this shop, which has '
                . implode(', ', SomcpTool::activeLanguages($idShop));
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

        return SomcpTool::json([
            'disabled_categories' => self::disabledCategories(),
            'empty_categories' => self::emptyCategories(),
            'top_category' => self::topCategory($idShop, $idLang),
            'products_per_category' => self::productsPerCategory(),
            'language' => $iso,
        ]);
    }

    /**
     * Categories of the shop whose active flag is off, as getDisabledCategories()
     * (AdminStatsController line 163) counts them. The shop context set from the
     * token drives Shop::addSqlAssociation.
     *
     * @return int
     */
    private static function disabledCategories(): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*)
             FROM `' . _DB_PREFIX_ . 'category` c
             ' . Shop::addSqlAssociation('category', 'c') . '
             WHERE c.`active` = 0'
        );
    }

    /**
     * Categories of the shop, the root left out, that hold no product directly,
     * as getEmptyCategories() (AdminStatsController line 315) counts them: the
     * total minus those with at least one row in category_product. Home counts
     * when it holds no product directly, as the screen shows it.
     *
     * @return int
     */
    private static function emptyCategories(): int
    {
        $db = Db::getInstance();
        $idRoot = (int) Configuration::get('PS_ROOT_CATEGORY');

        $total = (int) $db->getValue(
            'SELECT COUNT(*)
             FROM `' . _DB_PREFIX_ . 'category` c
             ' . Shop::addSqlAssociation('category', 'c') . '
             AND c.`id_category` != ' . $idRoot
        );
        $used = (int) $db->getValue(
            'SELECT COUNT(DISTINCT cp.`id_category`)
             FROM `' . _DB_PREFIX_ . 'category` c
             LEFT JOIN `' . _DB_PREFIX_ . 'category_product` cp ON c.`id_category` = cp.`id_category`
             ' . Shop::addSqlAssociation('category', 'c') . '
             AND c.`id_category` != ' . $idRoot
        );

        return $total - $used;
    }

    /**
     * The best selling category of the last month, or null when nothing sold.
     *
     * getBestCategory() (AdminStatsController line 357) orders the categories by
     * their summed sales and takes the first row with getValue(); when no line
     * has an invoice date in the period every sum is NULL and it still names a
     * category that sold nothing. This counts only categories with a sold line
     * in the period, so an empty period answers null instead.
     *
     * Two deliberate differences from the back office query, both written in the
     * entry: the orders are restricted to the shop of the token, and each line
     * is divided by its own order's conversion rate before summing (the back
     * office divides a product's whole sum by one order's rate, which depends on
     * which order MySQL picks when the group spans several currencies). The sum
     * is tax excluded, in the shop default currency.
     *
     * @param int $idShop
     * @param int $idLang language the category name is read in
     *
     * @return array<string, mixed>|null
     */
    private static function topCategory(int $idShop, int $idLang): ?array
    {
        // The last month, as the KPI row asks for it (line 943).
        $dateFrom = date('Y-m-d', strtotime('-1 month'));
        $dateTo = date('Y-m-d');
        $between = "'" . pSQL($dateFrom) . " 00:00:00' AND '" . pSQL($dateTo) . " 23:59:59'";

        $row = Db::getInstance()->getRow(
            'SELECT ca.`id_category` AS `id_category`,
                    SUM(od.`unit_price_tax_excl` * od.`product_quantity` / o.`conversion_rate`) AS `sales`
             FROM `' . _DB_PREFIX_ . 'category` ca
             INNER JOIN `' . _DB_PREFIX_ . 'category_product` cp ON cp.`id_category` = ca.`id_category`
             INNER JOIN `' . _DB_PREFIX_ . 'order_detail` od ON od.`product_id` = cp.`id_product`
             INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = od.`id_order`
             WHERE ca.`level_depth` > 1
                 AND o.`id_shop` = ' . (int) $idShop . '
                 AND o.`invoice_date` BETWEEN ' . $between . '
             GROUP BY ca.`id_category`
             ORDER BY `sales` DESC'
        );

        if (!$row || $row['sales'] === null || (float) $row['sales'] <= 0) {
            return null;
        }

        $idCategory = (int) $row['id_category'];
        $category = new Category($idCategory, $idLang, $idShop);

        return [
            'id_category' => $idCategory,
            'name' => (string) $category->name,
            'sales_tax_excl' => round((float) $row['sales'], 2),
            'currency' => (string) (new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop)))->iso_code,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    /**
     * The total products divided by the total categories of the shop, rounded,
     * as the KPI row shows it (AdminStatsController line 933, getTotalProducts()
     * line 195 over getTotalCategories() line 174). A shop always has at least
     * its root category, so the division is safe, but the guard keeps a zero
     * answer rather than a warning should a shop have none.
     *
     * @return int
     */
    private static function productsPerCategory(): int
    {
        $db = Db::getInstance();
        $products = (int) $db->getValue(
            'SELECT COUNT(*)
             FROM `' . _DB_PREFIX_ . 'product` p
             ' . Shop::addSqlAssociation('product', 'p')
        );
        $categories = (int) $db->getValue(
            'SELECT COUNT(*)
             FROM `' . _DB_PREFIX_ . 'category` c
             ' . Shop::addSqlAssociation('category', 'c')
        );

        return $categories > 0 ? (int) round($products / $categories) : 0;
    }
}
