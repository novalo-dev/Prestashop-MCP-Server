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
 * somcp_set_order_note - the Order note (8.0.1) / Order private note (9.0.0)
 * text area of the Status tab of the order page.
 *
 * The note belongs to the order (orders.note), not to the customer: the
 * Private note of the Customer block on the same page is customer.note, and is
 * left to the customer tools.
 *
 * The sequence is the core's own, read from SetInternalOrderNoteHandler, which
 * is the same on 8.0.1 and 9.0.0: set Order::$note, validateFields(false),
 * update(). Nothing else happens there - no email, no history line - so
 * nothing else happens here either. Going through Order::update() keeps the
 * actionObjectOrderUpdateBefore/After hooks firing as from the back office.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// order lookup lives in that class, and the employee on the context in the
// other.
require_once __DIR__ . '/SomcpToolGetOrder.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';

class SomcpToolSetOrderNote
{
    /**
     * @var int The bytes a MySQL text column holds: orders.note on 8.0.1,
     *          where the class declares no size and MySQL would cut the rest
     */
    private const TEXT_COLUMN_BYTES = 65535;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_set_order_note',
            Somcp::t('Set the private note of an order'),
            'Sets the private note of one order - the Order note (Order private note on PrestaShop 9) of the '
                . 'Status tab of the order page, a text only the shop sees. The customer is never emailed and '
                . 'never sees it. The note given REPLACES the current one, exactly as the screen does: there is '
                . 'no append. To add to it, read the current note first with somcp_get_order (order.note) and '
                . 'send the whole new text. An empty note ("") clears it - only when the merchant asked for that. '
                . 'When the order already has this exact note, nothing is written (changed: false). One order '
                . 'per call. Not for writing to the customer (use somcp_add_order_message), nor for a note about '
                . 'the customer rather than this order (the customer tools). Not to be retried on a lost answer: '
                . 'read the order with somcp_get_order, whose order.note shows the saved text.',
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
                    'note' => [
                        'type' => 'string',
                        'description' => 'The whole new note, which replaces the current one. Plain text, line '
                            . 'breaks allowed. An empty string clears the note.',
                    ],
                ],
                'required' => ['note'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The note is saved from the order page, which takes the edit
            // right there.
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

        if (!array_key_exists('note', $arguments) || !is_string($arguments['note'])) {
            return SomcpTool::text('Nothing was changed: note must be a string, "" to clear the note.', true);
        }
        // The back office form trims what is typed, and spaces kept around the
        // text would make the same note look new.
        $note = trim($arguments['note']);

        $order = SomcpToolGetOrder::locate($arguments);
        if ($order === null) {
            return SomcpTool::text('No order of this shop matches what was given. Nothing was changed.', true);
        }

        // PrestaShop 9 declares the size of the field on the class and
        // validateFields() checks it; 8.0.1 declares none, and the text column
        // would silently cut a longer note. Read from the definition rather
        // than the version, so a release that adds the size is followed.
        $definition = Order::$definition['fields']['note'] ?? [];
        if (empty($definition['size']) && strlen($note) > self::TEXT_COLUMN_BYTES) {
            return SomcpTool::text(
                'Nothing was changed: the note is longer than the ' . self::TEXT_COLUMN_BYTES
                    . ' bytes this shop can store.',
                true
            );
        }

        $previous = (string) $order->note;

        // A retried call or a repeated instruction writes nothing, and fires no
        // hook, rather than recording a change that is not one.
        if ($previous === $note) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => $note === ''
                    ? 'The order has no note already. Nothing was written.'
                    : 'The order already has this note. Nothing was written.',
                'order' => self::identify($order),
                'note' => $note,
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($order, $previous, $note, $idEmployee): array {
            return self::apply($order, $previous, $note, $idEmployee);
        });
    }

    /**
     * Writes the note as the core's handler does.
     *
     * @param Order $order
     * @param string $previous
     * @param string $note
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, string $previous, string $note, int $idEmployee): array
    {
        $order->note = $note;

        // The core's own check before saving: on PrestaShop 9 it holds the
        // size of the field.
        $valid = $order->validateFields(false, true);
        if ($valid !== true) {
            return SomcpTool::text(
                'Nothing was changed: PrestaShop refused the note - ' . strip_tags((string) $valid),
                true
            );
        }

        try {
            $saved = (bool) $order->update();
        } catch (Throwable $e) {
            $saved = false;
        }

        if (!$saved) {
            SomcpLogger::log(
                'order_note_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'order ' . (int) $order->id,
                '',
                $idEmployee
            );

            return SomcpTool::text('The note could not be saved. Nothing was changed.', true);
        }

        SomcpLogger::log(
            'order_note_changed',
            SomcpLogger::OUTCOME_SUCCESS,
            'order ' . (int) $order->id . ($note === '' ? ', cleared' : ''),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'changed' => true,
            'order' => self::identify($order),
            'previous_note' => $previous,
            'note' => $note,
        ]);
    }

    /**
     * @param Order $order
     *
     * @return array<string, mixed>
     */
    private static function identify(Order $order): array
    {
        return [
            'id_order' => (int) $order->id,
            'reference' => (string) $order->reference,
        ];
    }
}
