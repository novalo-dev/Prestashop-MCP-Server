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
 * somcp_set_order_status - the first tool that writes.
 *
 * One order per call, deliberately. The back office can change a batch of
 * orders at once, and the temptation was to mirror that. Three reasons not to:
 * a batch that half fails leaves an answer nobody can act on; the client asks
 * the merchant before a destructive call, so one order is one question while
 * thirty is one click and thirty mails; and no write here is replayable, so
 * "the answer was lost on the way back" has no safe recovery on a batch. An
 * assistant with ten orders to move loops ten times, and the merchant sees ten
 * confirmations - which is the point.
 *
 * The sequence is the core's own, read from UpdateOrderStatusHandler on 8.0.0
 * and 9.1.5, not reinvented: refuse a status the order already has, build an
 * OrderHistory carrying the employee, changeIdOrderState() so every hook fires
 * and other modules follow, then addWithemail() so the customer mail leaves
 * exactly as it would from the back office.
 *
 * Writing through the core object rather than an UPDATE is what makes stock,
 * invoices, vouchers and every listening module agree with the order. A direct
 * query would change one column and quietly desynchronise the rest of the shop.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolSetOrderStatus
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_set_order_status',
            Somcp::t('Change the status of an order'),
            'Changes the status of one order, as the merchant does on the order page. Depending on the '
                . 'status this emails the customer, moves stock, issues an invoice or records a refund: calling '
                . 'again does not undo it. Take the identifier from somcp_list_order_statuses, never guess it, '
                . 'and pick the status by its role (shipped, delivered, refunded, canceled), not by its name. '
                . 'One order per call; report each result. Not to be retried on a lost answer: a second call '
                . 'can email the customer again - check the status with somcp_get_order.',
            [
                'type' => 'object',
                'properties' => [
                    'id_order' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the order to change, from somcp_search_orders.',
                    ],
                    'reference' => [
                        'type' => 'string',
                        'description' => 'Reference of the order to change, used when no identifier is known.',
                    ],
                    'status_id' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the status to set, taken from somcp_list_order_statuses.',
                    ],
                ],
                'required' => ['status_id'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The order page is where a merchant would do this by hand, and
            // editing is what it takes there.
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
        $order = self::locate($arguments);
        if ($order === null) {
            return SomcpTool::text('No order of this shop matches what was given.', true);
        }

        $idLang = (int) $order->id_lang;
        $target = new OrderState(SomcpTool::id($arguments, 'status_id'), $idLang);
        if (!Validate::isLoadedObject($target)) {
            return SomcpTool::text(
                'No such status in this shop. Call somcp_list_order_statuses to get the identifiers.',
                true
            );
        }

        $current = $order->getCurrentOrderState();
        $previous = Validate::isLoadedObject($current)
            ? ['status_id' => (int) $current->id, 'name' => (string) (new OrderState((int) $current->id, $idLang))->name]
            : null;

        // The core refuses this too, in UpdateOrderStatusHandler. Refusing it
        // here as well is what keeps a retried call from mailing the customer a
        // second time - the single likeliest way this tool causes harm.
        if ($previous !== null && $previous['status_id'] === (int) $target->id) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The order already has this status. Nothing was changed and no email was sent.',
                'order' => self::identify($order),
                'status' => ['status_id' => (int) $target->id, 'name' => (string) $target->name],
            ]);
        }

        return self::apply($order, $target, $previous, (int) ($context['id_employee'] ?? 0));
    }

    /**
     * Performs the change, following the core's own sequence.
     *
     * @param Order $order
     * @param OrderState $target
     * @param array{id:int, name:string}|null $previous
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, OrderState $target, ?array $previous, int $idEmployee): array
    {
        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        // changeIdOrderState() reads this property and only falls back to the
        // context employee when it is empty, so the order history carries the
        // name of the account behind the token rather than an anonymous line.
        $history->id_employee = $idEmployee;

        // An order with no invoice yet has its payments carried over, exactly as
        // the back office does it.
        $history->changeIdOrderState((int) $target->id, $order, !$order->hasInvoice());

        $templateVars = [];
        $shippingNumber = $order->getShippingNumber();
        if ((int) $history->id_order_state === (int) Configuration::get('PS_OS_SHIPPING') && $shippingNumber) {
            $carrier = new Carrier((int) $order->id_carrier, (int) $order->id_lang);
            $templateVars = ['{followup}' => str_replace('@', $shippingNumber, (string) $carrier->url)];
        }

        $done = $history->addWithemail(true, $templateVars);

        // addWithemail() returns false both when nothing was written and when
        // the status changed but the mail did not leave. Those call for opposite
        // reactions from the assistant, so they are never reported as one: the
        // history row having an id is what tells them apart.
        $written = (int) $history->id > 0;

        if (!$done && !$written) {
            SomcpLogger::log(
                'order_status_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'order ' . (int) $order->id . ' to status ' . (int) $target->id,
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The status could not be changed. The order is unchanged and no email was sent.',
                true
            );
        }

        self::synchroniseAdvancedStock($order);

        SomcpLogger::log(
            'order_status_changed',
            $done ? SomcpLogger::OUTCOME_SUCCESS : SomcpLogger::OUTCOME_FAILURE,
            'order ' . (int) $order->id . ' to status ' . (int) $target->id . ($done ? '' : ', email failed'),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'changed' => true,
            'order' => self::identify($order),
            'previous_status' => $previous,
            'status' => ['status_id' => (int) $target->id, 'name' => (string) $target->name],
            // What the shop is set to do at this status, and what actually
            // happened to the mail. Reported apart because a status that mails
            // the customer can still fail to send.
            'status_sends_email' => (bool) $target->send_email,
            'email_sent' => (bool) $target->send_email && $done,
            'warning' => $done
                ? null
                : 'The status was changed but the customer email could not be sent. '
                    . 'Do not repeat this call: the change is already recorded.',
            'changed_by_employee_id' => $idEmployee,
        ]);
    }

    /**
     * Advanced stock management, which only PrestaShop 8 still has.
     *
     * @param Order $order
     *
     * @return void
     */
    private static function synchroniseAdvancedStock(Order $order): void
    {
        // Held in a variable and called through it: written as a static call,
        // the method has to exist at analysis time, and on PrestaShop 9 it
        // does not - advanced stock management was taken out. The guard was
        // already there; what changes is that the call itself no longer names
        // a method the version being analysed has never heard of.
        $dependsOnStock = ['StockAvailable', 'dependsOnStock'];

        if (!is_callable($dependsOnStock) || !Configuration::get('PS_ADVANCED_STOCK_MANAGEMENT')) {
            return;
        }

        foreach ($order->getProducts() as $product) {
            if ($dependsOnStock((int) $product['product_id'])) {
                StockAvailable::synchronize((int) $product['product_id'], (int) $product['id_shop']);
            }
        }
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
     * Finds the order, and only within the shop the token belongs to.
     *
     * @param array<string, mixed> $arguments
     *
     * @return Order|null
     */
    private static function locate(array $arguments): ?Order
    {
        $idShop = Somcp::currentShopId();
        $idOrder = SomcpTool::id($arguments, 'id_order');

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
}
