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
 * somcp_resend_order_status_email - the Resend email action on a line of the
 * Status tab of the order page.
 *
 * Sends again the email of one past status of the order. The core path
 * (ResendOrderEmailCommand then ResendOrderEmailHandler, identical on 8.0.1
 * and 9.0.0) loads the status, reads the history line, adds {followup} when
 * the status is PS_OS_SHIPPING and the order carries a shipping number, then
 * calls OrderHistory::sendEmail(). The handler trusts the history line it is
 * given: it checks neither that the line belongs to the order nor that its
 * status is the one asked for. So the tool takes the line only, checks it
 * belongs to the order and reads the status from it, rather than letting a
 * line of another order mail that order's customer.
 *
 * A status whose order_state.send_email is 0 mails nobody: refusing it is
 * truer than reporting a success that sent nothing. Every call emails the
 * customer and the order keeps no trace of it, so the description tells the
 * assistant to call it once and never retry on a lost answer.
 *
 * Note on the shared helpers assumed by issue #128: id_order_history is read
 * by SomcpTool::id(), like every order tool's identifiers;
 * SomcpTool::refused() (#169, #170) is not on develop yet, so the refusal is
 * built here in the order tools' shape until that issue lands.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// shared order lookup lives in the first class, asEmployee() in the second.
require_once __DIR__ . '/SomcpToolGetOrder.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';

class SomcpToolResendOrderStatusEmail
{
    /** @var array<int, string> The arguments the tool reads; anything else is refused. */
    private const KEYS = ['id_order', 'reference', 'id_order_history'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_resend_order_status_email',
            Somcp::t('Resend the email of an order status'),
            'Sends again the email of one past status of an order, like Resend email on a line of the Status '
                . 'tab of the order page. Take the status line from history of somcp_get_order. A status the '
                . 'shop does not email is refused. This does not change the status (that is somcp_set_order_status) '
                . 'and does not write to the customer (that is somcp_add_order_message). Every call emails the '
                . 'customer and nothing on the order records it: call it once and do not retry on a lost answer.',
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
                    'id_order_history' => [
                        'type' => 'integer',
                        'description' => 'Which status line to resend, from history[].id_order_history of '
                            . 'somcp_get_order. Never guessed.',
                    ],
                ],
                'required' => ['id_order_history'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The Resend email action sits on the order page, and using it
            // takes the edit right there.
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
        // an array into 1 and resend a status line nobody named.
        $idOrderHistory = array_key_exists('id_order_history', $arguments)
            ? SomcpTool::id($arguments, 'id_order_history')
            : 0;
        if (!array_key_exists('id_order_history', $arguments)) {
            $problems[] = 'id_order_history: required, the status line to resend - take it from history of '
                . 'somcp_get_order';
        } elseif ($idOrderHistory <= 0) {
            $problems[] = 'id_order_history: give the status line identifier, a whole number';
        }

        // find() rather than the newest order of a reference: the resend emails the customer, so a
        // reference shared by the orders of a split cart is refused with their
        // list instead of landing on the newest of them.
        $order = SomcpToolGetOrder::find($arguments);
        if (!$order instanceof Order) {
            $problems[] = (array_key_exists('reference', $arguments) && (int) ($arguments['id_order'] ?? 0) <= 0
                ? 'reference: '
                : 'id_order: ') . $order;

            return self::refused($problems);
        }

        if ($problems !== []) {
            return self::refused($problems);
        }

        $idLang = (int) $order->id_lang;

        // The line as the Status tab lists it, checked to belong to this order:
        // the core handler does not check it, so a line of another order would
        // otherwise mail that order's customer.
        $line = self::line($order, $idLang, $idOrderHistory);
        if ($line === null) {
            return self::refused([
                'id_order_history: not a status line of this order - find it in history of somcp_get_order',
            ]);
        }

        $state = new OrderState((int) $line['status_id'], $idLang);
        // A status the shop does not email sends nothing; a success here would
        // claim an email that never left.
        if (!Validate::isLoadedObject($state) || !(bool) $state->send_email) {
            return self::refused([
                'id_order_history: the status ' . (string) $line['status'] . ' sends no email',
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($order, $state, $line, $idEmployee): array {
            return self::apply($order, $state, $line, $idEmployee);
        });
    }

    /**
     * Resends the mail of the status line, as the core handler does.
     *
     * @param Order $order
     * @param OrderState $state
     * @param array<string, mixed> $line
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, OrderState $state, array $line, int $idEmployee): array
    {
        $history = new OrderHistory((int) $line['id_order_history']);
        if (!Validate::isLoadedObject($history) || (int) $history->id_order !== (int) $order->id) {
            return SomcpTool::text('The status line could not be read. Nothing was sent.', true);
        }

        // The core mails in the order's language; set it so the email and the
        // links in it reach the customer in their language, not the call's.
        $legacy = Somcp::legacyContext();
        $previousLanguage = $legacy->language;
        $language = new Language((int) $order->id_lang);
        if (Validate::isLoadedObject($language)) {
            $legacy->language = $language;
        }

        $templateVars = [];
        // The {followup} the back office adds when it resends a shipping email:
        // the carrier URL with the shipment number in place of the @.
        $shippingNumber = $order->getShippingNumber();
        if ((int) $history->id_order_state === (int) Configuration::get('PS_OS_SHIPPING') && $shippingNumber) {
            $carrier = new Carrier((int) $order->id_carrier, (int) $order->id_lang);
            $templateVars = ['{followup}' => str_replace('@', $shippingNumber, (string) $carrier->url)];
        }

        try {
            $sent = (bool) $history->sendEmail($order, $templateVars);
        } catch (Throwable $e) {
            // Thrown when the customer or the template cannot be loaded: the
            // mail did not leave, reported as a failure rather than a crash.
            $sent = false;
        } finally {
            $legacy->language = $previousLanguage;
        }

        SomcpLogger::log(
            'order_resend_status_email',
            $sent ? SomcpLogger::OUTCOME_SUCCESS : SomcpLogger::OUTCOME_FAILURE,
            'order ' . (int) $order->id . ', history ' . (int) $history->id . ', status ' . (int) $state->id
                . ($sent ? '' : ', email failed'),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'sent' => $sent,
            'order' => self::identify($order),
            'history_line' => $line,
            'template' => (string) $state->template,
            'warning' => $sent
                ? 'The email was sent again. Do not repeat this call: each call emails the customer, and '
                    . 'nothing on the order records it.'
                : 'The email could not be sent. The order is unchanged.',
        ]);
    }

    /**
     * One status line of the order, in the shape somcp_get_order gives the
     * entries of its history, or null when no line carries this identifier.
     *
     * @param Order $order
     * @param int $idLang
     * @param int $idOrderHistory
     *
     * @return array<string, mixed>|null
     */
    private static function line(Order $order, int $idLang, int $idOrderHistory): ?array
    {
        foreach ($order->getHistory($idLang) as $step) {
            if ((int) $step['id_order_history'] === $idOrderHistory) {
                return [
                    'id_order_history' => (int) $step['id_order_history'],
                    'date' => (string) $step['date_add'],
                    'status' => (string) $step['ostate_name'],
                    'status_id' => (int) $step['id_order_state'],
                    'employee' => trim(
                        (string) ($step['employee_firstname'] ?? '') . ' '
                        . (string) ($step['employee_lastname'] ?? '')
                    ),
                ];
            }
        }

        return null;
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
     * The order tools' refusal: every problem at once, nothing sent.
     *
     * Kept here, in the wording issue #128 asks for, until SomcpTool::refused()
     * of #169 and #170 unifies the order tools on one helper.
     *
     * @param string[] $problems
     *
     * @return array<string, mixed>
     */
    private static function refused(array $problems): array
    {
        return SomcpTool::text("Nothing was sent.\n- " . implode("\n- ", $problems), true);
    }
}
