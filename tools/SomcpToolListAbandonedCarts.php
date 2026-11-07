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
 * somcp_list_abandoned_carts - the carts that never became an order.
 *
 * The rule is the back office's, so the answer matches the Abandoned cart
 * rows of Orders > Shopping Carts: no order, created more than 24 hours ago
 * (AdminCartsController on 8.0, CartQueryBuilder on 9.0, both on
 * cart.date_add). A younger cart without an order is still being filled.
 *
 * The rows are found with one query; the amounts are then asked of each cart
 * itself, because no table stores a cart's total and only the cart knows its
 * prices, specific prices and taxes. Nothing is written, and the Share this
 * cart link of 9.0, which opens the cart to whoever holds it, never leaves
 * the shop.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListAbandonedCarts
{
    /** @var int Carts returned when the caller asks for no particular number. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Most a single call will return. */
    private const MAX_LIMIT = 100;

    /** @var int The back office's age for a cart without an order to count as abandoned. */
    private const DEFAULT_HOURS = 24;

    /** @var int Seconds a guest counts as online after a connection, as the Online column. */
    private const ONLINE_SECONDS = 1800;

    /** @var int Product lines returned per cart: enough to say what was left behind. */
    private const TOP_PRODUCTS = 3;

    /**
     * Carts priced for the total value. Each one is priced by the cart itself,
     * so a shop with years of carts left behind would otherwise keep the call
     * waiting; past this, the answer says the total covers the newest only.
     *
     * @var int
     */
    private const MAX_VALUED = 1000;

    /** @var array<int, string> */
    private const FIELDS = ['older_than_hours', 'date_from', 'date_to', 'with_customer', 'customer_name', 'online', 'language', 'limit', 'offset'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_abandoned_carts',
            Somcp::t('List abandoned carts'),
            'Lists the carts of this shop that hold products and never became an order, newest first, as the '
                . 'Abandoned cart rows of Orders > Shopping Carts: a cart counts as abandoned when it has no order '
                . 'and was created more than 24 hours ago. Gives the customer, whether they are online, the carrier, '
                . 'the products total tax excluded and included, and the three most expensive lines. Read only: '
                . 'nobody is contacted. For placed orders use somcp_search_orders, for one customer\'s history '
                . 'somcp_get_customer, for sales figures somcp_get_sales_summary.',
            [
                'type' => 'object',
                'properties' => [
                    'older_than_hours' => [
                        'type' => 'integer',
                        'description' => 'Age a cart without an order must have, in hours since it was created. '
                            . '24 by default, the back office rule; 1 at least.',
                    ],
                    'date_from' => [
                        'type' => 'string',
                        'description' => 'Earliest creation date of the cart (the Date column), YYYY-MM-DD.',
                    ],
                    'date_to' => [
                        'type' => 'string',
                        'description' => 'Latest creation date of the cart, YYYY-MM-DD, that day included. Not before date_from.',
                    ],
                    'with_customer' => [
                        'type' => 'boolean',
                        'description' => 'True for the carts linked to a customer account, guest accounts included; '
                            . 'false for the carts without one.',
                    ],
                    'customer_name' => [
                        'type' => 'string',
                        'description' => 'First name, last name, or both, of the customer, whole or partial.',
                    ],
                    'online' => [
                        'type' => 'boolean',
                        'description' => 'True for the carts whose visitor was on the shop in the last 30 minutes '
                            . '(the Online column), false for the others.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for the product names. '
                            . 'The shop default when left out.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many carts to return, 20 by default and 100 at most.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'How many to skip, to read the next page.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::SALES_READ],
            [self::class, 'handle'],
            // The list and the cart page open with view: nothing here needs
            // the edit right of Orders > Shopping Carts.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminCarts')
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

        // Every mistake is reported in one answer, so the call is corrected
        // in one go (the shape of #169).
        $problems = [];
        foreach (array_keys($arguments) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                $problems[] = '- ' . $field . ': not a field of this tool';
            }
        }

        $hours = self::DEFAULT_HOURS;
        if (array_key_exists('older_than_hours', $arguments) && $arguments['older_than_hours'] !== null) {
            if (!is_int($arguments['older_than_hours']) || $arguments['older_than_hours'] < 1) {
                $problems[] = '- older_than_hours: a whole number of hours, 1 at least';
            } else {
                $hours = (int) $arguments['older_than_hours'];
            }
        }

        $dateFrom = self::date($arguments, 'date_from', $problems);
        $dateTo = self::date($arguments, 'date_to', $problems);
        if ($dateFrom !== null && $dateTo !== null && $dateTo < $dateFrom) {
            $problems[] = '- date_to: a date not before date_from';
        }

        foreach (['with_customer', 'online'] as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== null && !is_bool($arguments[$field])) {
                $problems[] = '- ' . $field . ': true or false';
            }
        }
        if (array_key_exists('customer_name', $arguments) && $arguments['customer_name'] !== null
            && !is_string($arguments['customer_name'])) {
            $problems[] = '- customer_name: a text';
        }

        $limit = self::DEFAULT_LIMIT;
        if (array_key_exists('limit', $arguments) && $arguments['limit'] !== null) {
            if (!is_int($arguments['limit']) || $arguments['limit'] < 1 || $arguments['limit'] > self::MAX_LIMIT) {
                $problems[] = '- limit: a whole number from 1 to ' . self::MAX_LIMIT;
            } else {
                // Cast although checked: the value goes into the LIMIT clause,
                // where only a number may ever land.
                $limit = (int) $arguments['limit'];
            }
        }
        $offset = 0;
        if (array_key_exists('offset', $arguments) && $arguments['offset'] !== null) {
            if (!is_int($arguments['offset']) || $arguments['offset'] < 0) {
                $problems[] = '- offset: a whole number, 0 at least';
            } else {
                $offset = (int) $arguments['offset'];
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

        $where = self::conditions($arguments, $idShop, $hours, $dateFrom, $dateTo);
        $from = '`' . _DB_PREFIX_ . 'cart` a
             LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = a.`id_customer`';
        $db = Db::getInstance();

        $total = (int) $db->getValue('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where);

        $rows = $db->executeS(
            'SELECT a.`id_cart`, a.`date_add`, a.`date_upd`, a.`id_customer`,
                    c.`firstname`, c.`lastname`, c.`is_guest`,
                    ca.`name` AS `carrier`, cu.`iso_code` AS `currency`,
                    ' . self::onlineSql() . ' AS `online`,
                    (SELECT SUM(cp.`quantity`) FROM `' . _DB_PREFIX_ . 'cart_product` cp
                     WHERE cp.`id_cart` = a.`id_cart`) AS `products_count`
             FROM ' . $from . '
             LEFT JOIN `' . _DB_PREFIX_ . 'carrier` ca ON ca.`id_carrier` = a.`id_carrier`
             LEFT JOIN `' . _DB_PREFIX_ . 'currency` cu ON cu.`id_currency` = a.`id_currency`
             WHERE ' . $where . '
             ORDER BY a.`date_add` DESC, a.`id_cart` DESC
             LIMIT ' . $offset . ', ' . $limit
        ) ?: [];

        $carts = [];
        foreach ($rows as $row) {
            $idCart = (int) $row['id_cart'];
            [$totalExcl, $totalIncl, $products] = self::price($idCart, $idLang, true);
            $carts[] = [
                'id_cart' => $idCart,
                'date_add' => (string) $row['date_add'],
                'date_upd' => (string) $row['date_upd'],
                'id_customer' => (int) $row['id_customer'],
                // The name only: email, phone and address stay with
                // somcp_get_customer, under the customers scope.
                'customer_name' => trim((string) $row['firstname'] . ' ' . (string) $row['lastname']),
                'is_guest' => (bool) $row['is_guest'],
                // A visitor still on the site is not one to chase yet.
                'online' => (bool) $row['online'],
                'carrier' => $row['carrier'] === null ? '' : SomcpTool::carrierName((string) $row['carrier']),
                'products_count' => (int) $row['products_count'],
                // The Total column, products only: named both ways so the
                // assistant never guesses whether tax is in it.
                'total_products_tax_excl' => $totalExcl,
                'total_products_tax_incl' => $totalIncl,
                'currency' => (string) $row['currency'],
                'products' => $products,
            ];
        }

        [$value, $complete] = self::totalValue($where, $from, $idLang, $total);

        return SomcpTool::json([
            'carts' => $carts,
            'returned' => count($carts),
            'total_count' => $total,
            'offset' => $offset,
            'older_than_hours' => $hours,
            // An object even when empty, so the field keeps one shape.
            'total_value_tax_incl' => $value === [] ? new stdClass() : $value,
            'total_value_complete' => $complete,
            'language' => $iso,
        ]);
    }

    /**
     * The WHERE clause: the back office's abandoned carts of this shop, then
     * the filters the caller gave.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param int $hours
     * @param string|null $dateFrom
     * @param string|null $dateTo
     *
     * @return string
     */
    private static function conditions(array $arguments, int $idShop, int $hours, ?string $dateFrom, ?string $dateTo): string
    {
        // The core compares the creation date with PHP's clock, not the
        // database's: the same clock here gives the same rows.
        $before = date('Y-m-d H:i:s', time() - $hours * 3600);

        // The shop comes from the token: carts are per shop.
        $conditions = [
            'a.`id_shop` = ' . $idShop,
            "a.`date_add` < '" . pSQL($before) . "'",
            'NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'orders` o WHERE o.`id_cart` = a.`id_cart`)',
            // The back office also lists empty carts; there is nothing to
            // recover in them.
            'EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'cart_product` cp WHERE cp.`id_cart` = a.`id_cart`)',
        ];

        if ($dateFrom !== null) {
            $conditions[] = "a.`date_add` >= '" . pSQL($dateFrom) . " 00:00:00'";
        }
        if ($dateTo !== null) {
            // The whole day, not up to midnight of it.
            $conditions[] = "a.`date_add` <= '" . pSQL($dateTo) . " 23:59:59'";
        }
        if (isset($arguments['with_customer']) && is_bool($arguments['with_customer'])) {
            $conditions[] = $arguments['with_customer'] ? 'a.`id_customer` > 0' : 'a.`id_customer` = 0';
        }
        if (isset($arguments['customer_name']) && is_string($arguments['customer_name'])) {
            // Each word has to appear somewhere in the name, as in
            // somcp_search_orders, whichever way round it was typed.
            foreach (preg_split('/\s+/', trim($arguments['customer_name'])) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }
                $escaped = pSQL($word);
                $conditions[] = "(c.`firstname` LIKE '%" . $escaped . "%' OR c.`lastname` LIKE '%" . $escaped . "%')";
            }
        }
        if (isset($arguments['online']) && is_bool($arguments['online'])) {
            $conditions[] = self::onlineSql() . ' = ' . ($arguments['online'] ? 1 : 0);
        }

        return implode(' AND ', $conditions);
    }

    /**
     * 1 when the cart's visitor had a connection in the last 30 minutes, as
     * the Online column of the back office computes it.
     *
     * @return string
     */
    private static function onlineSql(): string
    {
        $since = date('Y-m-d H:i:s', time() - self::ONLINE_SECONDS);

        return '(SELECT IF(COUNT(*) > 0, 1, 0) FROM `' . _DB_PREFIX_ . 'connections` co
                 WHERE co.`id_guest` = a.`id_guest` AND a.`id_guest` > 0 AND co.`date_add` > \'' . pSQL($since) . '\')';
    }

    /**
     * A date argument, null when left out; a value given but not a date is
     * refused rather than ignored, or the list would silently cover more.
     *
     * @param array<string, mixed> $arguments
     * @param string $field
     * @param array<int, string> $problems
     *
     * @return string|null
     */
    private static function date(array $arguments, string $field, array &$problems): ?string
    {
        if (!array_key_exists($field, $arguments) || $arguments[$field] === null || $arguments[$field] === '') {
            return null;
        }
        $day = SomcpTool::day($arguments[$field]);
        if ($day === null) {
            $problems[] = '- ' . $field . ': a date, YYYY-MM-DD';
        }

        return $day;
    }

    /**
     * The products total of a cart tax excluded and included, and its most
     * expensive lines, asked of the cart itself so prices and taxes follow
     * the shop's rules. Null amounts when the cart cannot be priced any more
     * (a product removed since), so one old cart does not hide the others.
     *
     * @param int $idCart
     * @param int $idLang language of the product names
     * @param bool $withLines
     *
     * @return array{0: float|null, 1: float|null, 2: array<int, array<string, mixed>>}
     */
    private static function price(int $idCart, int $idLang, bool $withLines): array
    {
        $cart = new Cart($idCart);
        if (!Validate::isLoadedObject($cart)) {
            return [null, null, []];
        }
        $excl = null;
        $incl = null;
        $lines = [];
        try {
            $excl = round((float) $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS), 2);
            $incl = round((float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS), 2);
            if ($withLines) {
                // The names in the language asked, not the one the visitor
                // shopped in; the cart is never saved.
                $cart->id_lang = $idLang;
                $products = $cart->getProducts(true);
                usort($products, static function (array $a, array $b): int {
                    return (float) ($b['total_wt'] ?? 0) <=> (float) ($a['total_wt'] ?? 0);
                });
                foreach (array_slice($products, 0, self::TOP_PRODUCTS) as $product) {
                    $name = (string) ($product['name'] ?? '');
                    if (!empty($product['attributes'])) {
                        $name .= ' - ' . (string) $product['attributes'];
                    }
                    $lines[] = [
                        'id_product' => (int) $product['id_product'],
                        'id_product_attribute' => (int) $product['id_product_attribute'],
                        'name' => $name,
                        'reference' => (string) ($product['reference'] ?? ''),
                        'quantity' => (int) ($product['cart_quantity'] ?? 0),
                        'unit_price_tax_incl' => round((float) ($product['price_wt'] ?? 0), 2),
                    ];
                }
            }
        } catch (Throwable $e) {
            return [$excl, $incl, $lines];
        }

        return [$excl, $incl, $lines];
    }

    /**
     * The products total tax included of every matching cart, not only this
     * page, keyed by currency ISO code: carts in different currencies are
     * never added together, as no rate would be right for all of them.
     *
     * @param string $where
     * @param string $from
     * @param int $idLang
     * @param int $total number of matching carts
     *
     * @return array{0: array<string, float>, 1: bool}
     */
    private static function totalValue(string $where, string $from, int $idLang, int $total): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT a.`id_cart`, cu.`iso_code` AS `currency`
             FROM ' . $from . '
             LEFT JOIN `' . _DB_PREFIX_ . 'currency` cu ON cu.`id_currency` = a.`id_currency`
             WHERE ' . $where . '
             ORDER BY a.`date_add` DESC, a.`id_cart` DESC
             LIMIT ' . self::MAX_VALUED
        ) ?: [];

        $value = [];
        foreach ($rows as $row) {
            $incl = self::price((int) $row['id_cart'], $idLang, false)[1];
            if ($incl === null) {
                continue;
            }
            $currency = (string) $row['currency'];
            $value[$currency] = round(($value[$currency] ?? 0.0) + $incl, 2);
        }

        return [$value, $total <= self::MAX_VALUED];
    }
}
