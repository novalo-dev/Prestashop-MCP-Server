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
 * somcp_list_cart_rules - the vouchers and cart discounts of the shop.
 *
 * The list matches Catalog > Discounts > Cart Rules: the cart_rule rows, their
 * cart_rule_lang name, newest first (id_cart_rule descending, the list's
 * _orderWay). What each rule gives is read straight from its own columns, so
 * reduction, gift and the one-line summary are built once here and the detail
 * tool (the get, #106) calls the same builder rather than keeping
 * a second copy that could drift.
 *
 * Read only. The back office list shows every shop's rules; the token's shop
 * sees only the rules it can use (shop_restriction off, or a cart_rule_shop
 * row for it), because a rule restricted to another shop never applies here.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListCartRules
{
    /** @var int Rules returned when the caller asks for no particular number. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Most a single call will return. */
    private const MAX_LIMIT = 100;

    /** @var array<int, string> */
    private const FIELDS = ['active', 'name', 'code', 'has_code', 'current', 'language', 'limit', 'offset'];

    /** @var array<string, string> The restriction columns, in the order has_restrictions reports them. */
    private const RESTRICTIONS = [
        'country_restriction' => 'country',
        'carrier_restriction' => 'carrier',
        'group_restriction' => 'group',
        'cart_rule_restriction' => 'cart_rule',
        'product_restriction' => 'product',
        'shop_restriction' => 'shop',
    ];

    /** @var array<int, string> ISO code of a currency id, resolved once and kept. */
    private static $currencyIso = [];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_cart_rules',
            Somcp::t('List cart rules'),
            'Lists the cart rules of this shop - vouchers the customer types a code for, and discounts applied on '
                . 'their own - newest first, as the list Catalog > Discounts > Cart Rules shows them, with what each '
                . 'one gives in one line. Read only: this is the list, not one rule\'s full conditions and '
                . 'restrictions. For the vouchers used on one order use somcp_get_order.',
            [
                'type' => 'object',
                'properties' => [
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'True for the rules that are on (the Status column), false for the ones that are off.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Part of the rule\'s name, without case, as the Name filter of the list.',
                    ],
                    'code' => [
                        'type' => 'string',
                        'description' => 'Part of the code, without case, as the Code filter. To split the rules that '
                            . 'have a code from the ones applied on their own, use has_code rather than an empty code.',
                    ],
                    'has_code' => [
                        'type' => 'boolean',
                        'description' => 'True for the rules with a code (vouchers the customer types), false for the '
                            . 'rules applied on their own, with no code.',
                    ],
                    'current' => [
                        'type' => 'boolean',
                        'description' => 'True keeps only the rules valid right now: their Valid From/To period holds '
                            . 'the shop\'s current time and they have uses left (Quantity above 0).',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for the rule name. '
                            . 'The shop default when left out.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many rules to return, 20 by default and 100 at most.',
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
            // The list and the rule page open with view: nothing here needs the
            // edit right of Catalog > Discounts > Cart Rules.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminCartRules')
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

        // Every mistake is reported in one answer, so the call is corrected in
        // one go (the shape of #169).
        $problems = [];
        foreach (array_keys($arguments) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                $problems[] = '- ' . $field . ': not a field of this tool';
            }
        }

        foreach (['active', 'has_code', 'current'] as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== null && !is_bool($arguments[$field])) {
                $problems[] = '- ' . $field . ': true or false';
            }
        }

        if (array_key_exists('name', $arguments) && $arguments['name'] !== null && !is_string($arguments['name'])) {
            $problems[] = '- name: a text';
        }
        // An empty code would match every rule: the split between coded and
        // uncoded rules is has_code, so an empty code is a mistake, not a
        // silent "all".
        if (array_key_exists('code', $arguments) && $arguments['code'] !== null) {
            if (!is_string($arguments['code'])) {
                $problems[] = '- code: a text';
            } elseif (trim($arguments['code']) === '') {
                $problems[] = '- code: a piece of a code to look for; for the rules with no code use has_code: false';
            }
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

        $where = self::conditions($arguments, $idShop);
        $db = Db::getInstance();

        // The count joins the names as the page does: the name filter reads
        // them, and a total counted on other rows would not match the list.
        $from = '`' . _DB_PREFIX_ . 'cart_rule` cr
             LEFT JOIN `' . _DB_PREFIX_ . 'cart_rule_lang` crl
                    ON crl.`id_cart_rule` = cr.`id_cart_rule` AND crl.`id_lang` = ' . (int) $idLang;

        $total = (int) $db->getValue('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where);

        $rows = $db->executeS(
            'SELECT cr.*, crl.`name`, ' . self::timesUsed() . ' AS `times_used`
             FROM ' . $from . '
             WHERE ' . $where . '
             ORDER BY cr.`id_cart_rule` DESC
             LIMIT ' . $offset . ', ' . $limit
        ) ?: [];

        $cartRules = [];
        foreach ($rows as $row) {
            $built = self::describe($row);
            $cartRules[] = array_merge(
                [
                    'id_cart_rule' => (int) $row['id_cart_rule'],
                    'name' => (string) ($row['name'] ?? ''),
                    'priority' => (int) $row['priority'],
                    'code' => (string) $row['code'],
                    'quantity' => (int) $row['quantity'],
                    'quantity_per_user' => (int) $row['quantity_per_user'],
                    'date_from' => self::iso((string) $row['date_from']),
                    'date_to' => self::iso((string) $row['date_to']),
                    'active' => (bool) $row['active'],
                    // null when the rule is for everyone, as somcp_get_customer
                    // expects the identifier only when there is one.
                    'id_customer' => (int) $row['id_customer'] > 0 ? (int) $row['id_customer'] : null,
                ],
                $built,
                [
                    'has_restrictions' => self::restrictions($row),
                    // The use the screen does not show: orders that spent it.
                    'times_used' => (int) $row['times_used'],
                ]
            );
        }

        return SomcpTool::json([
            'cart_rules' => $cartRules,
            'returned' => count($cartRules),
            'total_count' => $total,
            'offset' => $offset,
            'language' => $iso,
        ]);
    }

    /**
     * The WHERE clause: the rules this shop can use, then the caller's filters.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     *
     * @return string
     */
    private static function conditions(array $arguments, int $idShop): string
    {
        $conditions = [self::inShop($idShop)];

        if (isset($arguments['active']) && is_bool($arguments['active'])) {
            $conditions[] = 'cr.`active` = ' . ($arguments['active'] ? 1 : 0);
        }
        if (isset($arguments['name']) && is_string($arguments['name']) && trim($arguments['name']) !== '') {
            $conditions[] = "crl.`name` LIKE '%" . pSQL(trim($arguments['name'])) . "%'";
        }
        if (isset($arguments['code']) && is_string($arguments['code']) && trim($arguments['code']) !== '') {
            $conditions[] = "cr.`code` LIKE '%" . pSQL(trim($arguments['code'])) . "%'";
        }
        if (isset($arguments['has_code']) && is_bool($arguments['has_code'])) {
            $conditions[] = $arguments['has_code'] ? "cr.`code` <> ''" : "cr.`code` = ''";
        }
        if (isset($arguments['current']) && $arguments['current'] === true) {
            // The same clock the rest of the module reads dates against.
            $now = date('Y-m-d H:i:s');
            $conditions[] = "cr.`date_from` <= '" . pSQL($now) . "'";
            $conditions[] = "cr.`date_to` >= '" . pSQL($now) . "'";
            $conditions[] = 'cr.`quantity` > 0';
        }

        return implode(' AND ', $conditions);
    }

    /**
     * The rules this shop can use, as a condition on the alias cr. Shared with
     * the detail get, so a rule the list hides is also one the get refuses.
     *
     * The back office lists every shop's rules; the token reaches one shop,
     * and a rule restricted to another shop could never apply to it.
     *
     * @param int $idShop
     *
     * @return string
     */
    public static function inShop(int $idShop): string
    {
        return '(cr.`shop_restriction` = 0 OR EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'cart_rule_shop` crs
                WHERE crs.`id_cart_rule` = cr.`id_cart_rule` AND crs.`id_shop` = ' . $idShop . '))';
    }

    /**
     * The orders that spent a rule, as a subquery on the alias cr: the use the
     * screen does not show. A deleted order_cart_rule line is a voucher taken
     * off the order, so it does not count.
     *
     * @return string
     */
    public static function timesUsed(): string
    {
        return '(SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_cart_rule` ocr
                     WHERE ocr.`id_cart_rule` = cr.`id_cart_rule` AND ocr.`deleted` = 0)';
    }

    /**
     * What a cart rule gives, built from its own columns: the discount, what it
     * applies to, the gift, and a one-line summary. Shared with the detail get
     * (#106) so a rule is read the same way whichever tool reads it.
     *
     * @param array<string, mixed> $row a cart_rule row, with its reduction and gift columns
     *
     * @return array{reduction: array<string, mixed>|null, reduction_applies_to: string|null, gift: array<string, int>|null, summary: string}
     */
    public static function describe(array $row): array
    {
        $freeShipping = (bool) ($row['free_shipping'] ?? false);

        $reduction = null;
        $percent = (float) ($row['reduction_percent'] ?? 0);
        $amount = (float) ($row['reduction_amount'] ?? 0);
        if ($percent > 0) {
            $reduction = ['type' => 'percent', 'value' => round($percent, 2)];
        } elseif ($amount > 0) {
            $reduction = [
                'type' => 'amount',
                'value' => round($amount, 2),
                'currency' => self::currencyIso((int) ($row['reduction_currency'] ?? 0)),
                'tax_included' => (bool) ($row['reduction_tax'] ?? false),
            ];
        }

        // Where the discount lands, only when there is one.
        $appliesTo = null;
        if ($reduction !== null) {
            $product = (int) ($row['reduction_product'] ?? 0);
            if ($product > 0) {
                $appliesTo = 'specific_product';
                $reduction['id_product'] = $product;
            } elseif ($product === -1) {
                $appliesTo = 'cheapest_product';
            } elseif ($product === -2) {
                $appliesTo = 'selected_products';
            } else {
                $appliesTo = 'order';
            }
        }

        $gift = null;
        if ((int) ($row['gift_product'] ?? 0) > 0) {
            $gift = [
                'id_product' => (int) $row['gift_product'],
                'id_product_attribute' => (int) ($row['gift_product_attribute'] ?? 0),
            ];
        }

        return [
            'reduction' => $reduction,
            'reduction_applies_to' => $appliesTo,
            'gift' => $gift,
            'summary' => self::summary($freeShipping, $reduction, $appliesTo, $gift),
            'free_shipping' => $freeShipping,
        ];
    }

    /**
     * One English line describing what the rule gives, for a merchant reading
     * the list without opening each rule.
     *
     * @param bool $freeShipping
     * @param array<string, mixed>|null $reduction
     * @param string|null $appliesTo
     * @param array<string, int>|null $gift
     *
     * @return string
     */
    private static function summary(bool $freeShipping, ?array $reduction, ?string $appliesTo, ?array $gift): string
    {
        $parts = [];
        if ($reduction !== null) {
            $target = [
                'order' => 'the order',
                'specific_product' => 'a product',
                'cheapest_product' => 'the cheapest product',
                'selected_products' => 'selected products',
            ][$appliesTo ?? 'order'] ?? 'the order';
            if ($reduction['type'] === 'percent') {
                $parts[] = self::number((float) $reduction['value']) . '% off ' . $target;
            } else {
                $parts[] = self::number((float) $reduction['value']) . ' ' . (string) $reduction['currency']
                    . ' off ' . $target;
            }
        }
        if ($freeShipping) {
            $parts[] = 'free shipping';
        }
        if ($gift !== null) {
            $parts[] = 'a free gift';
        }

        return $parts === [] ? 'no discount' : implode(', ', $parts);
    }

    /**
     * The restriction names that are on, in a fixed order, so the assistant
     * knows which rules have conditions worth reading with the detail get (#106).
     *
     * @param array<string, mixed> $row
     *
     * @return array<int, string>
     */
    private static function restrictions(array $row): array
    {
        $on = [];
        foreach (self::RESTRICTIONS as $column => $name) {
            if ((int) ($row[$column] ?? 0) === 1) {
                $on[] = $name;
            }
        }

        return $on;
    }

    /**
     * A stored datetime as ISO 8601, or an empty string when the column is the
     * zero date PrestaShop stores for "no date".
     *
     * @param string $value
     *
     * @return string
     */
    public static function iso(string $value): string
    {
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return '';
        }
        $time = strtotime($value);

        return $time === false ? '' : date('c', $time);
    }

    /**
     * The ISO code of a currency id, the shop default when the id is 0 (an
     * amount rule without a currency). Resolved once per id and kept.
     *
     * @param int $idCurrency
     *
     * @return string
     */
    public static function currencyIso(int $idCurrency): string
    {
        if ($idCurrency <= 0) {
            $idCurrency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        }
        if (!array_key_exists($idCurrency, self::$currencyIso)) {
            self::$currencyIso[$idCurrency] = (string) Db::getInstance()->getValue(
                'SELECT `iso_code` FROM `' . _DB_PREFIX_ . 'currency` WHERE `id_currency` = ' . $idCurrency
            );
        }

        return self::$currencyIso[$idCurrency];
    }

    /**
     * A number without trailing zeros, so a summary reads "10% off" not
     * "10.00% off".
     *
     * @param float $value
     *
     * @return string
     */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
