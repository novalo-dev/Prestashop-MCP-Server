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
 * somcp_delete_zone - one zone of International > Locations > Zones deleted,
 * as the Delete of its row does.
 *
 * A zone created by mistake, or emptied once its countries are moved, would
 * otherwise stay in the carrier form of the merchant for good. The core
 * deletes a zone that still holds countries or states and leaves them in no
 * zone, where no carrier and no tax rule by zone reaches their addresses:
 * that is refused here, so only an empty zone goes.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// zone is found through the list, and the other shops counted as the zone
// change counts them.
require_once __DIR__ . '/SomcpToolListZones.php';
require_once __DIR__ . '/SomcpToolUpdateZone.php';

class SomcpToolDeleteZone
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_zone',
            Somcp::t('Delete a zone'),
            'Deletes one zone of International > Locations > Zones, as the Delete of its row. Only a zone with no '
                . 'country and no state: read its countries in somcp_list_zones, and the carriers that serve it '
                . 'in somcp_get_carrier, first. The carriers lose the zone and their prices for it, which cannot '
                . 'be undone. To remove a zone from one carrier use somcp_update_carrier; to stop shipping to it '
                . 'for a while, somcp_update_zone.',
            [
                'type' => 'object',
                'properties' => [
                    'id_zone' => [
                        'type' => 'integer',
                        'description' => 'Required. The zone to delete (ID), from somcp_list_zones.',
                    ],
                ],
                'required' => ['id_zone'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Deleting a zone is what the Delete of the zones list asks for in
            // Team, Permissions.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminZones')
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

        $errors = SomcpTool::unknownFields($arguments, ['id_zone']);
        $stored = null;
        $idZone = SomcpTool::id($arguments, 'id_zone');
        if ($idZone === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_zone');
        } else {
            $stored = SomcpToolListZones::zone($idShop, $idZone);
            if ($stored === null) {
                $errors[] = SomcpTool::notFound('id_zone', 'zone', $idZone, 'somcp_list_zones');
            }
        }
        if ($stored !== null) {
            // Zone::delete() sets id_zone = 0 on every country and state of
            // the zone: no carrier would then be offered to their addresses,
            // and nothing on the screen would say so.
            if ($stored['countries'] > 0) {
                $errors[] = 'id_zone: zone ' . $idZone . ' holds ' . $stored['countries']
                    . ' countries - move them to another zone first';
            }
            $states = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'state` WHERE `id_zone` = ' . (int) $idZone
            );
            if ($states > 0) {
                $errors[] = 'id_zone: zone ' . $idZone . ' holds ' . $states . ' states - move them to another zone first';
            }
            // A zone has one row in zone: deleting it here would remove it
            // from the other shops too.
            $otherShops = SomcpToolUpdateZone::otherShops($idZone, $idShop);
            if ($otherShops > 0) {
                $errors[] = 'id_zone: zone ' . $idZone . ' is also used by ' . $otherShops
                    . ' other shops - deleting it would remove it there too';
            }
        }
        if ($errors !== [] || $stored === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        // Read before the deletion, which removes the carrier_zone rows. Only
        // the carriers in use: the rows of an older version of a changed
        // carrier go too, but that version is offered to nobody already.
        $carriers = SomcpToolUpdateZone::carriers($idZone);

        $zone = new Zone($idZone);
        try {
            $done = $zone->delete();
            $failure = $done ? '' : 'PrestaShop refused to delete it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('zone_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'zone ' . $idZone . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text(
                'The zone could not be deleted: ' . $failure . '. Read it with somcp_list_zones before calling again.',
                true
            );
        }
        SomcpLogger::log('zone_deleted', SomcpLogger::OUTCOME_SUCCESS, 'zone ' . $idZone . ' (' . $stored['name']
            . '), carriers detached: ' . ($carriers === [] ? 'none' : implode(', ', $carriers)), '', $idEmployee);

        $result = [
            'deleted' => true,
            'id_zone' => $idZone,
            // The name as it was, so the assistant can recreate the zone.
            'name' => $stored['name'],
            'carriers_detached' => $carriers,
        ];
        if ($carriers !== []) {
            $result['warning'] = 'The ' . count($carriers) . ' carriers (' . implode(', ', $carriers) . ') no longer '
                . 'serve this zone, and their prices for it are deleted: check their zones with somcp_get_carrier.';
        }

        return SomcpTool::json($result);
    }
}
