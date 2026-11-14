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
            'Sets the tracking number of one shipment, like the Tracking number field of Edit shipping '
                . 'details on the Carriers tab of the order page. A new number emails the customer and tells '
                . 'the carrier modules: tell the merchant first. An empty number clears it without email, only '
                . 'if the merchant asked. The carrier and the status are not changed (shipped is '
                . 'somcp_set_order_status). One shipment per call. Not to be retried on a lost answer: a second '
                . 'call could email the customer twice - check shipping.shipments with somcp_get_order.',
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

        // Every problem in one answer, each named by its field, as the other
        // tools answer: a wrong number and a wrong order are fixed in one
        // call, and a misspelt field is named rather than ignored.
        $problems = SomcpTool::unknownFields(
            $arguments,
            ['id_order', 'reference', 'tracking_number', 'id_order_carrier']
        );

        $tracking = '';
        if (!array_key_exists('tracking_number', $arguments) || !is_string($arguments['tracking_number'])) {
            $problems[] = 'tracking_number: a string, "" to clear the number';
        } else {
            // Spaces around a pasted number are not part of it, and kept they
            // would make the same number look new and email the customer again.
            $tracking = trim($arguments['tracking_number']);

            if (Tools::strlen($tracking) > self::MAX_LENGTH) {
                $problems[] = 'tracking_number: ' . self::MAX_LENGTH . ' characters at most';
            } elseif ($tracking !== '' && !Validate::isTrackingNumber($tracking)) {
                // The core's only check, the one the back office applies:
                // carriers have no format of their own in PrestaShop.
                $problems[] = 'tracking_number: a character PrestaShop refuses; allowed are letters, digits, '
                    . 'spaces and ~ : # , % & _ = ( ) [ ] . ? + - @ /';
            }
        }

        // find() rather than the newest order of a reference: the number emails the customer, so a
        // reference shared by the orders of a split cart is refused with their
        // list instead of landing on the newest of them.
        $order = SomcpToolGetOrder::find($arguments);
        if (!$order instanceof Order) {
            $problems[] = $order;

            return SomcpTool::refused('changed', $problems);
        }

        $idLang = (int) $order->id_lang;
        $shipments = SomcpToolGetOrder::shipments($order, $idLang);
        $shipment = null;
        if ($shipments === []) {
            $problems[] = 'id_order: this order has no shipment, so it has no tracking number to set';
        } else {
            $shipment = self::pick($shipments, $arguments);
            if ($shipment === null) {
                $listed = json_encode(self::summaries($shipments), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!array_key_exists('id_order_carrier', $arguments)) {
                    $problems[] = 'id_order_carrier: this order has several shipments, say which one; its shipments: '
                        . $listed;
                } elseif (SomcpTool::id($arguments, 'id_order_carrier') === 0) {
                    $problems[] = SomcpTool::notAnIdentifier('id_order_carrier');
                } else {
                    $problems[] = 'id_order_carrier: no shipment of this order has the identifier '
                        . SomcpTool::id($arguments, 'id_order_carrier') . ' - find it with somcp_get_order; its shipments: '
                        . $listed;
                }
            }
        }

        if ($problems !== [] || $shipment === null) {
            return SomcpTool::refused('changed', $problems);
        }

        $orderCarrier = new OrderCarrier((int) $shipment['id_order_carrier']);
        if (!Validate::isLoadedObject($orderCarrier) || (int) $orderCarrier->id_order !== (int) $order->id) {
            return SomcpTool::refused('changed', ['id_order_carrier: the shipment could not be read']);
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

        $idOrderCarrier = SomcpTool::id($arguments, 'id_order_carrier');
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
}
