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
 * somcp_update_country_options - the Save of the Country options panel of
 * International > Locations > Countries: Restrict country selections in front
 * office to those covered by active carriers.
 *
 * Saved by its configuration key for the shop of the token, as the legacy
 * page saves its options and as the tax options are saved. PrestaShop 9
 * dropped the panel while its storefront still applies the key, so this is
 * the only way left to change it there, and the answer says so.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolUpdateCountryOptions
{
    private const FIELDS = ['restrict_to_carriers'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_country_options',
            Somcp::t('Change the country options'),
            'Changes the Country options of International > Locations > Countries, as their Save: Restrict '
                . 'country selections in front office to those covered by active carriers (restrict_to_carriers). '
                . 'When it is true, a customer can only pick in an address a country an active carrier delivers. '
                . 'The answer of somcp_get_country_options can be given back. To switch a country on or off use '
                . 'somcp_update_country, for the zones a carrier serves somcp_update_carrier.',
            [
                'type' => 'object',
                'properties' => [
                    'restrict_to_carriers' => [
                        'type' => 'boolean',
                        'description' => 'Restrict country selections in front office to those covered by active carriers.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminCountries')
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
        $ps9 = version_compare(_PS_VERSION_, '9.0.0', '>=');
        $stored = SomcpToolGetCountryOptions::read($idShop);

        // The note of the read, given back as it reads, is no change and is
        // taken out, so the read given back whole is not refused for it.
        $checked = $arguments;
        if (array_key_exists('note', $checked) && $ps9 && $checked['note'] === SomcpToolGetCountryOptions::NOTE_PS9) {
            unset($checked['note']);
        }

        // Every problem in one answer (#169), so the call is corrected in one go.
        $problems = SomcpTool::unknownFields($checked, self::FIELDS, ['note']);
        $given = null;
        if (array_key_exists('restrict_to_carriers', $arguments) && $arguments['restrict_to_carriers'] !== null) {
            if (is_bool($arguments['restrict_to_carriers'])) {
                $given = $arguments['restrict_to_carriers'];
            } else {
                $problems[] = 'restrict_to_carriers: true or false';
            }
        }
        if ($problems !== []) {
            return SomcpTool::refused('changed', $problems);
        }

        if ($given === null || $given === $stored['restrict_to_carriers']) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The country options already hold every value given. Nothing was written.',
                'options' => $stored,
            ]);
        }

        if (!Configuration::updateValue('PS_RESTRICT_DELIVERED_COUNTRIES', (int) $given, false, $idShopGroup, $idShop)) {
            SomcpLogger::log('country_options_update_failed', SomcpLogger::OUTCOME_FAILURE, 'restrict_to_carriers could not be saved', '', $idEmployee);

            return SomcpTool::text(
                'The country options could not be changed: restrict_to_carriers could not be saved. '
                    . 'Read them with somcp_get_country_options before calling again.',
                true
            );
        }
        SomcpLogger::log('country_options_updated', SomcpLogger::OUTCOME_SUCCESS, 'restrict_to_carriers', '', $idEmployee);

        $result = [
            'changed' => true,
            'changes' => [['field' => 'restrict_to_carriers', 'old' => $stored['restrict_to_carriers'], 'new' => $given]],
        ];
        $warnings = [];
        // The storefront offers only the countries this returns, so an empty
        // list leaves the address form with no country to pick.
        if ($given && empty(Carrier::getDeliveredCountries((int) Configuration::get('PS_LANG_DEFAULT'), true, true))) {
            $warnings[] = 'No active carrier delivers to an active country: customers can no longer choose any country in their address.';
        }
        if ($ps9) {
            $warnings[] = SomcpToolGetCountryOptions::NOTE_PS9;
        }
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'The option changed for this shop only, as the back office saves it in a shop context.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        // Read back rather than echoed: what the assistant shows is what the
        // shop stored.
        $result['options'] = SomcpToolGetCountryOptions::read($idShop);

        return SomcpTool::json($result);
    }
}
