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
 * somcp_update_payment_restrictions - the Save buttons of the Currency
 * restrictions and Group restrictions cards of Payment > Preferences, for one
 * payment module.
 *
 * The core's PaymentRestrictionsConfigurator lives in the back office's
 * container, which the MCP endpoint does not have, and it keeps no object: the
 * rows of module_<type> are the whole setting. So the rows are written here,
 * exactly as its configureRestrictions() writes them, and nothing else.
 * Compared with, and answered with, the reading of
 * somcp_get_payment_preferences, so the read given back whole writes nothing.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// list of payment modules and the read before and after, and the currencies a
// shop offers.
require_once __DIR__ . '/SomcpToolGetPaymentPreferences.php';
require_once __DIR__ . '/SomcpToolListCurrencies.php';

class SomcpToolUpdatePaymentRestrictions
{
    /**
     * The tables of the four cards and the column of each, fixed here so no
     * table or column name is ever built from the request. A carrier is
     * stored by its reference, as the page stores it.
     */
    private const TABLES = [
        'currency' => ['module_currency', 'id_currency'],
        'group' => ['module_group', 'id_group'],
        'country' => ['module_country', 'id_country'],
        'carrier' => ['module_carrier', 'id_reference'],
    ];

    /**
     * The lists this tool writes, keyed to their table and to the key of
     * their items in the read.
     */
    private const LISTS = [
        'currencies' => ['currency', 'id_currency', 'currency'],
        'groups' => ['group', 'id_group', 'customer group'],
    ];

    /**
     * What somcp_get_payment_preferences answers beside the lists: given back
     * as read they are no change, given otherwise they are named as the
     * shop's, not as misspelt fields.
     */
    private const COMPUTED = ['display_name', 'active', 'currencies_mode', 'limited_countries', 'language', 'warning'];

    /**
     * The two cards of part 2 of #396, named so the assistant is not told
     * they are misspelt.
     */
    private const LATER = ['countries', 'carriers'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        // Items left untyped, as the group access of somcp_update_carrier: an
        // integer or the object of the read, which one schema type cannot say.
        $item = static function (string $key, string $tool): array {
            return [
                'type' => 'array',
                'description' => 'Full list after the call, replacing the stored one: each item a ' . $key
                    . ' from ' . $tool . ' or the object of the read. [] offers the module to none.',
            ];
        };
        $currencies = $item('id_currency', 'somcp_list_currencies');
        $currencies['description'] = 'Full list after the call, replacing the stored one: each item an id_currency '
            . 'from somcp_list_currencies or the object of the read. One item, -1 or -2 when the module takes one.';

        return new SomcpTool(
            'somcp_update_payment_restrictions',
            Somcp::t('Change the payment restrictions'),
            'Changes Payment > Preferences for one payment module, as the Save of its Currency restrictions and Group '
                . 'restrictions cards: each list given replaces the stored one, a list left out is kept. One module '
                . 'of the answer of somcp_get_payment_preferences can be given back as it is. To install, enable or '
                . 'configure a payment module use somcp_install_module or somcp_set_module_active.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Technical name of the payment module, as somcp_get_payment_preferences '
                            . 'gives it, for example ps_checkpayment.',
                    ],
                    'currencies' => $currencies,
                    'groups' => $item('id_group', 'somcp_list_customer_groups'),
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for the names in the '
                            . 'answer. The shop default language when left out.',
                    ],
                ],
                'required' => ['name'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminPaymentPreferences')
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
        $idEmployee = (int) ($context['id_employee'] ?? 0);
        $language = SomcpTool::readLanguage($arguments, $idShop);
        $default = $language ?? SomcpTool::readLanguage([], $idShop);
        $idLang = $default !== null ? $default[0] : 0;

        $problems = [];
        $module = null;
        $given = is_string($arguments['name'] ?? null) ? trim($arguments['name']) : '';
        if ($given === '') {
            $problems[] = 'name: required';
        } else {
            foreach (SomcpToolGetPaymentPreferences::modules($idShop) as $candidate) {
                if (Tools::strtolower($candidate['name']) === Tools::strtolower($given)) {
                    $module = $candidate;
                    break;
                }
            }
            if ($module === null) {
                $problems[] = SomcpTool::notFound('name', 'payment module', $given, 'somcp_get_payment_preferences', 'technical name');
            }
        }
        $before = null;
        if ($module !== null) {
            $read = SomcpToolGetPaymentPreferences::preferences($idShop, $idLang, $module['name']);
            $before = $read[0] ?? null;
        }

        // What the read worked out, given back as it reads, is no change and
        // is taken out, so a module given back whole is not refused for it.
        $checked = $arguments;
        foreach (self::COMPUTED as $key) {
            if (!array_key_exists($key, $checked)) {
                continue;
            }
            if ($key === 'language' ? $language !== null
                : ($key === 'warning' || $before === null || json_encode($checked[$key]) === json_encode($before[$key]))) {
                unset($checked[$key]);
            }
        }
        foreach (self::LATER as $key) {
            if (!array_key_exists($key, $checked)) {
                continue;
            }
            // The read of the module gives them back: as read they are no change.
            if ($before === null || self::sameIds($checked[$key], $before[$key], $key === 'countries' ? 'id_country' : 'id_carrier')) {
                unset($checked[$key]);
            }
        }

        // Every problem in one answer (#169), so the call is corrected in one go.
        $problems = array_merge($problems, SomcpTool::unknownFields(
            array_diff_key($checked, array_flip(self::LATER)),
            array_merge(['name'], array_keys(self::LISTS)),
            self::COMPUTED
        ));
        foreach (self::LATER as $key) {
            if (array_key_exists($key, $checked)) {
                $problems[] = $key . ': not available yet';
            }
        }
        if (array_key_exists('language', $checked)) {
            $problems[] = SomcpTool::languageProblem($idShop);
        }

        $wanted = [];
        foreach (self::LISTS as $list => [$type, $key, $thing]) {
            if (!array_key_exists($list, $arguments) || $arguments[$list] === null) {
                continue;
            }
            $ids = self::ids($list, $arguments[$list], $key, $problems);
            if ($ids === null || $module === null) {
                continue;
            }
            if ($list === 'currencies') {
                $ids = self::checkCurrencies($ids, $module, $idShop, $idLang, $problems);
            } else {
                $groups = array_map('intval', array_column(Group::getGroups($idLang, $idShop), 'id_group'));
                foreach ($ids as $id) {
                    if (!in_array($id, $groups, true)) {
                        $problems[] = SomcpTool::notFound('groups', $thing, $id, 'somcp_list_customer_groups');
                    }
                }
            }
            if ($ids !== null) {
                $wanted[$list] = $ids;
            }
        }

        if ($problems !== [] || $module === null || $before === null) {
            return SomcpTool::refused('changed', $problems);
        }

        $plan = [];
        foreach ($wanted as $list => $ids) {
            $stored = array_map('intval', array_column($before[$list], self::LISTS[$list][1]));
            $added = array_values(array_diff($ids, $stored));
            $removed = array_values(array_diff($stored, $ids));
            if ($added !== [] || $removed !== []) {
                $plan[$list] = ['ids' => $ids, 'change' => ['field' => $list, 'added' => $added, 'removed' => $removed]];
            }
        }
        if ($plan === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The payment module already has every list given. Nothing was written.',
                'preferences' => $before,
            ]);
        }

        $saved = [];
        foreach ($plan as $list => $step) {
            if (!self::replace(self::LISTS[$list][0], $module['id_module'], $idShop, $step['ids'])) {
                return self::failed($module['name'], $saved, $list . ' could not be saved', $idEmployee);
            }
            $saved[] = $list . ' (' . count($step['ids']) . ')';
        }
        SomcpLogger::log(
            'payment_restrictions_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            $module['name'] . ': ' . implode(', ', $saved),
            '',
            $idEmployee
        );

        $result = ['changed' => true, 'changes' => array_column($plan, 'change')];
        $warnings = [];
        $empty = [];
        foreach ($plan as $list => $step) {
            if ($step['ids'] === []) {
                $empty[] = self::LISTS[$list][2];
            }
        }
        if ($empty !== []) {
            // The checkout offers a module only to a cart that matches a row
            // of each table: one empty list hides it everywhere.
            $warnings[] = $module['display_name'] . ' is now offered to no ' . implode(', ', $empty)
                . ': customers cannot pay with it.';
        }
        if (!$module['active']) {
            $warnings[] = $module['display_name'] . ' is disabled: these restrictions apply once it is enabled with '
                . 'somcp_set_module_active.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        // Read back rather than echoed: what the assistant shows is what the
        // shop stored.
        $after = SomcpToolGetPaymentPreferences::preferences($idShop, $idLang, $module['name']);
        $result['preferences'] = $after[0] ?? null;

        return SomcpTool::json($result);
    }

    /**
     * Replaces the rows of one card for one module in one shop, as the core's
     * configureRestrictions() does: every row of the module in the shop
     * deleted, then one row per identifier.
     *
     * Public so the countries and carriers of part 2 are written by the same
     * code.
     *
     * @param string $type currency, group, country or carrier
     * @param int $idModule
     * @param int $idShop
     * @param array<int, int> $ids for a carrier, its id_reference
     *
     * @return bool
     */
    public static function replace(string $type, int $idModule, int $idShop, array $ids): bool
    {
        if (!isset(self::TABLES[$type])) {
            return false;
        }
        [$table, $column] = self::TABLES[$type];
        $db = Db::getInstance();
        if (!$db->delete($table, '`id_module` = ' . (int) $idModule . ' AND `id_shop` = ' . (int) $idShop)) {
            return false;
        }
        if ($ids === []) {
            return true;
        }
        $rows = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            $rows[] = ['id_module' => (int) $idModule, 'id_shop' => (int) $idShop, $column => (int) $id];
        }

        return (bool) $db->insert($table, $rows);
    }

    /**
     * The identifiers of a list, each once, in the order given, or null when
     * an item is not one; each item an identifier or the object of the read.
     *
     * Negative numbers pass here: -1 and -2 are rows of Currency restrictions,
     * checked against the module's mode by checkCurrencies().
     *
     * @param string $list
     * @param mixed $value
     * @param string $key the key of an item of the read, for example id_group
     * @param array<int, string> $problems
     *
     * @return array<int, int>|null
     */
    private static function ids(string $list, $value, string $key, array &$problems): ?array
    {
        if (!is_array($value) || ($value !== [] && array_keys($value) !== range(0, count($value) - 1))) {
            $problems[] = $list . ': a list of ' . $key . ', [] for none';

            return null;
        }
        $ids = [];
        foreach ($value as $item) {
            $id = self::identifier(is_array($item) ? ($item[$key] ?? null) : $item);
            if ($id === null || ($id === 0 || ($id < 0 && $list !== 'currencies'))) {
                $problems[] = SomcpTool::notAnIdentifier($list);

                return null;
            }
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * A whole number given as a number or a string of digits, or null.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    private static function identifier($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,10}$/', trim($value))) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * The currencies of a list checked against the module's mode and the
     * currencies of the shop, as the card offers them.
     *
     * @param array<int, int> $ids
     * @param array<string, mixed> $module a row of SomcpToolGetPaymentPreferences::modules()
     * @param int $idShop
     * @param int $idLang
     * @param array<int, string> $problems
     *
     * @return array<int, int>|null
     */
    private static function checkCurrencies(array $ids, array $module, int $idShop, int $idLang, array &$problems): ?array
    {
        $choices = SomcpToolGetPaymentPreferences::CURRENCY_CHOICES;
        $radio = $module['currencies_mode'] === 'radio';
        if ($radio && count($ids) !== 1) {
            $problems[] = 'currencies: ' . $module['display_name'] . ' takes one currency, or -1 (' . $choices[-1]
                . ') or -2 (' . $choices[-2] . ')';

            return null;
        }
        $valid = true;
        foreach ($ids as $id) {
            if (isset($choices[$id])) {
                if (!$radio) {
                    $problems[] = 'currencies: -1 and -2 only for a module that takes one currency';
                    $valid = false;
                }
                continue;
            }
            if ($id < 0) {
                $problems[] = SomcpTool::notAnIdentifier('currencies');
                $valid = false;
                continue;
            }
            // Read where somcp_list_currencies reads, so a deleted currency is
            // unknown here as it is there.
            if (SomcpToolListCurrencies::currency($idShop, $idLang, $id) === null) {
                $problems[] = SomcpTool::notFound('currencies', 'currency', $id, 'somcp_list_currencies');
                $valid = false;
            }
        }

        return $valid ? $ids : null;
    }

    /**
     * Whether a list given back holds the identifiers of the list read.
     *
     * @param mixed $given
     * @param array<int, array<string, mixed>> $read
     * @param string $key
     *
     * @return bool
     */
    private static function sameIds($given, array $read, string $key): bool
    {
        if (!is_array($given)) {
            return false;
        }
        $ids = [];
        foreach ($given as $item) {
            $id = self::identifier(is_array($item) ? ($item[$key] ?? null) : $item);
            if ($id === null) {
                return false;
            }
            $ids[] = $id;
        }
        $stored = array_map('intval', array_column($read, $key));
        sort($ids);
        sort($stored);

        return array_values(array_unique($ids)) === array_values(array_unique($stored));
    }

    /**
     * The answer to a save that stopped half way, naming what was saved so
     * the assistant reads the preferences before trying again.
     *
     * @param string $name
     * @param array<int, string> $saved
     * @param string $failure
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function failed(string $name, array $saved, string $failure, int $idEmployee): array
    {
        SomcpLogger::log('payment_restrictions_update_failed', SomcpLogger::OUTCOME_FAILURE, $name . ': ' . $failure, '', $idEmployee);

        return SomcpTool::text(
            'The payment restrictions of ' . $name . ' could not be changed: ' . $failure
                . ($saved !== [] ? ', after ' . implode(', ', $saved) . ' had been saved' : '')
                . '. Read them with somcp_get_payment_preferences before calling again.',
            true
        );
    }
}
