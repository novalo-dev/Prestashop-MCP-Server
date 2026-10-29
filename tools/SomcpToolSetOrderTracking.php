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
 * somcp_set_order_tracking - the Tracking number field of the Edit shipping
 * details window, on the Carriers tab of the order page.
 *
 * The number belongs to one shipment (order_carrier), not to the order: an
 * order can have several, and the tab lists one line each. So the shipment is
 * named when there is more than one, and the call is refused with the list
 * rather than writing on whichever one comes first.
 *
 * The carrier field of the same window is left out on purpose. Changing it
 * recalculates the shipping cost and the order totals, which is another
 * decision with another risk than typing a parcel number.
 *
 * The sequence is the core's own, read from UpdateOrderShippingDetailsHandler
 * on 8.0.1 and 9.0.0, where it is identical: check the number with
 * Validate::isTrackingNumber(), save the OrderCarrier, then, only when the
 * number is new and not empty, send the in_transit email and fire
 * actionAdminOrdersTrackingNumberUpdate for the order's shop, so carrier
 * modules see what they see from the back office.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// order lookup and the reading of shipments live in that class, and the
// employee on the context in the other.
require_once __DIR__ . '/SomcpToolGetOrder.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';

class SomcpToolSetOrderTracking
{
    /** @var int The size of order_carrier.tracking_number on PrestaShop 8 and 9. */
    private const MAX_LENGTH = 64;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_set_order_tracking',
            Somcp::t('Set the tracking number of an order'),
            'Sets the tracking number of one shipment of one order, like the Tracking number field of the '
                . 'Edit shipping details window on the Carriers tab of the order page. A new number that is not '
                . 'empty emails the customer the in_transit message (with the tracking link when the carrier has '
                . 'one) and tells the carrier modules, as the back office does: tell the merchant before calling. '
                . 'An empty tracking_number ("") clears the number and sends no email - only send it when the '
                . 'merchant asked for the number to be removed. The carrier of the shipment is never changed, '
                . 'and the order status is not touched: marking the order as shipped is somcp_set_order_status, '
                . 'a separate decision. An order with several shipments needs id_order_carrier; without it the '
                . 'call is refused with the list of shipments. When the shipment already has this number, '
                . 'nothing is written and nobody is emailed (changed: false). One shipment per call. Not to be '
                . 'retried on a lost answer: a second call could email the customer twice - read the order with '
                . 'somcp_get_order, whose shipping.shipments show the number once saved.',
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
                    'tracking_number' => [
                        'type' => 'string',
                        'description' => 'The tracking number, 64 characters at most: letters, digits, spaces '
                            . 'and ~ : # , % & _ = ( ) [ ] . ? + - @ /. An empty string clears the number '
                            . 'without emailing anyone.',
                    ],
                    'id_order_carrier' => [
                        'type' => 'integer',
                        'description' => 'Which shipment, from shipping.shipments of somcp_get_order. Required '
                            . 'only when the order has more than one.',
                    ],
                ],
                'required' => ['tracking_number'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The Edit shipping details window sits on the order page, and
            // saving it takes the edit right there.
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

        if (!array_key_exists('tracking_number', $arguments) || !is_string($arguments['tracking_number'])) {
            return SomcpTool::text(
                'Nothing was changed: tracking_number must be a string, "" to clear the number.',
                true
            );
        }
        // Spaces around a pasted number are not part of it, and kept they
        // would make the same number look new and email the customer again.
        $tracking = trim($arguments['tracking_number']);

        if (Tools::strlen($tracking) > self::MAX_LENGTH) {
            return SomcpTool::text(
                'Nothing was changed: a tracking number holds ' . self::MAX_LENGTH . ' characters at most.',
                true
            );
        }
        // The core's only check, the one the back office applies: carriers
        // have no format of their own in PrestaShop.
        if ($tracking !== '' && !Validate::isTrackingNumber($tracking)) {
            return SomcpTool::text(
                'Nothing was changed: the tracking number holds a character PrestaShop refuses. Allowed: letters, '
                    . 'digits, spaces and ~ : # , % & _ = ( ) [ ] . ? + - @ /.',
                true
            );
        }

        $order = SomcpToolGetOrder::locate($arguments);
        if ($order === null) {
            return SomcpTool::text('No order of this shop matches what was given. Nothing was changed.', true);
        }

        $idLang = (int) $order->id_lang;
        $shipments = SomcpToolGetOrder::shipments($order, $idLang);
        if ($shipments === []) {
            return SomcpTool::text(
                'This order has no shipment, so it has no tracking number to set. Nothing was changed.',
                true
            );
        }

        $shipment = self::pick($shipments, $arguments);
        if ($shipment === null) {
            return SomcpTool::text(
                (array_key_exists('id_order_carrier', $arguments)
                    ? 'This id_order_carrier is not a shipment of this order.'
                    : 'This order has several shipments: say which one with id_order_carrier.')
                    . ' Nothing was changed. Its shipments: '
                    . json_encode(self::summaries($shipments), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                true
            );
        }

        $orderCarrier = new OrderCarrier((int) $shipment['id_order_carrier']);
        if (!Validate::isLoadedObject($orderCarrier) || (int) $orderCarrier->id_order !== (int) $order->id) {
            return SomcpTool::text('The shipment could not be read. Nothing was changed.', true);
        }

        $previous = (string) $orderCarrier->tracking_number;

        // The guard against a retried call emailing the customer twice: the
        // core only mails a number that differs, and nothing is written either.
        if ($previous === $tracking) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => $tracking === ''
                    ? 'The shipment has no tracking number already. Nothing was written.'
                    : 'The shipment already has this tracking number. Nothing was written and no email was sent.',
                'order' => self::identify($order),
                'id_order_carrier' => (int) $orderCarrier->id,
                'carrier' => (string) $shipment['carrier'],
                'tracking_number' => $tracking,
                'tracking_url' => (string) $shipment['tracking_url'],
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($order, $orderCarrier, $previous, $tracking, $idEmployee): array {
            return self::apply($order, $orderCarrier, $previous, $tracking, $idEmployee);
        });
    }

    /**
     * Saves the number, then mails and notifies as the core does.
     *
     * @param Order $order
     * @param OrderCarrier $orderCarrier
     * @param string $previous
     * @param string $tracking
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, OrderCarrier $orderCarrier, string $previous, string $tracking, int $idEmployee): array
    {
        $idLang = (int) $order->id_lang;

        // The core switches the context to the order's language for the length
        // of the change, so the email and the links in it are in the
        // customer's language rather than the one the call came in.
        $legacy = Somcp::legacyContext();
        $previousLanguage = $legacy->language;
        $language = new Language($idLang);
        if (Validate::isLoadedObject($language)) {
            $legacy->language = $language;
        }

        try {
            $orderCarrier->tracking_number = $tracking;

            try {
                $saved = $orderCarrier->update();
            } catch (Throwable $e) {
                $saved = false;
            }

            if (!$saved) {
                SomcpLogger::log(
                    'order_tracking_failed',
                    SomcpLogger::OUTCOME_FAILURE,
                    'order ' . (int) $order->id . ', shipment ' . (int) $orderCarrier->id,
                    '',
                    $idEmployee
                );

                return SomcpTool::text(
                    'The tracking number could not be saved. Nothing was changed and no email was sent.',
                    true
                );
            }

            $sent = false;
            if ($tracking !== '') {
                try {
                    $sent = (bool) $orderCarrier->sendInTransitEmail($order);
                } catch (Throwable $e) {
                    // Thrown when the customer, the carrier or the address
                    // cannot be loaded: the number is saved all the same.
                    $sent = false;
                }

                // Fired only once the email has left, as in the core, where a
                // failed email ends the handler before the hook.
                if ($sent) {
                    Hook::exec('actionAdminOrdersTrackingNumberUpdate', [
                        'order' => $order,
                        'customer' => new Customer((int) $order->id_customer),
                        'carrier' => new Carrier((int) $order->id_carrier, $idLang),
                    ], null, false, true, false, (int) $order->id_shop);
                }
            }
        } finally {
            $legacy->language = $previousLanguage;
        }

        $carrier = new Carrier((int) $orderCarrier->id_carrier, $idLang);
        $url = Validate::isLoadedObject($carrier) ? (string) $carrier->url : '';
        // sendInTransitEmail() answers true without sending anything when the
        // order's carrier has no tracking address, so true alone does not mean
        // the customer heard of it.
        $mailCarrier = new Carrier((int) $order->id_carrier, $idLang);
        $notified = $sent && Validate::isLoadedObject($mailCarrier) && (string) $mailCarrier->url !== '';
        $failed = $tracking !== '' && !$sent;

        SomcpLogger::log(
            'order_tracking_changed',
            $failed ? SomcpLogger::OUTCOME_FAILURE : SomcpLogger::OUTCOME_SUCCESS,
            'order ' . (int) $order->id . ', shipment ' . (int) $orderCarrier->id . ($failed ? ', email failed' : ''),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'changed' => true,
            'order' => self::identify($order),
            'id_order_carrier' => (int) $orderCarrier->id,
            'carrier' => Validate::isLoadedObject($carrier) ? (string) $carrier->name : '',
            'previous_tracking_number' => $previous,
            'tracking_number' => $tracking,
            'tracking_url' => $url !== '' && $tracking !== '' ? str_replace('@', $tracking, $url) : '',
            'customer_notified' => $notified,
            // Reported apart from the change, which did happen, so the
            // assistant does not retry it to get the email out.
            'warning' => $failed
                ? 'The tracking number was saved but the customer email could not be sent. Do not repeat this '
                    . 'call: the change is already recorded.'
                : null,
        ]);
    }

    /**
     * The shipment the call is about, or null when it cannot be told.
     *
     * @param array<int, array<string, mixed>> $shipments
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>|null
     */
    private static function pick(array $shipments, array $arguments): ?array
    {
        if (!array_key_exists('id_order_carrier', $arguments)) {
            // Only one to choose from: the back office has a single line too.
            return count($shipments) === 1 ? $shipments[0] : null;
        }

        $idOrderCarrier = self::identifier($arguments['id_order_carrier']);
        foreach ($shipments as $shipment) {
            if ($idOrderCarrier > 0 && (int) $shipment['id_order_carrier'] === $idOrderCarrier) {
                return $shipment;
            }
        }

        return null;
    }

    /**
     * What tells the shipments of an order apart, for the merchant to pick one.
     *
     * @param array<int, array<string, mixed>> $shipments
     *
     * @return array<int, array<string, mixed>>
     */
    private static function summaries(array $shipments): array
    {
        $summaries = [];
        foreach ($shipments as $shipment) {
            $summaries[] = [
                'id_order_carrier' => (int) $shipment['id_order_carrier'],
                'date' => (string) $shipment['date'],
                'carrier' => (string) $shipment['carrier'],
                'weight' => (float) $shipment['weight'],
                'tracking_number' => (string) $shipment['tracking_number'],
            ];
        }

        return $summaries;
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

    /**
     * An identifier as a client may send it: an integer, or digits in a string.
     *
     * A cast would read an array as 1 and write on a shipment nobody named.
     *
     * @param mixed $value
     *
     * @return int
     */
    private static function identifier($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d{1,10}$/', trim($value))) {
            return (int) trim($value);
        }

        return 0;
    }
}
