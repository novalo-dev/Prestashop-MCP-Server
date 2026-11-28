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
 * somcp_delete_customer_group - the Delete of a row, or Delete selected, of
 * Shop Parameters > Customer Settings > Groups, one group per call.
 *
 * The same legacy AdminGroupsController on 8.0 and 9.0 offers no Delete for
 * the three groups of Default groups options, and calls Group::delete(),
 * the same body on both: the group in every shop, its members, categories,
 * discounts and modules, the customers left in no group put in the Customers
 * group and those whose default group it was given another. The tool calls
 * that very method, never its own SQL.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The employee wrapper, shared with every write tool.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
// The answer is the group as the read gives it.
require_once __DIR__ . '/SomcpToolGetCustomerGroup.php';

class SomcpToolDeleteCustomerGroup
{
    /** @var array<string, string> The roles of Default groups options, by their configuration key. */
    private const DEFAULT_ROLES = [
        'PS_UNIDENTIFIED_GROUP' => 'visitors',
        'PS_GUEST_GROUP' => 'guests',
        'PS_CUSTOMER_GROUP' => 'customers',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_customer_group',
            Somcp::t('Delete a customer group'),
            'Deletes one customer group of Shop Parameters > Customer Settings > Groups, as its Delete: its '
                . 'members keep their other groups, those left in none go to the Customers group. The groups of '
                . 'visitors, guests and customers cannot be deleted. Cannot be undone: ask first, naming the '
                . 'members. id_group from somcp_list_customer_groups. The answer holds the group as it was. Not to '
                . 'be retried: if the answer is lost, read somcp_list_customer_groups first. To take one customer '
                . 'out of a group use somcp_update_customer.',
            [
                'type' => 'object',
                'properties' => [
                    'id_group' => [
                        'type' => 'integer',
                        'description' => 'The group to delete, from somcp_list_customer_groups.',
                    ],
                ],
                'required' => ['id_group'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminGroups')
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

        // Every mistake in one answer (#169), so the call is corrected in one go.
        $errors = SomcpTool::unknownFields($arguments, ['id_group']);

        $idGroup = SomcpTool::id($arguments, 'id_group');
        $found = false;
        if (!array_key_exists('id_group', $arguments)) {
            $errors[] = 'id_group: required - find it with somcp_list_customer_groups';
        } elseif ($idGroup === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_group');
        } elseif (!SomcpToolGetCustomerGroup::inShop($idGroup, $idShop)) {
            // A group of another shop is unknown here, as this shop's list
            // does not show it.
            $errors[] = SomcpTool::notFound('id_group', 'customer group', $idGroup, 'somcp_list_customer_groups');
        } else {
            $found = true;
            // The list shows no Delete for these three, read for the shop of
            // the token as Default groups options saves them.
            foreach (self::DEFAULT_ROLES as $key => $role) {
                if ((int) Configuration::get($key, null, $idShopGroup, $idShop) === $idGroup) {
                    $errors[] = 'id_group: the group of ' . $role . ' cannot be deleted - choose another one with '
                        . 'somcp_update_default_groups first';
                    break;
                }
            }
        }

        if ($errors !== [] || !$found) {
            return SomcpTool::refused('deleted', $errors);
        }

        // Read before it goes: the answer is what it takes to create it again,
        // and what the warnings speak of is gone once the core has deleted it.
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $before = SomcpToolGetCustomerGroup::group($idGroup, $idShop, $idShopGroup, $idLang);
        $counts = self::counts($idGroup);
        $otherShops = self::otherShops($idGroup, $idShop);
        $customersGroup = new Group((int) Configuration::get('PS_CUSTOMER_GROUP', null, $idShopGroup, $idShop), $idLang);
        $customersName = is_string($customersGroup->name) && $customersGroup->name !== '' ? $customersGroup->name : 'the Customers group';

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($idGroup, $idEmployee, $before, $counts, $otherShops, $customersName): array {
            try {
                $done = (bool) (new Group($idGroup))->delete();
                $failure = $done ? '' : 'PrestaShop refused to delete it';
            } catch (Throwable $e) {
                $done = false;
                $failure = $e->getMessage();
            }
            if (!$done) {
                SomcpLogger::log('customer_group_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'group ' . $idGroup
                    . ': ' . Tools::substr($failure, 0, 500), '', $idEmployee);

                return SomcpTool::text(
                    'The customer group could not be deleted: ' . $failure . '. Read it with '
                        . 'somcp_get_customer_group before calling again.',
                    true
                );
            }
            // The members now see the prices of another group.
            Tools::clearSmartyCache();
            // The identifier and the counts only: the name is the merchant's text.
            SomcpLogger::log('customer_group_deleted', SomcpLogger::OUTCOME_SUCCESS, 'group ' . $idGroup . ': '
                . $counts['members'] . ' members, ' . $counts['defaults'] . ' by default, ' . $counts['cart_rules']
                . ' cart rules, ' . $counts['specific_prices'] . ' specific prices', '', $idEmployee);

            $warnings = [];
            if ($counts['members'] > 0) {
                $warnings[] = 'Its ' . $counts['members'] . ' members keep their other groups; those left in none were '
                    . 'put in ' . $customersName . '.';
            }
            if ($counts['defaults'] > 0) {
                $warnings[] = $counts['defaults'] . ' customers had it as default group: each now has the first group '
                    . 'he is in.';
            }
            if ($counts['cart_rules'] > 0) {
                $warnings[] = $counts['cart_rules'] . ' cart rules were kept to this group only: no customer can use '
                    . 'them any more - change them with somcp_update_cart_rule.';
            }
            if ($counts['specific_prices'] > 0) {
                $warnings[] = $counts['specific_prices'] . ' specific prices were for this group: they no longer apply '
                    . 'to anyone.';
            }
            if ($otherShops !== null) {
                $warnings[] = 'This group also belonged to ' . $otherShops . ': it is deleted for all of them.';
            }

            $result = [
                'deleted' => true,
                'id_group' => $idGroup,
                'group' => $before,
            ];
            if ($warnings !== []) {
                $result['warning'] = implode(' ', $warnings);
            }

            return SomcpTool::json($result);
        });
    }

    /**
     * What the deletion reaches, counted before the core removes it.
     *
     * @param int $idGroup
     *
     * @return array{members: int, defaults: int, cart_rules: int, specific_prices: int}
     */
    private static function counts(int $idGroup): array
    {
        $db = Db::getInstance();

        return [
            'members' => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer_group` WHERE `id_group` = ' . $idGroup),
            'defaults' => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_default_group` = ' . $idGroup),
            // A rule kept to groups whose only group is this one: once its row
            // goes, nobody matches it.
            'cart_rules' => (int) $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cart_rule` cr WHERE cr.`group_restriction` = 1'
                . ' AND EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'cart_rule_group` g WHERE g.`id_cart_rule` = cr.`id_cart_rule` AND g.`id_group` = ' . $idGroup . ')'
                . ' AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'cart_rule_group` o WHERE o.`id_cart_rule` = cr.`id_cart_rule` AND o.`id_group` <> ' . $idGroup . ')'
            ),
            'specific_prices' => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'specific_price` WHERE `id_group` = ' . $idGroup),
        ];
    }

    /**
     * The other shops the group belongs to, named for the warning, or null
     * when it is kept to this shop or the shop is the only one.
     *
     * Group::delete() removes the group from every shop, so the merchant is
     * told which shops lose it.
     *
     * @param int $idGroup
     * @param int $idShop
     *
     * @return string|null
     */
    private static function otherShops(int $idGroup, int $idShop): ?string
    {
        if (!Shop::isFeatureActive() || count(Shop::getShops(false, null, true)) < 2) {
            return null;
        }
        $names = array_column(Db::getInstance()->executeS(
            'SELECT s.`name` FROM `' . _DB_PREFIX_ . 'group_shop` gs
             INNER JOIN `' . _DB_PREFIX_ . 'shop` s ON s.`id_shop` = gs.`id_shop`
             WHERE gs.`id_group` = ' . $idGroup . ' AND gs.`id_shop` <> ' . $idShop . ' ORDER BY s.`id_shop`'
        ) ?: [], 'name');

        return $names === [] ? null : 'the shops ' . implode(', ', $names);
    }
}
