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
 * somcp_update_general_settings - changes the price rounding and the three
 * storefront pages of Shop Parameters > General, tab General, as its Save
 * does for those fields: Round mode, Round type, Display suppliers, Display
 * brands and Display best sellers.
 *
 * The core's PreferencesConfiguration::updateConfiguration() saves each of
 * these keys as given, with no other step, so the tool saves them the same
 * way for the shop of the token. Unlike the back office, which saves the
 * whole tab at once, only the keys whose value differs are written, so the
 * other fields of the tab (SSL, front office security, iframes, HTMLPurifier,
 * multistore) are never touched: they are refused as unknown fields.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolUpdateGeneralSettings
{
    /**
     * The switches, keyed to their configuration key, in the order of the
     * screen.
     */
    private const SWITCHES = [
        'display_suppliers' => 'PS_DISPLAY_SUPPLIERS',
        'display_brands' => 'PS_DISPLAY_MANUFACTURERS',
        'display_best_sellers' => 'PS_DISPLAY_BEST_SELLERS',
    ];

    /**
     * What somcp_get_general_settings works out beside the five settings:
     * given back as read they are no change, given otherwise they are named
     * as the shop's, not as misspelt fields.
     */
    private const COMPUTED = ['round_mode_label', 'round_type_label', 'warning'];

    /**
     * The storefront page each switch shows, for the warning.
     */
    private const PAGES = [
        'display_suppliers' => 'suppliers',
        'display_brands' => 'brands',
        'display_best_sellers' => 'best sellers',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_general_settings',
            Somcp::t('Change the general settings'),
            'Changes the price rounding and storefront pages of Shop Parameters > General, as its Save: '
                . 'round_mode, round_type, display_suppliers, display_brands, display_best_sellers. Only the '
                . 'fields given change; the answer of somcp_get_general_settings can be given back. New carts '
                . 'and orders use a new rounding, orders already made keep their totals. For the tax options use '
                . 'somcp_update_tax_options, for the language, country, currency and units '
                . 'somcp_update_localization_settings.',
            [
                'type' => 'object',
                'properties' => [
                    'round_mode' => [
                        'type' => 'string',
                        'enum' => array_keys(SomcpToolGetGeneralSettings::ROUND_MODES),
                        'description' => 'Round mode: half_up (recommended) rounds away from zero when half way, '
                            . 'half_down towards zero, half_even and half_odd to the next even or odd value, up '
                            . 'and down to the nearest value above or below.',
                    ],
                    'round_type' => [
                        'type' => 'string',
                        'enum' => array_keys(SomcpToolGetGeneralSettings::ROUND_TYPES),
                        'description' => 'Round type: round on each item, each line or the total.',
                    ],
                    'display_suppliers' => [
                        'type' => 'boolean',
                        'description' => 'Display suppliers: the suppliers page of the storefront, even when its '
                            . 'module is disabled.',
                    ],
                    'display_brands' => [
                        'type' => 'boolean',
                        'description' => 'Display brands: the brands page of the storefront, even when its module '
                            . 'is disabled.',
                    ],
                    'display_best_sellers' => [
                        'type' => 'boolean',
                        'description' => 'Display best sellers: the best sellers page of the storefront, even when '
                            . 'its module is disabled.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminPreferences')
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
        $stored = SomcpToolGetGeneralSettings::settings($idShop);

        // The labels the read worked out, given back as it reads, are no
        // change and are taken out, so a read given back whole is not refused
        // for them; a label that differs is named among the shop's fields.
        $checked = $arguments;
        foreach (['round_mode_label', 'round_type_label'] as $key) {
            if (array_key_exists($key, $checked) && $checked[$key] === $stored[$key]) {
                unset($checked[$key]);
            }
        }
        // The read's warning names a stored number outside the lists; given
        // back with the rest it is a remark, never a setting.
        if (isset($checked['warning']) && is_string($checked['warning'])) {
            unset($checked['warning']);
        }

        // Every problem in one answer (#169), so the call is corrected in one go.
        $problems = SomcpTool::unknownFields(
            $checked,
            array_merge(['round_mode', 'round_type'], array_keys(self::SWITCHES)),
            self::COMPUTED
        );

        $given = [];
        foreach (['round_mode' => SomcpToolGetGeneralSettings::ROUND_MODES, 'round_type' => SomcpToolGetGeneralSettings::ROUND_TYPES] as $field => $list) {
            // Null is left out, so a read whose stored number matched no
            // choice can still be given back whole.
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            if (is_string($arguments[$field]) && isset($list[$arguments[$field]])) {
                $given[$field] = $arguments[$field];
            } else {
                $problems[] = $field . ': one of ' . implode(', ', array_keys($list));
            }
        }
        foreach (array_keys(self::SWITCHES) as $field) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            if (!is_bool($arguments[$field])) {
                $problems[] = $field . ': true or false';
                continue;
            }
            $given[$field] = $arguments[$field];
        }

        if ($problems !== []) {
            return SomcpTool::refused('changed', $problems);
        }

        $plan = self::plan($given, $stored);
        if ($plan === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The general settings already hold every value given. Nothing was written.',
                'settings' => $stored,
            ]);
        }

        $saved = [];
        foreach ($plan as $change) {
            if (!Configuration::updateValue($change['key'], $change['stored'], false, $idShopGroup, $idShop)) {
                return self::failed($saved, $change['field'] . ' could not be saved', $idEmployee);
            }
            $saved[] = $change['field'];
        }
        SomcpLogger::log('general_settings_updated', SomcpLogger::OUTCOME_SUCCESS, implode(', ', $saved), '', $idEmployee);

        $result = [
            'changed' => true,
            'changes' => array_map(static function (array $change): array {
                return ['field' => $change['field'], 'old' => $change['old'], 'new' => $change['new']];
            }, $plan),
        ];

        $warnings = [];
        $rounding = [];
        foreach ($plan as $change) {
            if (isset(self::PAGES[$change['field']])) {
                if ($change['new'] === true) {
                    $warnings[] = 'The ' . self::PAGES[$change['field']] . ' page is now shown on the storefront, '
                        . 'even when its module is disabled.';
                }
            } else {
                $rounding[] = (string) SomcpToolGetGeneralSettings::label((string) $change['new']);
            }
        }
        if ($rounding !== []) {
            // The core reads the keys at each computation (Tools::ps_round(),
            // Cart::getOrderTotal()): nothing stored is worked out again.
            array_unshift($warnings, 'Prices are now rounded with ' . implode(' and ', $rounding) . ': orders and '
                . 'invoices already made keep their totals, new carts and orders use the new rounding.');
        }
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'The settings changed for this shop only, as the back office saves them in a shop context.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        // Read back rather than echoed: what the assistant shows is what the
        // shop stored.
        $result['settings'] = SomcpToolGetGeneralSettings::settings($idShop);

        return SomcpTool::json($result);
    }

    /**
     * The keys to save, in the order of the screen, each with its field, its
     * old and new value under the names and codes of the parameters, and the
     * value stored.
     *
     * @param array<string, mixed> $given
     * @param array<string, mixed> $stored
     *
     * @return array<int, array{field: string, key: string, old: mixed, new: mixed, stored: int}>
     */
    private static function plan(array $given, array $stored): array
    {
        $plan = [];
        $lists = [
            'round_mode' => ['PS_PRICE_ROUND_MODE', SomcpToolGetGeneralSettings::ROUND_MODES],
            'round_type' => ['PS_ROUND_TYPE', SomcpToolGetGeneralSettings::ROUND_TYPES],
        ];
        foreach ($lists as $field => [$key, $list]) {
            if (isset($given[$field]) && $given[$field] !== $stored[$field]) {
                $plan[] = [
                    'field' => $field,
                    'key' => $key,
                    'old' => $stored[$field],
                    'new' => $given[$field],
                    'stored' => $list[$given[$field]],
                ];
            }
        }
        foreach (self::SWITCHES as $field => $key) {
            if (isset($given[$field]) && $given[$field] !== $stored[$field]) {
                $plan[] = [
                    'field' => $field,
                    'key' => $key,
                    'old' => $stored[$field],
                    'new' => $given[$field],
                    'stored' => $given[$field] ? 1 : 0,
                ];
            }
        }

        return $plan;
    }

    /**
     * The answer to a save that stopped half way, naming what was saved so
     * the assistant reads the settings before trying again.
     *
     * Public so every tool that saves configuration keys one after the other
     * stops in the same words, naming its own read.
     *
     * @param array<int, string> $saved
     * @param string $failure
     * @param int $idEmployee
     * @param string $what what could not be changed, as the sentence opens
     * @param string $reader the tool that reads them again
     * @param string $event the line of the module's log
     *
     * @return array<string, mixed>
     */
    public static function failed(
        array $saved,
        string $failure,
        int $idEmployee,
        string $what = 'The general settings',
        string $reader = 'somcp_get_general_settings',
        string $event = 'general_settings_update_failed',
    ): array {
        SomcpLogger::log($event, SomcpLogger::OUTCOME_FAILURE, $failure, '', $idEmployee);

        return SomcpTool::text(
            $what . ' could not be changed: ' . $failure
                . ($saved !== [] ? ', after ' . implode(', ', $saved) . ' had been saved' : '')
                . '. Read them with ' . $reader . ' before calling again.',
            true
        );
    }
}
