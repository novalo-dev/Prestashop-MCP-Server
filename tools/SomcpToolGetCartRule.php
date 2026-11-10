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
 * somcp_get_cart_rule - one cart rule, every field of its three tabs.
 *
 * Catalog > Discounts > Cart Rules > Edit shows a rule on three tabs
 * (Information, Conditions, Actions). This tool returns all of them under the
 * names the cart rule write tools (#107, #108) take, so a rule read here can
 * be given back as is. What the rule gives (reduction, gift,
 * summary) comes from SomcpToolListCartRules::describe(), the builder the list
 * uses, so the two tools never read a rule two ways.
 *
 * Read only. A rule the token's shop cannot use is answered as unknown, the
 * same rule the list applies.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetCartRule
{
    /** @var array<int, string> */
    private const FIELDS = ['id_cart_rule', 'language'];

    /** @var array<int, string> The Product selection types the core stores, in the order of the form's select. */
    private const PRODUCT_TYPES = ['products', 'categories', 'attributes', 'manufacturers', 'suppliers'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_cart_rule',
            Somcp::t('Get a cart rule'),
            'Returns every field of one cart rule, as the three tabs Information, Conditions and Actions of Catalog > '
                . 'Discounts > Cart Rules > Edit show it: name in every language, code, dates, minimum amount, '
                . 'quantities, each restriction as a list of identifiers and names, and what the rule gives. Read only. '
                . 'To find a rule use somcp_list_cart_rules. Catalogue price rules are not cart rules.',
            [
                'type' => 'object',
                'properties' => [
                    'id_cart_rule' => [
                        'type' => 'integer',
                        'description' => 'The rule\'s identifier, the ID column of somcp_list_cart_rules.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of an active language of the shop, for example fr, for the names of '
                            . 'the restrictions and the gift. The shop default when left out. The rule\'s name comes in '
                            . 'every language.',
                    ],
                ],
                'required' => ['id_cart_rule'],
                'additionalProperties' => false,
            ],
            [SomcpScope::SALES_READ],
            [self::class, 'handle'],
            // The rule page opens with view: nothing here needs the edit right.
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

        $idCartRule = 0;
        if (!isset($arguments['id_cart_rule']) || !is_int($arguments['id_cart_rule']) || $arguments['id_cart_rule'] < 1) {
            $problems[] = '- id_cart_rule: a whole number above 0, the ID given by somcp_list_cart_rules';
        } else {
            $idCartRule = (int) $arguments['id_cart_rule'];
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

        $cartRule = self::byId($idCartRule, $idLang, $idShop);
        if ($cartRule === null) {
            return SomcpTool::text(
                "Nothing was read. Correct these and call again:\n- id_cart_rule: no cart rule of this shop has the identifier "
                    . $idCartRule . ' - find it with somcp_list_cart_rules',
                true
            );
        }

        return SomcpTool::json([
            'cart_rule' => $cartRule,
            'language' => $iso,
        ]);
    }

    /**
     * One rule as this tool returns it, or null when the token's shop cannot
     * use it. Public so the write tools answer with the rule read back the
     * same way, rather than echoing their arguments.
     *
     * @param int $idCartRule
     * @param int $idLang language of the names of the restrictions and the gift
     * @param int $idShop
     *
     * @return array<string, mixed>|null
     */
    public static function byId(int $idCartRule, int $idLang, int $idShop): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT cr.*, ' . SomcpToolListCartRules::timesUsed() . ' AS `times_used`
             FROM `' . _DB_PREFIX_ . 'cart_rule` cr
             WHERE cr.`id_cart_rule` = ' . (int) $idCartRule . ' AND ' . SomcpToolListCartRules::inShop($idShop)
        );

        return is_array($row) && $row !== [] ? self::cartRule($row, $idLang, $idShop) : null;
    }

    /**
     * The whole rule, from its cart_rule row.
     *
     * @param array<string, mixed> $row
     * @param int $idLang
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    private static function cartRule(array $row, int $idLang, int $idShop): array
    {
        $idCartRule = (int) $row['id_cart_rule'];
        $built = SomcpToolListCartRules::describe($row);

        $idCustomer = (int) $row['id_customer'];
        $customer = null;
        if ($idCustomer > 0) {
            $names = Db::getInstance()->getRow(
                'SELECT `firstname`, `lastname` FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_customer` = ' . $idCustomer
            );
            if (is_array($names) && $names !== []) {
                $customer = ['firstname' => (string) $names['firstname'], 'lastname' => (string) $names['lastname']];
            }
        }

        // The gift carries its name, as the field of the Actions tab does.
        $gift = $built['gift'];
        if ($gift !== null) {
            $gift['name'] = self::productName((int) $gift['id_product'], $idLang, $idShop);
        }

        $minimum = (float) $row['minimum_amount'];

        return [
            'id_cart_rule' => $idCartRule,
            'name' => self::names($idCartRule, $idShop),
            'description' => (string) $row['description'],
            'code' => (string) $row['code'],
            'highlight' => (bool) $row['highlight'],
            'partial_use' => (bool) $row['partial_use'],
            'priority' => (int) $row['priority'],
            'active' => (bool) $row['active'],
            // null when the rule is for everyone, as somcp_get_customer
            // expects the identifier only when there is one.
            'id_customer' => $idCustomer > 0 ? $idCustomer : null,
            'customer' => $customer,
            'date_from' => SomcpToolListCartRules::iso((string) $row['date_from']),
            'date_to' => SomcpToolListCartRules::iso((string) $row['date_to']),
            'minimum_amount' => $minimum > 0 ? [
                'value' => round($minimum, 2),
                'currency' => SomcpToolListCartRules::currencyIso((int) $row['minimum_amount_currency']),
                'tax_included' => (bool) $row['minimum_amount_tax'],
                'shipping_included' => (bool) $row['minimum_amount_shipping'],
            ] : null,
            'quantity' => (int) $row['quantity'],
            'quantity_per_user' => (int) $row['quantity_per_user'],
            'restrictions' => self::restrictions($row, $idLang, $idShop),
            'free_shipping' => $built['free_shipping'],
            'reduction' => $built['reduction'],
            'reduction_applies_to' => $built['reduction_applies_to'],
            'reduction_exclude_special' => (bool) $row['reduction_exclude_special'],
            'gift' => $gift,
            'summary' => $built['summary'],
            'times_used' => (int) $row['times_used'],
            'date_add' => SomcpToolListCartRules::iso((string) $row['date_add']),
            'date_upd' => SomcpToolListCartRules::iso((string) $row['date_upd']),
        ];
    }

    /**
     * The rule's name in every active language of the shop, keyed by ISO code,
     * the shape the write tools take, so it can be given back unchanged. A
     * language with no name stored yet reads as an empty string.
     *
     * @param int $idCartRule
     * @param int $idShop
     *
     * @return array<string, string>
     */
    private static function names(int $idCartRule, int $idShop): array
    {
        $stored = [];
        foreach (Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'cart_rule_lang` WHERE `id_cart_rule` = ' . $idCartRule
        ) ?: [] as $line) {
            $stored[(int) $line['id_lang']] = (string) $line['name'];
        }

        $names = [];
        foreach (SomcpTool::activeLanguages($idShop) as $idLang => $iso) {
            $names[$iso] = $stored[$idLang] ?? '';
        }

        return $names;
    }

    /**
     * Each restriction of the Conditions tab: null when it is off, else the
     * list of what it is limited to. Public so the update tool compares a
     * rule with the same reading.
     *
     * A restriction that points to something deleted since (a carrier, a
     * product) is left out of its list, as the form leaves it out.
     *
     * @param array<string, mixed> $row a cart_rule row
     * @param int $idLang language of the names
     * @param int $idShop
     *
     * @return array<string, array<int, array<string, mixed>>|null>
     */
    public static function restrictions(array $row, int $idLang, int $idShop): array
    {
        $idCartRule = (int) $row['id_cart_rule'];
        $db = Db::getInstance();
        $on = static function (string $column) use ($row): bool {
            return (int) ($row[$column] ?? 0) === 1;
        };

        $country = null;
        if ($on('country_restriction')) {
            $country = self::pairs($db->executeS(
                'SELECT c.`id_country` AS `id`, cl.`name`
                 FROM `' . _DB_PREFIX_ . 'cart_rule_country` crc
                 INNER JOIN `' . _DB_PREFIX_ . 'country` c ON c.`id_country` = crc.`id_country`
                 LEFT JOIN `' . _DB_PREFIX_ . 'country_lang` cl
                        ON cl.`id_country` = c.`id_country` AND cl.`id_lang` = ' . $idLang . '
                 WHERE crc.`id_cart_rule` = ' . $idCartRule . '
                 ORDER BY c.`id_country`'
            ), 'id_country');
        }

        $carrier = null;
        if ($on('carrier_restriction')) {
            // The core stores the carrier's id_reference here, not its
            // id_carrier, so the rule survives a carrier being edited (which
            // makes a new id_carrier). The name is the live version's.
            $lines = $db->executeS(
                'SELECT c.`id_reference` AS `id`, c.`name`
                 FROM `' . _DB_PREFIX_ . 'cart_rule_carrier` crc
                 INNER JOIN `' . _DB_PREFIX_ . 'carrier` c ON c.`id_reference` = crc.`id_carrier` AND c.`deleted` = 0
                 WHERE crc.`id_cart_rule` = ' . $idCartRule . '
                 GROUP BY c.`id_reference`, c.`name`
                 ORDER BY c.`id_reference`'
            ) ?: [];
            foreach ($lines as &$line) {
                $line['name'] = SomcpTool::carrierName((string) $line['name']);
            }
            unset($line);
            $carrier = self::pairs($lines, 'id_reference');
        }

        $group = null;
        if ($on('group_restriction')) {
            $group = self::pairs($db->executeS(
                'SELECT g.`id_group` AS `id`, gl.`name`
                 FROM `' . _DB_PREFIX_ . 'cart_rule_group` crg
                 INNER JOIN `' . _DB_PREFIX_ . 'group` g ON g.`id_group` = crg.`id_group`
                 LEFT JOIN `' . _DB_PREFIX_ . 'group_lang` gl
                        ON gl.`id_group` = g.`id_group` AND gl.`id_lang` = ' . $idLang . '
                 WHERE crg.`id_cart_rule` = ' . $idCartRule . '
                 ORDER BY g.`id_group`'
            ), 'id_group');
        }

        $cartRule = null;
        if ($on('cart_rule_restriction')) {
            // A combination is stored once, with either rule on either side.
            $cartRule = self::pairs($db->executeS(
                'SELECT other.`id_cart_rule` AS `id`, crl.`name`
                 FROM `' . _DB_PREFIX_ . 'cart_rule_combination` crc
                 INNER JOIN `' . _DB_PREFIX_ . 'cart_rule` other
                        ON other.`id_cart_rule` = IF(crc.`id_cart_rule_1` = ' . $idCartRule . ', crc.`id_cart_rule_2`, crc.`id_cart_rule_1`)
                 LEFT JOIN `' . _DB_PREFIX_ . 'cart_rule_lang` crl
                        ON crl.`id_cart_rule` = other.`id_cart_rule` AND crl.`id_lang` = ' . $idLang . '
                 WHERE crc.`id_cart_rule_1` = ' . $idCartRule . ' OR crc.`id_cart_rule_2` = ' . $idCartRule . '
                 GROUP BY other.`id_cart_rule`, crl.`name`
                 ORDER BY other.`id_cart_rule`'
            ), 'id_cart_rule');
        }

        $product = null;
        if ($on('product_restriction')) {
            $product = self::productSelection($idCartRule, $idLang, $idShop);
        }

        $shop = null;
        if ($on('shop_restriction')) {
            $shop = self::pairs($db->executeS(
                'SELECT s.`id_shop` AS `id`, s.`name`
                 FROM `' . _DB_PREFIX_ . 'cart_rule_shop` crs
                 INNER JOIN `' . _DB_PREFIX_ . 'shop` s ON s.`id_shop` = crs.`id_shop`
                 WHERE crs.`id_cart_rule` = ' . $idCartRule . '
                 ORDER BY s.`id_shop`'
            ), 'id_shop');
        }

        return [
            'country' => $country,
            'carrier' => $carrier,
            'group' => $group,
            'cart_rule' => $cartRule,
            'product' => $product,
            'shop' => $shop,
        ];
    }

    /**
     * Product selection: the groups of rules, each with the quantity the cart
     * must hold and its rules, read with the core's own CartRule reader so the
     * stored shape is never guessed.
     *
     * @param int $idCartRule
     * @param int $idLang
     * @param int $idShop
     *
     * @return array<int, array<string, mixed>>
     */
    private static function productSelection(int $idCartRule, int $idLang, int $idShop): array
    {
        $groups = [];
        foreach ((new CartRule($idCartRule))->getProductRuleGroups() as $ruleGroup) {
            $rules = [];
            foreach ((array) ($ruleGroup['product_rules'] ?? []) as $rule) {
                $type = (string) ($rule['type'] ?? '');
                if (!in_array($type, self::PRODUCT_TYPES, true)) {
                    continue;
                }
                $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($rule['values'] ?? [])))));
                $rules[] = ['type' => $type, 'values' => self::items($type, $ids, $idLang, $idShop)];
            }
            $groups[] = ['quantity' => (int) ($ruleGroup['quantity'] ?? 1), 'rules' => $rules];
        }

        return $groups;
    }

    /**
     * The identifiers of one product rule with their names, those deleted
     * since left out.
     *
     * @param string $type one of PRODUCT_TYPES
     * @param array<int, int> $ids
     * @param int $idLang
     * @param int $idShop
     *
     * @return array<int, array<string, mixed>>
     */
    private static function items(string $type, array $ids, int $idLang, int $idShop): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $p = _DB_PREFIX_;

        switch ($type) {
            case 'products':
                $sql = 'SELECT p.`id_product` AS `id`, pl.`name` FROM `' . $p . 'product` p
                    LEFT JOIN `' . $p . 'product_lang` pl ON pl.`id_product` = p.`id_product`
                        AND pl.`id_lang` = ' . $idLang . ' AND pl.`id_shop` = ' . $idShop . '
                    WHERE p.`id_product` IN (' . $in . ') ORDER BY p.`id_product`';
                break;
            case 'categories':
                $sql = 'SELECT c.`id_category` AS `id`, cl.`name` FROM `' . $p . 'category` c
                    LEFT JOIN `' . $p . 'category_lang` cl ON cl.`id_category` = c.`id_category`
                        AND cl.`id_lang` = ' . $idLang . ' AND cl.`id_shop` = ' . $idShop . '
                    WHERE c.`id_category` IN (' . $in . ') ORDER BY c.`id_category`';
                break;
            case 'attributes':
                // "Group - Value", the label the form gives an attribute.
                $sql = 'SELECT a.`id_attribute` AS `id`, CONCAT(agl.`name`, \' - \', al.`name`) AS `name`
                    FROM `' . $p . 'attribute` a
                    LEFT JOIN `' . $p . 'attribute_lang` al ON al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . $idLang . '
                    LEFT JOIN `' . $p . 'attribute_group_lang` agl
                        ON agl.`id_attribute_group` = a.`id_attribute_group` AND agl.`id_lang` = ' . $idLang . '
                    WHERE a.`id_attribute` IN (' . $in . ') ORDER BY a.`id_attribute`';
                break;
            case 'manufacturers':
                $sql = 'SELECT `id_manufacturer` AS `id`, `name` FROM `' . $p . 'manufacturer`
                    WHERE `id_manufacturer` IN (' . $in . ') ORDER BY `id_manufacturer`';
                break;
            default:
                $sql = 'SELECT `id_supplier` AS `id`, `name` FROM `' . $p . 'supplier`
                    WHERE `id_supplier` IN (' . $in . ') ORDER BY `id_supplier`';
        }

        $values = [];
        foreach (Db::getInstance()->executeS($sql) ?: [] as $line) {
            $values[] = ['id' => (int) $line['id'], 'name' => (string) ($line['name'] ?? '')];
        }

        return $values;
    }

    /**
     * Rows of id and name renamed to the identifier the other tools use.
     *
     * @param mixed $lines what Db::executeS() gave, false or null on failure
     * @param string $key
     *
     * @return array<int, array<string, mixed>>
     */
    private static function pairs($lines, string $key): array
    {
        $pairs = [];
        foreach (is_array($lines) ? $lines : [] as $line) {
            $pairs[] = [$key => (int) $line['id'], 'name' => (string) ($line['name'] ?? '')];
        }

        return $pairs;
    }

    /**
     * A product's name in the shop, empty when it was deleted since.
     *
     * @param int $idProduct
     * @param int $idLang
     * @param int $idShop
     *
     * @return string
     */
    private static function productName(int $idProduct, int $idLang, int $idShop): string
    {
        return (string) Db::getInstance()->getValue(
            'SELECT `name` FROM `' . _DB_PREFIX_ . 'product_lang`
             WHERE `id_product` = ' . $idProduct . ' AND `id_lang` = ' . $idLang . ' AND `id_shop` = ' . $idShop
        );
    }
}
