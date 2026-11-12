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

use PrestaShop\PrestaShop\Adapter\Order\CommandHandler\DeleteProductFromOrderHandler;
use PrestaShop\PrestaShop\Core\Domain\Order\Product\Command\DeleteProductFromOrderCommand;

/*
 * somcp_delete_order_product - the Delete action of a line in the Products
 * block of the order page: one product line taken off an order not
 * delivered, the totals recomputed. No credit slip, no voucher: it is
 * neither a cancellation nor a refund.
 *
 * The core's own DeleteProductFromOrderHandler does the work, built with new
 * and the services the order line tools share
 * (SomcpToolUpdateOrderProduct::services()): the context set to the order,
 * the quantity brought to 0 on the line's invoice, the amounts and
 * actionOrderEdited stay exactly as the Delete action does them. The tool
 * checks the arguments first so every mistake comes back in one answer.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// order lookup, the line and totals shapes, the employee on the context, the
// refusal envelope and the shared core services live in these classes.
require_once __DIR__ . '/SomcpToolGetOrder.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolIssuePartialRefund.php';
require_once __DIR__ . '/SomcpToolUpdateOrderProduct.php';

class SomcpToolDeleteOrderProduct
{
    /** @var string[] */
    private const KEYS = ['id_order', 'reference', 'id_order_detail'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_order_product',
            Somcp::t('Remove a product line from an order'),
            'Removes one product line from one order not delivered, like Delete on a line in the Products block '
                . 'of the order page, and recomputes the totals (id_order_detail from products of '
                . 'somcp_get_order). Nothing is refunded: for units the customer paid use '
                . 'somcp_cancel_order_products or somcp_issue_partial_refund, to change a quantity '
                . 'somcp_update_order_product. The last line is refused. Say the new total and wait for the '
                . 'go-ahead. Never to be retried on a lost answer: check somcp_get_order.',
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
                        'description' => 'The line to remove, from products[].id_order_detail of somcp_get_order.',
                    ],
                ],
                'required' => ['id_order_detail'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The back office removes a line with the right to edit orders,
            // like every other change of an order's content.
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
        if (!$order instanceof Order) {
            $problems[] = (array_key_exists('reference', $arguments) && SomcpTool::id($arguments, 'id_order') <= 0
                ? 'reference' : 'id_order') . ': ' . $order;

            return SomcpToolIssuePartialRefund::refused($problems);
        }

        // The line, read from the order with the shape of somcp_get_order, so
        // it is proven to belong to it and can be reported as it was.
        $idOrderDetail = SomcpTool::id($arguments, 'id_order_detail');
        $lines = SomcpToolGetOrder::products($order, (int) $order->id_lang, (int) $order->id_shop);
        $line = null;
        if ($idOrderDetail <= 0) {
            $problems[] = 'id_order_detail: give the line, from products[].id_order_detail of somcp_get_order';
        } else {
            foreach ($lines as $product) {
                if ((int) $product['id_order_detail'] === $idOrderDetail) {
                    $line = $product;
                    break;
                }
            }
            if ($line === null) {
                $problems[] = 'id_order_detail: no line of this order has the identifier ' . $idOrderDetail
                    . ' - find it with somcp_get_order';
            }
        }

        if ($problems !== [] || $line === null) {
            return SomcpToolIssuePartialRefund::refused($problems);
        }

        // The back office lets the last line go and leaves an order with no
        // product, which no tool can then put right: cancelling the order is
        // what the merchant means.
        if (count($lines) <= 1) {
            return SomcpToolIssuePartialRefund::refused([
                'id_order_detail: this is the last line of the order, and an order cannot be left without a '
                    . 'product - cancel the order with somcp_set_order_status instead',
            ]);
        }

        if ($order->hasBeenDelivered()) {
            return SomcpToolIssuePartialRefund::refused([
                'id_order: this order was delivered, so its lines can no longer be removed',
            ]);
        }

        // Last of the checks, so the mistakes in the arguments still come back
        // on every version.
        if (!SomcpToolUpdateOrderProduct::supported()) {
            return SomcpToolIssuePartialRefund::refused([
                'this PrestaShop version removes an order line together with its shipments, which this tool '
                    . 'cannot do yet - use the Delete action in the Products block of the order page',
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($order, $idOrderDetail, $line, $idEmployee): array {
            return self::apply($order, $idOrderDetail, $line, $idEmployee);
        });
    }

    /**
     * Runs the core handler and reports the line removed and the totals after
     * it.
     *
     * @param Order $order
     * @param int $idOrderDetail
     * @param array<string, mixed> $line the line as it was, before the removal
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, int $idOrderDetail, array $line, int $idEmployee): array
    {
        $services = SomcpToolUpdateOrderProduct::services();

        try {
            // Built through reflection like the other order line handlers, so
            // the analysis of each PrestaShop version accepts the call.
            $handler = (new ReflectionClass(DeleteProductFromOrderHandler::class))->newInstanceArgs([
                $services['context'],
                $services['quantity'],
            ]);
            $handler->handle(new DeleteProductFromOrderCommand((int) $order->id, $idOrderDetail));
        } catch (Throwable $e) {
            SomcpLogger::log(
                'order_delete_product_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'order ' . (int) $order->id . ', line ' . $idOrderDetail . ', ' . get_class($e) . ' ' . (int) $e->getCode(),
                '',
                $idEmployee
            );

            return SomcpToolIssuePartialRefund::refused([self::reason($e)]);
        }

        $order = new Order((int) $order->id);
        $currency = new Currency((int) $order->id_currency);

        SomcpLogger::log(
            'order_delete_product',
            SomcpLogger::OUTCOME_SUCCESS,
            'order ' . (int) $order->id . ', line ' . $idOrderDetail,
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'deleted' => true,
            'order' => [
                'id_order' => (int) $order->id,
                'reference' => (string) $order->reference,
            ],
            'line' => $line,
            'totals' => SomcpToolGetOrder::totals($order),
            'currency' => Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '',
        ]);
    }

    /**
     * What the core refused, in plain words, by its message: the handler
     * throws a plain OrderException for each case.
     *
     * @param Throwable $e
     *
     * @return string
     */
    private static function reason(Throwable $e): string
    {
        $message = $e->getMessage();

        if (stripos($message, 'delivered') !== false) {
            return 'id_order: this order was delivered, so its lines can no longer be removed';
        }
        if (stripos($message, 'does not belong') !== false || stripos($message, 'could not be found') !== false) {
            return 'id_order_detail: no line of this order has the identifier - find it with somcp_get_order';
        }

        return 'the line could not be removed';
    }
}
