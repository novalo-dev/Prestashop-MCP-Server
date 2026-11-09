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
use PrestaShop\PrestaShop\Adapter\Order\CommandHandler\IssueStandardRefundHandler;
use PrestaShop\PrestaShop\Adapter\Order\Refund\OrderRefundCalculator;
use PrestaShop\PrestaShop\Adapter\Order\Refund\OrderRefundUpdater;
use PrestaShop\PrestaShop\Adapter\Order\Refund\OrderSlipCreator;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\IssueStandardRefundCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\VoucherRefundType;

/*
 * somcp_issue_standard_refund - the Standard refund button of the order page:
 * whole quantities of some lines of a paid order not delivered yet, and the
 * shipping if asked, with a credit slip, a voucher or both.
 *
 * As for the partial refund, the core's own IssueStandardRefundHandler does
 * the work, built with new and the same five services: the stock put back,
 * actionProductCancel, the carrier weight, the slip, the refund data and the
 * voucher stay exactly as the button does them. The checks, the refusals and
 * the answer are those of SomcpToolIssuePartialRefund, called rather than
 * copied, so the two refunds answer alike.
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

class SomcpToolIssueStandardRefund
{
    /**
     * @var array<string, int> The choices of the voucher block, as for the partial refund: the values of
     *                         VoucherRefundType, written as numbers because define() is also read outside
     *                         PrestaShop (dev/tools/measure.php), where the core class is not loaded.
     */
    private const VOUCHER_REFUND_TYPES = [
        'product_prices' => 0,
        'product_prices_excluding_voucher' => 1,
    ];

    /** @var string[] The keys of one entry of lines. */
    private const LINE_KEYS = ['id_order_detail', 'quantity'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_issue_standard_refund',
            Somcp::t('Issue a standard refund on an order'),
            'Issues a standard refund on one paid order not delivered yet, like the Standard refund button of the '
                . 'order page: whole quantities of some lines (id_order_detail from somcp_get_order), put back in '
                . 'stock, and the shipping if asked. Needs merchandise returns on. Say the lines and what will be '
                . 'generated, and wait for the merchant\'s go-ahead. Chosen amounts: somcp_issue_partial_refund. '
                . 'Never to be retried on a lost answer: check products[].quantity_refunded and '
                . 'documents.credit_slips of somcp_get_order.',
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
                        'description' => 'The lines to refund, one entry per line; each line\'s price is refunded.',
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
                                    'description' => 'How many units to refund, at least 1 and at most what is '
                                        . 'left to refund on the line.',
                                ],
                            ],
                            'required' => ['id_order_detail', 'quantity'],
                            'additionalProperties' => false,
                        ],
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
                'required' => ['lines'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The Standard refund button sits on the order page, and the form
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

        $lines = self::lines($arguments, $problems);

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

        $refunds = self::checkLines($order, $lines, $problems);

        // The page only shows the choice for an order partly paid with a
        // voucher: taken anywhere else, the assistant would think it chose
        // something that changes nothing.
        if ($voucherType !== null && (float) $order->total_discounts <= 0) {
            $problems[] = 'voucher_refund_type: this order was not paid with a voucher, leave it out';
        }

        // The three refusals of IssueStandardRefundHandler, said up front with
        // the tool that does the job instead: the page shows another button
        // in each of these cases.
        if (!(bool) Configuration::get('PS_ORDER_RETURN', null, null, (int) $order->id_shop)) {
            $problems[] = 'id_order: merchandise returns are disabled (Customer Service > Merchandise Returns), '
                . 'so the order page has no Standard refund - use somcp_issue_partial_refund, which needs neither';
        }
        if (!$order->hasBeenPaid() && !$order->hasPayments()) {
            $problems[] = 'id_order: this order is neither paid nor has a payment - a standard refund needs one';
        }
        if ($order->hasBeenDelivered()) {
            $problems[] = 'id_order: this order is already delivered - a standard refund is only for an order '
                . 'not delivered yet; somcp_return_order_products records products sent back, '
                . 'somcp_issue_partial_refund refunds a chosen amount';
        }

        if ($problems !== []) {
            return SomcpToolIssuePartialRefund::refused($problems);
        }

        $type = $voucherType ?? VoucherRefundType::PRODUCT_PRICES_EXCLUDING_VOUCHER_REFUND;

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($order, $refunds, $shipping, $creditSlip, $voucher, $type, $idEmployee): array {
            return self::apply($order, $refunds, $shipping, $creditSlip, $voucher, $type, $idEmployee);
        });
    }

    /**
     * Runs the core handler and reports what it recorded.
     *
     * @param Order $order
     * @param array<int, array{quantity: int}> $refunds
     * @param bool $shipping
     * @param bool $creditSlip
     * @param bool $voucher
     * @param int $type
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, array $refunds, bool $shipping, bool $creditSlip, bool $voucher, int $type, int $idEmployee): array
    {
        $legacy = Somcp::legacyContext();
        $configuration = new CoreConfiguration();
        $translator = $legacy->getTranslator();
        $contextManager = new ContextStateManager(new LegacyContext());

        $slipBefore = SomcpToolIssuePartialRefund::lastSlipId($order);
        $voucherBefore = SomcpToolIssuePartialRefund::lastVoucherId($order);

        try {
            $command = new IssueStandardRefundCommand(
                (int) $order->id,
                $refunds,
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

            $handler = new IssueStandardRefundHandler(
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
            return SomcpToolIssuePartialRefund::coreRefused($order, $e, $slipBefore, $voucherBefore, $idEmployee, 'order_standard_refund_failed', 'a standard refund');
        }

        // The order is not delivered, so the handler always puts the
        // quantities back in stock.
        return SomcpToolIssuePartialRefund::report($order, $summary, true, $creditSlip, $voucher, $slipBefore, $voucherBefore, 'order_standard_refund', $idEmployee);
    }

    /**
     * Reads lines, keeping the problems found entry by entry. Shared with the
     * return of products, which takes its lines the same way.
     *
     * @param array<string, mixed> $arguments
     * @param string[] $problems
     *
     * @return array<int, array<string, mixed>>
     */
    public static function lines(array $arguments, array &$problems): array
    {
        $given = $arguments['lines'] ?? null;
        if (!is_array($given) || $given === [] || array_keys($given) !== range(0, count($given) - 1)) {
            $problems[] = 'lines: give the lines to refund, a list of id_order_detail and quantity';

            return [];
        }

        $lines = [];
        $seen = [];
        foreach ($given as $index => $entry) {
            $path = 'lines[' . $index . ']';
            if (!is_array($entry)) {
                $problems[] = $path . ': must be an object with id_order_detail and quantity';
                continue;
            }
            foreach (array_diff(array_keys($entry), self::LINE_KEYS) as $unknown) {
                $problems[] = $path . '.' . $unknown . ': unknown field';
            }

            $idDetail = SomcpToolIssuePartialRefund::integer($entry['id_order_detail'] ?? null);
            if ($idDetail === null || $idDetail <= 0) {
                $problems[] = $path . '.id_order_detail: must be the identifier of a line, from somcp_get_order';
                $idDetail = null;
            } elseif (isset($seen[$idDetail])) {
                // Two entries for one line would add up in nobody's head.
                $problems[] = $path . '.id_order_detail: line ' . $idDetail . ' is already given in lines['
                    . $seen[$idDetail] . ']';
                $idDetail = null;
            } else {
                $seen[$idDetail] = $index;
            }

            $quantity = SomcpToolIssuePartialRefund::integer($entry['quantity'] ?? null);
            // A line at 0 is refused rather than skipped: it says the request
            // was not what the merchant meant.
            if ($quantity === null || $quantity < 1) {
                $problems[] = $path . '.quantity: must be a whole number, at least 1';
                $quantity = null;
            }

            $lines[] = ['path' => $path, 'id_order_detail' => $idDetail, 'quantity' => $quantity];
        }

        return $lines;
    }

    /**
     * Checks each line against the order and builds what the command takes;
     * also what IssueReturnProductCommand takes, which has the same shape.
     *
     * @param Order $order
     * @param array<int, array<string, mixed>> $lines
     * @param string[] $problems
     *
     * @return array<int, array{quantity: int}>
     */
    public static function checkLines(Order $order, array $lines, array &$problems): array
    {
        $refunds = [];
        foreach ($lines as $line) {
            $path = $line['path'];
            $idDetail = $line['id_order_detail'];
            if ($idDetail === null) {
                continue;
            }

            $detail = new OrderDetail((int) $idDetail);
            // The core handler takes any line id: one of another order would
            // refund someone else's purchase.
            if (!Validate::isLoadedObject($detail) || (int) $detail->id_order !== (int) $order->id) {
                $problems[] = $path . '.id_order_detail: no line of this order has the identifier ' . $idDetail
                    . ' - find it with somcp_get_order';
                continue;
            }

            $quantity = $line['quantity'];
            if ($quantity === null) {
                continue;
            }
            $left = SomcpToolIssuePartialRefund::leftToRefund($detail);
            if ($quantity > $left) {
                $problems[] = $path . '.quantity: ' . ($left > 0 ? 'at most ' . $left . ' left to refund on this line'
                    : 'nothing is left to refund on this line');
                continue;
            }

            $refunds[(int) $idDetail] = ['quantity' => (int) $quantity];
        }

        return $refunds;
    }
}
