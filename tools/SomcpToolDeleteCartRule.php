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
 * somcp_delete_cart_rule - the Delete of a row, or Delete selected, of
 * Catalog > Discounts > Cart Rules, one rule per call.
 *
 * AdminController::processDelete() calls CartRule::delete(), the same body on
 * 8.0 and 9.0: the rule, its texts and every row of its conditions and
 * actions go, it leaves the carts in progress, and the cart rule feature is
 * switched off when no active rule is left. The orders that used it keep
 * their discount line. The tool calls that very method, never its own SQL.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The employee wrapper, shared with every write tool.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
// The answer is the rule as the read gives it.
require_once __DIR__ . '/SomcpToolGetCartRule.php';
// The rules this shop may touch are those the list shows.
require_once __DIR__ . '/SomcpToolSetCartRuleActive.php';

class SomcpToolDeleteCartRule
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_cart_rule',
            Somcp::t('Delete a cart rule'),
            'Deletes one cart rule of Catalog > Discounts > Cart Rules, as its Delete, with every condition and '
                . 'action: it leaves the carts in progress, and orders that used it keep their discount. Cannot be '
                . 'undone: ask first, and to stop a promotion without losing it use somcp_set_cart_rule_active. '
                . 'id_cart_rule from somcp_list_cart_rules. The answer holds the rule as it was. Not to be '
                . 'retried: if the answer is lost, read somcp_get_cart_rule first.',
            [
                'type' => 'object',
                'properties' => [
                    'id_cart_rule' => [
                        'type' => 'integer',
                        'description' => 'The rule to delete, the ID column of somcp_list_cart_rules.',
                    ],
                ],
                'required' => ['id_cart_rule'],
                'additionalProperties' => false,
            ],
            [SomcpScope::SALES_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminCartRules')
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

        // Every mistake in one answer (#169), so the call is corrected in one go.
        $errors = SomcpTool::unknownFields($arguments, ['id_cart_rule']);

        $cartRule = null;
        $idCartRule = SomcpTool::id($arguments, 'id_cart_rule');
        if (!array_key_exists('id_cart_rule', $arguments)) {
            $errors[] = 'id_cart_rule: required - find it with somcp_list_cart_rules';
        } elseif ($idCartRule === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_cart_rule');
        } else {
            $cartRule = SomcpToolSetCartRuleActive::existing($idCartRule, $idShop);
            if ($cartRule === null) {
                $errors[] = SomcpTool::notFound('id_cart_rule', 'cart rule', $idCartRule, 'somcp_list_cart_rules');
            }
        }

        if ($errors !== [] || $cartRule === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        // Read before it goes: the answer is what it takes to create it again,
        // and what the warnings speak of is gone once the core has deleted it.
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $before = SomcpToolGetCartRule::byId($idCartRule, $idLang, $idShop);
        $inCarts = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cart_cart_rule` WHERE `id_cart_rule` = ' . $idCartRule
        );
        $otherShops = self::otherShops($cartRule, $idShop);
        $featureWasOn = (bool) Configuration::getGlobalValue('PS_CART_RULE_FEATURE_ACTIVE');

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($cartRule, $idCartRule, $idEmployee, $before, $inCarts, $otherShops, $featureWasOn): array {
            try {
                $done = (bool) $cartRule->delete();
                $failure = $done ? '' : 'PrestaShop refused to delete it';
            } catch (Throwable $e) {
                $done = false;
                $failure = $e->getMessage();
            }
            if (!$done) {
                SomcpLogger::log('cart_rule_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'cart rule ' . $idCartRule
                    . ': ' . Tools::substr($failure, 0, 500), '', $idEmployee);

                return SomcpTool::text(
                    'The cart rule could not be deleted: ' . $failure . '. Read it with somcp_get_cart_rule before '
                        . 'calling again.',
                    true
                );
            }
            // The identifier only: a rule may be kept to one customer.
            SomcpLogger::log('cart_rule_deleted', SomcpLogger::OUTCOME_SUCCESS, 'cart rule ' . $idCartRule, '', $idEmployee);

            $warnings = [];
            $used = (int) ($before['times_used'] ?? 0);
            if ($used > 0) {
                $warnings[] = $used . ' orders used this rule: they keep their discount.';
            }
            if ($inCarts > 0) {
                $warnings[] = 'It was removed from ' . $inCarts . ' carts in progress.';
            }
            // CartRule::delete() sets the key again from the rules left, for
            // every shop at once.
            if ($featureWasOn && !(bool) Configuration::getGlobalValue('PS_CART_RULE_FEATURE_ACTIVE')) {
                $warnings[] = 'No cart rule is active any more: the voucher field is no longer shown at checkout.';
            }
            if ($otherShops !== null) {
                $warnings[] = 'This rule also applied to ' . $otherShops . ': it is deleted for all of them.';
            }

            $result = [
                'deleted' => true,
                'id_cart_rule' => $idCartRule,
                'cart_rule' => $before,
            ];
            if ($warnings !== []) {
                $result['warning'] = implode(' ', $warnings);
            }

            return SomcpTool::json($result);
        });
    }

    /**
     * The other shops a rule applies to, named for the warning, or null when
     * it is kept to this shop or the shop is the only one.
     *
     * A rule has no shop of its own to remove it from: CartRule::delete()
     * removes it everywhere, so the merchant is told which shops lose it.
     *
     * @param CartRule $cartRule
     * @param int $idShop
     *
     * @return string|null
     */
    private static function otherShops(CartRule $cartRule, int $idShop): ?string
    {
        if (!Shop::isFeatureActive() || count(Shop::getShops(false, null, true)) < 2) {
            return null;
        }
        if (!(bool) $cartRule->shop_restriction) {
            return 'every shop';
        }
        $names = array_column(Db::getInstance()->executeS(
            'SELECT s.`name` FROM `' . _DB_PREFIX_ . 'cart_rule_shop` crs
             INNER JOIN `' . _DB_PREFIX_ . 'shop` s ON s.`id_shop` = crs.`id_shop`
             WHERE crs.`id_cart_rule` = ' . (int) $cartRule->id . ' AND crs.`id_shop` <> ' . $idShop
                . ' ORDER BY s.`id_shop`'
        ) ?: [], 'name');

        return $names === [] ? null : 'the shops ' . implode(', ', $names);
    }
}
