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
 * somcp_create_cart_rule - one cart rule, with the fields of the Information,
 * Conditions and Actions tabs of Catalog > Discounts > Cart Rules > Add new.
 * Of the restrictions, countries, carriers, groups, the product selection
 * and the compatibility with other rules are written; the shops stay in the
 * back office.
 *
 * Every field takes the name and the shape somcp_get_cart_rule returns, so a
 * rule read there can be given back. The checks are the ones
 * AdminCartRulesController::postProcess() runs before saving, kept in one
 * plan function the update tool extends, so a call is refused here exactly
 * when the back office would refuse the same form.
 *
 * Everything is checked before anything is written, and every problem is
 * reported at once. The rule is written through CartRule::add(), so
 * actionObjectCartRuleAddAfter fires, then the rows the back office adds in
 * afterAdd() on its own: the product selection a "Specific product" discount
 * needs, the shop of a multistore, and the white list of the rules that
 * restrict compatibility. If one of them fails, the rule is deleted again.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends, and it answers
// with the rule as somcp_get_cart_rule returns it.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolGetProduct.php';
require_once __DIR__ . '/SomcpToolListCartRules.php';
require_once __DIR__ . '/SomcpToolGetCartRule.php';

class SomcpToolCreateCartRule extends SomcpToolCreateProduct
{
    /** @var array<int, string> Every argument this tool takes. */
    protected const RULE_ARGUMENTS = [
        'languages', 'name', 'description', 'code', 'highlight', 'partial_use', 'priority', 'active', 'id_customer',
        'date_from', 'date_to', 'minimum_amount', 'quantity', 'quantity_per_user', 'free_shipping', 'reduction',
        'reduction_applies_to', 'reduction_exclude_special', 'gift',
    ];

    /**
     * @var array<int, string> What somcp_get_cart_rule returns and this tool refuses, because the shop works it
     *                         out. Named, so the assistant learns why rather than meeting an "unknown field".
     */
    protected const RULE_COMPUTED = ['id_cart_rule', 'customer', 'summary', 'times_used', 'date_add', 'date_upd'];

    /** @var int The size of the name and code columns (CartRule::$definition). */
    protected const TEXT_MAX = 254;

    /** @var array<string, int> Apply a discount to, as the core stores it in reduction_product (a product id above 0). */
    protected const APPLIES_TO = ['order' => 0, 'specific_product' => 0, 'cheapest_product' => -1, 'selected_products' => -2];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_cart_rule',
            Somcp::t('Create a cart rule'),
            'Creates one cart rule, a voucher with a code or a discount applied on its own, as Catalog > Discounts > '
                . 'Cart Rules > Add new. Fields have the names and shapes of somcp_get_cart_rule. Created off unless '
                . 'active is true: confirm with the merchant first. restrictions: countries, carriers, groups, product '
                . 'selection and other rules it combines with; shops stay in the back office. Not for changing a rule, nor for catalogue '
                . 'price rules. Not to be retried: if the answer is lost, look for the rule with somcp_list_cart_rules '
                . 'first.',
            self::inputSchema(),
            [SomcpScope::SALES_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::ADD, 'AdminCartRules')
        );
    }

    /**
     * @var array<int, string> the restrictions somcp_get_cart_rule reads that only the back office sets
     */
    private const BACK_OFFICE_RESTRICTIONS = ['shop'];

    /**
     * @var array<string, array{0: string, 1: string, 2: string}> the restrictions of the Conditions tab this tool
     *                                                            writes: table, column, and the key of an item as
     *                                                            somcp_get_cart_rule answers it. Fixed here so no
     *                                                            table name is ever built from an argument.
     */
    protected const RESTRICTION_TABLES = [
        'country' => ['cart_rule_country', 'id_country', 'id_country'],
        'carrier' => ['cart_rule_carrier', 'id_carrier', 'id_reference'],
        'group' => ['cart_rule_group', 'id_group', 'id_group'],
    ];

    /**
     * @var array<string, array{0: string, 1: string}> the types of a rule of Product selection, in the order
     *                                                 the refusal names them: what an item is called and the
     *                                                 tool that lists it. The item keys are the ones
     *                                                 somcp_get_cart_rule reads (ITEM_KEYS).
     */
    protected const PRODUCT_RULE_TYPES = [
        'products' => ['product', 'somcp_search_products'],
        'attributes' => ['attribute value', 'somcp_list_attributes'],
        'categories' => ['category', 'somcp_list_categories'],
        'manufacturers' => ['brand', 'somcp_list_manufacturers'],
        'suppliers' => ['supplier', 'somcp_list_suppliers'],
    ];

    /**
     * The arguments, under the names somcp_get_cart_rule returns.
     *
     * @return array<string, mixed>
     */
    protected static function inputSchema(): array
    {
        $flag = static function (string $description): array {
            return ['type' => 'boolean', 'description' => $description];
        };

        return [
            'type' => 'object',
            'properties' => [
                'languages' => [
                    'type' => 'array',
                    'description' => 'ISO codes of the languages to fill, among the active languages of the shop. '
                        . 'All of them by default. The name must then be given in each of them.',
                    'items' => ['type' => 'string'],
                ],
                'name' => [
                    'type' => 'object',
                    'description' => 'Merchant. Required. Name shown to the customer, one per language being filled, '
                        . 'keyed by ISO code: {"fr": "...", "en": "..."}. 254 characters at most.',
                    'additionalProperties' => ['type' => 'string'],
                ],
                'description' => ['type' => 'string', 'description' => 'For the merchant only, never shown to customers.'],
                'code' => [
                    'type' => 'string',
                    'description' => 'Merchant. The code the customer types. Empty: the rule applies on its own to '
                        . 'every cart that meets its conditions. A code another rule has is refused.',
                ],
                'highlight' => $flag('Show the voucher in the cart when it is not added yet. False by default.'),
                'partial_use' => $flag('Keep what is left of an amount for later. True by default.'),
                'priority' => ['type' => 'integer', 'description' => 'Order the rules apply in, 1 first. 1 by default.'],
                'active' => $flag('Status. False by default, so the merchant reads the rule before customers see it.'),
                'id_customer' => [
                    'type' => ['integer', 'null'],
                    'description' => 'Limit to a single customer, from somcp_search_customers. Null: everyone.',
                ],
                'date_from' => ['type' => 'string', 'description' => 'Valid from, ISO 8601, converted to the shop\'s time zone; without an offset, the shop\'s time. Now by default.'],
                'date_to' => ['type' => 'string', 'description' => 'Valid to, same format. One month after now by default.'],
                'minimum_amount' => [
                    'type' => ['object', 'null'],
                    'description' => 'Minimum amount of the cart: {"value": 50, "currency": "EUR", "tax_included": false, '
                        . '"shipping_included": false}; currency defaults to the shop\'s. Null: none.',
                ],
                'quantity' => ['type' => 'integer', 'description' => 'Total available. 1 by default.'],
                'quantity_per_user' => ['type' => 'integer', 'description' => 'Total available for each user. 1 by default.'],
                'free_shipping' => $flag('Free shipping. False by default.'),
                'reduction' => [
                    'type' => ['object', 'null'],
                    'description' => 'Apply a discount: {"type": "percent", "value": 10} or {"type": "amount", "value": 5, '
                        . '"currency": "EUR", "tax_included": true}. Null: none. A rule needs a discount, free shipping or a gift.',
                ],
                'reduction_applies_to' => [
                    'type' => ['string', 'object'],
                    'description' => 'Apply a discount to: order (default), specific_product (with id_product in '
                        . 'reduction, or {"type": "specific_product", "id_product": 12}), and for a percent only '
                        . 'cheapest_product or selected_products.',
                ],
                'reduction_exclude_special' => $flag('Exclude discounted products, for a percent. False by default.'),
                'gift' => [
                    'type' => ['object', 'null'],
                    'description' => 'Send a free gift: {"id_product": 3, "id_product_attribute": 13}, the combination '
                        . 'from somcp_get_product, required when the product has some. Null: none.',
                ],
                'restrictions' => [
                    'type' => 'object',
                    'description' => 'As somcp_get_cart_rule gives it; null: none; cart_rule []: combines with none. product: '
                        . '[{"quantity", "rules": [{"type", "values"}]}], type: products, attributes, categories, '
                        . 'manufacturers, suppliers.',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public static function handle(array $arguments, array $context): array
    {
        $idShop = Somcp::currentShopId();
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = [];
        $plan = self::planRule($arguments, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::refused('created', $errors);
        }

        return self::asEmployee($idEmployee, static function () use ($plan, $idShop, $idEmployee): array {
            return self::addRule($plan, $idShop, $idEmployee);
        });
    }

    /**
     * Turns the arguments into what will be written, or says everything that is wrong with them.
     *
     * Nothing is written here, so a refused call leaves the shop exactly as it
     * was. Protected for the update tool, which plans from the stored rule.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $errors
     * @param int $idSelf the rule being changed, whose own code is not a conflict, 0 for a new one
     *
     * @return array<string, mixed>|null
     */
    protected static function planRule(array $arguments, int $idShop, array &$errors, int $idSelf = 0): ?array
    {
        $restrictions = array_key_exists('restrictions', $arguments)
            ? self::newRestrictions($arguments['restrictions'], $idShop, $errors)
            : [];
        $errors = array_merge($errors, SomcpTool::unknownFields(
            array_diff_key($arguments, ['restrictions' => true]),
            self::RULE_ARGUMENTS,
            self::RULE_COMPUTED
        ));

        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }

        $probe = new CartRule();
        $fields = [];

        $names = self::texts($arguments, 'name', 'name', $languages, $errors, true);
        if ($names !== null) {
            foreach ($names as $idLang => $name) {
                $name = trim($name);
                $names[$idLang] = $name;
                $where = 'name (' . $languages['filled'][$idLang] . ')';
                if (Tools::strlen($name) > self::TEXT_MAX) {
                    $errors[] = $where . ': ' . self::TEXT_MAX . ' characters at most';
                } elseif ($name !== '' && ($check = $probe->validateField('name', $name, $idLang)) !== true) {
                    $errors[] = $where . ': ' . self::reason($check);
                }
            }
        }

        $fields['description'] = '';
        if (isset($arguments['description'])) {
            $description = self::string($arguments['description']);
            if ($description === null) {
                $errors[] = 'description: a plain text';
            } elseif (($check = $probe->validateField('description', trim($description))) !== true) {
                $errors[] = 'description: ' . self::reason($check);
            } else {
                $fields['description'] = trim($description);
            }
        }

        $fields['code'] = '';
        if (isset($arguments['code'])) {
            $code = self::string($arguments['code']);
            $code = $code === null ? null : trim($code);
            if ($code === null) {
                $errors[] = 'code: a text, or empty for a rule that applies on its own';
            } elseif (Tools::strlen($code) > self::TEXT_MAX) {
                $errors[] = 'code: ' . self::TEXT_MAX . ' characters at most';
            } elseif ($code !== '' && ($check = $probe->validateField('code', $code)) !== true) {
                $errors[] = 'code: ' . self::reason($check);
            } elseif ($code !== '' && ($same = (int) CartRule::getIdByCode($code)) > 0 && $same !== $idSelf) {
                // Compared on every shop, as the back office does: a code is
                // what the customer types, and two rules cannot answer to it.
                // The rule being changed keeps its own, as the form compares
                // with its id_cart_rule.
                $errors[] = 'code: This cart rule code is already used (conflict with cart rule ' . $same . ')';
            } else {
                $fields['code'] = $code;
            }
        }

        foreach (['highlight' => false, 'partial_use' => true, 'active' => false, 'free_shipping' => false, 'reduction_exclude_special' => false] as $flag => $default) {
            $fields[$flag] = $default;
            if (array_key_exists($flag, $arguments)) {
                $value = self::bool($arguments[$flag]);
                if ($value === null) {
                    $errors[] = $flag . ': true or false';
                } else {
                    $fields[$flag] = $value;
                }
            }
        }

        foreach (['priority' => 1, 'quantity' => 1, 'quantity_per_user' => 1] as $number => $default) {
            $fields[$number] = $default;
            if (array_key_exists($number, $arguments)) {
                $value = self::integer($arguments[$number]);
                $minimum = $number === 'priority' ? 1 : 0;
                if ($value === null || $value < $minimum) {
                    $errors[] = $number . ': a whole number, ' . $minimum . ' at least';
                } else {
                    $fields[$number] = $value;
                }
            }
        }

        $fields['id_customer'] = 0;
        if (isset($arguments['id_customer'])) {
            $idCustomer = SomcpTool::id($arguments, 'id_customer');
            $customer = $idCustomer > 0 ? new Customer($idCustomer) : null;
            if ($idCustomer <= 0) {
                $errors[] = SomcpTool::notAnIdentifier('id_customer');
            } elseif ($customer === null || !Validate::isLoadedObject($customer) || (int) $customer->id_shop !== $idShop || (int) $customer->deleted === 1) {
                $errors[] = SomcpTool::notFound('id_customer', 'customer', $idCustomer, 'somcp_search_customers');
            } else {
                $fields['id_customer'] = $idCustomer;
            }
        }

        // The form's defaults: valid from now for one month.
        $fields['date_from'] = date('Y-m-d H:i:s');
        $fields['date_to'] = date('Y-m-d H:i:s', (int) strtotime('+1 month'));
        foreach (['date_from', 'date_to'] as $bound) {
            if (isset($arguments[$bound])) {
                $value = self::dateTime($arguments[$bound], $bound, $errors);
                if ($value !== null) {
                    $fields[$bound] = $value;
                }
            }
        }
        if (strtotime($fields['date_from']) > strtotime($fields['date_to'])) {
            $errors[] = 'date_to: The voucher cannot end before it begins.';
        }

        $idDefaultCurrency = (int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop);
        self::minimumAmount($arguments, $idShop, $idDefaultCurrency, $fields, $errors);
        $reductionProduct = self::reduction($arguments, $idShop, $idDefaultCurrency, $fields, $errors);
        self::gift($arguments, $idShop, $fields, $errors);

        if ($fields['reduction_percent'] <= 0 && $fields['reduction_amount'] <= 0 && !$fields['free_shipping'] && $fields['gift_product'] === 0) {
            $errors[] = 'reduction: An action is required for this cart rule. Give a discount, free_shipping or a gift';
        }

        $plan = [
            'languages' => $languages,
            'names' => $names ?? [],
            'fields' => $fields,
            // The rows of the restrictions switched on, by key of RESTRICTION_TABLES.
            'restrictions' => $restrictions,
            // The product the back office adds to Product selection on its
            // own when the discount goes to one product.
            'specific_product' => $reductionProduct,
        ];

        // The same check the core runs before add(), on the whole object, as a
        // last net for a rule of the running version none of the above knew.
        if ($errors === []) {
            $candidate = self::newRule($plan);
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'cart rule: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * Minimum amount, in the shape somcp_get_cart_rule returns.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param int $idDefaultCurrency
     * @param array<string, mixed> $fields
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function minimumAmount(array $arguments, int $idShop, int $idDefaultCurrency, array &$fields, array &$errors): void
    {
        $fields['minimum_amount'] = 0.0;
        $fields['minimum_amount_currency'] = $idDefaultCurrency;
        $fields['minimum_amount_tax'] = false;
        $fields['minimum_amount_shipping'] = false;
        $minimum = $arguments['minimum_amount'] ?? null;
        if ($minimum === null) {
            return;
        }
        if (!is_array($minimum) || array_is_list($minimum)) {
            $errors[] = 'minimum_amount: an object {"value", "currency", "tax_included", "shipping_included"}, or null';

            return;
        }
        foreach (array_keys($minimum) as $key) {
            if (!in_array($key, ['value', 'currency', 'tax_included', 'shipping_included'], true)) {
                $errors[] = 'minimum_amount.' . $key . ': not a field of the minimum amount';
            }
        }
        $value = self::float($minimum['value'] ?? null);
        if ($value === null) {
            $errors[] = 'minimum_amount.value: a number';
        } elseif ($value < 0) {
            $errors[] = 'minimum_amount.value: The minimum amount cannot be lower than zero.';
        } else {
            $fields['minimum_amount'] = round($value, 6);
        }
        if (isset($minimum['currency'])) {
            $fields['minimum_amount_currency'] = self::activeCurrency($minimum['currency'], $idShop, 'minimum_amount.currency', $errors);
        }
        foreach (['tax_included' => 'minimum_amount_tax', 'shipping_included' => 'minimum_amount_shipping'] as $key => $column) {
            if (array_key_exists($key, $minimum)) {
                $flag = self::bool($minimum[$key]);
                if ($flag === null) {
                    $errors[] = 'minimum_amount.' . $key . ': true or false';
                } else {
                    $fields[$column] = $flag;
                }
            }
        }
    }

    /**
     * Apply a discount and Apply a discount to, in the shapes of
     * somcp_get_cart_rule, which puts the product of a specific product
     * discount inside reduction.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param int $idDefaultCurrency
     * @param array<string, mixed> $fields
     * @param array<int, string> $errors
     *
     * @return int the product of a specific product discount, 0 otherwise
     */
    protected static function reduction(array $arguments, int $idShop, int $idDefaultCurrency, array &$fields, array &$errors): int
    {
        $fields['reduction_percent'] = 0.0;
        $fields['reduction_amount'] = 0.0;
        $fields['reduction_currency'] = $idDefaultCurrency;
        $fields['reduction_tax'] = false;
        $fields['reduction_product'] = 0;

        $reduction = $arguments['reduction'] ?? null;
        $type = null;
        $idProduct = 0;
        if ($reduction !== null) {
            if (!is_array($reduction) || array_is_list($reduction)) {
                $errors[] = 'reduction: an object {"type": "percent", "value": 10} or {"type": "amount", "value": 5, "currency": "EUR", "tax_included": true}, or null';
                $reduction = null;
            } else {
                $type = $reduction['type'] ?? null;
                $allowed = $type === 'amount' ? ['type', 'value', 'currency', 'tax_included', 'id_product'] : ['type', 'value', 'id_product'];
                foreach (array_keys($reduction) as $key) {
                    if (!in_array($key, $allowed, true)) {
                        $errors[] = 'reduction.' . $key . ': not a field of a ' . (is_string($type) ? $type : 'discount');
                    }
                }
                $value = self::float($reduction['value'] ?? null);
                if ($type === 'percent') {
                    if ($value === null || $value <= 0 || $value > 100) {
                        $errors[] = 'reduction.value: Reduction percentage must be between 0% and 100% (give null for no discount)';
                    } else {
                        $fields['reduction_percent'] = round($value, 2);
                    }
                } elseif ($type === 'amount') {
                    if ($value === null || $value <= 0) {
                        $errors[] = 'reduction.value: Reduction amount cannot be lower than zero (give null for no discount)';
                    } else {
                        $fields['reduction_amount'] = round($value, 6);
                    }
                    if (isset($reduction['currency'])) {
                        $fields['reduction_currency'] = self::activeCurrency($reduction['currency'], $idShop, 'reduction.currency', $errors);
                    }
                    if (array_key_exists('tax_included', $reduction)) {
                        $flag = self::bool($reduction['tax_included']);
                        if ($flag === null) {
                            $errors[] = 'reduction.tax_included: true or false';
                        } else {
                            $fields['reduction_tax'] = $flag;
                        }
                    }
                } else {
                    $errors[] = 'reduction.type: percent or amount';
                }
                if (isset($reduction['id_product'])) {
                    $idProduct = self::productId($reduction, 'reduction.', $errors);
                }
            }
        }

        $appliesTo = $arguments['reduction_applies_to'] ?? null;
        $target = 'order';
        if ($appliesTo !== null) {
            // The string somcp_get_cart_rule returns, or an object carrying the product.
            if (is_array($appliesTo) && !array_is_list($appliesTo)) {
                if (isset($appliesTo['id_product'])) {
                    $idProduct = self::productId($appliesTo, 'reduction_applies_to.', $errors);
                }
                $appliesTo = $appliesTo['type'] ?? null;
            }
            if (!is_string($appliesTo) || !array_key_exists($appliesTo, self::APPLIES_TO)) {
                $errors[] = 'reduction_applies_to: one of ' . implode(', ', array_keys(self::APPLIES_TO));
            } else {
                $target = $appliesTo;
            }
        }

        if ($fields['reduction_exclude_special'] && $type !== 'percent') {
            $errors[] = 'reduction_exclude_special: only with a percent discount';
        }
        if ($reduction === null) {
            if ($target !== 'order') {
                $errors[] = 'reduction_applies_to: only with a discount in reduction';
            }

            return 0;
        }
        if ($type === 'amount' && in_array($target, ['cheapest_product', 'selected_products'], true)) {
            $errors[] = 'reduction_applies_to: ' . $target . ' only with a percent discount; an amount goes to the order or a specific product';
        }

        if ($target !== 'specific_product') {
            // -1 is a product already refused as not an identifier.
            if ($idProduct > 0) {
                $errors[] = 'reduction.id_product: only with reduction_applies_to specific_product';
            }
            $fields['reduction_product'] = self::APPLIES_TO[$target];

            return 0;
        }

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        if ($idProduct < 0) {
            return 0;
        }
        if ($idProduct === 0) {
            $errors[] = 'reduction.id_product: required for specific_product, from somcp_search_products';

            return 0;
        }
        if (SomcpToolGetProduct::locate($idProduct, $idLang, $idShop) === null) {
            $errors[] = SomcpTool::notFound('reduction.id_product', 'product', $idProduct, 'somcp_search_products');

            return 0;
        }
        $fields['reduction_product'] = $idProduct;
        // Set here so add() writes it with the rule, as the back office ticks
        // Product selection before saving.
        $fields['product_restriction'] = true;

        return $idProduct;
    }

    /**
     * Send a free gift, with the checks of PrestaShop 9 on both versions, so
     * the same call gets the same answer whatever the shop runs.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<string, mixed> $fields
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function gift(array $arguments, int $idShop, array &$fields, array &$errors): void
    {
        $fields['gift_product'] = 0;
        $fields['gift_product_attribute'] = 0;
        $gift = $arguments['gift'] ?? null;
        if ($gift === null) {
            return;
        }
        if (!is_array($gift) || array_is_list($gift)) {
            $errors[] = 'gift: an object {"id_product", "id_product_attribute"}, or null';

            return;
        }
        foreach (array_keys($gift) as $key) {
            // name is what somcp_get_cart_rule adds to read the gift: given back, it is ignored.
            if (!in_array($key, ['id_product', 'id_product_attribute', 'name'], true)) {
                $errors[] = 'gift.' . $key . ': not a field of the gift';
            }
        }

        $idProduct = SomcpTool::id($gift, 'id_product');
        if ($idProduct <= 0) {
            $errors[] = SomcpTool::notAnIdentifier('gift.id_product');

            return;
        }
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $product = SomcpToolGetProduct::locate($idProduct, $idLang, $idShop);
        if ($product === null) {
            $errors[] = SomcpTool::notFound('gift.id_product', 'product', $idProduct, 'somcp_search_products');

            return;
        }
        if (!empty(Product::getRequiredCustomizableFieldsStatic($idProduct))) {
            $errors[] = 'gift.id_product: Product with required customization fields cannot be used as a gift.';
        }

        // 0, or nothing given, is the product without a combination, as the
        // field of the Actions tab leaves it.
        $idCombination = 0;
        if (!in_array($gift['id_product_attribute'] ?? 0, [0, '0', null], true)) {
            $idCombination = SomcpTool::id($gift, 'id_product_attribute');
            if ($idCombination <= 0) {
                $errors[] = SomcpTool::notAnIdentifier('gift.id_product_attribute');

                return;
            }
        }
        if ($product->hasAttributes()) {
            if ($idCombination <= 0) {
                $errors[] = 'gift.id_product_attribute: required, this product has combinations - find them with somcp_get_product';
            } elseif (!SomcpToolGetProduct::combinationBelongs($product, $idCombination, $idShop)) {
                $errors[] = 'gift.id_product_attribute: no combination of product ' . $idProduct . ' has the identifier ' . $idCombination
                    . ' - find it with somcp_get_product';
            }
        } elseif ($idCombination !== 0) {
            $errors[] = 'gift.id_product_attribute: this product has no combinations, give 0 or leave it out';
        }

        $fields['gift_product'] = $idProduct;
        $fields['gift_product_attribute'] = max(0, $idCombination);
    }

    /**
     * The product named inside reduction or reduction_applies_to, or -1 once
     * refused, so the checks that follow do not refuse it a second time.
     *
     * @param array<string, mixed> $object
     * @param string $path
     * @param array<int, string> $errors
     *
     * @return int
     */
    private static function productId(array $object, string $path, array &$errors): int
    {
        $idProduct = SomcpTool::id($object, 'id_product');
        if ($idProduct <= 0) {
            $errors[] = SomcpTool::notAnIdentifier($path . 'id_product');

            return -1;
        }

        return $idProduct;
    }

    /**
     * An active currency of the shop by ISO code, or 0 with the problem said.
     *
     * @param mixed $value
     * @param int $idShop
     * @param string $path
     * @param array<int, string> $errors
     *
     * @return int
     */
    public static function activeCurrency($value, int $idShop, string $path, array &$errors): int
    {
        $idCurrency = self::currency($value, $idShop, $path, $errors);
        if ($idCurrency > 0 && !(bool) (new Currency($idCurrency))->active) {
            $errors[] = $path . ': this currency is not active in the shop';

            return 0;
        }

        return $idCurrency;
    }

    /**
     * The rule as it will be added, every language filled.
     *
     * @param array<string, mixed> $plan
     *
     * @return CartRule
     */
    protected static function newRule(array $plan): CartRule
    {
        $cartRule = new CartRule();
        self::fill($cartRule, $plan['fields']);
        self::fill($cartRule, self::completeTexts(['name' => $plan['names']], $plan['languages']));
        // Switched on before add(), so the rule is saved once with the same
        // switches the rows written right after it need.
        foreach (array_keys($plan['restrictions'] ?? []) as $key) {
            $cartRule->{$key . '_restriction'} = true;
        }

        return $cartRule;
    }

    /**
     * The restrictions a new rule starts with, as rows to write after it.
     *
     * Country, carrier and group are checked by planRestrictions(), the
     * compatibility with other rules by planCompatibility(), the product
     * selection by planProductSelection(), as the update checks them. The
     * shops are only accepted off, so a rule read with somcp_get_cart_rule can
     * be given back: they stay on the Conditions tab.
     *
     * @param mixed $restrictions as given
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed> the keys switched on only: identifiers, the groups of
     *                              planProductSelection() for product
     */
    private static function newRestrictions($restrictions, int $idShop, array &$errors): array
    {
        if (!is_array($restrictions) || ($restrictions !== [] && array_is_list($restrictions))) {
            $errors[] = 'restrictions: not set by this tool - the merchant sets them on the Conditions tab of the rule in the back office';

            return [];
        }
        foreach ($restrictions as $key => $value) {
            if (isset(self::RESTRICTION_TABLES[$key]) || $key === 'cart_rule' || $key === 'product') {
                continue;
            }
            if (!in_array($key, self::BACK_OFFICE_RESTRICTIONS, true)) {
                $errors[] = 'restrictions.' . $key . ': not a field of this tool';
            } elseif ($value !== null) {
                $errors[] = 'restrictions.' . $key . ': not set by this tool - the merchant sets it on the Conditions tab '
                    . 'of the rule in the back office';
            }
        }

        $rows = self::planRestrictions($restrictions, $errors);
        if (array_key_exists('cart_rule', $restrictions)) {
            $ids = self::planCompatibility($restrictions['cart_rule'], $idShop, 0, $errors);
            if ($ids !== false) {
                $rows['cart_rule'] = $ids;
            }
        }
        if (array_key_exists('product', $restrictions)) {
            // [] is refused there: a new rule has no read to give back.
            $groups = self::planProductSelection($restrictions['product'], $idShop, $errors);
            if ($groups !== false) {
                $rows['product'] = $groups;
            }
        }

        // A key given null asks for no restriction, which a new rule already
        // has; an empty cart_rule is kept, the box checked with no rule.
        return array_filter($rows, static function ($ids): bool {
            return $ids !== null;
        });
    }

    /**
     * Writes the rule and the rows the back office adds after it, then answers with what was stored.
     *
     * @param array<string, mixed> $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function addRule(array $plan, int $idShop, int $idEmployee): array
    {
        // A multistore rule created from one shop is kept to that shop, as the
        // form saves it: without the row, every shop would offer it.
        $multistore = Shop::isFeatureActive() && count(Shop::getCompleteListOfShopsID()) > 1;
        if ($multistore) {
            $plan['fields']['shop_restriction'] = true;
        }
        // The group of a specific product, added beside the groups given when
        // no products rule holds it, as postProcess() adds it before saving.
        $idSpecific = (int) $plan['specific_product'];
        $groups = $plan['restrictions']['product'] ?? null;
        $added = 0;
        if ($groups !== null && $idSpecific > 0 && !self::holdsProduct($groups, $idSpecific)) {
            $groups[] = self::productGroup($idSpecific);
            $added = $idSpecific;
        }
        $cartRule = self::newRule($plan);

        $failure = '';
        $restricted = [];
        try {
            $done = (bool) $cartRule->add();
            if (!$done) {
                $failure = 'PrestaShop refused to save it';
            } elseif (!self::writeRestrictions($cartRule, $plan['restrictions'])) {
                // No transaction: the rule is removed below, and
                // CartRule::delete() takes its restriction rows with it.
                $done = false;
                $failure = 'the restrictions could not be saved';
            } else {
                $failure = self::afterAdd($cartRule, $idShop, $idSpecific, $groups, $multistore);
                // Always, and last: with the box unchecked the new rule joins
                // the lists of the rules that keep one; with it checked, the
                // boxes it checks on other rules are not undone by delete(),
                // so nothing may fail after it.
                if ($failure === ''
                    && ($restricted = self::writeCompatibility($cartRule, $plan['restrictions']['cart_rule'] ?? null)) === null) {
                    $failure = 'the compatibility with the other cart rules could not be saved';
                }
                $done = $failure === '';
            }
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        if (!$done) {
            $kept = false;
            if ((int) $cartRule->id > 0) {
                try {
                    // CartRule::delete() also removes the rows written after add().
                    $kept = !$cartRule->delete();
                } catch (Throwable $e) {
                    $kept = true;
                }
            }
            SomcpLogger::log(
                'cart_rule_create_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr($failure, 0, 500) . ($kept ? ', cart rule ' . (int) $cartRule->id . ' could not be removed' : ''),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The cart rule could not be created: ' . $failure . '. '
                    . ($kept
                        ? 'It could not be removed either: cart rule ' . (int) $cartRule->id . ' exists in an incomplete '
                            . 'state. Tell the merchant to check it in the back office; do not call again.'
                        : 'Nothing was kept.'),
                true
            );
        }

        $idCartRule = (int) $cartRule->id;
        SomcpLogger::log('cart_rule_created', SomcpLogger::OUTCOME_SUCCESS, 'cart rule ' . $idCartRule, '', $idEmployee);

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $result = [
            'created' => true,
            'id_cart_rule' => $idCartRule,
        ];
        $warnings = [];
        $warning = self::compatibilityWarning($restricted ?? []);
        if ($warning !== '') {
            $warnings[] = $warning;
        }
        if ($added > 0) {
            $warnings[] = self::productAddedWarning($added);
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        $result['cart_rule'] = SomcpToolGetCartRule::byId($idCartRule, (int) $plan['languages']['fallback'], $idShop);

        return SomcpTool::json($result);
    }

    /**
     * The rows AdminCartRulesController::afterAdd() writes for a new rule that
     * CartRule::add() does not. These tables have no ObjectModel, so they are
     * written as the back office writes them. The compatibility with other
     * rules comes after, from writeCompatibility().
     *
     * @param CartRule $cartRule just added
     * @param int $idShop
     * @param int $idProduct the product of a specific product discount, 0 for none
     * @param array<int, array{quantity: int, rules: array<int, array{type: string, ids: array<int, int>}>}>|null $groups
     *                                                                                                                    Product selection given, with the group of the specific product when it was added; null for none
     * @param bool $multistore
     *
     * @return string empty when every row went in, else what failed
     */
    private static function afterAdd(CartRule $cartRule, int $idShop, int $idProduct, ?array $groups, bool $multistore): string
    {
        $idCartRule = (int) $cartRule->id;
        $db = Db::getInstance();

        if ($multistore && !$db->insert('cart_rule_shop', ['id_cart_rule' => $idCartRule, 'id_shop' => $idShop])) {
            return 'the shop of the rule could not be saved';
        }

        if ($groups !== null) {
            if (!self::writeProductSelection($cartRule, $groups)) {
                return 'the product selection could not be saved';
            }
        } elseif ($idProduct > 0 && ($failure = self::productSelection($idCartRule, $idProduct)) !== '') {
            return $failure;
        }

        return '';
    }

    /**
     * Product selection for a discount on one product: one group, quantity 1,
     * holding that product, so the rule only applies to carts that contain it.
     * Written the way the back office writes it, these tables having no
     * ObjectModel. Protected for the update tool, which writes the same rows
     * when a discount moves to a product.
     *
     * @param int $idCartRule
     * @param int $idProduct
     *
     * @return string empty when every row went in, else what failed
     */
    protected static function productSelection(int $idCartRule, int $idProduct): string
    {
        return self::insertProductGroups($idCartRule, [self::productGroup($idProduct)])
            ? '' : 'the product selection could not be saved';
    }

    /**
     * What the merchant is told when the group of a specific product was
     * added beside the Product selection given, by the creation or the update.
     *
     * @param int $idProduct
     *
     * @return string
     */
    protected static function productAddedWarning(int $idProduct): string
    {
        return 'Product ' . $idProduct . ' was added to Product selection, as the back office adds it: the discount '
            . 'applies to that product.';
    }

    /**
     * The group the back office adds for a discount on one product.
     *
     * @param int $idProduct
     *
     * @return array{quantity: int, rules: array<int, array{type: string, ids: array<int, int>}>}
     */
    protected static function productGroup(int $idProduct): array
    {
        return ['quantity' => 1, 'rules' => [['type' => 'products', 'ids' => [$idProduct]]]];
    }

    /**
     * Whether a products rule of the groups holds the product, as
     * AdminCartRulesController::postProcess() looks before it adds the group
     * of a specific product.
     *
     * @param array<int, array{quantity: int, rules: array<int, array{type: string, ids: array<int, int>}>}> $groups
     * @param int $idProduct
     *
     * @return bool
     */
    protected static function holdsProduct(array $groups, int $idProduct): bool
    {
        foreach ($groups as $group) {
            foreach ($group['rules'] as $rule) {
                if ($rule['type'] === 'products' && in_array($idProduct, $rule['ids'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Product selection as given, checked as the Conditions tab offers it.
     *
     * The shape is read by productSelectionShape(), then each group needs 1
     * at least and each item must be one the form offers for the token's shop.
     *
     * @param mixed $value restrictions.product as given
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<int, array{quantity: int, rules: array<int, array{type: string, ids: array<int, int>}>}>|false|null
     *                                                                                                                   the groups, null for no restriction, false when refused
     */
    protected static function planProductSelection($value, int $idShop, array &$errors)
    {
        $groups = self::productSelectionShape($value, $errors);
        if (!is_array($groups)) {
            return $groups;
        }

        $refused = false;
        foreach ($groups as $g => $group) {
            $groupPath = 'restrictions.product[' . $g . ']';
            if ($group['quantity'] < 1) {
                // A group of 0 would let every cart through, as no group does.
                $errors[] = $groupPath . '.quantity: a whole number, 1 at least';
                $refused = true;
            }
            foreach ($group['rules'] as $r => $rule) {
                [$thing, $tool] = self::PRODUCT_RULE_TYPES[$rule['type']];
                foreach (array_diff($rule['ids'], self::offeredItems($rule['type'], $rule['ids'], $idShop)) as $missing) {
                    $errors[] = SomcpTool::notFound($groupPath . '.rules[' . $r . '].values', $thing, (int) $missing, $tool);
                    $refused = true;
                }
            }
        }

        return $refused ? false : $groups;
    }

    /**
     * The shape of a Product selection as given, before any check of the shop.
     *
     * The shape somcp_get_cart_rule reads: groups of a quantity and rules,
     * each rule a type and the items of that type. Each item once, in the
     * order of the identifiers; a name given back is ignored. The update
     * compares this with readProductGroups() before checking anything, so a
     * selection given back as read is never refused for what the form let
     * the merchant save (a group of 0, an item since removed from the shop);
     * planProductSelection() reads through it too, so the two never read a
     * list differently.
     *
     * @param mixed $value restrictions.product as given
     * @param array<int, string> $errors
     *
     * @return array<int, array{quantity: int, rules: array<int, array{type: string, ids: array<int, int>}>}>|false|null
     *                                                                                                                   the groups, null for no restriction, false when malformed
     */
    public static function productSelectionShape($value, array &$errors)
    {
        $path = 'restrictions.product';
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value)) {
            $errors[] = $path . ': a list of {"quantity": ..., "rules": [...]}, or null for no restriction';

            return false;
        }
        if ($value === []) {
            // The page would show the box unchecked and no group.
            $errors[] = $path . ': at least one group, or null for no restriction';

            return false;
        }

        $refused = false;
        $groups = [];
        foreach ($value as $g => $group) {
            $groupPath = $path . '[' . $g . ']';
            if (!is_array($group) || ($group !== [] && array_is_list($group))
                || array_diff(array_keys($group), ['quantity', 'rules']) !== []) {
                $errors[] = $groupPath . ': an object of quantity and rules';
                $refused = true;
                continue;
            }
            // 1 when left out, as a new group of the form.
            $quantity = $group['quantity'] ?? 1;
            if (is_string($quantity) && ctype_digit($quantity)) {
                $quantity = (int) $quantity;
            }
            if (!is_int($quantity) || $quantity < 0) {
                $errors[] = $groupPath . '.quantity: a whole number, 1 at least';
                $refused = true;
                $quantity = 1;
            }
            $rules = $group['rules'] ?? null;
            if (!is_array($rules) || !array_is_list($rules) || $rules === []) {
                // The core deletes a group left without rules.
                $errors[] = $groupPath . '.rules: at least one rule';
                $refused = true;
                continue;
            }
            $planned = [];
            foreach ($rules as $r => $rule) {
                $rulePath = $groupPath . '.rules[' . $r . ']';
                $type = is_array($rule) ? ($rule['type'] ?? null) : null;
                if (!is_string($type) || !isset(self::PRODUCT_RULE_TYPES[$type])) {
                    $errors[] = $rulePath . '.type: one of ' . implode(', ', array_keys(self::PRODUCT_RULE_TYPES));
                    $refused = true;
                    continue;
                }
                $itemKey = SomcpToolGetCartRule::ITEM_KEYS[$type];
                $values = $rule['values'] ?? null;
                if (!is_array($values) || !array_is_list($values) || $values === []) {
                    // The core deletes a rule left without values.
                    $errors[] = $rulePath . '.values: at least one, a list of {"' . $itemKey . '": ...}';
                    $refused = true;
                    continue;
                }
                $ids = [];
                foreach ($values as $item) {
                    $id = is_array($item) ? ($item[$itemKey] ?? null) : $item;
                    if (is_string($id) && ctype_digit($id)) {
                        $id = (int) $id;
                    }
                    if (!is_int($id) || $id <= 0) {
                        $errors[] = SomcpTool::notAnIdentifier($rulePath . '.values');
                        $refused = true;
                        continue 2;
                    }
                    $ids[$id] = $id;
                }
                ksort($ids);
                $planned[] = ['type' => $type, 'ids' => array_values($ids)];
            }
            $groups[] = ['quantity' => $quantity, 'rules' => $planned];
        }

        return $refused ? false : $groups;
    }

    /**
     * The identifiers of a Product selection rule that the form would offer.
     *
     * The products and the categories of the token's shop, the root category
     * left out as the tree of the form starts under it; every attribute
     * value, brand and supplier, as the form lists them all.
     *
     * @param string $type a key of PRODUCT_RULE_TYPES
     * @param array<int, int> $ids
     * @param int $idShop
     *
     * @return array<int, int>
     */
    private static function offeredItems(string $type, array $ids, int $idShop): array
    {
        $in = implode(',', array_map('intval', $ids));
        $p = _DB_PREFIX_;
        switch ($type) {
            case 'products':
                $sql = 'SELECT `id_product` AS `id` FROM `' . $p . 'product_shop`
                        WHERE `id_shop` = ' . (int) $idShop . ' AND `id_product` IN (' . $in . ')';
                break;
            case 'categories':
                $sql = 'SELECT `id_category` AS `id` FROM `' . $p . 'category_shop`
                        WHERE `id_shop` = ' . (int) $idShop . ' AND `id_category` IN (' . $in . ')
                        AND `id_category` <> ' . (int) Configuration::get('PS_ROOT_CATEGORY');
                break;
            case 'attributes':
                $sql = 'SELECT `id_attribute` AS `id` FROM `' . $p . 'attribute` WHERE `id_attribute` IN (' . $in . ')';
                break;
            case 'manufacturers':
                $sql = 'SELECT `id_manufacturer` AS `id` FROM `' . $p . 'manufacturer` WHERE `id_manufacturer` IN (' . $in . ')';
                break;
            default:
                $sql = 'SELECT `id_supplier` AS `id` FROM `' . $p . 'supplier` WHERE `id_supplier` IN (' . $in . ')';
        }

        return array_map('intval', array_column(Db::getInstance()->executeS($sql) ?: [], 'id'));
    }

    /**
     * Product selection read by somcp_get_cart_rule, in the shape planProductSelection() answers.
     *
     * @param mixed $read restrictions.product as somcp_get_cart_rule returns it
     *
     * @return array<int, array{quantity: int, rules: array<int, array{type: string, ids: array<int, int>}>}>|null
     */
    protected static function readProductGroups($read): ?array
    {
        if (!is_array($read)) {
            return null;
        }
        $groups = [];
        foreach ($read as $group) {
            $rules = [];
            foreach ((array) ($group['rules'] ?? []) as $rule) {
                $type = (string) ($rule['type'] ?? '');
                $itemKey = SomcpToolGetCartRule::ITEM_KEYS[$type] ?? '';
                $ids = array_map('intval', array_column((array) ($rule['values'] ?? []), $itemKey));
                sort($ids);
                $rules[] = ['type' => $type, 'ids' => array_values(array_unique($ids))];
            }
            $groups[] = ['quantity' => (int) ($group['quantity'] ?? 1), 'rules' => $rules];
        }

        return $groups;
    }

    /**
     * Replaces Product selection, as AdminCartRulesController::afterUpdate()
     * clears it and afterAdd() writes it back. These tables have no
     * ObjectModel, so they are written as the controller writes them, with
     * table names fixed here.
     *
     * somcp_update_cart_rule calls it before update(), inside its
     * transaction, so the switch set here and the rows go in together;
     * somcp_create_cart_rule right after add(), the switch set before it, and
     * a failure deletes the rule with its rows.
     *
     * @param CartRule $rule saved already, so it has its identifier
     * @param array<int, array{quantity: int, rules: array<int, array{type: string, ids: array<int, int>}>}>|null $groups
     *                                                                                                                    null for no restriction
     *
     * @return bool
     */
    protected static function writeProductSelection(CartRule $rule, ?array $groups): bool
    {
        $idCartRule = (int) $rule->id;
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        if (!$db->delete('cart_rule_product_rule_group', '`id_cart_rule` = ' . $idCartRule)
            || !$db->delete('cart_rule_product_rule', '`id_product_rule_group` NOT IN '
                . '(SELECT `id_product_rule_group` FROM `' . $p . 'cart_rule_product_rule_group`)')
            || !$db->delete('cart_rule_product_rule_value', '`id_product_rule` NOT IN '
                . '(SELECT `id_product_rule` FROM `' . $p . 'cart_rule_product_rule`)')) {
            return false;
        }
        if ($groups !== null && !self::insertProductGroups($idCartRule, $groups)) {
            return false;
        }
        $rule->product_restriction = $groups !== null;

        return true;
    }

    /**
     * The rows of groups of Product selection: one per group, one per rule,
     * one per item, as afterAdd() inserts them.
     *
     * @param int $idCartRule
     * @param array<int, array{quantity: int, rules: array<int, array{type: string, ids: array<int, int>}>}> $groups
     *
     * @return bool
     */
    private static function insertProductGroups(int $idCartRule, array $groups): bool
    {
        $db = Db::getInstance();
        foreach ($groups as $group) {
            if (!$db->insert('cart_rule_product_rule_group', ['id_cart_rule' => $idCartRule, 'quantity' => (int) $group['quantity']])) {
                return false;
            }
            $idGroup = (int) $db->Insert_ID();
            foreach ($group['rules'] as $rule) {
                // The type comes from PRODUCT_RULE_TYPES, never as given.
                $type = isset(self::PRODUCT_RULE_TYPES[$rule['type']]) ? $rule['type'] : 'products';
                if (!$db->insert('cart_rule_product_rule', ['id_product_rule_group' => $idGroup, 'type' => $type])) {
                    return false;
                }
                $idRule = (int) $db->Insert_ID();
                $values = [];
                foreach ($rule['ids'] as $id) {
                    $values[] = ['id_product_rule' => $idRule, 'id_item' => (int) $id];
                }
                if ($values !== [] && !$db->insert('cart_rule_product_rule_value', $values)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The rows each restriction given will hold, checked as the form offers them.
     *
     * Only the keys of RESTRICTION_TABLES are read; the caller decides what the
     * others mean. A list becomes the identifiers to write, each once and in
     * order, null switches the restriction off. An empty list is refused: with
     * the box checked and nothing selected, the rule would match no cart.
     *
     * @param array<string, mixed> $restrictions as somcp_get_cart_rule answers them
     * @param array<int, string> $errors
     *
     * @return array<string, array<int, int>|null>
     */
    protected static function planRestrictions(array $restrictions, array &$errors): array
    {
        $rows = [];
        foreach (self::RESTRICTION_TABLES as $key => [, , $itemKey]) {
            if (!array_key_exists($key, $restrictions)) {
                continue;
            }
            $path = 'restrictions.' . $key;
            $value = $restrictions[$key];
            if ($value === null) {
                $rows[$key] = null;
                continue;
            }
            if (!is_array($value) || !array_is_list($value)) {
                $errors[] = $path . ': a list of {"' . $itemKey . '": ...}, or null for no restriction';
                continue;
            }
            if ($value === []) {
                $errors[] = $path . ': at least one, or null for no restriction';
                continue;
            }
            $ids = [];
            foreach ($value as $item) {
                $id = is_array($item) ? ($item[$itemKey] ?? null) : $item;
                if (is_string($id) && ctype_digit($id)) {
                    $id = (int) $id;
                }
                if (!is_int($id) || $id <= 0) {
                    $errors[] = SomcpTool::notAnIdentifier($path);
                    continue 2;
                }
                $ids[$id] = $id;
            }
            ksort($ids);
            $ids = array_values($ids);
            foreach (array_diff($ids, self::existing($key, $ids)) as $missing) {
                $errors[] = $key === 'country'
                    ? SomcpTool::notFound($path, 'country', $missing, 'somcp_list_countries')
                    : ($key === 'group'
                        ? SomcpTool::notFound($path, 'group', $missing, 'somcp_list_customer_groups')
                        : SomcpTool::notFound($path, 'carrier', $missing, 'somcp_list_carriers', 'reference'));
            }
            $rows[$key] = $ids;
        }

        return $rows;
    }

    /**
     * The identifiers of a list that name something the form would offer.
     *
     * A carrier is kept by its reference, the column the core stores, and only
     * when a version of it is not deleted: somcp_get_cart_rule leaves the
     * others out, so a row written for one would never be read back.
     *
     * @param string $key a key of RESTRICTION_TABLES
     * @param array<int, int> $ids
     *
     * @return array<int, int>
     */
    private static function existing(string $key, array $ids): array
    {
        $in = implode(',', array_map('intval', $ids));
        if ($key === 'country') {
            $sql = 'SELECT `id_country` AS `id` FROM `' . _DB_PREFIX_ . 'country` WHERE `id_country` IN (' . $in . ')';
        } elseif ($key === 'group') {
            $sql = 'SELECT `id_group` AS `id` FROM `' . _DB_PREFIX_ . 'group` WHERE `id_group` IN (' . $in . ')';
        } else {
            $sql = 'SELECT DISTINCT `id_reference` AS `id` FROM `' . _DB_PREFIX_ . 'carrier`
                    WHERE `deleted` = 0 AND `id_reference` IN (' . $in . ')';
        }

        return array_map('intval', array_column(Db::getInstance()->executeS($sql) ?: [], 'id'));
    }

    /**
     * The rules a rule combines with, checked as the Combinable cart rules list offers them.
     *
     * Unlike the other restrictions, an empty list is a choice of its own:
     * the box checked with nothing selected, which the form saves without an
     * error, is a rule that combines with no other.
     *
     * @param mixed $value restrictions.cart_rule as given
     * @param int $idShop
     * @param int $idSelf the rule being changed, 0 for a new one
     * @param array<int, string> $errors
     *
     * @return array<int, int>|false|null the identifiers, null for no restriction, false when refused
     */
    protected static function planCompatibility($value, int $idShop, int $idSelf, array &$errors)
    {
        $path = 'restrictions.cart_rule';
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value)) {
            $errors[] = $path . ': a list of {"id_cart_rule": ...}, or null for no restriction';

            return false;
        }
        $ids = [];
        foreach ($value as $item) {
            $id = is_array($item) ? ($item['id_cart_rule'] ?? null) : $item;
            if (is_string($id) && ctype_digit($id)) {
                $id = (int) $id;
            }
            if (!is_int($id) || $id <= 0) {
                $errors[] = SomcpTool::notAnIdentifier($path);

                return false;
            }
            $ids[$id] = $id;
        }
        ksort($ids);
        $ids = array_values($ids);
        if ($idSelf > 0 && in_array($idSelf, $ids, true)) {
            $errors[] = $path . ': a cart rule cannot list itself';

            return false;
        }
        if ($ids === []) {
            return [];
        }
        // The rules this shop can use, as the list and the get read them, so
        // an identifier refused here is never one those tools give.
        $found = array_map('intval', array_column(Db::getInstance()->executeS(
            'SELECT cr.`id_cart_rule` FROM `' . _DB_PREFIX_ . 'cart_rule` cr
             WHERE cr.`id_cart_rule` IN (' . implode(',', $ids) . ') AND ' . SomcpToolListCartRules::inShop($idShop)
        ) ?: [], 'id_cart_rule'));
        $missing = array_diff($ids, $found);
        foreach ($missing as $id) {
            $errors[] = SomcpTool::notFound($path, 'cart rule', $id, 'somcp_list_cart_rules');
        }

        return $missing === [] ? $ids : false;
    }

    /**
     * Writes Compatibility with other cart rules as AdminCartRulesController saves it.
     *
     * The same four steps as the back office's afterUpdate() and afterAdd(),
     * in their order, through Db since cart_rule_combination has no object:
     * every pair of the rule removed; with the box checked, one pair per
     * combinable rule; with it unchecked, the rule added to the list of every
     * rule whose own box is checked; and with it checked, every rule left
     * unrestricted that does not list this one gets its box checked and keeps
     * every other active rule, so it stops combining with this one only.
     * The switch of the rule is set on the object, saved by the caller.
     *
     * @param CartRule $rule saved already, so it has its identifier
     * @param array<int, int>|null $ids from planCompatibility(), null for the box unchecked
     *
     * @return array<int, int>|null the other rules whose box was checked, null when a write failed
     */
    protected static function writeCompatibility(CartRule $rule, ?array $ids): ?array
    {
        $idCartRule = (int) $rule->id;
        $table = _DB_PREFIX_ . 'cart_rule';
        $db = Db::getInstance();
        if (!$db->delete('cart_rule_combination', '`id_cart_rule_1` = ' . $idCartRule . ' OR `id_cart_rule_2` = ' . $idCartRule)) {
            return null;
        }
        $rule->cart_rule_restriction = $ids !== null;
        if ($ids === null) {
            // The rule's own switch may still be stored on: it is saved after
            // this, so the rule is left out by its identifier.
            return $db->execute(
                'INSERT INTO `' . _DB_PREFIX_ . 'cart_rule_combination` (`id_cart_rule_1`, `id_cart_rule_2`)
                 SELECT `id_cart_rule`, ' . $idCartRule . ' FROM `' . $table . '`
                 WHERE `cart_rule_restriction` = 1 AND `id_cart_rule` != ' . $idCartRule
            ) ? [] : null;
        }

        $insert = [];
        foreach ($ids as $id) {
            $insert[] = ['id_cart_rule_1' => $idCartRule, 'id_cart_rule_2' => (int) $id];
        }
        if ($insert !== [] && !$db->insert('cart_rule_combination', $insert)) {
            return null;
        }

        $others = array_map('intval', array_column($db->executeS(
            'SELECT cr.`id_cart_rule` FROM `' . $table . '` cr
             WHERE cr.`id_cart_rule` != ' . $idCartRule . ' AND cr.`cart_rule_restriction` = 0
             AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'cart_rule_combination` crc
                 WHERE (crc.`id_cart_rule_1` = ' . $idCartRule . ' AND crc.`id_cart_rule_2` = cr.`id_cart_rule`)
                    OR (crc.`id_cart_rule_2` = ' . $idCartRule . ' AND crc.`id_cart_rule_1` = cr.`id_cart_rule`))
             ORDER BY cr.`id_cart_rule`'
        ) ?: [], 'id_cart_rule'));
        foreach ($others as $idOther) {
            if (!$db->update('cart_rule', ['cart_rule_restriction' => 1], '`id_cart_rule` = ' . $idOther, 1)
                || !$db->execute(
                    'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'cart_rule_combination` (`id_cart_rule_1`, `id_cart_rule_2`)
                     SELECT `id_cart_rule`, ' . $idOther . ' FROM `' . $table . '`
                     WHERE `active` = 1 AND `id_cart_rule` != ' . $idCartRule . ' AND `id_cart_rule` != ' . $idOther
                )) {
                return null;
            }
        }

        return $others;
    }

    /**
     * The line that names the rules Compatibility with other cart rules restricted on its own.
     *
     * @param array<int, int> $others from writeCompatibility()
     *
     * @return string empty when there are none
     */
    protected static function compatibilityWarning(array $others): string
    {
        if ($others === []) {
            return '';
        }

        return count($others) . ' other cart rule(s) can no longer be combined with this one, as in the back office: '
            . implode(', ', $others) . '.';
    }

    /**
     * Writes the rows of planRestrictions() and sets the matching switches of the rule.
     *
     * The same rows AdminCartRulesController::afterAdd() inserts, through Db
     * since these tables have no object, but only for the restrictions given:
     * the back office's afterUpdate() wipes every restriction of the rule
     * before writing back what its form posted. Product selection has its own
     * steps, in writeProductSelection(), and so has the compatibility with
     * other rules, in writeCompatibility().
     *
     * somcp_create_cart_rule sets the switches before add() and calls this
     * right after it, with no transaction: a write that fails removes the
     * rule, and CartRule::delete() takes its rows with it.
     * somcp_update_cart_rule calls this before update(), so the switches set
     * here and the rows go in together, inside one transaction, since the
     * rule it changes must keep its old rows if the save fails.
     *
     * @param CartRule $rule saved already, so it has its identifier
     * @param array<string, array<int, int>|null> $rows from planRestrictions()
     *
     * @return bool
     */
    protected static function writeRestrictions(CartRule $rule, array $rows): bool
    {
        $idCartRule = (int) $rule->id;
        $db = Db::getInstance();
        foreach ($rows as $key => $ids) {
            if (!isset(self::RESTRICTION_TABLES[$key])) {
                continue;
            }
            [$table, $column] = self::RESTRICTION_TABLES[$key];
            if (!$db->delete($table, '`id_cart_rule` = ' . $idCartRule)) {
                return false;
            }
            $insert = [];
            foreach ($ids ?? [] as $id) {
                $insert[] = ['id_cart_rule' => $idCartRule, $column => (int) $id];
            }
            if ($insert !== [] && !$db->insert($table, $insert)) {
                return false;
            }
            $rule->{$key . '_restriction'} = $ids !== null;
        }

        return true;
    }
}
