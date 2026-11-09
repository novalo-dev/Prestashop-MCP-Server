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

use PrestaShop\Decimal\DecimalNumber;
use PrestaShop\PrestaShop\Adapter\Configuration as CoreConfiguration;
use PrestaShop\PrestaShop\Adapter\ContextStateManager;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use PrestaShop\PrestaShop\Adapter\Order\CommandHandler\IssueReturnProductHandler;
use PrestaShop\PrestaShop\Adapter\Order\Refund\OrderRefundCalculator;
use PrestaShop\PrestaShop\Adapter\Order\Refund\OrderRefundUpdater;
use PrestaShop\PrestaShop\Adapter\Order\Refund\OrderSlipCreator;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\IssueReturnProductCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\VoucherRefundType;

/*
 * somcp_return_order_products - the Return products button of the order page:
 * whole quantities of some lines of a delivered order, sent back by the
 * customer and refunded, put back in stock only when asked.
 *
 * As for the standard refund, the core's own IssueReturnProductHandler does
 * the work, built with new and the same services: the stock put back when
 * asked, actionProductCancel with the return action, the carrier weight, the
 * slip, the lines counted as returned and the voucher stay exactly as the
 * button does them. The lines are read and checked by the standard refund,
 * and the refusals and the answer are those of the partial refund, called
 * rather than copied, so the three refunds answer alike.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// order lookup, the employee on the context and the refund helpers live in
// these classes.
require_once __DIR__ . '/SomcpToolGetOrder.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolIssuePartialRefund.php';
require_once __DIR__ . '/SomcpToolIssueStandardRefund.php';

class SomcpToolReturnOrderProducts
{
    /**
     * @var array<string, int> The choices of the voucher block, as for the other refunds: the values of
     *                         VoucherRefundType, written as numbers because define() is also read outside
     *                         PrestaShop (dev/tools/measure.php), where the core class is not loaded.
     */
    private const VOUCHER_REFUND_TYPES = [
        'product_prices' => 0,
        'product_prices_excluding_voucher' => 1,
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_return_order_products',
            Somcp::t('Return products of an order'),
            'Records products the customer sent back on one delivered order and refunds them, like the Return '
                . 'products button of the order page: whole quantities of some lines (id_order_detail from '
                . 'somcp_get_order), the shipping if asked. Needs merchandise returns on. Ask whether the goods go '
                . 'back in stock, say the lines and what will be generated, and wait for the go-ahead. Never to be '
                . 'retried on a lost answer: check products[].quantity_refunded of somcp_get_order.',
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
                        'description' => 'The lines sent back, one entry per line; each line\'s price is refunded.',
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
                                    'description' => 'How many units came back, at least 1 and at most what is '
                                        . 'left to refund on the line.',
                                ],
                            ],
                            'required' => ['id_order_detail', 'quantity'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'restock' => [
                        'type' => 'boolean',
                        'description' => 'Re-stock products: put the returned units back in stock. No default: '
                            . 'ask the merchant whether the goods can be sold again.',
                    ],
                    'refund_shipping' => [
                        'type' => 'boolean',
                        'description' => 'Refund the shipping, what is left of it. False when left out.',
                    ],
                    'credit_slip' => [
                        'type' => 'boolean',
                        'description' => 'Generate a credit slip. True when left out, as the box is ticked when '
                            . 'the form opens.',
                    ],
                    'voucher' => [
                        'type' => 'boolean',
                        'description' => 'Generate a voucher for the refunded amount. False when left out. At '
                            . 'least one of credit_slip and voucher must be true.',
                    ],
                    'voucher_refund_type' => [
                        'type' => 'string',
                        'enum' => array_keys(self::VOUCHER_REFUND_TYPES),
                        'description' => 'Only for an order partly paid with a voucher: refund the product '
                            . 'prices, or take the initial voucher off them (the default, as on screen).',
                    ],
                ],
                'required' => ['lines', 'restock'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The Return products button sits on the order page, and the form
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

        $lines = SomcpToolIssueStandardRefund::lines($arguments, $problems);

        // Required with no default, although the box opens unticked: whether
        // returned goods can be sold again is the merchant's call, not a
        // default a caller falls into.
        if (!array_key_exists('restock', $arguments)) {
            $problems[] = 'restock: say whether the returned units go back in stock (true or false) - ask the '
                . 'merchant';
        }
        $restock = SomcpToolIssuePartialRefund::flag($arguments, 'restock', false, $problems);
        $shipping = SomcpToolIssuePartialRefund::flag($arguments, 'refund_shipping', false, $problems);
        $creditSlip = SomcpToolIssuePartialRefund::flag($arguments, 'credit_slip', true, $problems);
        $voucher = SomcpToolIssuePartialRefund::flag($arguments, 'voucher', false, $problems);
        // The core refuses a refund that generates nothing; said here with
        // the field names, rather than as its bare exception.
        if (!$creditSlip && !$voucher) {
            $problems[] = 'credit_slip, voucher: at least one must be true - the refund has to generate a credit '
                . 'slip, a voucher or both';
        }

        $voucherType = null;
        if (array_key_exists('voucher_refund_type', $arguments)) {
            $type = $arguments['voucher_refund_type'];
            if (!is_string($type) || !isset(self::VOUCHER_REFUND_TYPES[$type])) {
                $problems[] = 'voucher_refund_type: must be one of ' . implode(', ', array_keys(self::VOUCHER_REFUND_TYPES));
            } else {
                $voucherType = self::VOUCHER_REFUND_TYPES[$type];
            }
        }

        $order = SomcpToolGetOrder::find($arguments);
        if (!$order instanceof Order) {
            $problems[] = (array_key_exists('reference', $arguments) && (int) ($arguments['id_order'] ?? 0) <= 0
                ? 'reference' : 'id_order') . ': ' . $order;

            return SomcpToolIssuePartialRefund::refused($problems);
        }

        $refunds = SomcpToolIssueStandardRefund::checkLines($order, $lines, $problems);

        // The page only shows the choice for an order partly paid with a
        // voucher: taken anywhere else, the caller would think it chose
        // something that changes nothing.
        if ($voucherType !== null && (float) $order->total_discounts <= 0) {
            $problems[] = 'voucher_refund_type: this order was not paid with a voucher, leave it out';
        }

        // The two refusals of IssueReturnProductHandler, said up front with
        // the tool that does the job instead: the page shows another button
        // in each of these cases.
        if (!(bool) Configuration::get('PS_ORDER_RETURN', null, null, (int) $order->id_shop)) {
            $problems[] = 'id_order: merchandise returns are disabled (Customer Service > Merchandise Returns), '
                . 'so the order page has no Return products - switch them on there, or use '
                . 'somcp_issue_partial_refund, which needs neither';
        }
        if (!$order->hasBeenDelivered()) {
            $problems[] = 'id_order: this order is not delivered yet - products are returned only after '
                . 'delivery; somcp_issue_standard_refund refunds an order not delivered yet';
        }

        if ($problems !== []) {
            return SomcpToolIssuePartialRefund::refused($problems);
        }

        $type = $voucherType ?? VoucherRefundType::PRODUCT_PRICES_EXCLUDING_VOUCHER_REFUND;

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($order, $refunds, $restock, $shipping, $creditSlip, $voucher, $type, $idEmployee): array {
            return self::apply($order, $refunds, $restock, $shipping, $creditSlip, $voucher, $type, $idEmployee);
        });
    }

    /**
     * Runs the core handler and reports what it recorded.
     *
     * @param Order $order
     * @param array<int, array{quantity: int}> $refunds
     * @param bool $restock
     * @param bool $shipping
     * @param bool $creditSlip
     * @param bool $voucher
     * @param int $type
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, array $refunds, bool $restock, bool $shipping, bool $creditSlip, bool $voucher, int $type, int $idEmployee): array
    {
        $legacy = Somcp::legacyContext();
        $configuration = new CoreConfiguration();
        $translator = $legacy->getTranslator();
        $contextManager = new ContextStateManager(new LegacyContext());

        $slipBefore = SomcpToolIssuePartialRefund::lastSlipId($order);
        $voucherBefore = SomcpToolIssuePartialRefund::lastVoucherId($order);

        try {
            $command = new IssueReturnProductCommand(
                (int) $order->id,
                $refunds,
                $restock,
                $shipping,
                $creditSlip,
                $voucher,
                $type
            );

            // Computed once more here for the answer, with the shipping the
            // handler takes: the whole of it tax included, capped by the
            // calculator to what earlier slips left.
            $calculator = new OrderRefundCalculator();
            $summary = $calculator->computeOrderRefund(
                $order,
                $command->getOrderDetailRefunds(),
                new DecimalNumber((string) ($shipping ? $order->total_shipping_tax_incl : 0)),
                $type,
                $command->getVoucherRefundAmount()
            );

            $handler = new IssueReturnProductHandler(
                $configuration,
                $calculator,
                new OrderSlipCreator($configuration, $translator),
                // Shared with the partial refund, which owns the locale typing
                // workaround PrestaShop 9 needs.
                SomcpToolIssuePartialRefund::voucherGenerator($legacy),
                new OrderRefundUpdater(),
                $contextManager
            );
            $handler->handle($command);
        } catch (Throwable $e) {
            return SomcpToolIssuePartialRefund::coreRefused($order, $e, $slipBefore, $voucherBefore, $idEmployee, 'order_return_products_failed', 'a return of products');
        }

        return SomcpToolIssuePartialRefund::report($order, $summary, $restock, $creditSlip, $voucher, $slipBefore, $voucherBefore, 'order_return_products', $idEmployee);
    }
}
