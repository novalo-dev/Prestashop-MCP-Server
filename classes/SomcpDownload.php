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
 * SomcpDownload - the one way a private file leaves the shop through an
 * assistant, the outgoing counterpart of the deposit page (SomcpUpload).
 *
 * A tool that produces a private file - an invoice, a delivery slip or a
 * credit slip today - never puts the bytes in the conversation: it hands out a
 * signed link to a hidden back office page. The link names the file by its
 * kind and its identifier, never by a path, and carries only kind, id, e
 * (expiry) and s (signature). Who the link was made for and which shop it
 * belongs to are not in the address: they are folded into the signature, so a
 * link recomputed for the wrong employee or the wrong shop simply fails.
 *
 * The page behind also requires the back office session of that same employee,
 * so a leaked link alone is useless, and it checks the view right of the screen
 * the document belongs to. Nothing is stored: the PDF is built on demand from
 * the core PDF class and the shop's templates when the link is opened.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpDownload
{
    /** @var int How long a link stays valid, in seconds: 15 minutes, as a deposit. */
    public const TTL = SomcpUpload::TTL;

    /**
     * @var array<string, array{identifier:string, object:string, template:string}> The kinds a link may serve.
     *
     * Each names the parameter that identifies the document (as somcp_get_order
     * returns it), the core object it is built from, and the PDF template the
     * core renders it with. The controller whose view right is checked is
     * AdminOrders for all three, the same screen the Documents tab lives on.
     */
    public const KINDS = [
        'invoice' => ['identifier' => 'id_order_invoice', 'object' => 'OrderInvoice', 'template' => 'TEMPLATE_INVOICE'],
        'delivery_slip' => ['identifier' => 'id_order_invoice', 'object' => 'OrderInvoice', 'template' => 'TEMPLATE_DELIVERY_SLIP'],
        'credit_slip' => ['identifier' => 'id_order_slip', 'object' => 'OrderSlip', 'template' => 'TEMPLATE_ORDER_SLIP'],
    ];

    /** @var string The controller whose view right opening any of these documents requires. */
    public const SCREEN = 'AdminOrders';

    /**
     * A signed link to one document, for one employee on one shop.
     *
     * @param string $kind one of KINDS
     * @param int $id the document identifier (id_order_invoice or id_order_slip)
     * @param int $idEmployee the account the link is handed to
     * @param int $idShop the shop the document belongs to
     *
     * @return array{url:string, expires_at:string} empty url when the administration folder is unknown
     */
    public static function link(string $kind, int $id, int $idEmployee, int $idShop): array
    {
        $expires = time() + self::TTL;

        $query = [
            'kind' => $kind,
            'id' => (string) $id,
            'e' => (string) $expires,
            's' => self::signature($kind, $id, $idEmployee, $idShop, $expires),
        ];

        return [
            'url' => SomcpBackOffice::downloadUrl($query, $idEmployee),
            'expires_at' => date('Y-m-d H:i:s', $expires),
        ];
    }

    /**
     * The document a link resolves to, once the link itself is sound.
     *
     * The employee and the shop are not read from the address: they are the
     * signed-in employee and the shop in context, folded back into the
     * signature here. A link made for another employee or another shop does
     * not verify, which is exactly the check that keeps a leaked link useless.
     *
     * @param array<string, mixed> $query the kind, id, e and s read from the request
     * @param int $idEmployee the signed-in employee
     * @param int $idShop the shop in context
     *
     * @return array{object:ObjectModel, template:string}|string the object and the PDF
     *                                                            template constant, or a
     *                                                            reason: expired, signature, gone
     */
    public static function check(array $query, int $idEmployee, int $idShop)
    {
        $kind = (string) ($query['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) {
            return 'signature';
        }

        $id = (int) ($query['id'] ?? 0);
        $expires = (int) ($query['e'] ?? 0);
        $signature = (string) ($query['s'] ?? '');

        if ($expires <= time()) {
            return 'expired';
        }

        // Compared in constant time, against a signature recomputed for the
        // employee asking and the shop in context, as controllers/front/file.php
        // does. A hex shape check first keeps a malformed value out of the
        // comparison.
        $expected = self::signature($kind, $id, $idEmployee, $idShop, $expires);
        if (!preg_match('/^[a-f0-9]{64}$/', $signature) || !hash_equals($expected, $signature)) {
            return 'signature';
        }

        return self::resolve($kind, $id, $idShop);
    }

    /**
     * The document itself, when it exists and belongs to the shop.
     *
     * @param string $kind
     * @param int $id
     * @param int $idShop
     *
     * @return array{object:ObjectModel, template:string}|string the object and template, or 'gone'
     */
    private static function resolve(string $kind, int $id, int $idShop)
    {
        // Named rather than instantiated from the kind string so the property
        // reads below (id_order, delivery_number) are on the concrete core
        // class static analysis knows, not on the bare ObjectModel.
        if ($kind === 'credit_slip') {
            $object = new OrderSlip($id);
            if (!Validate::isLoadedObject($object)) {
                return 'gone';
            }
            $idOrder = (int) $object->id_order;
        } else {
            $object = new OrderInvoice($id);
            if (!Validate::isLoadedObject($object)) {
                return 'gone';
            }
            $idOrder = (int) $object->id_order;

            // A delivery slip is the delivery side of an OrderInvoice: without a
            // delivery number there is no slip to render, the same gap the
            // Documents tab shows by listing none.
            if ($kind === 'delivery_slip' && (int) $object->delivery_number <= 0) {
                return 'gone';
            }
        }

        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order) || (int) $order->id_shop !== $idShop) {
            return 'gone';
        }

        return [
            'object' => $object,
            'template' => (string) constant('PDF::' . self::KINDS[$kind]['template']),
        ];
    }

    /**
     * The signature of a link, over its kind, document, employee, shop and expiry.
     *
     * It reuses the one key that already signs the chat file links rather than
     * minting a second: SomcpAssistantChat::signature() keys an HMAC over the
     * string it is given and the expiry, so the whole tuple is covered by
     * passing it as that string.
     *
     * @param string $kind
     * @param int $id
     * @param int $idEmployee
     * @param int $idShop
     * @param int $expires
     *
     * @return string
     */
    private static function signature(string $kind, int $id, int $idEmployee, int $idShop, int $expires): string
    {
        $payload = implode('|', ['download', $kind, (string) $id, (string) $idEmployee, (string) $idShop]);

        return SomcpAssistantChat::signature($payload, $expires);
    }
}
