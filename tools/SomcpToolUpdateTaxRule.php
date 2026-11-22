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
 * somcp_update_tax_rule - the Name and Enable of one tax rule of
 * International > Taxes > Tax Rules, as its edit page then Save and stay do,
 * and as the Enabled column of the list switches it.
 *
 * The name is checked by somcp_create_tax_rule and the answer read back
 * through somcp_get_tax_rule, so the three tools agree on what a valid name
 * is and give the same fields under the same names.
 *
 * A rule that an order line already carries is kept unchanged for those orders
 * and the change goes on a copy under a new identifier, as PrestaShop 9 does
 * it. The copy is made by replaceUsed() rather than TaxRulesGroup::update(),
 * whose historize() breaks on 8.0 (see replaceUsed()).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in:
// the name check and the answer live in these two tools.
require_once __DIR__ . '/SomcpToolGetTaxRule.php';
require_once __DIR__ . '/SomcpToolCreateTaxRule.php';

class SomcpToolUpdateTaxRule
{
    /** @var array<int, string> Every argument this tool takes. */
    private const ARGUMENTS = ['id_tax_rules_group', 'name', 'active'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_tax_rule',
            Somcp::t('Update a tax rule'),
            'Changes the Name and Enable of one tax rule of International > Taxes > Tax Rules, as its edit '
                . 'page does; Enable also switches the rule on or off as the Enabled column of the list. '
                . 'id_tax_rules_group comes from somcp_list_tax_rules: never guess one. Only what is given '
                . 'changes. Show the merchant the change and call once they agree. A rule used by orders '
                . 'already placed keeps its old version for them: the change gets a new id_tax_rules_group, '
                . 'given in the answer, to use from then on. Not for its country rows. To delete a rule use '
                . 'somcp_delete_tax_rule.',
            [
                'type' => 'object',
                'properties' => [
                    'id_tax_rules_group' => [
                        'type' => 'integer',
                        'description' => 'The rule to change, from somcp_list_tax_rules.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Merchant. The Name of the rule, at most the size of the Name column '
                            . '(64 characters on PrestaShop 9, 50 on some 8.0 shops), without < > = { }. Left '
                            . 'out: unchanged.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Enable: true offers the rule on the product page. Left out: '
                            . 'unchanged.',
                    ],
                ],
                'required' => ['id_tax_rules_group'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Changing a rule is the edit right of the tax rules page in Team,
            // Permissions; creating one is a different right.
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
        // call leaves the shop as it was and the assistant fixes all at once.
        $errors = SomcpTool::unknownFields($arguments, self::ARGUMENTS, array_values(array_diff(SomcpToolCreateTaxRule::COMPUTED, ['id_tax_rules_group'])));

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

        $fields = [];
        if (array_key_exists('name', $arguments)) {
            $name = SomcpToolCreateTaxRule::name($arguments, $errors);
            if ($name !== null) {
                $fields['name'] = $name;
            }
        }
        if (array_key_exists('active', $arguments)) {
            // A boolean only: "false" cast to bool is true, and would offer
            // a rule the merchant asked to switch off.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } else {
                $fields['active'] = $arguments['active'];
            }
        }
        if ($errors !== [] || $stored === null) {
            return SomcpTool::refused('changed', $errors);
        }

        $changes = [];
        if (isset($fields['name']) && $fields['name'] !== (string) $stored['name']) {
            $changes[] = ['field' => 'name', 'old' => (string) $stored['name'], 'new' => $fields['name']];
        }
        if (isset($fields['active']) && $fields['active'] !== (bool) $stored['active']) {
            $changes[] = ['field' => 'active', 'old' => (bool) $stored['active'], 'new' => $fields['active']];
        }
        if ($changes === []) {
            // Nothing saved, so no hook fires and date_upd keeps its value.
            return SomcpTool::json([
                'changed' => false,
                'id_tax_rules_group' => $idGroup,
                'reason' => 'The tax rule already holds every value given. Nothing was written.',
            ]);
        }

        $rule = new TaxRulesGroup($idGroup);
        $replacedId = 0;
        $shopList = [$idShop];
        if ($rule->isUsed()) {
            // The orders already placed keep the rule they were priced with:
            // the change goes on a copy, which takes over products and carriers.
            $copied = self::replaceUsed($rule);
            if (is_string($copied)) {
                SomcpLogger::log(
                    'tax_rule_update_failed',
                    SomcpLogger::OUTCOME_FAILURE,
                    'tax rule ' . $idGroup . ': ' . Tools::substr($copied, 0, 500),
                    '',
                    $idEmployee
                );

                return SomcpTool::text(
                    'The tax rule could not be changed: ' . $copied . '. Read it with somcp_list_tax_rules, tell '
                        . 'the merchant what the shop holds now, and do not call again before.',
                    true
                );
            }
            $replacedId = $idGroup;
            $idGroup = $copied;
            $rule = new TaxRulesGroup($idGroup);
            // The copy is in every shop the old rule was in, and stays there.
            $shopList = self::shopsOf($idGroup);
            if ($shopList === []) {
                $shopList = [$idShop];
            }
        }

        if (isset($fields['name'])) {
            $rule->name = $fields['name'];
        }
        if (isset($fields['active'])) {
            $rule->active = $fields['active'];
        }
        // The shop of the token only: left to the context's list of shops,
        // update() can attach the rule to a shop it was never in.
        $rule->id_shop_list = $shopList;

        try {
            $done = $rule->update();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            // Step 6 of the replacement: the copy and the old rule both exist,
            // so both are named for the merchant to look in the back office.
            $where = $replacedId > 0
                ? ' (step 6, new values on the copy ' . $idGroup . ' of rule ' . $replacedId . ', which orders keep)'
                : '';
            SomcpLogger::log(
                'tax_rule_update_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'tax rule ' . $idGroup . $where . ': ' . Tools::substr($failure, 0, 500),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The tax rule could not be changed' . $where . ': ' . $failure . '. Read it again with '
                    . 'somcp_get_tax_rule, tell the merchant what it holds now, and do not call again before.',
                true
            );
        }

        SomcpLogger::log(
            'tax_rule_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'tax rule ' . $idGroup . ', ' . count($changes) . ' change(s)'
                . ($replacedId > 0 ? ', replacing rule ' . $replacedId . ' kept for past orders' : ''),
            '',
            $idEmployee
        );

        $result = [
            'changed' => true,
            'id_tax_rules_group' => $idGroup,
        ];
        $warnings = [];
        if ($replacedId > 0) {
            $result['replaced_id_tax_rules_group'] = $replacedId;
            $warnings[] = 'This rule is used by orders already placed: PrestaShop keeps it unchanged for them and '
                . 'gives the rule a new identifier, ' . $idGroup . '. Products and carriers now use the new one.';
        }
        $result['changes'] = $changes;

        // A rule has no per-shop name or status, so the change shows in every
        // shop the rule is in: said, so the merchant is not surprised there.
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
        $language = SomcpTool::readLanguage([], $idShop);
        if ($language !== null) {
            $result['tax_rule'] = SomcpToolGetTaxRule::rule($idGroup, $language[0], $language[1], $idShop);
        }

        return SomcpTool::json($result);
    }

    /**
     * Steps 1 to 5 of changing a rule that orders already placed carry: a copy
     * takes its country rows, products and carriers, and the old rule is kept,
     * deleted, for those orders. This is the sequence TaxRulesGroup::update()
     * runs through historize() on PrestaShop 9, done here so it is the same on
     * every version: on 8.0 historize() fails on a carrier column that does
     * not exist, leaving the carriers on the deleted rule and the change
     * unwritten. Shared with the tools that change the country rows of a rule.
     *
     * The copy is complete before anything leaves the old rule, so a failure
     * in steps 1 to 3 removes it and the shop is as it was.
     *
     * @return int|string the identifier of the copy, or the step that failed
     */
    public static function replaceUsed(TaxRulesGroup $rule)
    {
        $db = Db::getInstance();
        $idOld = (int) $rule->id;
        $shops = self::shopsOf($idOld);

        // 1. The rule row itself; duplicateObject() copies the main table only.
        try {
            $copy = $rule->duplicateObject();
        } catch (Throwable $e) {
            $copy = false;
        }
        if (!$copy instanceof TaxRulesGroup || (int) $copy->id === 0) {
            return 'step 1, the copy of rule ' . $idOld . ' could not be created';
        }
        $idNew = (int) $copy->id;

        try {
            // 2. Its shops, so the copy shows wherever the old rule did; ignore
            // a row a version of duplicateObject() may already have written.
            $done = true;
            foreach ($shops as $idShop) {
                $done = $done && $db->insert(
                    'tax_rules_group_shop',
                    ['id_tax_rules_group' => $idNew, 'id_shop' => (int) $idShop],
                    false,
                    true,
                    Db::INSERT_IGNORE
                );
            }
            if (!$done) {
                throw new RuntimeException('step 2, the shops of the copy ' . $idNew . ' could not be written');
            }

            // 3. Its rows per country, as historize() copies them.
            if (!$db->execute(
                'INSERT INTO `' . _DB_PREFIX_ . 'tax_rule`'
                . ' (`id_tax_rules_group`, `id_country`, `id_state`, `zipcode_from`, `zipcode_to`, `id_tax`, `behavior`, `description`)'
                . ' SELECT ' . $idNew . ', `id_country`, `id_state`, `zipcode_from`, `zipcode_to`, `id_tax`, `behavior`, `description`'
                . ' FROM `' . _DB_PREFIX_ . 'tax_rule` WHERE `id_tax_rules_group` = ' . $idOld
            )) {
                throw new RuntimeException('step 3, the country rows could not be copied to the copy ' . $idNew);
            }
        } catch (Throwable $e) {
            // Only the copy is removed: nothing has left the old rule yet.
            $db->delete('tax_rule', '`id_tax_rules_group` = ' . $idNew);
            $db->delete('tax_rules_group_shop', '`id_tax_rules_group` = ' . $idNew);
            $copy->delete();

            return $e instanceof RuntimeException ? $e->getMessage() : 'step 2 or 3, ' . $e->getMessage();
        }

        // 4. Products and carriers follow the rule, as historize() moves them;
        // never `carrier`, which has no such column.
        foreach (['product', 'product_shop', 'carrier_tax_rules_group_shop'] as $table) {
            $moved = false;
            try {
                $moved = $db->update($table, ['id_tax_rules_group' => $idNew], '`id_tax_rules_group` = ' . $idOld);
            } catch (Throwable $e) {
                $moved = false;
            }
            if (!$moved) {
                return 'step 4, ' . $table . ' could not be moved from rule ' . $idOld . ' to its copy ' . $idNew;
            }
        }

        // 5. The old rule deleted for the lists, kept for its orders: with
        // deleted set, update() takes its plain path instead of copying again.
        $old = new TaxRulesGroup($idOld);
        $old->id_shop_list = $shops;
        try {
            $deleted = (bool) $old->softDelete();
        } catch (Throwable $e) {
            $deleted = false;
        }
        if (!$deleted) {
            return 'step 5, rule ' . $idOld . ' could not be marked deleted after its copy ' . $idNew . ' took over';
        }

        return $idNew;
    }

    /**
     * @return array<int, int> the shops a rule is in
     */
    private static function shopsOf(int $idGroup): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_shop` FROM `' . _DB_PREFIX_ . 'tax_rules_group_shop` WHERE `id_tax_rules_group` = ' . (int) $idGroup
        );

        return array_map('intval', array_column(is_array($rows) ? $rows : [], 'id_shop'));
    }
}
