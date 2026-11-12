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

use PrestaShop\PrestaShop\Adapter\ContextStateManager;
use PrestaShop\PrestaShop\Adapter\LegacyContext;

/*
 * somcp_create_order - Orders > Orders > Add new order, in one call: the
 * customer, the cart lines, the addresses, the carrier, the Shipping options,
 * the currency and language, the Order message, the Payment and the Order
 * status, then Create order.
 *
 * The screen's handlers are built by the Symfony container, which a front
 * controller does not have, so the tool runs the same sequence with the
 * legacy calls they wrap: a new Cart, Cart::updateQty(), the delivery option,
 * the free shipping cart rule of UpdateCartDeliverySettingsHandler, a Message
 * on the cart, then PaymentModule::validateOrder() as
 * AddOrderFromBackOfficeHandler calls it, with the context set to the cart and
 * restored afterwards. The sequence is the same on 8.0 and 9.x.
 *
 * Every mistake in the arguments is answered before anything is written. What
 * can only be known once the cart exists - the quantities the cart accepts,
 * the carriers it is offered - is checked next, and a refusal at that point
 * deletes the cart and its free shipping rule, so nothing is left behind.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// product, currency and address checks, the employee on the context and the
// refusal envelope live in these classes.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolCreateCartRule.php';
require_once __DIR__ . '/SomcpToolIssuePartialRefund.php';
require_once __DIR__ . '/SomcpToolAddOrderProduct.php';
require_once __DIR__ . '/SomcpToolSetOrderAddress.php';

class SomcpToolCreateOrder
{
    /** @var string[] The arguments the tool takes, so a misspelt one is refused by name. */
    private const KEYS = [
        'id_customer', 'products', 'id_address_delivery', 'id_address_invoice', 'id_carrier', 'free_shipping',
        'recycled_packaging', 'gift', 'gift_message', 'currency', 'language', 'message', 'payment_module',
        'status_id',
    ];

    /** @var string[] The keys of one cart line. */
    private const LINE_KEYS = ['id_product', 'id_product_attribute', 'quantity'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_order',
            Somcp::t('Create an order'),
            'Creates one order for an existing customer, like Add new order then Create order: lines, '
                . 'addresses, carrier, Shipping options, currency, language, message, Payment and Order status, '
                . 'at the prices the cart computes. It sends the new-order emails of that status: show the '
                . 'lines, carrier, total and status and wait for a yes. Not replayable: on a lost answer look '
                . 'with somcp_search_orders (this customer, today), then somcp_get_order. For an existing order: '
                . 'somcp_add_order_product, somcp_set_order_status. A new customer: somcp_create_customer.',
            [
                'type' => 'object',
                'properties' => [
                    'id_customer' => [
                        'type' => 'integer',
                        'description' => 'The customer, from somcp_search_customers.',
                    ],
                    'products' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'description' => 'The cart lines, each product and combination once.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id_product' => [
                                    'type' => 'integer',
                                    'description' => 'From somcp_search_products.',
                                ],
                                'id_product_attribute' => [
                                    'type' => 'integer',
                                    'description' => 'The combination, from combinations[].id_product_attribute '
                                        . 'of somcp_get_product; required when the product has combinations.',
                                ],
                                'quantity' => [
                                    'type' => 'integer',
                                    'minimum' => 1,
                                    'description' => 'At least the minimal quantity of the product.',
                                ],
                            ],
                            'required' => ['id_product', 'quantity'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'id_address_delivery' => [
                        'type' => 'integer',
                        'description' => 'Delivery address, from addresses[].id_address of somcp_get_customer.',
                    ],
                    'id_address_invoice' => [
                        'type' => 'integer',
                        'description' => 'Invoice address, same source; the delivery address when left out.',
                    ],
                    'id_carrier' => [
                        'type' => 'integer',
                        'description' => 'Delivery option, required unless every product is virtual. A carrier '
                            . 'the address is not offered is refused with those it is.',
                    ],
                    'free_shipping' => [
                        'type' => 'boolean',
                        'description' => 'Free shipping. Default false.',
                    ],
                    'recycled_packaging' => [
                        'type' => 'boolean',
                        'description' => 'Recycled packaging. Default false.',
                    ],
                    'gift' => [
                        'type' => 'boolean',
                        'description' => 'Gift. Default false.',
                    ],
                    'gift_message' => [
                        'type' => 'string',
                        'description' => 'Gift message, only with gift true. Plain text.',
                    ],
                    'currency' => [
                        'type' => 'string',
                        'description' => 'ISO code of an active currency, the shop default when left out.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr; a code '
                            . 'the shop does not have is refused. The shop default language when left out. The '
                            . 'order emails go out in it.',
                    ],
                    'message' => [
                        'type' => 'string',
                        'description' => 'Order message. Plain text.',
                    ],
                    'payment_module' => [
                        'type' => 'string',
                        'description' => 'Payment: technical name of an active payment module. A wrong one is '
                            . 'refused with the choices.',
                    ],
                    'status_id' => [
                        'type' => 'integer',
                        'description' => 'Order status, from somcp_list_order_statuses.',
                    ],
                ],
                'required' => ['id_customer', 'products', 'id_address_delivery', 'payment_module', 'status_id'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Add new order sits on the orders list, and creating one takes
            // the add right there.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminOrders')
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
        $idShop = Somcp::currentShopId();
        $problems = [];

        array_push($problems, ...SomcpTool::unknownFields($arguments, self::KEYS));

        $customer = self::customer($arguments, $idShop, $problems);
        $lines = self::lines($arguments, $idShop, $problems);
        $language = self::orderLanguage($arguments, $idShop, $problems);
        $idLang = $language !== null ? $language[0] : (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

        $idDelivery = 0;
        $idInvoice = 0;
        if ($customer instanceof Customer) {
            $idDelivery = self::address($arguments, 'id_address_delivery', $customer, $idLang, true, $problems);
            $idInvoice = array_key_exists('id_address_invoice', $arguments)
                ? self::address($arguments, 'id_address_invoice', $customer, $idLang, false, $problems)
                : $idDelivery;
        }

        $idCurrency = (int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop);
        if (array_key_exists('currency', $arguments)) {
            $errors = [];
            $idCurrency = SomcpToolCreateCartRule::activeCurrency($arguments['currency'], $idShop, 'currency', $errors);
            foreach ($errors as $error) {
                $problems[] = $error;
            }
        }

        $flags = [];
        foreach (['free_shipping', 'recycled_packaging', 'gift'] as $key) {
            $value = $arguments[$key] ?? false;
            if (!is_bool($value)) {
                $problems[] = $key . ': true or false';
                $value = false;
            }
            $flags[$key] = $value;
        }

        $giftMessage = self::text($arguments, 'gift_message', $problems);
        if ($giftMessage !== '' && !$flags['gift']) {
            $problems[] = 'gift_message: only with gift true';
        }
        $message = self::text($arguments, 'message', $problems);

        $payment = self::paymentModule($arguments, $problems);

        $idStatus = SomcpTool::id($arguments, 'status_id');
        $status = new OrderState($idStatus, $idLang);
        // A deleted status still loads: Delete only flags it. The Order status
        // select never offers one, so neither does this tool.
        if ($idStatus <= 0 || !Validate::isLoadedObject($status) || (bool) $status->deleted) {
            $problems[] = 'status_id: no status of this shop has this identifier - read them with '
                . 'somcp_list_order_statuses';
        }

        $idCarrier = 0;
        if (array_key_exists('id_carrier', $arguments)) {
            $idCarrier = SomcpTool::id($arguments, 'id_carrier');
            if ($idCarrier <= 0) {
                $problems[] = 'id_carrier: must be a positive identifier';
            }
        }

        if ($problems !== [] || !$customer instanceof Customer || $payment === null || $idDelivery <= 0
            || $idInvoice <= 0 || $idCurrency <= 0) {
            return SomcpToolIssuePartialRefund::refused($problems);
        }

        $plan = [
            'customer' => $customer,
            'lines' => $lines,
            'id_address_delivery' => $idDelivery,
            'id_address_invoice' => $idInvoice,
            'id_carrier' => $idCarrier,
            'id_currency' => $idCurrency,
            'id_lang' => $idLang,
            'flags' => $flags,
            'gift_message' => $giftMessage,
            'message' => $message,
            'payment' => $payment,
            'id_status' => $idStatus,
            'id_shop' => $idShop,
        ];

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($plan, $idEmployee): array {
            return self::create($plan, $idEmployee);
        });
    }

    /**
     * The customer, of this shop and not deleted.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param string[] $problems
     *
     * @return Customer|null
     */
    private static function customer(array $arguments, int $idShop, array &$problems): ?Customer
    {
        $idCustomer = SomcpTool::id($arguments, 'id_customer');
        if ($idCustomer <= 0) {
            $problems[] = 'id_customer: give the customer, from somcp_search_customers';

            return null;
        }

        $customer = new Customer($idCustomer);
        if (!Validate::isLoadedObject($customer) || (int) $customer->id_shop !== $idShop
            || (int) $customer->deleted === 1) {
            $problems[] = 'id_customer: no customer of this shop has the identifier ' . $idCustomer
                . ' - find one with somcp_search_customers';

            return null;
        }

        return $customer;
    }

    /**
     * The cart lines, each checked as Add a product of an order checks it.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param string[] $problems
     *
     * @return array<int, array{id_product:int, id_product_attribute:int, quantity:int}>
     */
    private static function lines(array $arguments, int $idShop, array &$problems): array
    {
        $given = $arguments['products'] ?? null;
        if (!is_array($given) || $given === [] || array_keys($given) !== range(0, count($given) - 1)) {
            $problems[] = 'products: give a list of at least one line, each with id_product and quantity';

            return [];
        }

        $lines = [];
        $seen = [];
        $stockManaged = (bool) Configuration::get('PS_STOCK_MANAGEMENT');
        foreach ($given as $i => $item) {
            $path = 'products[' . $i . ']';
            if (!is_array($item)) {
                $problems[] = $path . ': give an object with id_product and quantity';
                continue;
            }
            array_push($problems, ...SomcpTool::unknownFields($item, self::LINE_KEYS, [], $path . '.'));

            $quantity = $item['quantity'] ?? null;
            if (!is_int($quantity) || $quantity < 1) {
                $problems[] = $path . '.quantity: must be a whole number, at least 1';
                $quantity = 0;
            }

            $idProduct = SomcpTool::id($item, 'id_product');
            if ($idProduct <= 0) {
                $problems[] = $path . '.id_product: give the product, from somcp_search_products';
                continue;
            }
            $product = new Product($idProduct, false, null, $idShop);
            if (!Validate::isLoadedObject($product) || !SomcpToolAddOrderProduct::inShop($idProduct, $idShop)) {
                $problems[] = $path . '.id_product: no product of this shop has the identifier ' . $idProduct
                    . ' - find it with somcp_search_products';
                continue;
            }
            if (!(bool) $product->available_for_order) {
                $problems[] = $path . '.id_product: this product is not available for order';
            }
            // The cart of the screen cannot hold a line missing a required
            // customization, and this tool takes none.
            if (!empty(Product::getRequiredCustomizableFieldsStatic($idProduct))) {
                $problems[] = $path . '.id_product: this product has required customization fields, which this '
                    . 'tool cannot fill - create the order from the back office';
            }

            $idCombination = SomcpTool::id($item, 'id_product_attribute');
            $minimal = (int) $product->minimal_quantity;
            if ($idCombination > 0) {
                $combination = new Combination($idCombination);
                if (!Validate::isLoadedObject($combination) || (int) $combination->id_product !== $idProduct) {
                    $problems[] = $path . '.id_product_attribute: no combination of this product has the '
                        . 'identifier ' . $idCombination . ' - read them with somcp_get_product';
                    continue;
                }
                $minimal = (int) $combination->minimal_quantity;
            } elseif (array_key_exists('id_product_attribute', $item) && $item['id_product_attribute'] !== 0) {
                $problems[] = $path . '.id_product_attribute: must be a positive identifier';
                continue;
            } elseif ((bool) $product->hasAttributes()) {
                $problems[] = $path . '.id_product_attribute: this product has combinations - give one, from '
                    . 'combinations[].id_product_attribute of somcp_get_product';
                continue;
            }

            $key = $idProduct . ':' . $idCombination;
            if (isset($seen[$key])) {
                $problems[] = $path . ': the same product and combination as products[' . $seen[$key]
                    . '] - give one line with the whole quantity';
                continue;
            }
            $seen[$key] = $i;

            if ($quantity > 0 && $quantity < $minimal) {
                $problems[] = $path . '.quantity: this product is sold by ' . $minimal . ' at least';
            }

            if ($quantity > 0 && $stockManaged && !$product->is_virtual
                && !Product::isAvailableWhenOutOfStock(StockAvailable::outOfStock($idProduct, $idShop))) {
                $available = (int) StockAvailable::getQuantityAvailableByProduct($idProduct, $idCombination, $idShop);
                if ($available < $quantity) {
                    $problems[] = $path . '.quantity: ' . max(0, $available) . ' in stock, and this product '
                        . 'cannot be ordered out of stock';
                }
            }

            $lines[] = ['id_product' => $idProduct, 'id_product_attribute' => $idCombination, 'quantity' => (int) $quantity];
        }

        return $lines;
    }

    /**
     * The language of the order, read and refused as every tool reads one:
     * the refusal is languageRefused()'s own text, under the field's name.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param string[] $problems
     *
     * @return array{0: int, 1: string}|null
     */
    private static function orderLanguage(array $arguments, int $idShop, array &$problems): ?array
    {
        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            $problems[] = 'language: ' . (string) (SomcpTool::languageRefused($idShop)['content'][0]['text'] ?? '');
        }

        return $language;
    }

    /**
     * One of the customer's addresses, as the Addresses block offers them.
     *
     * @param array<string, mixed> $arguments
     * @param string $key
     * @param Customer $customer
     * @param int $idLang
     * @param bool $required
     * @param string[] $problems
     *
     * @return int 0 when refused
     */
    private static function address(array $arguments, string $key, Customer $customer, int $idLang, bool $required, array &$problems): int
    {
        $idAddress = SomcpTool::id($arguments, $key);
        if ($idAddress <= 0 && !$required && !array_key_exists($key, $arguments)) {
            return 0;
        }

        $address = $idAddress > 0 ? new Address($idAddress) : null;
        if ($address === null || !Validate::isLoadedObject($address) || (int) $address->deleted === 1
            || (int) $address->id_customer !== (int) $customer->id) {
            $problems[] = $key . ': ' . ($idAddress > 0
                ? 'no address of this customer has the identifier ' . $idAddress
                : 'give an address of this customer')
                . ' - the addresses of this customer: '
                . json_encode(SomcpToolSetOrderAddress::addressesOf((int) $customer->id, $idLang), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return 0;
        }

        // The core refuses the order on a disabled country, but only once the
        // cart is built: said here with the rest.
        if (!Address::isCountryActiveById($idAddress)) {
            $problems[] = $key . ': the country of this address is not active in the shop';

            return 0;
        }

        return $idAddress;
    }

    /**
     * An optional plain text, checked as the screen checks its messages.
     *
     * @param array<string, mixed> $arguments
     * @param string $key
     * @param string[] $problems
     *
     * @return string
     */
    private static function text(array $arguments, string $key, array &$problems): string
    {
        if (!array_key_exists($key, $arguments)) {
            return '';
        }
        if (!is_string($arguments[$key])) {
            $problems[] = $key . ': give a text';

            return '';
        }

        $text = trim($arguments[$key]);
        if (!Validate::isMessage($text)) {
            $problems[] = $key . ': the text holds HTML or a character PrestaShop refuses in it';

            return '';
        }
        $size = (int) (Message::$definition['fields']['message']['size'] ?? 0);
        if ($key === 'message' && $size > 0 && Tools::strlen($text) > $size) {
            $problems[] = $key . ': ' . $size . ' characters at most';

            return '';
        }

        return $text;
    }

    /**
     * The payment module, one of the choices of the Payment select.
     *
     * @param array<string, mixed> $arguments
     * @param string[] $problems
     *
     * @return PaymentModule|null
     */
    private static function paymentModule(array $arguments, array &$problems): ?PaymentModule
    {
        $choices = self::paymentChoices();
        $name = isset($arguments['payment_module']) && is_string($arguments['payment_module'])
            ? trim($arguments['payment_module']) : '';

        if ($name === '' || !isset($choices[$name])) {
            $listed = [];
            foreach ($choices as $choice) {
                $listed[] = ['name' => (string) $choice->name, 'display_name' => (string) $choice->displayName];
            }
            $problems[] = 'payment_module: ' . ($name === '' ? 'give the technical name of a payment module' : 'not a payment choice of this shop')
                . ' - the choices: ' . json_encode($listed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return null;
        }

        // In catalog mode the screen records the order through the core's
        // back office payment, as AddOrderFromBackOfficeHandler does.
        if ((bool) Configuration::get('PS_CATALOG_MODE')) {
            return new BoOrderCore();
        }

        return $choices[$name];
    }

    /**
     * The choices of the Payment select, as InstalledPaymentModulesChoiceProvider
     * builds them: installed payment modules, loaded and active.
     *
     * @return array<string, PaymentModule> keyed by technical name
     */
    private static function paymentChoices(): array
    {
        $choices = [];
        foreach (PaymentModule::getInstalledPaymentModules() as $row) {
            $module = Module::getInstanceByName((string) ($row['name'] ?? ''));
            if ($module instanceof PaymentModule && Validate::isLoadedObject($module) && (bool) $module->active) {
                $choices[(string) $module->name] = $module;
            }
        }

        return $choices;
    }

    /**
     * Builds the cart, then validates it into an order.
     *
     * @param array<string, mixed> $plan
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function create(array $plan, int $idEmployee): array
    {
        /** @var Customer $customer */
        $customer = $plan['customer'];
        /** @var PaymentModule $payment */
        $payment = $plan['payment'];
        $idDelivery = (int) $plan['id_address_delivery'];

        // A new cart every time, never the customer's last empty one as the
        // screen takes: that may be a cart open on the storefront right now.
        $cart = new Cart();
        $cart->id_customer = (int) $customer->id;
        $cart->secure_key = (string) $customer->secure_key;
        $cart->id_shop = (int) $plan['id_shop'];
        $cart->id_shop_group = (int) Shop::getGroupFromShop((int) $plan['id_shop']);
        $cart->id_currency = (int) $plan['id_currency'];
        $cart->id_lang = (int) $plan['id_lang'];
        $cart->id_address_delivery = $idDelivery;
        $cart->id_address_invoice = (int) $plan['id_address_invoice'];
        $cart->recyclable = $plan['flags']['recycled_packaging'];
        $cart->gift = $plan['flags']['gift'];
        $cart->gift_message = (string) $plan['gift_message'];

        try {
            $added = $cart->add();
        } catch (Throwable $e) {
            $added = false;
        }
        if (!$added) {
            return SomcpToolIssuePartialRefund::refused(['the cart could not be created - nothing was written']);
        }

        // The context validateOrder() and the prices read, set to the cart as
        // the handler sets it, and restored whatever happens.
        $contextManager = new ContextStateManager(new LegacyContext());
        $contextManager->setCart($cart);
        $contextManager->setCustomer($customer);
        $contextManager->setCurrency(new Currency((int) $cart->id_currency));
        $contextManager->setLanguage(new Language((int) $cart->id_lang));
        $contextManager->setCountry(new Country((int) (new Address($idDelivery))->id_country));

        $freeShippingRule = null;
        $note = null;
        $idOrder = 0;
        try {
            $problems = [];
            $shop = new Shop((int) $plan['id_shop']);
            foreach ($plan['lines'] as $i => $line) {
                $result = $cart->updateQty($line['quantity'], $line['id_product'], $line['id_product_attribute'], false, 'up', $idDelivery, $shop);
                if ($result === -1 || $result === false) {
                    $problems[] = 'products[' . $i . '].quantity: the cart refused this quantity'
                        . ($result === -1 ? ', below the minimal quantity' : ' - not enough in stock');
                }
            }

            if ($problems === []) {
                self::carrier($cart, (int) $plan['id_carrier'], $problems);
            }

            if ($problems !== []) {
                self::discard($cart, null, null);

                return SomcpToolIssuePartialRefund::refused($problems);
            }

            if ($plan['flags']['free_shipping']) {
                $freeShippingRule = self::freeShipping($cart);
            }

            if ($plan['message'] !== '') {
                $note = new Message();
                $note->message = (string) $plan['message'];
                $note->id_cart = (int) $cart->id;
                $note->id_customer = (int) $cart->id_customer;
                $note->add();
            }

            $employee = new Employee($idEmployee);
            $recorded = sprintf(
                '%s %s. %s',
                Somcp::legacyContext()->getTranslator()->trans('Manual order -- Employee:', [], 'Admin.Orderscustomers.Feature'),
                Tools::substr((string) $employee->firstname, 0, 1),
                (string) $employee->lastname
            );

            $payment->validateOrder(
                (int) $cart->id,
                (int) $plan['id_status'],
                (float) $cart->getOrderTotal(true, Cart::BOTH),
                (string) $payment->displayName,
                $recorded,
                [],
                null,
                false,
                (string) $cart->secure_key
            );
            $idOrder = (int) $payment->currentOrder;
        } catch (Throwable $e) {
            $idOrder = (int) Order::getIdByCartId((int) $cart->id);
        } finally {
            $contextManager->restorePreviousContext();
        }

        if ($idOrder <= 0) {
            self::discard($cart, $freeShippingRule, $note);
            SomcpLogger::log('order_create_failed', SomcpLogger::OUTCOME_FAILURE, 'customer ' . (int) $customer->id, '', $idEmployee);

            return SomcpToolIssuePartialRefund::refused([
                'the order could not be created, and its cart was removed - check the arguments, or create it '
                    . 'from Add new order in the back office',
            ]);
        }

        $order = new Order($idOrder);
        $state = new OrderState((int) $order->current_state, (int) $order->id_lang);
        $currency = new Currency((int) $order->id_currency);

        SomcpLogger::log(
            'order_created',
            SomcpLogger::OUTCOME_SUCCESS,
            'order ' . $idOrder . ', customer ' . (int) $customer->id . ', cart ' . (int) $cart->id,
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'created' => true,
            'id_order' => $idOrder,
            'reference' => (string) $order->reference,
            'id_cart' => (int) $cart->id,
            'status' => [
                'status_id' => (int) $order->current_state,
                'name' => Validate::isLoadedObject($state) ? (string) $state->name : '',
            ],
            'payment' => (string) $order->payment,
            // Named as totals of somcp_get_order, so the two read the same.
            'totals' => SomcpToolGetOrder::totals($order),
            'currency' => Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '',
        ]);
    }

    /**
     * Sets the delivery option, or says which carriers the cart is offered.
     *
     * @param Cart $cart
     * @param int $idCarrier 0 when not given
     * @param string[] $problems
     *
     * @return void
     */
    private static function carrier(Cart $cart, int $idCarrier, array &$problems): void
    {
        if ($cart->isVirtualCart()) {
            if ($idCarrier > 0) {
                $problems[] = 'id_carrier: every product is virtual, so the order has no delivery - leave '
                    . 'id_carrier out';
            }

            return;
        }

        $idAddress = (int) $cart->id_address_delivery;
        $options = $cart->getDeliveryOptionList(null, true);
        $offered = [];
        foreach ($options[$idAddress] ?? [] as $key => $option) {
            // A single carrier option is keyed "<id_carrier>,", the legacy
            // format the screen writes back.
            if (!preg_match('/^(\d+),$/', (string) $key, $match)) {
                continue;
            }
            $carrier = $option['carrier_list'][(int) $match[1]]['instance'] ?? null;
            $offered[(int) $match[1]] = [
                'id_carrier' => (int) $match[1],
                'name' => SomcpTool::carrierName($carrier instanceof Carrier ? (string) $carrier->name : ''),
                'shipping_tax_included' => (float) ($option['total_price_with_tax'] ?? 0),
            ];
        }

        if ($offered === []) {
            $problems[] = 'id_carrier: No carrier can be applied to this order - check the carriers of the '
                . 'delivery address\'s zone and the weight of the products';

            return;
        }
        if (!isset($offered[$idCarrier])) {
            $problems[] = 'id_carrier: ' . ($idCarrier > 0 ? 'this carrier is not offered for this address and '
                . 'these products' : 'give the delivery option') . ' - offered: '
                . json_encode(array_values($offered), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return;
        }

        $cart->setDeliveryOption([$idAddress => sprintf('%d,', $idCarrier)]);
        $cart->update();
    }

    /**
     * The free shipping cart rule the Free shipping switch creates, named and
     * coded as UpdateCartDeliverySettingsHandler names and codes it.
     *
     * @param Cart $cart
     *
     * @return CartRule
     */
    private static function freeShipping(Cart $cart): CartRule
    {
        $rule = new CartRule();
        $rule->code = CartRule::BO_ORDER_CODE_PREFIX . (int) $cart->id;
        $rule->name = [
            // The default language of the cart's shop, not the global one:
            // on a multistore the two differ, as line 198 already knows (#149).
            (int) Configuration::get('PS_LANG_DEFAULT', null, null, (int) $cart->id_shop) => Somcp::legacyContext()->getTranslator()->trans('Free Shipping', [], 'Admin.Orderscustomers.Feature'),
        ];
        $rule->id_customer = (int) $cart->id_customer;
        $rule->free_shipping = true;
        $rule->quantity = 1;
        $rule->quantity_per_user = 1;
        $rule->minimum_amount_currency = (int) $cart->id_currency;
        $rule->reduction_currency = (int) $cart->id_currency;
        $rule->date_from = date('Y-m-d H:i:s');
        $rule->date_to = date('Y-m-d H:i:s', time() + 24 * 36000);
        $rule->active = true;
        $rule->add();
        $cart->addCartRule((int) $rule->id);

        return $rule;
    }

    /**
     * Removes what a refused call made, so it leaves nothing behind.
     *
     * @param Cart $cart
     * @param CartRule|null $rule
     * @param Message|null $note
     *
     * @return void
     */
    private static function discard(Cart $cart, ?CartRule $rule, ?Message $note): void
    {
        try {
            if ($note instanceof Message && Validate::isLoadedObject($note)) {
                $note->delete();
            }
            if ($rule instanceof CartRule && Validate::isLoadedObject($rule)) {
                $rule->delete();
            }
            // Cart::delete() keeps a cart an order stands on, so this never
            // removes the cart of an order that was created after all.
            $cart->delete();
        } catch (Throwable $e) {
            SomcpLogger::log('order_create_cleanup_failed', SomcpLogger::OUTCOME_FAILURE, 'cart ' . (int) $cart->id, '', 0);
        }
    }
}
