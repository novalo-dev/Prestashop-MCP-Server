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
 * somcp_add_order_payment - the line that adds a payment in the Payment block
 * of the order page: a bank wire or a cheque that arrived, a part payment.
 *
 * The checks are those of AddPaymentCommand, the same on 8.0.1 and 9.0.0 (a
 * payment method without < > = { }, an amount that is not negative, a currency
 * that exists, the invoice when the order has one), then AddPaymentHandler
 * calls Order::addOrderPayment(), which adds the order_payment row and raises
 * orders.total_paid_real. The command bus is not used: on 9.0.0 its command
 * takes an employee id the 8.0.1 one does not know, and the method the handler
 * ends in is the same on both.
 *
 * The order status is never touched. Marking the order paid is a separate
 * decision, with its own emails, taken with somcp_set_order_status.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// order lookup lives in that class, and the employee on the context in the
// other.
require_once __DIR__ . '/SomcpToolGetOrder.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';

class SomcpToolAddOrderPayment
{
    /** @var int The size of order_payment.payment_method on PrestaShop 8 and 9. */
    private const METHOD_LENGTH = 255;

    /** @var int The size the class declares for order_payment.transaction_id. */
    private const TRANSACTION_LENGTH = 254;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_add_order_payment',
            Somcp::t('Record a payment for an order'),
            'Records one payment received for one order - a bank wire or a cheque that arrived, a part '
                . 'payment - like the Payment block of the order page. It adds the payment line and raises the '
                . 'amount paid of the order; it does NOT change the order status: marking the order paid is a '
                . 'separate call to somcp_set_order_status, only if the merchant wants it. The answer gives '
                . 'what is still due (remaining), so you can tell the merchant whether the order is now fully '
                . 'paid. When the order has an invoice, id_order_invoice is required: take it from '
                . 'documents.invoices of somcp_get_order, never guess it; without it the call is refused with '
                . 'the list of invoices. One payment per call. Not for refunds, which are credit slips. Never '
                . 'to be retried on a lost answer: a payment recorded twice is money counted twice - read the '
                . 'order with somcp_get_order, whose payments list shows it once recorded.',
            [
                'type' => 'object',
                'properties' => [
                    'id_order' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the order.',
                    ],
                    'reference' => [
                        'type' => 'string',
                        'description' => 'Reference of the order, used when no identifier is known.',
                    ],
                    'amount' => [
                        'type' => 'number',
                        'description' => 'The amount received, greater than 0, in currency.',
                    ],
                    'payment_method' => [
                        'type' => 'string',
                        'description' => 'The payment method as the merchant would type it, for example "Bank '
                            . 'wire" or "Cheque". 255 characters at most, without < > = { }.',
                    ],
                    'currency' => [
                        'type' => 'string',
                        'description' => 'ISO code of the currency of the amount, for example EUR. The order\'s '
                            . 'currency when left out.',
                    ],
                    'payment_date' => [
                        'type' => 'string',
                        'description' => 'When the payment was received, ISO 8601 (2026-10-29 or '
                            . '2026-10-29T14:30:00+01:00). Now when left out.',
                    ],
                    'transaction_id' => [
                        'type' => 'string',
                        'description' => 'The reference of the bank or of the payment processor, 254 characters '
                            . 'at most.',
                    ],
                    'id_order_invoice' => [
                        'type' => 'integer',
                        'description' => 'The invoice the payment is for, from documents.invoices of '
                            . 'somcp_get_order. Required when the order has an invoice, ignored when it has none.',
                    ],
                ],
                'required' => ['amount', 'payment_method'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The Payment block sits on the order page, and adding a line
            // there takes the edit right on it.
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

        $amount = self::amount($arguments['amount'] ?? null);
        if ($amount === null) {
            return SomcpTool::text('Nothing was recorded: amount must be a number.', true);
        }
        // The core refuses a negative amount; a zero one would add a line that
        // records nothing, and is refused too.
        if ($amount <= 0) {
            return SomcpTool::text('Nothing was recorded: the amount must be greater than 0.', true);
        }

        $method = is_string($arguments['payment_method'] ?? null) ? trim($arguments['payment_method']) : '';
        if ($method === '') {
            return SomcpTool::text(
                'Nothing was recorded: payment_method is required, for example "Bank wire".',
                true
            );
        }
        // The rule of AddPaymentCommand and of the class field: the same
        // characters refused on both versions.
        if (Tools::strlen($method) > self::METHOD_LENGTH || !Validate::isGenericName($method)) {
            return SomcpTool::text(
                'Nothing was recorded: the payment method holds ' . self::METHOD_LENGTH
                    . ' characters at most, without < > = { }.',
                true
            );
        }

        $transaction = '';
        if (array_key_exists('transaction_id', $arguments)) {
            if (!is_string($arguments['transaction_id'])) {
                return SomcpTool::text('Nothing was recorded: transaction_id must be a string.', true);
            }
            $transaction = trim($arguments['transaction_id']);
            if (Tools::strlen($transaction) > self::TRANSACTION_LENGTH) {
                return SomcpTool::text(
                    'Nothing was recorded: the transaction ID holds ' . self::TRANSACTION_LENGTH
                        . ' characters at most.',
                    true
                );
            }
        }

        $date = self::date($arguments['payment_date'] ?? null);
        if ($date === null) {
            return SomcpTool::text(
                'Nothing was recorded: payment_date must be an ISO 8601 date, for example 2026-10-29 or '
                    . '2026-10-29T14:30:00+01:00.',
                true
            );
        }

        $order = SomcpToolGetOrder::locate($arguments);
        if ($order === null) {
            return SomcpTool::text('No order of this shop matches what was given. Nothing was recorded.', true);
        }

        $currency = self::currency($arguments['currency'] ?? null, $order);
        if ($currency === null) {
            return SomcpTool::text(
                'Nothing was recorded: this currency does not exist in the shop. Give its ISO code, for '
                    . 'example EUR, or leave it out for the order\'s currency.',
                true
            );
        }

        // The back office asks for the invoice only when the order has one,
        // and the core ignores it otherwise.
        $invoice = null;
        if ($order->hasInvoice()) {
            $invoice = self::invoice($arguments['id_order_invoice'] ?? null, $order);
            if ($invoice === null) {
                return SomcpTool::text(
                    (array_key_exists('id_order_invoice', $arguments)
                        ? 'This id_order_invoice is not an invoice of this order.'
                        : 'This order has an invoice: say which one the payment is for with id_order_invoice.')
                        . ' Nothing was recorded. Its invoices: '
                        . json_encode(self::invoices($order), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    true
                );
            }
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($order, $amount, $method, $transaction, $currency, $date, $invoice, $idEmployee): array {
            return self::apply($order, $amount, $method, $transaction, $currency, $date, $invoice, $idEmployee);
        });
    }

    /**
     * Adds the payment as AddPaymentHandler does, and reports what is due.
     *
     * @param Order $order
     * @param float $amount
     * @param string $method
     * @param string $transaction
     * @param Currency $currency
     * @param string $date
     * @param OrderInvoice|null $invoice
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, float $amount, string $method, string $transaction, Currency $currency, string $date, ?OrderInvoice $invoice, int $idEmployee): array
    {
        // addOrderPayment() does not hand the new row back. The highest id of
        // the order's payments before and after tells which one it added.
        $before = self::lastPaymentId($order);

        $arguments = [(string) $amount, $method, $transaction, $currency, $date, $invoice];
        // PrestaShop 9 stores who took the payment (order_payment.id_employee)
        // through a seventh argument that 8.0.1 does not have. The signature
        // says which, rather than the version number.
        if ((new ReflectionMethod($order, 'addOrderPayment'))->getNumberOfParameters() >= 7) {
            $arguments[] = $idEmployee > 0 ? $idEmployee : null;
        }

        try {
            $added = (bool) $order->addOrderPayment(...$arguments);
        } catch (Throwable $e) {
            // ObjectModel throws when a field fails its validation.
            $added = false;
        }

        $idPayment = self::lastPaymentId($order);
        if (!$added && $idPayment <= $before) {
            SomcpLogger::log(
                'order_payment_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'order ' . (int) $order->id,
                '',
                $idEmployee
            );

            return SomcpTool::text('The payment could not be recorded. Nothing was added.', true);
        }

        SomcpLogger::log(
            'order_payment_added',
            $added ? SomcpLogger::OUTCOME_SUCCESS : SomcpLogger::OUTCOME_FAILURE,
            'order ' . (int) $order->id . ', payment ' . ($idPayment > $before ? $idPayment : 0)
                . ($added ? '' : ', order not fully updated'),
            '',
            $idEmployee
        );

        $orderCurrency = new Currency((int) $order->id_currency);
        $precision = Validate::isLoadedObject($orderCurrency) ? (int) $orderCurrency->precision : 2;
        $paid = Tools::ps_round((float) $order->total_paid_real, $precision);
        $total = Tools::ps_round((float) $order->total_paid_tax_incl, $precision);
        // What is still due, never below zero: an overpayment is not a
        // negative debt, and a rounding remainder is not a -0 to report.
        $remaining = Tools::ps_round($total - $paid, $precision);
        $remaining = $remaining > 0 ? $remaining : 0.0;

        return SomcpTool::json([
            'added' => true,
            'order' => [
                'id_order' => (int) $order->id,
                'reference' => (string) $order->reference,
            ],
            'id_order_payment' => $idPayment > $before ? $idPayment : null,
            'amount' => $amount,
            'currency' => (string) $currency->iso_code,
            'payment_method' => $method,
            'transaction_id' => $transaction,
            'payment_date' => $date,
            'total_paid_real' => $paid,
            'total_tax_included' => $total,
            'order_currency' => Validate::isLoadedObject($orderCurrency) ? (string) $orderCurrency->iso_code : '',
            'remaining' => $remaining,
            // addOrderPayment() answers false when the payment row was added
            // but the order or the link to its invoice was not updated: said
            // apart so the assistant does not add the payment again.
            'warning' => $added
                ? null
                : 'The payment was recorded but the order or its invoice could not be fully updated. Do not '
                    . 'repeat this call: the payment is already there - check it with somcp_get_order.',
        ]);
    }

    /**
     * An amount as a client may send it: a number, or digits in a string.
     *
     * @param mixed $value
     *
     * @return float|null
     */
    private static function amount($value): ?float
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
     * The payment date in the shop's time, or null when it cannot be read.
     *
     * Only an ISO 8601 date is taken: PHP would otherwise read "tomorrow" or
     * "next friday" and record a date nobody typed.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private static function date($value): ?string
    {
        if ($value === null) {
            return date('Y-m-d H:i:s');
        }
        if (!is_string($value)
            || !preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/', trim($value))) {
            return null;
        }

        try {
            $date = new DateTime(trim($value));
        } catch (Throwable $e) {
            return null;
        }
        // 2026-02-31 is read as March 3rd: refused rather than shifted. The
        // offset typed is kept until the conversion below, so the day read
        // back is the day typed.
        if ($date->format('Y-m-d') !== substr(trim($value), 0, 10)) {
            return null;
        }

        // A day alone gets the time of the call, as addOrderPayment() does,
        // rather than midnight.
        if (strlen(trim($value)) === 10) {
            return $date->format('Y-m-d') . ' ' . date('H:i:s');
        }

        // The shop stores its dates in its own time zone, the one PrestaShop
        // set as PHP's default.
        $date->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return $date->format('Y-m-d H:i:s');
    }

    /**
     * The currency of the amount: the order's when none is given.
     *
     * @param mixed $value
     * @param Order $order
     *
     * @return Currency|null
     */
    private static function currency($value, Order $order): ?Currency
    {
        if ($value === null) {
            $currency = new Currency((int) $order->id_currency);

            return Validate::isLoadedObject($currency) ? $currency : null;
        }
        if (!is_string($value) || !preg_match('/^[A-Za-z]{3}$/', trim($value))) {
            return null;
        }

        $idCurrency = (int) Currency::getIdByIsoCode(Tools::strtoupper(trim($value)), (int) $order->id_shop);
        if ($idCurrency <= 0) {
            return null;
        }
        $currency = new Currency($idCurrency);

        return Validate::isLoadedObject($currency) ? $currency : null;
    }

    /**
     * The invoice named, when it is one of this order's.
     *
     * The core only checks that it exists: one of another order would tie
     * this payment to someone else's invoice.
     *
     * @param mixed $value
     * @param Order $order
     *
     * @return OrderInvoice|null
     */
    private static function invoice($value, Order $order): ?OrderInvoice
    {
        $idInvoice = 0;
        if (is_int($value)) {
            $idInvoice = $value;
        } elseif (is_string($value) && preg_match('/^\d{1,10}$/', trim($value))) {
            $idInvoice = (int) trim($value);
        }
        if ($idInvoice <= 0) {
            return null;
        }

        $invoice = new OrderInvoice($idInvoice);
        if (!Validate::isLoadedObject($invoice) || (int) $invoice->id_order !== (int) $order->id || (int) $invoice->number <= 0) {
            return null;
        }

        return $invoice;
    }

    /**
     * The invoices of the order, for the merchant to pick one.
     *
     * @param Order $order
     *
     * @return array<int, array<string, mixed>>
     */
    private static function invoices(Order $order): array
    {
        $invoices = [];
        foreach ($order->getInvoicesCollection() as $invoice) {
            if (!$invoice instanceof OrderInvoice || (int) $invoice->number <= 0) {
                continue;
            }
            $invoices[] = [
                'id_order_invoice' => (int) $invoice->id,
                'number' => (string) $invoice->getInvoiceNumberFormatted((int) $order->id_lang),
                'total_tax_included' => (float) $invoice->total_paid_tax_incl,
            ];
        }

        return $invoices;
    }

    /**
     * The highest payment id of the order, 0 when it has none.
     *
     * Payments are tied to the order by its reference, as in the core.
     *
     * @param Order $order
     *
     * @return int
     */
    private static function lastPaymentId(Order $order): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT MAX(`id_order_payment`) FROM `' . _DB_PREFIX_ . "order_payment`
             WHERE `order_reference` = '" . pSQL((string) $order->reference) . "'"
        );
    }
}
