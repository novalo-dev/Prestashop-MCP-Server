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
 * somcp_search_orders - the way into the orders of a shop.
 *
 * A list, deliberately: reference, who placed it, how much, how they paid and
 * where it stands. Everything else is one call away with somcp_get_order, and
 * sending it here would mean shipping the address book of a shop to an
 * assistant's vendor every time someone asks what came in yesterday.
 *
 * Read through a query rather than through Order objects: a page of twenty
 * orders would otherwise load twenty orders, their customers and their
 * addresses, and a merchant asking for last month would wait for it. Nothing is
 * written here, so no hook is missed by going this way.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolSearchOrders
{
    /** @var int Orders returned when the caller asks for no particular number. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Most a single call will return, whatever it asks for. */
    private const MAX_LIMIT = 100;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_search_orders',
            Somcp::t('Search orders'),
            'Finds orders of this shop by reference, customer, date, status, amount or payment '
                . 'method, delivery country, company or whether it was the customer\'s first order, and '
                . 'returns a short line for each: reference, customer, company, phone, delivery country, '
                . 'amount paid tax included, payment method, current status and whether the customer was '
                . 'new. Use it to answer questions about '
                . 'several orders, or to find the reference of one. '
                . 'Do not use it to read a single order you already identified - somcp_get_order '
                . 'returns everything about it. Do not use it for carts that were never paid for, '
                . 'which are not orders. Status identifiers differ from one shop to the next, so '
                . 'do not guess one.',
            [
                'type' => 'object',
                'properties' => [
                    'id_order' => [
                        'type' => 'integer',
                        'description' => 'Exact order identifier.',
                    ],
                    'reference' => [
                        'type' => 'string',
                        'description' => 'Order reference, whole or partial, for example XKBKNABJ.',
                    ],
                    'customer_name' => [
                        'type' => 'string',
                        'description' => 'First name, last name, or both, of the customer who placed the order.',
                    ],
                    'customer_email' => [
                        'type' => 'string',
                        'description' => 'Email address of the customer. More reliable than the name.',
                    ],
                    'date_from' => [
                        'type' => 'string',
                        'description' => 'Earliest order date, YYYY-MM-DD. Given alone, covers that whole day onwards.',
                    ],
                    'date_to' => [
                        'type' => 'string',
                        'description' => 'Latest order date, YYYY-MM-DD, that day included.',
                    ],
                    'status_id' => [
                        'type' => 'integer',
                        'description' => 'Current order status. Identifiers are specific to this shop.',
                    ],
                    'total_min' => [
                        'type' => 'number',
                        'description' => 'Smallest amount paid, tax included.',
                    ],
                    'total_max' => [
                        'type' => 'number',
                        'description' => 'Largest amount paid, tax included.',
                    ],
                    'payment_method' => [
                        'type' => 'string',
                        'description' => 'Payment method as the shop records it, whole or partial.',
                    ],
                    'new_client' => [
                        'type' => 'boolean',
                        'description' => 'True for orders placed by a customer with no earlier order in any '
                            . 'status, false for returning customers. The New client column of the back office.',
                    ],
                    'delivery_country' => [
                        'type' => 'string',
                        'description' => 'ISO code of the country of the delivery address, for example FR.',
                    ],
                    'company' => [
                        'type' => 'string',
                        'description' => 'Company of the customer, whole or partial.',
                    ],
                    'only_valid' => [
                        'type' => 'boolean',
                        'description' => 'Keep only orders the shop considers valid, meaning paid for.',
                    ],
                    'sort' => [
                        'type' => 'string',
                        'enum' => ['date_desc', 'date_asc', 'total_desc', 'total_asc'],
                        'description' => 'Order of the results. Most recent first by default.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many orders to return, 20 by default and 100 at most.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'How many to skip, to read the next page.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_READ],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminOrders')
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
        $idLang = (int) Somcp::legacyContext()->language->id;
        $idCountry = 0;
        if (isset($arguments['delivery_country']) && trim((string) $arguments['delivery_country']) !== '') {
            $iso = trim((string) $arguments['delivery_country']);
            // Country::getByIso() stops the request on a malformed code
            // rather than answering false, so the code is checked first.
            $idCountry = Validate::isLanguageIsoCode($iso) ? (int) Country::getByIso($iso) : 0;
            if ($idCountry <= 0) {
                return SomcpTool::text('No country has this ISO code: give delivery_country as a code such as FR.', true);
            }
        }
        $where = self::conditions($arguments, $idShop, $idCountry);

        // The count joins the same tables as the list, so a filter on the
        // delivery country or the company counts what the list shows.
        $from = '`' . _DB_PREFIX_ . 'orders` o
             LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = o.`id_customer`
             LEFT JOIN `' . _DB_PREFIX_ . 'address` a ON a.`id_address` = o.`id_address_delivery`';

        $total = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . $from . '
             WHERE ' . $where
        );

        $limit = self::limit($arguments);
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        $rows = Db::getInstance()->executeS(
            'SELECT o.`id_order`, o.`reference`, o.`date_add`, o.`payment`,
                    o.`total_paid_tax_incl`, o.`current_state`,
                    c.`firstname`, c.`lastname`, c.`company`,
                    a.`phone`, a.`phone_mobile`,
                    ctry.`iso_code` AS `country_iso`, ctryl.`name` AS `country_name`,
                    os.`color` AS `status_color`,
                    osl.`name` AS `status`,
                    cur.`iso_code` AS `currency`,
                    ' . self::newClientSql() . ' AS `new_client`
             FROM ' . $from . '
             LEFT JOIN `' . _DB_PREFIX_ . 'country` ctry ON ctry.`id_country` = a.`id_country`
             LEFT JOIN `' . _DB_PREFIX_ . 'country_lang` ctryl
                    ON ctryl.`id_country` = a.`id_country` AND ctryl.`id_lang` = ' . $idLang . '
             LEFT JOIN `' . _DB_PREFIX_ . 'order_state` os ON os.`id_order_state` = o.`current_state`
             LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl
                    ON osl.`id_order_state` = o.`current_state` AND osl.`id_lang` = ' . $idLang . '
             LEFT JOIN `' . _DB_PREFIX_ . 'currency` cur ON cur.`id_currency` = o.`id_currency`
             WHERE ' . $where . '
             ORDER BY ' . self::sort($arguments) . '
             LIMIT ' . $offset . ', ' . $limit
        ) ?: [];

        $orders = [];
        foreach ($rows as $row) {
            $orders[] = [
                'id_order' => (int) $row['id_order'],
                'reference' => (string) $row['reference'],
                'date' => (string) $row['date_add'],
                'customer_name' => trim((string) $row['firstname'] . ' ' . (string) $row['lastname']),
                'new_client' => (bool) $row['new_client'],
                // What the Company column shows, whether B2B is on or not:
                // the field exists either way and an empty one costs nothing.
                'company' => (string) $row['company'],
                // The delivery address is the one a merchant calls about a
                // parcel. The mobile number when there is one, since that is
                // what the carriers use.
                'phone' => (string) ($row['phone_mobile'] ?: $row['phone']),
                'delivery_country' => (string) $row['country_name'],
                'delivery_country_iso' => (string) $row['country_iso'],
                // The Total column of the back office, named so the assistant
                // never has to guess whether tax is in it.
                'total_paid_tax_incl' => (float) $row['total_paid_tax_incl'],
                'currency' => (string) $row['currency'],
                'payment_method' => (string) $row['payment'],
                'status' => (string) $row['status'],
                'status_id' => (int) $row['current_state'],
                'status_color' => (string) $row['status_color'],
            ];
        }

        return SomcpTool::json([
            'orders' => $orders,
            'returned' => count($orders),
            // So the assistant knows there is more instead of concluding from
            // one page that it has seen everything.
            'total_count' => $total,
            'offset' => $offset,
        ]);
    }

    /**
     * The WHERE clause, from the filters the caller gave.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param int $idCountry delivery country already resolved from its ISO code, 0 for any
     *
     * @return string
     */
    private static function conditions(array $arguments, int $idShop, int $idCountry): string
    {
        // The shop comes from the token, never from the caller: an assistant
        // connected to one shop has no business reading another's orders.
        $conditions = ['o.`id_shop` = ' . $idShop];

        if (!empty($arguments['id_order'])) {
            $conditions[] = 'o.`id_order` = ' . (int) $arguments['id_order'];
        }
        if (!empty($arguments['reference'])) {
            $conditions[] = "o.`reference` LIKE '%" . pSQL((string) $arguments['reference']) . "%'";
        }
        if (!empty($arguments['customer_email'])) {
            $conditions[] = "c.`email` = '" . pSQL((string) $arguments['customer_email']) . "'";
        }
        if (!empty($arguments['customer_name'])) {
            // Each word has to appear somewhere in the name, so "Jean Dupont"
            // finds the customer whichever way round the caller typed it.
            foreach (preg_split('/\s+/', trim((string) $arguments['customer_name'])) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }
                $escaped = pSQL($word);
                $conditions[] = "(c.`firstname` LIKE '%" . $escaped . "%' OR c.`lastname` LIKE '%" . $escaped . "%')";
            }
        }
        if (!empty($arguments['date_from']) && Validate::isDate((string) $arguments['date_from'])) {
            $conditions[] = "o.`date_add` >= '" . pSQL((string) $arguments['date_from']) . " 00:00:00'";
        }
        if (!empty($arguments['date_to']) && Validate::isDate((string) $arguments['date_to'])) {
            // The whole day, not up to midnight of it.
            $conditions[] = "o.`date_add` <= '" . pSQL((string) $arguments['date_to']) . " 23:59:59'";
        }
        if (!empty($arguments['status_id'])) {
            $conditions[] = 'o.`current_state` = ' . (int) $arguments['status_id'];
        }
        if (isset($arguments['total_min']) && is_numeric($arguments['total_min'])) {
            $conditions[] = 'o.`total_paid_tax_incl` >= ' . (float) $arguments['total_min'];
        }
        if (isset($arguments['total_max']) && is_numeric($arguments['total_max'])) {
            $conditions[] = 'o.`total_paid_tax_incl` <= ' . (float) $arguments['total_max'];
        }
        if (isset($arguments['new_client']) && is_bool($arguments['new_client'])) {
            $conditions[] = self::newClientSql() . ' = ' . ($arguments['new_client'] ? 1 : 0);
        }
        if ($idCountry > 0) {
            $conditions[] = 'a.`id_country` = ' . $idCountry;
        }
        if (!empty($arguments['company'])) {
            $conditions[] = "c.`company` LIKE '%" . pSQL((string) $arguments['company']) . "%'";
        }
        if (!empty($arguments['payment_method'])) {
            $conditions[] = "o.`payment` LIKE '%" . pSQL((string) $arguments['payment_method']) . "%'";
        }
        if (!empty($arguments['only_valid'])) {
            $conditions[] = 'o.`valid` = 1';
        }

        return implode(' AND ', $conditions);
    }

    /**
     * 1 when the customer had no earlier order, 0 otherwise.
     *
     * The subquery of the core's order grid (OrderQueryBuilder), any status and
     * any shop, so the answer matches the New client column on screen.
     *
     * @return string
     */
    private static function newClientSql(): string
    {
        return '(SELECT IF(COUNT(so.`id_order`) > 0, 0, 1) FROM `' . _DB_PREFIX_ . 'orders` so
                 WHERE so.`id_customer` = o.`id_customer` AND so.`id_order` < o.`id_order`)';
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return string
     */
    private static function sort(array $arguments): string
    {
        // An allowed list, not the caller's string: this goes into an ORDER BY.
        switch ((string) ($arguments['sort'] ?? '')) {
            case 'date_asc':
                return 'o.`date_add` ASC';
            case 'total_desc':
                return 'o.`total_paid_tax_incl` DESC';
            case 'total_asc':
                return 'o.`total_paid_tax_incl` ASC';
            default:
                return 'o.`date_add` DESC';
        }
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return int
     */
    private static function limit(array $arguments): int
    {
        $limit = (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT);

        return max(1, min(self::MAX_LIMIT, $limit));
    }
}
