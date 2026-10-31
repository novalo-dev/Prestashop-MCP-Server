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
 * somcp_add_order_message - the Messages block of the order page: a message,
 * the Display to customer? checkbox and Send message.
 *
 * The sequence is the core's own, read from AddOrderCustomerMessageHandler on
 * 8.0.1 and 9.0.0, where it is identical: refuse a message holding a script or
 * an event attribute, find the order's customer thread or open one, add the
 * CustomerMessage under the employee, then, only when the customer is meant
 * to see it, email the order_merchant_comment template in the order's
 * language. The command bus is not used: its handler is built with context
 * ids (shop, language, employee) that only exist inside a back office request.
 *
 * Nothing guards against a repeat here, unlike the status or the tracking
 * number: two identical messages are a legitimate thing to write. That is why
 * the description insists on reading the order instead of calling again.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// order lookup lives in the first, and the employee on the context in the other.
require_once __DIR__ . '/SomcpToolGetOrder.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';

class SomcpToolAddOrderMessage
{
    /** @var int The limit of the Message field of the order page, on 8 and 9. */
    private const MAX_LENGTH = 1200;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_add_order_message',
            Somcp::t('Add a message to an order'),
            'Adds one message to one order, like the Messages block of the order page. With '
                . 'display_to_customer true the customer sees it in their account and is emailed it ("New '
                . 'message regarding your order"): tell the merchant before calling. With display_to_customer '
                . 'false it stays private to the shop and nobody is emailed. display_to_customer has no default: '
                . 'decide it with the merchant, never assume. The message is plain text, 1200 characters at '
                . 'most; one holding a script or an event attribute is refused. The order status is not touched '
                . '(that is somcp_set_order_status). Not for a customer service thread with no order, nor for '
                . 'the order note only the shop sees on the order (that is somcp_set_order_note). One message per '
                . 'call. Not to be retried on a lost answer: a second call adds the message twice and emails the '
                . 'customer twice - read the order with somcp_get_order, whose messages list shows it once saved.',
            [
                'type' => 'object',
                'properties' => [
                    'id_order' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the order.',
                    ],
                    'reference' => [
                        'type' => 'string',
                        'description' => 'Reference of the order, used when no identifier is known.',
                    ],
                    'message' => [
                        'type' => 'string',
                        'description' => 'The message, plain text, 1200 characters at most.',
                    ],
                    'display_to_customer' => [
                        'type' => 'boolean',
                        'description' => 'The Display to customer? checkbox. true: the customer sees the message '
                            . 'and is emailed it. false: private to the shop, no email.',
                    ],
                ],
                'required' => ['message', 'display_to_customer'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The Messages block sits on the order page, and sending from it
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

        // A strict boolean: "false" as a string would read as true and email a
        // message the merchant meant to keep private.
        if (!array_key_exists('display_to_customer', $arguments) || !is_bool($arguments['display_to_customer'])) {
            return SomcpTool::text(
                'Nothing was sent: display_to_customer must be true (the customer sees it and is emailed) or false '
                    . '(private to the shop). Ask the merchant which.',
                true
            );
        }
        $display = $arguments['display_to_customer'];

        if (!array_key_exists('message', $arguments) || !is_string($arguments['message'])) {
            return SomcpTool::text('Nothing was sent: message must be a string.', true);
        }
        $message = trim($arguments['message']);

        if ($message === '') {
            return SomcpTool::text('Nothing was sent: the message is empty.', true);
        }
        if (Tools::strlen($message) > self::MAX_LENGTH) {
            return SomcpTool::text(
                'Nothing was sent: a message holds ' . self::MAX_LENGTH . ' characters at most.',
                true
            );
        }
        // The check behind the core's CleanHtml constraint, so the shop refuses
        // here exactly what it refuses on the order page.
        if (!Validate::isCleanHtml($message)) {
            return SomcpTool::text(
                'Nothing was sent: the message holds a script or an event attribute, which PrestaShop refuses. '
                    . 'Send plain text.',
                true
            );
        }

        $order = SomcpToolGetOrder::locate($arguments);
        if ($order === null) {
            return SomcpTool::text('No order of this shop matches what was given. Nothing was sent.', true);
        }

        // Refused as the core refuses it: the thread is keyed on the customer's
        // email, and without a customer there is nobody to write to.
        $customer = new Customer((int) $order->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            return SomcpTool::text(
                'The customer of this order no longer exists, so no message can be added. Nothing was sent.',
                true
            );
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($order, $customer, $message, $display, $idEmployee): array {
            return self::apply($order, $customer, $message, $display, $idEmployee);
        });
    }

    /**
     * Writes the message in the order's thread, then mails it when it is not private.
     *
     * @param Order $order
     * @param Customer $customer
     * @param string $message
     * @param bool $display
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, Customer $customer, string $message, bool $display, int $idEmployee): array
    {
        $customerMessage = new CustomerMessage();
        $idThread = 0;

        try {
            $idThread = (int) CustomerThread::getIdCustomerThreadByEmailAndIdOrder((string) $customer->email, (int) $order->id);
            if ($idThread <= 0) {
                $idThread = self::openThread($order, $customer);
            }

            $customerMessage->id_customer_thread = $idThread;
            // Carried on the message so the order page shows who wrote it,
            // rather than an anonymous line.
            $customerMessage->id_employee = $idEmployee;
            $customerMessage->message = $message;
            $customerMessage->private = !$display;
            $saved = $idThread > 0 && $customerMessage->add();
        } catch (Throwable $e) {
            $saved = false;
        }

        if (!$saved) {
            SomcpLogger::log(
                'order_message_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'order ' . (int) $order->id,
                '',
                $idEmployee
            );

            return SomcpTool::text('The message could not be saved. Nothing was sent.', true);
        }

        $sent = false;
        if ($display) {
            try {
                $sent = self::mail($order, $customer, $message);
            } catch (Throwable $e) {
                // The message is saved all the same: it shows in the
                // customer's account, only the email is missing.
                $sent = false;
            }
        }
        $failed = $display && !$sent;

        SomcpLogger::log(
            'order_message_added',
            $failed ? SomcpLogger::OUTCOME_FAILURE : SomcpLogger::OUTCOME_SUCCESS,
            'order ' . (int) $order->id . ', message ' . (int) $customerMessage->id
                . ($display ? '' : ', private') . ($failed ? ', email failed' : ''),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'added' => true,
            'order' => [
                'id_order' => (int) $order->id,
                'reference' => (string) $order->reference,
            ],
            'id_customer_thread' => $idThread,
            'id_customer_message' => (int) $customerMessage->id,
            'display_to_customer' => $display,
            'email_sent' => $sent,
            // Reported apart from the message, which is saved, so the
            // assistant does not send it again to get the email out.
            'warning' => $failed
                ? 'The message was saved and shows in the customer\'s account, but the email could not be sent. '
                    . 'Do not repeat this call: the message is already recorded.'
                : null,
        ]);
    }

    /**
     * Opens the customer service thread of the order, as the core does when it has none.
     *
     * @param Order $order
     * @param Customer $customer
     *
     * @return int
     */
    private static function openThread(Order $order, Customer $customer): int
    {
        $thread = new CustomerThread();
        $thread->id_contact = 0;
        $thread->id_customer = (int) $order->id_customer;
        $thread->id_shop = (int) $order->id_shop;
        $thread->id_order = (int) $order->id;
        // The order's language rather than the caller's: the thread is the
        // customer's, and the customer service page answers them in it.
        $thread->id_lang = (int) $order->id_lang;
        $thread->email = (string) $customer->email;
        $thread->status = 'open';
        $thread->token = Tools::passwdGen(12);

        return $thread->add() ? (int) $thread->id : 0;
    }

    /**
     * The order_merchant_comment email, as the core sends it.
     *
     * @param Order $order
     * @param Customer $customer
     * @param string $message
     *
     * @return bool
     */
    private static function mail(Order $order, Customer $customer, string $message): bool
    {
        $idShop = (int) $order->id_shop;

        // The core escapes the text for an HTML email and keeps the line
        // breaks, so the customer reads what was typed rather than markup.
        $body = $message;
        if ((int) Configuration::get('PS_MAIL_TYPE', null, null, $idShop) !== (int) Mail::TYPE_TEXT) {
            $body = Tools::nl2br(Tools::htmlentitiesUTF8($message));
        }

        $language = $order->getAssociatedLanguage();
        $subject = Somcp::legacyContext()->getTranslator()->trans(
            'New message regarding your order',
            [],
            'Emails.Subject',
            (string) $language->locale
        );

        return (bool) Mail::send(
            (int) $language->id,
            'order_merchant_comment',
            $subject,
            [
                '{lastname}' => (string) $customer->lastname,
                '{firstname}' => (string) $customer->firstname,
                '{id_order}' => (int) $order->id,
                '{order_name}' => (string) $order->getUniqReference(),
                '{message}' => $body,
            ],
            (string) $customer->email,
            trim((string) $customer->firstname . ' ' . (string) $customer->lastname),
            null,
            null,
            null,
            null,
            _PS_MAIL_DIR_,
            false,
            $idShop
        );
    }
}
