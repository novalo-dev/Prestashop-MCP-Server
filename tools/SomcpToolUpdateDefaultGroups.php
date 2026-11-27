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
 * somcp_update_default_groups - the Save of the card Default groups options
 * of Shop Parameters > Customer Settings, tab Groups: the group of a visitor,
 * of a guest and of a newly registered customer.
 *
 * The same legacy AdminGroupsController on 8.0 and 9.0 saves the three keys
 * with Configuration::updateValue() through processUpdateOptions(), with no
 * other check than the cast, so the tool saves them the same way for the
 * shop of the token, and only the keys whose value differs.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// A group is checked as somcp_update_customer_group checks one.
require_once __DIR__ . '/SomcpToolGetCustomerGroup.php';
// A save that stops half way answers as every configuration write does.
require_once __DIR__ . '/SomcpToolUpdateGeneralSettings.php';

class SomcpToolUpdateDefaultGroups
{
    /** The fields, keyed to their configuration key, in the order of the card. */
    private const KEYS = [
        'visitors_group' => 'PS_UNIDENTIFIED_GROUP',
        'guests_group' => 'PS_GUEST_GROUP',
        'customers_group' => 'PS_CUSTOMER_GROUP',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $field = static function (string $label, string $who): array {
            return [
                'type' => 'integer',
                'description' => $label . ': the group of ' . $who . ', an id_group of somcp_list_customer_groups.',
            ];
        };

        return new SomcpTool(
            'somcp_update_default_groups',
            Somcp::t('Change the default customer groups'),
            'Changes the Default groups options of Shop Parameters > Customer Settings > Groups, as its Save: '
                . 'visitors_group, guests_group, customers_group, each an id_group of somcp_list_customer_groups, '
                . 'which answers default_for. Only the fields given change; one group may hold several roles. The '
                . 'prices and discounts a visitor, a guest or a new customer sees follow the group chosen. To '
                . 'change a group itself use somcp_update_customer_group.',
            [
                'type' => 'object',
                'properties' => [
                    'visitors_group' => $field('Visitors group', 'the visitors who are not logged in'),
                    'guests_group' => $field('Guests group', 'the customers who order as guests'),
                    'customers_group' => $field('Customers group', 'the customers who register'),
                ],
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
        $idShop = Somcp::currentShopId();
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        // Every problem in one answer (#169), so the call is corrected in one go.
        $problems = SomcpTool::unknownFields($arguments, array_keys(self::KEYS));
        $given = [];
        foreach (array_keys(self::KEYS) as $field) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            $id = SomcpTool::id($arguments, $field);
            if ($id === 0) {
                $problems[] = SomcpTool::notAnIdentifier($field);
            } elseif (!SomcpToolGetCustomerGroup::inShop($id, $idShop)) {
                $problems[] = SomcpTool::notFound($field, 'customer group', $id, 'somcp_list_customer_groups');
            } else {
                $given[$field] = $id;
            }
        }
        if ($problems === [] && $given === []) {
            $problems[] = 'give at least one of ' . implode(', ', array_keys(self::KEYS));
        }
        // The back office hides the card while groups are off, so nothing it
        // could not save is saved here.
        if (!(bool) Configuration::get('PS_GROUP_FEATURE_ACTIVE', null, $idShopGroup, $idShop)) {
            $problems[] = 'customer groups are disabled in Advanced Parameters > Performance, so the back office '
                . 'does not show these options';
        }
        if ($problems !== []) {
            return SomcpTool::refused('changed', $problems);
        }

        $stored = self::stored($idShop, $idShopGroup);
        $changes = [];
        foreach ($given as $field => $id) {
            if ($id !== $stored[$field]) {
                $changes[] = ['field' => $field, 'old' => $stored[$field], 'new' => $id];
            }
        }
        if ($changes === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The default groups already hold every value given. Nothing was written.',
                'default_groups' => $stored,
            ]);
        }

        $saved = [];
        foreach ($changes as $change) {
            if (!Configuration::updateValue(self::KEYS[$change['field']], $change['new'], false, $idShopGroup, $idShop)) {
                return SomcpToolUpdateGeneralSettings::failed(
                    $saved,
                    $change['field'] . ' could not be saved',
                    $idEmployee,
                    'The default groups',
                    'somcp_list_customer_groups',
                    'default_groups_update_failed'
                );
            }
            $saved[] = $change['field'];
        }
        SomcpLogger::log('default_groups_updated', SomcpLogger::OUTCOME_SUCCESS, implode(', ', $saved), '', $idEmployee);

        $result = [
            'changed' => true,
            'changes' => $changes,
            // Read back rather than echoed: what the assistant shows is what
            // the shop stored.
            'default_groups' => self::stored($idShop, $idShopGroup),
        ];
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $result['warning'] = 'The settings changed for this shop only, as the back office saves them in a shop context.';
        }

        return SomcpTool::json($result);
    }

    /**
     * The three groups as the shop of the token reads them.
     *
     * @param int $idShop
     * @param int $idShopGroup
     *
     * @return array<string, int>
     */
    private static function stored(int $idShop, int $idShopGroup): array
    {
        $stored = [];
        foreach (self::KEYS as $field => $key) {
            $stored[$field] = (int) Configuration::get($key, null, $idShopGroup, $idShop);
        }

        return $stored;
    }
}
