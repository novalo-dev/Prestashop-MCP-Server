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
use PrestaShop\PrestaShop\Adapter\Order\CommandHandler\UpdateProductInOrderHandler;
use PrestaShop\PrestaShop\Adapter\Order\OrderAmountUpdater;
use PrestaShop\PrestaShop\Adapter\Order\OrderDetailUpdater;
use PrestaShop\PrestaShop\Adapter\Order\OrderProductQuantityUpdater;
use PrestaShop\PrestaShop\Adapter\Order\Refund\OrderProductRemover;
use PrestaShop\PrestaShop\Core\Domain\Order\Product\Command\UpdateProductInOrderCommand;

/*
 * somcp_update_order_product - Edit one product line of an order not delivered,
 * as the Edit action of a line in the Products block of the order page does:
 * the unit price, the quantity, or the invoice the line sits on, with the
 * totals recomputed.
 *
 * The core's own UpdateProductInOrderHandler does the work, built with new and
 * the three services it is built with in the back office (the same constructor
 * from 8.0.0 to 9.1): the order detail, the identical lines, the cart price,
 * the quantity, the amounts and actionOrderEdited stay exactly as the Update
 * button does them. The tool checks the arguments first so every mistake comes
 * back in one answer, and it reads the line before the call so a field left
 * out keeps its current value rather than being reset.
 *
 * PrestaShop 9.2 gave the handler four more services, for the shipments of the
 * improved shipping feature, which only the Symfony container builds and which
 * a front controller does not have. Rather than fake them, the tool refuses on
 * 9.2 and later and points to the Edit action of the order page.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// order lookup, the line and totals shapes, the employee on the context and
// the refusal envelope live in these classes.
require_once __DIR__ . '/SomcpToolGetOrder.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolIssuePartialRefund.php';

class SomcpToolUpdateOrderProduct
{
    /** @var string[] */
    private const KEYS = [
        'id_order', 'reference', 'id_order_detail', 'quantity',
        'unit_price_tax_excluded', 'unit_price_tax_included', 'id_order_invoice',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_order_product',
            Somcp::t('Edit a product line of an order'),
            'Changes the unit price, the quantity or the invoice of one product line of an order not delivered, '
                . 'like Edit on a line in the Products block of the order page, and recomputes the totals '
                . '(id_order_detail from products of somcp_get_order). To add a product use '
                . 'somcp_add_order_product, to remove a line somcp_delete_order_product; units the customer will '
                . 'not get go through somcp_cancel_order_products or somcp_issue_partial_refund. Say the new total '
                . 'and wait for the go-ahead. Never to be retried on a lost answer: check somcp_get_order.',
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
                    'id_order_detail' => [
                        'type' => 'integer',
                        'description' => 'The line to edit, from products[].id_order_detail of somcp_get_order.',
                    ],
                    'quantity' => [
                        'type' => 'integer',
                        'description' => 'New quantity, at least 1. Left out: unchanged. A quantity of 0 is '
                            . 'refused: remove the line with somcp_delete_order_product.',
                    ],
                    'unit_price_tax_excluded' => [
                        'type' => 'number',
                        'description' => 'New unit price tax excl., in the order currency. Left out: unchanged.',
                    ],
                    'unit_price_tax_included' => [
                        'type' => 'number',
                        'description' => 'New unit price tax incl., in the order currency. Given alone, the price '
                            . 'tax excl. is worked out with the tax rate of the line.',
                    ],
                    'id_order_invoice' => [
                        'type' => 'integer',
                        'description' => 'Move the line to another invoice of the order, from '
                            . 'documents.invoices[].id_order_invoice of somcp_get_order. Left out: unchanged.',
                    ],
                ],
                'required' => ['id_order_detail'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Editing a line sits on the order page and takes the edit right
            // there.
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

        // Read before it is used in a refusal; validated once the order and the
        // line are known.
        $wantsQuantity = array_key_exists('quantity', $arguments);
        $quantity = $arguments['quantity'] ?? null;
        if ($wantsQuantity && (!is_int($quantity) || $quantity < 0)) {
            $problems[] = 'quantity: must be a whole number, at least 1';
            $quantity = null;
        } elseif ($wantsQuantity && $quantity === 0) {
            $problems[] = 'quantity: 0 removes the line, which this tool does not do - remove it with '
                . 'somcp_delete_order_product';
            $quantity = null;
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

        $order = SomcpToolGetOrder::find($arguments);
        if (!$order instanceof Order) {
            $problems[] = $order;

            return SomcpToolIssuePartialRefund::refused($problems);
        }

        // The line, read from the order so it is proven to belong to it, and so
        // a field left out keeps its current value.
        $idOrderDetail = SomcpTool::id($arguments, 'id_order_detail');
        $current = null;
        if ($idOrderDetail <= 0) {
            $problems[] = 'id_order_detail: give the line, from products[].id_order_detail of somcp_get_order';
        } else {
            foreach ($order->getProducts() as $line) {
                if ((int) $line['id_order_detail'] === $idOrderDetail) {
                    $current = $line;
                    break;
                }
            }
            if ($current === null) {
                $problems[] = 'id_order_detail: no line of this order has the identifier ' . $idOrderDetail
                    . ' - find it with somcp_get_order';
            }
        }

        $idInvoice = SomcpTool::id($arguments, 'id_order_invoice');
        $wantsInvoice = array_key_exists('id_order_invoice', $arguments);
        if ($wantsInvoice) {
            $invoice = new OrderInvoice($idInvoice);
            if ($idInvoice <= 0 || !Validate::isLoadedObject($invoice) || (int) $invoice->id_order !== (int) $order->id) {
                $problems[] = 'id_order_invoice: not an invoice of this order - read them in documents.invoices of '
                    . 'somcp_get_order';
            }
        }

        // Nothing asked to change: the order page Update button always submits a
        // value, so the tool asks for at least one field rather than rewriting
        // the line with itself.
        if (!$wantsQuantity && $prices === [] && !$wantsInvoice) {
            $problems[] = 'give at least one of quantity, unit_price_tax_excluded, unit_price_tax_included or '
                . 'id_order_invoice to change';
        }

        if ($problems !== [] || $current === null) {
            return SomcpToolIssuePartialRefund::refused($problems);
        }

        $precision = self::precision($order);
        $rate = (float) $current['tax_rate'];
        $amounts = self::prices($current, $prices, $rate, $precision, $problems);
        if ($problems !== [] || !is_array($amounts)) {
            return SomcpToolIssuePartialRefund::refused($problems);
        }

        $targetQuantity = $wantsQuantity ? (int) $quantity : (int) $current['product_quantity'];
        $targetInvoice = $wantsInvoice ? $idInvoice : 0;
        $currentInvoice = (int) $current['id_order_invoice'];

        // Last of the checks, so the mistakes in the arguments still come back
        // on every version.
        if (!self::supported()) {
            return SomcpToolIssuePartialRefund::refused([
                'this PrestaShop version edits an order line together with its shipments, which this tool cannot '
                    . 'do yet - use the Edit action in the Products block of the order page',
            ]);
        }

        // The values are absolute: nothing different from the line as it stands
        // writes nothing, as the order page would save no change.
        $changes = self::changes($current, $amounts, $targetQuantity, $wantsInvoice, $targetInvoice, $currentInvoice, $precision);
        if ($changes === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'the line already has these values',
                'order' => [
                    'id_order' => (int) $order->id,
                    'reference' => (string) $order->reference,
                ],
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($order, $idOrderDetail, $amounts, $targetQuantity, $wantsInvoice, $targetInvoice, $changes, $idEmployee): array {
            return self::apply($order, $idOrderDetail, $amounts, $targetQuantity, $wantsInvoice, $targetInvoice, $changes, $idEmployee);
        });
    }

    /**
     * The unit prices to record, tax excluded and included.
     *
     * A field left out keeps the line's current value. Given one of the two,
     * the other is worked out with the line's tax rate, as the order page does;
     * given both, they must agree with it to the currency's precision.
     *
     * @param array<string, mixed> $current
     * @param array<string, float> $given
     * @param float $rate
     * @param int $precision
     * @param string[] $problems
     *
     * @return array{excl: float, incl: float}|null
     */
    private static function prices(array $current, array $given, float $rate, int $precision, array &$problems): ?array
    {
        $excl = $given['unit_price_tax_excluded'] ?? null;
        $incl = $given['unit_price_tax_included'] ?? null;

        if ($excl === null && $incl === null) {
            $excl = (float) $current['unit_price_tax_excl'];
            $incl = (float) $current['unit_price_tax_incl'];
        } elseif ($incl === null) {
            $incl = $excl * (1 + $rate / 100);
        } elseif ($excl === null) {
            $excl = $incl / (1 + $rate / 100);
        } elseif (abs(Tools::ps_round($excl * (1 + $rate / 100), $precision) - Tools::ps_round((float) $incl, $precision))
            > 1 / (10 ** $precision)) {
            // The pair is compared the way somcp_add_order_product compares it,
            // to the currency precision.
            $problems[] = 'unit_price_tax_included: does not agree with unit_price_tax_excluded and the tax rate '
                . 'of the line (' . $rate . ' %) - give one of the two';

            return null;
        }

        return ['excl' => (float) $excl, 'incl' => (float) $incl];
    }

    /**
     * The currency precision of the order.
     *
     * @param Order $order
     *
     * @return int
     */
    private static function precision(Order $order): int
    {
        $currency = new Currency((int) $order->id_currency);

        return Validate::isLoadedObject($currency) ? (int) $currency->precision : 2;
    }

    /**
     * What the call changes, under the parameter names, each with its old and
     * new value. Empty when the line already holds these values.
     *
     * @param array<string, mixed> $current
     * @param array{excl: float, incl: float} $amounts
     * @param int $targetQuantity
     * @param bool $wantsInvoice
     * @param int $targetInvoice
     * @param int $currentInvoice
     * @param int $precision
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private static function changes(array $current, array $amounts, int $targetQuantity, bool $wantsInvoice, int $targetInvoice, int $currentInvoice, int $precision): array
    {
        $changes = [];
        $step = 1 / (10 ** $precision);

        $oldExcl = (float) $current['unit_price_tax_excl'];
        if (abs(Tools::ps_round($amounts['excl'], $precision) - Tools::ps_round($oldExcl, $precision)) >= $step) {
            $changes['unit_price_tax_excluded'] = ['old' => $oldExcl, 'new' => Tools::ps_round($amounts['excl'], $precision)];
        }
        $oldIncl = (float) $current['unit_price_tax_incl'];
        if (abs(Tools::ps_round($amounts['incl'], $precision) - Tools::ps_round($oldIncl, $precision)) >= $step) {
            $changes['unit_price_tax_included'] = ['old' => $oldIncl, 'new' => Tools::ps_round($amounts['incl'], $precision)];
        }
        $oldQuantity = (int) $current['product_quantity'];
        if ($targetQuantity !== $oldQuantity) {
            $changes['quantity'] = ['old' => $oldQuantity, 'new' => $targetQuantity];
        }
        if ($wantsInvoice && $targetInvoice !== $currentInvoice) {
            $changes['id_order_invoice'] = ['old' => $currentInvoice, 'new' => $targetInvoice];
        }

        return $changes;
    }

    /**
     * Whether the order line handlers still take the services services()
     * builds.
     *
     * PrestaShop 9.2 gave the add and edit handlers more services, for the
     * shipments of the improved shipping feature, which only the Symfony
     * container builds. A line written without them would be unknown to the
     * shipments, so the tools that add, edit and remove a line refuse from
     * 9.2 on, all on this one check.
     *
     * @return bool
     */
    public static function supported(): bool
    {
        return version_compare(_PS_VERSION_, '9.2.0', '<');
    }

    /**
     * The core services the order line handlers are built with in the back
     * office, built with new since a front controller has no Symfony
     * container. Shared by the tools that add, edit, cancel and remove a line,
     * so the chain is the same wherever an order line changes.
     *
     * @return array{context: ContextStateManager, remover: OrderProductRemover, detail: OrderDetailUpdater, amount: OrderAmountUpdater, quantity: OrderProductQuantityUpdater}
     */
    public static function services(): array
    {
        $configuration = new CoreConfiguration();
        $contextManager = new ContextStateManager(new LegacyContext());
        $remover = new OrderProductRemover();
        $detailUpdater = new OrderDetailUpdater($contextManager, $configuration);
        $amountUpdater = new OrderAmountUpdater($configuration, $contextManager, $detailUpdater, $remover);

        return [
            'context' => $contextManager,
            'remover' => $remover,
            'detail' => $detailUpdater,
            'amount' => $amountUpdater,
            'quantity' => new OrderProductQuantityUpdater($amountUpdater, $remover, $contextManager),
        ];
    }

    /**
     * Runs the core handler and reports the line and the totals after it.
     *
     * @param Order $order
     * @param int $idOrderDetail
     * @param array{excl: float, incl: float} $amounts
     * @param int $quantity
     * @param bool $wantsInvoice
     * @param int $idInvoice
     * @param array<string, array{old: mixed, new: mixed}> $changes
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, int $idOrderDetail, array $amounts, int $quantity, bool $wantsInvoice, int $idInvoice, array $changes, int $idEmployee): array
    {
        $services = self::services();

        try {
            $command = new UpdateProductInOrderCommand(
                (int) $order->id,
                $idOrderDetail,
                (string) $amounts['incl'],
                (string) $amounts['excl'],
                $quantity,
                $wantsInvoice ? $idInvoice : null
            );
            // Built through reflection: the three-service constructor is the one
            // of every version supported() lets through, and PHPStan, which
            // reads one version at a time, would refuse it on 9.2.
            $handler = (new ReflectionClass(UpdateProductInOrderHandler::class))->newInstanceArgs([
                $services['quantity'],
                $services['detail'],
                $services['context'],
            ]);
            $handler->handle($command);
        } catch (Throwable $e) {
            SomcpLogger::log(
                'order_update_product_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'order ' . (int) $order->id . ', line ' . $idOrderDetail . ', ' . get_class($e) . ' ' . (int) $e->getCode(),
                '',
                $idEmployee
            );

            return SomcpToolIssuePartialRefund::refused([self::reason($e)]);
        }

        $order = new Order((int) $order->id);
        $idLang = (int) $order->id_lang;
        $line = null;
        foreach (SomcpToolGetOrder::products($order, $idLang, (int) $order->id_shop) as $product) {
            if ((int) $product['id_order_detail'] === $idOrderDetail) {
                $line = $product;
                break;
            }
        }
        $currency = new Currency((int) $order->id_currency);

        SomcpLogger::log(
            'order_update_product',
            SomcpLogger::OUTCOME_SUCCESS,
            'order ' . (int) $order->id . ', line ' . $idOrderDetail . ', ' . implode(' ', array_keys($changes)),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'changed' => true,
            'order' => [
                'id_order' => (int) $order->id,
                'reference' => (string) $order->reference,
            ],
            'changes' => $changes,
            'line' => $line,
            'totals' => SomcpToolGetOrder::totals($order),
            'currency' => Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '',
        ]);
    }

    /**
     * What the core refused, in plain words, by the exception it threw.
     *
     * Matched on the short class name: the exception classes are not the same
     * set on every 8.x and 9.x release, and naming one that is missing would
     * only fail at run time.
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
            case 'CannotEditDeliveredOrderProductException':
                return 'id_order_detail: this order was delivered, so its lines can no longer be edited';
            case 'CannotFindProductInOrderException':
                return 'id_order_detail: the product of this line no longer exists in the catalogue';
            case 'DuplicateProductInOrderInvoiceException':
                return 'id_order_invoice: that invoice already holds this product - the line cannot be moved to it';
            case 'ProductOutOfStockException':
                return 'quantity: not enough stock, and the shop refuses orders out of stock';
        }
        if (stripos($message, 'delivered') !== false) {
            return 'id_order_detail: this order was delivered, so its lines can no longer be edited';
        }
        if (stripos($message, 'stock') !== false) {
            return 'quantity: not enough stock, and the shop refuses orders out of stock';
        }

        return 'the line could not be edited';
    }
}
