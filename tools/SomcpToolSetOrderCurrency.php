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
 * somcp_set_order_currency - Change currency in the Payment block of the order
 * page, shown only while the order is not validated.
 *
 * The sequence is ChangeOrderCurrencyHandler::handle(), identical on 8.0.1
 * (lines 53 to 73) and 9.0.0 (lines 55 to 75): every amount of the order
 * lines, the order carrier, each invoice and the order converted with
 * Tools::convertPriceFull(), then the cart's and the order's currency. The
 * handler only uses core objects, so it is reproduced here rather than called:
 * its command bus is not reachable the same way on both versions. The core
 * fires no hook on this path, and neither does the tool.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// order lookup and the totals live in the first, the currency reader the cart
// rule tools use in the second.
require_once __DIR__ . '/SomcpToolGetOrder.php';
require_once __DIR__ . '/SomcpToolCreateCartRule.php';

class SomcpToolSetOrderCurrency
{
    /** @var string[] The arguments the tool takes, so a misspelt one is refused by name. */
    private const KEYS = ['id_order', 'reference', 'currency'];

    /** @var string[] The amounts of an order line the core converts. */
    private const DETAIL_FIELDS = [
        'ecotax',
        'product_price',
        // The core lists reduction_amount twice and so converts it twice; it is
        // converted once here, or the line's reduction would no longer match
        // its tax included and excluded parts.
        'reduction_amount',
        'reduction_amount_tax_incl',
        'reduction_amount_tax_excl',
        'total_shipping_price_tax_excl',
        'total_shipping_price_tax_incl',
        'total_price_tax_incl',
        'total_price_tax_excl',
        'product_quantity_discount',
        'purchase_supplier_price',
        'unit_price_tax_incl',
        'unit_price_tax_excl',
        'original_product_price',
    ];

    /** @var string[] The amounts the core converts on the order and on each invoice alike. */
    private const SHARED_FIELDS = [
        'total_discounts',
        'total_discounts_tax_incl',
        'total_discounts_tax_excl',
        'total_discount_tax_excl',
        'total_discount_tax_incl',
        'total_paid',
        'total_paid_tax_incl',
        'total_paid_tax_excl',
        'total_paid_real',
        'total_products',
        'total_products_wt',
        'total_shipping',
        'total_shipping_tax_incl',
        'total_shipping_tax_excl',
        'total_wrapping',
        'total_wrapping_tax_incl',
        'total_wrapping_tax_excl',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_set_order_currency',
            Somcp::t('Change the currency of an order'),
            'Changes the currency of one order that is not validated yet, like Change currency in the Payment '
                . 'block of the order page: every amount of the order, its lines, its shipping and its invoices '
                . 'is converted at the exchange rates the shop has today. A validated order is refused, as the '
                . 'back office refuses it; the exchange rates themselves are not set here. One order per call. On '
                . 'a lost answer do not retry: read currency and totals with somcp_get_order.',
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
                    'currency' => [
                        'type' => 'string',
                        'description' => 'ISO code of an active currency of the shop (EUR, USD), as somcp_get_order '
                            . 'returns currency. The currency the order already has changes nothing.',
                    ],
                ],
                'required' => ['currency'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Change currency sits on the order page, and the back office takes
            // the edit right there to save it.
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

        array_push($problems, ...SomcpTool::unknownFields($arguments, self::KEYS));

        $order = SomcpToolGetOrder::find($arguments);
        $idShop = $order instanceof Order ? (int) $order->id_shop : Somcp::currentShopId();
        if (!$order instanceof Order) {
            $problems[] = (array_key_exists('reference', $arguments) && SomcpTool::id($arguments, 'id_order') <= 0
                ? 'reference' : 'id_order') . ': ' . $order;
        }

        $idCurrency = 0;
        if (!array_key_exists('currency', $arguments)) {
            $problems[] = 'currency: give the ISO code of the new currency' . self::activeList();
        } else {
            $errors = [];
            $idCurrency = SomcpToolCreateCartRule::activeCurrency($arguments['currency'], $idShop, 'currency', $errors);
            foreach ($errors as $error) {
                // The shop's active currencies spare a second call to find a code that works.
                $problems[] = $error . self::activeList();
            }
        }

        if ($problems !== [] || !$order instanceof Order || $idCurrency <= 0) {
            return SomcpTool::refused('changed', $problems);
        }

        $oldCurrency = new Currency((int) $order->id_currency);
        $newCurrency = new Currency($idCurrency);
        $totalsBefore = SomcpToolGetOrder::totals($order);

        // The currency the order already has writes nothing, where the core
        // refuses it: a call repeated after a lost answer stays harmless.
        if ((int) $order->id_currency === $idCurrency) {
            return self::answer(false, $order, $oldCurrency, $newCurrency, $totalsBefore, $totalsBefore);
        }

        if ((bool) $order->valid) {
            return SomcpTool::refused('changed', [
                'id_order: You cannot change the currency. The order is validated: its amounts are final.',
            ]);
        }

        if (!Validate::isLoadedObject($oldCurrency)) {
            return SomcpTool::refused('changed', ['id_order: the current currency of this order no longer exists in the shop']);
        }

        try {
            self::convert($order, $oldCurrency, $newCurrency);
        } catch (Throwable $e) {
            SomcpLogger::log(
                'order_currency_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'order ' . (int) $order->id . ', ' . $oldCurrency->iso_code . ' to ' . $newCurrency->iso_code,
                '',
                $idEmployee
            );

            return SomcpTool::refused('changed', [
                'currency: the currency could not be changed completely - read the order with somcp_get_order',
            ]);
        }

        SomcpLogger::log(
            'order_currency_changed',
            SomcpLogger::OUTCOME_SUCCESS,
            'order ' . (int) $order->id . ', ' . $oldCurrency->iso_code . ' to ' . $newCurrency->iso_code,
            '',
            $idEmployee
        );

        // Read again so the answer shows what was stored, not what was meant.
        $saved = new Order((int) $order->id);

        return self::answer(true, $saved, $oldCurrency, $newCurrency, $totalsBefore, SomcpToolGetOrder::totals($saved));
    }

    /**
     * The core's sequence, in its order: lines, carrier, invoices, cart, order.
     *
     * @param Order $order
     * @param Currency $old
     * @param Currency $new
     *
     * @return void
     */
    private static function convert(Order $order, Currency $old, Currency $new): void
    {
        foreach ($order->getOrderDetailList() as $row) {
            $detail = new OrderDetail((int) $row['id_order_detail']);
            self::convertFields($detail, self::DETAIL_FIELDS, $old, $new);
            $detail->update();
            $detail->updateTaxAmount($order);
        }

        $idOrderCarrier = (int) $order->getIdOrderCarrier();
        if ($idOrderCarrier > 0) {
            $carrier = new OrderCarrier($idOrderCarrier);
            $carrier->shipping_cost_tax_excl = (float) Tools::convertPriceFull($carrier->shipping_cost_tax_excl, $old, $new);
            $carrier->shipping_cost_tax_incl = (float) Tools::convertPriceFull($carrier->shipping_cost_tax_incl, $old, $new);
            $carrier->update();
        }

        foreach ($order->getInvoicesCollection() as $invoice) {
            self::convertFields($invoice, self::SHARED_FIELDS, $old, $new);
            $invoice->save();
        }

        $cart = new Cart((int) $order->id_cart);
        if (Validate::isLoadedObject($cart)) {
            $cart->id_currency = (int) $new->id;
            $cart->update();
        }

        self::convertFields($order, self::SHARED_FIELDS, $old, $new);
        $order->id_currency = (int) $new->id;
        $order->conversion_rate = (float) $new->conversion_rate;
        $order->update();
    }

    /**
     * @param ObjectModel $object
     * @param string[] $fields
     * @param Currency $old
     * @param Currency $new
     *
     * @return void
     */
    private static function convertFields(ObjectModel $object, array $fields, Currency $old, Currency $new): void
    {
        foreach ($fields as $field) {
            // Not every field exists on every model (an invoice has no
            // total_paid_real), which the core skips the same way.
            if (isset($object->{$field})) {
                $object->{$field} = Tools::convertPriceFull($object->{$field}, $old, $new);
            }
        }
    }

    /**
     * The shop's active currencies, appended to a refusal.
     *
     * @return string
     */
    private static function activeList(): string
    {
        $codes = [];
        foreach (Currency::getCurrencies(false, true) as $currency) {
            $codes[] = (string) $currency['iso_code'];
        }

        return $codes === [] ? '' : ' - the active currencies of the shop: ' . implode(', ', $codes);
    }

    /**
     * @param bool $changed
     * @param Order $order
     * @param Currency $old
     * @param Currency $new
     * @param array<string, float> $before
     * @param array<string, float> $after
     *
     * @return array<string, mixed>
     */
    private static function answer(bool $changed, Order $order, Currency $old, Currency $new, array $before, array $after): array
    {
        return SomcpTool::json([
            'changed' => $changed,
            'order' => [
                'id_order' => (int) $order->id,
                'reference' => (string) $order->reference,
            ],
            'old_currency' => (string) $old->iso_code,
            'new_currency' => (string) $new->iso_code,
            'totals_before' => $before,
            'totals_after' => $after,
        ]);
    }
}
