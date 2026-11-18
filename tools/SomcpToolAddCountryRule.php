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
 * somcp_add_country_rule - one row of a tax rule of International > Taxes >
 * Tax Rules, as the New tax rule panel of its edit page then Save and stay
 * add it: a country, its states, a zip code range, a behavior and a tax.
 *
 * Without a row a rule charges nothing: somcp_create_tax_rule makes the rule,
 * this tool makes it charge. The country and the states are read as the
 * address tools read them, the behaviors are the words somcp_get_tax_rule
 * answers, and the answer is that tool's, so a row read there can be given
 * here and the two agree on every name.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in:
// the rule check, the answer, the copy of a used rule and the country and
// state lookups live in these tools.
require_once __DIR__ . '/SomcpToolGetTaxRule.php';
require_once __DIR__ . '/SomcpToolUpdateTaxRule.php';
require_once __DIR__ . '/SomcpToolCreateSupplier.php';

class SomcpToolAddCountryRule
{
    /** @var array<int, string> Every argument this tool takes. */
    private const ARGUMENTS = [
        'id_tax_rules_group', 'country', 'states', 'zipcode_from', 'zipcode_to', 'behavior', 'id_tax', 'description',
    ];

    /** @var array<int, string> the fields of a country_rules entry the shop works out */
    private const COMPUTED = ['id_tax_rule', 'country_name', 'state', 'state_name', 'tax_name', 'rate'];

    /** @var int the size of the zip code columns of a fresh install, when the database cannot say */
    private const ZIPCODE_MAX = 12;

    /** @var int the size of the description column of a fresh install, when the database cannot say */
    private const DESCRIPTION_MAX = 100;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_add_country_rule',
            Somcp::t('Add a country to a tax rule'),
            'Adds a row to one tax rule of International > Taxes > Tax Rules, as the New tax rule panel of '
                . 'its edit page: Country, State, Zip/Postal code range, Behavior, Tax, Description. A rule '
                . 'charges nothing without one. Read the rule with somcp_get_tax_rule first; the same call twice '
                . 'adds the row twice. id_tax comes from somcp_list_taxes. A rule used by orders already placed '
                . 'gets a new id_tax_rules_group, given in the answer. To create a rule use '
                . 'somcp_create_tax_rule.',
            [
                'type' => 'object',
                'properties' => [
                    'id_tax_rules_group' => [
                        'type' => 'integer',
                        'description' => 'The rule to add to, from somcp_list_tax_rules.',
                    ],
                    'country' => [
                        'type' => 'string',
                        'description' => 'Merchant. ISO code of the Country, for example US.',
                    ],
                    'states' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Merchant. ISO codes of the country\'s states, for example ["AL", "FL"]: '
                            . 'one row each. Left out or empty: the whole country (All). Only for a country '
                            . 'with states.',
                    ],
                    'zipcode_from' => [
                        'type' => 'string',
                        'description' => 'Merchant. First bound of the Zip/Postal code range, in the format of '
                            . 'the country. Left out: every zip code.',
                    ],
                    'zipcode_to' => [
                        'type' => 'string',
                        'description' => 'Merchant. Second bound of the range, only with zipcode_from; the two '
                            . 'are put in order.',
                    ],
                    'behavior' => [
                        'type' => 'string',
                        'enum' => array_values(SomcpToolGetTaxRule::BEHAVIORS),
                        'description' => 'Merchant. Behavior: this_tax_only (the default), combine or '
                            . 'one_after_another, as somcp_get_tax_rule gives it.',
                    ],
                    'id_tax' => [
                        'type' => 'integer',
                        'description' => 'Merchant. The Tax, from somcp_list_taxes; 0 is No Tax.',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Merchant. Description of the row, 100 characters at most.',
                    ],
                ],
                'required' => ['id_tax_rules_group', 'country', 'id_tax'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // A row is added through the create_rule action of the tax rules
            // page, which asks for its add right in Team, Permissions.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminTaxRulesGroup')
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
        $errors = SomcpTool::unknownFields($arguments, self::ARGUMENTS, self::COMPUTED);

        // The same test of the shop as somcp_get_tax_rule, so a rule this tool
        // refuses is never one that tool describes.
        $idGroup = SomcpTool::id($arguments, 'id_tax_rules_group');
        $stored = null;
        if ($idGroup === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_tax_rules_group');
        } else {
            $stored = SomcpToolGetTaxRule::existingRule($idGroup, $idShop);
            if ($stored === null) {
                $errors[] = SomcpTool::notFound('id_tax_rules_group', 'tax rule', $idGroup, 'somcp_list_tax_rules');
            }
        }

        $country = SomcpToolCreateSupplier::supplierCountry($arguments, $idShop, $errors);
        $states = $country === null ? [] : self::states($arguments, $country, $errors);
        [$zipFrom, $zipTo] = self::zipcodes($arguments, $country, $errors);
        $behavior = self::behavior($arguments, $errors);
        $idTax = self::tax($arguments, $errors);
        $description = self::description($arguments, $errors);

        if ($errors === [] && $stored !== null && $country !== null && $behavior !== null) {
            self::unique($idGroup, $country, $states, $behavior, $errors);
        }
        if ($errors !== [] || $stored === null || $country === null || $behavior === null || $idTax === null) {
            return SomcpTool::refused('created', $errors);
        }

        $rule = new TaxRulesGroup($idGroup);
        $replacedId = 0;
        if ($rule->isUsed()) {
            // The orders already placed keep the rule they were priced with:
            // the row goes on a copy, as PrestaShop 9 does when its edit page
            // saves a row, through the same copy as somcp_update_tax_rule.
            $copied = SomcpToolUpdateTaxRule::replaceUsed($rule);
            if (is_string($copied)) {
                return self::failed($idGroup, $copied, $idEmployee);
            }
            $replacedId = $idGroup;
            $idGroup = $copied;
            $rule = new TaxRulesGroup($idGroup);
        }

        $added = [];
        $failure = '';
        foreach ($states as $idState => $iso) {
            $row = new TaxRule();
            $row->id_tax_rules_group = $idGroup;
            $row->id_country = (int) $country->id;
            $row->id_state = (int) $idState;
            // The core's own folding, so the bounds are stored as the form
            // stores them: ordered, and a single code as from with 0 as to.
            [$row->zipcode_from, $row->zipcode_to] = $row->breakDownZipCode(
                $zipFrom === '' ? '0' : ($zipTo === '' ? $zipFrom : $zipFrom . '-' . $zipTo)
            );
            $row->zipcode_from = (string) $row->zipcode_from;
            $row->zipcode_to = (string) $row->zipcode_to;
            $row->id_tax = $idTax;
            $row->behavior = $behavior;
            $row->description = $description;
            try {
                $done = $row->add();
                $failure = $done ? '' : 'PrestaShop refused to save the row' . ($iso !== '' ? ' of ' . $iso : '');
            } catch (Throwable $e) {
                $done = false;
                $failure = $e->getMessage();
            }
            if (!$done) {
                break;
            }
            $added[] = (int) $row->id;
        }
        if ($failure !== '') {
            // All the rows of the call or none: a rule charging some of the
            // states asked would price orders the merchant never agreed to.
            foreach ($added as $idRow) {
                $row = new TaxRule($idRow);
                $row->delete();
            }
            $where = $replacedId > 0 ? ' (on the copy ' . $idGroup . ' of rule ' . $replacedId . ', which orders keep)' : '';

            return self::failed($idGroup, $failure . $where, $idEmployee);
        }

        // Saved as the back office does after a row, so the rule's date_upd
        // says when it last changed; the shop of the token only, or update()
        // could attach the rule to a shop it was never in.
        $rule->id_shop_list = [$idShop];
        try {
            $rule->update();
        } catch (Throwable $e) {
            // The rows are written and charge already: a stale date is not
            // worth refusing the call over.
        }

        SomcpLogger::log(
            'tax_rule_country_added',
            SomcpLogger::OUTCOME_SUCCESS,
            'tax rule ' . $idGroup . ', ' . count($added) . ' row(s) for ' . $country->iso_code
                . ($replacedId > 0 ? ', replacing rule ' . $replacedId . ' kept for past orders' : ''),
            '',
            $idEmployee
        );

        $result = [
            'created' => true,
            'id_tax_rules_group' => $idGroup,
            'id_tax_rule' => $added,
        ];
        $warnings = [];
        if ($replacedId > 0) {
            $result['replaced_id_tax_rules_group'] = $replacedId;
            $warnings[] = 'This rule is used by orders already placed: PrestaShop keeps it unchanged for them and '
                . 'gives the rule a new identifier, ' . $idGroup . '. Products and carriers now use the new one.';
        }
        // A row has no shop: it charges in every shop the rule is in, said so
        // the merchant is not surprised there.
        $others = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rules_group_shop`'
            . ' WHERE `id_tax_rules_group` = ' . (int) $idGroup . ' AND `id_shop` != ' . (int) $idShop
        );
        if ($others > 0) {
            $result['also_in_other_shops'] = $others;
            $warnings[] = 'This rule is also in ' . $others . ' other shop(s): the row applies there too, '
                . 'as it does from the back office.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $language = SomcpTool::readLanguage([], $idShop);
        if ($language !== null) {
            $result['tax_rule'] = SomcpToolGetTaxRule::rule($idGroup, $language[0], $language[1], $idShop);
        }

        return SomcpTool::json($result);
    }

    /**
     * The states asked, by id_state, with their ISO code; [0 => ''] for the
     * whole country.
     *
     * @param array<string, mixed> $arguments
     * @param Country $country
     * @param array<int, string> $errors
     *
     * @return array<int, string>
     */
    private static function states(array $arguments, Country $country, array &$errors): array
    {
        $given = $arguments['states'] ?? null;
        if ($given === null || $given === []) {
            return [0 => ''];
        }
        if (!is_array($given) || !array_is_list($given)) {
            $errors[] = 'states: a list of ISO codes of states, for example ["AL"]';

            return [0 => ''];
        }
        if (!(bool) $country->contains_states) {
            // Stored, the row would never match an address, which has no state.
            $errors[] = 'states: ' . $country->iso_code . ' has no states, leave the field out';

            return [0 => ''];
        }
        $states = [];
        foreach ($given as $code) {
            $idState = SomcpToolCreateSupplier::stateOf($country, $code, 'states', $errors);
            if ($idState > 0) {
                // A code given twice adds one row, not two the same.
                $states[$idState] = Tools::strtoupper(trim((string) $code));
            }
        }

        return $states === [] ? [0 => ''] : $states;
    }

    /**
     * The two bounds of the range, '' when not given.
     *
     * @param array<string, mixed> $arguments
     * @param Country|null $country
     * @param array<int, string> $errors
     *
     * @return array{0: string, 1: string}
     */
    private static function zipcodes(array $arguments, ?Country $country, array &$errors): array
    {
        $bounds = [];
        $max = self::columnSize('zipcode_from', self::ZIPCODE_MAX);
        foreach (['zipcode_from', 'zipcode_to'] as $field) {
            $value = $arguments[$field] ?? null;
            if ($value === null || $value === '') {
                $bounds[] = '';
                continue;
            }
            $value = is_string($value) || is_int($value) ? trim((string) $value) : null;
            // A hyphen inside a bound would be read by breakDownZipCode() as
            // the separator of a range nobody asked for.
            if ($value === null || $value === '' || strpos($value, '-') !== false || !Validate::isPostCode($value)
                || Tools::strlen($value) > $max) {
                $errors[] = $field . ': ' . $max . ' characters at most, letters, digits and spaces only';
                $bounds[] = '';
                continue;
            }
            // The check of the form: the country's format, when it asks for one.
            if ($country !== null && $country->need_zip_code && $country->zip_code_format
                && !$country->checkZipCode($value)) {
                $errors[] = $field . ': not in the format of ' . $country->iso_code . ' ('
                    . str_replace(['N', 'L', 'C'], ['0', 'A', $country->iso_code], (string) $country->zip_code_format) . ')';
            }
            $bounds[] = $value;
        }
        if ($bounds[0] === '' && $bounds[1] !== '') {
            $errors[] = 'zipcode_to: only with zipcode_from';
        }

        return [$bounds[0], $bounds[1]];
    }

    /**
     * The behavior asked, as stored in tax_rule.behavior, or null with the reason.
     *
     * @param array<string, mixed> $arguments
     * @param array<int, string> $errors
     *
     * @return int|null
     */
    private static function behavior(array $arguments, array &$errors): ?int
    {
        // The first option of the form when left out.
        if (!array_key_exists('behavior', $arguments) || $arguments['behavior'] === null) {
            return 0;
        }
        $key = is_string($arguments['behavior'])
            ? array_search(trim($arguments['behavior']), SomcpToolGetTaxRule::BEHAVIORS, true)
            : false;
        if ($key === false) {
            $errors[] = SomcpTool::oneOf('behavior', array_values(SomcpToolGetTaxRule::BEHAVIORS));

            return null;
        }

        return (int) $key;
    }

    /**
     * The tax asked, 0 for No Tax, or null with the reason.
     *
     * Required although the form defaults to No Tax: a row added without a
     * tax by omission would charge nothing where the merchant meant a rate.
     *
     * @param array<string, mixed> $arguments
     * @param array<int, string> $errors
     *
     * @return int|null
     */
    private static function tax(array $arguments, array &$errors): ?int
    {
        if (!array_key_exists('id_tax', $arguments) || $arguments['id_tax'] === null) {
            $errors[] = 'id_tax: required, from somcp_list_taxes, 0 for No Tax';

            return null;
        }
        if ($arguments['id_tax'] === 0 || $arguments['id_tax'] === '0') {
            return 0;
        }
        $idTax = SomcpTool::id($arguments, 'id_tax');
        if ($idTax === 0) {
            $errors[] = 'id_tax: a whole number, 0 for No Tax';

            return null;
        }
        // A deleted tax stays in the table for the orders that used it, and
        // the form no longer offers it.
        $exists = (int) Db::getInstance()->getValue(
            'SELECT `id_tax` FROM `' . _DB_PREFIX_ . 'tax` WHERE `id_tax` = ' . (int) $idTax . ' AND `deleted` = 0'
        );
        if ($exists === 0) {
            $errors[] = SomcpTool::notFound('id_tax', 'tax', $idTax, 'somcp_list_taxes');

            return null;
        }

        return $idTax;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<int, string> $errors
     *
     * @return string
     */
    private static function description(array $arguments, array &$errors): string
    {
        $value = $arguments['description'] ?? null;
        if ($value === null || $value === '') {
            return '';
        }
        $max = self::columnSize('description', self::DESCRIPTION_MAX);
        if (!is_string($value) || !Validate::isString($value) || Tools::strlen($value) > $max) {
            $errors[] = 'description: text of ' . $max . ' characters at most';

            return '';
        }

        return $value;
    }

    /**
     * Refuses what the form refuses, and the reverse it lets through: a This
     * tax only row must be the only one for its country and state, or the
     * other rows never apply.
     *
     * @param int $idGroup
     * @param Country $country
     * @param array<int, string> $states
     * @param int $behavior
     * @param array<int, string> $errors
     */
    private static function unique(int $idGroup, Country $country, array $states, int $behavior, array &$errors): void
    {
        $group = new TaxRulesGroup($idGroup);
        foreach ($states as $idState => $iso) {
            $field = $iso === '' ? 'country' : 'states';
            $where = $country->iso_code . ($iso === '' ? '' : ' ' . $iso);
            // The core's own test, the one its edit page runs before each row.
            if ($group->hasUniqueTaxRuleForCountry((int) $country->id, (int) $idState)) {
                $errors[] = $field . ': ' . $where . ' already has a This tax only row in this rule - delete it first';
                continue;
            }
            if ($behavior === 0 && (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rule` WHERE `id_tax_rules_group` = ' . (int) $idGroup
                . ' AND `id_country` = ' . (int) $country->id . ' AND `id_state` = ' . (int) $idState
            ) > 0) {
                $errors[] = $field . ': ' . $where . ' already has a row in this rule, and a This tax only row must '
                    . 'be alone - choose combine or one_after_another, or delete that row first';
            }
        }
    }

    /**
     * The size of a column of tax_rule in this shop's database.
     *
     * TaxRule gives no size on 8.0: the column is what refuses or cuts a
     * longer value, so it is the limit to check against.
     *
     * @param string $column
     * @param int $default
     *
     * @return int
     */
    private static function columnSize(string $column, int $default): int
    {
        // executeS rather than getRow, which appends a LIMIT that SHOW COLUMNS
        // does not take.
        $columns = Db::getInstance()->executeS(
            'SHOW COLUMNS FROM `' . bqSQL(_DB_PREFIX_ . 'tax_rule') . "` LIKE '" . pSQL($column) . "'"
        );
        if (is_array($columns) && isset($columns[0]['Type'])
            && preg_match('/^varchar\((\d+)\)/i', (string) $columns[0]['Type'], $match)) {
            return (int) $match[1];
        }

        return $default;
    }

    /**
     * @param int $idGroup
     * @param string $failure
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function failed(int $idGroup, string $failure, int $idEmployee): array
    {
        SomcpLogger::log(
            'tax_rule_country_add_failed',
            SomcpLogger::OUTCOME_FAILURE,
            'tax rule ' . $idGroup . ': ' . Tools::substr($failure, 0, 500),
            '',
            $idEmployee
        );

        return SomcpTool::text(
            'The row could not be added: ' . $failure . '. Read the rule with somcp_get_tax_rule, tell the '
                . 'merchant what it holds now, and do not call again before.',
            true
        );
    }
}
