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
 * somcp_update_carrier - the General settings of one carrier of Shipping >
 * Carriers, as its edit page saves them: Carrier name, Transit time, Speed
 * grade, Tracking URL, Enabled / Active and Group access, its place in the
 * list, the Logo, the settings of Shipping locations and costs that are not
 * ranges (Free shipping, handling costs, billing, Tax and Out-of-range
 * behavior) and the size and weight limits. Only what is given changes; a call that
 * would change nothing writes nothing.
 *
 * The two versions of PrestaShop save a carrier differently: the 8.0.1 wizard
 * makes a new carrier at every save, 9.0.0 changes it in place while no order
 * uses it. The tool follows 9.0.0 on both: the new version only exists so
 * that past orders keep the carrier they were placed with, and a new
 * identifier at every call would break every id_carrier just read.
 *
 * It takes the answer of somcp_get_carrier back as it is: the fields other
 * tools write are compared with the shop and named only when they differ.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolUpdateCarrier
{
    /** @var array<int, string> The arguments this tool writes, under the names somcp_get_carrier returns. */
    private const ARGUMENTS = [
        'id_carrier', 'languages', 'name', 'delay', 'grade', 'tracking_url', 'active', 'group_access', 'position',
        'is_free', 'handling_costs', 'billing', 'id_tax_rules_group', 'out_of_range', 'max_width', 'max_height',
        'max_depth', 'max_weight', 'logo', 'remove_logo',
    ];

    /**
     * @var array<int, string> The other keys of the answer of somcp_get_carrier,
     *                         passed when they hold what the shop holds, so a read given back changes nothing
     */
    private const READ_BACK = [
        'id_reference', 'external_module', 'zones', 'tax_rule', 'handling_cost_tax_excl', 'ranges',
        'range_unit', 'dimension_unit', 'weight_unit',
    ];

    /** @var array<int, string> The three sizes, whole numbers in the dimension unit of the shop. */
    private const SIZES = ['max_width', 'max_height', 'max_depth'];

    /** @var int The largest value of the unsigned int(10) size columns. */
    private const SIZE_MAX = 4294967295;

    /** @var array<int, string> The values of billing, by the shipping_method they store. */
    private const BILLING = [
        Carrier::SHIPPING_METHOD_WEIGHT => 'weight',
        Carrier::SHIPPING_METHOD_PRICE => 'price',
    ];

    /** @var array<int, string> Keys of the read that describe the answer rather than the carrier. */
    private const READ_ONLY = ['language', 'warning'];

    /** @var int The size of carrier.url, which the definition does not declare. */
    private const URL_SIZE = 255;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_carrier',
            Somcp::t('Change a carrier'),
            'Changes one carrier of Shipping > Carriers: Carrier name, Transit time, Speed grade, Logo, Tracking '
                . 'URL, Active, Group access, the place in the list, Free shipping, handling costs, billing by '
                . 'price or weight, Tax, Out-of-range behavior and the size and weight limits. Only the fields '
                . 'given change; the answer of somcp_get_carrier can be given back as it is. A carrier an order '
                . 'uses gets a new id_carrier, which the answer gives. Not for the carrier of one order '
                . '(somcp_set_order_carrier). Replayable: the same call twice changes nothing the second time.',
            [
                'type' => 'object',
                'properties' => [
                    'id_carrier' => [
                        'type' => 'integer',
                        'description' => 'The carrier to change, from somcp_list_carriers or somcp_get_carrier.',
                    ],
                    'languages' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'ISO codes of the languages delay is given in; every active language '
                            . 'when left out. A language left out keeps its text.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Carrier name, 64 characters at most.',
                    ],
                    'delay' => [
                        'type' => 'object',
                        'description' => 'Transit time, keyed by ISO code, for example {"en": "2 to 3 days"}; '
                            . '512 characters at most.',
                    ],
                    'grade' => [
                        'type' => 'integer',
                        'description' => 'Speed grade, from 0 (slow) to 9 (fast).',
                    ],
                    'tracking_url' => [
                        'type' => 'string',
                        'description' => 'Tracking URL, @ where the tracking number goes; an empty text '
                            . 'removes it.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Whether customers can choose the carrier.',
                    ],
                    'group_access' => [
                        'type' => 'array',
                        'description' => 'Every customer group allowed to use the carrier after the change, '
                            . 'as id_group integers from somcp_list_customer_groups or the objects '
                            . 'somcp_get_carrier answers.',
                    ],
                    'position' => [
                        'type' => 'integer',
                        'description' => 'Merchant. The place among the carriers, from 0 as somcp_list_carriers '
                            . 'gives it (the back office list shows it plus 1); the others shift.',
                    ],
                    'is_free' => [
                        'type' => 'boolean',
                        'description' => 'Free shipping. Turned on alone, it turns the handling costs off.',
                    ],
                    'handling_costs' => [
                        'type' => 'boolean',
                        'description' => 'Whether the carrier adds the handling costs of Shipping > Preferences. '
                            . 'Turned on alone, it turns Free shipping off.',
                    ],
                    'billing' => [
                        // null too: the billing a free carrier is read with, given back as it is.
                        'type' => ['string', 'null'],
                        'description' => 'Billing: "price" or "weight", by the total price or the total weight '
                            . 'of the order.',
                    ],
                    'id_tax_rules_group' => [
                        'type' => 'integer',
                        'description' => 'Tax: a tax rule from somcp_list_tax_rules, or 0 for No tax. For the '
                            . 'shop of the token.',
                    ],
                    'out_of_range' => [
                        'type' => 'string',
                        'enum' => ['highest_range', 'disable_carrier'],
                        'description' => 'Out-of-range behavior: charge the highest range, or disable the carrier.',
                    ],
                    'max_width' => [
                        'type' => 'integer',
                        'description' => 'Largest package width the carrier takes, a whole number in the '
                            . 'dimension_unit of the shop; 0 for no limit.',
                    ],
                    'max_height' => [
                        'type' => 'integer',
                        'description' => 'Largest package height, as max_width.',
                    ],
                    'max_depth' => [
                        'type' => 'integer',
                        'description' => 'Largest package depth, as max_width.',
                    ],
                    'max_weight' => [
                        'type' => 'number',
                        'description' => 'Heaviest package the carrier takes, in the weight_unit of the shop; 0 '
                            . 'for no limit.',
                    ],
                    'logo' => SomcpUpload::schema('Merchant. A new logo, which replaces the current one.', 'the logo'),
                    'remove_logo' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. true removes the logo. Not with logo, which already replaces it.',
                    ],
                ],
                'required' => ['id_carrier'],
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The right the carrier edit page asks for in Team, Permissions.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminCarriers'),
            files: ['logo' => SomcpUpload::images()]
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
        $idShop = (int) Somcp::currentShop()->id;
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = SomcpTool::unknownFields(
            $arguments,
            array_merge(self::ARGUMENTS, self::READ_BACK, self::READ_ONLY)
        );

        $idCarrier = SomcpTool::id($arguments, 'id_carrier');
        $read = null;
        if ($idCarrier === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_carrier');
        } else {
            $read = SomcpToolGetCarrier::carrier($idCarrier, $idShop, (int) Configuration::get('PS_LANG_DEFAULT'));
            if ($read === null) {
                $errors[] = SomcpToolGetCarrier::notFound($idCarrier, $idShop);
            }
        }

        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $errors);
        $plan = $languages === null ? null : self::plan($arguments, $read, $idCarrier, $idShop, $languages, $errors);
        if ($errors !== [] || $plan === null || $read === null) {
            return SomcpTool::refused('changed', $errors);
        }

        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_carrier' => $idCarrier,
                'reason' => 'The carrier already holds every value given. Nothing was written.',
                'carrier' => SomcpToolGetCarrier::carrier($idCarrier, $idShop, (int) $languages['fallback']),
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($idCarrier, $idShop, $plan, $languages, $idEmployee): array {
            return self::apply($idCarrier, $idShop, $plan, (int) $languages['fallback'], $idEmployee);
        });
    }

    /**
     * Turns the arguments into what will change, or says everything that is wrong with them.
     *
     * Nothing is written here. A field equal to what the shop holds is left
     * out of the changes, so what remains is exactly what will change. Run
     * even when the carrier is unknown, so every problem is told at once.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $read the carrier as somcp_get_carrier answers it
     * @param int $idCarrier
     * @param int $idShop
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>
     */
    private static function plan(array $arguments, ?array $read, int $idCarrier, int $idShop, array $languages, array &$errors): array
    {
        $probe = new Carrier();
        $stored = $read === null ? null : self::stored($idCarrier, $idShop);
        $plan = [
            'changes' => [], 'set' => [], 'groups' => null, 'position' => null, 'tax' => null,
            'logo' => null, 'remove_logo' => false,
        ];

        $after = self::planCosts($arguments, $read, $stored, $idShop, $errors, $plan);
        if ($read !== null && $after !== null) {
            foreach (self::READ_BACK as $key) {
                if (!array_key_exists($key, $arguments)) {
                    continue;
                }
                // The keys that follow the billing, the handling costs or the
                // tax are compared with what the carrier shows once this call
                // is done, so a read taken before a change can come back with it.
                if (!self::same($arguments[$key], array_key_exists($key, $after) ? $after[$key] : ($read[$key] ?? null))) {
                    $errors[] = $key . ': changed by another tool, not this one - leave it out or give it as '
                        . 'somcp_get_carrier answers it';
                }
            }
        }

        if (array_key_exists('name', $arguments)) {
            $name = $arguments['name'];
            $size = SomcpTool::columnSize($probe, 'name', 64);
            if (!is_string($name) || trim($name) === '') {
                $errors[] = 'name: a text, ' . $size . ' characters at most';
            } elseif (Tools::strlen($name) > $size) {
                $errors[] = 'name: ' . $size . ' characters at most';
            } elseif (!Validate::isCarrierName($name)) {
                $errors[] = 'name: letters, digits and spaces, without < > ; = # { }';
            } elseif ($stored !== null && $name !== SomcpTool::carrierName($stored['name']) && $name !== $stored['name']) {
                $plan['changes'][] = ['field' => 'name', 'old' => SomcpTool::carrierName($stored['name']), 'new' => $name];
                $plan['set']['name'] = $name;
            }
        }

        if (array_key_exists('delay', $arguments)) {
            self::planDelay($arguments, $stored, $languages, $probe, $errors, $plan);
        }

        if (array_key_exists('grade', $arguments)) {
            $grade = $arguments['grade'];
            if (!is_int($grade) || $grade < 0 || $grade > 9) {
                $errors[] = 'grade: an integer from 0 to 9';
            } elseif ($stored !== null && $grade !== $stored['grade']) {
                $plan['changes'][] = ['field' => 'grade', 'old' => $stored['grade'], 'new' => $grade];
                $plan['set']['grade'] = $grade;
            }
        }

        if (array_key_exists('tracking_url', $arguments)) {
            $url = $arguments['tracking_url'];
            if (!is_string($url) || Tools::strlen($url) > self::URL_SIZE
                || ($url !== '' && !Validate::isAbsoluteUrl($url))) {
                $errors[] = 'tracking_url: an absolute URL of ' . self::URL_SIZE . ' characters at most, for '
                    . 'example https://carrier.example/track?n=@, or "" for none';
            } elseif ($stored !== null && $url !== $stored['url']) {
                $plan['changes'][] = ['field' => 'tracking_url', 'old' => $stored['url'], 'new' => $url];
                $plan['set']['url'] = $url;
            }
        }

        if (array_key_exists('active', $arguments)) {
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } elseif ($stored !== null && $arguments['active'] !== $stored['active']) {
                $plan['changes'][] = ['field' => 'active', 'old' => $stored['active'], 'new' => $arguments['active']];
                $plan['set']['active'] = $arguments['active'];
            }
        }

        if (array_key_exists('group_access', $arguments)) {
            $groups = self::groups($arguments['group_access'], $idShop, $errors);
            if ($groups !== null && $stored !== null && $groups !== $stored['groups']) {
                $plan['changes'][] = ['field' => 'group_access', 'old' => $stored['groups'], 'new' => $groups];
                $plan['groups'] = $groups;
            }
        }

        self::planLimits($arguments, $stored, $idShop, $errors, $plan);
        self::planLogo($arguments, $read, $idCarrier, $errors, $plan);

        if (array_key_exists('position', $arguments)) {
            // Carriers have one order for the whole installation, counted
            // among those not deleted, as Carrier::updatePosition() counts them.
            $last = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'carrier` WHERE `deleted` = 0'
            ) - 1;
            $position = SomcpToolCreateProduct::integer($arguments['position']);
            if ($position === null || $position < 0 || $position > $last) {
                $errors[] = 'position: a whole number from 0 to ' . $last . ', the place among the carriers';
            } elseif ($stored !== null && $position !== $stored['position']) {
                $plan['changes'][] = ['field' => 'position', 'old' => $stored['position'], 'new' => $position];
                $plan['position'] = $position;
            }
        }

        return $plan;
    }

    /**
     * Records the change of the Logo, under the names and rules of somcp_update_manufacturer.
     *
     * A download_ref carries its own expiry, so the one given back is not the
     * one a new read would answer: it is recognised by the file it names, and
     * is no change. An address cannot be compared with a stored file, so any
     * other logo given counts as a change; a removal only when there is a logo.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $read the carrier as somcp_get_carrier answers it
     * @param int $idCarrier
     * @param array<int, string> $errors
     * @param array<string, mixed> $plan
     *
     * @return void
     */
    private static function planLogo(array $arguments, ?array $read, int $idCarrier, array &$errors, array &$plan): void
    {
        if (array_key_exists('logo', $arguments)
            && !SomcpUpload::same($arguments['logo'], 'carrier_logo', (string) $idCarrier)) {
            $plan['logo'] = SomcpUpload::argument($arguments, 'logo', $errors, 'logo');
        }
        if (array_key_exists('remove_logo', $arguments)) {
            if (!is_bool($arguments['remove_logo'])) {
                $errors[] = 'remove_logo: true or false';
            } elseif ($arguments['remove_logo'] && array_key_exists('logo', $arguments)) {
                $errors[] = 'remove_logo: not with logo, which already replaces the current one';
            } else {
                $plan['remove_logo'] = $arguments['remove_logo'];
            }
        }

        if ($read === null) {
            return;
        }
        if ($plan['logo'] !== null) {
            $plan['changes'][] = ['field' => 'logo', 'old' => $read['logo'] ?? null, 'new' => SomcpUpload::origin($plan['logo'])];
        } elseif ($plan['remove_logo'] && ($read['logo'] ?? null) === null) {
            $plan['remove_logo'] = false;
        } elseif ($plan['remove_logo']) {
            $plan['changes'][] = ['field' => 'logo', 'old' => $read['logo'], 'new' => null];
        }
    }

    /**
     * Records the changes of Shipping locations and costs that are not
     * ranges, and answers the keys of the read that follow them as the
     * carrier will show them after the call.
     *
     * Free shipping and handling costs switch each other off as the 9.0.0
     * EditCarrierHandler does when only one is given; both given on is a
     * contradiction the assistant would report, so it is refused rather than
     * one of them silently winning.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $read
     * @param array<string, mixed>|null $stored from stored()
     * @param int $idShop
     * @param array<int, string> $errors
     * @param array<string, mixed> $plan
     *
     * @return array<string, mixed>|null the read-back keys after the call, null without a carrier
     */
    private static function planCosts(array $arguments, ?array $read, ?array $stored, int $idShop, array &$errors, array &$plan): ?array
    {
        $free = null;
        if (array_key_exists('is_free', $arguments)) {
            if (is_bool($arguments['is_free'])) {
                $free = $arguments['is_free'];
            } else {
                $errors[] = 'is_free: true or false';
            }
        }
        $handling = null;
        if (array_key_exists('handling_costs', $arguments)) {
            if (is_bool($arguments['handling_costs'])) {
                $handling = $arguments['handling_costs'];
            } else {
                $errors[] = 'handling_costs: true or false';
            }
        }
        if ($free === true && $handling === true) {
            $errors[] = 'handling_costs: a free carrier adds no handling costs - set is_free false in the same call';
            $free = $handling = null;
        }

        $method = null;
        if (array_key_exists('billing', $arguments)) {
            $given = $arguments['billing'];
            $known = array_search($given, self::BILLING, true);
            if ($known !== false) {
                $method = (int) $known;
            } elseif ($read === null || $given !== $read['billing']) {
                // null is the billing a free carrier is read with: passed back
                // as it is, it changes nothing.
                $errors[] = 'billing: price or weight';
            }
        }

        $tax = null;
        if (array_key_exists('id_tax_rules_group', $arguments)) {
            $value = $arguments['id_tax_rules_group'];
            $tax = $value === 0 || $value === '0' ? 0 : SomcpTool::id($arguments, 'id_tax_rules_group');
            if ($tax === 0 && $value !== 0 && $value !== '0') {
                $errors[] = 'id_tax_rules_group: 0 for No tax, or an id_tax_rules_group from somcp_list_tax_rules';
                $tax = null;
            } elseif ($tax > 0 && SomcpToolGetTaxRule::existingRule($tax, $idShop) === null) {
                $errors[] = SomcpTool::notFound('id_tax_rules_group', 'tax rule', $tax, 'somcp_list_tax_rules');
                $tax = null;
            }
        }

        $behavior = null;
        if (array_key_exists('out_of_range', $arguments)) {
            $known = array_search($arguments['out_of_range'], SomcpToolGetCarrier::OUT_OF_RANGE, true);
            if ($known === false) {
                $errors[] = 'out_of_range: highest_range or disable_carrier';
            } else {
                $behavior = (int) $known;
            }
        }

        if ($stored === null || $read === null) {
            return null;
        }

        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $finalFree = $free ?? ($handling === true ? false : $stored['is_free']);
        $finalHandling = $handling ?? ($free === true ? false : $stored['handling']);
        if ($finalFree !== $stored['is_free']) {
            $plan['changes'][] = ['field' => 'is_free', 'old' => $stored['is_free'], 'new' => $finalFree];
            $plan['set']['is_free'] = $finalFree;
        }
        if ($finalHandling !== $stored['handling']) {
            $plan['changes'][] = ['field' => 'handling_costs', 'old' => $stored['handling'], 'new' => $finalHandling];
            $plan['set']['shipping_handling'] = $finalHandling;
        }

        // Compared as the read answers it, the default method resolved, so a
        // carrier stored with 0 and given back its own billing keeps 0.
        $storedBilling = SomcpToolGetCarrier::method($stored['method'], $idShopGroup, $idShop);
        $finalBilling = $storedBilling;
        if ($method !== null && self::BILLING[$method] !== $storedBilling) {
            $finalBilling = self::BILLING[$method];
            $plan['changes'][] = ['field' => 'billing', 'old' => $storedBilling, 'new' => $finalBilling];
            $plan['set']['shipping_method'] = $method;
        }

        $finalTax = $stored['tax'];
        if ($tax !== null && $tax !== $stored['tax']) {
            $finalTax = $tax;
            $plan['changes'][] = ['field' => 'id_tax_rules_group', 'old' => $stored['tax'], 'new' => $tax];
            $plan['tax'] = $tax;
        }

        if ($behavior !== null && $behavior !== $stored['range_behavior']) {
            $plan['changes'][] = [
                'field' => 'out_of_range',
                'old' => SomcpToolGetCarrier::OUT_OF_RANGE[$stored['range_behavior']] ?? SomcpToolGetCarrier::OUT_OF_RANGE[0],
                'new' => SomcpToolGetCarrier::OUT_OF_RANGE[$behavior],
            ];
            $plan['set']['range_behavior'] = $behavior;
        }

        $billing = $finalFree ? null : $finalBilling;
        $warnings = [];
        $rule = $finalTax > 0 ? SomcpToolGetTaxRule::existingRule($finalTax, $idShop) : null;
        $after = [
            'ranges' => SomcpToolGetCarrier::ranges((int) $read['id_carrier'], $billing, $read['zones'], $idShopGroup, $idShop, $warnings),
            'range_unit' => SomcpToolGetCarrier::rangeUnit($billing, $idShopGroup, $idShop),
            'tax_rule' => $rule === null ? null : (string) $rule['name'],
            'handling_cost_tax_excl' => null,
        ];
        if ($finalHandling) {
            $after['handling_cost_tax_excl'] = (float) Configuration::get('PS_SHIPPING_HANDLING', null, $idShopGroup, $idShop);
        }

        return $after;
    }

    /**
     * Records the changes of the size and weight limits.
     *
     * Only a whole number is a size, as the help of both forms says, so 12.5
     * or "12" is refused rather than rounded. A negative weight is refused on
     * both versions, as the 9.0.0 form does: 8.0.1 would store it, and a
     * negative limit matches no cart, which hides the carrier at checkout.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $stored from stored()
     * @param int $idShop
     * @param array<int, string> $errors
     * @param array<string, mixed> $plan
     *
     * @return void
     */
    private static function planLimits(array $arguments, ?array $stored, int $idShop, array &$errors, array &$plan): void
    {
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $dimension = (string) Configuration::get('PS_DIMENSION_UNIT', null, $idShopGroup, $idShop);
        foreach (self::SIZES as $key) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            $size = $arguments[$key];
            if (!is_int($size) || $size < 0 || $size > self::SIZE_MAX) {
                $errors[] = $key . ': a whole number from 0, in ' . $dimension . ', 0 for no limit';
            } elseif ($stored !== null && $size !== $stored[$key]) {
                $plan['changes'][] = ['field' => $key, 'old' => $stored[$key], 'new' => $size];
                $plan['set'][$key] = $size;
            }
        }

        if (array_key_exists('max_weight', $arguments)) {
            $weight = SomcpToolCreateProduct::float($arguments['max_weight']);
            if ($weight === null || $weight < 0) {
                $errors[] = 'max_weight: a number from 0, in '
                    . (string) Configuration::get('PS_WEIGHT_UNIT', null, $idShopGroup, $idShop) . ', 0 for no limit';
            } elseif ($stored !== null && round($weight, 6) !== round($stored['max_weight'], 6)) {
                // Compared as the decimal(20,6) column stores it, so 5 and 5.000000 are one weight.
                $plan['changes'][] = ['field' => 'max_weight', 'old' => $stored['max_weight'], 'new' => $weight];
                $plan['set']['max_weight'] = $weight;
            }
        }
    }

    /**
     * Records the Transit time change per language, and the full set of texts to write.
     *
     * somcp_get_carrier answers the transit time as one text, in the language
     * it was read in. On a shop with several languages such a text names no
     * language: it is passed when it is the stored text of one of them, the
     * read given back as it is, as somcp_update_order_status does with its
     * name, and refused as ambiguous otherwise by the shared parser.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $stored from stored()
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param Carrier $probe
     * @param array<int, string> $errors
     * @param array<string, mixed> $plan
     *
     * @return void
     */
    private static function planDelay(array $arguments, ?array $stored, array $languages, Carrier $probe, array &$errors, array &$plan): void
    {
        if ($stored !== null && is_string($arguments['delay']) && count($languages['filled']) > 1
            && in_array($arguments['delay'], $stored['delay'], true)) {
            return;
        }
        $texts = SomcpToolCreateProduct::texts($arguments, 'delay', 'delay', $languages, $errors, true);
        if ($texts === null) {
            return;
        }
        $size = SomcpTool::columnSize($probe, 'delay', 512);
        $merged = $stored === null ? [] : $stored['delay'];
        $changed = false;
        foreach ($texts as $idLang => $text) {
            $iso = $languages['filled'][$idLang];
            if (trim($text) === '') {
                // Already said by texts(), which requires a text in every language filled.
                continue;
            }
            if (Tools::strlen($text) > $size) {
                $errors[] = 'delay (' . $iso . '): ' . $size . ' characters at most';
                continue;
            }
            if (!Validate::isGenericName($text)) {
                $errors[] = 'delay (' . $iso . '): a text without < > = { }';
                continue;
            }
            $current = (string) ($merged[$idLang] ?? '');
            if ($stored === null || $current === $text) {
                continue;
            }
            $merged[$idLang] = $text;
            $changed = true;
            $plan['changes'][] = ['field' => 'delay (' . $iso . ')', 'old' => $current, 'new' => $text];
        }
        if ($changed) {
            $plan['set']['delay'] = $merged;
        }
    }

    /**
     * The customer groups given, as sorted identifiers, or null when one is wrong.
     *
     * The full list after the change: either the objects somcp_get_carrier
     * answers, whose name is ignored, or bare identifiers.
     *
     * @param mixed $value
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<int, int>|null
     */
    private static function groups($value, int $idShop, array &$errors): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $errors[] = 'group_access: a list of id_group from somcp_list_customer_groups';

            return null;
        }
        if ($value === []) {
            $errors[] = 'group_access: at least one customer group';

            return null;
        }
        $known = [];
        foreach (Db::getInstance()->executeS(
            'SELECT `id_group` FROM `' . _DB_PREFIX_ . 'group_shop` WHERE `id_shop` = ' . $idShop
        ) ?: [] as $row) {
            $known[(int) $row['id_group']] = true;
        }

        $ids = [];
        $wrong = false;
        foreach ($value as $index => $group) {
            $id = SomcpTool::id(is_array($group) ? $group : ['id_group' => $group], 'id_group');
            if ($id === 0) {
                $errors[] = SomcpTool::notAnIdentifier('group_access[' . $index . ']');
                $wrong = true;
                continue;
            }
            if (!isset($known[$id])) {
                $errors[] = SomcpTool::notFound('group_access', 'customer group', $id, 'somcp_list_customer_groups');
                $wrong = true;
                continue;
            }
            $ids[$id] = $id;
        }
        if ($wrong) {
            return null;
        }
        $ids = array_values($ids);
        sort($ids);

        return $ids;
    }

    /**
     * What the shop stores for the fields this tool writes.
     *
     * @param int $idCarrier
     * @param int $idShop
     *
     * @return array{name: string, grade: int, url: string, active: bool, position: int, max_width: int, max_height: int, max_depth: int, max_weight: float, is_free: bool, handling: bool, method: int, range_behavior: int, tax: int, delay: array<int, string>, groups: array<int, int>, orders: int}
     */
    private static function stored(int $idCarrier, int $idShop): array
    {
        $db = Db::getInstance();
        $row = $db->getRow(
            'SELECT `name`, `grade`, `url`, `active`, `position`, `is_free`, `shipping_handling`,'
            . ' `shipping_method`, `range_behavior`, `max_width`, `max_height`, `max_depth`, `max_weight` FROM `' . _DB_PREFIX_ . 'carrier`'
            . ' WHERE `id_carrier` = ' . $idCarrier
        ) ?: [];

        // Every language of this shop, so a language not filled keeps its text when the rest is written.
        $delay = [];
        foreach ($db->executeS(
            'SELECT `id_lang`, `delay` FROM `' . _DB_PREFIX_ . 'carrier_lang`'
            . ' WHERE `id_carrier` = ' . $idCarrier . ' AND `id_shop` = ' . $idShop
        ) ?: [] as $line) {
            $delay[(int) $line['id_lang']] = (string) $line['delay'];
        }

        $groups = [];
        foreach ($db->executeS(
            'SELECT `id_group` FROM `' . _DB_PREFIX_ . 'carrier_group` WHERE `id_carrier` = ' . $idCarrier
            . ' ORDER BY `id_group` ASC'
        ) ?: [] as $line) {
            $groups[] = (int) $line['id_group'];
        }

        return [
            'name' => (string) ($row['name'] ?? ''),
            'grade' => (int) ($row['grade'] ?? 0),
            'url' => (string) ($row['url'] ?? ''),
            'active' => (bool) ($row['active'] ?? false),
            'position' => (int) ($row['position'] ?? 0),
            'max_width' => (int) ($row['max_width'] ?? 0),
            'max_height' => (int) ($row['max_height'] ?? 0),
            'max_depth' => (int) ($row['max_depth'] ?? 0),
            'max_weight' => (float) ($row['max_weight'] ?? 0),
            'is_free' => (bool) ($row['is_free'] ?? false),
            'handling' => (bool) ($row['shipping_handling'] ?? false),
            'method' => (int) ($row['shipping_method'] ?? 0),
            'range_behavior' => (int) ($row['range_behavior'] ?? 0),
            // Per shop, as the Tax of the form is the one of the shop the back office is in.
            'tax' => (int) $db->getValue(
                'SELECT `id_tax_rules_group` FROM `' . _DB_PREFIX_ . 'carrier_tax_rules_group_shop`'
                . ' WHERE `id_carrier` = ' . $idCarrier . ' AND `id_shop` = ' . $idShop
            ),
            'delay' => $delay,
            'groups' => $groups,
            'orders' => self::orders($idCarrier),
        ];
    }

    /**
     * The orders placed with the carrier, counted as the 9.0.0 core counts
     * them (CarrierRepository::getOrdersCount()) to decide on a new version.
     *
     * @param int $idCarrier
     *
     * @return int
     */
    private static function orders(int $idCarrier): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_carrier` WHERE `id_carrier` = ' . $idCarrier
        );
    }

    /**
     * Whether a value given back is the one the read answers.
     *
     * JSON does not keep 0 and 0.0 apart, so numbers are compared as numbers.
     *
     * @param mixed $given
     * @param mixed $read
     *
     * @return bool
     */
    private static function same($given, $read): bool
    {
        if ((is_int($given) || is_float($given)) && (is_int($read) || is_float($read))) {
            return (float) $given === (float) $read;
        }
        if (is_array($given) && is_array($read)) {
            if (count($given) !== count($read) || array_keys($given) !== array_keys($read)) {
                return false;
            }
            foreach ($read as $key => $value) {
                if (!self::same($given[$key], $value)) {
                    return false;
                }
            }

            return true;
        }

        return $given === $read;
    }

    /**
     * Writes the change, in place or on a new version, and answers with what was stored.
     *
     * @param int $idCarrier
     * @param int $idShop
     * @param array<string, mixed> $plan
     * @param int $idLang for the carrier read back
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(int $idCarrier, int $idShop, array $plan, int $idLang, int $idEmployee): array
    {
        $carrier = new Carrier($idCarrier);
        $oldGroups = array_map('intval', array_column($carrier->getGroups() ?: [], 'id_group'));
        $newId = $idCarrier;
        $replaced = false;

        // A move alone is not a change of the carrier: neither version of the
        // core makes a new version for it, so the orders keep it as it is.
        $edits = $plan['set'] !== [] || $plan['groups'] !== null || $plan['tax'] !== null
            || $plan['logo'] !== null || $plan['remove_logo'];

        try {
            if ($edits && self::orders($idCarrier) > 0) {
                // As 9.0.0 does in CarrierRepository::getEditableOrNewVersion():
                // the orders keep the old row, hidden, and the change goes on a
                // copy that takes over its ranges, zones, logo, tax and reference.
                $copy = $carrier->duplicateObject();
                if (!$copy instanceof Carrier) {
                    return self::failed($idCarrier, null, 'PrestaShop could not copy the carrier', $idEmployee);
                }
                $carrier->deleted = true;
                $carrier->id_shop_list = [$idShop];
                if (!$carrier->update()) {
                    $copy->delete();

                    return self::failed($idCarrier, null, 'PrestaShop refused to save it', $idEmployee);
                }
                $copy->copyCarrierData($idCarrier);
                $copy->setGroups($oldGroups);
                $newId = (int) $copy->id;
                $replaced = true;
                $carrier = new Carrier($newId);
            }

            if (array_key_exists('name', $plan['set'])) {
                $carrier->name = $plan['set']['name'];
            }
            if (array_key_exists('grade', $plan['set'])) {
                $carrier->grade = (int) $plan['set']['grade'];
            }
            if (array_key_exists('url', $plan['set'])) {
                $carrier->url = $plan['set']['url'];
            }
            if (array_key_exists('active', $plan['set'])) {
                $carrier->active = (bool) $plan['set']['active'];
            }
            if (array_key_exists('delay', $plan['set'])) {
                $carrier->delay = $plan['set']['delay'];
            }
            // The prices of the ranges are kept when the carrier turns free,
            // as 9.0.0 does, rather than deleted as the 8.0.1 wizard does: a
            // free carrier charges nothing whatever they hold, and deleting
            // them would lose amounts no tool gives back once it is paid again.
            if (array_key_exists('is_free', $plan['set'])) {
                $carrier->is_free = (bool) $plan['set']['is_free'];
            }
            if (array_key_exists('shipping_handling', $plan['set'])) {
                $carrier->shipping_handling = (bool) $plan['set']['shipping_handling'];
            }
            if (array_key_exists('shipping_method', $plan['set'])) {
                $carrier->shipping_method = (int) $plan['set']['shipping_method'];
            }
            foreach (self::SIZES as $key) {
                if (array_key_exists($key, $plan['set'])) {
                    $carrier->{$key} = (int) $plan['set'][$key];
                }
            }
            if (array_key_exists('max_weight', $plan['set'])) {
                // Through hydrate(): 8.0 declares the property an int while
                // the column and 9.0 keep a decimal weight, so 2.5 stays 2.5.
                // The id_lang it resets is already null: the carrier is
                // loaded without a language, every translation kept.
                $carrier->hydrate(['max_weight' => (float) $plan['set']['max_weight']]);
            }
            if (array_key_exists('range_behavior', $plan['set'])) {
                // Declared a boolean by the core: 1 is Disable carrier.
                $carrier->range_behavior = (int) $plan['set']['range_behavior'] === 1;
            }
            // The transit time is stored per shop: the shop of the token only,
            // the other shops keep their own text. update() also writes back
            // the shipping_method the constructor resolved from
            // PS_SHIPPING_METHOD when 0 was stored, as the form saves it: the
            // billing the merchant sees does not change.
            $carrier->id_shop_list = [$idShop];
            $done = !$edits || $carrier->update();
            if ($done && $plan['groups'] !== null) {
                $done = (bool) $carrier->setGroups($plan['groups']);
            }
            $failure = $done ? '' : 'PrestaShop refused to save it';
            if ($done && $plan['tax'] !== null) {
                // After the copy, which took over the old tax, so one call
                // makes one version. setTaxRulesGroup() writes the shops of
                // the context: the shop of the token, as the back office does
                // from a shop context.
                Somcp::actAsShop($idShop);
                $done = (bool) $carrier->setTaxRulesGroup((int) $plan['tax'], false);
                $failure = $done ? '' : 'the carrier was saved, but PrestaShop refused its tax';
            }
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        if (!$done) {
            return self::failed($idCarrier, $replaced ? $newId : null, $failure, $idEmployee);
        }

        // On the carrier now in use, after the new version: copyCarrierData()
        // has already given it the old logo, and the old version keeps its own
        // for the orders placed with it.
        $logo = null;
        if ($plan['remove_logo']) {
            $logo = self::removeLogo($newId);
        } elseif ($plan['logo'] !== null) {
            $logo = self::storeLogo($newId, $plan['logo']);
        }

        if ($plan['position'] !== null) {
            // Written last, on the carrier now in use: a new version stands
            // where the old one stood, then moves. Carrier::updatePosition()
            // finds the carrier from its own id, so no request value is needed.
            $failure = SomcpCatalog::movePosition($carrier, (int) $carrier->position, (int) $plan['position']);
            if ($failure !== '') {
                SomcpLogger::log(
                    'carrier_update_failed',
                    SomcpLogger::OUTCOME_FAILURE,
                    'carrier ' . $newId . ' position: ' . Tools::substr($failure, 0, 500),
                    '',
                    $idEmployee
                );

                return SomcpTool::text(
                    'The carrier could not be moved to position ' . (int) $plan['position'] . ': ' . $failure
                        . '. The other changes of this call may be saved. Read the carrier again with '
                        . 'somcp_get_carrier, tell the merchant what it holds now, and do not call again before.',
                    true
                );
            }
        }

        if ($replaced) {
            // What the 8.0.1 wizard fires on every save: shipping modules
            // follow their carrier across versions with it. Nothing of the
            // 9.0.0 core listens to it.
            Hook::exec('actionCarrierUpdate', ['id_carrier' => $idCarrier, 'carrier' => $carrier]);
        }
        SomcpLogger::log('carrier_updated', SomcpLogger::OUTCOME_SUCCESS, 'carrier ' . $newId, '', $idEmployee);

        $result = [
            'changed' => true,
            'id_carrier' => $newId,
        ];
        $warnings = [];
        if ($replaced) {
            $result['replaced_id_carrier'] = $idCarrier;
            $warnings[] = 'An order uses this carrier, so the shop keeps it for that order and gives the carrier '
                . 'a new identifier, ' . $newId . '. Use ' . $newId . ' from now on.';
        }
        $result['changes'] = $plan['changes'];
        if ($logo !== null) {
            $result['logo'] = $logo;
            if (isset($logo['refused'])) {
                $warnings[] = 'The other changes were saved, but the logo was not: ' . $logo['refused'] . '.';
            }
        }
        // Said under the key the tax tools use for the same fact (#314).
        $otherShops = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'carrier_shop` WHERE `id_carrier` = ' . $newId
            . ' AND `id_shop` <> ' . $idShop
        );
        if ($otherShops > 0) {
            $result['also_in_other_shops'] = $otherShops;
            $warnings[] = 'This carrier is also in ' . $otherShops . ' other shop(s): its name, Speed grade, '
                . 'Tracking URL, status, groups, Free shipping, handling costs, billing, Out-of-range '
                . 'behavior and size and weight limits change there too, as they do from the back office; the Transit time and the Tax '
                . 'were changed for this shop only.';
        }
        if ($plan['position'] !== null && SomcpToolCreateTax::otherShops() > 0) {
            $warnings[] = 'The place in the list of carriers is the same in every shop of this installation, so '
                . 'the new order shows in the other shops too.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        // Read back rather than echoed: what the assistant shows is what the
        // shop stored, under the new identifier for a carrier orders use.
        $result['carrier'] = SomcpToolGetCarrier::carrier($newId, $idShop, $idLang);

        return SomcpTool::json($result);
    }

    /**
     * A logo downloaded, checked and written where both versions of the core keep it.
     *
     * One JPEG at img/s/<id_carrier>.jpg, shared by every shop, with no image
     * type: what the 8.0.1 wizard makes from any image and 9.0.0 takes as it
     * is. Any image the module takes is turned into that JPEG on both, so a
     * PNG sent in the chat is not refused on 9.0.0 only. The file comes from
     * SomcpUpload::fetchImage(), as every other image does.
     *
     * @param int $idCarrier
     * @param array{url: string, upload_ref: string} $source
     *
     * @return array<string, mixed> the url or upload_ref, with stored: true or the reason it was refused
     */
    private static function storeLogo(int $idCarrier, array $source): array
    {
        $origin = SomcpUpload::origin($source);
        $file = tempnam(_PS_TMP_IMG_DIR_, 'somcp');
        if ($file === false) {
            return $origin + ['refused' => 'the shop has no room to receive it'];
        }

        try {
            $refusal = SomcpUpload::fetchImage($source, $file);
            if ($refusal === '') {
                // Forced to JPEG: the file is named .jpg whatever the image
                // quality setting of the shop would pick for a PNG.
                $stored = ImageManager::resize($file, _PS_SHIP_IMG_DIR_ . $idCarrier . '.jpg', null, null, 'jpg', true);
                if ($stored) {
                    self::dropListCopies($idCarrier);
                }
                $refusal = $stored ? '' : 'the shop could not read it as an image';
            }
        } catch (Throwable $e) {
            $refusal = 'the shop could not save it';
        } finally {
            @unlink($file);
        }

        return $origin + ($refusal === '' ? ['stored' => true] : ['refused' => $refusal]);
    }

    /**
     * Removes the logo as the 9.0.0 CarrierLogoFileUploader::deleteOldFile() does.
     *
     * @param int $idCarrier
     *
     * @return array<string, mixed>
     */
    private static function removeLogo(int $idCarrier): array
    {
        $file = _PS_SHIP_IMG_DIR_ . $idCarrier . '.jpg';
        if (is_file($file) && !@unlink($file)) {
            return ['refused' => 'the shop could not delete its file'];
        }
        self::dropListCopies($idCarrier);

        return ['removed' => true];
    }

    /**
     * Deletes the small copies of a logo the back office list keeps in the
     * image cache, so the list shows the new one: carrier_mini_<id>.jpg, and
     * carrier_mini_<id>_<id_shop>.jpg per shop, which HelperList names so.
     *
     * @param int $idCarrier
     *
     * @return void
     */
    private static function dropListCopies(int $idCarrier): void
    {
        $copies = glob(_PS_TMP_IMG_DIR_ . 'carrier_mini_' . $idCarrier . '_*.jpg') ?: [];
        $copies[] = _PS_TMP_IMG_DIR_ . 'carrier_mini_' . $idCarrier . '.jpg';
        foreach ($copies as $copy) {
            if (is_file($copy)) {
                @unlink($copy);
            }
        }
    }

    /**
     * The answer to a save that failed, naming both identifiers once a new version exists.
     *
     * @param int $idCarrier
     * @param int|null $newId
     * @param string $failure
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function failed(int $idCarrier, ?int $newId, string $failure, int $idEmployee): array
    {
        SomcpLogger::log(
            'carrier_update_failed',
            SomcpLogger::OUTCOME_FAILURE,
            'carrier ' . $idCarrier . ($newId === null ? '' : ' copied to carrier ' . $newId),
            '',
            $idEmployee
        );
        if ($newId !== null) {
            return SomcpTool::text(
                'The carrier was copied to a new identifier, ' . $newId . ', and the old one, ' . $idCarrier
                    . ', is now hidden, but PrestaShop reported a failure: ' . $failure
                    . '. Read the carrier ' . $newId . ' with somcp_get_carrier before calling again.',
                true
            );
        }

        return SomcpTool::text(
            'The carrier could not be changed: ' . $failure . '. Read it with somcp_get_carrier before calling again.',
            true
        );
    }
}
