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
 * somcp_list_stock_movements - the Movements tab of Catalog > Stock.
 *
 * Read the way the core's StockMovementRepository reads it: stock_mvt joined
 * to stock_available on id_stock, so the shop restriction is the one of the
 * stock rows. A shop that shares its stock with its group sees the movements
 * of the group, as the tab does, and somcp_get_stock reads the same rows.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListStockMovements
{
    /** @var int Movements returned when the caller asks for no particular number. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Most a single call will return, whatever it asks for. */
    private const MAX_LIMIT = 100;

    /** @var array<int, string> The arguments the tool takes; any other is refused. */
    private const FIELDS = [
        'id_product', 'id_product_attribute', 'search', 'id_stock_mvt_reason', 'id_employee',
        'date_from', 'date_to', 'id_category', 'id_supplier', 'active', 'language', 'limit', 'offset',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_stock_movements',
            Somcp::t('List stock movements'),
            'Lists the stock movements of the shop, newest first, like the Movements tab of the Stock page: '
                . 'who changed a quantity, when, by how much (signed) and why, with the order when there is '
                . 'one. types and employees list the values the filters take. For the current quantities use '
                . 'somcp_get_stock, for what runs low somcp_list_low_stock, to change one '
                . 'somcp_set_stock_quantity. total_count counts every match: read the next page with offset.',
            [
                'type' => 'object',
                'properties' => [
                    'id_product' => [
                        'type' => 'integer',
                        'description' => 'Movements of this product only. From somcp_search_products, never guessed.',
                    ],
                    'id_product_attribute' => [
                        'type' => 'integer',
                        'description' => 'Movements of this combination of id_product only, which it requires. '
                            . 'From somcp_get_stock.',
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' => 'Part of the product name, of the product or combination reference, '
                            . 'or of the supplier name, as the search box of the tab.',
                    ],
                    'id_stock_mvt_reason' => [
                        'type' => 'integer',
                        'description' => 'One movement type, from types of a previous answer: the identifiers '
                            . 'differ from one shop to another.',
                    ],
                    'id_employee' => [
                        'type' => 'integer',
                        'description' => 'Movements made by this employee, from employees of a previous answer.',
                    ],
                    'date_from' => [
                        'type' => 'string',
                        'description' => 'First day of the period, YYYY-MM-DD, included.',
                    ],
                    'date_to' => [
                        'type' => 'string',
                        'description' => 'Last day of the period, YYYY-MM-DD, included up to 23:59:59.',
                    ],
                    'id_category' => [
                        'type' => 'integer',
                        'description' => 'Products filed under this category. From somcp_list_categories, never guessed.',
                    ],
                    'id_supplier' => [
                        'type' => 'integer',
                        'description' => 'Products whose default supplier this is. From somcp_list_suppliers, never guessed.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true for enabled products only, false for disabled ones only; both '
                            . 'when left out.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, '
                            . 'for the product, attribute and type names; a code the shop does not have is '
                            . 'refused. The shop default language when left out.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many movements to return, 20 by default and 100 at most.',
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
            // The Stock page holds the Movements tab, so whoever may view it
            // may read the movements.
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
        $idShop = (int) $shop->id;

        // Every mistake is reported in one answer, so the call is corrected
        // in one go (the shape of #169).
        $problems = [];
        foreach (array_keys($arguments) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                $problems[] = '- ' . $field . ': not a field of this tool';
            }
        }

        $asked = array_key_exists('language', $arguments) && $arguments['language'] !== null && $arguments['language'] !== '';
        $language = SomcpTool::readLanguage($asked ? $arguments : [], $idShop);
        if ($asked && $language === null) {
            $problems[] = '- language: not a language of this shop, which has '
                . implode(', ', SomcpTool::activeLanguages($idShop));
        }
        $idLang = $language !== null ? $language[0] : 0;

        $filters = [
            'id_product' => self::identifier($arguments, 'id_product', $problems),
            'id_product_attribute' => self::identifier($arguments, 'id_product_attribute', $problems),
            'id_stock_mvt_reason' => self::identifier($arguments, 'id_stock_mvt_reason', $problems),
            'id_employee' => self::identifier($arguments, 'id_employee', $problems),
            'id_category' => self::identifier($arguments, 'id_category', $problems),
            'id_supplier' => self::identifier($arguments, 'id_supplier', $problems),
            'search' => '',
            'active' => null,
            'date_from' => self::date($arguments, 'date_from', $problems),
            'date_to' => self::date($arguments, 'date_to', $problems),
        ];

        if ($filters['id_product'] > 0 && !SomcpToolAddOrderProduct::inShop($filters['id_product'], $idShop)) {
            $problems[] = '- id_product: no product of this shop has the identifier ' . $filters['id_product']
                . ' - find it with somcp_search_products';
        } elseif ($filters['id_product_attribute'] > 0) {
            if ($filters['id_product'] <= 0) {
                $problems[] = '- id_product_attribute: give id_product too, the product of this combination';
            } elseif (!SomcpToolGetProduct::combinationBelongs(
                new Product($filters['id_product'], false, null, $idShop),
                $filters['id_product_attribute'],
                $idShop
            )) {
                $problems[] = '- id_product_attribute: no combination of this product has the identifier '
                    . $filters['id_product_attribute'] . ' - read them with somcp_get_stock';
            }
        }
        if ($filters['id_category'] > 0 && !self::exists('category_shop', 'id_category', $filters['id_category'], $idShop)) {
            $problems[] = '- id_category: no category of this shop has the identifier ' . $filters['id_category']
                . ' - find it with somcp_list_categories';
        }
        if ($filters['id_supplier'] > 0 && !self::exists('supplier_shop', 'id_supplier', $filters['id_supplier'], $idShop)) {
            $problems[] = '- id_supplier: no supplier of this shop has the identifier ' . $filters['id_supplier']
                . ' - find it with somcp_list_suppliers';
        }

        $types = self::types($idLang);
        if ($filters['id_stock_mvt_reason'] > 0
            && !in_array($filters['id_stock_mvt_reason'], array_column($types, 'id_stock_mvt_reason'), true)) {
            $problems[] = '- id_stock_mvt_reason: not one of the movement types of this shop, listed in types';
        }

        if ($filters['date_from'] !== null && $filters['date_to'] !== null && $filters['date_to'] < $filters['date_from']) {
            $problems[] = '- date_to: a date not before date_from';
        }

        if (array_key_exists('search', $arguments) && $arguments['search'] !== null) {
            if (!is_string($arguments['search'])) {
                $problems[] = '- search: a text';
            } else {
                $filters['search'] = trim($arguments['search']);
            }
        }
        if (array_key_exists('active', $arguments) && $arguments['active'] !== null) {
            if (!is_bool($arguments['active'])) {
                $problems[] = '- active: true or false';
            } else {
                $filters['active'] = $arguments['active'];
            }
        }

        $limit = self::DEFAULT_LIMIT;
        if (array_key_exists('limit', $arguments) && $arguments['limit'] !== null) {
            if (!is_int($arguments['limit']) || $arguments['limit'] < 1 || $arguments['limit'] > self::MAX_LIMIT) {
                $problems[] = '- limit: a whole number from 1 to ' . self::MAX_LIMIT;
            } else {
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
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;

        $count = self::query($shop, $idLang, $filters);
        $count->select('COUNT(*)');
        $total = (int) Db::getInstance()->getValue($count);

        $page = self::query($shop, $idLang, $filters);
        $page->select('sm.`id_stock_mvt`, sa.`id_product`, sa.`id_product_attribute`, pl.`name`');
        // A combination is labelled with its own reference when it has one,
        // as the tab does; the product's otherwise.
        $page->select('IF(pa.`reference` IS NOT NULL AND pa.`reference` <> \'\', pa.`reference`, p.`reference`) AS `reference`');
        $page->select('sm.`id_stock_mvt_reason`, srl.`name` AS `type`, sm.`physical_quantity`, sm.`sign`, sm.`date_add`');
        $page->select('sm.`id_employee`, sm.`employee_firstname`, sm.`employee_lastname`, sm.`id_order`');
        $page->orderBy('sm.`id_stock_mvt` DESC');
        $page->limit($limit, $offset);
        $rows = Db::getInstance()->executeS($page) ?: [];

        $combinationIds = [];
        foreach ($rows as $row) {
            if ((int) $row['id_product_attribute'] > 0) {
                $combinationIds[] = (int) $row['id_product_attribute'];
            }
        }
        $attributes = $combinationIds === []
            ? []
            : SomcpToolGetProduct::combinationAttributes(array_values(array_unique($combinationIds)), $idLang);

        $movements = [];
        foreach ($rows as $row) {
            $idCombination = (int) $row['id_product_attribute'];
            $movements[] = [
                'id_stock_mvt' => (int) $row['id_stock_mvt'],
                'id_product' => (int) $row['id_product'],
                'id_product_attribute' => $idCombination,
                'name' => (string) ($row['name'] ?? ''),
                'attributes' => $attributes[$idCombination] ?? [],
                'reference' => (string) ($row['reference'] ?? ''),
                'id_stock_mvt_reason' => (int) $row['id_stock_mvt_reason'],
                'type' => (string) ($row['type'] ?? ''),
                // physical_quantity is stored unsigned on PrestaShop 9 and the
                // direction lives in sign, so the two are put back together.
                'quantity' => (int) $row['physical_quantity'] * ((int) $row['sign'] < 0 ? -1 : 1),
                'date' => SomcpToolSearchCustomers::isoDate($row['date_add'] ?? null),
                'id_employee' => (int) $row['id_employee'],
                'employee' => self::employeeName($row),
                'id_order' => (int) $row['id_order'] > 0 ? (int) $row['id_order'] : null,
            ];
        }

        return SomcpTool::json([
            'movements' => $movements,
            'returned' => count($movements),
            // So the assistant knows there is more instead of concluding from
            // one page that it has seen everything.
            'total_count' => $total,
            'offset' => $offset,
            // Every type rather than only the ones used, so a filter can be
            // given before a first movement of that type exists.
            'types' => $types,
            'employees' => self::employees($shop),
            'language' => $iso,
        ]);
    }

    /**
     * The query both the count and the page are built on: the joins and the
     * filters, without what is selected.
     *
     * The product is joined through product_shop with an inner join, as the
     * tab does, so a movement of a product deleted since is left out.
     *
     * @param Shop $shop
     * @param int $idLang
     * @param array{id_product: int, id_product_attribute: int, id_stock_mvt_reason: int, id_employee: int, id_category: int, id_supplier: int, search: string, active: bool|null, date_from: string|null, date_to: string|null} $filters
     *
     * @return DbQuery
     */
    private static function query(Shop $shop, int $idLang, array $filters): DbQuery
    {
        $idShop = (int) $shop->id;

        $query = new DbQuery();
        $query->from('stock_mvt', 'sm');
        // The shop comes from the token, never from the caller, restricted as
        // stock sharing decides, the way somcp_get_stock reads quantities.
        $query->innerJoin(
            'stock_available',
            'sa',
            'sa.`id_stock_available` = sm.`id_stock`' . (string) StockAvailable::addSqlShopRestriction(null, $shop, 'sa')
        );
        $query->innerJoin('product', 'p', 'p.`id_product` = sa.`id_product`');
        $query->innerJoin('product_shop', 'ps', 'ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop);
        $query->leftJoin(
            'product_attribute',
            'pa',
            'pa.`id_product_attribute` = sa.`id_product_attribute` AND sa.`id_product_attribute` > 0'
        );
        $query->leftJoin(
            'product_lang',
            'pl',
            'pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $idLang . ' AND pl.`id_shop` = ' . $idShop
        );
        $query->leftJoin('supplier', 'su', 'su.`id_supplier` = p.`id_supplier`');
        $query->leftJoin(
            'stock_mvt_reason_lang',
            'srl',
            'srl.`id_stock_mvt_reason` = sm.`id_stock_mvt_reason` AND srl.`id_lang` = ' . $idLang
        );

        if ($filters['id_product'] > 0) {
            $query->where('sa.`id_product` = ' . (int) $filters['id_product']);
        }
        if ($filters['id_product_attribute'] > 0) {
            $query->where('sa.`id_product_attribute` = ' . (int) $filters['id_product_attribute']);
        }
        if ($filters['id_stock_mvt_reason'] > 0) {
            $query->where('sm.`id_stock_mvt_reason` = ' . (int) $filters['id_stock_mvt_reason']);
        }
        if ($filters['id_employee'] > 0) {
            $query->where('sm.`id_employee` = ' . (int) $filters['id_employee']);
        }
        if ($filters['date_from'] !== null) {
            $query->where('sm.`date_add` >= \'' . pSQL($filters['date_from']) . ' 00:00:00\'');
        }
        if ($filters['date_to'] !== null) {
            $query->where('sm.`date_add` <= \'' . pSQL($filters['date_to']) . ' 23:59:59\'');
        }
        if ($filters['id_category'] > 0) {
            // Any category the product is filed under, as the category filter
            // of the tab and somcp_list_low_stock do.
            $query->where(
                'EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'category_product` cp'
                . ' WHERE cp.`id_product` = p.`id_product` AND cp.`id_category` = ' . (int) $filters['id_category'] . ')'
            );
        }
        if ($filters['id_supplier'] > 0) {
            $query->where('p.`id_supplier` = ' . (int) $filters['id_supplier']);
        }
        if ($filters['active'] !== null) {
            $query->where('ps.`active` = ' . ($filters['active'] ? 1 : 0));
        }
        if ($filters['search'] !== '') {
            $like = "LIKE '%" . pSQL($filters['search']) . "%'";
            $query->where(
                '(pl.`name` ' . $like . ' OR p.`reference` ' . $like
                . ' OR pa.`reference` ' . $like . ' OR su.`name` ' . $like . ')'
            );
        }

        return $query;
    }

    /**
     * Every movement type of the shop, with its sign.
     *
     * The rows differ from one installation to another (twelve on a shop
     * installed with 8.0, five with 9.0), so an identifier means nothing
     * unless it is read here; the sign tells the assistant whether a type
     * adds stock or removes it.
     *
     * @param int $idLang
     *
     * @return array<int, array{id_stock_mvt_reason: int, type: string, sign: int}>
     */
    private static function types(int $idLang): array
    {
        $query = new DbQuery();
        $query->select('r.`id_stock_mvt_reason`, r.`sign`, rl.`name`');
        $query->from('stock_mvt_reason', 'r');
        $query->leftJoin(
            'stock_mvt_reason_lang',
            'rl',
            'rl.`id_stock_mvt_reason` = r.`id_stock_mvt_reason` AND rl.`id_lang` = ' . $idLang
        );
        $query->where('r.`deleted` = 0');
        $query->orderBy('r.`id_stock_mvt_reason` ASC');

        $types = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $types[] = [
                'id_stock_mvt_reason' => (int) $row['id_stock_mvt_reason'],
                'type' => (string) ($row['name'] ?? ''),
                'sign' => (int) $row['sign'] < 0 ? -1 : 1,
            ];
        }

        return $types;
    }

    /**
     * The employees who made a movement of this shop, as the options of the
     * tab's employee filter: no somcp tool lists employees, so this is where
     * the assistant takes the identifier from.
     *
     * @param Shop $shop
     *
     * @return array<int, array{id_employee: int, employee: string}>
     */
    private static function employees(Shop $shop): array
    {
        $query = new DbQuery();
        $query->select('sm.`id_employee`, MAX(sm.`employee_firstname`) AS `employee_firstname`');
        $query->select('MAX(sm.`employee_lastname`) AS `employee_lastname`');
        $query->from('stock_mvt', 'sm');
        $query->innerJoin(
            'stock_available',
            'sa',
            'sa.`id_stock_available` = sm.`id_stock`' . (string) StockAvailable::addSqlShopRestriction(null, $shop, 'sa')
        );
        $query->where('sm.`id_employee` > 0');
        $query->groupBy('sm.`id_employee`');
        $query->orderBy('`employee_lastname` ASC, `employee_firstname` ASC');

        $employees = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $employees[] = [
                'id_employee' => (int) $row['id_employee'],
                'employee' => self::employeeName($row),
            ];
        }

        return $employees;
    }

    /**
     * The name the movement was saved with, "First Last". Taken from the
     * movement rather than the employee account, as the tab does, so a
     * movement keeps its author after the account is renamed or deleted.
     *
     * @param array<string, mixed> $row
     *
     * @return string
     */
    private static function employeeName(array $row): string
    {
        return trim((string) ($row['employee_firstname'] ?? '') . ' ' . (string) ($row['employee_lastname'] ?? ''));
    }

    /**
     * An identifier argument, 0 when left out; a value given but not an
     * identifier is refused rather than ignored, or the list would silently
     * cover more than was asked.
     *
     * @param array<string, mixed> $arguments
     * @param string $field
     * @param array<int, string> $problems
     *
     * @return int
     */
    private static function identifier(array $arguments, string $field, array &$problems): int
    {
        if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
            return 0;
        }
        $id = SomcpTool::id($arguments, $field);
        if ($id <= 0) {
            $problems[] = '- ' . $field . ': a positive identifier';
        }

        return $id;
    }

    /**
     * A day argument, null when left out; a value given but not a day is
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
     * Whether an object is associated with the shop, through its _shop table.
     *
     * @param string $table category_shop or supplier_shop, fixed by the caller
     * @param string $column its identifier column, fixed by the caller
     * @param int $id
     * @param int $idShop
     *
     * @return bool
     */
    private static function exists(string $table, string $column, int $id, int $idShop): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . bqSQL($table) . '` WHERE `' . bqSQL($column) . '` = ' . (int) $id
                . ' AND `id_shop` = ' . (int) $idShop
        );
    }
}
