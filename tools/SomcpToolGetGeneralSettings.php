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
 * somcp_get_general_settings - the price rounding and the three storefront
 * pages of Shop Parameters > General, tab General: Round mode, Round type,
 * Display suppliers, Display brands and Display best sellers.
 *
 * The rounding decides every price the shop shows and every total it
 * invoices, and the switches whether the storefront has a suppliers, a
 * brands and a best sellers page. The other fields of the tab (SSL, front
 * office security, iframes, HTMLPurifier, multistore) are left out on
 * purpose: no tool of this pair reads or writes them.
 *
 * Read by their configuration keys for the shop of the token, as the core's
 * PreferencesConfiguration reads them for the shop of the context.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetGeneralSettings
{
    /**
     * Round mode, code => the number the core stores, in the order of the
     * screen. Codes rather than numbers because the numbers of the core's
     * PS_ROUND_* constants say nothing on their own. Public so the update
     * takes the very same list.
     */
    public const ROUND_MODES = [
        'half_up' => 2,
        'half_down' => 3,
        'half_even' => 4,
        'half_odd' => 5,
        'up' => 0,
        'down' => 1,
    ];

    /**
     * Round type, code => the number the core stores (Order::ROUND_ITEM,
     * ROUND_LINE and ROUND_TOTAL), in the order of the screen.
     */
    public const ROUND_TYPES = [
        'item' => 1,
        'line' => 2,
        'total' => 3,
    ];

    /**
     * The words of the screen for each choice, in English like every answer.
     */
    private const ROUND_MODE_LABELS = [
        'half_up' => 'Round up away from zero, when it is half way there (recommended)',
        'half_down' => 'Round down towards zero, when it is half way there',
        'half_even' => 'Round towards the next even value',
        'half_odd' => 'Round towards the next odd value',
        'up' => 'Round up to the nearest value',
        'down' => 'Round down to the nearest value',
    ];

    private const ROUND_TYPE_LABELS = [
        'item' => 'Round on each item',
        'line' => 'Round on each line',
        'total' => 'Round on the total',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_general_settings',
            Somcp::t('Read the general settings'),
            'Reads the price rounding and storefront pages of Shop Parameters > General: Round mode '
                . '(half_up, half_down, half_even, half_odd, up, down), Round type (item, line, total), and '
                . 'whether the suppliers, brands and best sellers pages are shown. Change them with '
                . 'somcp_update_general_settings. For the tax options use somcp_get_tax_options, for the '
                . 'default language, country, currency and units somcp_get_localization_settings, for one '
                . 'product\'s price somcp_get_product.',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // The tab General of Shop Parameters > General is AdminPreferences,
            // the page whose view right lets a merchant read it.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminPreferences')
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

        $idShop = Somcp::currentShopId();
        $result = self::settings($idShop);

        $warnings = [];
        foreach (['round_mode' => 'PS_PRICE_ROUND_MODE', 'round_type' => 'PS_ROUND_TYPE'] as $field => $key) {
            if ($result[$field] === null) {
                $warnings[] = ($field === 'round_mode' ? 'Round mode' : 'Round type') . ' holds "'
                    . self::stored($key, $idShop) . '" (' . $key . '), none of the choices of the screen: '
                    . 'choose one with somcp_update_general_settings.';
            }
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }

    /**
     * The five general settings of a shop, under the names the update takes
     * back.
     *
     * Public so the tool that changes them compares with and answers with
     * this very reading, rather than a second shape that could drift.
     *
     * @param int $idShop
     *
     * @return array{round_mode: string|null, round_mode_label: string|null, round_type: string|null,
     *     round_type_label: string|null, display_suppliers: bool, display_brands: bool,
     *     display_best_sellers: bool}
     */
    public static function settings(int $idShop): array
    {
        $modeCode = self::code(self::ROUND_MODES, self::stored('PS_PRICE_ROUND_MODE', $idShop));
        $typeCode = self::code(self::ROUND_TYPES, self::stored('PS_ROUND_TYPE', $idShop));

        return [
            'round_mode' => $modeCode,
            'round_mode_label' => $modeCode !== null ? self::ROUND_MODE_LABELS[$modeCode] : null,
            'round_type' => $typeCode,
            'round_type_label' => $typeCode !== null ? self::ROUND_TYPE_LABELS[$typeCode] : null,
            'display_suppliers' => (bool) self::stored('PS_DISPLAY_SUPPLIERS', $idShop),
            // The key says manufacturers, the screen says brands.
            'display_brands' => (bool) self::stored('PS_DISPLAY_MANUFACTURERS', $idShop),
            'display_best_sellers' => (bool) self::stored('PS_DISPLAY_BEST_SELLERS', $idShop),
        ];
    }

    /**
     * A key as stored for the shop of the token.
     *
     * @param string $key
     * @param int $idShop
     *
     * @return string
     */
    private static function stored(string $key, int $idShop): string
    {
        // The group of the shop too, so a value set for the group of shops is
        // read as the back office of that shop shows it.
        return (string) Configuration::get($key, null, (int) Shop::getGroupFromShop($idShop), $idShop);
    }

    /**
     * The label of a code of ROUND_MODES or ROUND_TYPES.
     *
     * @param string $code
     *
     * @return string|null
     */
    public static function label(string $code): ?string
    {
        return self::ROUND_MODE_LABELS[$code] ?? self::ROUND_TYPE_LABELS[$code] ?? null;
    }

    /**
     * The code of a stored number, or null when the number is none of the
     * list (an old shop): never guessed into the nearest choice.
     *
     * @param array<string, int> $list
     * @param string $stored
     *
     * @return string|null
     */
    private static function code(array $list, string $stored): ?string
    {
        if ($stored === '' || !ctype_digit($stored)) {
            return null;
        }
        $code = array_search((int) $stored, $list, true);

        return $code === false ? null : $code;
    }
}
