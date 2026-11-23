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
 * somcp_update_zone - one zone of International > Locations > Zones changed,
 * as its edit form then Save do (Name, Active), or as the Enabled switch of
 * the list does.
 *
 * A zone set up under the wrong name, or one the merchant has to stop
 * shipping to for a while, could only be fixed in the back office. Only the
 * fields given and different from the stored zone are written, so a row of
 * somcp_list_zones given back as read changes nothing, and the name is
 * checked by the same function as somcp_create_zone.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// name is checked as the creation checks it, and the answer is the zone as
// somcp_list_zones returns it.
require_once __DIR__ . '/SomcpToolCreateZone.php';
require_once __DIR__ . '/SomcpToolListZones.php';

class SomcpToolUpdateZone
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_zone',
            Somcp::t('Change a zone'),
            'Changes one zone of International > Locations > Zones, as its edit form then Save or the Enabled '
                . 'switch of the list: Name and Active. Take id_zone from somcp_list_zones; a row of it can be '
                . 'given back whole, and only what differs is written. Disabling a zone stops every carrier that '
                . 'serves it for the addresses of its countries: say so first. For the zones a carrier serves use '
                . 'somcp_update_carrier; to create a zone, somcp_create_zone.',
            [
                'type' => 'object',
                'properties' => [
                    'id_zone' => [
                        'type' => 'integer',
                        'description' => 'Required. The zone to change (ID), from somcp_list_zones.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Merchant. Name, 64 characters at most, without < > = { }. Left out, kept.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Active: true allows shipping to this zone, false stops it. Left '
                            . 'out, kept.',
                    ],
                    'countries' => [
                        'type' => 'integer',
                        'description' => 'Worked out by the shop: taken only as somcp_list_zones gives it, so a '
                            . 'row of that list can be given back whole.',
                    ],
                ],
                'required' => ['id_zone'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Editing a zone is what its edit form and the Enabled switch ask
            // for in Team, Permissions; adding one is a different right.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminZones')
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

        $errors = SomcpTool::unknownFields($arguments, ['id_zone', 'name', 'active', 'countries']);
        $stored = null;
        $idZone = SomcpTool::id($arguments, 'id_zone');
        if ($idZone === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_zone');
        } else {
            // Only a zone zone_shop ties to the shop of the token: another
            // shop's zone is not one this employee manages here.
            $stored = SomcpToolListZones::zone($idShop, $idZone);
            if ($stored === null) {
                $errors[] = SomcpTool::notFound('id_zone', 'zone', $idZone, 'somcp_list_zones');
            }
        }

        $name = SomcpToolCreateZone::name($arguments, false, $errors);
        $active = null;
        if (array_key_exists('active', $arguments) && $arguments['active'] !== null) {
            // A boolean only: "false" cast to bool is true, and would keep
            // shipping to a zone the merchant wanted closed.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } else {
                $active = $arguments['active'];
            }
        }
        // Taken only as the list answers it, so a row given back whole is not
        // refused; any other number would claim a change this tool cannot make.
        if (array_key_exists('countries', $arguments) && $arguments['countries'] !== null && $stored !== null
            && $arguments['countries'] !== $stored['countries']) {
            $errors[] = 'countries: worked out by the shop - move countries with the countries tools';
        }
        if (!array_key_exists('name', $arguments) && !array_key_exists('active', $arguments)) {
            $errors[] = 'name or active: give at least one field to change';
        }
        if ($errors !== [] || $stored === null) {
            return SomcpTool::refused('changed', $errors);
        }

        $changed = [];
        if ($name !== null && $name !== $stored['name']) {
            $changed[] = 'name';
        }
        if ($active !== null && $active !== $stored['active']) {
            $changed[] = 'active';
        }

        $result = ['updated' => false, 'changed' => $changed];
        if ($changed !== []) {
            $zone = new Zone($idZone);
            if ($name !== null) {
                $zone->name = $name;
            }
            if ($active !== null) {
                $zone->active = $active;
            }
            try {
                $done = $zone->update();
                $failure = $done ? '' : 'PrestaShop refused to save it';
            } catch (Throwable $e) {
                $done = false;
                $failure = $e->getMessage();
            }
            if (!$done) {
                SomcpLogger::log('zone_update_failed', SomcpLogger::OUTCOME_FAILURE, 'zone ' . $idZone . ': '
                    . Tools::substr($failure, 0, 500), '', $idEmployee);

                return SomcpTool::text(
                    'The zone could not be changed: ' . $failure . '. Read it with somcp_list_zones before calling '
                        . 'again.',
                    true
                );
            }
            SomcpLogger::log('zone_updated', SomcpLogger::OUTCOME_SUCCESS, 'zone ' . $idZone . ': '
                . implode(', ', $changed), '', $idEmployee);
            $result['updated'] = true;
        }

        $warnings = [];
        if (in_array('active', $changed, true) && $active === false) {
            // The help of the field: Allow or disallow shipping to this zone.
            $carriers = array_map('intval', array_column(Db::getInstance()->executeS(
                'SELECT DISTINCT cz.`id_carrier` FROM `' . _DB_PREFIX_ . 'carrier_zone` cz'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'carrier` c ON c.`id_carrier` = cz.`id_carrier` AND c.`deleted` = 0'
                . ' WHERE cz.`id_zone` = ' . (int) $idZone . ' ORDER BY cz.`id_carrier` ASC'
            ) ?: [], 'id_carrier'));
            if ($carriers !== []) {
                $warnings[] = 'Shipping is now disallowed to zone ' . $idZone . ': the ' . count($carriers)
                    . ' carriers that serve it (' . implode(', ', $carriers) . ') are no longer offered to '
                    . 'addresses in its ' . (int) $stored['countries'] . ' countries.';
            }
        }
        if (in_array('name', $changed, true) && $name !== null) {
            $same = SomcpToolCreateZone::sameName($idShop, $name, $idZone);
            if ($same !== null) {
                $warnings[] = $same;
            }
        }
        // A zone has one row in zone, so a change reaches every shop it is in.
        $otherShops = self::otherShops($idZone, $idShop);
        if ($otherShops > 0) {
            $result['also_in_other_shops'] = $otherShops;
            $warnings[] = 'This zone is also in ' . $otherShops . ' other shop(s): the change reaches them too.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $result['zone'] = SomcpToolListZones::zone($idShop, $idZone);

        return SomcpTool::json($result);
    }

    /**
     * The number of shops other than the one of the token that hold the zone.
     *
     * Public so the deletion refuses a shared zone on the same count: a zone
     * has one row in zone, so a change or a deletion reaches every shop of it.
     *
     * @param int $idZone
     * @param int $idShop
     *
     * @return int
     */
    public static function otherShops(int $idZone, int $idShop): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'zone_shop` WHERE `id_zone` = ' . (int) $idZone
            . ' AND `id_shop` <> ' . (int) $idShop
        );
    }
}
