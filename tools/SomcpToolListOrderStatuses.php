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
 * somcp_list_order_statuses - the statuses this shop actually has.
 *
 * Identifiers are not the same from one shop to the next: a merchant renames
 * them, adds their own, removes the ones they never use. An assistant asked for
 * "the orders awaiting payment" and left without this tool does what it did
 * before this one existed - it lists orders and reads their labels, guesses, and
 * says so. Harmless while everything is read-only. The day a tool changes a
 * status, a guessed identifier sends the wrong mail to a customer.
 *
 * Which is why each status also says what it sets off: whether it counts as
 * paid, as shipped, as a refund, and whether reaching it mails the customer. An
 * assistant can then tell "Payment accepted" from "Awaiting bank wire payment"
 * without knowing this shop.
 *
 * It belongs to Configuration rather than Orders: an order uses a status, it
 * does not contain one.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListOrderStatuses
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_order_statuses',
            Somcp::t('List order statuses'),
            'Lists the order statuses of this shop with their identifiers and what each means: paid, shipped '
                . 'or delivered, whether reaching it emails the customer and with what. Call it before filtering '
                . 'orders by status or changing one. Identifiers differ from shop to shop: never assume one. Each '
                . 'status lists its roles (shipped, delivered, refunded, payment_accepted...): match on a role, not '
                . 'on the name, which is translated and the merchant can change; no role means the merchant created '
                . 'it. To read orders use somcp_search_orders.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of the language to read the names in, '
                            . 'for example fr. The shop default is used otherwise.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_READ],
            [self::class, 'handle'],
            // Statuses are shop settings, and that is the page a merchant would
            // open to look at them.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminStatuses')
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
        $idLang = self::language($arguments);

        $roles = self::roles();

        $statuses = [];
        foreach (OrderState::getOrderStates($idLang) as $state) {
            $id = (int) $state['id_order_state'];
            $statuses[] = [
                'id' => $id,
                'name' => (string) $state['name'],
                // The part this status plays in the shop, which survives a
                // rename where the name does not.
                'roles' => $roles[$id] ?? [],
                // What reaching this status does, which is what tells one shop's
                // "Payment accepted" from another's "Awaiting bank wire".
                'paid' => (bool) $state['paid'],
                'shipped' => (bool) $state['shipped'],
                'delivery' => (bool) $state['delivery'],
                'sends_email' => (bool) $state['send_email'],
                'invoice' => (bool) $state['invoice'],
                'counts_as_valid' => (bool) $state['logable'],
                'hidden_from_customer' => (bool) $state['hidden'],
                // Attached to the email the status sends, when it sends one.
                'attaches_invoice_pdf' => (bool) $state['pdf_invoice'],
                'attaches_delivery_slip_pdf' => (bool) $state['pdf_delivery'],
                // A status PrestaShop itself relies on. Telling these apart from
                // the merchant's own says which ones are safe to reason about
                // across shops.
                'built_in' => (bool) $state['unremovable'],
                'email_template' => (string) $state['template'],
                'payment_module' => (string) $state['module_name'],
                'color' => (string) $state['color'],
            ];
        }

        return SomcpTool::json([
            'statuses' => $statuses,
            'count' => count($statuses),
            'language_id' => $idLang,
        ]);
    }

    /**
     * Which status plays which part in this shop, by status identifier.
     *
     * PrestaShop records the part a status plays in its own configuration, and
     * that is the only reliable way to find it: a merchant renames "Shipped" and
     * the name stops telling anyone anything, while the key still points at the
     * right row. Reading it here is what lets an assistant asked to mark an
     * order shipped find the status instead of guessing from a translated label.
     *
     * A status carries a list rather than a single part: in a default install
     * PS_OS_OUTOFSTOCK and PS_OS_OUTOFSTOCK_UNPAID land on the same one.
     *
     * The fourteen keys are the same on 8.0.0 and on 9.1.5, checked against the
     * installer of both. Configuration::get() resolves them through the shop
     * this request belongs to on its own, so nothing is passed to it.
     *
     * @return array<int, array<int, string>>
     */
    private static function roles(): array
    {
        $parts = [
            'PS_OS_PAYMENT' => 'payment_accepted',
            'PS_OS_WS_PAYMENT' => 'payment_accepted_remotely',
            'PS_OS_PREPARATION' => 'preparation_in_progress',
            'PS_OS_SHIPPING' => 'shipped',
            'PS_OS_DELIVERED' => 'delivered',
            'PS_OS_CANCELED' => 'canceled',
            'PS_OS_REFUND' => 'refunded',
            'PS_OS_ERROR' => 'payment_error',
            'PS_OS_OUTOFSTOCK' => 'out_of_stock',
            'PS_OS_OUTOFSTOCK_PAID' => 'out_of_stock_paid',
            'PS_OS_OUTOFSTOCK_UNPAID' => 'out_of_stock_unpaid',
            'PS_OS_BANKWIRE' => 'awaiting_bank_wire_payment',
            'PS_OS_CHEQUE' => 'awaiting_cheque_payment',
            'PS_OS_COD_VALIDATION' => 'awaiting_cash_on_delivery_validation',
        ];

        $roles = [];
        foreach ($parts as $key => $role) {
            $id = (int) Configuration::get($key);
            if ($id > 0) {
                $roles[$id][] = $role;
            }
        }

        return $roles;
    }

    /**
     * The language to read the names in.
     *
     * @param array<string, mixed> $arguments
     *
     * @return int
     */
    private static function language(array $arguments): int
    {
        $iso = trim((string) ($arguments['language'] ?? ''));
        if ($iso !== '') {
            $idLang = (int) Language::getIdByIso($iso);
            if ($idLang > 0) {
                return $idLang;
            }
        }

        return (int) Somcp::legacyContext()->language->id;
    }
}
