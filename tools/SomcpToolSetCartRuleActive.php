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
 * somcp_set_cart_rule_active - the Status switch of the back office cart rule
 * list.
 *
 * Kept apart from somcp_update_cart_rule on purpose: "stop this promotion"
 * has to touch Status and nothing else. The list's switch goes through
 * ObjectModel::toggleStatus(), which writes active alone; the form, by
 * contrast, saves every field and rewrites the restriction tables in
 * afterUpdate(). This tool follows the switch.
 *
 * Which rules this shop can use is decided by somcp_list_cart_rules' own
 * test, so the cart rule tools never disagree on what may be touched.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// test of the shop and the employee wrapper live in these classes.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolListCartRules.php';

class SomcpToolSetCartRuleActive
{
    /** @var array<int, string> */
    private const FIELDS = ['id_cart_rule', 'active'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_set_cart_rule_active',
            Somcp::t('Switch a cart rule on or off'),
            'Switches one cart rule on or off, like the Status switch of Catalog > Discounts > Cart Rules, and '
                . 'changes nothing else. Reversible: switching off stops a promotion early, orders that used it keep '
                . 'their discount. A rule with no code applies to every cart at once: tell the merchant before '
                . 'switching it on. id_cart_rule from somcp_list_cart_rules: never guess one. To change anything else '
                . 'use somcp_update_cart_rule. Not to be retried: if the answer is lost, read somcp_get_cart_rule first.',
            [
                'type' => 'object',
                'properties' => [
                    'id_cart_rule' => [
                        'type' => 'integer',
                        'description' => 'The rule to switch, the ID column of somcp_list_cart_rules.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Status: true to switch the rule on, false to switch it off.',
                    ],
                ],
                'required' => ['id_cart_rule', 'active'],
                'additionalProperties' => false,
            ],
            [SomcpScope::SALES_WRITE],
            [self::class, 'handle'],
            // The switch sits on the cart rule list and takes the edit right
            // there: switching a rule off is a change, not a deletion.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminCartRules')
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

        // Every mistake is reported in one answer, so the call is corrected in
        // one go (the shape of #169).
        $problems = [];
        foreach (array_keys($arguments) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                $problems[] = '- ' . $field . ': not a field of this tool';
            }
        }

        $idCartRule = 0;
        if (!isset($arguments['id_cart_rule']) || !is_int($arguments['id_cart_rule']) || $arguments['id_cart_rule'] < 1) {
            $problems[] = '- id_cart_rule: a whole number above 0, the ID given by somcp_list_cart_rules';
        } else {
            $idCartRule = $arguments['id_cart_rule'];
        }

        // A boolean only: a string such as "false" cast to bool is true, and
        // would switch on a rule the merchant asked to stop.
        if (!array_key_exists('active', $arguments) || !is_bool($arguments['active'])) {
            $problems[] = '- active: true or false';
        }

        if ($problems !== []) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n" . implode("\n", array_unique($problems)),
                true
            );
        }
        $active = (bool) $arguments['active'];

        $cartRule = self::existing($idCartRule, $idShop);
        if ($cartRule === null) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- id_cart_rule: no cart rule of this shop has the "
                    . 'identifier ' . $idCartRule . ' - find it with somcp_list_cart_rules',
                true
            );
        }

        if ((bool) $cartRule->active === $active) {
            return SomcpTool::json([
                'changed' => false,
                'id_cart_rule' => $idCartRule,
                'active' => $active,
                'reason' => $active
                    ? 'The cart rule is already on. Nothing was written.'
                    : 'The cart rule is already off. Nothing was written.',
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($cartRule, $active, $idEmployee): array {
            return self::apply($cartRule, $active, $idEmployee);
        });
    }

    /**
     * The rule, when the token's shop can use it.
     *
     * The test is the one of somcp_list_cart_rules, so a rule that tool does
     * not list is never switched here.
     *
     * @param int $idCartRule
     * @param int $idShop
     *
     * @return CartRule|null
     */
    private static function existing(int $idCartRule, int $idShop): ?CartRule
    {
        $found = (int) Db::getInstance()->getValue(
            'SELECT cr.`id_cart_rule` FROM `' . _DB_PREFIX_ . 'cart_rule` cr
             WHERE cr.`id_cart_rule` = ' . (int) $idCartRule . ' AND ' . SomcpToolListCartRules::inShop($idShop)
        );
        if ($found < 1) {
            return null;
        }
        $cartRule = new CartRule($found);

        return Validate::isLoadedObject($cartRule) ? $cartRule : null;
    }

    /**
     * Writes Status, and only that.
     *
     * @param CartRule $cartRule
     * @param bool $active
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(CartRule $cartRule, bool $active, int $idEmployee): array
    {
        $idCartRule = (int) $cartRule->id;
        $cartRule->active = $active;
        // The single field the switch stands for, as toggleStatus() writes
        // it, so the rule's other fields as loaded are not written back over
        // a change made since.
        $cartRule->setFieldsToUpdate(['active' => true]);

        try {
            $done = (bool) $cartRule->update();
        } catch (Throwable $e) {
            $done = false;
        }

        if (!$done) {
            SomcpLogger::log(
                'cart_rule_status_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'cart rule ' . $idCartRule . ' to ' . ($active ? 'on' : 'off'),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'Status could not be changed. Read the rule with somcp_get_cart_rule to see what it holds before '
                    . 'calling again.',
                true
            );
        }

        SomcpLogger::log(
            'cart_rule_status_changed',
            SomcpLogger::OUTCOME_SUCCESS,
            'cart rule ' . $idCartRule . ' to ' . ($active ? 'on' : 'off'),
            '',
            $idEmployee
        );

        $result = [
            'changed' => true,
            'id_cart_rule' => $idCartRule,
            'active' => $active,
        ];
        $warnings = $active ? self::warnings($cartRule) : [];
        if ($warnings !== []) {
            $result['warnings'] = $warnings;
        }

        return SomcpTool::json($result);
    }

    /**
     * What keeps a rule just switched on from applying as the merchant may expect.
     *
     * Status on does not make a rule usable: CartRule::checkValidity() still
     * refuses it outside its dates or once Total available is spent. And a
     * rule with no code is the Caution! of the form: it applies on its own.
     *
     * @param CartRule $cartRule
     *
     * @return array<int, string>
     */
    private static function warnings(CartRule $cartRule): array
    {
        $warnings = [];
        $now = date('Y-m-d H:i:s');
        if ((string) $cartRule->date_to !== '' && strtotime((string) $cartRule->date_to) !== false
            && (string) $cartRule->date_to < $now) {
            $warnings[] = 'The end of Valid (' . $cartRule->date_to . ') has passed: no cart can use the rule until it is moved.';
        }
        if ((string) $cartRule->date_from !== '' && strtotime((string) $cartRule->date_from) !== false
            && (string) $cartRule->date_from > $now) {
            $warnings[] = 'The start of Valid (' . $cartRule->date_from . ') is still to come: the rule applies from then on.';
        }
        if ((int) $cartRule->quantity <= 0) {
            $warnings[] = 'Total available is 0: no cart can use the rule until it is raised.';
        }
        if (trim((string) $cartRule->code) === '') {
            $warnings[] = 'The rule has no code: it applies on its own to every cart that meets its conditions.';
        }

        return $warnings;
    }
}
