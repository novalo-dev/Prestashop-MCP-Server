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
 * somcp_list_low_stock - the products and combinations at or under a quantity.
 *
 * One line per thing that can be sold: a product without combinations, or one
 * combination of a product that has some. A product with combinations has no
 * line of its own, because its quantity is only the total of theirs, and a
 * total that looks fine can hide a size that is gone.
 *
 * Quantities come from stock_available, restricted to the shop or its group
 * the way the core decides, as somcp_get_stock reads them, so the two tools
 * never disagree on what is left.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListLowStock
{
    /** @var int Lines returned when the caller asks for no particular number. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Most a single call will return, whatever it asks for. */
    private const MAX_LIMIT = 100;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_low_stock',
            Somcp::t('List low stock'),
            'Lists what runs low in this shop: one line per product without combinations and per '
                . 'combination, whose quantity - what the storefront can still sell - is at or under '
                . 'a threshold, lowest quantity first. Without a threshold, each line is compared with '
                . 'its own low stock level, the one the merchant set on the product or the '
                . 'combination, and a line with no low stock level set counts as low only at zero or '
                . 'below. threshold on each line is the level it was compared with. Only enabled '
                . 'products by default. Filters as on the Stock page of the back office: a category, '
                . 'a supplier, and a search on the product name, the product or combination '
                . 'reference or the supplier name. supplier on each line is the name of the default '
                . 'supplier. A combination line carries the name of its product; read '
                . 'somcp_get_stock for its attributes (Size: M) and its physical and reserved '
                . 'quantities. total_count says how many lines match in all: read the next page with '
                . 'offset rather than concluding from one page. Do not use it for the stock of one '
                . 'known product: somcp_get_stock returns it.',
            [
                'type' => 'object',
                'properties' => [
                    'threshold' => [
                        'type' => 'integer',
                        'description' => 'Quantity at or under which a line counts as low. When absent, '
                            . 'each line uses its own low stock level, and 0 when none is set.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true (the default) for enabled products only, false to include '
                            . 'the disabled ones too.',
                    ],
                    'id_category' => [
                        'type' => 'integer',
                        'description' => 'Keep the products associated with this category, its default '
                            . 'one or not. From somcp_list_categories, never guessed.',
                    ],
                    'id_supplier' => [
                        'type' => 'integer',
                        'description' => 'Keep the products whose default supplier this is. From '
                            . 'somcp_list_suppliers, never guessed.',
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' => 'Part of the product name, of the product or combination '
                            . 'reference, or of the supplier name.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many lines to return, 20 by default and 100 at most.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'How many to skip, to read the next page.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::STOCK_READ],
            [self::class, 'handle'],
            // The Stock page of the catalogue is where a merchant reviews these
            // quantities by hand.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminStockManagement')
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
        $shop = Somcp::currentShop();
        $idLang = (int) Somcp::legacyContext()->language->id;

        $threshold = isset($arguments['threshold']) && is_numeric($arguments['threshold'])
            ? (int) $arguments['threshold']
            : null;
        $activeOnly = !(isset($arguments['active']) && $arguments['active'] === false);
        $filters = [
            'id_category' => isset($arguments['id_category']) && is_numeric($arguments['id_category'])
                ? (int) $arguments['id_category']
                : 0,
            'id_supplier' => isset($arguments['id_supplier']) && is_numeric($arguments['id_supplier'])
                ? (int) $arguments['id_supplier']
                : 0,
            'search' => isset($arguments['search']) && is_scalar($arguments['search'])
                ? trim((string) $arguments['search'])
                : '',
        ];

        // The level each line is compared with, worked out in SQL so the count,
        // the filter and the page all agree. A combination has its own alert
        // level and a product without combinations its own; null means the
        // merchant set none, which is no alert, so only zero or below is low.
        $level = $threshold !== null
            ? (string) $threshold
            : 'IFNULL(IF(pas.`id_product_attribute` IS NULL, ps.`low_stock_threshold`, pas.`low_stock_threshold`), 0)';

        $count = self::query($shop, $idLang, $activeOnly, $level, $filters);
        $count->select('COUNT(*)');
        $total = (int) Db::getInstance()->getValue($count);

        $limit = self::limit($arguments);
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        $page = self::query($shop, $idLang, $activeOnly, $level, $filters);
        $page->select('p.`id_product`, IFNULL(pas.`id_product_attribute`, 0) AS `id_product_attribute`');
        // A combination is labelled with its own reference; the product's
        // would point the merchant at the wrong shelf.
        $page->select('IF(pas.`id_product_attribute` IS NULL, p.`reference`, pa.`reference`) AS `reference`');
        $page->select('pl.`name`, IFNULL(sa.`quantity`, 0) AS `quantity`, ' . $level . ' AS `threshold`');
        $page->select('su.`name` AS `supplier`');
        $page->orderBy('`quantity` ASC, p.`id_product` ASC, `id_product_attribute` ASC');
        $page->limit($limit, $offset);

        $lines = [];
        foreach (Db::getInstance()->executeS($page) ?: [] as $row) {
            $lines[] = [
                'id_product' => (int) $row['id_product'],
                'id_product_attribute' => (int) $row['id_product_attribute'],
                'reference' => (string) ($row['reference'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'quantity' => (int) $row['quantity'],
                'threshold' => (int) $row['threshold'],
                // The Supplier column of the Stock page; a supplier deleted
                // since it was set reads as none, as somcp_get_stock says.
                'supplier' => isset($row['supplier']) ? (string) $row['supplier'] : null,
            ];
        }

        return SomcpTool::json([
            'lines' => $lines,
            'returned' => count($lines),
            // So the assistant knows there is more instead of concluding from
            // one page that it has seen everything.
            'total_count' => $total,
            'offset' => $offset,
        ]);
    }

    /**
     * The query both the count and the page are built on: the joins and the
     * filters, without what is selected.
     *
     * Products are joined to their combinations in this shop with a left join:
     * a product without any keeps one row, with no combination, and a product
     * with some gets one row per combination instead.
     *
     * @param Shop $shop
     * @param int $idLang
     * @param bool $activeOnly
     * @param string $level SQL built by handle() from a cast integer or fixed columns
     * @param array{id_category: int, id_supplier: int, search: string} $filters
     *
     * @return DbQuery
     */
    private static function query(Shop $shop, int $idLang, bool $activeOnly, string $level, array $filters): DbQuery
    {
        $idShop = (int) $shop->id;

        $query = new DbQuery();
        $query->from('product', 'p');
        // The shop comes from the token, never from the caller, and a product
        // not associated with that shop is not part of its catalogue.
        $query->innerJoin('product_shop', 'ps', 'ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop);
        $query->leftJoin(
            'product_attribute_shop',
            'pas',
            'pas.`id_product` = p.`id_product` AND pas.`id_shop` = ' . $idShop
        );
        $query->leftJoin('product_attribute', 'pa', 'pa.`id_product_attribute` = pas.`id_product_attribute`');
        $query->leftJoin(
            'product_lang',
            'pl',
            'pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $idLang . ' AND pl.`id_shop` = ' . $idShop
        );
        // The default supplier, the one the Stock page shows and filters on;
        // the other suppliers of a product are not what its Supplier column says.
        $query->leftJoin('supplier', 'su', 'su.`id_supplier` = p.`id_supplier`');
        // A combination with no stock row yet is at zero, not missing: the
        // left join and IFNULL keep it, as somcp_get_stock does.
        $query->leftJoin(
            'stock_available',
            'sa',
            'sa.`id_product` = p.`id_product` AND sa.`id_product_attribute` = IFNULL(pas.`id_product_attribute`, 0)'
                . (string) StockAvailable::addSqlShopRestriction(null, $shop, 'sa')
        );
        // A product the back office is still creating has no name yet and
        // nobody can sell it, so it is not short of anything.
        $query->where('p.`state` = ' . (int) Product::STATE_SAVED);
        if ($activeOnly) {
            $query->where('ps.`active` = 1');
        }
        $query->where('IFNULL(sa.`quantity`, 0) <= ' . $level);
        if ($filters['id_category'] > 0) {
            // Any category the product is filed under, not only its default
            // one, as the category filter of the Stock page and
            // somcp_search_products do.
            $query->where(
                'EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'category_product` cp'
                . ' WHERE cp.`id_product` = p.`id_product` AND cp.`id_category` = ' . $filters['id_category'] . ')'
            );
        }
        if ($filters['id_supplier'] > 0) {
            $query->where('p.`id_supplier` = ' . $filters['id_supplier']);
        }
        if ($filters['search'] !== '') {
            // Matched on the line itself: a combination is found by its own
            // reference without bringing its sizes that do not match along.
            $like = "LIKE '%" . pSQL($filters['search']) . "%'";
            $query->where(
                '(pl.`name` ' . $like . ' OR p.`reference` ' . $like
                . ' OR pa.`reference` ' . $like . ' OR su.`name` ' . $like . ')'
            );
        }

        return $query;
    }

    /**
     * How many lines to return, within the limits every list follows.
     *
     * @param array<string, mixed> $arguments
     *
     * @return int
     */
    private static function limit(array $arguments): int
    {
        $limit = isset($arguments['limit']) && is_numeric($arguments['limit']) ? (int) $arguments['limit'] : self::DEFAULT_LIMIT;

        return max(1, min(self::MAX_LIMIT, $limit));
    }
}
