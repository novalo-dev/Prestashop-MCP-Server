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
 * somcp_get_sales_summary - what the shop sold over a period.
 *
 * The figures are counted the way Stats > Stats Dashboard (statsforecast) and
 * Stats > Sales and orders (statssales) count them: valid orders dated by
 * their invoice date, tax excluded, divided by the conversion rate so every
 * amount is in the default currency. A merchant comparing the answer with
 * those two pages must find the same numbers, or they stop trusting both.
 *
 * The tables are read here rather than through the stats modules: the
 * figures must still come when those modules are disabled or uninstalled,
 * which many shops do to lighten the back office.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetSalesSummary
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_sales_summary',
            Somcp::t('Get a sales summary'),
            'Returns the sales of this shop over a period, counted as the Stats Dashboard and Sales and '
                . 'orders pages of the back office count them: valid orders only, dated by their invoice '
                . 'date, amounts tax excluded and converted to the shop\'s default currency (currency). '
                . 'revenue_tax_excl is Revenue on Stats Dashboard; sales_tax_excl is Sales on Sales and '
                . 'orders, that is the revenue minus the credit slips of those orders; revenue_tax_incl '
                . 'is the same revenue tax included. Also orders_count, products_bought, the average '
                . 'basket tax excluded and included, and new_customers (accounts created in the period). '
                . 'With compare_previous, previous holds the same figures for the period of the same '
                . 'length that ends the day before date_from. An empty period gives zeros. '
                . 'Do not use it to list the orders behind the figures - somcp_search_orders does, but '
                . 'it filters on the date the order was placed, not the invoice date, so a few orders '
                . 'can differ at the edges of a period. Do not use it for the products that sold most.',
            [
                'type' => 'object',
                'properties' => [
                    'date_from' => [
                        'type' => 'string',
                        'description' => 'First day of the period, YYYY-MM-DD, that day included, in the shop\'s time zone.',
                    ],
                    'date_to' => [
                        'type' => 'string',
                        'description' => 'Last day of the period, YYYY-MM-DD, that day included. Not before date_from.',
                    ],
                    'compare_previous' => [
                        'type' => 'boolean',
                        'description' => 'Also return, in previous, the same figures for the period of the same length that ends the day before date_from.',
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
        $dateFrom = self::date($arguments['date_from'] ?? null);
        if ($dateFrom === null) {
            return SomcpTool::text('date_from: a date, YYYY-MM-DD.', true);
        }
        $dateTo = self::date($arguments['date_to'] ?? null);
        if ($dateTo === null) {
            return SomcpTool::text('date_to: a date, YYYY-MM-DD.', true);
        }
        if ($dateTo < $dateFrom) {
            return SomcpTool::text('date_to: a date not before date_from.', true);
        }

        // The shop comes from the token, never from the caller.
        $idShop = Somcp::currentShopId();
        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop));

        $summary = ['date_from' => $dateFrom, 'date_to' => $dateTo, 'currency' => (string) $currency->iso_code]
            + self::figures($dateFrom, $dateTo, $idShop);

        if (!empty($arguments['compare_previous'])) {
            // Same number of days, ending the day before the period asked,
            // so a month is compared with the days just before it.
            $days = (int) (new DateTime($dateFrom))->diff(new DateTime($dateTo))->days;
            $previousTo = (new DateTime($dateFrom))->modify('-1 day');
            $previousFrom = (clone $previousTo)->modify('-' . $days . ' day');
            $summary['previous'] = [
                'date_from' => $previousFrom->format('Y-m-d'),
                'date_to' => $previousTo->format('Y-m-d'),
                'currency' => (string) $currency->iso_code,
            ] + self::figures($previousFrom->format('Y-m-d'), $previousTo->format('Y-m-d'), $idShop);
        }

        return SomcpTool::json($summary);
    }

    /**
     * A calendar date, or null when the value is not one.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private static function date($value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            return null;
        }

        return $value;
    }

    /**
     * The figures of one period.
     *
     * @param string $dateFrom YYYY-MM-DD, already checked
     * @param string $dateTo YYYY-MM-DD, already checked
     * @param int $idShop
     *
     * @return array<string, int|float>
     */
    private static function figures(string $dateFrom, string $dateTo, int $idShop): array
    {
        $db = Db::getInstance();
        // Whole days: an invoice issued at 18:00 on the last day counts.
        $between = "'" . pSQL($dateFrom) . " 00:00:00' AND '" . pSQL($dateTo) . " 23:59:59'";
        $orders = 'o.`valid` = 1 AND o.`id_shop` = ' . (int) $idShop . ' AND o.`invoice_date` BETWEEN ' . $between;

        // Revenue and Placed orders of Stats Dashboard.
        $totals = $db->getRow(
            'SELECT COUNT(*) AS `orders_count`,
                    IFNULL(SUM(o.`total_paid_tax_excl` / o.`conversion_rate`), 0) AS `revenue_tax_excl`,
                    IFNULL(SUM(o.`total_paid_tax_incl` / o.`conversion_rate`), 0) AS `revenue_tax_incl`
             FROM `' . _DB_PREFIX_ . 'orders` o
             WHERE ' . $orders
        ) ?: [];

        // Bought items: the quantities of the lines of those orders.
        $products = (int) $db->getValue(
            'SELECT IFNULL(SUM(od.`product_quantity`), 0)
             FROM `' . _DB_PREFIX_ . 'order_detail` od
             INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = od.`id_order`
             WHERE ' . $orders
        );

        // Sales of Sales and orders: that page only keeps orders in a status
        // counted as validated (logable) and takes off their credit slips,
        // with the same formula, so the two figures match on the same dates.
        // The slips are summed apart from the orders so that an order with
        // two slips is not counted twice.
        $logable = 'FROM `' . _DB_PREFIX_ . 'orders` o
             INNER JOIN `' . _DB_PREFIX_ . 'order_state` os ON os.`id_order_state` = o.`current_state`
             WHERE ' . $orders . ' AND os.`logable` = 1';
        $logableRevenue = (float) $db->getValue(
            'SELECT IFNULL(SUM(o.`total_paid_tax_excl` / o.`conversion_rate`), 0) ' . $logable
        );
        $slips = (float) $db->getValue(
            'SELECT IFNULL(SUM((sl.`total_products_tax_excl` - sl.`total_shipping_tax_excl`) / sl.`conversion_rate`), 0)
             FROM `' . _DB_PREFIX_ . 'order_slip` sl
             WHERE sl.`id_order` IN (SELECT o.`id_order` ' . $logable . ')'
        );

        // Registrations: accounts created in the period, guests included as
        // the dashboard counts them.
        $newCustomers = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer` c
             WHERE c.`id_shop` = ' . (int) $idShop . ' AND c.`date_add` BETWEEN ' . $between
        );

        $count = (int) ($totals['orders_count'] ?? 0);
        $revenueExcl = (float) ($totals['revenue_tax_excl'] ?? 0);
        $revenueIncl = (float) ($totals['revenue_tax_incl'] ?? 0);

        return [
            'revenue_tax_excl' => round($revenueExcl, 2),
            'sales_tax_excl' => round($logableRevenue - $slips, 2),
            'revenue_tax_incl' => round($revenueIncl, 2),
            'orders_count' => $count,
            'products_bought' => $products,
            'average_basket_tax_excl' => $count > 0 ? round($revenueExcl / $count, 2) : 0,
            'average_basket_tax_incl' => $count > 0 ? round($revenueIncl / $count, 2) : 0,
            'new_customers' => $newCustomers,
        ];
    }
}
