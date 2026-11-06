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
 * somcp_list_viewed_not_sold - the products seen but not bought over a period.
 *
 * Reads the figures Stats > Product details shows one product at a time, for
 * every saved product of the shop at once: Total Viewed, Total bought and the
 * Conversion rate, most viewed first and lowest conversion first among equals,
 * so the products that get looked at and not bought stand out.
 *
 * Counted the way that screen counts: sales are valid orders dated by their
 * order date (not the invoice date best sellers uses), page views come from
 * the ranges statsdata saved inside the period. Page views are off by default,
 * so the answer says when none are recorded rather than reading as "unseen".
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListViewedNotSold
{
    /** @var int Products returned when the caller asks for no particular number. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Most a single call will return. */
    private const MAX_LIMIT = 100;

    /** @var int A product nobody opened is list_unsold_products territory, not this one. */
    private const DEFAULT_MIN_PAGE_VIEWS = 1;

    /** @var string The module that records page views. */
    private const MODULE = 'statsdata';

    /** @var array<int, string> */
    private const FIELDS = ['date_from', 'date_to', 'id_category', 'min_page_views', 'language', 'limit', 'offset'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_viewed_not_sold',
            Somcp::t('List products viewed but not sold'),
            'Lists the products of the shop with their page views and what they sold over a period, most '
                . 'viewed first, so the ones seen but not bought stand out: the figures Stats > Product '
                . 'details shows one product at a time. page_views is Total Viewed, quantity_sold is Total '
                . 'bought, conversion_rate is units bought per view, revenue_tax_excl is Sales tax excluded '
                . 'in the default currency, quantity is what is available for sale. Page views need Save '
                . 'global page views on; when off, page_views is 0 and the answer says so. Read only. Best '
                . 'sellers are somcp_list_best_sellers, the stock of a product somcp_get_stock.',
            [
                'type' => 'object',
                'properties' => [
                    'date_from' => [
                        'type' => 'string',
                        'description' => 'First day of the period (From:), YYYY-MM-DD, that day included.',
                    ],
                    'date_to' => [
                        'type' => 'string',
                        'description' => 'Last day of the period (To:), YYYY-MM-DD, that day included. Not before date_from.',
                    ],
                    'id_category' => [
                        'type' => 'integer',
                        'description' => 'Only the products of that category (Choose a category), from '
                            . 'somcp_list_categories. Every category when left out.',
                    ],
                    'min_page_views' => [
                        'type' => 'integer',
                        'description' => 'Fewest page views a product needs to be listed: 1 by default, so a '
                            . 'product nobody opened is left out; 0 lists every product.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, for '
                            . 'the product names; a code the shop does not have is refused. The shop default '
                            . 'language when left out.',
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
        // The shop comes from the token, never from the caller: sales, views
        // and products are all read for it.
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

        // The texts of somcp_list_best_sellers, which takes the period the same way.
        $dateFrom = SomcpTool::day($arguments['date_from'] ?? null);
        if ($dateFrom === null) {
            $problems[] = '- date_from: a date, YYYY-MM-DD';
        }
        $dateTo = SomcpTool::day($arguments['date_to'] ?? null);
        if ($dateTo === null) {
            $problems[] = '- date_to: a date, YYYY-MM-DD';
        }
        if ($dateFrom !== null && $dateTo !== null && $dateTo < $dateFrom) {
            $problems[] = '- date_to: a date not before date_from';
        }

        $minPageViews = self::DEFAULT_MIN_PAGE_VIEWS;
        if (array_key_exists('min_page_views', $arguments) && $arguments['min_page_views'] !== null) {
            if (!is_int($arguments['min_page_views']) || $arguments['min_page_views'] < 0) {
                $problems[] = '- min_page_views: a whole number, 0 at least';
            } else {
                $minPageViews = (int) $arguments['min_page_views'];
            }
        }

        $idCategory = 0;
        if (array_key_exists('id_category', $arguments) && $arguments['id_category'] !== null) {
            // A cast would read an array as 1 and filter on a category nobody
            // asked for: only a whole number is an identifier.
            $value = $arguments['id_category'];
            if (!is_int($value) || $value < 1) {
                $problems[] = '- id_category: a whole number, the identifier somcp_list_categories gives';
            } elseif (!self::categoryInShop((int) $value, $idShop)) {
                $problems[] = '- id_category: no category of this shop has this identifier, find it with somcp_list_categories';
            } else {
                $idCategory = (int) $value;
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

        if ($problems !== [] || $dateFrom === null || $dateTo === null) {
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

        // Save global page views (PS_STATSDATA_PAGESVIEWS) is off by default:
        // without it page_viewed stays empty and every count is a true 0.
        $pageViewsRecorded = Module::isInstalled(self::MODULE) && Module::isEnabled(self::MODULE)
            && (bool) Configuration::get('PS_STATSDATA_PAGESVIEWS', null, null, $idShop);

        $db = Db::getInstance();
        // Whole days: an order placed at 18:00 on the last day counts.
        $start = "'" . pSQL($dateFrom) . " 00:00:00'";
        $end = "'" . pSQL($dateTo) . " 23:59:59'";

        $pageViews = SomcpStats::pageViewsSubquery($idShop, $start, $end, 'p.`id_product`');
        $stock = SomcpStats::productStockSubquery($shop, 'p.`id_product`');

        // Sales dated by the order date, as Stats > Product details does, not
        // by the invoice date somcp_list_best_sellers uses; amounts divided by
        // the order's conversion rate so the total is in the default currency.
        $sales = 'SELECT od.`product_id` AS `id_product`,
                    SUM(od.`product_quantity`) AS `quantity_sold`,
                    SUM(od.`total_price_tax_excl` / o.`conversion_rate`) AS `revenue_tax_excl`
                FROM `' . _DB_PREFIX_ . 'order_detail` od
                INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = od.`id_order`
                WHERE o.`valid` = 1 AND o.`id_shop` = ' . $idShop . '
                    AND o.`date_add` BETWEEN ' . $start . ' AND ' . $end . '
                GROUP BY od.`product_id`';

        // Every saved product of the shop, its sales left-joined so a product
        // seen and never bought still appears.
        $computed = 'SELECT p.`id_product`, p.`reference`,
                    (SELECT pl.`name` FROM `' . _DB_PREFIX_ . 'product_lang` pl
                        WHERE pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . (int) $idLang . ' AND pl.`id_shop` = ' . $idShop . ') AS `name`,
                    IFNULL(s.`quantity_sold`, 0) AS `quantity_sold`,
                    IFNULL(s.`revenue_tax_excl`, 0) AS `revenue_tax_excl`,
                    ' . $pageViews . ' AS `page_views`,
                    ' . $stock . ' AS `quantity`,
                    ps.`active`
                FROM `' . _DB_PREFIX_ . 'product` p
                INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
                LEFT JOIN (' . $sales . ') s ON s.`id_product` = p.`id_product`
                WHERE p.`state` = ' . (int) Product::STATE_SAVED
                . ($idCategory > 0
                    ? ' AND p.`id_product` IN (SELECT cp.`id_product` FROM `' . _DB_PREFIX_ . 'category_product` cp WHERE cp.`id_category` = ' . $idCategory . ')'
                    : '');

        // The page-view filter is on a computed column, so it is applied
        // around the computed rows rather than in a HAVING without a group.
        $filtered = 'SELECT t.* FROM (' . $computed . ') t WHERE t.`page_views` >= ' . (int) $minPageViews;

        $total = (int) $db->getValue('SELECT COUNT(*) FROM (' . $filtered . ') u');

        // Most viewed first, then the lowest conversion (seen and not bought)
        // first among equals; a product with no view sorts last on both.
        $rows = $db->executeS(
            $filtered . '
             ORDER BY t.`page_views` DESC,
                      IF(t.`page_views` = 0, NULL, t.`quantity_sold` / t.`page_views`) ASC,
                      t.`id_product` ASC
             LIMIT ' . (int) $offset . ', ' . (int) $limit
        ) ?: [];

        $products = [];
        foreach ($rows as $row) {
            $views = (int) $row['page_views'];
            $sold = (int) $row['quantity_sold'];
            $products[] = [
                'id_product' => (int) $row['id_product'],
                'reference' => (string) $row['reference'],
                'name' => (string) ($row['name'] ?? ''),
                'page_views' => $views,
                'quantity_sold' => $sold,
                'revenue_tax_excl' => round((float) $row['revenue_tax_excl'], 2),
                // Units bought per view, as the screen; undefined with no view.
                'conversion_rate' => $views > 0 ? round($sold / $views, 2) : null,
                'quantity' => (int) ($row['quantity'] ?? 0),
                'active' => (bool) $row['active'],
            ];
        }

        $answer = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'language' => $iso,
            'page_views_recorded' => $pageViewsRecorded,
            'products' => $products,
            'returned' => count($products),
            'total_count' => $total,
            'offset' => $offset,
        ];
        if (!$pageViewsRecorded) {
            $answer['note'] = 'Page views are not recorded: every page_views is 0 because Save global page '
                . 'views is off (Modules > Module Manager > Data mining for statistics > Configure). The sales '
                . 'figures are real.';
        }

        return SomcpTool::json($answer);
    }

    /**
     * Whether a category with this id exists in the shop.
     *
     * @param int $idCategory
     * @param int $idShop
     *
     * @return bool
     */
    private static function categoryInShop(int $idCategory, int $idShop): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'category_shop` cs
             WHERE cs.`id_category` = ' . (int) $idCategory . ' AND cs.`id_shop` = ' . (int) $idShop . ' LIMIT 1'
        );
    }
}
