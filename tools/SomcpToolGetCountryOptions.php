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
 * somcp_get_country_options - the Country options panel of International >
 * Locations > Countries: Restrict country selections in front office to those
 * covered by active carriers.
 *
 * The setting decides which countries a customer can pick in an address: only
 * those an active carrier delivers when it is on, every active country
 * otherwise. An assistant that enables a country cannot tell without it
 * whether customers will see it.
 *
 * Read by its configuration key for the shop of the token, as the tax options
 * are read. PrestaShop 9 dropped the panel but its storefront still applies
 * the key, so the answer says so there.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetCountryOptions
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_country_options',
            Somcp::t('Read the country options'),
            'Reads the Country options of International > Locations > Countries: Restrict country selections in '
                . 'front office to those covered by active carriers (restrict_to_carriers). When it is true, a '
                . 'customer can only pick a country an active carrier delivers. Read it before enabling a country. '
                . 'For the countries themselves, somcp_list_countries.',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_READ],
            [self::class, 'handle'],
            // The panel sits on the page of the countries, whose view right
            // lets a merchant read it.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminCountries')
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
        $problems = SomcpTool::unknownFields($arguments, []);
        if ($problems !== []) {
            return SomcpTool::refused('read', $problems);
        }

        $result = self::read(Somcp::currentShopId());
        if (version_compare(_PS_VERSION_, '9.0.0', '>=')) {
            // Told, so the merchant does not look for a switch that is gone.
            $result['note'] = 'PrestaShop 9 no longer shows this option, but the storefront still applies it.';
        }

        return SomcpTool::json($result);
    }

    /**
     * The country options of a shop, under the names an update takes back.
     *
     * Public so the tool that changes them answers with them as this one
     * reads them, rather than with a second shape that could drift.
     *
     * @param int $idShop
     *
     * @return array{restrict_to_carriers: bool}
     */
    public static function read(int $idShop): array
    {
        // The group of the shop too, so a value set for the group of shops is
        // read as the storefront of that shop applies it.
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);

        return [
            'restrict_to_carriers' => (bool) Configuration::get('PS_RESTRICT_DELIVERED_COUNTRIES', null, $idShopGroup, $idShop),
        ];
    }
}
