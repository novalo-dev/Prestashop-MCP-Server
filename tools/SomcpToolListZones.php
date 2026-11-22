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
 * somcp_list_zones - the zones of International > Locations > Zones, with
 * the number of countries in each.
 *
 * A carrier is offered at checkout only in the zones it serves, and every
 * country belongs to one zone: an assistant setting up the delivery of a
 * new shop needs the id_zone of each, which no other tool gives.
 * somcp_get_carrier answers the zones a carrier already serves, not the
 * others.
 *
 * Under the orders scope, as the carriers: the zones are read to set up
 * delivery, by the same assistant and with the same consent.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListZones
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_zones',
            Somcp::t('List zones'),
            'Lists the zones of International > Locations > Zones, by identifier as that list: id_zone, name, '
                . 'Enabled, and the number of countries in each. Take id_zone from here before giving a zone to '
                . 'a carrier: never guess one. For the zones one carrier already serves and its prices per zone, '
                . 'use somcp_get_carrier.',
            [
                'type' => 'object',
                'properties' => [
                    'id_zone' => [
                        'type' => 'integer',
                        'description' => 'Keep the zone with this identifier (ID), as the filter of the list: '
                            . 'an identifier no zone of the shop has gives an empty list.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Part of the zone name (Zone), case-insensitive. Left out, every zone.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true for enabled zones only, false for disabled ones only. Left out, '
                            . 'all of them.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_READ],
            [self::class, 'handle'],
            // Zones are managed on their own back office page, and that is the
            // page a merchant would open to look at them.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminZones')
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

        // A misspelt filter, or an Enabled that is not a boolean, would answer
        // another list than the one asked for without a word: refused, every
        // problem at once so the call is corrected in one go.
        $errors = SomcpTool::unknownFields($arguments, ['id_zone', 'name', 'active']);
        $idZone = 0;
        if (array_key_exists('id_zone', $arguments) && $arguments['id_zone'] !== null) {
            $idZone = SomcpTool::id($arguments, 'id_zone');
            if ($idZone === 0) {
                $errors[] = SomcpTool::notAnIdentifier('id_zone');
            }
        }
        if (array_key_exists('active', $arguments) && !is_bool($arguments['active'])) {
            $errors[] = 'active: true or false';
        }
        if ($errors !== []) {
            return SomcpTool::refused('read', $errors);
        }

        // A query rather than Zone::getZones(), which reads every zone of the
        // installation without the shop and has no name filter: the list of a
        // shop shows the zones zone_shop ties to it.
        $query = new DbQuery();
        $query->select('z.`id_zone`, z.`name`, z.`active`');
        // Counted here so the assistant sees an empty zone before giving it
        // to a carrier, which would then be offered to no address.
        $query->select('(SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'country` c WHERE c.`id_zone` = z.`id_zone`) AS `countries`');
        $query->from('zone', 'z');
        $query->innerJoin('zone_shop', 'zs', 'zs.`id_zone` = z.`id_zone` AND zs.`id_shop` = ' . (int) $idShop);
        // The ID, Zone and Enabled (All / Yes / No) filters of the list.
        if ($idZone > 0) {
            $query->where('z.`id_zone` = ' . (int) $idZone);
        }
        if (isset($arguments['active'])) {
            $query->where('z.`active` = ' . (int) $arguments['active']);
        }
        $name = isset($arguments['name']) && is_scalar($arguments['name']) ? trim((string) $arguments['name']) : '';
        if ($name !== '') {
            $query->where("z.`name` LIKE '%" . pSQL($name) . "%'");
        }
        // Identifier order, as the back office list, so a zone sits where the
        // merchant sees it.
        $query->orderBy('z.`id_zone` ASC');

        $zones = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            // The keys of the zones of somcp_get_carrier, so a zone read here
            // is given to the carrier tools under the same names.
            $zones[] = [
                'id_zone' => (int) $row['id_zone'],
                'name' => (string) $row['name'],
                'active' => (bool) $row['active'],
                'countries' => (int) $row['countries'],
            ];
        }

        return SomcpTool::json([
            'zones' => $zones,
            'count' => count($zones),
        ]);
    }
}
