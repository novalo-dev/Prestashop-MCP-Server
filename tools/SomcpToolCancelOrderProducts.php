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

use PrestaShop\PrestaShop\Adapter\Order\CommandHandler\CancelOrderProductHandler;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\CancelOrderProductCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\InvalidCancelProductException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\InvalidOrderStateException;
use Psr\Log\AbstractLogger;

/*
 * somcp_cancel_order_products - the Cancel products button of the order page:
 * quantities of some lines taken off an order that is not paid yet, the order
 * cancelled when nothing is left.
 *
 * The core's own CancelOrderProductHandler does the work, built with new and
 * the services the order line tools share (SomcpToolUpdateOrderProduct::
 * services()), as in the back office: the totals recomputed,
 * the stock, actionProductCancel with the cancel action and the status
 * Canceled with its customer email stay exactly as the button does them. The
 * lines are read and checked by the standard refund, and the refusals take
 * the shape of the other order writes.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// order lookup, the employee on the context and the line helpers live in
// these classes.
require_once __DIR__ . '/SomcpToolGetOrder.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolIssuePartialRefund.php';
require_once __DIR__ . '/SomcpToolIssueStandardRefund.php';
require_once __DIR__ . '/SomcpToolUpdateOrderProduct.php';

class SomcpToolCancelOrderProducts
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_cancel_order_products',
            Somcp::t('Cancel products of an order'),
            'Takes quantities of some lines off one order not paid yet, like the Cancel products button of the '
                . 'order page (id_order_detail from somcp_get_order); the order is cancelled and the customer '
                . 'emailed when no line is left. Needs merchandise returns on. A paid order goes to '
                . 'somcp_issue_standard_refund, a delivered one to somcp_return_order_products. Say the lines and '
                . 'wait for the go-ahead. Never to be retried on a lost answer: check products[].quantity of '
                . 'somcp_get_order.',
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
                    'lines' => [
                        'type' => 'array',
                        'description' => 'The lines to take quantities off, one entry per line.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id_order_detail' => [
                                    'type' => 'integer',
                                    'description' => 'The line, from products[].id_order_detail of '
                                        . 'somcp_get_order.',
                                ],
                                'quantity' => [
                                    'type' => 'integer',
                                    'description' => 'How many units to cancel, at least 1 and at most what is '
                                        . 'left on the line.',
                                ],
                            ],
                            'required' => ['id_order_detail', 'quantity'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['lines'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The Cancel products button sits on the order page, and the form
            // it opens takes the edit right there.
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

        $lines = SomcpToolIssueStandardRefund::lines($arguments, $problems, 'cancel');

        $order = SomcpToolGetOrder::find($arguments);
        if (!$order instanceof Order) {
            $problems[] = $order;

            return SomcpToolIssuePartialRefund::refused($problems);
        }

        $cancels = SomcpToolIssueStandardRefund::checkLines($order, $lines, $problems, 'cancel');

        // The handler does not look at merchandise returns, but the button
        // only shows with them on: the tool follows the screen, and names
        // what cancels the whole order instead.
        if (!(bool) Configuration::get('PS_ORDER_RETURN', null, null, (int) $order->id_shop)) {
            $problems[] = 'id_order: merchandise returns are disabled (Customer Service > Merchandise Returns), '
                . 'so the order page has no Cancel products - switch them on there, or cancel the whole order '
                . 'with somcp_set_order_status';
        }
        // Said up front with the tool that does the job instead: the page
        // shows another button in each of these cases.
        if ($order->hasBeenDelivered()) {
            $problems[] = 'id_order: this order is already delivered - somcp_return_order_products records '
                . 'products sent back';
        } elseif ($order->hasBeenPaid() || $order->hasPayments()) {
            $problems[] = 'id_order: this order is paid or has a payment - somcp_issue_standard_refund refunds '
                . 'whole lines, somcp_issue_partial_refund a chosen amount';
        }

        if ($problems !== []) {
            return SomcpToolIssuePartialRefund::refused($problems);
        }

        $quantities = [];
        foreach ($cancels as $idDetail => $cancel) {
            $quantities[(int) $idDetail] = (int) $cancel['quantity'];
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($order, $quantities, $idEmployee): array {
            return self::apply($order, $quantities, $idEmployee);
        });
    }

    /**
     * Runs the core handler and reports what it changed.
     *
     * @param Order $order
     * @param array<int, int> $quantities units to cancel, by id_order_detail
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, array $quantities, int $idEmployee): array
    {
        $legacy = Somcp::legacyContext();

        $before = [];
        foreach (array_keys($quantities) as $idDetail) {
            $detail = new OrderDetail($idDetail);
            $before[$idDetail] = ['name' => (string) $detail->product_name, 'quantity' => (int) $detail->product_quantity];
        }
        $stateBefore = (int) $order->current_state;

        // The handler only logs a warning when the customer email of the
        // Canceled status fails: kept here so the answer can say it.
        $logger = new class extends AbstractLogger {
            /** @var bool */
            public $warned = false;

            /**
             * @param mixed $level
             * @param string|Stringable $message
             * @param array<mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->warned = true;
            }
        };

        try {
            $handler = new CancelOrderProductHandler(
                SomcpToolUpdateOrderProduct::services()['quantity'],
                $logger,
                $legacy->getTranslator()
            );
            $handler->handle(new CancelOrderProductCommand($quantities, (int) $order->id));
        } catch (Throwable $e) {
            return self::coreRefused($order, $e, $before, $idEmployee);
        }

        $order = new Order((int) $order->id);
        $currency = new Currency((int) $order->id_currency);
        $state = new OrderState((int) $order->current_state, (int) $order->id_lang);
        $cancelled = (int) $order->current_state !== $stateBefore
            && (int) $order->current_state === (int) Configuration::get('PS_OS_CANCELED');

        $lines = [];
        foreach ($quantities as $idDetail => $quantity) {
            $detail = new OrderDetail($idDetail);
            // A line taken down to nothing is deleted by the core.
            $left = Validate::isLoadedObject($detail) ? (int) $detail->product_quantity : 0;
            $lines[] = [
                'id_order_detail' => $idDetail,
                'product_name' => $before[$idDetail]['name'],
                'quantity_cancelled' => $quantity,
                'quantity_left' => $left,
            ];
        }

        SomcpLogger::log(
            'order_cancel_products',
            $logger->warned ? SomcpLogger::OUTCOME_FAILURE : SomcpLogger::OUTCOME_SUCCESS,
            'order ' . (int) $order->id . ', ' . count($lines) . ' line(s)' . ($cancelled ? ', order cancelled' : '')
                . ($logger->warned ? ', email failed' : ''),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'cancelled' => true,
            'order' => [
                'id_order' => (int) $order->id,
                'reference' => (string) $order->reference,
            ],
            'lines' => $lines,
            'total_tax_excluded' => (float) $order->total_paid_tax_excl,
            'total_tax_included' => (float) $order->total_paid_tax_incl,
            'currency' => Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '',
            'order_cancelled' => $cancelled,
            'status' => [
                'id' => (int) $order->current_state,
                'name' => Validate::isLoadedObject($state) ? (string) $state->name : '',
            ],
            'warning' => $cancelled && $logger->warned
                ? 'The order was cancelled but the customer email could not be sent. Do not repeat this call: '
                    . 'the change is already recorded.'
                : null,
        ]);
    }

    /**
     * Turns what the core threw into the tool's error, after checking that
     * no line was changed before it stopped.
     *
     * @param Order $order
     * @param Throwable $e
     * @param array<int, array{name: string, quantity: int}> $before
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function coreRefused(Order $order, Throwable $e, array $before, int $idEmployee): array
    {
        $written = false;
        foreach ($before as $idDetail => $line) {
            $detail = new OrderDetail($idDetail);
            if (!Validate::isLoadedObject($detail) || (int) $detail->product_quantity !== $line['quantity']) {
                $written = true;
                break;
            }
        }

        SomcpLogger::log(
            'order_cancel_products_failed',
            SomcpLogger::OUTCOME_FAILURE,
            'order ' . (int) $order->id . ', ' . get_class($e) . ' ' . (int) $e->getCode(),
            '',
            $idEmployee
        );

        if ($written) {
            return SomcpTool::text(
                'The cancellation stopped part way: some lines were changed, but not all. Do not repeat this '
                    . 'call - check the order with somcp_get_order.',
                true
            );
        }

        $problem = 'the products could not be cancelled';
        if ($e instanceof InvalidCancelProductException) {
            switch ($e->getCode()) {
                case InvalidCancelProductException::QUANTITY_TOO_HIGH:
                    $problem = 'lines: a quantity is above what is left to cancel (' . $e->getRefundableQuantity() . ')';
                    break;
                case InvalidCancelProductException::NO_REFUNDS:
                    $problem = 'lines: give the lines to cancel, a list of id_order_detail and quantity';
                    break;
                case InvalidCancelProductException::INVALID_QUANTITY:
                    $problem = 'lines: each quantity must be a whole number, at least 1';
                    break;
            }
        } elseif ($e instanceof InvalidOrderStateException && $e->getCode() === InvalidOrderStateException::ALREADY_PAID) {
            $problem = 'id_order: this order is paid or has a payment - somcp_issue_standard_refund refunds whole '
                . 'lines, somcp_issue_partial_refund a chosen amount';
        }

        return SomcpToolIssuePartialRefund::refused([$problem]);
    }
}
