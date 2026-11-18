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
 * somcp_delete_country_rule - one row of a tax rule of International > Taxes >
 * Tax Rules, as the row Delete of the list on its edit page removes it.
 *
 * The row is named by the id_tax_rule somcp_get_tax_rule gives, and the
 * answer is that tool's, so the row deleted and the rule left are read the
 * same way. A rule an order line carries is first copied, as the edit page
 * does on PrestaShop 9, by the copy somcp_update_tax_rule makes.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in:
// the rule check, the answer, the copy of a used rule and the count of its
// products and carriers live in these tools.
require_once __DIR__ . '/SomcpToolGetTaxRule.php';
require_once __DIR__ . '/SomcpToolUpdateTaxRule.php';
require_once __DIR__ . '/SomcpToolDeleteTaxRule.php';

class SomcpToolDeleteCountryRule
{
    /** @var array<int, string> Every argument this tool takes. */
    private const ARGUMENTS = ['id_tax_rule'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_country_rule',
            Somcp::t('Delete a country from a tax rule'),
            'Deletes one row of a tax rule of International > Taxes > Tax Rules (a country, state and zip '
                . 'range with its tax), as the row Delete of its edit page. Irreversible. id_tax_rule comes from '
                . 'somcp_get_tax_rule: read the rule and confirm the row with the merchant first; a rule left '
                . 'without rows charges no tax. One row per call. A rule used by orders already placed gets a '
                . 'new id_tax_rules_group, given in the answer. To delete the whole rule use '
                . 'somcp_delete_tax_rule.',
            [
                'type' => 'object',
                'properties' => [
                    'id_tax_rule' => [
                        'type' => 'integer',
                        'description' => 'The row to delete, the id_tax_rule of a country_rules entry of '
                            . 'somcp_get_tax_rule.',
                    ],
                ],
                'required' => ['id_tax_rule'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // The row Delete of the tax rules page asks for its delete right
            // in Team, Permissions, as the deletion of a whole rule does.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminTaxRulesGroup')
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

        // No id_tax_rules_group: the row names its rule, and a second
        // identifier could only contradict it.
        $errors = SomcpTool::unknownFields($arguments, self::ARGUMENTS);
        $idRow = SomcpTool::id($arguments, 'id_tax_rule');
        $idGroup = 0;
        if ($idRow === 0) {
            // A cast would read a list as 1 and delete a row nobody named.
            $errors[] = SomcpTool::notAnIdentifier('id_tax_rule');
        } else {
            $idGroup = self::ruleOf($idRow, $idShop, $errors);
        }
        $language = SomcpTool::readLanguage([], $idShop);
        if ($errors !== [] || $idGroup === 0 || $language === null) {
            return SomcpTool::refused('deleted', $errors);
        }
        [$idLang, $iso] = $language;

        // Read before, since it is gone after.
        $before = SomcpToolGetTaxRule::countryRule($idGroup, $idRow, $idLang);

        $rule = new TaxRulesGroup($idGroup);
        $replacedId = 0;
        if ($rule->isUsed()) {
            // The orders already placed keep the rule they were priced with,
            // row included: the row is deleted from a copy, as PrestaShop 9
            // does from the edit page.
            $copied = SomcpToolUpdateTaxRule::replaceUsed($rule);
            if (is_string($copied)) {
                return self::failed($idRow, $copied, $idEmployee);
            }
            $replacedId = $idGroup;
            $idGroup = $copied;
            $idRow = self::sameRow($idRow, $idGroup);
            $rule = new TaxRulesGroup($idGroup);
            if ($idRow === 0) {
                return self::failed($idRow, 'the row was not found on the copy ' . $idGroup . ' of rule ' . $replacedId, $idEmployee);
            }
        }

        $row = new TaxRule($idRow);
        try {
            $done = (bool) $row->delete();
        } catch (Throwable $e) {
            $done = false;
        }
        // Checked on the table rather than trusted from delete().
        $left = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rule` WHERE `id_tax_rule` = ' . (int) $idRow
        );
        if (!$done || $left > 0) {
            return self::failed($idRow, 'PrestaShop refused to delete it', $idEmployee);
        }

        // Saved as the back office does after a row, so the rule's date_upd
        // says when it last changed; the shop of the token only, or update()
        // could attach the rule to a shop it was never in.
        $rule->id_shop_list = [$idShop];
        try {
            $rule->update();
        } catch (Throwable $e) {
            // The row is gone already: a stale date is not worth reporting
            // the deletion as failed.
        }

        SomcpLogger::log(
            'tax_rule_country_deleted',
            SomcpLogger::OUTCOME_SUCCESS,
            'tax rule ' . $idGroup . ', row ' . $idRow
                . ($replacedId > 0 ? ', replacing rule ' . $replacedId . ' kept for past orders' : ''),
            '',
            $idEmployee
        );

        $result = [
            'deleted' => true,
            'id_tax_rule' => $idRow,
            'id_tax_rules_group' => $idGroup,
        ];
        $warnings = [];
        if ($replacedId > 0) {
            $result['replaced_id_tax_rules_group'] = $replacedId;
            $warnings[] = 'This rule is used by orders already placed: PrestaShop keeps it unchanged for them and '
                . 'gives the rule a new identifier, ' . $idGroup . '. Products and carriers now use the new one.';
        }
        $result['country_rule'] = $before;

        $users = SomcpToolDeleteTaxRule::users($idGroup, $idShop);
        $rowsLeft = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rule` WHERE `id_tax_rules_group` = ' . (int) $idGroup
        );
        if ($rowsLeft === 0 && ($users['products'] > 0 || $users['carriers'] > 0)) {
            // The rule stays, charging nothing: said, since what takes it is
            // now sold without tax.
            $warnings[] = 'That was the last row of the rule: the ' . $users['products'] . ' product(s) and '
                . $users['carriers'] . ' carrier(s) of this shop that take it are now sold without tax.';
        }
        if ($users['shops'] > 0) {
            $result['also_in_other_shops'] = $users['shops'];
            $warnings[] = 'This rule is also in ' . $users['shops'] . ' other shop(s): the row is gone there too, '
                . 'as it is from the back office.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        // Read back: what the assistant shows the merchant is what the shop holds.
        $result['tax_rule'] = SomcpToolGetTaxRule::rule($idGroup, $idLang, $iso, $idShop);

        return SomcpTool::json($result);
    }

    /**
     * The rule of a row, when that rule is one somcp_get_tax_rule answers for
     * this shop, so a row of a deleted rule or of another shop is not found.
     *
     * Public so every tool that names a row by its id_tax_rule finds it, or
     * refuses it, with the same test and the same line.
     *
     * @param int $idRow
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return int 0 when the row is not found, the reason added to $errors
     */
    public static function ruleOf(int $idRow, int $idShop, array &$errors): int
    {
        $idGroup = (int) Db::getInstance()->getValue(
            'SELECT `id_tax_rules_group` FROM `' . _DB_PREFIX_ . 'tax_rule` WHERE `id_tax_rule` = ' . (int) $idRow
        );
        if ($idGroup === 0 || SomcpToolGetTaxRule::existingRule($idGroup, $idShop) === null) {
            $errors[] = SomcpTool::notFound('id_tax_rule', 'country row of a tax rule', $idRow, 'somcp_get_tax_rule');

            return 0;
        }

        return $idGroup;
    }

    /**
     * The row of the copy that matches a row of the rule it replaced, as
     * TaxRulesGroup::getIdTaxRuleGroupFromHistorizedId() finds it in the core.
     *
     * Public so somcp_update_country_rule changes the same row on the copy.
     *
     * @param int $idOld
     * @param int $idCopy
     *
     * @return int 0 when the copy has no such row
     */
    public static function sameRow(int $idOld, int $idCopy): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT n.`id_tax_rule` FROM `' . _DB_PREFIX_ . 'tax_rule` o'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'tax_rule` n ON n.`id_tax_rules_group` = ' . (int) $idCopy
            . ' AND n.`id_country` = o.`id_country` AND n.`id_state` = o.`id_state`'
            . ' AND n.`zipcode_from` = o.`zipcode_from` AND n.`zipcode_to` = o.`zipcode_to`'
            . ' AND n.`id_tax` = o.`id_tax` AND n.`behavior` = o.`behavior` AND n.`description` = o.`description`'
            . ' WHERE o.`id_tax_rule` = ' . (int) $idOld
            . ' ORDER BY n.`id_tax_rule` ASC'
        );
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
            'tax_rule_country_delete_failed',
            SomcpLogger::OUTCOME_FAILURE,
            'row ' . $idRow . ': ' . Tools::substr($failure, 0, 500),
            '',
            $idEmployee
        );

        return SomcpTool::text(
            'The row could not be deleted: ' . $failure . '. Read the rule with somcp_get_tax_rule, tell the '
                . 'merchant what it holds now, and do not call again before.',
            true
        );
    }
}
