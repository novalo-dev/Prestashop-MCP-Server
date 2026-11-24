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
 * somcp_get_maintenance_settings - the tab Maintenance of Shop Parameters >
 * General: Enable store, Enable store for logged-in employees (PrestaShop 9
 * only) and Custom maintenance text.
 *
 * A shop being set up stays closed to visitors until its catalogue, carriers
 * and pages are ready; without this read an assistant cannot tell whether
 * the storefront is open. Maintenance IP is left out on purpose: no tool of
 * this pair reads or writes the addresses let through.
 *
 * Read by their configuration keys for the shop of the token, as the core's
 * MaintenanceConfiguration reads them for the shop of the context.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetMaintenanceSettings
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_maintenance_settings',
            Somcp::t('Read the maintenance settings'),
            'Reads the Maintenance tab of Shop Parameters > General: whether the store is enabled '
                . '(store_enabled; false means visitors see the maintenance page), whether logged-in employees '
                . 'still see it (PrestaShop 9 only, null on 8), and the Custom maintenance text in each language. '
                . 'Read it before telling a merchant the shop is open. To change them use '
                . 'somcp_update_maintenance_settings, to check that the shop answers at all somcp_ping, for the '
                . 'price rounding and storefront pages somcp_get_general_settings.',
            [
                'type' => 'object',
                'properties' => [
                    'languages' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'ISO codes among the shop\'s active languages for the maintenance text, '
                            . 'for example ["en", "fr"]; every active language when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // The tab Maintenance of Shop Parameters > General is its own
            // page, AdminMaintenance, whose view right lets a merchant read it.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminMaintenance')
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

        // Every problem in one answer (#169), so the call is corrected in one go.
        $problems = SomcpTool::unknownFields($arguments, ['languages']);
        // The languages rule every tool with texts per language follows (#149).
        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $problems);
        if ($problems !== [] || $languages === null) {
            return SomcpTool::refused('read', $problems);
        }

        $result = self::settings($idShop, $languages['filled']);

        if (!$result['store_enabled']) {
            $result['warning'] = 'The storefront is closed: visitors see the maintenance text, and only the '
                . 'addresses of Maintenance IP in the back office'
                . ($result['open_for_logged_in_employees'] === true ? ', and the employees logged in to the back office' : '')
                . ' see the shop.';
        }

        return SomcpTool::json($result);
    }

    /**
     * The maintenance settings of a shop, under the names the update takes
     * back.
     *
     * Public so the tool that changes them compares with and answers with
     * this very reading, rather than a second shape that could drift.
     *
     * @param int $idShop
     * @param array<int, string> $idLangs ISO codes keyed by id_lang, the
     *                                    languages of the maintenance text
     *
     * @return array{store_enabled: bool, open_for_logged_in_employees: bool|null,
     *     maintenance_text: array<string, string>|stdClass, languages: array<int, string>}
     */
    public static function settings(int $idShop, array $idLangs): array
    {
        // The group of the shop too, so a value set for the group of shops is
        // read as the back office of that shop shows it.
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);

        $texts = [];
        foreach ($idLangs as $idLang => $iso) {
            $texts[$iso] = (string) Configuration::get('PS_MAINTENANCE_TEXT', (int) $idLang, $idShopGroup, $idShop);
        }

        return [
            'store_enabled' => (bool) Configuration::get('PS_SHOP_ENABLE', null, $idShopGroup, $idShop),
            // The switch exists on PrestaShop 9 only; on 8 there is nothing to
            // read, which null says rather than a false the screen never shows.
            'open_for_logged_in_employees' => self::hasEmployeeSwitch()
                ? (bool) Configuration::get('PS_MAINTENANCE_ALLOW_ADMINS', null, $idShopGroup, $idShop)
                : null,
            // An object even when empty, so the answer keeps one shape.
            'maintenance_text' => $texts === [] ? new stdClass() : $texts,
            'languages' => array_values($idLangs),
        ];
    }

    /**
     * Whether this version of PrestaShop has Enable store for logged-in
     * employees.
     *
     * @return bool
     */
    public static function hasEmployeeSwitch(): bool
    {
        return version_compare(_PS_VERSION_, '9.0.0', '>=');
    }
}
