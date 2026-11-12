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
use PrestaShop\PrestaShop\Adapter\Order\CommandHandler\IssuePartialRefundHandler;
use PrestaShop\PrestaShop\Adapter\Order\Refund\OrderRefundCalculator;
use PrestaShop\PrestaShop\Adapter\Order\Refund\OrderRefundSummary;
use PrestaShop\PrestaShop\Adapter\Order\Refund\OrderRefundUpdater;
use PrestaShop\PrestaShop\Adapter\Order\Refund\OrderSlipCreator;
use PrestaShop\PrestaShop\Adapter\Order\Refund\VoucherGenerator;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\IssuePartialRefundCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\InvalidCancelProductException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\InvalidOrderStateException;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\ReturnProductDisabledException;
use PrestaShop\PrestaShop\Core\Domain\Order\VoucherRefundType;

/*
 * somcp_issue_partial_refund - the Partial refund button of the order page:
 * chosen quantities and amounts of some lines, part of the shipping, with a
 * credit slip, a voucher or both.
 *
 * The core's own IssuePartialRefundHandler does the work, built with new and
 * its five services, whose constructors are the same on 8.0.1 and 9.0.0. The
 * module has no Symfony container outside the back office, and running the
 * handler itself rather than a copy of its sequence keeps the stock put back,
 * the hooks, the credit slip and the voucher exactly as the button does them.
 *
 * The handler trusts what it is given: it does not check that a line belongs
 * to the order, and it lowers an amount above the line's maximum instead of
 * refusing it. Both are checked here first, so nothing is refunded that the
 * merchant did not ask for.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// order lookup lives in that class, and the employee on the context in the
// other.
require_once __DIR__ . '/SomcpToolGetOrder.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';

class SomcpToolIssuePartialRefund
{
    /**
     * @var array<string, int> The choices of the voucher block, by the name the tool takes: the values of
     *                         VoucherRefundType::PRODUCT_PRICES_REFUND and PRODUCT_PRICES_EXCLUDING_VOUCHER_REFUND,
     *                         the same on 8.0.1 and 9.0.0. Written as numbers because define() is also read
     *                         outside PrestaShop (dev/tools/measure.php), where the core class is not loaded.
     */
    private const VOUCHER_REFUND_TYPES = [
        'product_prices' => 0,
        'product_prices_excluding_voucher' => 1,
    ];

    /** @var string[] The keys of one entry of lines. */
    private const LINE_KEYS = ['id_order_detail', 'quantity', 'amount_tax_excluded', 'amount_tax_included'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_issue_partial_refund',
            Somcp::t('Issue a partial refund on an order'),
            'Issues a partial refund on one paid order, like the Partial refund button of the order page: '
                . 'chosen quantities and amounts of some lines (id_order_detail from products of '
                . 'somcp_get_order) and part of the shipping, with a credit slip, a voucher or both. Say the '
                . 'amounts and what will be generated, and wait for the merchant\'s go-ahead. Not for money '
                . 'received (somcp_add_order_payment). Never to be retried on '
                . 'a lost answer: a refund issued twice is money paid back twice - check '
                . 'products[].quantity_refunded and documents.credit_slips of somcp_get_order.',
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
                        'description' => 'The lines to refund, one entry per line. Required unless only shipping '
                            . 'is refunded.',
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
                                'amount_tax_excluded' => [
                                    'type' => 'number',
                                    'description' => 'The amount to refund, tax excluded, greater than 0, at most '
                                        . 'quantity times the unit price. For orders whose customer group shows '
                                        . 'prices tax excluded; the answer names the right field otherwise.',
                                ],
                                'amount_tax_included' => [
                                    'type' => 'number',
                                    'description' => 'The amount to refund, tax included, greater than 0, at most '
                                        . 'quantity times the unit price. For orders whose customer group shows '
                                        . 'prices tax included; the answer names the right field otherwise.',
                                ],
                            ],
                            'required' => ['id_order_detail', 'quantity'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'shipping_amount_tax_included' => [
                        'type' => 'number',
                        'description' => 'The part of the shipping to refund, at most what is left of the order\'s '
                            . 'shipping. 0 when left out.',
                    ],
                    'restock' => [
                        'type' => 'boolean',
                        'description' => 'Re-stock products. Only matters once the order is delivered: before '
                            . 'that the quantities always go back to stock. False when left out.',
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
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The Partial refund button sits on the order page, and the form
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

        $shipping = 0.0;
        if (array_key_exists('shipping_amount_tax_included', $arguments)) {
            $value = self::number($arguments['shipping_amount_tax_included']);
            if ($value === null || $value < 0) {
                $problems[] = 'shipping_amount_tax_included: must be a number, 0 or more';
            } else {
                $shipping = $value;
            }
        }

        $restock = self::flag($arguments, 'restock', false, $problems);
        $creditSlip = self::flag($arguments, 'credit_slip', true, $problems);
        $voucher = self::flag($arguments, 'voucher', false, $problems);
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

        if ($lines === [] && $shipping <= 0) {
            $problems[] = 'lines: give the lines to refund, or a shipping_amount_tax_included above 0';
        }

        $order = SomcpToolGetOrder::find($arguments);
        if (!$order instanceof Order) {
            $problems[] = (array_key_exists('reference', $arguments) && (int) ($arguments['id_order'] ?? 0) <= 0
                ? 'reference' : 'id_order') . ': ' . $order;

            return self::refused($problems);
        }

        $taxIncluded = self::taxIncluded($order);
        $precision = self::precision($order);
        $refunds = self::checkLines($order, $lines, $taxIncluded, $precision, $problems);

        $shippingLeft = self::shippingLeft($order, $taxIncluded);
        if ($shipping > 0 && Tools::ps_round($shipping, $precision) > Tools::ps_round($shippingLeft, $precision)) {
            $problems[] = 'shipping_amount_tax_included: at most ' . Tools::ps_round($shippingLeft, $precision)
                . ' is left to refund on the shipping of this order';
        }

        // The page only shows the choice for an order partly paid with a
        // voucher: taken anywhere else, the assistant would think it chose
        // something that changes nothing.
        $hasVoucher = (float) $order->total_discounts > 0;
        if ($voucherType !== null && !$hasVoucher) {
            $problems[] = 'voucher_refund_type: this order was not paid with a voucher, leave it out';
        }

        if (!$order->hasBeenPaid() && !$order->hasPayments()) {
            $problems[] = 'id_order: this order is neither paid nor has a payment - a partial refund needs one';
        }

        if ($problems !== []) {
            return self::refused($problems);
        }

        $type = $voucherType ?? VoucherRefundType::PRODUCT_PRICES_EXCLUDING_VOUCHER_REFUND;

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($order, $refunds, $shipping, $restock, $creditSlip, $voucher, $type, $idEmployee): array {
            return self::apply($order, $refunds, $shipping, $restock, $creditSlip, $voucher, $type, $idEmployee);
        });
    }

    /**
     * Runs the core handler and reports what it recorded.
     *
     * @param Order $order
     * @param array<int, array{quantity: int, amount: string}> $refunds
     * @param float $shipping
     * @param bool $restock
     * @param bool $creditSlip
     * @param bool $voucher
     * @param int $type
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, array $refunds, float $shipping, bool $restock, bool $creditSlip, bool $voucher, int $type, int $idEmployee): array
    {
        $legacy = Somcp::legacyContext();
        $configuration = new CoreConfiguration();
        $translator = $legacy->getTranslator();
        $contextManager = new ContextStateManager(new LegacyContext());

        // The handler restocks only when the order is not delivered yet or
        // when asked: read before, as delivery is what it looks at.
        $restocked = !$order->hasBeenDelivered() || $restock;

        $slipBefore = self::lastSlipId($order);
        $voucherBefore = self::lastVoucherId($order);

        try {
            $command = new IssuePartialRefundCommand(
                (int) $order->id,
                $refunds,
                self::decimal($shipping),
                $restock,
                $creditSlip,
                $voucher,
                $type
            );

            // Computed once more here for the answer: the calculator only
            // reads, and the handler does not hand its summary back.
            $calculator = new OrderRefundCalculator();
            $summary = $calculator->computeOrderRefund(
                $order,
                $command->getOrderDetailRefunds(),
                $command->getShippingCostRefundAmount(),
                $type,
                null
            );

            $handler = new IssuePartialRefundHandler(
                $configuration,
                $calculator,
                new OrderSlipCreator($configuration, $translator),
                self::voucherGenerator($legacy),
                new OrderRefundUpdater(),
                $contextManager
            );
            $handler->handle($command);
        } catch (Throwable $e) {
            // The core's own refusals and any failure of a side effect end
            // here alike: coreRefused() tells which, and whether something
            // was written.
            return self::coreRefused($order, $e, $slipBefore, $voucherBefore, $idEmployee, 'order_partial_refund_failed', 'a partial refund');
        }

        return self::report($order, $summary, $restocked, $creditSlip, $voucher, $slipBefore, $voucherBefore, 'order_partial_refund', $idEmployee);
    }

    /**
     * What a refund recorded, in the answer shared by the refund tools.
     *
     * The handlers give nothing back: the lines and the shipping come from
     * the summary computed before the call, the slip and the voucher from the
     * highest ids after it.
     *
     * @param Order $order
     * @param OrderRefundSummary $summary
     * @param bool $restocked
     * @param bool $creditSlip
     * @param bool $voucher
     * @param int $slipBefore
     * @param int $voucherBefore
     * @param string $event
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    public static function report(Order $order, OrderRefundSummary $summary, bool $restocked, bool $creditSlip, bool $voucher, int $slipBefore, int $voucherBefore, string $event, int $idEmployee): array
    {
        $order = new Order((int) $order->id);
        $currency = new Currency((int) $order->id_currency);
        $precision = self::precision($order);

        $lines = [];
        $totalExcluded = 0.0;
        $totalIncluded = 0.0;
        foreach ($summary->getProductRefunds() as $idOrderDetail => $refund) {
            $detail = new OrderDetail((int) $idOrderDetail);
            $lines[] = [
                'id_order_detail' => (int) $idOrderDetail,
                'product_name' => (string) $detail->product_name,
                'quantity' => (int) $refund['quantity'],
                'amount_tax_excluded' => (float) $refund['total_refunded_tax_excl'],
                'amount_tax_included' => (float) $refund['total_refunded_tax_incl'],
            ];
            $totalExcluded += (float) $refund['total_refunded_tax_excl'];
            $totalIncluded += (float) $refund['total_refunded_tax_incl'];
        }

        $shippingRefunded = (float) $summary->getRefundedShipping();
        // The shipping amount is read in the tax mode of the order, as the
        // form sends it; its other side is worked out with the carrier tax
        // rate of the order.
        $rate = (float) $order->carrier_tax_rate;
        if ($summary->isTaxIncluded()) {
            $shippingIncluded = $shippingRefunded;
            $shippingExcluded = $rate > 0 ? $shippingRefunded / (1 + $rate / 100) : $shippingRefunded;
        } else {
            $shippingExcluded = $shippingRefunded;
            $shippingIncluded = $shippingRefunded * (1 + $rate / 100);
        }

        $slip = null;
        $idSlip = self::lastSlipId($order);
        // 9.0.0 keeps the slip it created and 8.0.1 does not: the highest id
        // of the order's slips is the one that works on both.
        if ($creditSlip && $idSlip > $slipBefore) {
            $orderSlip = new OrderSlip($idSlip);
            $slip = [
                'id_order_slip' => $idSlip,
                'total_tax_excluded' => (float) $orderSlip->total_products_tax_excl + (float) $orderSlip->total_shipping_tax_excl,
                'total_tax_included' => (float) $orderSlip->total_products_tax_incl + (float) $orderSlip->total_shipping_tax_incl,
            ];
        }

        $generated = null;
        $idVoucher = self::lastVoucherId($order);
        if ($voucher && $idVoucher > $voucherBefore) {
            $cartRule = new CartRule($idVoucher);
            $generated = [
                'id_cart_rule' => $idVoucher,
                'code' => (string) $cartRule->code,
                'amount' => (float) $cartRule->reduction_amount,
                'tax_included' => (bool) $cartRule->reduction_tax,
            ];
        }

        $missing = [];
        if ($creditSlip && $slip === null) {
            $missing[] = 'the credit slip';
        }
        if ($voucher && $generated === null && $summary->getRefundedAmount() > 0) {
            $missing[] = 'the voucher';
        }

        SomcpLogger::log(
            $event,
            $missing === [] ? SomcpLogger::OUTCOME_SUCCESS : SomcpLogger::OUTCOME_FAILURE,
            'order ' . (int) $order->id . ', slip ' . ($slip['id_order_slip'] ?? 0)
                . ', voucher ' . ($generated['id_cart_rule'] ?? 0),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'refunded' => true,
            'order' => [
                'id_order' => (int) $order->id,
                'reference' => (string) $order->reference,
            ],
            'lines' => $lines,
            'shipping_refunded_tax_included' => Tools::ps_round($shippingIncluded, $precision),
            'total_refunded_tax_excluded' => Tools::ps_round($totalExcluded + $shippingExcluded, $precision),
            'total_refunded_tax_included' => Tools::ps_round($totalIncluded + $shippingIncluded, $precision),
            'currency' => Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '',
            'restocked' => $restocked && $lines !== [],
            'credit_slip' => $slip,
            'voucher' => $generated,
            'warning' => $missing === []
                ? null
                : 'The refund was recorded but ' . implode(' and ', $missing) . ' could not be found afterwards. Do '
                    . 'not repeat this call: check documents.credit_slips of somcp_get_order.',
        ]);
    }

    /**
     * Reads lines, keeping the problems found entry by entry.
     *
     * @param array<string, mixed> $arguments
     * @param string[] $problems
     *
     * @return array<int, array<string, mixed>>
     */
    private static function lines(array $arguments, array &$problems): array
    {
        if (!array_key_exists('lines', $arguments)) {
            return [];
        }
        if (!is_array($arguments['lines']) || ($arguments['lines'] !== [] && array_keys($arguments['lines']) !== range(0, count($arguments['lines']) - 1))) {
            $problems[] = 'lines: must be a list of lines';

            return [];
        }

        $lines = [];
        $seen = [];
        foreach ($arguments['lines'] as $index => $entry) {
            $path = 'lines[' . $index . ']';
            if (!is_array($entry)) {
                $problems[] = $path . ': must be an object with id_order_detail, quantity and an amount';
                continue;
            }
            foreach (array_diff(array_keys($entry), self::LINE_KEYS) as $unknown) {
                $problems[] = $path . '.' . $unknown . ': unknown field';
            }

            $idDetail = self::integer($entry['id_order_detail'] ?? null);
            if ($idDetail === null || $idDetail <= 0) {
                $problems[] = $path . '.id_order_detail: must be the identifier of a line, from somcp_get_order';
            } elseif (isset($seen[$idDetail])) {
                // Two entries for one line would add up in nobody's head.
                $problems[] = $path . '.id_order_detail: line ' . $idDetail . ' is already given in lines['
                    . $seen[$idDetail] . ']';
                $idDetail = null;
            } else {
                $seen[$idDetail] = $index;
            }

            $quantity = self::integer($entry['quantity'] ?? null);
            // A line at 0 is refused rather than skipped: it says the request
            // was not what the merchant meant.
            if ($quantity === null || $quantity < 1) {
                $problems[] = $path . '.quantity: must be a whole number, at least 1';
            }

            $excluded = array_key_exists('amount_tax_excluded', $entry);
            $included = array_key_exists('amount_tax_included', $entry);
            $amount = null;
            if ($excluded && $included) {
                $problems[] = $path . ': give amount_tax_excluded or amount_tax_included, not both';
            } elseif (!$excluded && !$included) {
                $problems[] = $path . ': the amount is missing - amount_tax_excluded or amount_tax_included, as '
                    . 'the order shows its prices';
            } else {
                $field = $excluded ? 'amount_tax_excluded' : 'amount_tax_included';
                $amount = self::number($entry[$field]);
                if ($amount === null || $amount <= 0) {
                    $problems[] = $path . '.' . $field . ': must be a number greater than 0';
                    $amount = null;
                }
            }

            $lines[] = [
                'path' => $path,
                'id_order_detail' => $idDetail,
                'quantity' => $quantity,
                'tax_included' => $included,
                'amount' => $amount,
            ];
        }

        return $lines;
    }

    /**
     * Checks each line against the order, as the core would, and builds what
     * the command takes.
     *
     * @param Order $order
     * @param array<int, array<string, mixed>> $lines
     * @param bool $taxIncluded
     * @param int $precision
     * @param string[] $problems
     *
     * @return array<int, array{quantity: int, amount: string}>
     */
    private static function checkLines(Order $order, array $lines, bool $taxIncluded, int $precision, array &$problems): array
    {
        $refunds = [];
        foreach ($lines as $line) {
            $path = $line['path'];
            $idDetail = $line['id_order_detail'];
            if ($idDetail === null) {
                continue;
            }

            $detail = new OrderDetail((int) $idDetail);
            // The core handlers take any line id: one of another order would
            // refund someone else's purchase.
            if (!Validate::isLoadedObject($detail) || (int) $detail->id_order !== (int) $order->id) {
                $problems[] = $path . '.id_order_detail: no line of this order has the identifier ' . $idDetail
                    . ' - find it with somcp_get_order';
                continue;
            }

            if ($line['amount'] !== null && $line['tax_included'] !== $taxIncluded) {
                $problems[] = $path . '.' . ($line['tax_included'] ? 'amount_tax_included' : 'amount_tax_excluded')
                    . ': this order shows its prices tax ' . ($taxIncluded ? 'included' : 'excluded') . ', give '
                    . ($taxIncluded ? 'amount_tax_included' : 'amount_tax_excluded') . ' instead';
                continue;
            }

            $left = self::leftToRefund($detail);

            $quantity = $line['quantity'];
            if ($quantity === null) {
                continue;
            }
            if ($quantity > $left) {
                $problems[] = $path . '.quantity: ' . ($left > 0 ? 'at most ' . $left . ' left to refund on this line'
                    : 'nothing is left to refund on this line');
                continue;
            }

            if ($line['amount'] === null) {
                continue;
            }
            $unit = $taxIncluded ? (float) $detail->unit_price_tax_incl : (float) $detail->unit_price_tax_excl;
            $max = Tools::ps_round($quantity * $unit, $precision);
            // The calculator would quietly lower it to the maximum: refused
            // instead, so the amount refunded is the amount asked for.
            if (Tools::ps_round((float) $line['amount'], $precision) > $max) {
                $problems[] = $path . '.' . ($taxIncluded ? 'amount_tax_included' : 'amount_tax_excluded')
                    . ': at most ' . $max . ' for ' . $quantity . ' unit' . ($quantity > 1 ? 's' : '');
                continue;
            }

            $refunds[(int) $idDetail] = [
                'quantity' => $quantity,
                'amount' => self::decimal((float) $line['amount']),
            ];
        }

        return $refunds;
    }

    /**
     * How many units of a line are left to refund, read as
     * OrderRefundCalculator reads it: the customization's own counts when the
     * line has one.
     *
     * @param OrderDetail $detail
     *
     * @return int
     */
    public static function leftToRefund(OrderDetail $detail): int
    {
        if ((int) $detail->id_customization > 0) {
            $customization = new Customization((int) $detail->id_customization);

            return (int) $customization->quantity - (int) $customization->quantity_refunded
                - (int) $customization->quantity_returned;
        }

        return (int) $detail->product_quantity - (int) $detail->product_quantity_refunded
            - (int) $detail->product_quantity_return;
    }

    /**
     * Turns what the core threw into the tool's error, after checking that
     * nothing was left half written.
     *
     * @param Order $order
     * @param Throwable $e
     * @param int $slipBefore
     * @param int $voucherBefore
     * @param int $idEmployee
     * @param string $event the journal entry of the failure
     * @param string $kind the refund named in the answer, "a partial refund"
     *
     * @return array<string, mixed>
     */
    public static function coreRefused(Order $order, Throwable $e, int $slipBefore, int $voucherBefore, int $idEmployee, string $event, string $kind): array
    {
        $written = self::lastSlipId($order) > $slipBefore || self::lastVoucherId($order) > $voucherBefore;

        SomcpLogger::log(
            $event,
            SomcpLogger::OUTCOME_FAILURE,
            'order ' . (int) $order->id . ', ' . get_class($e) . ' ' . (int) $e->getCode(),
            '',
            $idEmployee
        );

        if ($written) {
            return SomcpTool::text(
                'The refund stopped part way: a credit slip or a voucher was created, but not everything the '
                    . 'refund does. Do not repeat this call - check the order with somcp_get_order.',
                true
            );
        }

        $problem = 'the refund could not be issued';
        if ($e instanceof InvalidCancelProductException) {
            switch ($e->getCode()) {
                case InvalidCancelProductException::QUANTITY_TOO_HIGH:
                    $problem = 'lines: a quantity is above what is left to refund (' . $e->getRefundableQuantity() . ')';
                    break;
                case InvalidCancelProductException::NO_GENERATION:
                    $problem = 'credit_slip, voucher: at least one must be true';
                    break;
                case InvalidCancelProductException::NO_REFUNDS:
                case InvalidCancelProductException::INVALID_AMOUNT:
                    $problem = 'lines: nothing is left to refund once the initial voucher is taken off - try '
                        . 'voucher_refund_type product_prices, or higher amounts';
                    break;
            }
        } elseif ($e instanceof InvalidOrderStateException) {
            $problem = $e->getCode() === InvalidOrderStateException::UNEXPECTED_DELIVERY
                ? 'id_order: this order is already delivered - ' . $kind . ' is only for an order not delivered yet'
                : 'id_order: this order is neither paid nor has a payment - ' . $kind . ' needs one';
        } elseif ($e instanceof ReturnProductDisabledException) {
            $problem = 'id_order: merchandise returns are disabled (Customer Service > Merchandise Returns) - use '
                . 'somcp_issue_partial_refund';
        }

        return self::refused([$problem]);
    }

    /**
     * The refusal of the refund family and of the order writes built on it.
     *
     * Kept as the one entry point those tools call, so they all answer in the
     * shared envelope of SomcpTool::refused() with the same verb.
     *
     * @param string[] $problems
     *
     * @return array<string, mixed>
     */
    public static function refused(array $problems): array
    {
        return SomcpTool::refused('recorded', $problems);
    }

    /**
     * Whether the order page shows this order's prices tax included.
     *
     * The rule of OrderRefundCalculator: the display method of the
     * customer's default group, the one the Amount label follows.
     *
     * @param Order $order
     *
     * @return bool
     */
    private static function taxIncluded(Order $order): bool
    {
        $customer = new Customer((int) $order->id_customer);

        return Group::getPriceDisplayMethod((int) $customer->id_default_group) === PS_TAX_INC;
    }

    /**
     * The rounding precision of the order's currency.
     *
     * @param Order $order
     *
     * @return int
     */
    public static function precision(Order $order): int
    {
        $currency = new Currency((int) $order->id_currency);

        return Validate::isLoadedObject($currency) ? (int) $currency->precision : 2;
    }

    /**
     * What is left of the shipping to refund, as OrderRefundCalculator caps it.
     *
     * The core takes the shipping in the order's tax mode and subtracts what
     * the earlier slips refunded tax included: the same arithmetic is kept,
     * so the tool refuses exactly what the core would lower.
     *
     * @param Order $order
     * @param bool $taxIncluded
     *
     * @return float
     */
    private static function shippingLeft(Order $order, bool $taxIncluded): float
    {
        $total = $taxIncluded ? (float) $order->total_shipping_tax_incl : (float) $order->total_shipping_tax_excl;
        $resume = OrderSlip::getShippingSlipResume((int) $order->id);
        $left = $total - (float) ($resume['total_shipping_tax_incl'] ?? 0);

        return $left > 0 ? $left : 0.0;
    }

    /**
     * A boolean argument, or its default.
     *
     * @param array<string, mixed> $arguments
     * @param string $name
     * @param bool $default
     * @param string[] $problems
     *
     * @return bool
     */
    public static function flag(array $arguments, string $name, bool $default, array &$problems): bool
    {
        if (!array_key_exists($name, $arguments)) {
            return $default;
        }
        if (!is_bool($arguments[$name])) {
            $problems[] = $name . ': must be true or false';

            return $default;
        }

        return $arguments[$name];
    }

    /**
     * A number as a client may send it: a number, or digits in a string.
     *
     * @param mixed $value
     *
     * @return float|null
     */
    public static function number($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (is_string($value) && preg_match('/^-?\d{1,14}(\.\d{1,6})?$/', trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    /**
     * A whole number as a client may send it.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    public static function integer($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d{1,10}$/', trim($value))) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * An amount as the string the command reads into a DecimalNumber.
     *
     * @param float $value
     *
     * @return string
     */
    public static function decimal(float $value): string
    {
        return sprintf('%.6F', $value);
    }

    /**
     * The highest credit slip id of the order, 0 when it has none.
     *
     * @param Order $order
     *
     * @return int
     */
    public static function lastSlipId(Order $order): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT MAX(`id_order_slip`) FROM `' . _DB_PREFIX_ . 'order_slip`
             WHERE `id_order` = ' . (int) $order->id
        );
    }

    /**
     * The core voucher generator, built the one way both refund tools need.
     *
     * The locale is set by the front controller; built from the context
     * otherwise, as the core does for a missing one. PrestaShop 9 types both
     * accessors against LocaleInterface while VoucherGenerator still wants the
     * concrete Locale, its only implementation - the cross-version typing
     * ignore in phpstan-module.neon is scoped to this one call, so the
     * standard refund comes here rather than building its own.
     *
     * @param Context $legacy
     *
     * @return VoucherGenerator
     */
    public static function voucherGenerator(Context $legacy): VoucherGenerator
    {
        return new VoucherGenerator($legacy->getCurrentLocale() ?? Tools::getContextLocale($legacy), $legacy->getTranslator());
    }

    /**
     * The highest cart rule id of the order's customer, 0 when there is none.
     *
     * VoucherGenerator ties the voucher to the customer and gives no id back.
     *
     * @param Order $order
     *
     * @return int
     */
    public static function lastVoucherId(Order $order): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT MAX(`id_cart_rule`) FROM `' . _DB_PREFIX_ . 'cart_rule`
             WHERE `id_customer` = ' . (int) $order->id_customer
        );
    }
}
