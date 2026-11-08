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
 * somcp_create_catalog_price_rule - one catalogue price rule, as Catalog >
 * Discounts > Catalog Price Rules > Add new catalog price rule, with its
 * conditions, applied at once.
 *
 * Every field takes the name somcp_list_catalog_price_rules returns, so a rule
 * read there can be given back. The checks of the price and the reduction are
 * those of one specific price of somcp_create_product (rule()), called rather
 * than copied: the rule writes specific prices, and the same promotion must be
 * accepted or refused the same way whichever tool sets it.
 *
 * Everything is checked before anything is written, and every problem is
 * reported at once. The writes follow AdminSpecificPriceRuleController::
 * processSave(): SpecificPriceRule::add(), addConditions() once per group, then
 * apply(). If a condition group cannot be written, the rule is deleted again,
 * so a half-built rule never discounts the wrong products.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends, and it answers
// with the rule as somcp_list_catalog_price_rules builds it.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolListCatalogPriceRules.php';

class SomcpToolCreateCatalogPriceRule extends SomcpToolCreateProduct
{
    /** @var array<int, string> Every argument this tool takes. */
    private const RULE_FIELDS = [
        'name', 'currency', 'country', 'id_group', 'from_quantity', 'price', 'reduction_type', 'reduction',
        'reduction_tax', 'from', 'to', 'condition_groups',
    ];

    /**
     * @var array<int, string> What somcp_list_catalog_price_rules returns and this tool refuses, because the shop
     *                         works it out. Named, so the assistant learns why rather than meeting an "unknown field".
     */
    private const RULE_COMPUTED = ['id_specific_price_rule', 'id_shop', 'group_name', 'amounts_currency', 'current'];

    /** @var int The size the form allows for the name (its maxlength on 8.0 and 9.0). */
    private const NAME_MAX = 255;

    /**
     * @var array<string, array{0: string, 1: string}> Per condition type, the table that holds the identifier and the
     *                                                 list tool that gives it.
     */
    private const CONDITION_SOURCES = [
        'category' => ['category', 'somcp_list_categories'],
        'manufacturer' => ['manufacturer', 'somcp_list_manufacturers'],
        'supplier' => ['supplier', 'somcp_list_suppliers'],
        'attribute' => ['attribute', 'somcp_list_attributes'],
        'feature' => ['feature_value', 'somcp_list_features'],
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_catalog_price_rule',
            Somcp::t('Create a catalogue price rule'),
            'Creates one catalogue price rule, as Catalog > Discounts > Catalog Price Rules > Add new, and applies it '
                . 'to its products at once: confirm with the merchant first. Fields have the names of '
                . 'somcp_list_catalog_price_rules. In condition_groups, a product must meet every condition of one '
                . 'group, and one group is enough; none means every product. Not for a voucher, nor one product\'s '
                . 'own price. Not to be retried: if the answer is lost, look with somcp_list_catalog_price_rules.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Name, 255 characters at most.',
                    ],
                    'currency' => [
                        'type' => 'string',
                        'description' => 'ISO code of a currency of the shop. All currencies when left out.',
                    ],
                    'country' => [
                        'type' => 'string',
                        'description' => 'ISO code of a country. All countries when left out.',
                    ],
                    'id_group' => [
                        'type' => 'integer',
                        'description' => 'A customer group from somcp_list_customer_groups, 0 (the default) for all.',
                    ],
                    'from_quantity' => [
                        'type' => 'integer',
                        'description' => 'From quantity, 1 by default.',
                    ],
                    'price' => [
                        'type' => 'number',
                        'description' => 'A fixed price tax excluded, or -1 (the default) to leave each product\'s price.',
                    ],
                    'reduction_type' => [
                        'type' => 'string',
                        'enum' => ['amount', 'percentage'],
                        'description' => 'amount (the default) or percentage.',
                    ],
                    'reduction' => [
                        'type' => 'number',
                        'description' => 'An amount, or for a percentage the fraction (0.1 for 10 %).',
                    ],
                    'reduction_tax' => [
                        'type' => 'boolean',
                        'description' => 'True (the default) for an amount tax included, false for tax excluded.',
                    ],
                    'from' => [
                        'type' => 'string',
                        'description' => 'Start, YYYY-MM-DD HH:MM:SS. No start when left out.',
                    ],
                    'to' => [
                        'type' => 'string',
                        'description' => 'End, YYYY-MM-DD HH:MM:SS, not before from. No end when left out.',
                    ],
                    'condition_groups' => [
                        'type' => 'array',
                        'description' => 'Groups of conditions, each a list of {"type": "category", "id_category": 4}; '
                            . 'types category, manufacturer, supplier, attribute, feature (id_feature_value).',
                        'items' => [
                            'type' => 'array',
                            'items' => ['type' => 'object'],
                        ],
                    ],
                ],
                'required' => ['name', 'reduction'],
                'additionalProperties' => false,
            ],
            [SomcpScope::SALES_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::ADD, 'AdminSpecificPriceRule')
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
        $idShop = Somcp::currentShopId();
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = [];
        $plan = self::plan($arguments, $idShop, $errors);
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
     * Nothing is written here, so a refused call leaves the shop exactly as it was.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function plan(array $arguments, int $idShop, array &$errors): ?array
    {
        foreach (array_keys($arguments) as $field) {
            if (in_array($field, self::RULE_COMPUTED, true)) {
                $errors[] = $field . ': worked out by the shop, not given';
            } elseif (!in_array($field, self::RULE_FIELDS, true)) {
                $errors[] = $field . ': not a field of this tool';
            }
        }

        $name = isset($arguments['name']) ? self::string($arguments['name']) : null;
        $name = $name === null ? '' : trim($name);
        if ($name === '') {
            $errors[] = 'name: required, a text';
        } elseif (Tools::strlen($name) > self::NAME_MAX) {
            // Checked here on both versions: only 9.0 declares the size in the
            // class, and 8.0 would cut the name silently in the column.
            $errors[] = 'name: ' . self::NAME_MAX . ' characters at most';
        } elseif (($check = (new SpecificPriceRule())->validateField('name', $name)) !== true) {
            $errors[] = 'name: ' . self::reason($check);
        }

        if (!isset($arguments['reduction'])) {
            $errors[] = 'reduction: required, an amount, or for a percentage the fraction (0.1 for 10 %)';
        }

        // The defaults of the form: all currencies, countries and groups, from
        // one unit, Leave initial price ticked, an amount tax included.
        $given = array_intersect_key($arguments, array_flip(['currency', 'country', 'id_group', 'from_quantity', 'price', 'reduction_type', 'reduction', 'reduction_tax', 'from', 'to']));
        $rule = self::rule($given, '', [
            'combination' => null,
            'id_currency' => 0,
            'id_country' => 0,
            'id_group' => 0,
            'id_customer' => 0,
            'from_quantity' => 1,
            'price' => -1.0,
            'reduction' => 0.0,
            'reduction_type' => 'amount',
            'reduction_tax' => 1,
            'from' => '0000-00-00 00:00:00',
            'to' => '0000-00-00 00:00:00',
        ], 0, $idShop, $errors);

        $groups = self::conditionGroups($arguments['condition_groups'] ?? null, $errors);

        if ($errors !== []) {
            return null;
        }

        return ['name' => $name, 'rule' => $rule, 'condition_groups' => $groups];
    }

    /**
     * The condition groups, checked, as the type and value pairs addConditions() writes.
     *
     * @param mixed $value
     * @param array<int, string> $errors
     *
     * @return array<int, array<int, array{type: string, value: int}>>
     */
    private static function conditionGroups($value, array &$errors): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || ($value !== [] && array_keys($value) !== range(0, count($value) - 1))) {
            $errors[] = 'condition_groups: a list of groups, each a list of conditions';

            return [];
        }

        $groups = [];
        foreach ($value as $i => $group) {
            $path = 'condition_groups[' . $i . ']';
            if (!is_array($group) || $group === [] || array_keys($group) !== range(0, count($group) - 1)) {
                // An empty group would match every product, which the merchant
                // gets by leaving condition_groups out instead.
                $errors[] = $path . ': a list of one condition or more';
                continue;
            }
            $conditions = [];
            foreach ($group as $j => $condition) {
                $at = $path . '[' . $j . ']';
                if (!is_array($condition)) {
                    $errors[] = $at . ': a condition, {"type": ..., "<identifier>": ...}';
                    continue;
                }
                $type = isset($condition['type']) && is_string($condition['type']) ? $condition['type'] : '';
                if (!isset(SomcpToolListCatalogPriceRules::CONDITION_IDS[$type])) {
                    $errors[] = $at . '.type: category, manufacturer, supplier, attribute or feature';
                    continue;
                }
                $key = SomcpToolListCatalogPriceRules::CONDITION_IDS[$type];
                // name is what the list returns beside the identifier: accepted
                // and ignored, so a rule read can be given back as it came.
                foreach (array_keys($condition) as $field) {
                    if (!in_array($field, ['type', $key, 'name'], true)) {
                        $errors[] = $at . '.' . $field . ': not a field of a ' . $type . ' condition, which takes ' . $key;
                    }
                }
                [$table, $listTool] = self::CONDITION_SOURCES[$type];
                $id = isset($condition[$key]) ? self::integer($condition[$key]) : null;
                if ($id === null || $id < 1 || !self::exists($table, $id)) {
                    $errors[] = $at . '.' . $key . ': no ' . str_replace('_', ' ', $table) . ' has this identifier (take ' . $key . ' from ' . $listTool . ')';
                    continue;
                }
                $conditions[] = ['type' => $type, 'value' => $id];
            }
            $groups[] = $conditions;
        }

        return $groups;
    }

    /**
     * Whether a row of a catalogue table carries this identifier.
     *
     * @param string $table one of the tables of CONDITION_SOURCES, never a caller's value
     * @param int $id
     *
     * @return bool
     */
    private static function exists(string $table, int $id): bool
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . bqSQL($table) . '` WHERE `id_' . bqSQL($table) . '` = ' . (int) $id
        ) > 0;
    }

    /**
     * Writes the rule, its conditions, applies it, and answers with what was stored.
     *
     * @param array<string, mixed> $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function addRule(array $plan, int $idShop, int $idEmployee): array
    {
        $values = $plan['rule'];
        $rule = new SpecificPriceRule();
        $rule->name = (string) $plan['name'];
        $rule->id_shop = $idShop;
        $rule->id_currency = (int) $values['id_currency'];
        $rule->id_country = (int) $values['id_country'];
        $rule->id_group = (int) $values['id_group'];
        $rule->from_quantity = (int) $values['from_quantity'];
        $rule->price = (float) $values['price'];
        $rule->reduction_type = (string) $values['reduction_type'];
        // The rule stores a percentage as the form shows it (10) and
        // applyRuleToProduct() divides it by 100; the call gives the fraction
        // somcp_create_product takes for the same promotion.
        $rule->reduction = $values['reduction_type'] === 'percentage'
            ? round((float) $values['reduction'] * 100, 6)
            : (float) $values['reduction'];
        $rule->reduction_tax = (int) $values['reduction_tax'];
        $rule->from = (string) $values['from'];
        $rule->to = (string) $values['to'];

        $failure = '';
        try {
            if (!$rule->add()) {
                $failure = 'PrestaShop refused to save it';
            } else {
                foreach ($plan['condition_groups'] as $conditions) {
                    if ($rule->addConditions($conditions) !== true) {
                        $failure = 'a condition group could not be saved';
                        break;
                    }
                }
            }
            if ($failure === '') {
                $rule->apply();
            }
        } catch (Throwable $e) {
            $failure = $e->getMessage();
        }

        if ($failure !== '') {
            $kept = false;
            if ((int) $rule->id > 0) {
                try {
                    // delete() also removes the conditions and the specific
                    // prices apply() may have written.
                    $kept = !$rule->delete();
                } catch (Throwable $e) {
                    $kept = true;
                }
            }
            SomcpLogger::log(
                'catalog_price_rule_create_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr($failure, 0, 500) . ($kept ? ', catalogue price rule ' . (int) $rule->id . ' could not be removed' : ''),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The catalogue price rule could not be created: ' . $failure . '. '
                    . ($kept
                        ? 'It could not be removed either: rule ' . (int) $rule->id . ' exists in an incomplete state. '
                            . 'Tell the merchant to check it in the back office; do not call again.'
                        : 'Nothing was kept.'),
                true
            );
        }

        $idRule = (int) $rule->id;
        SomcpLogger::log('catalog_price_rule_created', SomcpLogger::OUTCOME_SUCCESS, 'catalogue price rule ' . $idRule, '', $idEmployee);

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'specific_price_rule` WHERE `id_specific_price_rule` = ' . $idRule
        );
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $defaultCurrency = (string) Currency::getIsoCodeById((int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop));

        $answer = [
            'created' => true,
            'catalog_price_rule' => is_array($row) ? SomcpToolListCatalogPriceRules::buildRule($row, $idLang, $defaultCurrency) : ['id_specific_price_rule' => $idRule],
        ];

        if ($plan['condition_groups'] === []) {
            // A rule without condition is one specific price for product 0,
            // which the shop applies to each of its products.
            $answer['all_products'] = true;
            $answer['products_affected'] = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `id_shop` = ' . (int) $idShop
            );
        } else {
            // An attribute condition picks combinations: each product is
            // counted once, whatever number of its combinations is touched.
            $products = [];
            foreach ($rule->getAffectedProducts() as $product) {
                $products[(int) $product['id_product']] = true;
            }
            $answer['all_products'] = false;
            $answer['products_affected'] = count($products);
        }

        return SomcpTool::json($answer);
    }
}
