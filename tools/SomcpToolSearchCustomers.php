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
 * somcp_search_customers - the list of Customers > Customers, with its filters.
 *
 * Built with DbQuery on the same tables and conditions as the back office grid
 * (CustomerQueryBuilder): the customers of the token's shop, deleted ones kept
 * in the database left out. Customer::searchByName() is not called: it does not
 * leave deleted customers out, and its signature differs between PrestaShop 8
 * and 9. Its word-by-word condition is reproduced instead, so the same words
 * find the same people as the header search of the back office.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolSearchCustomers
{
    /** @var int Customers returned when the caller asks for no particular number. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Most a single call will return, whatever it asks for. */
    private const MAX_LIMIT = 100;

    /** @var array<int, string> Sort keys offered, as on the columns of the list. */
    private const SORTS = ['date_add', 'last_visit', 'sales', 'lastname', 'id_customer'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_search_customers',
            Somcp::t('Search customers'),
            'Finds customers of this shop, as the list of Customers > Customers in the back office, '
                . 'and returns one short line each: name, email, company, social title, default group, '
                . 'sales, whether the account is enabled, newsletter and partner offers, registration '
                . 'and last visit, and whether it is a guest account. Filters as on that list. Most '
                . 'recently registered first by default. Only the customers registered on this shop '
                . 'are listed, even in a multistore that shares its customers, and a customer deleted '
                . 'with their data kept is left out, as the list leaves them out. total_count says how '
                . 'many match in all: read the next page with offset rather than concluding from one '
                . 'page. No address, phone or birthday here. Do not use it to read one customer '
                . 'already identified (somcp_get_customer), or their orders (somcp_search_orders '
                . 'with customer_email).',
            [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Words matched against the first name, last name and email, and '
                            . 'the company when B2B mode is on; each word must be found in one of them, as '
                            . 'the customer search of the back office: "doe pub@" finds John DOE.',
                    ],
                    'email' => [
                        'type' => 'string',
                        'description' => 'Exact email address of the customer.',
                    ],
                    'id_default_group' => [
                        'type' => 'integer',
                        'description' => 'Default group of the customer, as the Group filter of the list '
                            . '(id_default_group of an earlier answer).',
                    ],
                    'id_gender' => [
                        'type' => 'integer',
                        'description' => 'Social title, as the Social title filter of the list.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true for enabled accounts only, false for disabled ones only. '
                            . 'Both when left out.',
                    ],
                    'newsletter' => [
                        'type' => 'boolean',
                        'description' => 'true for the customers subscribed to the newsletter, false for '
                            . 'the others. Both when left out.',
                    ],
                    'partner_offers' => [
                        'type' => 'boolean',
                        'description' => 'true for the customers who accepted partner offers, false for '
                            . 'the others. Both when left out.',
                    ],
                    'is_guest' => [
                        'type' => 'boolean',
                        'description' => 'false for registered accounts only, true for guest accounts '
                            . 'only. Both when left out.',
                    ],
                    'date_from' => [
                        'type' => 'string',
                        'description' => 'Registered on or after this date, YYYY-MM-DD.',
                    ],
                    'date_to' => [
                        'type' => 'string',
                        'description' => 'Registered on or before this date, YYYY-MM-DD.',
                    ],
                    'sort' => [
                        'type' => 'string',
                        'enum' => self::SORTS,
                        'description' => 'What to order by: date_add (registration, the default), '
                            . 'last_visit, sales, lastname or id_customer.',
                    ],
                    'order' => [
                        'type' => 'string',
                        'enum' => ['desc', 'asc'],
                        'description' => 'desc (the default) or asc.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, '
                            . 'for the social title and group names; a code the shop does not have is '
                            . 'refused. The shop default language when left out.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many customers to return, 20 by default and 100 at most.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'How many to skip, to read the next page.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_READ],
            [self::class, 'handle'],
            // The customer list of the back office is where a merchant looks
            // for one by hand.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminCustomers')
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
        $idShop = (int) Somcp::currentShop()->id;
        $language = self::language($arguments, $idShop);
        if ($language === null) {
            return SomcpTool::text(
                'This language is not one of the shop\'s, which has '
                    . implode(', ', SomcpToolAuditProductSeo::languages([], $idShop) ?? [])
                    . '. Give the ISO code of one of them, or leave language out for the shop default.',
                true
            );
        }
        [$idLang, $iso] = $language;

        $count = self::query($arguments, $idShop);
        $count->select('COUNT(*)');
        $total = (int) Db::getInstance()->getValue($count);

        $limit = self::limit($arguments);
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        $page = self::query($arguments, $idShop);
        $page->select('c.`id_customer`, c.`firstname`, c.`lastname`, c.`email`, c.`company`');
        $page->select('c.`id_gender`, gl.`name` AS `social_title`, c.`id_default_group`, grl.`name` AS `default_group`');
        $page->select('c.`active`, c.`newsletter`, c.`optin`, c.`date_add`, c.`is_guest`');
        $page->select(self::salesExpression($idShop) . ' AS `sales`');
        $page->select(self::lastVisitExpression() . ' AS `last_visit`');
        $page->leftJoin('gender_lang', 'gl', 'gl.`id_gender` = c.`id_gender` AND gl.`id_lang` = ' . (int) $idLang);
        $page->leftJoin('group_lang', 'grl', 'grl.`id_group` = c.`id_default_group` AND grl.`id_lang` = ' . (int) $idLang);
        $page->orderBy(self::sort($arguments));
        $page->limit($limit, $offset);

        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop));
        $currencyIso = Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '';

        $customers = [];
        foreach (Db::getInstance()->executeS($page) ?: [] as $row) {
            $customers[] = [
                'id_customer' => (int) $row['id_customer'],
                'social_title' => (string) ($row['social_title'] ?? ''),
                'firstname' => (string) $row['firstname'],
                'lastname' => (string) $row['lastname'],
                'email' => (string) $row['email'],
                'company' => (string) ($row['company'] ?? ''),
                'id_default_group' => (int) $row['id_default_group'],
                'default_group' => (string) ($row['default_group'] ?? ''),
                'sales_tax_incl' => round((float) $row['sales'], 2),
                'sales_currency' => $currencyIso,
                'active' => (bool) $row['active'],
                'newsletter' => (bool) $row['newsletter'],
                'partner_offers' => (bool) $row['optin'],
                'date_add' => self::isoDate($row['date_add']),
                'last_visit' => self::isoDate($row['last_visit']),
                'is_guest' => (bool) $row['is_guest'],
            ];
        }

        return SomcpTool::json([
            'customers' => $customers,
            'returned' => count($customers),
            // So the assistant knows there is more instead of concluding from
            // one page that it has seen everything.
            'total_count' => $total,
            'offset' => $offset,
            'language' => $iso,
        ]);
    }

    /**
     * The query both the count and the page are built on: the customers of the
     * shop and the filters, without what is selected.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     *
     * @return DbQuery
     */
    private static function query(array $arguments, int $idShop): DbQuery
    {
        $query = new DbQuery();
        $query->from('customer', 'c');
        // The shop comes from the token, never from the caller, and the grid
        // lists the shop's own customers even when customers are shared.
        $query->where('c.`id_shop` = ' . $idShop);
        // Deleting a customer while keeping their data only sets this flag;
        // the back office no longer lists them, and neither does this tool.
        $query->where('c.`deleted` = 0');

        $words = isset($arguments['query']) && is_scalar($arguments['query'])
            ? preg_split('/\s+/', trim((string) $arguments['query'])) ?: []
            : [];
        // The company is only searched when B2B mode shows it, as the core
        // search does.
        $columns = ['c.`firstname`', 'c.`lastname`', 'c.`email`'];
        if (Configuration::get('PS_B2B_ENABLE', null, null, $idShop)) {
            $columns[] = 'c.`company`';
        }
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $like = " LIKE '%" . pSQL($word) . "%'";
            $query->where('(' . implode(' OR ', array_map(static function (string $column) use ($like): string {
                return $column . $like;
            }, $columns)) . ')');
        }

        if (isset($arguments['email']) && is_scalar($arguments['email']) && trim((string) $arguments['email']) !== '') {
            $query->where("c.`email` = '" . pSQL(trim((string) $arguments['email'])) . "'");
        }
        foreach (['id_default_group', 'id_gender'] as $key) {
            if (isset($arguments[$key]) && is_numeric($arguments[$key])) {
                $query->where('c.`' . $key . '` = ' . (int) $arguments[$key]);
            }
        }
        // Each flag left out means "All", as on the list; the column is fixed
        // here, never taken from the caller.
        foreach (['active' => 'active', 'newsletter' => 'newsletter', 'partner_offers' => 'optin', 'is_guest' => 'is_guest'] as $key => $column) {
            if (isset($arguments[$key]) && is_bool($arguments[$key])) {
                $query->where('c.`' . $column . '` = ' . ($arguments[$key] ? 1 : 0));
            }
        }
        // Both dates included, from the first to the last second of the day,
        // as the Registration filter of the grid.
        if (!empty($arguments['date_from']) && is_string($arguments['date_from']) && Validate::isDate($arguments['date_from'])) {
            $query->where("c.`date_add` >= '" . pSQL($arguments['date_from']) . " 00:00:00'");
        }
        if (!empty($arguments['date_to']) && is_string($arguments['date_to']) && Validate::isDate($arguments['date_to'])) {
            $query->where("c.`date_add` <= '" . pSQL($arguments['date_to']) . " 23:59:59'");
        }

        return $query;
    }

    /**
     * What the customer spent in the shop's valid orders, tax included, in the
     * default currency.
     *
     * PrestaShop 8.0 sums the amount paid on its Sales column and 9.0 the tax
     * included total; one figure is returned on both so the answer does not
     * depend on the version, at the cost of differing from the 8.0 screen for
     * an order not fully paid.
     *
     * @param int $idShop
     *
     * @return string
     */
    private static function salesExpression(int $idShop): string
    {
        return 'IFNULL((SELECT SUM(o.`total_paid_tax_incl` / o.`conversion_rate`) FROM `' . _DB_PREFIX_ . 'orders` o'
            . ' WHERE o.`id_customer` = c.`id_customer` AND o.`valid` = 1 AND o.`id_shop` = ' . $idShop . '), 0)';
    }

    /**
     * The latest connection of any visitor tied to the customer, as the Last
     * visit column of the grid.
     *
     * @return string
     */
    private static function lastVisitExpression(): string
    {
        return '(SELECT MAX(co.`date_add`) FROM `' . _DB_PREFIX_ . 'connections` co'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'guest` g ON g.`id_guest` = co.`id_guest`'
            . ' WHERE g.`id_customer` = c.`id_customer`)';
    }

    /**
     * The ORDER BY clause, from a fixed list only.
     *
     * @param array<string, mixed> $arguments
     *
     * @return string
     */
    private static function sort(array $arguments): string
    {
        $direction = isset($arguments['order']) && $arguments['order'] === 'asc' ? 'ASC' : 'DESC';
        // Each column is a literal chosen by a fixed key, so nothing the caller
        // sends reaches the clause. The identifier breaks ties, so a page never
        // repeats or skips a customer registered in the same second as another.
        switch ((string) ($arguments['sort'] ?? '')) {
            case 'last_visit':
                return '`last_visit` ' . $direction . ', c.`id_customer` ' . $direction;
            case 'sales':
                return '`sales` ' . $direction . ', c.`id_customer` ' . $direction;
            case 'lastname':
                return 'c.`lastname` ' . $direction . ', c.`id_customer` ' . $direction;
            case 'id_customer':
                return 'c.`id_customer` ' . $direction;
            default:
                return 'c.`date_add` ' . $direction . ', c.`id_customer` ' . $direction;
        }
    }

    /**
     * The language to read the social titles and group names in, as
     * [id_lang, iso], or null for a code the shop does not have.
     *
     * Left out, it is the shop default rather than the context language, which
     * on an MCP call follows the client's browser header.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     *
     * @return array{0: int, 1: string}|null
     */
    private static function language(array $arguments, int $idShop): ?array
    {
        if (!array_key_exists('language', $arguments) || $arguments['language'] === null || $arguments['language'] === '') {
            $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

            return [$idLang, (string) Language::getIsoById($idLang)];
        }
        $languages = SomcpToolAuditProductSeo::languages($arguments, $idShop);
        if ($languages === null) {
            return null;
        }

        return [(int) array_key_first($languages), (string) reset($languages)];
    }

    /**
     * A database date as ISO 8601, or null when there is none.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private static function isoDate($value): ?string
    {
        if (!is_string($value) || $value === '' || strpos($value, '0000-00-00') === 0) {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? null : date('c', $time);
    }

    /**
     * How many customers to return, within the limits every list follows.
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
