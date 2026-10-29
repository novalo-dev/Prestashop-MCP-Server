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
 * somcp_list_manufacturer_addresses - the postal addresses of the brands, as
 * the Brand addresses list of the back office shows them.
 *
 * Kept to the columns of that list: it is what an assistant reads to find the
 * address of a brand before reading or changing it, so it stays plain.
 * Everything else about one address is the job of
 * somcp_get_manufacturer_address.
 *
 * A brand address has no shop of its own in PrestaShop: the address table
 * carries no id_shop. It belongs to the shop of its brand, so only the
 * addresses of brands of the token's shop are listed.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// test of the shop lives in that class, so the brand tools never disagree on
// which brands belong to the shop.
require_once __DIR__ . '/SomcpToolGetManufacturer.php';

class SomcpToolListManufacturerAddresses
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_manufacturer_addresses',
            Somcp::t('List brand addresses'),
            'Lists the postal addresses of the brands (manufacturers) of this shop, like the Brand '
                . 'addresses list of the back office: identifier, brand, last name, first name, postal code, '
                . 'city and country ISO code. Give id_manufacturer, taken from somcp_list_manufacturers, to '
                . 'list the addresses of one brand only. Take an address identifier from here rather than '
                . 'assuming one. Every field of one address is read with somcp_get_manufacturer_address when '
                . 'the shop offers it. Do not use it for the brands themselves (somcp_list_manufacturers), '
                . 'nor for customer or supplier addresses: it lists brand addresses only.',
            [
                'type' => 'object',
                'properties' => [
                    'id_manufacturer' => [
                        'type' => 'integer',
                        'description' => 'Identifier of one brand, from somcp_list_manufacturers, to list '
                            . 'its addresses only. The addresses of every brand are listed otherwise.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Brand addresses are managed under the brands page of the back
            // office, and that page's right is the one it checks for them.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminManufacturers')
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

        // A brand asked for must be one of this shop: an identifier that
        // matches nothing is said, not answered with an empty list the
        // assistant would read as "this brand has no address".
        $idManufacturer = null;
        if (array_key_exists('id_manufacturer', $arguments) && $arguments['id_manufacturer'] !== null) {
            $idManufacturer = self::identifier($arguments['id_manufacturer']);
            if (SomcpToolGetManufacturer::existingManufacturer($idManufacturer, $idShop) === null) {
                return SomcpTool::text(
                    'No brand of this shop has this identifier. Find it with somcp_list_manufacturers, or list every brand address without id_manufacturer.',
                    true
                );
            }
        }

        // A query rather than Address objects: the list only needs the columns
        // the back office list shows, and the brand must be in the shop of the
        // token, which an Address knows nothing about. Deleted addresses are
        // kept by PrestaShop for the orders that used them, and are hidden
        // from the back office list, so from here too.
        $query = new DbQuery();
        $query->select('a.`id_address`, a.`id_manufacturer`, m.`name` AS manufacturer_name, a.`lastname`, a.`firstname`, a.`postcode`, a.`city`, c.`iso_code`');
        $query->from('address', 'a');
        $query->innerJoin('manufacturer', 'm', 'm.`id_manufacturer` = a.`id_manufacturer`');
        $query->innerJoin('manufacturer_shop', 'ms', 'ms.`id_manufacturer` = a.`id_manufacturer` AND ms.`id_shop` = ' . $idShop);
        // A left join, so an address whose country was removed is still listed.
        $query->leftJoin('country', 'c', 'c.`id_country` = a.`id_country`');
        $query->where('a.`id_manufacturer` > 0');
        $query->where('a.`deleted` = 0');
        if ($idManufacturer !== null) {
            $query->where('a.`id_manufacturer` = ' . (int) $idManufacturer);
        }
        $query->orderBy('m.`name` ASC, a.`id_address` ASC');

        $addresses = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $addresses[] = [
                'id_address' => (int) $row['id_address'],
                'id_manufacturer' => (int) $row['id_manufacturer'],
                'manufacturer_name' => (string) ($row['manufacturer_name'] ?? ''),
                'lastname' => (string) ($row['lastname'] ?? ''),
                'firstname' => (string) ($row['firstname'] ?? ''),
                'postcode' => (string) ($row['postcode'] ?? ''),
                'city' => (string) ($row['city'] ?? ''),
                'country' => isset($row['iso_code']) && $row['iso_code'] !== '' ? (string) $row['iso_code'] : null,
            ];
        }

        return SomcpTool::json([
            'addresses' => $addresses,
            'count' => count($addresses),
        ]);
    }

    /**
     * A cast would read an array as 1 and answer about a brand nobody asked
     * for: only a whole number is an identifier.
     *
     * @param mixed $value
     *
     * @return int
     */
    private static function identifier($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d{1,10}$/', trim($value))) {
            return (int) trim($value);
        }

        return 0;
    }
}
