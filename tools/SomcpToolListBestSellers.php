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
 * somcp_list_best_sellers - the products that sold most over a period.
 *
 * Counted the way Stats > Best-selling products (statsbestproducts) counts
 * them: valid orders dated by their invoice date, one row per product with
 * its combinations merged, amounts tax excluded and divided by the
 * conversion rate so they are all in the default currency. A merchant
 * comparing the answer with that page must find the same rows.
 *
 * The tables are read here rather than through the stats module, so the
 * list still comes when statsbestproducts is disabled or uninstalled.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListBestSellers
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    /**
     * Sort values mapped to the computed columns, all read descending.
     */
    private const SORTS = [
        'sales' => '`revenue_tax_excl`',
        'quantity_sold' => '`quantity_sold`',
        'quantity_sold_per_day' => '`quantity_sold_per_day`',
        'average_price_tax_excl' => '`average_price_tax_excl`',
        'page_views' => '`page_views`',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_best_sellers',
            Somcp::t('List best-selling products'),
            'Lists the products that sold most over a period, as Stats > Best-selling products shows '
                . 'them: valid orders only, dated by their invoice date, one row per product with its '
                . 'combinations merged, amounts tax excluded and converted to the shop\'s default '
                . 'currency (currency). Each row gives id_product, reference, name, quantity_sold, '
                . 'average_price_tax_excl (Price sold, the average unit price of the order lines), '
                . 'revenue_tax_excl (Sales, credit slips not taken off), quantity_sold_per_day, '
                . 'page_views (0 when page views are not recorded), quantity (available for sale) and '
                . 'active. Sorted by sales by default, as the screen. A product deleted since is not '
                . 'listed. Do not use it for sales totals (somcp_get_sales_summary), the orders behind '
                . 'a product (somcp_search_orders, which filters on the date the order was placed) or '
                . 'the stock of each combination (somcp_get_stock).',
            [
                'type' => 'object',
                'properties' => [
                    'date_from' => [
                        'type' => 'string',
                        'description' => 'First day of the period (From:), YYYY-MM-DD, that day included, in the shop\'s time zone.',
                    ],
                    'date_to' => [
                        'type' => 'string',
                        'description' => 'Last day of the period (To:), YYYY-MM-DD, that day included. Not before date_from.',
                    ],
                    'sort' => [
                        'type' => 'string',
                        'enum' => array_keys(self::SORTS),
                        'description' => 'Column to sort on, always highest first: sales (default, as the screen), '
                            . 'quantity_sold, quantity_sold_per_day, average_price_tax_excl or page_views.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, '
                            . 'for the product names; a code the shop does not have is refused. '
                            . 'The shop default language when left out.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many products to return, 20 by default and 100 at most.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'How many to skip, to read the next page.',
                    ],
                ],
                'required' => ['date_from', 'date_to'],
                'additionalProperties' => false,
            ],
            [SomcpScope::STATS_READ],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminStats')
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
        $dateFrom = SomcpTool::day($arguments['date_from'] ?? null);
        if ($dateFrom === null) {
            return SomcpTool::text('date_from: a date, YYYY-MM-DD.', true);
        }
        $dateTo = SomcpTool::day($arguments['date_to'] ?? null);
        if ($dateTo === null) {
            return SomcpTool::text('date_to: a date, YYYY-MM-DD.', true);
        }
        if ($dateTo < $dateFrom) {
            return SomcpTool::text('date_to: a date not before date_from.', true);
        }

        $sort = $arguments['sort'] ?? 'sales';
        if (!is_string($sort) || !isset(self::SORTS[$sort])) {
            return SomcpTool::text('sort: one of ' . implode(', ', array_keys(self::SORTS)) . '.', true);
        }

        $limit = $arguments['limit'] ?? self::DEFAULT_LIMIT;
        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            return SomcpTool::text('limit: an integer from 1 to ' . self::MAX_LIMIT . '.', true);
        }
        $offset = $arguments['offset'] ?? 0;
        if (!is_int($offset) || $offset < 0) {
            return SomcpTool::text('offset: an integer, 0 or more.', true);
        }

        // The shop comes from the token, never from the caller.
        $shop = Somcp::currentShop();
        $idShop = (int) $shop->id;

        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;

        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop));

        $db = Db::getInstance();
        // Whole days: an invoice issued at 18:00 on the last day counts.
        $start = "'" . pSQL($dateFrom) . " 00:00:00'";
        $end = "'" . pSQL($dateTo) . " 23:59:59'";

        // Starting from the product, as the screen does, drops the lines of a
        // product deleted since: there is no page left to open for it.
        $from = ' FROM `' . _DB_PREFIX_ . 'order_detail` od
             INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = od.`id_order`
             INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = od.`product_id`
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
             WHERE o.`valid` = 1 AND o.`id_shop` = ' . $idShop . ' AND o.`invoice_date` BETWEEN ' . $start . ' AND ' . $end;

        $total = (int) $db->getValue('SELECT COUNT(DISTINCT od.`product_id`)' . $from);

        // Days the product could sell in the period, both ends included: not
        // before it was added to the shop, not after today. The screen
        // divides by this; a product added after the period has no day, and
        // gets 0 rather than a negative rate.
        $days = '(1 + LEAST(TO_DAYS(' . $end . '), TO_DAYS(NOW())) - GREATEST(TO_DAYS(' . $start . '), TO_DAYS(ps.`date_add`)))';

        // Page views are saved by statsdata per day range; the screen keeps
        // the ranges that lie inside the period.
        $pageViews = '(SELECT IFNULL(SUM(pv.`counter`), 0)
                FROM `' . _DB_PREFIX_ . 'page` pa
                INNER JOIN `' . _DB_PREFIX_ . 'page_type` pt ON pt.`id_page_type` = pa.`id_page_type`
                INNER JOIN `' . _DB_PREFIX_ . 'page_viewed` pv ON pv.`id_page` = pa.`id_page` AND pv.`id_shop` = ' . $idShop . '
                INNER JOIN `' . _DB_PREFIX_ . 'date_range` dr ON dr.`id_date_range` = pv.`id_date_range`
                WHERE pa.`id_object` = p.`id_product` AND pt.`name` = \'product\'
                    AND dr.`time_start` BETWEEN ' . $start . ' AND ' . $end . '
                    AND dr.`time_end` BETWEEN ' . $start . ' AND ' . $end . ')';

        // The total quantity sits on the row without a combination; whether it
        // belongs to the shop or to its group depends on stock sharing, which
        // the core decides.
        $stock = '(SELECT sa.`quantity` FROM `' . _DB_PREFIX_ . 'stock_available` sa
                WHERE sa.`id_product` = p.`id_product` AND sa.`id_product_attribute` = 0'
                . (string) StockAvailable::addSqlShopRestriction(null, $shop, 'sa') . ' LIMIT 1)';

        $rows = $db->executeS(
            'SELECT p.`id_product`, p.`reference`,
                    (SELECT pl.`name` FROM `' . _DB_PREFIX_ . 'product_lang` pl
                        WHERE pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . (int) $idLang . ' AND pl.`id_shop` = ' . $idShop . ') AS `name`,
                    SUM(od.`product_quantity`) AS `quantity_sold`,
                    AVG(od.`unit_price_tax_excl` / o.`conversion_rate`) AS `average_price_tax_excl`,
                    SUM(od.`unit_price_tax_excl` * od.`product_quantity` / o.`conversion_rate`) AS `revenue_tax_excl`,
                    IF(' . $days . ' > 0, SUM(od.`product_quantity`) / ' . $days . ', 0) AS `quantity_sold_per_day`,
                    ' . $pageViews . ' AS `page_views`,
                    ' . $stock . ' AS `quantity`,
                    ps.`active`'
                . $from . '
             GROUP BY p.`id_product`, p.`reference`, ps.`date_add`, ps.`active`
             ORDER BY ' . self::SORTS[$sort] . ' DESC, p.`id_product` ASC
             LIMIT ' . (int) $offset . ', ' . (int) $limit
        ) ?: [];

        $products = [];
        foreach ($rows as $row) {
            $products[] = [
                'id_product' => (int) $row['id_product'],
                'reference' => (string) $row['reference'],
                'name' => (string) ($row['name'] ?? ''),
                'quantity_sold' => (int) $row['quantity_sold'],
                'average_price_tax_excl' => round((float) $row['average_price_tax_excl'], 2),
                'revenue_tax_excl' => round((float) $row['revenue_tax_excl'], 2),
                'quantity_sold_per_day' => round((float) $row['quantity_sold_per_day'], 2),
                'page_views' => (int) $row['page_views'],
                'quantity' => (int) ($row['quantity'] ?? 0),
                'active' => (bool) $row['active'],
            ];
        }

        return SomcpTool::json([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'currency' => (string) $currency->iso_code,
            'language' => $iso,
            'products' => $products,
            'total_count' => $total,
            'offset' => $offset,
        ]);
    }
}
