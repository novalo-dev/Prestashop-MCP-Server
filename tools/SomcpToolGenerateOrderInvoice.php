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
 * somcp_generate_order_invoice - the Generate invoice button of the
 * Documents tab of the order page.
 *
 * It does what the core's GenerateInvoiceHandler does, identical on 8.0.1
 * and 9.0.0: invoices on for the order's shop, no invoice yet, then
 * Order::setInvoice(true), which numbers the invoice with the shop's next
 * number and ties the payments without invoice to it. The handler is not
 * built here because its two checks are what the tool must turn into
 * readable refusals before anything is written, and setInvoice() is the
 * whole of what it does after them.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// order lookup lives there.
require_once __DIR__ . '/SomcpToolGetOrder.php';

class SomcpToolGenerateOrderInvoice
{
    /**
     * The arguments the tool takes, so a misspelt one is refused by name
     * rather than silently ignored.
     */
    private const KEYS = ['id_order', 'reference'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_generate_order_invoice',
            Somcp::t('Generate the invoice of an order'),
            'Generates the invoice of one order that has none yet, like the Generate invoice button of the '
                . 'Documents tab of the order page; nothing is emailed. Needs invoices on (Orders > Invoices). '
                . 'Reading invoices goes to somcp_get_order, sending one to the customer to somcp_set_order_status. '
                . 'Never to be retried on a lost answer: check documents.invoices of somcp_get_order.',
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
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The button sits on the order page, and the core route behind it
            // takes the edit right on orders.
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

        foreach (array_diff(array_keys($arguments), self::KEYS) as $unknown) {
            $problems[] = $unknown . ': unknown field';
        }

        $order = SomcpToolGetOrder::find($arguments);
        if (!$order instanceof Order) {
            $problems[] = (array_key_exists('reference', $arguments) && (int) ($arguments['id_order'] ?? 0) <= 0
                ? 'reference' : 'id_order') . ': ' . $order;

            return SomcpTool::refused('generated', $problems);
        }

        // An order that already has one is answered with it rather than
        // refused: a retried call then finds what the first one made.
        if ($problems === [] && $order->hasInvoice()) {
            return SomcpTool::json([
                'generated' => false,
                'reason' => 'This order already has an invoice; nothing was generated.',
                'order' => self::order($order),
                'invoice' => self::invoice($order),
            ]);
        }

        // Checked for the order's shop, as the handler does: the setting is
        // per shop, and the token's shop is the order's.
        if (!(bool) Configuration::get('PS_INVOICE', null, null, (int) $order->id_shop)) {
            $problems[] = 'id_order: invoices are disabled for this shop - turn them on in Orders > Invoices, '
                . 'Invoice options > Enable invoices';
        }

        if ($problems !== []) {
            return SomcpTool::refused('generated', $problems);
        }

        try {
            $order->setInvoice(true);
        } catch (Throwable $e) {
            SomcpLogger::log(
                'order_invoice_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'order ' . (int) $order->id . ', ' . get_class($e) . ' ' . (int) $e->getCode(),
                '',
                $idEmployee
            );

            return SomcpTool::refused('generated', ['id_order: the invoice could not be generated - check the order with '
                . 'somcp_get_order before trying again']);
        }

        $order = new Order((int) $order->id);
        $invoice = self::invoice($order);

        SomcpLogger::log(
            'order_invoice',
            $invoice === null ? SomcpLogger::OUTCOME_FAILURE : SomcpLogger::OUTCOME_SUCCESS,
            'order ' . (int) $order->id . ($invoice === null ? ', no invoice read back' : ', ' . $invoice['number']),
            '',
            $idEmployee
        );

        if ($invoice === null) {
            return SomcpTool::text(
                'The invoice was requested but cannot be read back. Do not repeat this call - check the order '
                    . 'with somcp_get_order.',
                true
            );
        }

        return SomcpTool::json([
            'generated' => true,
            'order' => self::order($order),
            'invoice' => $invoice,
        ]);
    }

    /**
     * @param Order $order
     *
     * @return array<string, mixed>
     */
    private static function order(Order $order): array
    {
        return [
            'id_order' => (int) $order->id,
            'reference' => (string) $order->reference,
        ];
    }

    /**
     * The order's invoice, in the fields somcp_get_order gives it.
     *
     * @param Order $order
     *
     * @return array<string, mixed>|null
     */
    private static function invoice(Order $order): ?array
    {
        foreach ($order->getInvoicesCollection() as $invoice) {
            // The collection is declared as holding ObjectModel: the test is
            // what says this really is an invoice.
            if (!$invoice instanceof OrderInvoice) {
                continue;
            }
            $currency = new Currency((int) $order->id_currency);

            return [
                'id_order_invoice' => (int) $invoice->id,
                // Formatted for the order's shop, as payments[].invoice_number
                // of somcp_get_order: the prefix is a per-shop setting.
                'number' => (string) $invoice->getInvoiceNumberFormatted((int) $order->id_lang, (int) $order->id_shop),
                'date' => (string) $invoice->date_add,
                'total_tax_excluded' => (float) $invoice->total_paid_tax_excl,
                'total_tax_included' => (float) $invoice->total_paid_tax_incl,
                'currency' => Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '',
            ];
        }

        return null;
    }
}
