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
use PrestaShop\PrestaShop\Adapter\Order\OrderAmountUpdater;
use PrestaShop\PrestaShop\Adapter\Order\OrderDetailUpdater;
use PrestaShop\PrestaShop\Adapter\Order\Refund\OrderProductRemover;

/*
 * somcp_set_order_carrier - the Carrier field of the Edit shipping details
 * window, on the Carriers tab of the order page.
 *
 * Changes the carrier of one shipment and lets the core recompute the shipping
 * cost and the totals. The core's UpdateOrderShippingDetailsHandler also saves
 * the tracking number of the same form and may email the customer, which is
 * somcp_set_order_tracking's job; so the tool runs the carrier part of that
 * handler only, in the same steps (cart delivery option, OrderCarrier, Order,
 * then OrderAmountUpdater) on the order's language, rather than calling it and
 * risking an email nobody asked for.
 *
 * The handler accepts any carrier identifier; the screen only offers the
 * carriers of Carrier::getCarriersForOrder() for the delivery zone and the
 * customer's groups, so the tool refuses anything else, with that list.
 *
 * Note on the shared helpers assumed by issue #127: identifiers are read by
 * SomcpTool::id(), like every order tool's; SomcpTool::refused() (#169, #170)
 * is not on develop yet, so the refusal is built here in the order tools'
 * shape until that issue lands.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// order lookup and the shipment mapping both live in that class.
require_once __DIR__ . '/SomcpToolGetOrder.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';

class SomcpToolSetOrderCarrier
{
    /** @var array<int, string> The arguments the tool reads; anything else is refused. */
    private const KEYS = ['id_order', 'reference', 'id_carrier', 'id_order_carrier'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_set_order_carrier',
            Somcp::t('Change the carrier of an order'),
            'Changes the carrier of one shipment of an order, like the Carrier field of Edit shipping details '
                . 'on the order page; PrestaShop recomputes the shipping cost and the total, so say the new '
                . 'total before the change. Only the carriers the order may use are taken. The tracking number '
                . 'is somcp_set_order_tracking, the address somcp_set_order_address. One shipment per call. '
                . 'Not to be retried on a lost answer: check shipping.shipments with somcp_get_order.',
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
                    'id_carrier' => [
                        'type' => 'integer',
                        'description' => 'A carrier the order may use, from somcp_list_carriers or the list a '
                            . 'refused call returns. Never guessed.',
                    ],
                    'id_order_carrier' => [
                        'type' => 'integer',
                        'description' => 'Which shipment, from shipping.shipments of somcp_get_order. Required '
                            . 'only when the order has more than one.',
                    ],
                ],
                'required' => ['id_carrier'],
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

        $problems = [];

        foreach (array_diff(array_keys($arguments), self::KEYS) as $unknown) {
            $problems[] = $unknown . ': not a field of this tool';
        }

        // Read like the other order tools read identifiers: a cast would turn
        // an array into 1 and put the parcel on a carrier nobody named.
        $idCarrier = array_key_exists('id_carrier', $arguments) ? SomcpTool::id($arguments, 'id_carrier') : 0;
        if ($idCarrier <= 0) {
            $problems[] = 'id_carrier: give the carrier identifier, a whole number';
        }

        $idOrderCarrier = null;
        if (array_key_exists('id_order_carrier', $arguments)) {
            $idOrderCarrier = SomcpTool::id($arguments, 'id_order_carrier');
            if ($idOrderCarrier <= 0) {
                $problems[] = 'id_order_carrier: give the shipment identifier, a whole number';
            }
        }

        // find() rather than the newest order of a reference: the shipping cost and the total change,
        // so a reference shared by the orders of a split cart is refused with
        // their list instead of landing on the newest of them.
        $order = SomcpToolGetOrder::find($arguments);
        if (!$order instanceof Order) {
            $problems[] = (array_key_exists('reference', $arguments) && (int) ($arguments['id_order'] ?? 0) <= 0
                ? 'reference: '
                : 'id_order: ') . $order;

            return self::refused($problems);
        }

        $idLang = (int) $order->id_lang;
        $shipments = SomcpToolGetOrder::shipments($order, $idLang);
        $shipment = null;
        if ($shipments === []) {
            $problems[] = 'id_order: this order has no shipment, so it has no carrier to change';
        } elseif ($idOrderCarrier === null) {
            // Only one to choose from: the back office has a single line too.
            if (count($shipments) === 1) {
                $shipment = $shipments[0];
            } else {
                $problems[] = 'id_order_carrier: required, this order has ' . count($shipments) . ' shipments';
                $problems[] = 'shipments of this order: ' . self::encode(self::summaries($shipments));
            }
        } elseif ($idOrderCarrier > 0) {
            foreach ($shipments as $candidate) {
                if ((int) $candidate['id_order_carrier'] === $idOrderCarrier) {
                    $shipment = $candidate;
                }
            }
            if ($shipment === null) {
                $problems[] = 'id_order_carrier: not a shipment of this order';
                $problems[] = 'shipments of this order: ' . self::encode(self::summaries($shipments));
            }
        }

        $cart = Cart::getCartByOrderId((int) $order->id);
        if (!$cart instanceof Cart || !Validate::isLoadedObject($cart)) {
            $problems[] = 'id_order: the cart of this order cannot be read, so its carrier cannot be changed';
            $cart = null;
        }

        if ($problems !== [] || $shipment === null || $cart === null) {
            return self::refused($problems);
        }

        $previousCarrierId = (int) $shipment['id_carrier'];

        // The core writes nothing when the carrier does not move; reporting it
        // as unchanged keeps a retried call from looking like a second change,
        // even when that carrier is no longer offered for this order.
        if ($previousCarrierId === $idCarrier) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The shipment already uses this carrier. Nothing was changed.',
                'order' => self::identify($order),
                'carrier' => self::carrier($idCarrier, $idLang),
                'shipment' => $shipment,
            ]);
        }

        // The choices of the Carrier select on screen; the handler alone would
        // accept a carrier that does not deliver this zone or these groups.
        $allowed = self::allowedCarriers($cart);
        if (!isset($allowed[$idCarrier])) {
            return self::refused([
                'id_carrier: not a carrier this order can use - find one with somcp_list_carriers',
                'carriers this order can use: ' . self::encode(array_values($allowed)),
            ]);
        }

        $orderCarrier = new OrderCarrier((int) $shipment['id_order_carrier']);
        if (!Validate::isLoadedObject($orderCarrier) || (int) $orderCarrier->id_order !== (int) $order->id) {
            return self::refused(['id_order_carrier: the shipment could not be read']);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($order, $cart, $orderCarrier, $idCarrier, $shipment, $idEmployee): array {
            return self::apply($order, $cart, $orderCarrier, $idCarrier, $shipment, $idEmployee);
        });
    }

    /**
     * Runs the carrier part of the core handler and reports the change.
     *
     * @param Order $order
     * @param Cart $cart
     * @param OrderCarrier $orderCarrier
     * @param int $idCarrier
     * @param array<string, mixed> $previousShipment
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, Cart $cart, OrderCarrier $orderCarrier, int $idCarrier, array $previousShipment, int $idEmployee): array
    {
        $config = new CoreConfiguration();
        $contextManager = new ContextStateManager(new LegacyContext());
        $amountUpdater = new OrderAmountUpdater(
            $config,
            $contextManager,
            new OrderDetailUpdater($contextManager, $config),
            new OrderProductRemover()
        );

        $idOrder = (int) $order->id;
        $previousCarrierId = (int) $orderCarrier->id_carrier;

        // The handler switches the context to the order's language for the
        // length of the change, and restores it whatever happens.
        $contextManager->setLanguage(new Language((int) $order->id_lang));

        try {
            // The same steps as UpdateOrderShippingDetailsHandler: the cart
            // first, so OrderAmountUpdater prices the shipping of the new
            // carrier, then the shipment and the order, then the amounts. The
            // trailing comma is the legacy delivery option format the core
            // builds with formatLegacyDeliveryOptionFromCarrierId().
            $cart->setDeliveryOption([
                (int) $cart->id_address_delivery => sprintf('%d,', $idCarrier),
            ]);
            $cart->save();

            $orderCarrier->id_carrier = $idCarrier;
            $orderCarrier->update();

            $order->id_carrier = $idCarrier;
            $amountUpdater->update($order, $cart);
        } catch (Throwable $e) {
            SomcpLogger::log(
                'order_set_carrier',
                SomcpLogger::OUTCOME_FAILURE,
                'order ' . $idOrder . ', shipment ' . (int) $orderCarrier->id,
                '',
                $idEmployee
            );

            // The core runs these steps without a transaction, so a failure
            // half-way may have kept some of them: say how to check rather
            // than claim nothing moved.
            return SomcpTool::text(
                'The carrier could not be changed. Read shipping.shipments with somcp_get_order before trying '
                    . 'again: part of the change may have been saved.',
                true
            );
        } finally {
            $contextManager->restorePreviousContext();
        }

        $order = new Order($idOrder);
        $idLang = (int) $order->id_lang;
        $currency = new Currency((int) $order->id_currency);

        $shipment = null;
        foreach (SomcpToolGetOrder::shipments($order, $idLang) as $candidate) {
            if ((int) $candidate['id_order_carrier'] === (int) $orderCarrier->id) {
                $shipment = $candidate;
            }
        }

        SomcpLogger::log(
            'order_set_carrier',
            SomcpLogger::OUTCOME_SUCCESS,
            'order ' . $idOrder . ', shipment ' . (int) $orderCarrier->id . ', carrier ' . $previousCarrierId
                . ' to ' . $idCarrier,
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'changed' => true,
            'order' => self::identify($order),
            'previous_carrier' => self::carrier($previousCarrierId, $idLang),
            'carrier' => self::carrier($idCarrier, $idLang),
            'previous_shipment' => $previousShipment,
            'shipment' => $shipment,
            // The order totals after the change, named as totals of
            // somcp_get_order: the shipping cost follows the carrier.
            'shipping_tax_excluded' => (float) $order->total_shipping_tax_excl,
            'shipping_tax_included' => (float) $order->total_shipping_tax_incl,
            'total_tax_excluded' => (float) $order->total_paid_tax_excl,
            'total_tax_included' => (float) $order->total_paid_tax_incl,
            'currency' => Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '',
        ]);
    }

    /**
     * The carriers the Carrier select offers for this order, by identifier,
     * built as CarrierForOrderChoiceProvider builds them and named as
     * somcp_list_carriers names them.
     *
     * @param Cart $cart
     *
     * @return array<int, array<string, mixed>>
     */
    private static function allowedCarriers(Cart $cart): array
    {
        $address = new Address((int) $cart->id_address_delivery);
        $groups = Customer::getGroupsStatic((int) $cart->id_customer);

        try {
            $rows = Carrier::getCarriersForOrder(Address::getZoneById((int) $address->id), $groups, $cart);
        } catch (Throwable $e) {
            $rows = [];
        }

        $allowed = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id_carrier'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $allowed[$id] = [
                'id_carrier' => $id,
                'name' => SomcpTool::carrierName((string) ($row['name'] ?? '')),
                'delay' => (string) ($row['delay'] ?? ''),
            ];
        }

        return $allowed;
    }

    /**
     * A carrier under the keys of somcp_list_carriers.
     *
     * @param int $idCarrier
     * @param int $idLang
     *
     * @return array<string, mixed>
     */
    private static function carrier(int $idCarrier, int $idLang): array
    {
        $carrier = new Carrier($idCarrier, $idLang);

        return [
            'id_carrier' => $idCarrier,
            'name' => Validate::isLoadedObject($carrier) ? SomcpTool::carrierName((string) $carrier->name) : '',
        ];
    }

    /**
     * What tells the shipments of an order apart, for the merchant to pick one,
     * as somcp_set_order_tracking lists them.
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
     * @param array<int|string, mixed> $value
     *
     * @return string
     */
    private static function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
     * The order tools' refusal: every problem at once, nothing changed.
     *
     * Kept here, in the wording issue #127 asks for, until SomcpTool::refused()
     * of #169 and #170 unifies the order tools on one helper.
     *
     * @param string[] $problems
     *
     * @return array<string, mixed>
     */
    private static function refused(array $problems): array
    {
        return SomcpTool::text("Nothing was changed.\n- " . implode("\n- ", $problems), true);
    }
}
