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
 * somcp_list_catalog_price_rules - the catalogue price rules of the shop.
 *
 * The list matches Catalog > Discounts > Catalog Price Rules: the
 * specific_price_rule rows, id_specific_price_rule ascending (the controller's
 * default), with the conditions that pick their products. Read only.
 *
 * A catalogue price rule discounts products before the cart, so its reduction
 * is read the way somcp_get_product reads a product's specific prices: an
 * amount as stored, a percentage as the fraction (0.1 for 10 %). The rule
 * stores the percentage as shown (10) and SpecificPriceRule::applyRuleToProduct
 * divides it by 100 for the specific_price it writes; the tool converts here so
 * the assistant reads the same number on the rule and on the products it touches.
 *
 * The building of one rule and the reading of its conditions are each one
 * function, which the create tool (#111) calls to answer in the
 * same shape rather than keeping a second copy that could drift.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListCatalogPriceRules
{
    /** @var int Rules returned when the caller asks for no particular number. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Most a single call will return. */
    private const MAX_LIMIT = 100;

    /** @var array<int, string> */
    private const FIELDS = ['name', 'currency', 'country', 'id_group', 'reduction_type', 'current', 'language', 'limit', 'offset'];

    /**
     * The product-selection condition types of specific_price_rule_condition,
     * each mapped to the output key that carries the identifier it holds and the
     * list tool that gives that identifier. Country, currency and group are
     * columns of the rule itself, not conditions.
     *
     * @var array<string, string>
     */
    private const CONDITION_IDS = [
        'category' => 'id_category',
        'manufacturer' => 'id_manufacturer',
        'supplier' => 'id_supplier',
        'attribute' => 'id_attribute',
        'feature' => 'id_feature_value',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_catalog_price_rules',
            Somcp::t('List catalogue price rules'),
            'Lists the catalogue price rules of this shop - discounts applied to products before the cart - as the '
                . 'list Catalog > Discounts > Catalog Price Rules shows them, with the conditions that pick each '
                . 'rule\'s products. Read only. For cart rules and the vouchers a customer types use '
                . 'somcp_list_cart_rules; for one product\'s own specific prices use somcp_get_product.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Part of the rule\'s name, without case, as the Name filter of the list.',
                    ],
                    'currency' => [
                        'type' => 'string',
                        'description' => 'ISO code of a currency, as the Currency filter. Keeps the rules for that '
                            . 'currency and those for all currencies.',
                    ],
                    'country' => [
                        'type' => 'string',
                        'description' => 'ISO code of a country, as the Country filter. Keeps the rules for that '
                            . 'country and those for all countries.',
                    ],
                    'id_group' => [
                        'type' => 'integer',
                        'description' => 'A customer group, from somcp_list_customer_groups, as the Group filter. '
                            . 'Keeps the rules for that group and those for all groups.',
                    ],
                    'reduction_type' => [
                        'type' => 'string',
                        'description' => 'amount or percentage, as the Reduction type filter.',
                    ],
                    'current' => [
                        'type' => 'boolean',
                        'description' => 'True keeps only the rules whose Beginning/End period holds the shop\'s '
                            . 'current time. An empty Beginning or End is open on that side.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for the names of '
                            . 'currencies, countries, groups and conditions. The shop default when left out.',
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
            // edit right of Catalog > Discounts > Catalog Price Rules.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminSpecificPriceRule')
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

        if (array_key_exists('name', $arguments) && $arguments['name'] !== null && !is_string($arguments['name'])) {
            $problems[] = '- name: a text';
        }

        if (array_key_exists('current', $arguments) && $arguments['current'] !== null && !is_bool($arguments['current'])) {
            $problems[] = '- current: true or false';
        }

        if (array_key_exists('reduction_type', $arguments) && $arguments['reduction_type'] !== null) {
            if (!is_string($arguments['reduction_type']) || !in_array($arguments['reduction_type'], ['amount', 'percentage'], true)) {
                $problems[] = '- reduction_type: amount or percentage';
            }
        }

        // Currency and country are kept by ISO code, resolved to the id the
        // column holds; a rule for "all" (id 0) is kept whatever the filter.
        $idCurrency = null;
        if (array_key_exists('currency', $arguments) && $arguments['currency'] !== null && $arguments['currency'] !== '') {
            $iso = is_string($arguments['currency']) ? $arguments['currency'] : '';
            $resolved = preg_match('/^[A-Za-z]{3}$/', $iso) ? (int) Currency::getIdByIsoCode(Tools::strtoupper($iso), $idShop) : 0;
            if ($resolved <= 0) {
                $problems[] = '- currency: not a currency of this shop';
            } else {
                $idCurrency = $resolved;
            }
        }

        $idCountry = null;
        if (array_key_exists('country', $arguments) && $arguments['country'] !== null && $arguments['country'] !== '') {
            $iso = is_string($arguments['country']) ? Tools::strtoupper($arguments['country']) : '';
            $resolved = preg_match('/^[A-Z]{2}$/', $iso) ? (int) Country::getByIso($iso) : 0;
            if ($resolved <= 0) {
                $problems[] = '- country: no country of this shop has the ISO code "' . (string) ($arguments['country'] ?? '') . '"';
            } else {
                $idCountry = $resolved;
            }
        }

        $idGroup = null;
        if (array_key_exists('id_group', $arguments) && $arguments['id_group'] !== null) {
            if (!is_int($arguments['id_group']) || $arguments['id_group'] < 1 || !self::groupExists((int) $arguments['id_group'])) {
                $problems[] = '- id_group: not a group of this shop (take id_group from somcp_list_customer_groups)';
            } else {
                $idGroup = (int) $arguments['id_group'];
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

        $where = self::conditions($arguments, $idCurrency, $idCountry, $idGroup);
        $db = Db::getInstance();

        // The count joins the name as the page does, so the name filter and the
        // total read the same rows.
        $from = '`' . _DB_PREFIX_ . 'specific_price_rule` spr';
        $total = (int) $db->getValue('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where);

        $rows = $db->executeS(
            'SELECT spr.* FROM ' . $from . '
             WHERE ' . $where . '
             ORDER BY spr.`id_specific_price_rule` ASC
             LIMIT ' . $offset . ', ' . $limit
        ) ?: [];

        $defaultCurrency = (string) Currency::getIsoCodeById((int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop));

        $rules = [];
        foreach ($rows as $row) {
            $rules[] = self::buildRule($row, (int) $idLang, $defaultCurrency);
        }

        return SomcpTool::json([
            'catalog_price_rules' => $rules,
            'returned' => count($rules),
            'total_count' => $total,
            'offset' => $offset,
            'language' => $iso,
        ]);
    }

    /**
     * The WHERE clause: the token's shop, then the caller's filters. A rule for
     * "all" currencies, countries or groups (id 0) is kept whatever the filter.
     *
     * @param array<string, mixed> $arguments
     * @param int|null $idCurrency
     * @param int|null $idCountry
     * @param int|null $idGroup
     *
     * @return string
     */
    private static function conditions(array $arguments, ?int $idCurrency, ?int $idCountry, ?int $idGroup): string
    {
        // The back office lists every shop's rules (CONTEXT_ALL); the token
        // reaches one shop, and a rule written for another shop is not its.
        $conditions = ['spr.`id_shop` IN (0, ' . (int) Somcp::currentShopId() . ')'];

        if (isset($arguments['name']) && is_string($arguments['name']) && trim($arguments['name']) !== '') {
            $conditions[] = "spr.`name` LIKE '%" . pSQL(trim($arguments['name'])) . "%'";
        }
        if ($idCurrency !== null) {
            $conditions[] = 'spr.`id_currency` IN (0, ' . $idCurrency . ')';
        }
        if ($idCountry !== null) {
            $conditions[] = 'spr.`id_country` IN (0, ' . $idCountry . ')';
        }
        if ($idGroup !== null) {
            $conditions[] = 'spr.`id_group` IN (0, ' . $idGroup . ')';
        }
        if (isset($arguments['reduction_type']) && is_string($arguments['reduction_type']) && $arguments['reduction_type'] !== '') {
            $conditions[] = "spr.`reduction_type` = '" . pSQL($arguments['reduction_type']) . "'";
        }
        if (isset($arguments['current']) && $arguments['current'] === true) {
            // The same clock the rest of the module reads dates against. An
            // empty bound is the zero date, open on that side.
            $now = date('Y-m-d H:i:s');
            $conditions[] = "(spr.`from` = '0000-00-00 00:00:00' OR spr.`from` <= '" . pSQL($now) . "')";
            $conditions[] = "(spr.`to` = '0000-00-00 00:00:00' OR spr.`to` >= '" . pSQL($now) . "')";
        }

        return implode(' AND ', $conditions);
    }

    /**
     * One catalogue price rule, in the shape somcp_get_product reads a specific
     * price: the reduction as an amount or the fraction of a percentage, the
     * currency of the amount, the period, and the conditions that pick its
     * products. Shared with the create tool (#111) so a rule is
     * read the same way whichever tool answers.
     *
     * @param array<string, mixed> $row a specific_price_rule row
     * @param int $idLang
     * @param string $defaultCurrency ISO code of the shop default currency
     *
     * @return array<string, mixed>
     */
    public static function buildRule(array $row, int $idLang, string $defaultCurrency): array
    {
        $type = (string) $row['reduction_type'];
        // The rule stores a percentage as shown (10); the shop applies it as a
        // fraction (0.1), which is what somcp_get_product returns for the same
        // promotion. An amount is stored and read as itself.
        $reduction = (float) $row['reduction'];
        if ($type === 'percentage') {
            $reduction = round($reduction / 100, 6);
        }

        $idCurrency = (int) $row['id_currency'];
        $idGroup = (int) $row['id_group'];

        return [
            'id_specific_price_rule' => (int) $row['id_specific_price_rule'],
            'name' => (string) $row['name'],
            'id_shop' => (int) $row['id_shop'],
            // 0 everywhere below means "all", as in the back office.
            'currency' => $idCurrency > 0 ? (string) Currency::getIsoCodeById($idCurrency) : null,
            'country' => (int) $row['id_country'] > 0 ? (string) Country::getIsoById((int) $row['id_country']) : null,
            'id_group' => $idGroup,
            'group_name' => $idGroup > 0 ? self::groupName($idGroup, $idLang) : null,
            'from_quantity' => (int) $row['from_quantity'],
            // -1 keeps the product's own price and only applies the reduction.
            'price' => (float) $row['price'],
            'reduction_type' => $type,
            'reduction' => $reduction,
            'reduction_tax' => (bool) $row['reduction_tax'],
            // Amounts of a rule without a currency of its own are in the shop
            // default currency, as somcp_get_product reports them.
            'amounts_currency' => $idCurrency > 0 ? (string) Currency::getIsoCodeById($idCurrency) : $defaultCurrency,
            'from' => self::date((string) $row['from']),
            'to' => self::date((string) $row['to']),
            'condition_groups' => self::conditionGroups((int) $row['id_specific_price_rule'], $idLang),
            'current' => self::holdsNow((string) $row['from'], (string) $row['to']),
        ];
    }

    /**
     * The conditions that pick a rule's products, as a list of groups. The
     * conditions of one group must all hold (AND); a product is touched when one
     * group holds (OR); an empty list means the rule applies to every product.
     * One function, called by this tool and by #111.
     *
     * @param int $idRule
     * @param int $idLang
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function conditionGroups(int $idRule, int $idLang): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT g.`id_specific_price_rule_condition_group` AS `id_group`, c.`type`, c.`value`
             FROM `' . _DB_PREFIX_ . 'specific_price_rule_condition_group` g
             LEFT JOIN `' . _DB_PREFIX_ . 'specific_price_rule_condition` c
                    ON c.`id_specific_price_rule_condition_group` = g.`id_specific_price_rule_condition_group`
             WHERE g.`id_specific_price_rule` = ' . (int) $idRule . '
             ORDER BY g.`id_specific_price_rule_condition_group` ASC, c.`id_specific_price_rule_condition` ASC'
        ) ?: [];

        $groups = [];
        foreach ($rows as $row) {
            $idGroup = (int) $row['id_group'];
            if (!array_key_exists($idGroup, $groups)) {
                $groups[$idGroup] = [];
            }
            // A condition group row with no condition (type null) is an empty
            // group: it carries no product condition, so nothing is added.
            $type = $row['type'] !== null ? (string) $row['type'] : '';
            if (!isset(self::CONDITION_IDS[$type])) {
                continue;
            }
            $idValue = (int) $row['value'];
            $groups[$idGroup][] = [
                'type' => $type,
                self::CONDITION_IDS[$type] => $idValue,
                'name' => self::conditionName($type, $idValue, $idLang),
            ];
        }

        // Drop empty groups and renumber, so condition_groups is a plain list of
        // non-empty groups.
        return array_values(array_filter($groups, static function ($group) {
            return $group !== [];
        }));
    }

    /**
     * The name a condition's target shows on screen, in the asked language.
     *
     * @param string $type
     * @param int $id
     * @param int $idLang
     *
     * @return string
     */
    private static function conditionName(string $type, int $id, int $idLang): string
    {
        $db = Db::getInstance();
        switch ($type) {
            case 'category':
                return (string) $db->getValue(
                    'SELECT `name` FROM `' . _DB_PREFIX_ . 'category_lang`'
                    . ' WHERE `id_category` = ' . $id . ' AND `id_lang` = ' . $idLang
                    . ' AND `id_shop` = ' . (int) Somcp::currentShopId()
                );
            case 'manufacturer':
                return (string) $db->getValue('SELECT `name` FROM `' . _DB_PREFIX_ . 'manufacturer` WHERE `id_manufacturer` = ' . $id);
            case 'supplier':
                return (string) $db->getValue('SELECT `name` FROM `' . _DB_PREFIX_ . 'supplier` WHERE `id_supplier` = ' . $id);
            case 'attribute':
                return (string) $db->getValue(
                    'SELECT `name` FROM `' . _DB_PREFIX_ . 'attribute_lang`'
                    . ' WHERE `id_attribute` = ' . $id . ' AND `id_lang` = ' . $idLang
                );
            case 'feature':
                return (string) $db->getValue(
                    'SELECT `value` FROM `' . _DB_PREFIX_ . 'feature_value_lang`'
                    . ' WHERE `id_feature_value` = ' . $id . ' AND `id_lang` = ' . $idLang
                );
            default:
                return '';
        }
    }

    /**
     * Whether a customer group exists, to refuse an id_group filter that names
     * no group of the shop.
     *
     * @param int $idGroup
     *
     * @return bool
     */
    private static function groupExists(int $idGroup): bool
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'group` WHERE `id_group` = ' . $idGroup
        ) > 0;
    }

    /**
     * A customer group's name in the asked language.
     *
     * @param int $idGroup
     * @param int $idLang
     *
     * @return string
     */
    private static function groupName(int $idGroup, int $idLang): string
    {
        return (string) Db::getInstance()->getValue(
            'SELECT `name` FROM `' . _DB_PREFIX_ . 'group_lang`'
            . ' WHERE `id_group` = ' . $idGroup . ' AND `id_lang` = ' . $idLang
        );
    }

    /**
     * A stored datetime as it is kept, or null when the column is the zero date
     * PrestaShop stores for "no date" - the shape somcp_get_product returns.
     *
     * @param string $value
     *
     * @return string|null
     */
    private static function date(string $value): ?string
    {
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return null;
        }

        return $value;
    }

    /**
     * Whether a period holds the shop's current time, an empty bound being open
     * on its side. The value behind the current output field and the current
     * filter, read the same way.
     *
     * @param string $from
     * @param string $to
     *
     * @return bool
     */
    private static function holdsNow(string $from, string $to): bool
    {
        $now = date('Y-m-d H:i:s');
        $afterStart = self::date($from) === null || $from <= $now;
        $beforeEnd = self::date($to) === null || $to >= $now;

        return $afterStart && $beforeEnd;
    }
}
