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
 * somcp_update_shipping_preferences - the Save buttons of the Handling and
 * Carrier options cards of Shipping > Preferences.
 *
 * Saved by their configuration keys for the shop of the token, as the core's
 * HandlingConfiguration and CarrierOptionsConfiguration save them, with no
 * other step. Compared with, and answered with, the reading of
 * somcp_get_shipping_preferences, so the read given back whole writes nothing.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// read before and after, and the carriers the Default carrier list offers.
require_once __DIR__ . '/SomcpToolGetShippingPreferences.php';
require_once __DIR__ . '/SomcpToolListCarriers.php';

class SomcpToolUpdateShippingPreferences
{
    private const FIELDS = [
        'handling_cost_tax_excl',
        'free_shipping_from_price',
        'free_shipping_from_weight',
        'default_carrier',
        'default_id_carrier',
        'carrier_sort',
        'carrier_order',
    ];

    /**
     * What somcp_get_shipping_preferences answers beside the settings: given
     * back as read they are no change, given otherwise they are named as the
     * shop's, not as misspelt fields.
     */
    private const COMPUTED = ['currency', 'weight_unit', 'default_carrier_name', 'default_carrier_delay', 'language', 'warning'];

    /**
     * The three amounts, keyed to their configuration key, in the order of the Handling card.
     */
    private const AMOUNTS = [
        'handling_cost_tax_excl' => 'PS_SHIPPING_HANDLING',
        'free_shipping_from_price' => 'PS_SHIPPING_FREE_PRICE',
        'free_shipping_from_weight' => 'PS_SHIPPING_FREE_WEIGHT',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_shipping_preferences',
            Somcp::t('Change the shipping preferences'),
            'Changes Shipping > Preferences, as the Save of its Handling and Carrier options cards: Handling '
                . 'charges, Free shipping starts at a price and at a weight (0 turns it off), the Default carrier '
                . '(best_price, best_grade, or one carrier by its id_carrier from somcp_list_carriers), and how '
                . 'carriers are sorted. Only the fields given change; the answer of somcp_get_shipping_preferences '
                . 'can be given back. For the ranges of one carrier use somcp_update_carrier.',
            [
                'type' => 'object',
                'properties' => [
                    'handling_cost_tax_excl' => [
                        'type' => 'number',
                        'description' => 'Handling charges, tax excluded, in the default currency, 0 or more. Added '
                            . 'only by the carriers whose handling costs are on.',
                    ],
                    'free_shipping_from_price' => [
                        'type' => 'number',
                        'description' => 'Free shipping starts at this order amount, 0 or more. 0 turns it off.',
                    ],
                    'free_shipping_from_weight' => [
                        'type' => 'number',
                        'description' => 'Free shipping starts at this weight, in the shop weight unit, 0 or more. 0 '
                            . 'turns it off.',
                    ],
                    'default_carrier' => [
                        'type' => 'string',
                        'enum' => ['best_price', 'best_grade', 'carrier'],
                        'description' => 'Default carrier: the best price, the best grade, or the carrier of '
                            . 'default_id_carrier.',
                    ],
                    'default_id_carrier' => [
                        'type' => 'integer',
                        'description' => 'The default carrier, by its id_carrier from somcp_list_carriers. Implies '
                            . 'default_carrier carrier.',
                    ],
                    'carrier_sort' => [
                        'type' => 'string',
                        'enum' => SomcpToolGetShippingPreferences::SORTS,
                        'description' => 'Sort by: how the storefront orders the carriers offered.',
                    ],
                    'carrier_order' => [
                        'type' => 'string',
                        'enum' => SomcpToolGetShippingPreferences::ORDERS,
                        'description' => 'Order by: ascending or descending.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminShipping')
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
        // The language the read was given back in, so its transit time
        // compares with the same language; an unknown one is named below.
        $language = SomcpTool::readLanguage($arguments, $idShop);
        $default = $language ?? SomcpTool::readLanguage([], $idShop);
        $idLang = $default !== null ? $default[0] : 0;
        $before = SomcpToolGetShippingPreferences::preferences($idShop, $idLang);

        // What the read worked out, given back as it reads, is no change and
        // is taken out, so a read given back whole is not refused for it.
        $read = $before + ['language' => $language !== null ? $language[1] : null, 'warning' => null];
        $checked = $arguments;
        foreach (self::COMPUTED as $key) {
            if (!array_key_exists($key, $checked)) {
                continue;
            }
            if ($key === 'language' ? $language !== null : ($key === 'warning' || json_encode($checked[$key]) === json_encode($read[$key]))) {
                unset($checked[$key]);
            }
        }

        // Every problem in one answer (#169), so the call is corrected in one go.
        $problems = SomcpTool::unknownFields($checked, self::FIELDS, self::COMPUTED);
        $currency = (string) $before['currency'];
        $unit = (string) $before['weight_unit'];

        $given = [];
        foreach (array_keys(self::AMOUNTS) as $field) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            $value = $arguments[$field];
            $number = (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0;
            // The checks of the form types: a price for the two amounts, an
            // unsigned number for the weight.
            $valid = $number && ($field === 'free_shipping_from_weight'
                ? Validate::isUnsignedFloat((string) $value)
                : Validate::isPrice((string) $value));
            if ($valid) {
                $given[$field] = (float) $value;
            } elseif ($field === 'free_shipping_from_weight') {
                $problems[] = $field . ': a weight of 0 or more, in ' . $unit;
            } else {
                $problems[] = $field . ': an amount of 0 or more, tax excluded, in ' . $currency;
            }
        }
        foreach (['carrier_sort' => SomcpToolGetShippingPreferences::SORTS, 'carrier_order' => SomcpToolGetShippingPreferences::ORDERS] as $field => $choices) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            if (is_string($arguments[$field]) && in_array($arguments[$field], $choices, true)) {
                $given[$field] = $arguments[$field];
            } else {
                $problems[] = $field . ': ' . implode(' or ', $choices);
            }
        }

        $stored = self::defaultCarrier($arguments, $before, $idShop, $idLang, $problems);

        if ($problems !== []) {
            return SomcpTool::refused('changed', $problems);
        }

        $plan = self::plan($given, $before, $stored, $idShopGroup, $idShop, $idLang);
        if ($plan === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The shipping preferences already hold every value given. Nothing was written.',
                'preferences' => $before,
            ]);
        }

        $saved = [];
        foreach ($plan as $save) {
            if (!Configuration::updateValue($save['key'], $save['stored'], false, $idShopGroup, $idShop)) {
                return self::failed($saved, implode(', ', $save['fields']) . ' could not be saved', $idEmployee);
            }
            $saved = array_merge($saved, $save['fields']);
        }
        SomcpLogger::log('shipping_preferences_updated', SomcpLogger::OUTCOME_SUCCESS, implode(', ', $saved), '', $idEmployee);

        $changes = [];
        foreach ($plan as $save) {
            foreach ($save['changes'] as $change) {
                $changes[] = $change;
            }
        }
        $result = ['changed' => true, 'changes' => $changes];

        $warnings = [];
        if (in_array('handling_cost_tax_excl', $saved, true)) {
            $warnings[] = 'Handling charges are added only by the carriers whose Handling costs is on '
                . '(handling_costs of somcp_get_carrier).';
        }
        foreach (['free_shipping_from_price' => $currency, 'free_shipping_from_weight' => $unit] as $field => $suffix) {
            if (in_array($field, $saved, true) && $given[$field] > 0) {
                $warnings[] = 'Every carrier is now free from ' . $given[$field] . ' ' . $suffix . ', whatever its own ranges.';
            }
        }
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'The settings changed for this shop only, as the back office saves them in a shop context.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        // Read back rather than echoed: what the assistant shows is what the
        // shop stored.
        $result['preferences'] = SomcpToolGetShippingPreferences::preferences($idShop, $idLang);

        return SomcpTool::json($result);
    }

    /**
     * The value of Default carrier the call asks for, as the core stores it
     * (-1, -2 or a carrier's id_reference), or null when the call leaves it.
     *
     * The reference rather than the id_carrier, as the core's choice list
     * stores it: an edited carrier gets a new id_carrier under the same
     * reference, and the default follows it.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $before
     * @param int $idShop
     * @param int $idLang
     * @param array<int, string> $problems
     *
     * @return int|null
     */
    private static function defaultCarrier(array $arguments, array $before, int $idShop, int $idLang, array &$problems): ?int
    {
        $word = $arguments['default_carrier'] ?? null;
        $hasId = array_key_exists('default_id_carrier', $arguments) && $arguments['default_id_carrier'] !== null;
        $choices = array_merge(array_values(SomcpToolGetShippingPreferences::DEFAULT_CHOICES), ['carrier']);
        if ($word !== null && (!is_string($word) || !in_array($word, $choices, true))) {
            $problems[] = 'default_carrier: best_price, best_grade or carrier';
            $word = false;
        }
        if ($hasId && is_string($word) && $word !== 'carrier') {
            $problems[] = 'default_id_carrier: only with default_carrier carrier';

            return null;
        }
        if (!$hasId) {
            if (!is_string($word)) {
                return null;
            }
            if ($word !== 'carrier') {
                return (int) array_search($word, SomcpToolGetShippingPreferences::DEFAULT_CHOICES, true);
            }
            // The read given back of a default carrier deleted since names no
            // carrier: kept as it is rather than refused.
            if ($before['default_carrier'] !== 'carrier') {
                $problems[] = 'default_id_carrier: required with default_carrier carrier';
            }

            return null;
        }
        if ($word === false) {
            return null;
        }

        $idCarrier = SomcpTool::id($arguments, 'default_id_carrier');
        if ($idCarrier === 0) {
            $problems[] = SomcpTool::notAnIdentifier('default_id_carrier');

            return null;
        }
        // The carriers of the Default carrier list: every carrier of the shop
        // not deleted, enabled or not (CarrierByReferenceChoiceProvider asks
        // Carrier::getCarriers() for all of them). Read with the query of the
        // carrier list, since the core's provider lives in the back office's
        // container, which the MCP endpoint does not have.
        $query = SomcpToolListCarriers::query($idShop, $idLang);
        $query->where('c.`id_carrier` = ' . $idCarrier);
        $row = Db::getInstance()->getRow($query);
        if (is_array($row) && $row !== []) {
            return (int) $row['id_reference'];
        }
        $older = (int) Db::getInstance()->getValue(
            'SELECT c.`id_carrier` FROM `' . _DB_PREFIX_ . 'carrier` c'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'carrier_shop` cs'
            . ' ON cs.`id_carrier` = c.`id_carrier` AND cs.`id_shop` = ' . $idShop
            . ' WHERE c.`id_carrier` = ' . $idCarrier
        );
        $problems[] = $older > 0
            ? 'default_id_carrier: the carrier ' . $idCarrier . ' is not offered by the Default carrier list, which '
                . 'leaves out deleted carriers and older versions - find the current one with somcp_list_carriers'
            : SomcpTool::notFound('default_id_carrier', 'carrier', $idCarrier, 'somcp_list_carriers');

        return null;
    }

    /**
     * The keys to save, in the order of the two cards, each with the fields
     * it changes under the names of the parameters and the value stored.
     *
     * @param array<string, mixed> $given
     * @param array<string, mixed> $before
     * @param int|null $default the Default carrier asked for, as stored
     * @param int $idShopGroup
     * @param int $idShop
     * @param int $idLang
     *
     * @return array<int, array{key: string, stored: float|int, fields: array<int, string>, changes: array<int, array<string, mixed>>}>
     */
    private static function plan(array $given, array $before, ?int $default, int $idShopGroup, int $idShop, int $idLang): array
    {
        $plan = [];
        foreach (self::AMOUNTS as $field => $key) {
            // A float read back from JSON may come as 2 or 2.0: compared as a number.
            if (isset($given[$field]) && abs($given[$field] - (float) $before[$field]) >= 0.0000005) {
                $plan[] = [
                    'key' => $key,
                    'stored' => $given[$field],
                    'fields' => [$field],
                    'changes' => [['field' => $field, 'old' => $before[$field], 'new' => $given[$field]]],
                ];
            }
        }

        $current = (int) Configuration::get('PS_CARRIER_DEFAULT', null, $idShopGroup, $idShop);
        if ($default !== null && $default !== $current) {
            $word = SomcpToolGetShippingPreferences::DEFAULT_CHOICES[$default] ?? 'carrier';
            $changes = [];
            $fields = [];
            if ($word !== $before['default_carrier']) {
                $changes[] = ['field' => 'default_carrier', 'old' => $before['default_carrier'], 'new' => $word];
                $fields[] = 'default_carrier';
            }
            $carrier = $word === 'carrier'
                ? SomcpToolGetShippingPreferences::carrierOfReference($default, $idShop, $idLang)
                : null;
            $newId = $carrier !== null ? $carrier['id_carrier'] : null;
            if ($newId !== $before['default_id_carrier']) {
                $changes[] = ['field' => 'default_id_carrier', 'old' => $before['default_id_carrier'], 'new' => $newId];
                $fields[] = 'default_id_carrier';
            }
            $plan[] = ['key' => 'PS_CARRIER_DEFAULT', 'stored' => $default, 'fields' => $fields, 'changes' => $changes];
        }

        foreach (['carrier_sort' => ['PS_CARRIER_DEFAULT_SORT', SomcpToolGetShippingPreferences::SORTS],
            'carrier_order' => ['PS_CARRIER_DEFAULT_ORDER', SomcpToolGetShippingPreferences::ORDERS]] as $field => [$key, $choices]) {
            if (isset($given[$field]) && $given[$field] !== $before[$field]) {
                $plan[] = [
                    'key' => $key,
                    'stored' => (int) array_search($given[$field], $choices, true),
                    'fields' => [$field],
                    'changes' => [['field' => $field, 'old' => $before[$field], 'new' => $given[$field]]],
                ];
            }
        }

        return $plan;
    }

    /**
     * The answer to a save that stopped half way, naming what was saved so
     * the assistant reads the preferences before trying again.
     *
     * @param array<int, string> $saved
     * @param string $failure
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function failed(array $saved, string $failure, int $idEmployee): array
    {
        SomcpLogger::log('shipping_preferences_update_failed', SomcpLogger::OUTCOME_FAILURE, $failure, '', $idEmployee);

        return SomcpTool::text(
            'The shipping preferences could not be changed: ' . $failure
                . ($saved !== [] ? ', after ' . implode(', ', $saved) . ' had been saved' : '')
                . '. Read them with somcp_get_shipping_preferences before calling again.',
            true
        );
    }
}
