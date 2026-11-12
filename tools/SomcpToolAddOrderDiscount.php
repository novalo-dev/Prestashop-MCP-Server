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
use PrestaShop\PrestaShop\Adapter\Order\CommandHandler\AddCartRuleToOrderHandler;
use PrestaShop\PrestaShop\Adapter\Order\OrderAmountUpdater;
use PrestaShop\PrestaShop\Adapter\Order\OrderDetailUpdater;
use PrestaShop\PrestaShop\Adapter\Order\Refund\OrderProductRemover;
use PrestaShop\PrestaShop\Core\Domain\CartRule\Exception\InvalidCartRuleDiscountValueException;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\AddCartRuleToOrderCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\OrderDiscountType;

/*
 * somcp_add_order_discount - the Add a discount button of the Products block on
 * the order page.
 *
 * Adds one cart rule to one order - a percent, an amount tax included, or free
 * shipping - and recomputes the order totals, as Add new cart rule does. The
 * work is the core's own AddCartRuleToOrderHandler, built with new and run on
 * the order's context, as the other order write tools build their handlers:
 * the handler alone owns the amount ceiling against the invoices, so the tool
 * does not keep a second copy of that sum. The cheap problems (name, type,
 * value sign, a percent above 100, an invoice that is not the order's) are
 * collected and reported at once before the handler runs; the handler's own
 * refusals are caught and put in the same shape rather than sent raw.
 *
 * The screen writes the discount name in the shop's default language only, so
 * the tool takes one plain text, not an object per ISO code.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolGetOrder.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolIssuePartialRefund.php';

class SomcpToolAddOrderDiscount
{
    /** @var array<int, string> The three discount kinds the order page offers. */
    private const TYPES = ['percent', 'amount_tax_included', 'free_shipping'];

    /** @var array<int, string> The arguments the tool reads; anything else is refused. */
    private const KEYS = ['id_order', 'reference', 'name', 'type', 'value', 'id_order_invoice'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_add_order_discount',
            Somcp::t('Add a discount to an order'),
            'Adds one discount to one order - a percentage, an amount tax included or free shipping - like '
                . 'Add a discount on the order page, and recomputes the order totals. It changes what the '
                . 'customer owes: say the new total before adding it. One discount per call. Not to be retried '
                . 'on a lost answer - two identical discounts are both applied; check discounts with '
                . 'somcp_get_order. Not for a voucher a customer types at checkout (somcp_create_cart_rule) or '
                . 'for removing a discount already on the order.',
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
                    'name' => [
                        'type' => 'string',
                        'description' => 'The discount name the customer reads on the invoice. One plain '
                            . 'text, not an object per language: the screen has one field and the shop '
                            . 'stores it in its default language.',
                    ],
                    'type' => [
                        'type' => 'string',
                        'enum' => self::TYPES,
                        'description' => 'percent, amount_tax_included (the amount includes taxes, as the '
                            . 'screen says) or free_shipping.',
                    ],
                    'value' => [
                        'type' => 'number',
                        'description' => 'The percentage (1 to 100) or the amount tax included. Required for '
                            . 'percent and amount_tax_included, refused for free_shipping.',
                    ],
                    'id_order_invoice' => [
                        'type' => 'integer',
                        'description' => 'Which invoice the discount is for, from '
                            . 'documents.invoices[].id_order_invoice of somcp_get_order. Only when the order '
                            . 'has invoices, and it must be one of this order\'s.',
                    ],
                ],
                'required' => ['name', 'type'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Add a discount sits on the order page, and saving it takes the
            // edit right there.
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

        // The discount name: one plain text in the shop's default language, as
        // the screen stores it. The length limit is read from CartRule's
        // definition so it follows PrestaShop (254 on 8.0.1, the setting on
        // 9.0.0) rather than a hardcoded number.
        $name = null;
        if (!array_key_exists('name', $arguments) || !is_string($arguments['name'])) {
            $problems[] = 'name: give the discount name, one plain text.';
        } else {
            $name = trim($arguments['name']);
            if ($name === '') {
                $problems[] = 'name: the discount name cannot be empty.';
            } elseif (!Validate::isCleanHtml($name)) {
                $problems[] = 'name: the discount name holds a character PrestaShop refuses.';
            } else {
                $max = self::nameMaxLength();
                if (Tools::strlen($name) > $max) {
                    $problems[] = 'name: the discount name holds ' . $max . ' characters at most.';
                }
            }
        }

        $type = $arguments['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            $problems[] = 'type: one of ' . implode(', ', self::TYPES) . '.';
            $type = null;
        }

        // value is required for the two numeric kinds and refused for free
        // shipping. A percent is checked here so a plain mistake is reported
        // with the others; the amount ceiling belongs to the handler.
        $value = null;
        if ($type === 'free_shipping') {
            if (array_key_exists('value', $arguments)) {
                $problems[] = 'value: free_shipping takes no value.';
            }
        } elseif ($type !== null) {
            if (!array_key_exists('value', $arguments)) {
                $problems[] = 'value: required for ' . $type . '.';
            } elseif (!is_int($arguments['value']) && !is_float($arguments['value'])) {
                $problems[] = 'value: a number.';
            } else {
                $value = (float) $arguments['value'];
                if ($value <= 0) {
                    $problems[] = 'value: above 0.';
                } elseif ($type === 'percent' && $value > 100) {
                    $problems[] = 'value: a percentage is 100 at most.';
                }
            }
        }

        // The order itself, found like the other order write tools: a shared
        // reference is refused with the list rather than landing on one of them.
        $order = SomcpToolGetOrder::find($arguments);
        if (!$order instanceof Order) {
            $problems[] = (array_key_exists('reference', $arguments) && (int) ($arguments['id_order'] ?? 0) <= 0
                ? 'reference: '
                : 'id_order: ') . $order;

            return SomcpToolIssuePartialRefund::refused($problems);
        }

        // The invoice, only when the order has one, and only one of this
        // order's. Read now so a wrong invoice is reported with the rest.
        $idOrderInvoice = null;
        if (array_key_exists('id_order_invoice', $arguments)) {
            $idOrderInvoice = SomcpTool::id($arguments, 'id_order_invoice');
            if ($idOrderInvoice <= 0) {
                $problems[] = 'id_order_invoice: an invoice identifier from somcp_get_order.';
            } elseif (!$order->hasInvoice()) {
                $problems[] = 'id_order_invoice: this order has no invoice, so a discount cannot be put on one.';
            } else {
                $invoice = new OrderInvoice($idOrderInvoice);
                if (!Validate::isLoadedObject($invoice) || (int) $invoice->id_order !== (int) $order->id) {
                    $problems[] = 'id_order_invoice: not an invoice of this order (somcp_get_order lists them).';
                }
            }
        }

        if ($problems !== []) {
            return SomcpToolIssuePartialRefund::refused($problems);
        }

        // No problem left means both were read as strings above; the casts
        // only tell the static analysis so, as a docblock cannot sit here.
        $name = (string) $name;
        $type = (string) $type;

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($order, $name, $type, $value, $idOrderInvoice, $idEmployee): array {
            return self::apply($order, $name, $type, $value, $idOrderInvoice, $idEmployee);
        });
    }

    /**
     * Runs the core handler on the order's context and reports what it added.
     *
     * @param Order $order
     * @param string $name
     * @param string $type one of self::TYPES
     * @param float|null $value
     * @param int|null $idOrderInvoice
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Order $order, string $name, string $type, ?float $value, ?int $idOrderInvoice, int $idEmployee): array
    {
        // The highest order_cart_rule before the call, so the one the handler
        // adds is told apart from the discounts already on the order.
        $before = self::highestOrderCartRule($order);

        $command = new AddCartRuleToOrderCommand(
            (int) $order->id,
            $name,
            self::coreType($type),
            $value === null ? null : self::decimalString($value),
            $idOrderInvoice
        );

        $contextManager = new ContextStateManager(new LegacyContext());

        try {
            $handler = new AddCartRuleToOrderHandler(
                new OrderAmountUpdater(
                    new CoreConfiguration(),
                    $contextManager,
                    new OrderDetailUpdater($contextManager, new CoreConfiguration()),
                    new OrderProductRemover()
                ),
                $contextManager
            );
            $handler->handle($command);
        } catch (InvalidCartRuleDiscountValueException $e) {
            return self::valueRefused($order, $type, $e, $idEmployee);
        } catch (Throwable $e) {
            SomcpLogger::log(
                'order_add_discount',
                SomcpLogger::OUTCOME_FAILURE,
                'order ' . (int) $order->id,
                '',
                $idEmployee
            );

            return SomcpToolIssuePartialRefund::refused([
                'id_order: the discount could not be added. Nothing was recorded.',
            ]);
        }

        $order = new Order((int) $order->id);
        $currency = new Currency((int) $order->id_currency);
        $added = self::addedDiscount($order, $before);

        SomcpLogger::log(
            'order_add_discount',
            SomcpLogger::OUTCOME_SUCCESS,
            'order ' . (int) $order->id . ', ' . $type,
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'added' => true,
            'order' => [
                'id_order' => (int) $order->id,
                'reference' => (string) $order->reference,
            ],
            'discount' => $added,
            'discounts_tax_included' => (float) $order->total_discounts_tax_incl,
            'total_tax_excluded' => (float) $order->total_paid_tax_excl,
            'total_tax_included' => (float) $order->total_paid_tax_incl,
            'currency' => (string) $currency->iso_code,
        ]);
    }

    /**
     * The core's amount and percent refusals, put in the tool's shape.
     *
     * @param Order $order
     * @param string $type
     * @param InvalidCartRuleDiscountValueException $e
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function valueRefused(Order $order, string $type, InvalidCartRuleDiscountValueException $e, int $idEmployee): array
    {
        SomcpLogger::log(
            'order_add_discount',
            SomcpLogger::OUTCOME_FAILURE,
            'order ' . (int) $order->id . ', value refused',
            '',
            $idEmployee
        );

        switch ($e->getCode()) {
            case InvalidCartRuleDiscountValueException::INVALID_MIN_PERCENT:
                $problem = 'value: a percentage is above 0.';
                break;
            case InvalidCartRuleDiscountValueException::INVALID_MAX_PERCENT:
                $problem = 'value: a percentage is 100 at most.';
                break;
            case InvalidCartRuleDiscountValueException::INVALID_MIN_AMOUNT:
                $problem = 'value: an amount is above 0.';
                break;
            case InvalidCartRuleDiscountValueException::INVALID_MAX_AMOUNT:
                $currency = new Currency((int) $order->id_currency);
                $problem = 'value: an amount is at most the total tax included of the invoice, or of the order '
                    . 'when none is given (' . Tools::ps_round((float) $order->total_paid_tax_incl, 2) . ' '
                    . (string) $currency->iso_code . ').';
                break;
            default:
                $problem = 'value: refused by PrestaShop.';
        }

        return SomcpToolIssuePartialRefund::refused([$problem]);
    }

    /**
     * The discount the handler added, in the keys of discounts[] of
     * somcp_get_order plus id_cart_rule, so the same discount reads the same in
     * both answers and its id_order_cart_rule is what the delete tool takes.
     *
     * @param Order $order
     * @param int $before highest id_order_cart_rule before the call
     *
     * @return array<string, mixed>
     */
    private static function addedDiscount(Order $order, int $before): array
    {
        $new = null;
        foreach ($order->getCartRules() as $rule) {
            if ((int) $rule['id_order_cart_rule'] > $before
                && ($new === null || (int) $rule['id_order_cart_rule'] > (int) $new['id_order_cart_rule'])) {
                $new = $rule;
            }
        }

        if ($new === null) {
            return [];
        }

        return [
            'id_order_cart_rule' => (int) $new['id_order_cart_rule'],
            'id_cart_rule' => (int) $new['id_cart_rule'],
            'id_order_invoice' => (int) $new['id_order_invoice'],
            'name' => (string) $new['name'],
            'value_tax_excluded' => (float) $new['value_tax_excl'],
            'value_tax_included' => (float) $new['value'],
            'free_shipping' => (bool) $new['free_shipping'],
        ];
    }

    /**
     * The highest id_order_cart_rule on the order, 0 when it has none.
     *
     * @param Order $order
     *
     * @return int
     */
    private static function highestOrderCartRule(Order $order): int
    {
        $highest = 0;
        foreach ($order->getCartRules() as $rule) {
            $highest = max($highest, (int) $rule['id_order_cart_rule']);
        }

        return $highest;
    }

    /**
     * The tool type mapped to the core's OrderDiscountType string.
     *
     * @param string $type
     *
     * @return string
     */
    private static function coreType(string $type): string
    {
        if ($type === 'percent') {
            return OrderDiscountType::DISCOUNT_PERCENT;
        }
        if ($type === 'amount_tax_included') {
            return OrderDiscountType::DISCOUNT_AMOUNT;
        }

        return OrderDiscountType::FREE_SHIPPING;
    }

    /**
     * A number as the DecimalNumber of the command reads it: a dot, no
     * exponent, no trailing noise.
     *
     * @param float $value
     *
     * @return string
     */
    private static function decimalString(float $value): string
    {
        $string = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $string === '' || $string === '-0' ? '0' : $string;
    }

    /**
     * The length CartRule allows for a name, read from its definition so it
     * follows the version rather than a hardcoded number.
     *
     * @return int
     */
    private static function nameMaxLength(): int
    {
        $field = CartRule::$definition['fields']['name'] ?? [];
        $size = is_array($field) && isset($field['size']) ? (int) $field['size'] : 0;

        return $size > 0 ? $size : 254;
    }
}
