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
 * somcp_get_order - everything about one order.
 *
 * It takes an identifier or a reference and nothing else. Giving it the filters
 * of the search tool would let an assistant ask for "Dupont's March orders" and
 * leave the module to pick one of twelve - so finding is one tool and reading is
 * another, and an assistant that is unsure searches first.
 *
 * Read through the core objects here, not through a query: one order costs
 * little to load, and Order, Customer and Address already know how to assemble
 * what a merchant would see in the back office.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetOrder
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_order',
            Somcp::t('Get an order'),
            'Returns everything about one order of this shop, as its page in the back office shows '
                . 'it: the order note, the cart it came from, gift wrapping and gift message, recycled '
                . 'packaging; the customer, how to reach them, their valid orders and total spent, and '
                . 'the private note on them; both addresses; every product line with its quantity, '
                . 'prices, tax rate, identifiers (EAN-13, ISBN, UPC, MPN, supplier reference), stock '
                . 'location, quantity in stock now, refunded and returned quantities and '
                . 'customizations; discounts; each shipment with its carrier, weight, cost and '
                . 'tracking number; payments received; the current status and how it got there; the '
                . 'invoices, delivery slips and credit slips it produced; the messages exchanged, each '
                . 'saying whether the shop or the customer wrote it; and the totals. Identify the order '
                . 'by its identifier or its reference. '
                . 'Do not use it to look for orders - somcp_search_orders finds them, and this '
                . 'tool reads one you have already identified.',
            [
                'type' => 'object',
                'properties' => [
                    'id_order' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the order to read.',
                    ],
                    'reference' => [
                        'type' => 'string',
                        'description' => 'Reference of the order to read, used when no identifier is known.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_READ],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminOrders')
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
        $order = self::locate($arguments);
        if ($order === null) {
            return SomcpTool::text('No order of this shop matches what was given.', true);
        }

        $idLang = (int) $order->id_lang;
        $currency = new Currency((int) $order->id_currency);
        $customer = new Customer((int) $order->id_customer);

        return SomcpTool::json([
            'order' => [
                'id_order' => (int) $order->id,
                'reference' => (string) $order->reference,
                'date_add' => (string) $order->date_add,
                'valid' => (bool) $order->valid,
                'currency' => (string) $currency->iso_code,
                'language_id' => $idLang,
                'payment_method' => (string) $order->payment,
                'status' => self::status($order, $idLang),
                // The Order note of the Status tab, written by the shop.
                'note' => (string) $order->note,
                'id_cart' => (int) $order->id_cart,
                'gift' => (bool) $order->gift,
                'gift_message' => (string) $order->gift_message,
                'recycled_packaging' => (bool) $order->recyclable,
            ],
            'customer' => self::customer($customer, $order, $idLang, (string) $currency->iso_code),
            'addresses' => [
                'delivery' => self::address((int) $order->id_address_delivery, $idLang),
                'invoice' => self::address((int) $order->id_address_invoice, $idLang),
            ],
            'products' => self::products($order, $idLang),
            'discounts' => self::discounts($order),
            'shipping' => self::shipping($order, $idLang),
            'totals' => self::totals($order),
            'payments' => self::payments($order),
            'history' => self::history($order, $idLang),
            'documents' => self::documents($order, $idLang),
            'messages' => self::messages($order),
        ]);
    }

    /**
     * Finds the order, and only within the shop the token belongs to.
     *
     * Public so the order tools that write find an order exactly as this one
     * reads it.
     *
     * @param array<string, mixed> $arguments
     *
     * @return Order|null
     */
    public static function locate(array $arguments): ?Order
    {
        $idShop = Somcp::currentShopId();
        $idOrder = (int) ($arguments['id_order'] ?? 0);

        if ($idOrder <= 0 && !empty($arguments['reference'])) {
            $idOrder = (int) Db::getInstance()->getValue(
                'SELECT `id_order` FROM `' . _DB_PREFIX_ . "orders`
                 WHERE `reference` = '" . pSQL((string) $arguments['reference']) . "'
                   AND `id_shop` = " . $idShop . '
                 ORDER BY `id_order` DESC'
            );
        }

        if ($idOrder <= 0) {
            return null;
        }

        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order) || (int) $order->id_shop !== $idShop) {
            return null;
        }

        return $order;
    }

    /**
     * @param Order $order
     * @param int $idLang
     *
     * @return array<string, mixed>
     */
    private static function status(Order $order, int $idLang): array
    {
        $state = new OrderState((int) $order->current_state, $idLang);

        return [
            'id' => (int) $order->current_state,
            'name' => Validate::isLoadedObject($state) ? (string) $state->name : '',
            'paid' => Validate::isLoadedObject($state) ? (bool) $state->paid : false,
            'shipped' => Validate::isLoadedObject($state) ? (bool) $state->shipped : false,
        ];
    }

    /**
     * @param Customer $customer
     * @param Order $order
     * @param int $idLang
     * @param string $currency ISO code of the order's currency
     *
     * @return array<string, mixed>
     */
    private static function customer(Customer $customer, Order $order, int $idLang, string $currency): array
    {
        if (!Validate::isLoadedObject($customer)) {
            // A guest order, or an account deleted since.
            return ['id_customer' => 0];
        }

        $gender = new Gender((int) $customer->id_gender, $idLang);
        // The figures of the customer block of the order page, from the same
        // core method: valid orders, and their total converted from each
        // order's rate into the default currency, then into this order's.
        $stats = $customer->getStats();

        return [
            'id_customer' => (int) $customer->id,
            'title' => Validate::isLoadedObject($gender) ? (string) $gender->name : '',
            'firstname' => (string) $customer->firstname,
            'lastname' => (string) $customer->lastname,
            'email' => (string) $customer->email,
            'company' => (string) $customer->company,
            'is_guest' => (bool) $customer->is_guest,
            'newsletter' => (bool) $customer->newsletter,
            'date_add' => (string) $customer->date_add,
            'valid_orders_count' => (int) ($stats['nb_orders'] ?? 0),
            'total_spent' => (float) Tools::convertPrice((float) ($stats['total_orders'] ?? 0), (int) $order->id_currency),
            'total_spent_currency' => $currency,
            // Read only here: it is on the order page, but writing it is the
            // business of the customer tools.
            'private_note' => (string) $customer->note,
        ];
    }

    /**
     * @param int $idAddress
     * @param int $idLang
     *
     * @return array<string, mixed>
     */
    private static function address(int $idAddress, int $idLang): array
    {
        $address = new Address($idAddress);
        if (!Validate::isLoadedObject($address)) {
            return [];
        }

        $country = new Country((int) $address->id_country, $idLang);

        return [
            'firstname' => (string) $address->firstname,
            'lastname' => (string) $address->lastname,
            'company' => (string) $address->company,
            'address1' => (string) $address->address1,
            'address2' => (string) $address->address2,
            'postcode' => (string) $address->postcode,
            'city' => (string) $address->city,
            'country' => Validate::isLoadedObject($country) ? (string) $country->name : '',
            'phone' => (string) $address->phone,
            'phone_mobile' => (string) $address->phone_mobile,
            'vat_number' => (string) $address->vat_number,
            'other' => (string) $address->other,
        ];
    }

    /**
     * @param Order $order
     * @param int $idLang
     *
     * @return array<int, array<string, mixed>>
     */
    private static function products(Order $order, int $idLang): array
    {
        $lines = [];
        // getProducts() already adds what the Products table of the order page
        // shows beside order_detail: the stock location and the quantity in
        // stock now (current_stock), and the customizations of the line.
        foreach ($order->getProducts() as $product) {
            $lines[] = [
                'id_product' => (int) $product['product_id'],
                'id_product_attribute' => (int) $product['product_attribute_id'],
                'reference' => (string) $product['product_reference'],
                'supplier_reference' => (string) $product['product_supplier_reference'],
                // Strings: an EAN-13 can start with zeros, and the column is
                // wider on PrestaShop 9 than on 8.
                'ean13' => (string) $product['product_ean13'],
                'isbn' => (string) $product['product_isbn'],
                'upc' => (string) $product['product_upc'],
                'mpn' => (string) ($product['product_mpn'] ?? ''),
                'name' => (string) $product['product_name'],
                'quantity' => (int) $product['product_quantity'],
                'quantity_refunded' => (int) $product['product_quantity_refunded'],
                'quantity_returned' => (int) $product['product_quantity_return'],
                'refunded_tax_excluded' => (float) ($product['total_refunded_tax_excl'] ?? 0),
                'refunded_tax_included' => (float) ($product['total_refunded_tax_incl'] ?? 0),
                // Now, not product_quantity_in_stock, which is the quantity at
                // the time of the order.
                'quantity_in_stock_now' => (int) ($product['current_stock'] ?? 0),
                'stock_location' => (string) ($product['location'] ?? ''),
                'tax_name' => (string) $product['tax_name'],
                'tax_rate' => (float) $product['tax_rate'],
                'unit_price_tax_excluded' => (float) $product['unit_price_tax_excl'],
                'unit_price_tax_included' => (float) $product['unit_price_tax_incl'],
                'total_tax_excluded' => (float) $product['total_price_tax_excl'],
                'total_tax_included' => (float) $product['total_price_tax_incl'],
                'customizations' => self::customizations($product),
            ];
        }

        return $lines;
    }

    /**
     * The customizations of one product line, flattened.
     *
     * The core nests them by delivery address, customization and field type;
     * an assistant only needs which field received which value.
     *
     * @param array<string, mixed> $product a line of Order::getProducts()
     *
     * @return array<int, array<string, mixed>>
     */
    private static function customizations(array $product): array
    {
        $customizations = [];
        if (empty($product['customizedDatas']) || !is_array($product['customizedDatas'])) {
            return $customizations;
        }

        foreach ($product['customizedDatas'] as $byCustomization) {
            if (!is_array($byCustomization)) {
                continue;
            }
            foreach ($byCustomization as $idCustomization => $customization) {
                if (!is_array($customization) || empty($customization['datas']) || !is_array($customization['datas'])) {
                    continue;
                }
                foreach ($customization['datas'] as $type => $fields) {
                    foreach ((array) $fields as $field) {
                        $customizations[] = [
                            'id_customization' => (int) $idCustomization,
                            'type' => (int) $type === (int) Product::CUSTOMIZE_FILE ? 'file' : 'text',
                            'name' => (string) ($field['name'] ?? ''),
                            'value' => (string) ($field['value'] ?? ''),
                        ];
                    }
                }
            }
        }

        return $customizations;
    }

    /**
     * @param Order $order
     *
     * @return array<int, array<string, mixed>>
     */
    private static function discounts(Order $order): array
    {
        $discounts = [];
        foreach ($order->getCartRules() as $rule) {
            $discounts[] = [
                'name' => (string) $rule['name'],
                'value_tax_included' => (float) $rule['value'],
                'value_tax_excluded' => (float) $rule['value_tax_excl'],
            ];
        }

        return $discounts;
    }

    /**
     * @param Order $order
     * @param int $idLang
     *
     * @return array<string, mixed>
     */
    private static function shipping(Order $order, int $idLang): array
    {
        $carrier = new Carrier((int) $order->id_carrier, $idLang);
        $tracking = [];

        foreach ($order->getShipping() as $shipping) {
            if (!empty($shipping['tracking_number'])) {
                $tracking[] = (string) $shipping['tracking_number'];
            }
        }

        return [
            'carrier' => Validate::isLoadedObject($carrier) ? (string) $carrier->name : '',
            'tracking_numbers' => array_values(array_unique($tracking)),
            'weight' => (float) $order->getTotalWeight(),
            'shipping_tax_excluded' => (float) $order->total_shipping_tax_excl,
            'shipping_tax_included' => (float) $order->total_shipping_tax_incl,
            'shipments' => self::shipments($order, $idLang),
        ];
    }

    /**
     * One entry per shipment, as the Carriers tab lists them.
     *
     * Read from the order's OrderCarrier rows rather than getShipping(), which
     * groups by carrier: two parcels with the same carrier would become one,
     * and a tracking number could then not be set on the right shipment.
     * somcp_set_order_tracking lists the shipments to choose from with this
     * same reading, so the two tools never disagree on what a shipment is.
     *
     * @param Order $order
     * @param int $idLang
     *
     * @return array<int, array<string, mixed>>
     */
    public static function shipments(Order $order, int $idLang): array
    {
        $collection = new PrestaShopCollection('OrderCarrier');
        $collection->where('id_order', '=', (int) $order->id);
        $collection->orderBy('id_order_carrier', 'asc');

        $shipments = [];
        foreach ($collection->getResults() as $orderCarrier) {
            if (!$orderCarrier instanceof OrderCarrier) {
                continue;
            }
            $carrier = new Carrier((int) $orderCarrier->id_carrier, $idLang);
            $tracking = (string) $orderCarrier->tracking_number;
            $url = Validate::isLoadedObject($carrier) ? (string) $carrier->url : '';

            $shipments[] = [
                'id_order_carrier' => (int) $orderCarrier->id,
                'date' => (string) $orderCarrier->date_add,
                'carrier' => Validate::isLoadedObject($carrier) ? (string) $carrier->name : '',
                'weight' => (float) $orderCarrier->weight,
                'shipping_cost_tax_excluded' => (float) $orderCarrier->shipping_cost_tax_excl,
                'shipping_cost_tax_included' => (float) $orderCarrier->shipping_cost_tax_incl,
                'tracking_number' => $tracking,
                // As the order page builds it: the carrier's address with @
                // standing for the number, and nothing until there is one.
                'tracking_url' => $url !== '' && $tracking !== '' ? str_replace('@', $tracking, $url) : '',
            ];
        }

        return $shipments;
    }

    /**
     * @param Order $order
     *
     * @return array<string, float>
     */
    private static function totals(Order $order): array
    {
        return [
            'products_tax_excluded' => (float) $order->total_products,
            'products_tax_included' => (float) $order->total_products_wt,
            'discounts_tax_excluded' => (float) $order->total_discounts_tax_excl,
            'discounts_tax_included' => (float) $order->total_discounts_tax_incl,
            'shipping_tax_excluded' => (float) $order->total_shipping_tax_excl,
            'shipping_tax_included' => (float) $order->total_shipping_tax_incl,
            'wrapping_tax_excluded' => (float) $order->total_wrapping_tax_excl,
            'wrapping_tax_included' => (float) $order->total_wrapping_tax_incl,
            // The Taxes line of the totals on the order page.
            'taxes' => (float) $order->total_paid_tax_incl - (float) $order->total_paid_tax_excl,
            'total_tax_excluded' => (float) $order->total_paid_tax_excl,
            'total_tax_included' => (float) $order->total_paid_tax_incl,
            'total_paid' => (float) $order->total_paid,
            'total_paid_real' => (float) $order->total_paid_real,
        ];
    }

    /**
     * @param Order $order
     *
     * @return array<int, array<string, mixed>>
     */
    private static function payments(Order $order): array
    {
        $payments = [];
        foreach ($order->getOrderPayments() as $payment) {
            $currency = new Currency((int) $payment->id_currency);
            $invoice = $payment->getOrderInvoice((int) $order->id);

            // The Employee column exists on PrestaShop 9 only: the property
            // is not there on 8, where the payment says nothing of who took it.
            $employee = '';
            if (property_exists($payment, 'id_employee') && (int) $payment->id_employee > 0) {
                $who = new Employee((int) $payment->id_employee);
                if (Validate::isLoadedObject($who)) {
                    $employee = trim((string) $who->firstname . ' ' . (string) $who->lastname);
                }
            }

            // No card number, holder or expiry: the order page does not show
            // them, and they have no business leaving the shop.
            $payments[] = [
                'date' => (string) $payment->date_add,
                'amount' => (float) $payment->amount,
                'currency' => Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '',
                'payment_method' => (string) $payment->payment_method,
                'transaction_id' => (string) $payment->transaction_id,
                'invoice_number' => $invoice instanceof OrderInvoice
                    ? (string) $invoice->getInvoiceNumberFormatted((int) $order->id_lang, (int) $order->id_shop)
                    : '',
                'employee' => $employee,
            ];
        }

        return $payments;
    }

    /**
     * @param Order $order
     * @param int $idLang
     *
     * @return array<int, array<string, mixed>>
     */
    private static function history(Order $order, int $idLang): array
    {
        $history = [];
        foreach ($order->getHistory($idLang) as $step) {
            $history[] = [
                'date' => (string) $step['date_add'],
                'status' => (string) $step['ostate_name'],
                'status_id' => (int) $step['id_order_state'],
                'employee' => trim((string) ($step['employee_firstname'] ?? '') . ' ' . (string) ($step['employee_lastname'] ?? '')),
            ];
        }

        return $history;
    }

    /**
     * @param Order $order
     * @param int $idLang
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function documents(Order $order, int $idLang): array
    {
        $invoices = [];
        foreach ($order->getInvoicesCollection() as $invoice) {
            // The collection is declared as holding ObjectModel, so the fields
            // and the formatting method below cannot be resolved from it. The
            // test is what says which class this really is, rather than a
            // docblock asserting it.
            if (!$invoice instanceof OrderInvoice) {
                continue;
            }

            $invoices[] = [
                // So a billing tool can point to this invoice later.
                'id_order_invoice' => (int) $invoice->id,
                'number' => (string) $invoice->getInvoiceNumberFormatted((int) $order->id_lang),
                'date' => (string) $invoice->date_add,
                'total_tax_excluded' => (float) $invoice->total_paid_tax_excl,
                'total_tax_included' => (float) $invoice->total_paid_tax_incl,
            ];
        }

        $slips = [];
        foreach (OrderSlip::getOrdersSlip((int) $order->id_customer, (int) $order->id) as $slip) {
            $slips[] = [
                'id_order_slip' => (int) $slip['id_order_slip'],
                'date' => (string) $slip['date_add'],
                'total_tax_excluded' => (float) $slip['total_products_tax_excl'],
                'total_tax_included' => (float) $slip['total_products_tax_incl'],
            ];
        }

        // The Documents tab lists delivery slips beside invoices: they are
        // order_invoice rows with a delivery number, numbered with the shop's
        // prefix the way the order page formats them.
        $prefix = (string) Configuration::get('PS_DELIVERY_PREFIX', $idLang, null, (int) $order->id_shop);
        $deliverySlips = [];
        foreach ($order->getDeliverySlipsCollection() as $deliverySlip) {
            if (!$deliverySlip instanceof OrderInvoice) {
                continue;
            }

            $deliverySlips[] = [
                'id_order_invoice' => (int) $deliverySlip->id,
                'number' => sprintf('%s%06d', $prefix, (int) $deliverySlip->delivery_number),
                'date' => (string) $deliverySlip->delivery_date,
                'total_tax_included' => (float) $deliverySlip->total_paid_tax_incl,
            ];
        }

        return ['invoices' => $invoices, 'delivery_slips' => $deliverySlips, 'credit_slips' => $slips];
    }

    /**
     * @param Order $order
     *
     * @return array<int, array<string, mixed>>
     */
    private static function messages(Order $order): array
    {
        $messages = [];
        foreach (CustomerMessage::getMessagesByOrderId((int) $order->id, false) as $message) {
            // Without who wrote it, an assistant cannot tell what the shop
            // answered from what the customer asked.
            $byEmployee = (int) $message['id_employee'] > 0;
            $messages[] = [
                'date' => (string) $message['date_add'],
                'private' => (bool) $message['private'],
                'from' => $byEmployee ? 'employee' : 'customer',
                'from_name' => $byEmployee
                    ? trim((string) $message['efirstname'] . ' ' . (string) $message['elastname'])
                    : trim((string) $message['cfirstname'] . ' ' . (string) $message['clastname']),
                'message' => (string) $message['message'],
            ];
        }

        return $messages;
    }
}
