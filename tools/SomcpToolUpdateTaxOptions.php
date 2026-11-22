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
 * somcp_update_tax_options - the Save of the Tax options form below the list
 * of International > Taxes > Taxes: Enable tax, Display tax in the shopping
 * cart, Based on, Use ecotax and Ecotax.
 *
 * Saved by their configuration keys for the shop of the token, with the two
 * side effects of the core's TaxOptionsConfiguration::updateConfiguration():
 * Enable tax off turns the cart line off, and Use ecotax off sets the ecotax
 * of every product to 0. That second one cannot be undone, so it only runs
 * when the call confirms it.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolUpdateTaxOptions
{
    private const FIELDS = [
        'taxes_enabled',
        'display_tax_in_cart',
        'based_on',
        'use_ecotax',
        'ecotax_id_tax_rules_group',
        'reset_product_ecotax',
    ];

    /**
     * What somcp_get_tax_options answers beside the five options: given back
     * as read they are no change, given otherwise they are named as the
     * shop's, not as misspelt fields.
     */
    private const COMPUTED = ['ecotax_tax_rule', 'ecotax_rate', 'language', 'warning'];

    /**
     * The words of Based on, keyed to the address column the core stores.
     */
    private const BASED_ON = [
        'invoice_address' => 'id_address_invoice',
        'delivery_address' => 'id_address_delivery',
    ];

    /**
     * The switches, keyed to their configuration key.
     */
    private const SWITCHES = [
        'taxes_enabled' => 'PS_TAX',
        'display_tax_in_cart' => 'PS_TAX_DISPLAY',
        'use_ecotax' => 'PS_USE_ECOTAX',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_tax_options',
            Somcp::t('Change the tax options'),
            'Changes the Tax options of International > Taxes > Taxes, as their Save: taxes_enabled, '
                . 'display_tax_in_cart, based_on, use_ecotax, ecotax_id_tax_rules_group. Only the fields given '
                . 'change; the answer of somcp_get_tax_options can be given back. Turning the ecotax off sets the '
                . 'ecotax of every product to 0, so it needs reset_product_ecotax when a product has one. Prices '
                . 'of carts not yet ordered follow; placed orders keep theirs. For the rates use '
                . 'somcp_update_tax or somcp_update_country_rule, for one product\'s ecotax somcp_update_product.',
            [
                'type' => 'object',
                'properties' => [
                    'taxes_enabled' => [
                        'type' => 'boolean',
                        'description' => 'Enable tax. Off, no rate is charged and the cart shows no tax line.',
                    ],
                    'display_tax_in_cart' => [
                        'type' => 'boolean',
                        'description' => 'Display tax in the shopping cart. Needs Enable tax on.',
                    ],
                    'based_on' => [
                        'type' => 'string',
                        'enum' => array_keys(self::BASED_ON),
                        'description' => 'Based on: the address whose country picks the rate.',
                    ],
                    'use_ecotax' => [
                        'type' => 'boolean',
                        'description' => 'Use ecotax.',
                    ],
                    'ecotax_id_tax_rules_group' => [
                        'type' => 'integer',
                        'description' => 'Ecotax: the enabled tax rule charged on the ecotax, from somcp_list_tax_rules, '
                            . '0 for No tax. Needs Use ecotax on.',
                    ],
                    'reset_product_ecotax' => [
                        'type' => 'boolean',
                        'description' => 'true confirms use_ecotax false when products carry an ecotax: it sets '
                            . 'their ecotax to 0, and nothing gives it back.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminTaxes')
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
        $language = SomcpTool::readLanguage([], $idShop);
        $idLang = $language !== null ? $language[0] : 0;
        $stored = SomcpToolGetTaxOptions::options($idShop, $idLang);

        // What the read worked out, given back as it reads, is no change and
        // is taken out, so a read given back whole is not refused for it; a
        // value that differs is named among the fields the shop works out.
        $read = $stored + ['language' => $language !== null ? $language[1] : ''];
        $checked = $arguments;
        foreach (['ecotax_tax_rule', 'ecotax_rate', 'language'] as $key) {
            if (!array_key_exists($key, $checked)) {
                continue;
            }
            $value = $key === 'language' && is_string($checked[$key]) ? Tools::strtolower($checked[$key]) : $checked[$key];
            // A rate read back from JSON may come as 0 or 0.0: compared as a number.
            $same = $key === 'ecotax_rate'
                ? (is_int($value) || is_float($value)) && abs((float) $value - (float) $read[$key]) < 0.0005
                : json_encode($value) === json_encode($read[$key]);
            if ($same) {
                unset($checked[$key]);
            }
        }

        // Every problem in one answer (#169), so the call is corrected in one go.
        $problems = SomcpTool::unknownFields($checked, self::FIELDS, self::COMPUTED);

        $given = [];
        foreach (array_merge(array_keys(self::SWITCHES), ['reset_product_ecotax']) as $field) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            if (!is_bool($arguments[$field])) {
                $problems[] = $field . ': true or false';
                continue;
            }
            $given[$field] = $arguments[$field];
        }
        if (array_key_exists('based_on', $arguments) && $arguments['based_on'] !== null) {
            if (is_string($arguments['based_on']) && isset(self::BASED_ON[$arguments['based_on']])) {
                $given['based_on'] = $arguments['based_on'];
            } else {
                $problems[] = 'based_on: invoice_address or delivery_address';
            }
        }
        if (array_key_exists('ecotax_id_tax_rules_group', $arguments) && $arguments['ecotax_id_tax_rules_group'] !== null) {
            $value = $arguments['ecotax_id_tax_rules_group'];
            $idRule = SomcpTool::id($arguments, 'ecotax_id_tax_rules_group');
            if ($value === 0 || $value === '0') {
                // No tax, the first choice of the select.
                $given['ecotax_id_tax_rules_group'] = 0;
            } elseif ($idRule === 0) {
                $problems[] = SomcpTool::notAnIdentifier('ecotax_id_tax_rules_group');
            } else {
                $rule = SomcpToolGetTaxRule::existingRule($idRule, $idShop);
                if ($rule === null) {
                    $problems[] = SomcpTool::notFound('ecotax_id_tax_rules_group', 'tax rule', $idRule, 'somcp_list_tax_rules');
                } elseif ((int) $rule['active'] !== 1) {
                    $problems[] = 'ecotax_id_tax_rules_group: the tax rule ' . $idRule
                        . ' is disabled - the form offers enabled rules only';
                } else {
                    $given['ecotax_id_tax_rules_group'] = $idRule;
                }
            }
        }

        // The values the shop would hold after the call: what is given, the
        // stored value otherwise, so each condition reads the final state.
        $taxes = $given['taxes_enabled'] ?? $stored['taxes_enabled'];
        $ecotax = $given['use_ecotax'] ?? $stored['use_ecotax'];

        // A value given equal to the stored one is never refused, so the
        // answer of somcp_get_tax_options given back passes as it is.
        if (($given['display_tax_in_cart'] ?? null) === true && !$stored['display_tax_in_cart'] && !$taxes) {
            $problems[] = 'display_tax_in_cart: needs Enable tax on - the shop shows no tax line while taxes are off';
        }
        if (isset($given['ecotax_id_tax_rules_group'])
            && $given['ecotax_id_tax_rules_group'] !== $stored['ecotax_id_tax_rules_group'] && !$ecotax) {
            // The form hides the field while the ecotax is off and never saves it.
            $problems[] = 'ecotax_id_tax_rules_group: needs Use ecotax on';
        }

        $turningOff = $stored['use_ecotax'] && ($given['use_ecotax'] ?? null) === false;
        if (isset($given['reset_product_ecotax']) && ($given['use_ecotax'] ?? null) !== false) {
            $problems[] = 'reset_product_ecotax: only with use_ecotax: false';
        }
        $carrying = $turningOff ? self::productsWithEcotax($idShop) : 0;
        // The core wipes the ecotax of every product when the switch goes off,
        // which no screen can give back: an assistant turning a setting off
        // does not read the help text that warns of it, so the call has to
        // say it knows.
        if ($carrying > 0 && ($given['reset_product_ecotax'] ?? false) !== true) {
            $problems[] = 'use_ecotax: turning the ecotax off sets the ecotax of ' . $carrying
                . ' product(s) to 0, and nothing gives it back - call again with reset_product_ecotax: true to confirm';
        }

        if ($problems !== []) {
            return SomcpTool::refused('changed', $problems);
        }

        $plan = self::plan($given, $stored, $taxes);
        if ($plan === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The tax options already hold every value given. Nothing was written.',
                'tax_options' => $stored,
            ]);
        }

        // Saved in the order of the core's Save: the tax switches, then the
        // ecotax reset before its switch, then the ecotax rule.
        $saved = [];
        $reset = null;
        foreach ($plan as $change) {
            if ($change['key'] === 'PS_USE_ECOTAX' && $turningOff && $carrying > 0) {
                if (!Product::resetEcoTax()) {
                    return self::failed($saved, 'the ecotax of the products could not be set to 0', $idEmployee);
                }
                $reset = $carrying;
            }
            if (!Configuration::updateValue($change['key'], $change['stored'], false, $idShopGroup, $idShop)) {
                return self::failed($saved, $change['field'] . ' could not be saved', $idEmployee);
            }
            $saved[] = $change['field'];
        }
        SomcpLogger::log('tax_options_updated', SomcpLogger::OUTCOME_SUCCESS, implode(', ', $saved), '', $idEmployee);

        $result = [
            'changed' => true,
            'changes' => array_map(static function (array $change): array {
                return ['field' => $change['field'], 'old' => $change['old'], 'new' => $change['new']];
            }, $plan),
        ];
        if ($reset !== null) {
            $result['products_reset'] = $reset;
        }
        $warnings = [];
        if ($reset !== null) {
            $warnings[] = 'The ecotax of ' . $reset . ' product(s) was set to 0, in every shop, as the back office does.';
        }
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'The options changed for this shop only, as the back office saves them in a shop context.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        // Read back rather than echoed: what the assistant shows is what the
        // shop stored.
        $result['tax_options'] = SomcpToolGetTaxOptions::options($idShop, $idLang);

        return SomcpTool::json($result);
    }

    /**
     * The keys to save, each with its field, its old and new value under the
     * names of the parameters, and the value stored.
     *
     * @param array<string, mixed> $given
     * @param array<string, mixed> $stored
     * @param bool $taxes Enable tax after the call
     *
     * @return array<int, array{field: string, key: string, old: mixed, new: mixed, stored: int|string}>
     */
    private static function plan(array $given, array $stored, bool $taxes): array
    {
        $plan = [];
        foreach (['taxes_enabled', 'display_tax_in_cart'] as $field) {
            if (isset($given[$field]) && $given[$field] !== $stored[$field]) {
                $plan[$field] = self::change($field, self::SWITCHES[$field], $stored[$field], $given[$field]);
            }
        }
        if (isset($given['based_on']) && $given['based_on'] !== $stored['based_on']) {
            $plan['based_on'] = [
                'field' => 'based_on',
                'key' => 'PS_TAX_ADDRESS_TYPE',
                'old' => $stored['based_on'],
                'new' => $given['based_on'],
                'stored' => self::BASED_ON[$given['based_on']],
            ];
        }
        if (isset($given['use_ecotax']) && $given['use_ecotax'] !== $stored['use_ecotax']) {
            $plan['use_ecotax'] = self::change('use_ecotax', 'PS_USE_ECOTAX', $stored['use_ecotax'], $given['use_ecotax']);
        }
        if (isset($given['ecotax_id_tax_rules_group'])
            && $given['ecotax_id_tax_rules_group'] !== $stored['ecotax_id_tax_rules_group']) {
            $plan['ecotax_id_tax_rules_group'] = [
                'field' => 'ecotax_id_tax_rules_group',
                'key' => 'PS_ECOTAX_TAX_RULES_GROUP_ID',
                'old' => $stored['ecotax_id_tax_rules_group'],
                'new' => $given['ecotax_id_tax_rules_group'],
                'stored' => $given['ecotax_id_tax_rules_group'],
            ];
        }
        // The core turns the cart line off whenever it saves with Enable tax
        // off, so a save that leaves taxes off does the same.
        if ($plan !== [] && !$taxes && $stored['display_tax_in_cart'] && !isset($plan['display_tax_in_cart'])) {
            $plan['display_tax_in_cart'] = self::change('display_tax_in_cart', 'PS_TAX_DISPLAY', true, false);
        }

        // The order of the core's Save, whatever order the fields came in.
        $order = ['taxes_enabled', 'display_tax_in_cart', 'based_on', 'use_ecotax', 'ecotax_id_tax_rules_group'];
        $sorted = [];
        foreach ($order as $field) {
            if (isset($plan[$field])) {
                $sorted[] = $plan[$field];
            }
        }

        return $sorted;
    }

    /**
     * One switch to save.
     *
     * @param string $field
     * @param string $key
     * @param bool $old
     * @param bool $new
     *
     * @return array{field: string, key: string, old: bool, new: bool, stored: int}
     */
    private static function change(string $field, string $key, bool $old, bool $new): array
    {
        return ['field' => $field, 'key' => $key, 'old' => $old, 'new' => $new, 'stored' => $new ? 1 : 0];
    }

    /**
     * The products of the shop whose ecotax turning the switch off would wipe.
     *
     * @param int $idShop
     *
     * @return int
     */
    private static function productsWithEcotax(int $idShop): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `id_shop` = ' . (int) $idShop . ' AND `ecotax` > 0'
        );
    }

    /**
     * The answer to a save that stopped half way, naming what was saved so
     * the assistant reads the options before trying again.
     *
     * @param array<int, string> $saved
     * @param string $failure
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function failed(array $saved, string $failure, int $idEmployee): array
    {
        SomcpLogger::log('tax_options_update_failed', SomcpLogger::OUTCOME_FAILURE, $failure, '', $idEmployee);

        return SomcpTool::text(
            'The tax options could not be changed: ' . $failure
                . ($saved !== [] ? ', after ' . implode(', ', $saved) . ' had been saved' : '')
                . '. Read them with somcp_get_tax_options before calling again.',
            true
        );
    }
}
