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

use PrestaShop\PrestaShop\Adapter\Order\CommandHandler\AddProductToOrderHandler;
use PrestaShop\PrestaShop\Core\Domain\Order\Product\Command\AddProductToOrderCommand;

/*
 * somcp_add_order_product - Add a product in the Products block of the order
 * page: one product or combination added to an order not shipped yet, the
 * totals recomputed.
 *
 * The core's own AddProductToOrderHandler does the work, built with new and
 * the services it is built with in the back office (the same constructor from
 * 8.0.0 to 9.1): the cart, the invoice, the order lines, the totals and
 * actionOrderEdited stay exactly as the button does them. The tool checks the
 * arguments first so every mistake comes back in one answer.
 *
 * PrestaShop 9.2 gave the handler three more services, for the shipments of
 * the improved shipping feature, which only the Symfony container builds and
 * which a front controller does not have. Rather than fake them and record a
 * line the shipments do not know about, the tool refuses on 9.2 and later.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// order lookup, the employee on the context and the refusal envelope live in
// these classes.
require_once __DIR__ . '/SomcpToolGetOrder.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolIssuePartialRefund.php';
require_once __DIR__ . '/SomcpToolUpdateOrderProduct.php';

class SomcpToolAddOrderProduct
{
    /** @var string[] */
    private const KEYS = [
        'id_order', 'reference', 'id_product', 'id_product_attribute', 'quantity',
        'unit_price_tax_excluded', 'unit_price_tax_included', 'id_order_invoice', 'free_shipping',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_add_order_product',
            Somcp::t('Add a product to an order'),
            'Adds one product or combination to one order not shipped yet, like Add a product in the Products '
                . 'block of the order page, and recomputes the totals (id_product from somcp_search_products). A '
                . 'product already there is refused: change it with somcp_update_order_product, remove a line '
                . 'with somcp_delete_order_product. Say the new total and wait for the go-ahead. Never to be '
                . 'retried on a lost answer: check products of somcp_get_order.',
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
                    'id_product' => [
                        'type' => 'integer',
                        'description' => 'The product, from somcp_search_products.',
                    ],
                    'id_product_attribute' => [
                        'type' => 'integer',
                        'description' => 'The combination, from combinations[].id_product_attribute of '
                            . 'somcp_get_product. Required when the product has combinations.',
                    ],
                    'quantity' => [
                        'type' => 'integer',
                        'description' => 'Quantity, at least 1.',
                    ],
                    'unit_price_tax_excluded' => [
                        'type' => 'number',
                        'description' => 'Unit price tax excl., in the order currency. Left out: the price of the '
                            . 'product for this customer.',
                    ],
                    'unit_price_tax_included' => [
                        'type' => 'number',
                        'description' => 'Unit price tax incl., in the order currency. Given alone, the price tax '
                            . 'excl. is worked out with the tax rate of the line.',
                    ],
                    'id_order_invoice' => [
                        'type' => 'integer',
                        'description' => 'Existing invoice to add the line to, from documents.invoices of '
                            . 'somcp_get_order. Left out on an invoiced order, a new invoice is made.',
                    ],
                    'free_shipping' => [
                        'type' => 'boolean',
                        'description' => 'Free shipping on the new invoice. Only when a new invoice is made.',
                    ],
                ],
                'required' => ['id_product', 'quantity'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Add a product sits on the order page, and saving the line takes
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

        $quantity = $arguments['quantity'] ?? null;
        if (!is_int($quantity) || $quantity < 1) {
            $problems[] = 'quantity: must be a whole number, at least 1';
            $quantity = 0;
        }

        $prices = [];
        foreach (['unit_price_tax_excluded', 'unit_price_tax_included'] as $key) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            $value = $arguments[$key];
            if ((!is_int($value) && !is_float($value)) || $value < 0) {
                $problems[] = $key . ': must be a number, 0 or more';
                continue;
            }
            $prices[$key] = (float) $value;
        }

        $idProduct = SomcpTool::id($arguments, 'id_product');
        $product = null;
        $idShop = Somcp::currentShopId();
        if ($idProduct <= 0) {
            $problems[] = 'id_product: give the product, from somcp_search_products';
        } else {
            $product = new Product($idProduct, false, null, $idShop);
            if (!Validate::isLoadedObject($product) || !self::inShop($idProduct, $idShop)) {
                $problems[] = 'id_product: no product of this shop has the identifier ' . $idProduct
                    . ' - find it with somcp_search_products';
                $product = null;
            }
        }

        $idCombination = SomcpTool::id($arguments, 'id_product_attribute');
        if ($product instanceof Product) {
            $hasCombinations = (bool) $product->hasAttributes();
            if ($idCombination > 0) {
                $combination = new Combination($idCombination);
                if (!Validate::isLoadedObject($combination) || (int) $combination->id_product !== $idProduct) {
                    $problems[] = 'id_product_attribute: no combination of this product has the identifier '
                        . $idCombination . ' - read them with somcp_get_product';
                }
            } elseif ($hasCombinations) {
                $problems[] = 'id_product_attribute: this product has combinations - give one, from '
                    . 'combinations[].id_product_attribute of somcp_get_product';
            }
        } elseif (array_key_exists('id_product_attribute', $arguments) && $idCombination <= 0) {
            $problems[] = 'id_product_attribute: must be a positive identifier';
        }

        $order = SomcpToolGetOrder::find($arguments);
        if (!$order instanceof Order) {
            $problems[] = (array_key_exists('reference', $arguments) && SomcpTool::id($arguments, 'id_order') <= 0
                ? 'reference' : 'id_order') . ': ' . $order;

            return SomcpToolIssuePartialRefund::refused($problems);
        }

        $idInvoice = SomcpTool::id($arguments, 'id_order_invoice');
        $freeShipping = $arguments['free_shipping'] ?? null;
        if ($freeShipping !== null && !is_bool($freeShipping)) {
            $problems[] = 'free_shipping: true or false';
            $freeShipping = null;
        }
        if (array_key_exists('id_order_invoice', $arguments)) {
            $invoice = new OrderInvoice($idInvoice);
            if ($idInvoice <= 0 || !Validate::isLoadedObject($invoice) || (int) $invoice->id_order !== (int) $order->id) {
                $problems[] = 'id_order_invoice: not an invoice of this order - read them in documents.invoices of '
                    . 'somcp_get_order';
            } elseif ($freeShipping !== null) {
                $problems[] = 'free_shipping: only with a new invoice, leave out id_order_invoice';
            }
        } elseif ($freeShipping !== null && !$order->hasInvoice()) {
            $problems[] = 'free_shipping: only when a new invoice is made, and this order has no invoice';
        }

        // Said up front: the handler refuses it too, but only after the rest.
        if ($order->hasBeenShipped()) {
            $problems[] = 'id_order: this order was shipped, so the order page has no Add a product';
        }

        // The core's rule: once the order has invoices, the same product may
        // go to a new invoice, only not twice in the same one.
        if ($product instanceof Product && $problems === []) {
            foreach ($order->getProducts() as $line) {
                if ((int) $line['product_id'] === $idProduct && (int) $line['product_attribute_id'] === $idCombination
                    && (!$order->hasInvoice() || ($idInvoice > 0 && (int) $line['id_order_invoice'] === $idInvoice))) {
                    $problems[] = 'id_product: this product is already a line of the order (id_order_detail '
                        . (int) $line['id_order_detail'] . ') - change that line with somcp_update_order_product';
                    break;
                }
            }
        }

        $amounts = null;
        if ($product instanceof Product && $problems === []) {
            $amounts = self::prices($order, $product, $idCombination, $prices, $problems);
        }

        if ($problems !== [] || !is_array($amounts)) {
            return SomcpToolIssuePartialRefund::refused($problems);
        }

        // Last of the checks, so the mistakes in the arguments still come back
        // on every version.
        if (!SomcpToolUpdateOrderProduct::supported()) {
            return SomcpToolIssuePartialRefund::refused([
                'this PrestaShop version adds a product to an order together with its shipments, which this tool '
                    . 'cannot do yet - use Add a product in the Products block of the order page',
            ]);
        }

        $quantity = (int) $quantity;

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($order, $idProduct, $idCombination, $quantity, $amounts, $idInvoice, $freeShipping, $idEmployee): array {
            return self::apply($order, $idProduct, $idCombination, $quantity, $amounts, $idInvoice, $freeShipping, $idEmployee);
        });
    }

    /**
     * Whether the product is associated with the shop.
     *
     * @param int $idProduct
     * @param int $idShop
     *
     * @return bool
     */
    private static function inShop(int $idProduct, int $idShop): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `id_product` = ' . (int) $idProduct
                . ' AND `id_shop` = ' . (int) $idShop
        );
    }

    /**
     * The unit prices of the new line, tax excluded and included.
     *
     * Left out, they are the product's price for the order's customer and
     * delivery address, which is what the order page prefills when the
     * product is picked. The tax rate is the product's for that address, so
     * a price given alone gets its other half the way the screen works it
     * out.
     *
     * @param Order $order
     * @param Product $product
     * @param int $idCombination
     * @param array<string, float> $given
     * @param string[] $problems
     *
     * @return array{excl: float, incl: float}|null
     */
    private static function prices(Order $order, Product $product, int $idCombination, array $given, array &$problems): ?array
    {
        $currency = new Currency((int) $order->id_currency);
        $precision = Validate::isLoadedObject($currency) ? (int) $currency->precision : 2;
        $address = new Address((int) $order->id_address_delivery);
        $rate = (float) $product->getTaxesRate($address);

        $excl = $given['unit_price_tax_excluded'] ?? null;
        $incl = $given['unit_price_tax_included'] ?? null;

        if ($excl === null && $incl === null) {
            $null = null;
            // getPriceStatic() answers in the context currency: set to the
            // order's for the call, as the order page does when it searches.
            $context = Somcp::legacyContext();
            $previousCurrency = $context->currency;
            $context->currency = $currency;
            try {
                $excl = (float) Product::getPriceStatic(
                    (int) $product->id,
                    false,
                    $idCombination > 0 ? $idCombination : null,
                    6,
                    null,
                    false,
                    true,
                    1,
                    false,
                    (int) $order->id_customer,
                    (int) $order->id_cart,
                    (int) $order->id_address_delivery,
                    $null,
                    true,
                    true,
                    null,
                    true
                );
            } finally {
                $context->currency = $previousCurrency;
            }
            $incl = $excl * (1 + $rate / 100);
        } elseif ($incl === null) {
            $incl = $excl * (1 + $rate / 100);
        } elseif ($excl === null) {
            $excl = $incl / (1 + $rate / 100);
        } elseif (abs(Tools::ps_round($excl * (1 + $rate / 100), $precision) - Tools::ps_round($incl, $precision))
            > 1 / (10 ** $precision)) {
            $problems[] = 'unit_price_tax_included: does not agree with unit_price_tax_excluded and the tax rate of '
                . 'the line (' . $rate . ' %) - give one of the two';

            return null;
        }

        return ['excl' => (float) $excl, 'incl' => (float) $incl];
    }

    /**
     * Runs the core handler and reports the new line and the totals.
     *
     * @param Order $order
     * @param int $idProduct
     * @param int $idCombination
     * @param int $quantity
     * @param array{excl: float, incl: float} $amounts
     * @param int $idInvoice
     * @param bool|null $freeShipping
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, int $idProduct, int $idCombination, int $quantity, array $amounts, int $idInvoice, ?bool $freeShipping, int $idEmployee): array
    {
        $legacy = Somcp::legacyContext();
        $services = SomcpToolUpdateOrderProduct::services();

        $before = [];
        foreach ($order->getProducts() as $line) {
            $before[] = (int) $line['id_order_detail'];
        }

        try {
            $command = $idInvoice > 0
                ? AddProductToOrderCommand::toExistingInvoice(
                    (int) $order->id,
                    $idInvoice,
                    $idProduct,
                    $idCombination,
                    (string) $amounts['incl'],
                    (string) $amounts['excl'],
                    $quantity
                )
                : AddProductToOrderCommand::withNewInvoice(
                    (int) $order->id,
                    $idProduct,
                    $idCombination,
                    (string) $amounts['incl'],
                    (string) $amounts['excl'],
                    $quantity,
                    $freeShipping
                );
            // Built through reflection: the five-service constructor is the
            // one of every version SomcpToolUpdateOrderProduct::supported()
            // lets through, and PHPStan, which reads one version at a time,
            // would refuse it on 9.2.
            $handler = (new ReflectionClass(AddProductToOrderHandler::class))->newInstanceArgs([
                $legacy->getTranslator(),
                $services['context'],
                $services['amount'],
                $services['quantity'],
                $services['detail'],
            ]);
            $handler->handle($command);
        } catch (Throwable $e) {
            SomcpLogger::log(
                'order_add_product_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'order ' . (int) $order->id . ', ' . get_class($e) . ' ' . (int) $e->getCode(),
                '',
                $idEmployee
            );

            return SomcpToolIssuePartialRefund::refused([self::reason($e)]);
        }

        $order = new Order((int) $order->id);
        $idLang = (int) $order->id_lang;
        $line = null;
        foreach (SomcpToolGetOrder::products($order, $idLang, (int) $order->id_shop) as $product) {
            if (!in_array((int) $product['id_order_detail'], $before, true)
                && (int) $product['id_product'] === $idProduct
                && (int) $product['id_product_attribute'] === $idCombination) {
                $line = $product;
            }
        }
        $idNewInvoice = 0;
        if ($line !== null) {
            $detail = new OrderDetail((int) $line['id_order_detail']);
            $idNewInvoice = (int) $detail->id_order_invoice;
        }
        $currency = new Currency((int) $order->id_currency);

        SomcpLogger::log(
            'order_add_product',
            SomcpLogger::OUTCOME_SUCCESS,
            'order ' . (int) $order->id . ', product ' . $idProduct . ($idCombination > 0 ? ':' . $idCombination : '')
                . ' x' . $quantity,
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'added' => true,
            'order' => [
                'id_order' => (int) $order->id,
                'reference' => (string) $order->reference,
            ],
            'line' => $line,
            'invoice' => $idNewInvoice > 0 ? ['id_order_invoice' => $idNewInvoice] : null,
            'totals' => SomcpToolGetOrder::totals($order),
            'currency' => Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '',
        ]);
    }

    /**
     * What the core refused, in plain words, by the exception it threw.
     *
     * Matched on the short class name: the exception classes are not the
     * same set on every 8.x and 9.x release, and naming one that is missing
     * would only fail at run time.
     *
     * @param Throwable $e
     *
     * @return string
     */
    private static function reason(Throwable $e): string
    {
        $class = substr((string) strrchr('\\' . get_class($e), '\\'), 1);
        $message = $e->getMessage();

        switch ($class) {
            case 'DuplicateProductInOrderException':
            case 'DuplicateProductInOrderInvoiceException':
                return 'id_product: this product is already a line of the order - change that line with '
                    . 'somcp_update_order_product';
            case 'ProductOutOfStockException':
                return 'quantity: not enough stock, and the shop refuses orders out of stock';
        }
        if (stripos($message, 'shipped') !== false) {
            return 'id_order: this order was shipped, so the order page has no Add a product';
        }
        if (preg_match('/Minimum quantity of "?(\d+)"?/', $message, $match) === 1) {
            return 'quantity: at least ' . (int) $match[1] . ' must be added';
        }
        if (stripos($message, 'out of stock') !== false) {
            return 'quantity: not enough stock, and the shop refuses orders out of stock';
        }

        return 'the product could not be added to the order';
    }
}
