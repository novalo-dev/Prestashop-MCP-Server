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
 * somcp_list_unsold_products - the active products no valid order bought.
 *
 * The Products never purchased list of Stats > Catalog statistics, with the
 * stock and what it is worth, which the screen does not show. Like the screen,
 * no period counts every valid order ever; a period narrows it to the products
 * that did not sell in it, leaving out those added to the shop after its start,
 * which could not sell over the whole period.
 *
 * The screen counts the orders of every shop; this reads the token's shop
 * only, so the figures differ in a multistore install alone.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListUnsoldProducts
{
    /** @var int Products returned when the caller asks for no particular number. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Most a single call will return. */
    private const MAX_LIMIT = 100;

    /** @var int Stock that does not move is the point: a product out of stock is left out by default. */
    private const DEFAULT_MIN_QUANTITY = 1;

    /** @var array<int, string> */
    private const FIELDS = ['date_from', 'date_to', 'id_category', 'min_quantity', 'language', 'limit', 'offset'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_unsold_products',
            Somcp::t('List products never purchased'),
            'Lists the active products no valid order bought, with their stock and its value at cost price, '
                . 'the costliest stock first: Products never purchased of Stats > Catalog statistics. The '
                . 'period is optional: left out, never bought at all, as the screen; given, not bought in it. '
                . 'Orders of this shop only. Read only. Low stock is somcp_list_low_stock, products seen but '
                . 'not bought somcp_list_viewed_not_sold, best sellers somcp_list_best_sellers.',
            [
                'type' => 'object',
                'properties' => [
                    'date_from' => [
                        'type' => 'string',
                        'description' => 'First day of the period, YYYY-MM-DD, that day included. With date_to, '
                            . 'or neither: no period means no valid order ever.',
                    ],
                    'date_to' => [
                        'type' => 'string',
                        'description' => 'Last day of the period, YYYY-MM-DD, that day included. Not before date_from.',
                    ],
                    'id_category' => [
                        'type' => 'integer',
                        'description' => 'Only the products of that category (Choose a category), from '
                            . 'somcp_list_categories. Every category when left out.',
                    ],
                    'min_quantity' => [
                        'type' => 'integer',
                        'description' => 'Fewest units available for sale a product needs to be listed: 1 by '
                            . 'default, so the stock that does not move; 0 lists every product, as the screen.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, for '
                            . 'the product names; a code the shop does not have is refused. The shop default '
                            . 'language when left out.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many products to return, 20 by default, 100 at most.',
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
        // The shop comes from the token, never from the caller: products,
        // orders and stock are all read for it.
        $shop = Somcp::currentShop();
        $idShop = (int) $shop->id;

        // Every mistake is reported in one answer, so the call is corrected
        // in one go (the shape of #169).
        $problems = [];
        foreach (array_keys($arguments) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                $problems[] = '- ' . $field . ': not a field of this tool';
            }
        }

        // The period is optional, but half a period is a mistake rather than
        // an open end: the texts of somcp_list_best_sellers for the rest.
        $hasFrom = isset($arguments['date_from']);
        $hasTo = isset($arguments['date_to']);
        $dateFrom = null;
        $dateTo = null;
        if ($hasFrom || $hasTo) {
            $dateFrom = SomcpTool::day($arguments['date_from'] ?? null);
            if ($dateFrom === null) {
                $problems[] = $hasFrom
                    ? '- date_from: a date, YYYY-MM-DD'
                    : '- date_from: a date, YYYY-MM-DD, given with date_to or neither';
            }
            $dateTo = SomcpTool::day($arguments['date_to'] ?? null);
            if ($dateTo === null) {
                $problems[] = $hasTo
                    ? '- date_to: a date, YYYY-MM-DD'
                    : '- date_to: a date, YYYY-MM-DD, given with date_from or neither';
            }
            if ($dateFrom !== null && $dateTo !== null && $dateTo < $dateFrom) {
                $problems[] = '- date_to: a date not before date_from';
            }
        }

        $idCategory = 0;
        if (array_key_exists('id_category', $arguments) && $arguments['id_category'] !== null) {
            // A cast would read an array as 1 and filter on a category nobody
            // asked for: only a whole number is an identifier.
            $value = $arguments['id_category'];
            if (!is_int($value) || $value < 1) {
                $problems[] = '- id_category: a whole number, the identifier somcp_list_categories gives';
            } elseif (!SomcpStats::categoryInShop((int) $value, $idShop)) {
                $problems[] = '- id_category: no category of this shop has this identifier, find it with somcp_list_categories';
            } else {
                $idCategory = (int) $value;
            }
        }

        $minQuantity = self::DEFAULT_MIN_QUANTITY;
        if (array_key_exists('min_quantity', $arguments) && $arguments['min_quantity'] !== null) {
            if (!is_int($arguments['min_quantity']) || $arguments['min_quantity'] < 0) {
                $problems[] = '- min_quantity: a whole number, 0 at least';
            } else {
                $minQuantity = (int) $arguments['min_quantity'];
            }
        }

        $limit = self::DEFAULT_LIMIT;
        if (array_key_exists('limit', $arguments) && $arguments['limit'] !== null) {
            if (!is_int($arguments['limit']) || $arguments['limit'] < 1 || $arguments['limit'] > self::MAX_LIMIT) {
                $problems[] = '- limit: a whole number from 1 to ' . self::MAX_LIMIT;
            } else {
                // Cast although checked: the value goes into the LIMIT clause.
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

        if ($problems !== []) {
            return SomcpTool::text(
                "Nothing was read. Correct these and call again:\n" . implode("\n", array_unique($problems)),
                true
            );
        }

        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;

        // Amounts are rounded the way the shop's default currency is shown.
        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop));
        $precision = Validate::isLoadedObject($currency) ? (int) $currency->precision : 2;

        $db = Db::getInstance();
        $period = $dateFrom !== null && $dateTo !== null;
        // Whole days: an order placed at 18:00 on the last day counts.
        $start = $period ? "'" . pSQL((string) $dateFrom) . " 00:00:00'" : '';
        $end = $period ? "'" . pSQL((string) $dateTo) . " 23:59:59'" : '';

        $stock = SomcpStats::productStockSubquery($shop, 'p.`id_product`');

        // The products of the shop the screen counts as available: active and
        // saved, in the category asked for. A product without a stock row has
        // nothing for sale, hence the 0.
        $available = 'SELECT p.`id_product`, p.`reference`, ps.`date_add`,
                    ps.`price`, ps.`wholesale_price`,
                    IFNULL(' . $stock . ', 0) AS `quantity`
                FROM `' . _DB_PREFIX_ . 'product` p
                INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
                WHERE ps.`active` = 1 AND p.`state` = ' . (int) Product::STATE_SAVED
                . ($idCategory > 0
                    ? ' AND p.`id_product` IN (SELECT cp.`id_product` FROM `' . _DB_PREFIX_ . 'category_product` cp WHERE cp.`id_category` = ' . $idCategory . ')'
                    : '');
        // min_quantity 0 keeps every product, a negative stock included, as
        // the screen has no stock condition at all.
        $kept = 'SELECT a.* FROM (' . $available . ') a'
            . ($minQuantity > 0 ? ' WHERE a.`quantity` >= ' . (int) $minQuantity : '');

        // A product counts as bought when any of its combinations was, as the
        // screen reads order_detail.product_id.
        $bought = 'SELECT od.`product_id` FROM `' . _DB_PREFIX_ . 'order_detail` od
                INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = od.`id_order`
                WHERE o.`valid` = 1 AND o.`id_shop` = ' . $idShop
                . ($period ? ' AND o.`date_add` BETWEEN ' . $start . ' AND ' . $end : '');

        $unsold = 'SELECT k.*,
                    IF(k.`wholesale_price` > 0, k.`quantity` * k.`wholesale_price`, NULL) AS `stock_value`
                FROM (' . $kept . ') k
                WHERE k.`id_product` NOT IN (' . $bought . ')'
                // A product added after the start could not sell over the
                // whole period: listing it would blame it for days it missed.
                . ($period ? ' AND k.`date_add` <= ' . $start : '');

        $productsAvailable = (int) $db->getValue('SELECT COUNT(*) FROM (' . $kept . ') c');
        $totals = $db->getRow('SELECT COUNT(*) AS `total`, SUM(u.`stock_value`) AS `stock_value` FROM (' . $unsold . ') u');
        $total = is_array($totals) ? (int) $totals['total'] : 0;
        $totalStockValue = is_array($totals) ? round((float) $totals['stock_value'], $precision) : 0.0;

        // The stock that costs the most first, a product without a cost price
        // after those that have one.
        $rows = $db->executeS(
            'SELECT u.*,
                (SELECT pl.`name` FROM `' . _DB_PREFIX_ . 'product_lang` pl
                    WHERE pl.`id_product` = u.`id_product` AND pl.`id_lang` = ' . (int) $idLang . ' AND pl.`id_shop` = ' . $idShop . ') AS `name`,
                (SELECT MAX(o.`date_add`) FROM `' . _DB_PREFIX_ . 'order_detail` od
                    INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = od.`id_order`
                    WHERE od.`product_id` = u.`id_product` AND o.`valid` = 1 AND o.`id_shop` = ' . $idShop . ') AS `last_sold`
             FROM (' . $unsold . ') u
             ORDER BY (u.`stock_value` IS NULL) ASC, u.`stock_value` DESC, u.`quantity` DESC, u.`id_product` ASC
             LIMIT ' . (int) $offset . ', ' . (int) $limit
        ) ?: [];

        $products = [];
        foreach ($rows as $row) {
            $wholesale = (float) $row['wholesale_price'];
            $quantity = (int) $row['quantity'];
            $products[] = [
                'id_product' => (int) $row['id_product'],
                'reference' => (string) $row['reference'],
                'name' => (string) ($row['name'] ?? ''),
                'quantity' => $quantity,
                'price_tax_excl' => (float) $row['price'],
                // 0 is how PrestaShop stores a cost price nobody entered.
                'wholesale_price' => $wholesale > 0 ? $wholesale : null,
                'stock_value_tax_excl' => $wholesale > 0 ? round($quantity * $wholesale, $precision) : null,
                'date_add' => SomcpToolSearchCustomers::isoDate($row['date_add']),
                'last_sold' => SomcpToolSearchCustomers::isoDate($row['last_sold'] ?? null),
            ];
        }

        return SomcpTool::json([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'language' => $iso,
            'products' => $products,
            'returned' => count($products),
            'total_count' => $total,
            'products_available' => $productsAvailable,
            'total_stock_value_tax_excl' => $totalStockValue,
            'offset' => $offset,
        ]);
    }
}
