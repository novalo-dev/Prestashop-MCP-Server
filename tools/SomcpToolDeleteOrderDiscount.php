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

use PrestaShop\PrestaShop\Adapter\Configuration as CoreConfiguration;
use PrestaShop\PrestaShop\Adapter\ContextStateManager;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use PrestaShop\PrestaShop\Adapter\Order\CommandHandler\DeleteCartRuleFromOrderHandler;
use PrestaShop\PrestaShop\Adapter\Order\OrderAmountUpdater;
use PrestaShop\PrestaShop\Adapter\Order\OrderDetailUpdater;
use PrestaShop\PrestaShop\Adapter\Order\OrderProductQuantityUpdater;
use PrestaShop\PrestaShop\Adapter\Order\Refund\OrderProductRemover;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\DeleteCartRuleFromOrderCommand;

/*
 * somcp_delete_order_discount - the delete action of the discounts table in
 * the Products block of the order page.
 *
 * Removes one discount from one order and recomputes the order totals. The
 * work is the core's own DeleteCartRuleFromOrderHandler, built with new and
 * run as the employee, as somcp_add_order_discount builds its handler: the
 * handler soft-deletes the order_cart_rule row, takes the rule off the cart,
 * and either lowers the gift line by one (when the rule gave a gift product)
 * or recomputes the amounts. Running the handler rather than a copy of it
 * keeps the gift case exactly as the screen does it.
 *
 * A discount already removed stays in the table flagged deleted, and
 * Order::getCartRules() no longer lists it, so a second call answers
 * deleted: false rather than recomputing the order again.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolGetOrder.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolAddOrderDiscount.php';

class SomcpToolDeleteOrderDiscount
{
    /** @var array<int, string> The arguments the tool reads; anything else is refused. */
    private const KEYS = ['id_order', 'reference', 'id_order_cart_rule'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_order_discount',
            Somcp::t('Remove a discount from an order'),
            'Removes one discount from one order, like the delete action of the discounts table on the '
                . 'order page, and recomputes the order totals. It raises what the customer owes: say the new '
                . 'total before removing it. One discount per call. Not to be retried on a lost answer - check '
                . 'discounts with somcp_get_order: a discount no longer listed was removed. Not for switching '
                . 'off a voucher for every customer (somcp_set_cart_rule_active).',
            [
                'type' => 'object',
                'properties' => [
                    'id_order' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the order, from somcp_search_orders.',
                    ],
                    'reference' => [
                        'type' => 'string',
                        'description' => 'Reference of the order, used when no identifier is known. A '
                            . 'reference shared by several orders (a split cart) is refused with their '
                            . 'identifiers.',
                    ],
                    'id_order_cart_rule' => [
                        'type' => 'integer',
                        'description' => 'The discount to remove, from discounts[].id_order_cart_rule of '
                            . 'somcp_get_order or from the answer of somcp_add_order_discount. Never guessed.',
                    ],
                ],
                'required' => ['id_order_cart_rule'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The route behind the delete action asks for update on
            // AdminOrders, not delete: the discount is part of the order being
            // edited.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminOrders')
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
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $problems = [];

        array_push($problems, ...SomcpTool::unknownFields($arguments, self::KEYS));

        $idOrderCartRule = 0;
        if (!array_key_exists('id_order_cart_rule', $arguments)) {
            $problems[] = 'id_order_cart_rule: give the discount to remove, from discounts[].id_order_cart_rule '
                . 'of somcp_get_order.';
        } else {
            $idOrderCartRule = SomcpTool::id($arguments, 'id_order_cart_rule');
            if ($idOrderCartRule <= 0) {
                $problems[] = 'id_order_cart_rule: a whole number, from discounts[].id_order_cart_rule of '
                    . 'somcp_get_order.';
            }
        }

        // The order, found like the other order write tools: a shared
        // reference is refused with the list rather than landing on one of them.
        $order = SomcpToolGetOrder::find($arguments);
        if (!$order instanceof Order) {
            $problems[] = (array_key_exists('reference', $arguments) && (int) ($arguments['id_order'] ?? 0) <= 0
                ? 'reference: '
                : 'id_order: ') . $order;

            return SomcpTool::refused('deleted', $problems);
        }

        // The row must be one of this order's: the handler checks it too, but
        // its exception would not say where to find the right identifier.
        $orderCartRule = null;
        if ($idOrderCartRule > 0) {
            $orderCartRule = new OrderCartRule($idOrderCartRule);
            if (!Validate::isLoadedObject($orderCartRule) || (int) $orderCartRule->id_order !== (int) $order->id) {
                $problems[] = 'id_order_cart_rule: no discount of this order has the identifier ' . $idOrderCartRule
                    . ' - find it with somcp_get_order.';
                $orderCartRule = null;
            }
        }

        if ($problems !== [] || !$orderCartRule instanceof OrderCartRule) {
            return SomcpTool::refused('deleted', $problems);
        }

        if ((bool) $orderCartRule->deleted) {
            return SomcpTool::json(self::answer($order, $orderCartRule, false, false) + [
                'reason' => 'This discount was already removed from the order. Nothing was changed.',
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($order, $orderCartRule, $idEmployee): array {
            return self::apply($order, $orderCartRule, $idEmployee);
        });
    }

    /**
     * Runs the core handler and reports the order after it.
     *
     * @param Order $order
     * @param OrderCartRule $orderCartRule
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, OrderCartRule $orderCartRule, int $idEmployee): array
    {
        // Read before the call: once the rule is off the order, whether it gave
        // a gift line can no longer be told from the answer.
        $giftLine = self::hasGiftLine($order, (int) $orderCartRule->id_cart_rule);

        $contextManager = new ContextStateManager(new LegacyContext());
        $configuration = new CoreConfiguration();
        $remover = new OrderProductRemover();
        $amountUpdater = new OrderAmountUpdater(
            $configuration,
            $contextManager,
            new OrderDetailUpdater($contextManager, $configuration),
            $remover
        );

        try {
            $handler = new DeleteCartRuleFromOrderHandler(
                $amountUpdater,
                new OrderProductQuantityUpdater($amountUpdater, $remover, $contextManager),
                $contextManager
            );
            $handler->handle(new DeleteCartRuleFromOrderCommand((int) $order->id, (int) $orderCartRule->id));
        } catch (Throwable $e) {
            SomcpLogger::log(
                'order_delete_discount',
                SomcpLogger::OUTCOME_FAILURE,
                'order ' . (int) $order->id,
                '',
                $idEmployee
            );

            return SomcpTool::refused('deleted', [
                'id_order_cart_rule: the discount could not be removed.',
            ]);
        }

        SomcpLogger::log(
            'order_delete_discount',
            SomcpLogger::OUTCOME_SUCCESS,
            'order ' . (int) $order->id,
            '',
            $idEmployee
        );

        return SomcpTool::json(self::answer(
            new Order((int) $order->id),
            new OrderCartRule((int) $orderCartRule->id),
            true,
            $giftLine
        ));
    }

    /**
     * The answer, with the discount in the keys of discounts[] of
     * somcp_get_order and the totals in the keys somcp_add_order_discount
     * returns, so the same discount and the same order read the same in the
     * three answers.
     *
     * @param Order $order
     * @param OrderCartRule $orderCartRule
     * @param bool $deleted
     * @param bool $giftLine
     *
     * @return array<string, mixed>
     */
    private static function answer(Order $order, OrderCartRule $orderCartRule, bool $deleted, bool $giftLine): array
    {
        $currency = new Currency((int) $order->id_currency);

        return [
            'deleted' => $deleted,
            'order' => [
                'id_order' => (int) $order->id,
                'reference' => (string) $order->reference,
            ],
            'discount' => [
                'id_order_cart_rule' => (int) $orderCartRule->id,
                'id_order_invoice' => (int) $orderCartRule->id_order_invoice,
                'name' => (string) $orderCartRule->name,
                'value_tax_included' => (float) $orderCartRule->value,
                'value_tax_excluded' => (float) $orderCartRule->value_tax_excl,
                'free_shipping' => (bool) $orderCartRule->free_shipping,
            ],
            'gift_line_updated' => $giftLine,
            'discounts_tax_included' => (float) $order->total_discounts_tax_incl,
            'total_tax_excluded' => (float) $order->total_paid_tax_excl,
            'total_tax_included' => (float) $order->total_paid_tax_incl,
            'currency' => (string) $currency->iso_code,
        ];
    }

    /**
     * Whether the cart rule gave a gift product the order holds a line of:
     * the condition under which the core handler lowers that line by one
     * instead of only recomputing the amounts.
     *
     * @param Order $order
     * @param int $idCartRule
     *
     * @return bool
     */
    private static function hasGiftLine(Order $order, int $idCartRule): bool
    {
        $cartRule = new CartRule($idCartRule);
        if (!Validate::isLoadedObject($cartRule) || (int) $cartRule->gift_product <= 0) {
            return false;
        }

        foreach ($order->getOrderDetailList() as $line) {
            if ((int) $line['product_id'] === (int) $cartRule->gift_product
                && (int) $line['product_attribute_id'] === (int) $cartRule->gift_product_attribute) {
                return true;
            }
        }

        return false;
    }
}
