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
 * somcp_delete_carrier - one carrier of Shipping > Carriers, deleted as the
 * Delete of its row. Irreversible.
 *
 * The two versions of PrestaShop do not delete the same way: the 8.0.1 list
 * only hides every carrier, 9.0.0 removes one no order uses and hides the
 * others. The tool follows 9.0.0 on both: a carrier no order uses has nothing
 * to keep, and Carrier::delete() is never called on a used one, which on 8.0.1
 * would remove the row its orders point to.
 *
 * Unlike the back office, it refuses the default carrier, which the checkout
 * would go on pointing to, and a carrier a module created and manages.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolDeleteCarrier
{
    /** @var array<int, string> Every argument this tool takes. */
    private const ARGUMENTS = ['id_carrier'];

    /**
     * @var array<int, string> what somcp_list_carriers and somcp_get_carrier return and the shop works out,
     *                         named so a carrier given whole is understood rather than meeting an "unknown field"
     */
    private const COMPUTED = [
        'id_reference', 'name', 'delay', 'active', 'is_free', 'position', 'logo', 'tracking_url',
        'external_module', 'grade', 'group_access', 'zones', 'id_tax_rules_group', 'tax_rule', 'handling_costs',
        'handling_cost_tax_excl', 'billing', 'out_of_range', 'ranges', 'range_unit', 'max_width', 'max_height',
        'max_depth', 'max_weight', 'dimension_unit', 'weight_unit', 'language', 'warning',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_carrier',
            Somcp::t('Delete a carrier'),
            'Deletes one carrier of Shipping > Carriers, as the Delete of its row. Irreversible: confirm with '
                . 'the merchant by name first. id_carrier from somcp_list_carriers. A carrier orders use is kept '
                . 'hidden for them. Refused for the default carrier and for a carrier a module manages. To turn '
                . 'a carrier off instead, so it can come back, use somcp_update_carrier with active false. One '
                . 'carrier per call. Not to be retried: if the answer is lost, look with somcp_list_carriers.',
            [
                'type' => 'object',
                'properties' => [
                    'id_carrier' => [
                        'type' => 'integer',
                        'description' => 'The carrier to delete, from somcp_list_carriers.',
                    ],
                ],
                'required' => ['id_carrier'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The right the Delete action of the carriers page takes: edit
            // alone does not let the merchant do it by hand.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminCarriers')
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
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = SomcpTool::unknownFields($arguments, self::ARGUMENTS, self::COMPUTED);
        $idCarrier = SomcpTool::id($arguments, 'id_carrier');
        $before = null;
        if ($idCarrier === 0) {
            // A cast would read a list as 1 and delete a carrier nobody named.
            $errors[] = SomcpTool::notAnIdentifier('id_carrier');
        } else {
            // Read before, since the row may go with the delete, in the shop
            // default language as the list reads it.
            $before = SomcpToolGetCarrier::carrier($idCarrier, $idShop, (int) Configuration::get('PS_LANG_DEFAULT'));
            if ($before === null) {
                $errors[] = SomcpToolGetCarrier::notFound($idCarrier, $idShop);
            }
        }

        $carrier = null;
        if ($before !== null) {
            $carrier = new Carrier($idCarrier);
            $idShopGroup = (int) Shop::getGroupFromShop($idShop);
            // The checkout would go on offering a carrier no list shows, and
            // no tool sets the default carrier yet: the screen is named.
            if ((int) Configuration::get('PS_CARRIER_DEFAULT', null, $idShopGroup, $idShop) === $idCarrier) {
                $errors[] = 'id_carrier: ' . $idCarrier . ' is the default carrier of the shop - choose another '
                    . 'default carrier in Shipping > Preferences first';
            }
            // A module keeps the reference of the carriers it creates: deleted
            // behind its back, it recreates it or stops offering delivery.
            if ((bool) $carrier->is_module) {
                $errors[] = 'id_carrier: ' . $idCarrier . ' belongs to the module '
                    . (string) $carrier->external_module_name . ', which manages it - disable it with '
                    . 'somcp_update_carrier, or manage it from that module';
            }
        }
        if ($errors !== [] || $carrier === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($carrier, $idCarrier, $before, $idEmployee): array {
            return self::deleteCarrier($carrier, $idCarrier, $before, $idEmployee);
        });
    }

    /**
     * Deletes the carrier, or hides it when orders use it, and answers with what was removed.
     *
     * @param Carrier $carrier
     * @param int $idCarrier
     * @param array<string, mixed> $before the carrier as somcp_get_carrier answered it
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function deleteCarrier(Carrier $carrier, int $idCarrier, array $before, int $idEmployee): array
    {
        $used = (bool) $carrier->isUsed();
        $idReference = (int) $carrier->id_reference;

        try {
            if ($used) {
                $carrier->deleted = true;
                $done = (bool) $carrier->update();
            } else {
                $done = (bool) $carrier->delete();
            }
            if ($done) {
                // What the 8.0.1 list does around the delete, whichever way
                // it went: the warehouses stop offering it, the other
                // carriers close the gap in the positions.
                Warehouse::removeCarrier($idReference);
                Carrier::cleanPositions();
            }
        } catch (Throwable $e) {
            $done = false;
        }

        // Checked on the table rather than trusted from delete() or update():
        // gone, or deleted = 1 when kept for its orders.
        $row = Db::getInstance()->getRow(
            'SELECT `deleted` FROM `' . _DB_PREFIX_ . 'carrier` WHERE `id_carrier` = ' . $idCarrier
        );
        $gone = !is_array($row) || $row === [];
        $kept = is_array($row) && $row !== [] && (int) $row['deleted'] === 1;
        if (!$done || (!$gone && !$kept)) {
            SomcpLogger::log('carrier_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'carrier ' . $idCarrier, '', $idEmployee);

            return SomcpTool::text(
                'The carrier could not be deleted. Read somcp_list_carriers to see whether it is still there '
                    . 'before anything else.',
                true
            );
        }

        SomcpLogger::log('carrier_deleted', SomcpLogger::OUTCOME_SUCCESS, 'carrier ' . $idCarrier, '', $idEmployee);

        $result = [
            'deleted' => true,
            'id_carrier' => $idCarrier,
            'carrier' => $before,
            'kept_for_orders' => $kept,
        ];
        $warnings = [];
        if ($kept) {
            $warnings[] = 'Orders use this carrier: PrestaShop keeps it hidden for them, and it is gone from the list.';
        }
        // Said under the key the tax tools use for the same fact (#314).
        $otherShops = count(Shop::getShops(false, null, true)) - 1;
        if ($otherShops > 0) {
            $result['also_in_other_shops'] = $otherShops;
            $warnings[] = 'A carrier is not tied to one shop: it is gone from every shop of this installation ('
                . ($otherShops + 1) . ').';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }
}
