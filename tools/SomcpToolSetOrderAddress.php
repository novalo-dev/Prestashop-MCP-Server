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
use PrestaShop\PrestaShop\Adapter\Order\CommandHandler\ChangeOrderDeliveryAddressHandler;
use PrestaShop\PrestaShop\Adapter\Order\CommandHandler\ChangeOrderInvoiceAddressHandler;
use PrestaShop\PrestaShop\Adapter\Order\OrderAmountUpdater;
use PrestaShop\PrestaShop\Adapter\Order\OrderDetailUpdater;
use PrestaShop\PrestaShop\Adapter\Order\OrderProductQuantityUpdater;
use PrestaShop\PrestaShop\Adapter\Order\Refund\OrderProductRemover;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\ChangeOrderDeliveryAddressCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\ChangeOrderInvoiceAddressCommand;

/*
 * somcp_set_order_address - the Select another address window of the Shipping
 * address and Invoice address blocks of the order page.
 *
 * Points the delivery or the invoice address of one order to another address
 * of its customer, and lets the core recompute the taxes and the totals - the
 * country can change the tax. The work is the core's own: for the delivery
 * address, ChangeOrderDeliveryAddressHandler, which moves the cart address,
 * keeps the carrier's delivery option, synchronises the gift lines and updates
 * the detail taxes and the amounts; for the invoice address, the lighter
 * ChangeOrderInvoiceAddressHandler. Both are built with new and run on the
 * order's context, as the other order write tools build their handlers, so the
 * ceiling checks and the tax recomputation stay the core's and are not copied
 * here. The handlers only check that the address loads; the tool checks what
 * the select guarantees on screen - the address belongs to this customer and
 * is not deleted - and refuses with the customer's addresses otherwise, as the
 * select lists them.
 *
 * The addresses it moves between are reported through
 * SomcpToolGetOrder::address(), the same mapping somcp_get_order uses, rather
 * than a second copy.
 *
 * Note on the shared helpers assumed by issue #126: the identifier is read by
 * SomcpTool::id(), like every order tool's; SomcpTool::refused() (#169, #170)
 * is not on develop yet, so the refusal is built here in the order tools'
 * shape until that issue lands.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// order lookup and the address mapping both live in that class.
require_once __DIR__ . '/SomcpToolGetOrder.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';

class SomcpToolSetOrderAddress
{
    /** @var array<int, string> The two blocks the order page offers. */
    private const TYPES = ['delivery', 'invoice'];

    /** @var array<int, string> The arguments the tool reads; anything else is refused. */
    private const KEYS = ['id_order', 'reference', 'address_type', 'id_address'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_set_order_address',
            Somcp::t('Change an address of an order'),
            'Points the delivery or the invoice address of one order to another address of the same customer, '
                . 'like Select another address on the order page, and lets PrestaShop recompute the taxes and '
                . 'the totals - the country can change the tax, so say the new total before the change. It does '
                . 'not edit the content of an address (the address and customer tools do) and does not change the '
                . 'carrier. One order per call. Not to be retried on a lost answer: '
                . 'check addresses.delivery.id_address and addresses.invoice.id_address with somcp_get_order.',
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
                    'address_type' => [
                        'type' => 'string',
                        'enum' => self::TYPES,
                        'description' => 'delivery (the Shipping address block) or invoice (the Invoice '
                            . 'address block), the two keys of addresses in somcp_get_order.',
                    ],
                    'id_address' => [
                        'type' => 'integer',
                        'description' => 'An address of the order\'s customer, from addresses[].id_address of '
                            . 'somcp_get_customer, or from the list a refused call returns. Never guessed.',
                    ],
                ],
                'required' => ['address_type', 'id_address'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Select another address sits on the order page, and saving it
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

        $type = $arguments['address_type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            $problems[] = 'address_type: one of ' . implode(', ', self::TYPES);
            $type = null;
        }

        // The address identifier, read like the other order tools read theirs:
        // a cast would turn an array into 1 and move the order onto an address
        // nobody named.
        $idAddress = 0;
        if (!array_key_exists('id_address', $arguments)) {
            $problems[] = 'id_address: give the address identifier, a whole number';
        } else {
            $idAddress = SomcpTool::id($arguments, 'id_address');
            if ($idAddress <= 0) {
                $problems[] = 'id_address: give the address identifier, a whole number';
            }
        }

        // The order itself, found like the other order write tools: a shared
        // reference is refused with the list rather than landing on one of them.
        $order = SomcpToolGetOrder::find($arguments);
        if (!$order instanceof Order) {
            $problems[] = (array_key_exists('reference', $arguments) && (int) ($arguments['id_order'] ?? 0) <= 0
                ? 'reference: '
                : 'id_order: ') . $order;

            return self::refused($problems);
        }

        // The address must be one of this customer's, not deleted: what the
        // select guarantees on screen but the core handler does not check. On
        // failure the customer's addresses are listed in the same answer, as
        // the select lists them, so the next call has the right identifier.
        if ($idAddress > 0) {
            $address = new Address($idAddress);
            if (!Validate::isLoadedObject($address)
                || (int) $address->deleted === 1
                || (int) $address->id_customer !== (int) $order->id_customer) {
                $problems[] = 'id_address: no address of this customer has the identifier ' . $idAddress
                    . ' - find it with somcp_get_customer';
                $problems[] = 'addresses of this customer: '
                    . json_encode(self::customerAddresses($order), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        if ($problems !== []) {
            return self::refused($problems);
        }

        $type = (string) $type;
        $idLang = (int) $order->id_lang;
        $current = $type === 'delivery' ? (int) $order->id_address_delivery : (int) $order->id_address_invoice;

        // The core writes the same address again all the same; nothing to do,
        // and reporting it as unchanged keeps a retried call from looking like
        // a second move.
        if ($current === $idAddress) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The order already uses this address for the ' . $type . ' address. Nothing was changed.',
                'order' => self::identify($order),
                'address_type' => $type,
                'address' => SomcpToolGetOrder::address($idAddress, $idLang),
            ]);
        }

        $previous = SomcpToolGetOrder::address($current, $idLang);

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($order, $type, $idAddress, $previous, $idEmployee): array {
            return self::apply($order, $type, $idAddress, $previous, $idEmployee);
        });
    }

    /**
     * Runs the core handler on the order's context and reports the move.
     *
     * @param Order $order
     * @param string $type one of self::TYPES
     * @param int $idAddress
     * @param array<string, mixed> $previous the address before the change
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, string $type, int $idAddress, array $previous, int $idEmployee): array
    {
        $config = new CoreConfiguration();
        $contextManager = new ContextStateManager(new LegacyContext());
        $detailUpdater = new OrderDetailUpdater($contextManager, $config);
        $remover = new OrderProductRemover();
        $amountUpdater = new OrderAmountUpdater($config, $contextManager, $detailUpdater, $remover);

        try {
            if ($type === 'delivery') {
                // The delivery handler also keeps the carrier's delivery option
                // and synchronises the gift lines, so it needs the quantity
                // updater the invoice handler does without.
                $quantityUpdater = new OrderProductQuantityUpdater($amountUpdater, $remover, $contextManager);
                $handler = new ChangeOrderDeliveryAddressHandler($amountUpdater, $detailUpdater, $contextManager, $quantityUpdater);
                $handler->handle(new ChangeOrderDeliveryAddressCommand((int) $order->id, $idAddress));
            } else {
                $handler = new ChangeOrderInvoiceAddressHandler($amountUpdater, $detailUpdater);
                $handler->handle(new ChangeOrderInvoiceAddressCommand((int) $order->id, $idAddress));
            }
        } catch (Throwable $e) {
            SomcpLogger::log(
                'order_set_address',
                SomcpLogger::OUTCOME_FAILURE,
                'order ' . (int) $order->id . ', ' . $type,
                '',
                $idEmployee
            );

            return self::refused(['id_address: the address could not be changed. Nothing was changed.']);
        }

        $order = new Order((int) $order->id);
        $idLang = (int) $order->id_lang;
        $currency = new Currency((int) $order->id_currency);

        SomcpLogger::log(
            'order_set_address',
            SomcpLogger::OUTCOME_SUCCESS,
            'order ' . (int) $order->id . ', ' . $type,
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'changed' => true,
            'order' => self::identify($order),
            'address_type' => $type,
            'previous_address' => $previous,
            'address' => SomcpToolGetOrder::address($idAddress, $idLang),
            // The order totals after the move, named as totals of
            // somcp_get_order: the tax can change with the country.
            'total_tax_excluded' => (float) $order->total_paid_tax_excl,
            'total_tax_included' => (float) $order->total_paid_tax_incl,
            'currency' => Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '',
        ]);
    }

    /**
     * The customer's addresses, the way the select lists them for the merchant
     * to pick one.
     *
     * @param Order $order
     *
     * @return array<int, array<string, mixed>>
     */
    private static function customerAddresses(Order $order): array
    {
        return self::addressesOf((int) $order->id_customer, (int) $order->id_lang);
    }

    /**
     * A customer's addresses in the shape customerAddresses() lists them,
     * shared with somcp_create_order so both refusals list the same choices.
     *
     * @param int $idCustomer
     * @param int $idLang
     *
     * @return array<int, array<string, mixed>>
     */
    public static function addressesOf(int $idCustomer, int $idLang): array
    {
        $customer = new Customer($idCustomer);
        if (!Validate::isLoadedObject($customer)) {
            return [];
        }

        $listed = [];
        // deleted addresses are already filtered out by getAddresses().
        foreach ($customer->getAddresses($idLang) as $row) {
            $listed[] = [
                'id_address' => (int) $row['id_address'],
                'alias' => (string) ($row['alias'] ?? ''),
                'address1' => (string) ($row['address1'] ?? ''),
                'postcode' => (string) ($row['postcode'] ?? ''),
                'city' => (string) ($row['city'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
            ];
        }

        return $listed;
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
     * The order tools' refusal: every problem at once, nothing changed.
     *
     * Kept here, in the wording issue #126 asks for, until SomcpTool::refused()
     * of #169 and #170 unifies the order tools on one helper.
     *
     * @param string[] $problems
     *
     * @return array<string, mixed>
     */
    private static function refused(array $problems): array
    {
        return SomcpTool::text("Nothing was changed.\n- " . implode("\n- ", $problems), true);
    }
}
