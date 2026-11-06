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
 * SomcpStats - the SQL fragments the statistics tools share.
 *
 * Several stats tools count a product's page views and read its stock the
 * exact same way the back office does. The queries used to live inside
 * somcp_list_best_sellers; they are here so every tool that needs them calls
 * one function rather than keeping its own copy, which is how the same figure
 * ends up computed two different ways.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpStats
{
    /**
     * The product page views statsdata recorded inside a period.
     *
     * statsdata stores page views per saved day range; the back office keeps
     * the ranges that lie inside the period, both ends included, and sums
     * their counters. $start and $end are SQL date-time literals already
     * quoted by the caller (for example "'2026-01-01 00:00:00'"). The product
     * column is an outer-query reference such as p.`id_product`, so the
     * subquery stays correlated to the row being read.
     *
     * @param int $idShop shop the views are counted for
     * @param string $start quoted start literal, inclusive
     * @param string $end quoted end literal, inclusive
     * @param string $productColumn outer reference to the product id column
     *
     * @return string a scalar subquery, parentheses included
     */
    public static function pageViewsSubquery(int $idShop, string $start, string $end, string $productColumn): string
    {
        return '(SELECT IFNULL(SUM(pv.`counter`), 0)
                FROM `' . _DB_PREFIX_ . 'page` pa
                INNER JOIN `' . _DB_PREFIX_ . 'page_type` pt ON pt.`id_page_type` = pa.`id_page_type`
                INNER JOIN `' . _DB_PREFIX_ . 'page_viewed` pv ON pv.`id_page` = pa.`id_page` AND pv.`id_shop` = ' . $idShop . '
                INNER JOIN `' . _DB_PREFIX_ . 'date_range` dr ON dr.`id_date_range` = pv.`id_date_range`
                WHERE pa.`id_object` = ' . $productColumn . ' AND pt.`name` = \'product\'
                    AND dr.`time_start` BETWEEN ' . $start . ' AND ' . $end . '
                    AND dr.`time_end` BETWEEN ' . $start . ' AND ' . $end . ')';
    }

    /**
     * The quantity available for sale of a product, shop sharing applied.
     *
     * The total sits on the stock_available row without a combination; whether
     * it is read for the shop or for its group is decided by the core, through
     * addSqlShopRestriction, exactly as the back office reads it. The product
     * column is an outer-query reference such as p.`id_product`.
     *
     * @param Shop $shop shop the stock is read for
     * @param string $productColumn outer reference to the product id column
     *
     * @return string a scalar subquery, parentheses included
     */
    public static function productStockSubquery(Shop $shop, string $productColumn): string
    {
        return '(SELECT sa.`quantity` FROM `' . _DB_PREFIX_ . 'stock_available` sa
                WHERE sa.`id_product` = ' . $productColumn . ' AND sa.`id_product_attribute` = 0'
                . (string) StockAvailable::addSqlShopRestriction(null, $shop, 'sa') . ' LIMIT 1)';
    }

    /**
     * Whether a category with this id exists in the shop.
     *
     * The stats tools that filter on Choose a category refuse an identifier the
     * shop does not have rather than answer with an empty list, which would read
     * as "nothing in that category".
     *
     * @param int $idCategory
     * @param int $idShop
     *
     * @return bool
     */
    public static function categoryInShop(int $idCategory, int $idShop): bool
    {
        // No LIMIT here: getValue() appends its own, and a second one is a
        // syntax error.
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'category_shop` cs
             WHERE cs.`id_category` = ' . (int) $idCategory . ' AND cs.`id_shop` = ' . (int) $idShop
        );
    }
}
