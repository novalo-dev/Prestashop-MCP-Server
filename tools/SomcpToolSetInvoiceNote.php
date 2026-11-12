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
 * somcp_set_invoice_note - the Add note / Edit note field on an invoice line of
 * the Documents tab of the order page. The note is printed on the invoice PDF
 * the customer receives, which is why it is its own tool and not the order's
 * private note (somcp_set_order_note, read by the shop only).
 *
 * The core path, identical on 8.0.1 and 9.0.0, is UpdateInvoiceNoteHandler:
 * load the OrderInvoice, set its note, save(). The core's guard is wrong on
 * both (an unknown invoice with a dirty note slips through), so the tool
 * checks instead that the invoice exists AND belongs to the order, and that
 * the note passes Validate::isCleanHtml(), before writing. The length limit is
 * read from OrderInvoice::$definition, which carries a size on 9.0.0 only.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// order lookup lives in this class.
require_once __DIR__ . '/SomcpToolGetOrder.php';

class SomcpToolSetInvoiceNote
{
    /** @var string[] The arguments the tool takes, so a misspelt one is refused by name. */
    private const KEYS = ['id_order', 'reference', 'id_order_invoice', 'note'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_set_invoice_note',
            Somcp::t('Set the note on an invoice'),
            'Sets the note printed on one invoice of an order, like Add note / Edit note on an invoice line of '
                . 'the Documents tab of the order page (id_order_invoice from documents.invoices of '
                . 'somcp_get_order). The note is on the PDF the customer receives: for a note the shop alone '
                . 'reads use somcp_set_order_note, for a message to the customer somcp_add_order_message. An '
                . 'empty note clears it, only if the merchant asked. One invoice per call. On a lost answer do '
                . 'not retry: read documents.invoices[].note with somcp_get_order.',
            [
                'type' => 'object',
                'properties' => [
                    'id_order' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the order, from somcp_search_orders.',
                    ],
                    'reference' => [
                        'type' => 'string',
                        'description' => 'Reference of the order, used when no identifier is known. A reference '
                            . 'shared by several orders (a split cart) is refused with their identifiers.',
                    ],
                    'id_order_invoice' => [
                        'type' => 'integer',
                        'description' => 'Which invoice, from documents.invoices[].id_order_invoice of '
                            . 'somcp_get_order.',
                    ],
                    'note' => [
                        'type' => 'string',
                        'description' => 'The whole note, trimmed; an empty string clears it. No HTML.',
                    ],
                ],
                'required' => ['id_order_invoice', 'note'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Add note / Edit note sits on the order page, and saving it takes
            // the edit right there.
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

        // The note's shape and content do not depend on the order, so they are
        // checked up front and come back together with the rest.
        $note = null;
        if (!array_key_exists('note', $arguments) || !is_string($arguments['note'])) {
            $problems[] = 'note: give the note as a text, "" to clear it';
        } else {
            // Spaces around a pasted note are not part of it.
            $note = trim($arguments['note']);
            if (!Validate::isCleanHtml($note)) {
                $problems[] = 'note: the note holds HTML or a character PrestaShop refuses in it';
            } else {
                $limit = self::noteLimit();
                if ($limit > 0 && Tools::strlen($note) > $limit) {
                    $problems[] = 'note: a note holds ' . $limit . ' characters at most';
                }
            }
        }

        $order = SomcpToolGetOrder::find($arguments);
        if (!$order instanceof Order) {
            $problems[] = (array_key_exists('reference', $arguments) && SomcpTool::id($arguments, 'id_order') <= 0
                ? 'reference' : 'id_order') . ': ' . $order;

            return SomcpTool::refused('changed', $problems);
        }

        // The invoice must exist and belong to this order: the core's own check
        // misses the second half.
        $idOrderInvoice = SomcpTool::id($arguments, 'id_order_invoice');
        $invoice = null;
        if ($idOrderInvoice <= 0) {
            $problems[] = 'id_order_invoice: give the invoice, from documents.invoices[].id_order_invoice of '
                . 'somcp_get_order';
        } else {
            $loaded = new OrderInvoice($idOrderInvoice);
            if (!Validate::isLoadedObject($loaded) || (int) $loaded->id_order !== (int) $order->id) {
                $problems[] = 'id_order_invoice: no invoice of this order has the identifier ' . $idOrderInvoice
                    . ' - find it with somcp_get_order';
            } else {
                $invoice = $loaded;
            }
        }

        if ($problems !== [] || $invoice === null || $note === null) {
            return SomcpTool::refused('changed', $problems);
        }

        $previous = (string) $invoice->note;

        // The same note again writes nothing, so a retried call after a lost
        // answer reports the state without touching it.
        if ($previous === $note) {
            return SomcpTool::json([
                'changed' => false,
                'order' => self::order($order),
                'invoice' => self::invoice($invoice, $order),
                'old' => $previous,
                'new' => $note,
            ]);
        }

        $invoice->note = $note;
        try {
            $saved = $invoice->update();
        } catch (Throwable $e) {
            $saved = false;
        }

        if (!$saved) {
            SomcpLogger::log(
                'invoice_note_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'order ' . (int) $order->id . ', invoice ' . (int) $invoice->id,
                '',
                $idEmployee
            );

            return SomcpTool::refused('changed', [
                'id_order_invoice: the note could not be saved - check the invoice with somcp_get_order',
            ]);
        }

        SomcpLogger::log(
            'invoice_note_changed',
            SomcpLogger::OUTCOME_SUCCESS,
            'order ' . (int) $order->id . ', invoice ' . (int) $invoice->id,
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'changed' => true,
            'order' => self::order($order),
            'invoice' => self::invoice($invoice, $order),
            'old' => $previous,
            'new' => $note,
        ]);
    }

    /**
     * The length limit of an invoice note, read from the model definition so it
     * follows the running version: 9.0.0 declares a size, 8.0.1 declares none.
     *
     * @return int 0 when the definition carries no size
     */
    private static function noteLimit(): int
    {
        $definition = OrderInvoice::$definition;

        return isset($definition['fields']['note']['size']) ? (int) $definition['fields']['note']['size'] : 0;
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
     * The invoice in the fields somcp_get_order gives it under
     * documents.invoices[].
     *
     * @param OrderInvoice $invoice
     * @param Order $order
     *
     * @return array<string, mixed>
     */
    private static function invoice(OrderInvoice $invoice, Order $order): array
    {
        return [
            'id_order_invoice' => (int) $invoice->id,
            // Formatted as documents.invoices[].number of somcp_get_order.
            'number' => (string) $invoice->getInvoiceNumberFormatted((int) $order->id_lang),
        ];
    }
}
