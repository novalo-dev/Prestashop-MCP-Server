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
 * Conditions and Actions tabs of Catalog > Discounts > Cart Rules > Add new,
 * restrictions apart.
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
                . 'active is true: confirm with the merchant first. Restrictions (countries, carriers, groups, other '
                . 'rules, products, shops) are set in the back office. Not for changing a rule, nor for catalogue '
                . 'price rules. Not to be retried: if the answer is lost, look for the rule with somcp_list_cart_rules '
                . 'first.',
            self::inputSchema(),
            [SomcpScope::SALES_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::ADD, 'AdminCartRules')
        );
    }

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
                'date_from' => ['type' => 'string', 'description' => 'Valid from, ISO 8601 or YYYY-MM-DD HH:MM:SS, shop time. Now by default.'],
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
            return SomcpTool::text(
                "Nothing was created. Correct these and call again:\n- " . implode("\n- ", array_unique($errors)),
                true
            );
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
     *
     * @return array<string, mixed>|null
     */
    protected static function planRule(array $arguments, int $idShop, array &$errors): ?array
    {
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if ($key === 'restrictions') {
                // Given back from somcp_get_cart_rule with every restriction
                // off, it asks for nothing this tool does not already do.
                $given = is_array($arguments['restrictions']) ? array_filter($arguments['restrictions'], static function ($value): bool {
                    return $value !== null;
                }) : [1];
                if ($given !== []) {
                    $errors[] = 'restrictions: not set by this tool - the merchant sets them on the Conditions tab of the rule in the back office';
                }
                continue;
            }
            if (!in_array($key, self::RULE_ARGUMENTS, true)) {
                $errors[] = $key . (in_array($key, self::RULE_COMPUTED, true)
                    ? ': worked out by the shop, so it is not written here'
                    : ': not a field of this tool');
            }
        }

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
            } elseif ($code !== '' && ($same = (int) CartRule::getIdByCode($code)) > 0) {
                // Compared on every shop, as the back office does: a code is
                // what the customer types, and two rules cannot answer to it.
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
            $idCustomer = self::integer($arguments['id_customer']) ?? 0;
            $customer = $idCustomer > 0 ? new Customer($idCustomer) : null;
            if ($customer === null || !Validate::isLoadedObject($customer) || (int) $customer->id_shop !== $idShop || (int) $customer->deleted === 1) {
                $errors[] = 'id_customer: no customer of this shop has the identifier ' . $idCustomer . ' - find it with somcp_search_customers';
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
                    $idProduct = self::integer($reduction['id_product']) ?? -1;
                }
            }
        }

        $appliesTo = $arguments['reduction_applies_to'] ?? null;
        $target = 'order';
        if ($appliesTo !== null) {
            // The string somcp_get_cart_rule returns, or an object carrying the product.
            if (is_array($appliesTo) && !array_is_list($appliesTo)) {
                if (isset($appliesTo['id_product'])) {
                    $idProduct = self::integer($appliesTo['id_product']) ?? -1;
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
            if ($idProduct !== 0) {
                $errors[] = 'reduction.id_product: only with reduction_applies_to specific_product';
            }
            $fields['reduction_product'] = self::APPLIES_TO[$target];

            return 0;
        }

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        if ($idProduct <= 0) {
            $errors[] = 'reduction.id_product: required for specific_product, from somcp_search_products';

            return 0;
        }
        if (SomcpToolGetProduct::locate($idProduct, $idLang, $idShop) === null) {
            $errors[] = 'reduction.id_product: no product of this shop has the identifier ' . $idProduct . ' - find it with somcp_search_products';

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

        $idProduct = self::integer($gift['id_product'] ?? null) ?? 0;
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $product = $idProduct > 0 ? SomcpToolGetProduct::locate($idProduct, $idLang, $idShop) : null;
        if ($product === null) {
            $errors[] = 'gift.id_product: no product of this shop has the identifier ' . $idProduct . ' - find it with somcp_search_products';

            return;
        }
        if (!empty(Product::getRequiredCustomizableFieldsStatic($idProduct))) {
            $errors[] = 'gift.id_product: Product with required customization fields cannot be used as a gift.';
        }

        $idCombination = self::integer($gift['id_product_attribute'] ?? 0) ?? -1;
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
     * An active currency of the shop by ISO code, or 0 with the problem said.
     *
     * @param mixed $value
     * @param int $idShop
     * @param string $path
     * @param array<int, string> $errors
     *
     * @return int
     */
    protected static function activeCurrency($value, int $idShop, string $path, array &$errors): int
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

        return $cartRule;
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
        $cartRule = self::newRule($plan);

        $failure = '';
        try {
            $done = (bool) $cartRule->add();
            if (!$done) {
                $failure = 'PrestaShop refused to save it';
            } else {
                $failure = self::afterAdd((int) $cartRule->id, $idShop, (int) $plan['specific_product'], $multistore);
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
        return SomcpTool::json([
            'created' => true,
            'id_cart_rule' => $idCartRule,
            'cart_rule' => SomcpToolGetCartRule::byId($idCartRule, (int) $plan['languages']['fallback'], $idShop),
        ]);
    }

    /**
     * The rows AdminCartRulesController::afterAdd() writes for a new rule that
     * CartRule::add() does not. These tables have no ObjectModel, so they are
     * written as the back office writes them.
     *
     * @param int $idCartRule
     * @param int $idShop
     * @param int $idProduct the product of a specific product discount, 0 for none
     * @param bool $multistore
     *
     * @return string empty when every row went in, else what failed
     */
    private static function afterAdd(int $idCartRule, int $idShop, int $idProduct, bool $multistore): string
    {
        $db = Db::getInstance();

        if ($multistore && !$db->insert('cart_rule_shop', ['id_cart_rule' => $idCartRule, 'id_shop' => $idShop])) {
            return 'the shop of the rule could not be saved';
        }

        // Specific product: one group, quantity 1, holding that product, so the
        // rule only applies to carts that contain it.
        if ($idProduct > 0) {
            if (!$db->insert('cart_rule_product_rule_group', ['id_cart_rule' => $idCartRule, 'quantity' => 1])) {
                return 'the product selection could not be saved';
            }
            $idGroup = (int) $db->Insert_ID();
            if (!$db->insert('cart_rule_product_rule', ['id_product_rule_group' => $idGroup, 'type' => 'products'])) {
                return 'the product selection could not be saved';
            }
            $idRule = (int) $db->Insert_ID();
            if (!$db->insert('cart_rule_product_rule_value', ['id_product_rule' => $idRule, 'id_item' => $idProduct])) {
                return 'the product selection could not be saved';
            }
        }

        // The new rule restricts nothing, so every rule that restricts its
        // compatibility takes it on its white list, as the back office does on
        // every save: otherwise a voucher made here could never be combined
        // with them, while the same voucher made on screen can.
        if (!$db->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'cart_rule_combination` (`id_cart_rule_1`, `id_cart_rule_2`)
             SELECT `id_cart_rule`, ' . $idCartRule . ' FROM `' . _DB_PREFIX_ . 'cart_rule`
             WHERE `cart_rule_restriction` = 1 AND `id_cart_rule` != ' . $idCartRule
        )) {
            return 'the compatibility with the other cart rules could not be saved';
        }

        return '';
    }
}
