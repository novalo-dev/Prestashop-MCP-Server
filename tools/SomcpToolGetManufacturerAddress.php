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
 * somcp_get_manufacturer_address - every field of the back office brand
 * address form, for one brand address.
 *
 * The reader is public on purpose: the brand address write tools answer with
 * "the address as somcp_get_manufacturer_address returns it", and calling it
 * is what keeps them naming every field alike and agreeing on which addresses
 * are brand addresses of the shop.
 *
 * It takes an identifier only. Finding an address is the job of
 * somcp_list_manufacturer_addresses.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// test of the shop lives in that class, so the brand tools never disagree on
// which brands belong to the shop.
require_once __DIR__ . '/SomcpToolGetManufacturer.php';

class SomcpToolGetManufacturerAddress
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_manufacturer_address',
            Somcp::t('Get a brand address'),
            'Returns every field of one postal address of a brand (manufacturer) of this shop, like the back '
                . 'office brand address form: brand, last name, first name, address, address line 2, postal '
                . 'code, city, country and state as ISO codes (state null when the country has none), DNI - '
                . 'the national ID number, phone, mobile phone and other information. '
                . 'Identify the address by its identifier, from somcp_list_manufacturer_addresses: never guess '
                . 'one. Do not use it for the list of addresses, which is somcp_list_manufacturer_addresses, nor '
                . 'for the brand itself (somcp_get_manufacturer). Customer and supplier addresses are refused: '
                . 'it reads brand addresses only.',
            [
                'type' => 'object',
                'properties' => [
                    'id_address' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the brand address, from somcp_list_manufacturer_addresses.',
                    ],
                ],
                'required' => ['id_address'],
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

        // A cast would read an array as 1 and answer about an address nobody
        // asked for: only a whole number is an identifier.
        $value = $arguments['id_address'] ?? null;
        $idAddress = is_int($value) || (is_string($value) && preg_match('/^\d{1,10}$/', trim($value)))
            ? (int) trim((string) $value)
            : 0;

        $address = self::existingAddress($idAddress, $idShop);
        if ($address === null) {
            return SomcpTool::text(
                'No brand address of this shop has this identifier. Find it with somcp_list_manufacturer_addresses.',
                true
            );
        }

        return SomcpTool::json(self::address($address));
    }

    /**
     * The address, when it is a brand address of a brand of the shop of the
     * token.
     *
     * @param int $idAddress
     * @param int $idShop
     *
     * @return Address|null
     */
    public static function existingAddress(int $idAddress, int $idShop): ?Address
    {
        if ($idAddress <= 0) {
            return null;
        }
        $address = new Address($idAddress);
        if (!Validate::isLoadedObject($address)) {
            return null;
        }
        // A customer, supplier or orphan address is answered like one that
        // does not exist: the brand tools must never hand out someone's home
        // address under the catalogue permission. A deleted address is kept by
        // PrestaShop for the orders that used it and is gone from the back
        // office list, so it is gone from here too.
        if ((int) $address->id_manufacturer <= 0 || (bool) $address->deleted) {
            return null;
        }
        // The address table has no shop column: an address belongs to the
        // shop of its brand, tested as every brand tool tests it.
        if (SomcpToolGetManufacturer::existingManufacturer((int) $address->id_manufacturer, $idShop) === null) {
            return null;
        }

        return $address;
    }

    /**
     * One brand address, under the names the brand address tools take and
     * answer with.
     *
     * @param Address $address
     *
     * @return array<string, mixed>
     */
    public static function address(Address $address): array
    {
        $manufacturer = new Manufacturer((int) $address->id_manufacturer);

        $country = null;
        if ((int) $address->id_country > 0) {
            $country = (string) Country::getIsoById((int) $address->id_country) ?: null;
        }
        $state = null;
        if ((int) $address->id_state > 0) {
            $stateObject = new State((int) $address->id_state);
            $state = Validate::isLoadedObject($stateObject) ? (string) $stateObject->iso_code : null;
        }

        return [
            'id_address' => (int) $address->id,
            'id_manufacturer' => (int) $address->id_manufacturer,
            'manufacturer_name' => Validate::isLoadedObject($manufacturer) ? (string) $manufacturer->name : '',
            'lastname' => (string) $address->lastname,
            'firstname' => (string) $address->firstname,
            'address1' => (string) $address->address1,
            'address2' => (string) $address->address2,
            'postcode' => (string) $address->postcode,
            'city' => (string) $address->city,
            'country' => $country,
            'state' => $state,
            'dni' => (string) $address->dni,
            'phone' => (string) $address->phone,
            'phone_mobile' => (string) $address->phone_mobile,
            'other' => (string) $address->other,
        ];
    }
}
