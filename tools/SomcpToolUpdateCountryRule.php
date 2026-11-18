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
 * somcp_update_country_rule - one row of a tax rule of International > Taxes >
 * Tax Rules changed in place, as the row Edit of its edit page fills the New
 * tax rule panel and Save and stay writes it back.
 *
 * Every field is checked by the functions of somcp_add_country_rule, the row
 * is found as somcp_delete_country_rule finds it, and the names are those of a
 * country_rules entry of somcp_get_tax_rule, so a row read there can be given
 * back with only what changes.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in:
// the field checks, the row lookup, the copy of a used rule and the answer
// live in these tools.
require_once __DIR__ . '/SomcpToolGetTaxRule.php';
require_once __DIR__ . '/SomcpToolUpdateTaxRule.php';
require_once __DIR__ . '/SomcpToolCreateSupplier.php';
require_once __DIR__ . '/SomcpToolAddCountryRule.php';
require_once __DIR__ . '/SomcpToolDeleteCountryRule.php';

class SomcpToolUpdateCountryRule
{
    /** @var array<int, string> Every argument this tool takes. */
    private const ARGUMENTS = [
        'id_tax_rule', 'country', 'state', 'zipcode_from', 'zipcode_to', 'behavior', 'id_tax', 'description',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_country_rule',
            Somcp::t('Change a country of a tax rule'),
            'Changes one row of a tax rule of International > Taxes > Tax Rules in place, as the row Edit of '
                . 'its edit page: Country, State, Zip/Postal code range, Behavior, Tax, Description. '
                . 'id_tax_rule comes from somcp_get_tax_rule; only what is given changes. A rule used by '
                . 'orders already placed gets a new id_tax_rules_group, given in the answer. To add a state '
                . 'or a country use somcp_add_country_rule, to delete a row somcp_delete_country_rule.',
            [
                'type' => 'object',
                'properties' => [
                    'id_tax_rule' => [
                        'type' => 'integer',
                        'description' => 'The row to change, the id_tax_rule of a country_rules entry of '
                            . 'somcp_get_tax_rule.',
                    ],
                    'country' => [
                        'type' => 'string',
                        'description' => 'Merchant. ISO code of the Country, for example US. Left out: unchanged.',
                    ],
                    'state' => [
                        'type' => ['string', 'null'],
                        'description' => 'Merchant. ISO code of one of the country\'s states, as '
                            . 'somcp_get_tax_rule gives it; null for the whole country (All). Left out: unchanged.',
                    ],
                    'zipcode_from' => [
                        'type' => ['string', 'null'],
                        'description' => 'Merchant. First bound of the Zip/Postal code range, in the format of '
                            . 'the country; null removes the range. Left out: unchanged.',
                    ],
                    'zipcode_to' => [
                        'type' => ['string', 'null'],
                        'description' => 'Merchant. Second bound of the range; the two are put in order. Left '
                            . 'out: unchanged.',
                    ],
                    'behavior' => [
                        'type' => 'string',
                        'enum' => SomcpToolGetTaxRule::BEHAVIORS,
                        'description' => 'Merchant. Behavior: this_tax_only, combine or one_after_another, as '
                            . 'somcp_get_tax_rule gives it. Left out: unchanged.',
                    ],
                    'id_tax' => [
                        'type' => 'integer',
                        'description' => 'Merchant. The Tax, from somcp_list_taxes; 0 is No Tax. Left out: unchanged.',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Merchant. Description of the row, 100 characters at most. Left out: '
                            . 'unchanged.',
                    ],
                ],
                'required' => ['id_tax_rule'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // The row Edit then Save and stay of the tax rules page asks for
            // its edit right in Team, Permissions.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminTaxRulesGroup')
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

        // Every problem is gathered before anything is written, so a refused
        // call leaves the rule as it was and the assistant fixes all at once.
        // id_tax_rules_group is not taken: the row names its rule, and a
        // second identifier could only contradict it.
        $errors = SomcpTool::unknownFields(
            $arguments,
            self::ARGUMENTS,
            array_values(array_diff(SomcpToolAddCountryRule::COMPUTED, self::ARGUMENTS))
        );

        $idRow = SomcpTool::id($arguments, 'id_tax_rule');
        $idGroup = 0;
        $stored = null;
        if ($idRow === 0) {
            // A cast would read a list as 1 and change a row nobody named.
            $errors[] = SomcpTool::notAnIdentifier('id_tax_rule');
        } else {
            $idGroup = SomcpToolDeleteCountryRule::ruleOf($idRow, $idShop, $errors);
            if ($idGroup > 0) {
                $stored = Db::getInstance()->getRow(
                    'SELECT `id_country`, `id_state`, `zipcode_from`, `zipcode_to`, `id_tax`, `behavior`, `description`'
                    . ' FROM `' . _DB_PREFIX_ . 'tax_rule` WHERE `id_tax_rule` = ' . (int) $idRow
                );
                $stored = is_array($stored) ? $stored : null;
            }
        }

        // The fields that do not need the row are checked even when it is not
        // found, so every mistake of the call comes back at once.
        $behavior = null;
        if (array_key_exists('behavior', $arguments) && $arguments['behavior'] !== null) {
            $behavior = SomcpToolAddCountryRule::behavior($arguments, $errors);
        }
        $idTax = null;
        if (array_key_exists('id_tax', $arguments) && $arguments['id_tax'] !== null) {
            $idTax = SomcpToolAddCountryRule::tax($arguments, $errors);
        }
        $description = null;
        if (array_key_exists('description', $arguments)) {
            $description = SomcpToolAddCountryRule::description($arguments, $errors);
        }

        $country = null;
        $plan = null;
        if ($stored !== null) {
            if (array_key_exists('country', $arguments)) {
                $country = SomcpToolCreateSupplier::supplierCountry($arguments, $idShop, $errors);
            } else {
                $country = new Country((int) $stored['id_country']);
                $country = Validate::isLoadedObject($country) ? $country : null;
            }
            if ($country !== null) {
                $plan = self::plan($arguments, $stored, $country, $errors);
            }
        }
        if ($errors !== [] || $stored === null || $country === null || $plan === null) {
            return SomcpTool::refused('changed', $errors);
        }

        $new = [
            'id_country' => (int) $country->id,
            'id_state' => $plan['id_state'],
            'zipcode_from' => $plan['zipcode_from'],
            'zipcode_to' => $plan['zipcode_to'],
            'behavior' => $behavior ?? (int) $stored['behavior'],
            'id_tax' => $idTax ?? (int) $stored['id_tax'],
            'description' => $description ?? (string) $stored['description'],
        ];

        $language = SomcpTool::readLanguage([], $idShop);
        if ($language === null) {
            return SomcpTool::refused('changed', $errors);
        }
        [$idLang, $iso] = $language;
        $before = SomcpToolGetTaxRule::countryRule($idGroup, $idRow, $idLang);

        $changes = self::changes($stored, $new, $country, $plan['state'], $before);
        if ($changes === []) {
            // Nothing saved, so no hook fires, no copy is made of a used rule
            // and its date_upd keeps its value.
            return SomcpTool::json([
                'changed' => false,
                'id_tax_rule' => $idRow,
                'reason' => 'The country row already holds every value given. Nothing was written.',
            ]);
        }

        // Only a row that moves or changes behavior can clash with another;
        // a new description of a row the shop already holds cannot.
        $moved = $new['id_country'] !== (int) $stored['id_country'] || $new['id_state'] !== (int) $stored['id_state']
            || $new['behavior'] !== (int) $stored['behavior'];
        if ($moved) {
            SomcpToolAddCountryRule::unique(
                $idGroup,
                $country,
                [$new['id_state'] => $plan['state'] ?? ''],
                $new['behavior'],
                $errors,
                $idRow,
                'state'
            );
            if ($errors !== []) {
                return SomcpTool::refused('changed', $errors);
            }
        }

        $rule = new TaxRulesGroup($idGroup);
        $replacedId = 0;
        if ($rule->isUsed()) {
            // The orders already placed keep the rule they were priced with,
            // row included: the change goes on the same row of a copy, as
            // PrestaShop 9 does from the edit page.
            $copied = SomcpToolUpdateTaxRule::replaceUsed($rule);
            if (is_string($copied)) {
                return self::failed($idRow, $copied, $idEmployee);
            }
            $replacedId = $idGroup;
            $idGroup = $copied;
            $idOld = $idRow;
            $idRow = SomcpToolDeleteCountryRule::sameRow($idOld, $idGroup);
            $rule = new TaxRulesGroup($idGroup);
            if ($idRow === 0) {
                return self::failed($idOld, 'the row was not found on the copy ' . $idGroup . ' of rule ' . $replacedId, $idEmployee);
            }
        }

        $row = new TaxRule($idRow);
        foreach ($new as $field => $value) {
            $row->{$field} = $value;
        }
        try {
            $done = (bool) $row->update();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            $where = $replacedId > 0 ? ' (on the copy ' . $idGroup . ' of rule ' . $replacedId . ', which orders keep)' : '';

            return self::failed($idRow, $failure . $where, $idEmployee);
        }

        // Saved as the back office does after a row, so the rule's date_upd
        // says when it last changed; the shop of the token only, or update()
        // could attach the rule to a shop it was never in.
        $rule->id_shop_list = [$idShop];
        try {
            $rule->update();
        } catch (Throwable $e) {
            // The row is written and charges already: a stale date is not
            // worth reporting the change as failed.
        }

        SomcpLogger::log(
            'tax_rule_country_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'tax rule ' . $idGroup . ', row ' . $idRow . ', ' . count($changes) . ' change(s)'
                . ($replacedId > 0 ? ', replacing rule ' . $replacedId . ' kept for past orders' : ''),
            '',
            $idEmployee
        );

        $result = [
            'changed' => true,
            'id_tax_rule' => $idRow,
            'id_tax_rules_group' => $idGroup,
        ];
        $warnings = [];
        if ($replacedId > 0) {
            $result['replaced_id_tax_rules_group'] = $replacedId;
            $warnings[] = 'This rule is used by orders already placed: PrestaShop keeps it unchanged for them and '
                . 'gives the rule a new identifier, ' . $idGroup . '. Products and carriers now use the new one.';
        }
        $result['changes'] = $changes;

        // A row has no shop: the change shows in every shop the rule is in,
        // said so the merchant is not surprised there.
        $others = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rules_group_shop`'
            . ' WHERE `id_tax_rules_group` = ' . (int) $idGroup . ' AND `id_shop` != ' . (int) $idShop
        );
        if ($others > 0) {
            $result['also_in_other_shops'] = $others;
            $warnings[] = 'This rule is also in ' . $others . ' other shop(s): the change shows there too, '
                . 'as it does from the back office.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $result['tax_rule'] = SomcpToolGetTaxRule::rule($idGroup, $idLang, $iso, $idShop);

        return SomcpTool::json($result);
    }

    /**
     * The state and the zip code range the row will have, each read from the
     * call when given and from the row otherwise, checked against the
     * country the row will have.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $stored
     * @param Country $country
     * @param array<int, string> $errors
     *
     * @return array{id_state: int, state: string|null, zipcode_from: string, zipcode_to: string}|null
     */
    private static function plan(array $arguments, array $stored, Country $country, array &$errors): ?array
    {
        $countryChanged = (int) $country->id !== (int) $stored['id_country'];

        $idState = (int) $stored['id_state'];
        if (array_key_exists('state', $arguments)) {
            $given = $arguments['state'];
            if ($given === null || $given === '') {
                // The All of the State column.
                $idState = 0;
            } elseif (!(bool) $country->contains_states) {
                // Stored, the row would never match an address, which has no state.
                $errors[] = 'state: ' . $country->iso_code . ' has no states, leave the field out';
                $idState = 0;
            } else {
                $idState = SomcpToolCreateSupplier::stateOf($country, $given, 'state', $errors);
            }
        } elseif ($countryChanged && $idState > 0) {
            // A state left from the old country would make a row no address
            // ever matches.
            $state = new State($idState);
            if ((int) $state->id_country !== (int) $country->id) {
                $errors[] = 'state: ' . (string) $state->iso_code . ' is not a state of ' . $country->iso_code
                    . ' - give state too, or null for All';
            }
        }
        $stateIso = null;
        if ($idState > 0) {
            $state = new State($idState);
            $stateIso = (string) $state->iso_code;
        }

        $from = self::bound($stored['zipcode_from']);
        $to = self::bound($stored['zipcode_to']);
        $zipGiven = array_key_exists('zipcode_from', $arguments) || array_key_exists('zipcode_to', $arguments);
        if ($zipGiven || $countryChanged) {
            $asked = [
                'zipcode_from' => array_key_exists('zipcode_from', $arguments) ? $arguments['zipcode_from'] : $from,
                // null for zipcode_from removes the whole range, its second
                // bound with it, unless the call gives that bound again.
                'zipcode_to' => array_key_exists('zipcode_to', $arguments)
                    ? $arguments['zipcode_to']
                    : (array_key_exists('zipcode_from', $arguments) && ($arguments['zipcode_from'] === null || $arguments['zipcode_from'] === '') ? '' : $to),
            ];
            [$from, $to] = SomcpToolAddCountryRule::zipcodes($asked, $country, $errors);
        }

        // The core's own folding, as somcp_add_country_rule stores the bounds:
        // ordered, and a single code as from with 0 as to.
        $fold = new TaxRule();
        [$foldedFrom, $foldedTo] = $fold->breakDownZipCode($from === '' ? '0' : ($to === '' ? $from : $from . '-' . $to));

        return $errors === [] ? [
            'id_state' => $idState,
            'state' => $stateIso,
            'zipcode_from' => (string) $foldedFrom,
            'zipcode_to' => (string) $foldedTo,
        ] : null;
    }

    /**
     * One entry per field that changes, in the names and values of a
     * country_rules entry of somcp_get_tax_rule.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $new
     * @param Country $country
     * @param string|null $stateIso
     * @param array<string, mixed>|null $before the row as somcp_get_tax_rule reads it
     *
     * @return array<int, array<string, mixed>>
     */
    private static function changes(array $stored, array $new, Country $country, ?string $stateIso, ?array $before): array
    {
        $behaviors = SomcpToolGetTaxRule::BEHAVIORS;
        $after = [
            'country' => (string) $country->iso_code,
            'state' => $stateIso,
            'zipcode_from' => self::bound($new['zipcode_from']),
            'zipcode_to' => self::bound($new['zipcode_to']),
            'behavior' => $behaviors[$new['behavior']] ?? 'this_tax_only',
            'id_tax' => $new['id_tax'],
            'description' => $new['description'],
        ];
        $old = [
            'country' => (string) ($before['country'] ?? ''),
            'state' => $before['state'] ?? null,
            'zipcode_from' => self::bound($stored['zipcode_from']),
            'zipcode_to' => self::bound($stored['zipcode_to']),
            'behavior' => $behaviors[(int) $stored['behavior']] ?? 'this_tax_only',
            'id_tax' => (int) $stored['id_tax'],
            'description' => (string) $stored['description'],
        ];
        $changes = [];
        foreach ($after as $field => $value) {
            // The country and the state are compared by identifier, so a code
            // read in another case is never taken for a change.
            $differs = $field === 'country'
                ? $new['id_country'] !== (int) $stored['id_country']
                : ($field === 'state' ? $new['id_state'] !== (int) $stored['id_state'] : $value !== $old[$field]);
            if ($differs) {
                $changes[] = [
                    'field' => $field,
                    'old' => $old[$field] === '' && in_array($field, ['zipcode_from', 'zipcode_to'], true) ? null : $old[$field],
                    'new' => $value === '' && in_array($field, ['zipcode_from', 'zipcode_to'], true) ? null : $value,
                ];
            }
        }

        return $changes;
    }

    /**
     * A stored zip code bound, '' when the row has none (stored as 0).
     *
     * @param mixed $value
     *
     * @return string
     */
    private static function bound($value): string
    {
        $value = trim((string) $value);

        return $value === '0' ? '' : $value;
    }

    /**
     * @param int $idRow
     * @param string $failure
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function failed(int $idRow, string $failure, int $idEmployee): array
    {
        SomcpLogger::log(
            'tax_rule_country_update_failed',
            SomcpLogger::OUTCOME_FAILURE,
            'row ' . $idRow . ': ' . Tools::substr($failure, 0, 500),
            '',
            $idEmployee
        );

        return SomcpTool::text(
            'The row could not be changed: ' . $failure . '. Read the rule with somcp_get_tax_rule, tell the '
                . 'merchant what it holds now, and do not call again before.',
            true
        );
    }
}
