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
 * somcp_get_order_document - one PDF of an order, as View invoice, View
 * delivery slip and the document lines of the Documents tab of the order page
 * open it: an invoice, a delivery slip or a credit slip.
 *
 * The file never travels in the conversation: the tool hands out a signed
 * link to the module's download page (SomcpDownload), which serves the PDF
 * only to the employee of the token, signed in to the back office, with the
 * view right on the orders screen, for fifteen minutes. The link holds the
 * back office address, as the deposit page's does: the merchant's own browser
 * is the one that opens it.
 *
 * Documents are named by id_order_invoice or id_order_slip, the identifiers
 * somcp_get_order lists them with, and the tool checks they are documents of
 * this order and of this kind before signing anything.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// order lookup and the list of its documents live in this class.
require_once __DIR__ . '/SomcpToolGetOrder.php';

class SomcpToolGetOrderDocument
{
    /** @var string[] The arguments the tool takes, so a misspelt one is refused by name. */
    private const KEYS = ['id_order', 'reference', 'document', 'id_order_invoice', 'id_order_slip'];

    /**
     * @var array<string, array{list:string, identifier:string, label:string}> per kind: where
     *                                                                         somcp_get_order lists it, the
     *                                                                         identifier that names it, and
     *                                                                         how a refusal says it
     */
    private const DOCUMENTS = [
        'invoice' => ['list' => 'invoices', 'identifier' => 'id_order_invoice', 'label' => 'invoice'],
        'delivery_slip' => ['list' => 'delivery_slips', 'identifier' => 'id_order_invoice', 'label' => 'delivery slip'],
        'credit_slip' => ['list' => 'credit_slips', 'identifier' => 'id_order_slip', 'label' => 'credit slip'],
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_order_document',
            Somcp::t('Get a document of an order'),
            'Gives a link to one PDF of an order - invoice, delivery slip or credit slip - as View invoice, View '
                . 'delivery slip and the Documents tab of the order page open it. Give the link to the merchant as '
                . 'it is: it opens in their own signed-in back office only, and expires after 15 minutes (ask '
                . 'again after that). The documents and their identifiers are in documents of somcp_get_order. '
                . 'Not for making an invoice that does not exist: somcp_generate_order_invoice.',
            [
                'type' => 'object',
                'properties' => [
                    'id_order' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the order, from somcp_search_orders or somcp_get_order.',
                    ],
                    'reference' => [
                        'type' => 'string',
                        'description' => 'Reference of the order, used when no identifier is known. A reference '
                            . 'shared by several orders (a split cart) is refused with their identifiers.',
                    ],
                    'document' => [
                        'type' => 'string',
                        'enum' => array_keys(self::DOCUMENTS),
                        'description' => 'Which kind of document.',
                    ],
                    'id_order_invoice' => [
                        'type' => 'integer',
                        'description' => 'Which invoice or delivery slip, from documents.invoices or '
                            . 'documents.delivery_slips of somcp_get_order; may be left out when the order has '
                            . 'only one.',
                    ],
                    'id_order_slip' => [
                        'type' => 'integer',
                        'description' => 'Which credit slip, from documents.credit_slips of somcp_get_order; may be '
                            . 'left out when the order has only one.',
                    ],
                ],
                'required' => ['document'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_READ],
            [self::class, 'handle'],
            // The PDF controller of the core asks for the view right on the
            // orders screen on 8.0 and 9.x alike, and so does the download page.
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
        $idEmployee = (int) ($context['id_employee'] ?? 0);
        $problems = [];

        array_push($problems, ...SomcpTool::unknownFields($arguments, self::KEYS));

        $kind = isset($arguments['document']) && is_string($arguments['document']) ? $arguments['document'] : '';
        if (!isset(self::DOCUMENTS[$kind])) {
            $problems[] = 'document: one of ' . implode(', ', array_keys(self::DOCUMENTS));
            $kind = '';
        } else {
            // Each kind is named by one identifier; the other one would be
            // read as nothing and the wrong document handed over.
            $identifier = self::DOCUMENTS[$kind]['identifier'];
            foreach (['id_order_invoice', 'id_order_slip'] as $key) {
                if ($key !== $identifier && array_key_exists($key, $arguments)) {
                    $problems[] = $key . ': not taken with document ' . $kind . ', which is named by '
                        . $identifier;
                }
            }
        }

        $order = SomcpToolGetOrder::find($arguments);
        if (!$order instanceof Order) {
            $problems[] = $order;
        }

        if ($problems !== [] || !$order instanceof Order || $kind === '') {
            return self::refused($problems);
        }

        $spec = self::DOCUMENTS[$kind];
        $identifier = $spec['identifier'];
        $documents = SomcpToolGetOrder::documents($order, (int) $order->id_lang);
        $candidates = $documents[$spec['list']] ?? [];
        $idOrder = (int) $order->id;

        if ($candidates === []) {
            return self::refused([
                'document: Order ' . $idOrder . ' has no ' . $spec['label'] . '. Its documents: '
                    . self::summary($documents) . '. Read them with somcp_get_order.',
            ]);
        }

        $chosen = null;
        $asked = SomcpTool::id($arguments, $identifier);
        if (array_key_exists($identifier, $arguments)) {
            foreach ($candidates as $candidate) {
                if ($asked > 0 && (int) $candidate[$identifier] === $asked) {
                    $chosen = $candidate;
                }
            }
            if ($chosen === null) {
                return self::refused([
                    $identifier . ': not a ' . $spec['label'] . ' of order ' . $idOrder . '. Its documents: '
                        . self::summary($documents) . '. Read them with somcp_get_order.',
                ]);
            }
        } elseif (count($candidates) > 1) {
            return self::refused([
                $identifier . ': order ' . $idOrder . ' has ' . count($candidates) . ' of them: '
                    . self::summary([$spec['list'] => $candidates]) . '. Give ' . $identifier . ' to choose one.',
            ]);
        } else {
            $chosen = $candidates[0];
        }

        $idDocument = (int) $chosen[$identifier];
        $link = SomcpDownload::link($kind, $idDocument, $idEmployee, (int) $order->id_shop);
        if ($link['url'] === '') {
            // The address is built from the administration folder, which the
            // module only learns by running in the back office once.
            return SomcpTool::text(
                'The shop cannot build the address of its own back office yet. Open the module settings once, '
                    . 'then try again.',
                true
            );
        }

        SomcpLogger::log(
            'order_document_link',
            SomcpLogger::OUTCOME_SUCCESS,
            'order ' . $idOrder . ', ' . $kind . ' ' . $idDocument,
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'order' => [
                'id_order' => $idOrder,
                'reference' => (string) $order->reference,
            ],
            'document' => $kind,
            // The same name and value somcp_get_order gives, so the two chain.
            $identifier => $idDocument,
            'number' => (string) ($chosen['number'] ?? ''),
            'date' => (string) ($chosen['date'] ?? ''),
            'url' => $link['url'],
            'expires_at' => $link['expires_at'],
        ]);
    }

    /**
     * The documents of an order in one line, for a refusal to point at the
     * right one.
     *
     * @param array<string, array<int, array<string, mixed>>> $documents as somcp_get_order lists them
     *
     * @return string
     */
    private static function summary(array $documents): string
    {
        $listed = [];
        foreach (self::DOCUMENTS as $spec) {
            foreach ($documents[$spec['list']] ?? [] as $document) {
                $listed[] = $spec['label'] . ' ' . (string) ($document['number'] ?? '') . ' ('
                    . $spec['identifier'] . ' ' . (int) ($document[$spec['identifier']] ?? 0) . ')';
            }
        }

        return $listed === [] ? 'none' : implode(', ', array_unique($listed));
    }

    /**
     * Every mistake in one answer, each as `<path>: <problem>`.
     *
     * @param string[] $problems
     *
     * @return array<string, mixed>
     */
    private static function refused(array $problems): array
    {
        return SomcpTool::text("Nothing was read. Correct these and call again:\n- " . implode("\n- ", $problems), true);
    }
}
