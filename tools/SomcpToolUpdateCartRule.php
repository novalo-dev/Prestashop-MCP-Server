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
 * somcp_update_cart_rule - one existing cart rule, changed with everything
 * somcp_create_cart_rule can write.
 *
 * It extends the creation tool rather than copying it: the rule as it will be
 * once merged with what is kept goes through the same planRule(), so a value
 * is refused here exactly when the creation, and the back office, refuse it.
 *
 * Only what is given changes. The stored rule is read the way
 * somcp_get_cart_rule reads it, the arguments are laid over it, and only the
 * columns of the fields given are compared and written: a call that changes
 * nothing writes nothing and answers changed: false.
 *
 * The rule is written through CartRule::update() only. The back office's
 * afterUpdate() deletes every restriction row of the rule and writes back
 * what its form posted; this tool posts no restriction, so going that way
 * would wipe them.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolCreateCartRule.php';

class SomcpToolUpdateCartRule extends SomcpToolCreateCartRule
{
    /**
     * @var array<string, array<int, string>> The cart_rule columns each argument writes. An argument whose columns
     *                                        all hold what the plan holds changes nothing.
     */
    private const COLUMNS = [
        'description' => ['description'],
        'code' => ['code'],
        'highlight' => ['highlight'],
        'partial_use' => ['partial_use'],
        'priority' => ['priority'],
        'id_customer' => ['id_customer'],
        'date_from' => ['date_from'],
        'date_to' => ['date_to'],
        'minimum_amount' => ['minimum_amount', 'minimum_amount_currency', 'minimum_amount_tax', 'minimum_amount_shipping'],
        'quantity' => ['quantity'],
        'quantity_per_user' => ['quantity_per_user'],
        'free_shipping' => ['free_shipping'],
        'reduction' => ['reduction_percent', 'reduction_amount', 'reduction_currency', 'reduction_tax', 'reduction_product'],
        'reduction_applies_to' => ['reduction_percent', 'reduction_amount', 'reduction_currency', 'reduction_tax', 'reduction_product'],
        'reduction_exclude_special' => ['reduction_exclude_special'],
        'gift' => ['gift_product', 'gift_product_attribute'],
    ];

    /**
     * @var array<int, string> what somcp_get_cart_rule returns that is not written here: accepted when given back
     *                         unchanged, so a rule read there can be given back as is
     */
    private const KEPT_AS_READ = ['active', 'restrictions', 'customer', 'summary', 'times_used', 'date_add', 'date_upd'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_cart_rule',
            Somcp::t('Change a cart rule'),
            'Changes one cart rule, as Catalog > Discounts > Cart Rules > Edit, under the names of '
                . 'somcp_create_cart_rule; what somcp_get_cart_rule returns can be given back. id_cart_rule from '
                . 'somcp_list_cart_rules. Only what is given changes. quantity is what is left. Placed orders keep their '
                . 'amount. Status: somcp_set_cart_rule_active; restrictions stay in the back office. Show the merchant the change and call once '
                . 'they agree. Not to be retried: if the answer is lost, read somcp_get_cart_rule first.',
            self::inputSchema(),
            [SomcpScope::SALES_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminCartRules')
        );
    }

    /**
     * The creation schema, made to name an existing rule and to keep what is left out.
     *
     * Derived rather than written again, so a field added to the creation is
     * offered here too, with the same description.
     *
     * @return array<string, mixed>
     */
    protected static function inputSchema(): array
    {
        $schema = parent::inputSchema();
        $properties = $schema['properties'];
        unset($properties['active']);
        $kept = ' Left out: unchanged.';

        $properties['languages']['description'] = 'ISO codes of the languages the name is given in, among the active '
            . 'languages of the shop. All of them by default.';
        $properties['name']['description'] = 'Merchant. Name shown to the customer, keyed by ISO code: {"fr": "..."}. '
            . 'Changes in the languages given and keeps the others. 254 characters at most.' . $kept;
        $properties['code']['description'] = 'Merchant. The code the customer types; its own code is accepted, a code '
            . 'another rule has is refused. Empty: the rule applies on its own.' . $kept;
        $properties['quantity']['description'] = 'Total available: what is left, every order using the rule has '
            . 'lowered it already.' . $kept;
        foreach (array_keys(self::COLUMNS) as $field) {
            // Kept within the 200 characters of a property description.
            if (isset($properties[$field]['description']) && !str_ends_with($properties[$field]['description'], $kept)
                && strlen($properties[$field]['description'] . $kept) <= 200) {
                $properties[$field]['description'] .= $kept;
            }
        }

        $schema['properties'] = [
            'id_cart_rule' => [
                'type' => 'integer',
                'description' => 'The rule to change, the ID column of somcp_list_cart_rules.',
            ],
        ] + $properties;
        // What somcp_get_cart_rule returns and is not written here, offered so
        // a rule read there can be given back whole.
        foreach (self::KEPT_AS_READ as $field) {
            $schema['properties'][$field] = [
                'description' => 'As somcp_get_cart_rule returns it, accepted unchanged only: '
                    . ($field === 'active' ? 'switched with somcp_set_cart_rule_active.'
                        : ($field === 'restrictions' ? 'the merchant sets them on the Conditions tab of the rule.' : 'worked out by the shop.')),
            ];
        }
        $schema['required'] = ['id_cart_rule'];

        return $schema;
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
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

        $idCartRule = self::integer($arguments['id_cart_rule'] ?? null) ?? 0;
        // The same reading as somcp_get_cart_rule, so a rule this tool refuses
        // is never one that tool describes.
        $current = $idCartRule > 0 ? SomcpToolGetCartRule::byId($idCartRule, $idLang, $idShop) : null;
        $cartRule = new CartRule(max(0, $idCartRule));
        if ($current === null || !Validate::isLoadedObject($cartRule)) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- id_cart_rule: no cart rule of this shop has the "
                    . 'identifier ' . $idCartRule . ' - find it with somcp_list_cart_rules',
                true
            );
        }

        $errors = [];
        $given = self::given($arguments, $current, $errors);
        $plan = self::planRule(self::merged($given, $current), $idShop, $errors, $idCartRule);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
        }

        $write = self::differences($cartRule, $given, $plan);
        if ($write['fields'] === [] && $write['names'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_cart_rule' => $idCartRule,
                'reason' => 'The rule already holds everything given. Nothing was written.',
            ]);
        }

        return self::asEmployee($idEmployee, static function () use ($cartRule, $current, $write, $plan, $idLang, $idShop, $idEmployee): array {
            return self::applyUpdate($cartRule, $current, $write, $plan, $idLang, $idShop, $idEmployee);
        });
    }

    /**
     * The arguments this call changes, with what was read and given back unchanged taken out.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $current the rule as somcp_get_cart_rule returns it
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>
     */
    private static function given(array $arguments, array $current, array &$errors): array
    {
        unset($arguments['id_cart_rule']);
        foreach (self::KEPT_AS_READ as $key) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            // Compared as JSON, the shape both tools speak, so a rule read
            // there and given back whole is not refused for what it carries.
            if (json_encode(self::withoutNames($arguments[$key])) !== json_encode(self::withoutNames($current[$key] ?? null))) {
                if ($key === 'active') {
                    $errors[] = 'active: not changed by this tool - switch the rule on or off with somcp_set_cart_rule_active';
                } elseif ($key === 'restrictions') {
                    $errors[] = 'restrictions: not set by this tool - the merchant sets them on the Conditions tab of the rule in the back office';
                } else {
                    $errors[] = $key . ': worked out by the shop, so it is not written here';
                }
            }
            unset($arguments[$key]);
        }

        return $arguments;
    }

    /**
     * A value read by somcp_get_cart_rule, the names it adds taken out: they
     * follow the language it was read in, and are not what is compared.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private static function withoutNames($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        unset($value['name']);
        foreach ($value as $key => $item) {
            $value[$key] = self::withoutNames($item);
        }

        return $value;
    }

    /**
     * The rule as it will be: what is stored, with what is given laid over it.
     *
     * planRule() then checks the whole of it, so a value given alone is
     * checked against the ones kept - a start after the kept end, or a rule
     * left giving nothing, is refused as the form refuses it.
     *
     * @param array<string, mixed> $given
     * @param array<string, mixed> $current
     *
     * @return array<string, mixed>
     */
    private static function merged(array $given, array $current): array
    {
        $merged = [];
        foreach (array_keys(self::COLUMNS) as $field) {
            if (array_key_exists($field, $current)) {
                $merged[$field] = $current[$field];
            }
        }

        // The product of a specific product discount travels inside reduction:
        // a new amount given without it keeps the product it applied to.
        $currentReduction = is_array($current['reduction'] ?? null) ? $current['reduction'] : null;
        if (array_key_exists('reduction', $given)) {
            if ($given['reduction'] === null && !array_key_exists('reduction_applies_to', $given)) {
                $merged['reduction_applies_to'] = null;
            } elseif (is_array($given['reduction']) && !array_key_exists('id_product', $given['reduction'])
                && !array_key_exists('reduction_applies_to', $given) && isset($currentReduction['id_product'])) {
                $given['reduction']['id_product'] = $currentReduction['id_product'];
            }
        } elseif (array_key_exists('reduction_applies_to', $given) && is_array($merged['reduction'] ?? null)) {
            // Moved to another target, the kept discount no longer names a
            // product; moved to a specific product, the one given wins.
            unset($merged['reduction']['id_product']);
        }

        $names = is_array($current['name'] ?? null) ? $current['name'] : [];
        $isos = null;
        if (array_key_exists('languages', $given) && is_array($given['languages'])) {
            $isos = array_map(static function ($iso): string {
                return is_string($iso) ? Tools::strtolower(trim($iso)) : '';
            }, $given['languages']);
            $names = array_intersect_key($names, array_flip($isos));
        }
        // A language whose name was never filled takes the first one filled,
        // as a rule created here or on screen has it: the stored rule is not
        // refused for a gap the merchant did not make in this call.
        $fallback = '';
        foreach ($names as $name) {
            if (is_string($name) && trim($name) !== '') {
                $fallback = $name;
                break;
            }
        }
        foreach ($names as $iso => $name) {
            if (!is_string($name) || trim($name) === '') {
                $names[$iso] = $fallback;
            }
        }

        // A plain string is the name in the one language being filled, as
        // the creation reads it.
        if (isset($given['name']) && is_string($given['name']) && $isos !== null && count($isos) === 1) {
            $given['name'] = [$isos[0] => $given['name']];
        }
        if (isset($given['name']) && is_array($given['name']) && !array_is_list($given['name'])) {
            foreach ($given['name'] as $iso => $name) {
                $names[Tools::strtolower((string) $iso)] = $name;
            }
        } elseif (array_key_exists('name', $given)) {
            // Not a text per language: left as given, for planRule() to say so.
            $names = $given['name'];
        }

        return array_merge($merged, $given, ['name' => $names]);
    }

    /**
     * The columns and names the call changes, the ones equal to the stored rule left out.
     *
     * @param CartRule $cartRule loaded in every language
     * @param array<string, mixed> $given
     * @param array<string, mixed> $plan from planRule()
     *
     * @return array{fields: array<string, mixed>, names: array<int, string>, specific_product: int}
     */
    private static function differences(CartRule $cartRule, array $given, array $plan): array
    {
        $fields = [];
        foreach (self::COLUMNS as $field => $columns) {
            if (!array_key_exists($field, $given)) {
                continue;
            }
            foreach (self::comparedColumns($field, $columns, $cartRule, $plan['fields']) as $column) {
                if (!self::same($cartRule->{$column}, $plan['fields'][$column])) {
                    foreach ($columns as $written) {
                        $fields[$written] = $plan['fields'][$written];
                    }
                    break;
                }
            }
        }

        $names = [];
        $stored = is_array($cartRule->name) ? $cartRule->name : [];
        $byIso = array_flip($plan['languages']['filled']);
        // A plain string was the name of the one language being filled.
        $givenIsos = [];
        if (isset($given['name']) && is_array($given['name'])) {
            $givenIsos = array_keys($given['name']);
        } elseif (isset($given['name']) && is_string($given['name'])) {
            $givenIsos = array_values($plan['languages']['filled']);
        }
        if ($givenIsos !== []) {
            foreach ($givenIsos as $iso) {
                $idLang = $byIso[Tools::strtolower((string) $iso)] ?? 0;
                if ($idLang > 0 && isset($plan['names'][$idLang]) && (string) ($stored[$idLang] ?? '') !== $plan['names'][$idLang]) {
                    $names[$idLang] = $plan['names'][$idLang];
                }
            }
        }

        // A discount newly sent to a product takes that product into Product
        // selection, as the form ticks it before saving.
        $specific = 0;
        if (isset($fields['reduction_product']) && (int) $plan['specific_product'] > 0
            && (int) $plan['specific_product'] !== (int) $cartRule->reduction_product) {
            $specific = (int) $plan['specific_product'];
            $fields['product_restriction'] = true;
        }

        return ['fields' => $fields, 'names' => $names, 'specific_product' => $specific];
    }

    /**
     * The columns that tell whether a field changes.
     *
     * The form saves a currency and a tax switch with every rule, even when the
     * amount they belong to is zero or the discount is a percent: those are
     * not compared then, so giving back what was read changes nothing.
     *
     * @param string $field
     * @param array<int, string> $columns
     * @param CartRule $cartRule
     * @param array<string, mixed> $planned
     *
     * @return array<int, string>
     */
    private static function comparedColumns(string $field, array $columns, CartRule $cartRule, array $planned): array
    {
        if ($field === 'minimum_amount' && (float) $planned['minimum_amount'] <= 0 && (float) $cartRule->minimum_amount <= 0) {
            return ['minimum_amount'];
        }
        if ($field === 'reduction' || $field === 'reduction_applies_to') {
            if ((float) $planned['reduction_amount'] <= 0 && (float) $cartRule->reduction_amount <= 0) {
                return ['reduction_percent', 'reduction_amount', 'reduction_product'];
            }
        }

        return $columns;
    }

    /**
     * Whether a stored column holds the planned value, read the way the column is typed.
     *
     * @param mixed $stored as ObjectModel loaded it, often a string
     * @param mixed $planned
     *
     * @return bool
     */
    private static function same($stored, $planned): bool
    {
        if (is_bool($planned)) {
            return (bool) $stored === $planned;
        }
        if (is_int($planned)) {
            return (int) $stored === $planned;
        }
        if (is_float($planned)) {
            // Amounts are read back rounded to the cent: given back, they are
            // the same amount.
            return abs((float) $stored - $planned) < 0.005;
        }

        return (string) $stored === (string) $planned;
    }

    /**
     * Writes the change and answers with the rule read back.
     *
     * @param CartRule $cartRule
     * @param array<string, mixed> $current the rule before, as somcp_get_cart_rule returns it
     * @param array{fields: array<string, mixed>, names: array<int, string>, specific_product: int} $write
     * @param array<string, mixed> $plan
     * @param int $idLang
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function applyUpdate(CartRule $cartRule, array $current, array $write, array $plan, int $idLang, int $idShop, int $idEmployee): array
    {
        $idCartRule = (int) $cartRule->id;
        self::fill($cartRule, $write['fields']);
        if ($write['names'] !== []) {
            $names = is_array($cartRule->name) ? $cartRule->name : [];
            foreach ($write['names'] as $idNameLang => $name) {
                $names[$idNameLang] = $name;
            }
            self::fill($cartRule, ['name' => $names]);
        }

        $failure = '';
        try {
            // update() only: it leaves the restriction tables as they are.
            $done = (bool) $cartRule->update();
            if (!$done) {
                $failure = 'PrestaShop refused to save it';
            } elseif ($write['specific_product'] > 0) {
                $failure = self::productSelection($idCartRule, $write['specific_product']);
            }
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        if (!$done || $failure !== '') {
            SomcpLogger::log(
                'cart_rule_update_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'cart rule ' . $idCartRule . ': ' . Tools::substr($failure, 0, 500),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The cart rule could not be changed: ' . $failure . '. Part of it may be saved. Read the rule again '
                    . 'with somcp_get_cart_rule, tell the merchant what it holds now, and do not call again before.',
                true
            );
        }

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $after = SomcpToolGetCartRule::byId($idCartRule, $idLang, $idShop) ?? [];
        $changes = [];
        foreach ($write['names'] as $idNameLang => $name) {
            $iso = (string) ($plan['languages']['filled'][$idNameLang] ?? '');
            $changes[] = ['field' => 'name', 'language' => $iso, 'old' => $current['name'][$iso] ?? '', 'new' => $after['name'][$iso] ?? $name];
        }
        foreach (array_keys(self::COLUMNS) as $field) {
            if (json_encode($current[$field] ?? null) !== json_encode($after[$field] ?? null)) {
                $changes[] = ['field' => $field, 'old' => $current[$field] ?? null, 'new' => $after[$field] ?? null];
            }
        }

        $result = [
            'changed' => true,
            'id_cart_rule' => $idCartRule,
            'changes' => $changes,
        ];

        // The form keeps the product it added to Product selection when the
        // discount moves away from it, and so does this tool.
        $left = (int) ($current['reduction']['id_product'] ?? 0);
        if ($left > 0 && $left !== (int) ($after['reduction']['id_product'] ?? 0)) {
            $result['product_selection'] = 'Product ' . $left . ' is still in Product selection, as the back office keeps '
                . 'it: the rule only applies to carts that hold it until the merchant removes it on the Conditions tab.';
        }

        SomcpLogger::log(
            'cart_rule_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'cart rule ' . $idCartRule . ', ' . count($changes) . ' change(s)',
            '',
            $idEmployee
        );

        $result['cart_rule'] = $after;

        return SomcpTool::json($result);
    }
}
