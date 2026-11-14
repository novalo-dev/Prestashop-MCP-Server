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
 * somcp_delete_manufacturer_address - deletes one brand address, as the
 * delete action of the back office Brand addresses list does.
 *
 * The address is found by the same test as somcp_get_manufacturer_address, so
 * this tool can never reach a customer's or a supplier's address under the
 * catalogue permission, nor a brand address of another shop.
 *
 * The core's DeleteAddressHandler, the same on 8 and 9, only calls
 * Address::delete(): hooks fire, and an address an order still points to is
 * flagged deleted rather than removed, so the order keeps it. Either way the
 * address is gone from the back office list and from the brand tools, which is
 * what the answer is read back from.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// test of which addresses are brand addresses of the shop lives in that class.
require_once __DIR__ . '/SomcpToolGetManufacturerAddress.php';

class SomcpToolDeleteManufacturerAddress
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_manufacturer_address',
            Somcp::t('Delete a brand address'),
            'Deletes one brand address, as the Brand addresses list of Catalog > Brands & Suppliers > Brands '
                . 'does; the brand is kept. Irreversible: read it with somcp_get_manufacturer_address and have the '
                . 'merchant confirm, naming the brand and the city. Customer and supplier addresses are refused. '
                . 'id_address comes from '
                . 'somcp_list_manufacturer_addresses: never guess one. To change an address use '
                . 'somcp_update_manufacturer_address. One address per call. Not to be retried: if the answer is lost, '
                . 'read somcp_list_manufacturer_addresses for the brand to see whether it is gone.',
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
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // The right the back office checks on its delete action for a
            // brand address, on the brands page: edit alone does not allow it.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminManufacturers')
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

        // A cast would read an array as 1 and delete an address nobody asked
        // for: only a whole number is an identifier.
        $idAddress = SomcpTool::id($arguments, 'id_address');
        $errors = SomcpTool::unknownFields($arguments, ['id_address']);

        // Refused, not ignored: a second call for an address already deleted
        // must not read as a success.
        $address = null;
        if ($idAddress === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_address');
        } else {
            $address = SomcpToolGetManufacturerAddress::existingAddress($idAddress, $idShop);
            if ($address === null) {
                $errors[] = SomcpTool::notFound('id_address', 'brand address', $idAddress, 'somcp_list_manufacturer_addresses');
            }
        }
        if ($errors !== [] || $address === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        // Read before deleting: afterwards there is nothing left to read them
        // from, and the merchant needs them to know which address went.
        $before = SomcpToolGetManufacturerAddress::address($address);

        try {
            $done = (bool) $address->delete();
        } catch (Throwable $e) {
            $done = false;
        }

        // Asked of the shop rather than inferred from delete(): the same test
        // as the read tools, so "deleted" means the merchant no longer sees it.
        $gone = SomcpToolGetManufacturerAddress::existingAddress($idAddress, $idShop) === null;

        if (!$done || !$gone) {
            SomcpLogger::log(
                'manufacturer_address_delete_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'address ' . $idAddress . ($gone ? ' removed, clean-up incomplete' : ' still in the shop'),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                $gone
                    ? 'The brand address was removed, but PrestaShop reported a problem while doing it. Tell the '
                        . 'merchant; there is nothing to call again.'
                    : 'The brand address could not be deleted. Read it with somcp_get_manufacturer_address and '
                        . 'tell the merchant before anything else.',
                true
            );
        }

        SomcpLogger::log(
            'manufacturer_address_deleted',
            SomcpLogger::OUTCOME_SUCCESS,
            'address ' . $idAddress . ' of brand ' . (int) $before['id_manufacturer'],
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'deleted' => true,
            'id_address' => $idAddress,
            'manufacturer_name' => $before['manufacturer_name'],
            'city' => $before['city'],
        ]);
    }
}
