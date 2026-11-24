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
 * somcp_update_maintenance_settings - opens or closes the storefront and
 * changes its maintenance text, as the Save of Shop Parameters > General,
 * tab Maintenance, does for Enable store, Enable store for logged-in
 * employees (PrestaShop 9 only) and Custom maintenance text.
 *
 * The core's MaintenanceConfiguration::updateConfiguration() saves each field
 * by its configuration key for the shop of the context, the text as HTML, one
 * value per language; the tool saves the same keys the same way for the shop
 * of the token. Maintenance IP is never written: the addresses let through a
 * closed shop are the merchant's own decision, made on the screen.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolUpdateMaintenanceSettings
{
    /**
     * The length FormattedTextareaType::LIMIT_TEXT_UTF8 sets on the text, the
     * counter of the screen.
     */
    private const TEXT_LIMIT = 21844;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_maintenance_settings',
            Somcp::t('Change the maintenance settings'),
            'Opens or closes the storefront and changes the Custom maintenance text of Shop Parameters > '
                . 'General > Maintenance: store_enabled, open_for_logged_in_employees (PrestaShop 9 only) and '
                . 'maintenance_text per language. Only the fields given change; the answer of '
                . 'somcp_get_maintenance_settings can be given back. Close or open the storefront only when the '
                . 'merchant asks. Maintenance IP is never written. For the rounding and storefront pages use '
                . 'somcp_update_general_settings.',
            [
                'type' => 'object',
                'properties' => [
                    'store_enabled' => [
                        'type' => 'boolean',
                        'description' => 'Enable store: false closes the storefront, visitors see the maintenance text.',
                    ],
                    'open_for_logged_in_employees' => [
                        'type' => ['boolean', 'null'],
                        'description' => 'Enable store for logged-in employees: a closed shop stays visible to the '
                            . 'employees logged in to the back office. PrestaShop 9 only; null or left out on 8.',
                    ],
                    'languages' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'ISO codes among the shop\'s active languages whose maintenance text is '
                            . 'given, for example ["fr"]; every active language when left out. A language left '
                            . 'out keeps its text.',
                    ],
                    'maintenance_text' => [
                        'type' => ['object', 'string'],
                        'description' => 'Custom maintenance text, HTML, keyed by ISO code, for example {"en": '
                            . '"Back soon"}; one text alone when one language is given. Never empty, '
                            . self::TEXT_LIMIT . ' characters at most.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // The tab Maintenance is its own page, AdminMaintenance, whose edit
            // right lets an employee save it.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminMaintenance')
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

        // The read's warning, given back with the rest, is a remark, never a
        // setting.
        $checked = $arguments;
        if (isset($checked['warning']) && is_string($checked['warning'])) {
            unset($checked['warning']);
        }

        // Every problem in one answer (#169), so the call is corrected in one go.
        $problems = SomcpTool::unknownFields(
            $checked,
            ['store_enabled', 'open_for_logged_in_employees', 'languages', 'maintenance_text']
        );
        // The languages rule every tool with texts per language follows (#149).
        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $problems);

        $given = [];
        if (array_key_exists('store_enabled', $arguments) && $arguments['store_enabled'] !== null) {
            if (is_bool($arguments['store_enabled'])) {
                $given['store_enabled'] = $arguments['store_enabled'];
            } else {
                $problems[] = 'store_enabled: true or false';
            }
        }
        if (array_key_exists('open_for_logged_in_employees', $arguments) && $arguments['open_for_logged_in_employees'] !== null) {
            if (!SomcpToolGetMaintenanceSettings::hasEmployeeSwitch()) {
                // Null stays accepted, so the read of PrestaShop 8 given back
                // whole is no mistake.
                $problems[] = 'open_for_logged_in_employees: this field exists on PrestaShop 9 only';
            } elseif (is_bool($arguments['open_for_logged_in_employees'])) {
                $given['open_for_logged_in_employees'] = $arguments['open_for_logged_in_employees'];
            } else {
                $problems[] = 'open_for_logged_in_employees: true or false';
            }
        }

        $stored = SomcpToolGetMaintenanceSettings::settings($idShop, $languages === null ? [] : $languages['filled']);
        $texts = [];
        if ($languages !== null && array_key_exists('maintenance_text', $arguments)) {
            $texts = self::texts($arguments, $languages, $stored, $problems);
        }

        if ($problems !== [] || $languages === null) {
            return SomcpTool::refused('changed', $problems);
        }

        $plan = [];
        foreach (['store_enabled' => 'PS_SHOP_ENABLE', 'open_for_logged_in_employees' => 'PS_MAINTENANCE_ALLOW_ADMINS'] as $field => $key) {
            if (isset($given[$field]) && $given[$field] !== $stored[$field]) {
                $plan[] = ['field' => $field, 'key' => $key, 'old' => $stored[$field], 'new' => $given[$field]];
            }
        }
        $byIso = array_flip($languages['filled']);
        $textChanges = [];
        foreach ($texts as $iso => $text) {
            $textChanges[] = [
                'field' => 'maintenance_text (' . $iso . ')',
                'old' => $stored['maintenance_text'][$iso] ?? '',
                'new' => $text,
            ];
        }

        if ($plan === [] && $textChanges === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The maintenance settings already hold every value given. Nothing was written.',
                'settings' => $stored,
            ]);
        }

        $saved = [];
        foreach ($plan as $change) {
            if (!Configuration::updateValue($change['key'], $change['new'] ? 1 : 0, false, $idShopGroup, $idShop)) {
                return self::failed($saved, $change['field'] . ' could not be saved', $idEmployee);
            }
            $saved[] = $change['field'];
        }
        if ($texts !== []) {
            // One value per language, as HTML, as the core saves the text;
            // the languages not given keep theirs.
            $values = [];
            foreach ($texts as $iso => $text) {
                $values[$byIso[$iso]] = $text;
            }
            if (!Configuration::updateValue('PS_MAINTENANCE_TEXT', $values, true, $idShopGroup, $idShop)) {
                return self::failed($saved, 'maintenance_text could not be saved', $idEmployee);
            }
            $saved[] = 'maintenance_text (' . implode(', ', array_keys($texts)) . ')';
        }
        // The fields saved, never the text: it may name people or dates the
        // journal has no use for.
        SomcpLogger::log('maintenance_settings_updated', SomcpLogger::OUTCOME_SUCCESS, implode(', ', $saved), '', $idEmployee);

        $result = [
            'changed' => true,
            'changes' => array_merge(array_map(static function (array $change): array {
                return ['field' => $change['field'], 'old' => $change['old'], 'new' => $change['new']];
            }, $plan), $textChanges),
        ];

        // Read back rather than echoed: what the assistant shows is what the
        // shop stored.
        $settings = SomcpToolGetMaintenanceSettings::settings($idShop, $languages['filled']);

        $warnings = [];
        if (isset($given['store_enabled']) && $given['store_enabled'] !== $stored['store_enabled']) {
            $warnings[] = $given['store_enabled']
                ? 'The storefront is now open to every visitor.'
                : 'The storefront is now closed: visitors see the maintenance text. Only the addresses of '
                    . 'Maintenance IP in the back office'
                    . ($settings['open_for_logged_in_employees'] === true ? ', and the employees logged in to the back office,' : '')
                    . ' see the shop.';
        }
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'The settings changed for this shop only, as the back office saves them in a shop context.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        $result['settings'] = $settings;

        return SomcpTool::json($result);
    }

    /**
     * The texts that differ from the stored ones, keyed by ISO code, each
     * cleaned and checked as the back office's form does.
     *
     * @param array<string, mixed> $arguments
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<string, mixed> $stored
     * @param array<int, string> $problems
     *
     * @return array<string, string>
     */
    private static function texts(array $arguments, array $languages, array $stored, array &$problems): array
    {
        $byLang = SomcpToolCreateProduct::texts($arguments, 'maintenance_text', 'maintenance_text', $languages, $problems);
        if ($byLang === null) {
            return [];
        }

        $storedTexts = is_array($stored['maintenance_text']) ? $stored['maintenance_text'] : [];
        $changes = [];
        foreach ($byLang as $idLang => $text) {
            $iso = $languages['filled'][$idLang];
            $path = 'maintenance_text (' . $iso . ')';
            // The text given back as read is no change, even when cleaning it
            // again would rewrite it.
            if (isset($storedTexts[$iso]) && $text === $storedTexts[$iso]) {
                continue;
            }
            if (trim($text) === '') {
                // The form requires it: a closed shop always tells why.
                $problems[] = $path . ': required, never empty';
                continue;
            }
            $clean = SomcpToolCreateProduct::html($text, $path, $problems);
            $length = Tools::strlen($clean);
            if ($length > self::TEXT_LIMIT) {
                $problems[] = $path . ': ' . self::TEXT_LIMIT . ' characters at most, ' . $length . ' given';
                continue;
            }
            if ($clean !== ($storedTexts[$iso] ?? null)) {
                $changes[$iso] = $clean;
            }
        }

        return $changes;
    }

    /**
     * The answer to a save that stopped half way, naming what was saved so
     * the assistant reads the settings before trying again.
     *
     * @param array<int, string> $saved
     * @param string $failure
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function failed(array $saved, string $failure, int $idEmployee): array
    {
        SomcpLogger::log('maintenance_settings_update_failed', SomcpLogger::OUTCOME_FAILURE, $failure, '', $idEmployee);

        return SomcpTool::text(
            'The maintenance settings could not be changed: ' . $failure
                . ($saved !== [] ? ', after ' . implode(', ', $saved) . ' had been saved' : '')
                . '. Read them with somcp_get_maintenance_settings before calling again.',
            true
        );
    }
}
