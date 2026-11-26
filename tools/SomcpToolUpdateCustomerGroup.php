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
 * somcp_update_customer_group - the Edit form of a customer group of Shop
 * Parameters > Customer Settings > Groups, then Save.
 *
 * The same legacy AdminGroupsController on 8.0 and 9.0 checks the Discount
 * with validateDiscount(), then saves the Group object. The page always
 * sends every field; the tool changes only those given, and leaves the
 * category discounts and the authorized modules as they are: they are read
 * back unchanged, so the answer of somcp_get_customer_group can be given
 * back whole.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The languages and texts of #149, shared with every write tool.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
// The answer is the group as the read gives it.
require_once __DIR__ . '/SomcpToolGetCustomerGroup.php';

class SomcpToolUpdateCustomerGroup
{
    /** The size of group_lang.name in Group::$definition. */
    public const MAX_LENGTH = 32;

    /** @var array<string, int> Price display method, by the words of the list, as Group stores it. */
    public const PRICE_DISPLAY = ['tax_excluded' => 1, 'tax_included' => 0];

    /** @var array<string, string> The fields of the read this tool does not change, and why. */
    private const READ_ONLY = [
        'members_count' => 'read only',
        'date_add' => 'read only',
        'default_for' => 'chosen in Customer Settings > Groups, Default groups options',
        'category_discounts' => 'not changed by this tool yet',
        'authorized_modules' => 'not changed by this tool yet',
        'unauthorized_modules' => 'not changed by this tool yet',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $readBack = static function (string $type, string $what): array {
            return [
                'type' => $type,
                'description' => 'Read by somcp_get_customer_group: taken only as the read gives it, so the group '
                    . 'can be given back whole. ' . $what,
            ];
        };

        return new SomcpTool(
            'somcp_update_customer_group',
            Somcp::t('Change a customer group'),
            'Changes a customer group of Shop Parameters > Customer Settings > Groups, as Edit then Save: its '
                . 'name per language, its Discount, its Price display method and Show prices. Only the fields '
                . 'given change, a language left out keeping its text; the group of somcp_get_customer_group given '
                . 'back as it is changes nothing. Category discounts and authorized modules are not changed here. '
                . 'For the groups of one customer use somcp_update_customer.',
            [
                'type' => 'object',
                'properties' => [
                    'id_group' => [
                        'type' => 'integer',
                        'description' => 'The group to change, from somcp_list_customer_groups.',
                    ],
                    'languages' => [
                        'type' => 'array',
                        'description' => 'ISO codes of the languages to fill, among the active languages of the '
                            . 'shop. All of them by default. A name given must then be given in each of them.',
                        'items' => ['type' => 'string'],
                    ],
                    'name' => [
                        'type' => 'object',
                        'description' => 'Name, 32 characters at most, without < > = { }. One text per language '
                            . 'being filled, keyed by ISO code: {"fr": "...", "en": "..."}.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'names' => [
                        'type' => 'object',
                        'description' => 'The name in every language, as somcp_get_customer_group answers it: '
                            . 'taken as name for the languages being filled.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'reduction' => [
                        'type' => 'number',
                        'description' => 'Discount, in percent as shown (5 for 5 %), from 0 to 100, 2 decimals at '
                            . 'most: applied to every product for the members of the group.',
                    ],
                    'price_display_method' => [
                        'type' => 'string',
                        'enum' => array_keys(self::PRICE_DISPLAY),
                        'description' => 'Price display method: how prices are shown in the order summary for '
                            . 'this group.',
                    ],
                    'show_prices' => [
                        'type' => 'boolean',
                        'description' => 'Show prices: whether the members of the group see prices.',
                    ],
                    'members_count' => $readBack('integer', 'Read only.'),
                    'date_add' => $readBack('string', 'Read only.'),
                    'default_for' => $readBack('array', 'Chosen in Default groups options.'),
                    'category_discounts' => $readBack('array', 'Not changed by this tool.'),
                    'authorized_modules' => $readBack('array', 'Not changed by this tool.'),
                    'unauthorized_modules' => $readBack('array', 'Not changed by this tool.'),
                ],
                'required' => ['id_group'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminGroups')
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
        $idShopGroup = (int) $shop->id_shop_group;
        $idEmployee = (int) ($context['id_employee'] ?? 0);
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

        $errors = SomcpTool::unknownFields($arguments, array_merge(
            ['id_group', 'languages', 'name', 'names', 'reduction', 'price_display_method', 'show_prices'],
            array_keys(self::READ_ONLY)
        ));

        $group = null;
        $idGroup = SomcpTool::id($arguments, 'id_group');
        if (!array_key_exists('id_group', $arguments)) {
            $errors[] = 'id_group: required - find it with somcp_list_customer_groups';
        } elseif ($idGroup === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_group');
        } elseif (SomcpToolGetCustomerGroup::inShop($idGroup, $idShop)) {
            $group = new Group($idGroup);
        } else {
            $errors[] = SomcpTool::notFound('id_group', 'customer group', $idGroup, 'somcp_list_customer_groups');
        }

        $given = $arguments;
        unset($given['id_group']);
        $before = null;
        if ($group !== null) {
            $before = SomcpToolGetCustomerGroup::group($idGroup, $idShop, $idShopGroup, $idLang);
            // A read-only field given back as read is no change; another
            // value would be silently ignored, so it is refused instead.
            foreach (self::READ_ONLY as $field => $why) {
                if (array_key_exists($field, $given)) {
                    // Loose: a percent read as 10 and given back as 10.0 is the same.
                    if ($given[$field] != ($before[$field] ?? null)) {
                        $errors[] = $field . ': ' . $why;
                    }
                    unset($given[$field]);
                }
            }
            $probe = [];
            $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $probe);
            if ($languages !== null) {
                $given = SomcpToolCreateProduct::nameGivenBack($arguments, $given, self::texts($group->name), $languages, $idShop, 'somcp_get_customer_group', $errors);
            }
        }
        unset($given['names']);
        $plan = self::planGroup($given, $idShop, $errors);

        if ($errors !== [] || $plan === null || $group === null) {
            return SomcpTool::refused('changed', $errors);
        }

        $changes = [];
        $stored = self::texts($group->name);
        $names = $stored;
        foreach ($plan['name'] ?? [] as $idLanguage => $name) {
            $old = $stored[$idLanguage] ?? '';
            if ($name !== $old) {
                $changes[] = ['field' => 'name (' . $plan['languages']['filled'][$idLanguage] . ')', 'old' => $old, 'new' => $name];
                $names[$idLanguage] = $name;
            }
        }
        if (isset($plan['reduction']) && abs($plan['reduction'] - (float) $group->reduction) > 0.000001) {
            $changes[] = ['field' => 'reduction', 'old' => (float) $group->reduction, 'new' => $plan['reduction']];
        }
        if (isset($plan['price_display_method']) && $plan['price_display_method'] !== $before['price_display_method']) {
            $changes[] = ['field' => 'price_display_method', 'old' => $before['price_display_method'], 'new' => $plan['price_display_method']];
        }
        if (isset($plan['show_prices']) && $plan['show_prices'] !== (bool) $group->show_prices) {
            $changes[] = ['field' => 'show_prices', 'old' => (bool) $group->show_prices, 'new' => $plan['show_prices']];
        }

        if ($changes === []) {
            return SomcpTool::json([
                'changed' => false,
                'changes' => [],
                'reason' => 'The group already holds every value given. Nothing was written.',
                'group' => $before,
            ]);
        }

        $fields = array_column($changes, 'field');
        $group->name = $names;
        if (in_array('reduction', $fields, true)) {
            $group->reduction = $plan['reduction'];
        }
        if (in_array('price_display_method', $fields, true)) {
            $group->price_display_method = self::PRICE_DISPLAY[$plan['price_display_method']];
        }
        if (in_array('show_prices', $fields, true)) {
            $group->show_prices = $plan['show_prices'];
        }
        // The shop of the token, which the group already belongs to, never
        // the context's list: Shop association is not taken by this tool.
        $group->id_shop_list = [$idShop];

        try {
            $done = (bool) $group->update();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('customer_group_update_failed', SomcpLogger::OUTCOME_FAILURE, 'group ' . $idGroup . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text(
                'The customer group could not be changed: ' . $failure . '. Read it with somcp_get_customer_group '
                    . 'before calling again.',
                true
            );
        }
        // The storefront caches its price blocks: the switch of the list
        // clears them too, so the new prices show.
        if (array_intersect($fields, ['reduction', 'price_display_method', 'show_prices']) !== []) {
            Tools::clearSmartyCache();
        }
        SomcpLogger::log('customer_group_updated', SomcpLogger::OUTCOME_SUCCESS, 'group ' . $idGroup . ': '
            . implode(', ', $fields), '', $idEmployee);

        $result = [
            'changed' => true,
            'changes' => $changes,
            'group' => SomcpToolGetCustomerGroup::group($idGroup, $idShop, $idShopGroup, $idLang),
        ];
        $warnings = [];
        if (in_array('show_prices', $fields, true) && !$plan['show_prices']
            && !(bool) Configuration::get('PS_CATALOG_MODE', null, $idShopGroup, $idShop)) {
            $warnings[] = 'Customers of this group no longer see prices; to hide them for every group, turn on '
                . 'catalog mode in Product Settings instead.';
        }
        // Read after the save: Group::update() turns the feature on by itself
        // when its global value is off and the discount is above 0.
        if (in_array('reduction', $fields, true) && $plan['reduction'] > 0
            && !(bool) Configuration::get('PS_GROUP_FEATURE_ACTIVE', null, $idShopGroup, $idShop)) {
            $warnings[] = SomcpToolGetCustomerGroup::GROUPS_DISABLED;
        }
        if (($before['default_for'] ?? []) !== []) {
            $warnings[] = 'This group is used for ' . implode(', ', $before['default_for'])
                . ': the change reaches every one of them.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }

    /**
     * The fields given, checked with the rules of the Edit form.
     *
     * Public so a creation of a group checks with the same rules. Every
     * problem is added to $errors, so a call is corrected in one go; a field
     * left out is absent from the plan. The names are keyed by language
     * identifier, for the languages being filled only.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null null when the languages cannot be read
     */
    public static function planGroup(array $arguments, int $idShop, array &$errors): ?array
    {
        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }
        $plan = ['languages' => $languages];

        // Given, a name is never empty: the core requires it.
        $names = SomcpToolCreateProduct::texts($arguments, 'name', 'name', $languages, $errors, array_key_exists('name', $arguments));
        if ($names !== null) {
            foreach ($names as $idLang => $name) {
                $name = trim($name);
                $names[$idLang] = $name;
                $where = 'name (' . $languages['filled'][$idLang] . ')';
                if (!Validate::isGenericName($name)) {
                    $errors[] = $where . ': no <, >, =, { or }';
                } elseif (Tools::strlen($name) > self::MAX_LENGTH) {
                    $errors[] = $where . ': ' . self::MAX_LENGTH . ' characters at most';
                }
            }
            $plan['name'] = $names;
        }

        if (array_key_exists('reduction', $arguments)) {
            $value = $arguments['reduction'];
            // The rule of validateDiscount(): a price, from 0 to 100; two
            // decimals, as the column stores them.
            if ((!is_int($value) && !is_float($value)) || $value < 0 || $value > 100
                || abs(round((float) $value, 2) - (float) $value) > 0.0000001) {
                $errors[] = 'reduction: a percentage from 0 to 100';
            } else {
                $plan['reduction'] = round((float) $value, 2);
            }
        }

        if (array_key_exists('price_display_method', $arguments)) {
            $method = $arguments['price_display_method'];
            if (!is_string($method) || !isset(self::PRICE_DISPLAY[$method])) {
                $errors[] = SomcpTool::oneOf('price_display_method', array_keys(self::PRICE_DISPLAY));
            } else {
                $plan['price_display_method'] = $method;
            }
        }

        if (array_key_exists('show_prices', $arguments)) {
            if (!is_bool($arguments['show_prices'])) {
                $errors[] = 'show_prices: true or false';
            } else {
                $plan['show_prices'] = $arguments['show_prices'];
            }
        }

        return $plan;
    }

    /**
     * A translated field of the group as texts keyed by language identifier.
     *
     * @param mixed $value as the Group object loaded in every language holds it
     *
     * @return array<int, string>
     */
    private static function texts($value): array
    {
        $texts = [];
        foreach (is_array($value) ? $value : [] as $idLang => $text) {
            $texts[(int) $idLang] = (string) $text;
        }

        return $texts;
    }
}
