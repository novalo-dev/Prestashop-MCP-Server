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
 * somcp_get_carrier - one carrier of Shipping > Carriers as its edit page
 * shows it: the zones and customer groups it serves, its tax, its handling
 * costs, how its costs are billed, its ranges and the price of each per
 * zone, and its size and weight limits.
 *
 * somcp_list_carriers stops at what the list shows, so an assistant setting
 * up the delivery of a shop could not tell where a carrier delivers nor to
 * whom, nor what a customer pays there. The columns both tools give come
 * from the list's own query, so the two never answer differently for one
 * carrier; carrier() is public for the reads and writes of a carrier that
 * answer with it.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetCarrier
{
    /**
     * @var array<int, string> the choices of Out-of-range behavior, by the value stored in carrier.range_behavior
     */
    public const OUT_OF_RANGE = [
        0 => 'highest_range',
        1 => 'disable_carrier',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_carrier',
            Somcp::t('Read a carrier'),
            'Reads one carrier of Shipping > Carriers as its edit page shows it: name, Transit time, Speed '
                . 'grade, logo, Tracking URL, Group access, Zones, Free shipping, Tax, handling costs, billing '
                . 'by price or weight, the ranges with the price of each in every zone, Out-of-range behavior '
                . 'and the size and weight limits. Take id_carrier '
                . 'from somcp_list_carriers, which lists them all; for the carrier of one order use '
                . 'somcp_get_order. The ranges are not what one cart pays, the shop adding handling costs, '
                . 'free shipping thresholds and the carrier tax.',
            [
                'type' => 'object',
                'properties' => [
                    'id_carrier' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the carrier, from somcp_list_carriers.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, '
                            . 'for the transit time and the group names; a code the shop does not have is '
                            . 'refused. The shop default language when left out.',
                    ],
                ],
                'required' => ['id_carrier'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_READ],
            [self::class, 'handle'],
            // The same page and the same right as somcp_list_carriers: the
            // edit page a merchant would open to look at one carrier.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminCarriers')
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
        $language = SomcpTool::readLanguage($arguments, $idShop);

        // A cast would read an array as 1 and answer about a carrier nobody
        // asked for: only a whole number is an identifier.
        $idCarrier = SomcpTool::id($arguments, 'id_carrier');
        $errors = SomcpTool::unknownFields($arguments, ['id_carrier', 'language']);
        // Listed with the call's other mistakes, so it is corrected in one go.
        if ($language === null) {
            $errors[] = SomcpTool::languageProblem($idShop);
        }
        $carrier = null;
        if ($idCarrier === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_carrier');
        } else {
            $carrier = self::carrier($idCarrier, $idShop, $language === null ? 0 : $language[0]);
            if ($carrier === null) {
                $errors[] = self::notFound($idCarrier, $idShop);
            }
        }
        if ($errors !== [] || $carrier === null || $language === null) {
            return SomcpTool::refused('read', $errors);
        }
        $carrier['language'] = $language[1];

        return SomcpTool::json($carrier);
    }

    /**
     * One carrier as this tool answers it, without its language, or null
     * when the shop of the token has no such carrier, or only an older
     * version of it.
     *
     * @param int $idCarrier
     * @param int $idShop
     * @param int $idLang for the transit time and the group names
     *
     * @return array<string, mixed>|null
     */
    public static function carrier(int $idCarrier, int $idShop, int $idLang): ?array
    {
        $query = SomcpToolListCarriers::query($idShop, $idLang);
        $query->select(
            'c.`grade`, c.`shipping_handling`, c.`shipping_method`, c.`range_behavior`, '
                . 'c.`max_width`, c.`max_height`, c.`max_depth`, c.`max_weight`'
        );
        $query->where('c.`id_carrier` = ' . $idCarrier);
        $row = Db::getInstance()->getRow($query);
        if (!is_array($row) || $row === []) {
            return null;
        }

        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $carrier = SomcpToolListCarriers::entry($row, $idShop);
        $warnings = [];

        $carrier['grade'] = (int) $row['grade'];
        $carrier['group_access'] = self::groups($idCarrier, $idShop, $idLang);
        $carrier['zones'] = self::zones($idCarrier);

        // Stored per shop: the Tax of the form is the one of the shop the
        // back office is in, which is the shop of the token here.
        $idTaxRule = (int) Db::getInstance()->getValue(
            'SELECT `id_tax_rules_group` FROM `' . _DB_PREFIX_ . 'carrier_tax_rules_group_shop`'
            . ' WHERE `id_carrier` = ' . $idCarrier . ' AND `id_shop` = ' . $idShop
        );
        $rule = $idTaxRule > 0 ? SomcpToolGetTaxRule::existingRule($idTaxRule, $idShop) : null;
        $carrier['id_tax_rules_group'] = $idTaxRule;
        $carrier['tax_rule'] = $rule === null ? null : (string) $rule['name'];
        if ($idTaxRule > 0 && $rule === null) {
            $warnings[] = 'The tax rule ' . $idTaxRule . ' of this carrier was deleted since, so its shipping '
                . 'costs are charged without tax until another is chosen.';
        }

        $carrier['handling_costs'] = (bool) $row['shipping_handling'];
        if ($carrier['handling_costs']) {
            // Set once for the shop on Shipping > Preferences, not on the
            // carrier: the amount every carrier with handling costs adds.
            $carrier['handling_cost_tax_excl'] = (float) Configuration::get('PS_SHIPPING_HANDLING', null, $idShopGroup, $idShop);
        }

        $carrier['billing'] = self::billing($row, $idShopGroup, $idShop);
        $carrier['out_of_range'] = self::OUT_OF_RANGE[(int) $row['range_behavior']] ?? self::OUT_OF_RANGE[0];
        $carrier['ranges'] = self::ranges($idCarrier, $carrier['billing'], $carrier['zones'], $idShopGroup, $idShop, $warnings);
        $carrier['range_unit'] = self::rangeUnit($carrier['billing'], $idShopGroup, $idShop);
        // 0 is no limit, as the form shows it.
        $carrier['max_width'] = (int) $row['max_width'];
        $carrier['max_height'] = (int) $row['max_height'];
        $carrier['max_depth'] = (int) $row['max_depth'];
        $carrier['max_weight'] = (float) $row['max_weight'];
        $carrier['dimension_unit'] = (string) Configuration::get('PS_DIMENSION_UNIT', null, $idShopGroup, $idShop);
        $carrier['weight_unit'] = (string) Configuration::get('PS_WEIGHT_UNIT', null, $idShopGroup, $idShop);

        if ($carrier['external_module'] !== null) {
            $warnings[] = 'The shipping costs of this carrier are computed by the module '
                . $carrier['external_module'] . ', not by its ranges.';
        }
        if ($warnings !== []) {
            $carrier['warning'] = implode(' ', $warnings);
        }

        return $carrier;
    }

    /**
     * How the costs of the carrier are billed, as the form shows it.
     *
     * The same decision as Carrier::getShippingMethod(), read without
     * loading the object: a carrier stored with the default method follows
     * the shop's PS_SHIPPING_METHOD, and a free one has no billing at all.
     *
     * @param array<string, mixed> $row
     * @param int $idShopGroup
     * @param int $idShop
     *
     * @return string|null
     */
    private static function billing(array $row, int $idShopGroup, int $idShop): ?string
    {
        if ((bool) $row['is_free']) {
            return null;
        }

        return self::method((int) $row['shipping_method'], $idShopGroup, $idShop);
    }

    /**
     * The billing a stored shipping_method stands for, whether the carrier is free or not.
     *
     * Public so somcp_update_carrier compares a billing given with the one
     * this read answers, the default method resolved the same way.
     *
     * @param int $method carrier.shipping_method
     * @param int $idShopGroup
     * @param int $idShop
     *
     * @return string|null "price", "weight", or null for a method neither
     */
    public static function method(int $method, int $idShopGroup, int $idShop): ?string
    {
        if ($method === Carrier::SHIPPING_METHOD_DEFAULT) {
            $method = (int) Configuration::get('PS_SHIPPING_METHOD', null, $idShopGroup, $idShop)
                ? Carrier::SHIPPING_METHOD_WEIGHT
                : Carrier::SHIPPING_METHOD_PRICE;
        }
        if ($method === Carrier::SHIPPING_METHOD_WEIGHT) {
            return 'weight';
        }

        return $method === Carrier::SHIPPING_METHOD_PRICE ? 'price' : null;
    }

    /**
     * The unit of the bounds of the ranges: the default currency when billed
     * by price, the weight unit when billed by weight, none for a free carrier.
     *
     * @param string|null $billing from billing()
     * @param int $idShopGroup
     * @param int $idShop
     *
     * @return string|null
     */
    public static function rangeUnit(?string $billing, int $idShopGroup, int $idShop): ?string
    {
        if ($billing === 'price') {
            return (string) Currency::getIsoCodeById((int) Configuration::get('PS_CURRENCY_DEFAULT', null, $idShopGroup, $idShop));
        }
        if ($billing === 'weight') {
            return (string) Configuration::get('PS_WEIGHT_UNIT', null, $idShopGroup, $idShop);
        }

        return null;
    }

    /**
     * The ranges of the carrier and the price of each in every zone it
     * serves, as the ranges table of Shipping locations and costs shows them.
     *
     * Only the ranges of the kind the carrier is billed by: a carrier can
     * keep rows of the other kind from an earlier setting, which the form
     * does not show and the core never charges, so giving them would tell
     * the merchant a price no customer pays. A free carrier has none.
     * Public so somcp_update_carrier compares the ranges given back with the
     * ones the carrier shows once its billing has changed.
     *
     * @param int $idCarrier
     * @param string|null $billing "price", "weight" or null, from billing()
     * @param array<int, array{id_zone: int, name: string}> $zones from zones()
     * @param int $idShopGroup
     * @param int $idShop
     * @param array<int, string> $warnings completed with the zones a range has no price for
     *
     * @return array<int, array{from: float, to: float, prices: array<int, array{id_zone: int, zone: string, price_tax_excl: float|null}>}>
     */
    public static function ranges(int $idCarrier, ?string $billing, array $zones, int $idShopGroup, int $idShop, array &$warnings): array
    {
        if ($billing !== 'price' && $billing !== 'weight') {
            return [];
        }
        $table = $billing === 'price' ? 'range_price' : 'range_weight';
        $column = 'id_' . $table;

        $query = new DbQuery();
        $query->select('r.`' . $column . '` AS `id_range`, r.`delimiter1`, r.`delimiter2`');
        $query->from($table, 'r');
        $query->where('r.`id_carrier` = ' . $idCarrier);
        $query->orderBy('r.`delimiter1` ASC, r.`' . $column . '` ASC');
        $rows = Db::getInstance()->executeS($query) ?: [];
        if ($rows === []) {
            return [];
        }

        // The rows the core may charge in this shop: its own, then its
        // group's, then the global one, the order in which
        // Carrier::sqlDeliveryRangeShop() picks a single row per range and zone.
        $delivery = Db::getInstance()->executeS(
            'SELECT `' . $column . '` AS `id_range`, `id_zone`, `price`, `id_shop`, `id_shop_group`'
            . ' FROM `' . _DB_PREFIX_ . 'delivery`'
            . ' WHERE `id_carrier` = ' . $idCarrier . ' AND `' . $column . '` > 0'
            . ' AND (`id_shop` = ' . $idShop
            . ' OR (`id_shop_group` = ' . $idShopGroup . ' AND (`id_shop` IS NULL OR `id_shop` = 0))'
            . ' OR ((`id_shop_group` IS NULL OR `id_shop_group` = 0) AND (`id_shop` IS NULL OR `id_shop` = 0)))'
            . ' ORDER BY `id_shop` DESC, `id_shop_group` DESC'
        ) ?: [];
        $prices = [];
        foreach ($delivery as $line) {
            $key = (int) $line['id_range'] . ':' . (int) $line['id_zone'];
            if (!isset($prices[$key])) {
                $prices[$key] = (float) $line['price'];
            }
        }

        $ranges = [];
        $unpriced = [];
        foreach ($rows as $row) {
            $range = ['from' => (float) $row['delimiter1'], 'to' => (float) $row['delimiter2'], 'prices' => []];
            foreach ($zones as $zone) {
                $key = (int) $row['id_range'] . ':' . $zone['id_zone'];
                $price = $prices[$key] ?? null;
                if ($price === null) {
                    $unpriced[$zone['id_zone']] = $zone['name'];
                }
                $range['prices'][] = ['id_zone' => $zone['id_zone'], 'zone' => $zone['name'], 'price_tax_excl' => $price];
            }
            $ranges[] = $range;
        }
        if ($unpriced !== []) {
            // The core only offers a carrier where every range it needs has
            // a price, so a missing one hides the carrier in that zone.
            $warnings[] = 'Some ranges have no price in the zones ' . implode(', ', $unpriced)
                . ', so the shop does not offer this carrier there for those amounts.';
        }

        return $ranges;
    }

    /**
     * The customer groups allowed to use the carrier, among the groups of
     * the shop, as somcp_list_customer_groups gives them.
     *
     * @param int $idCarrier
     * @param int $idShop
     * @param int $idLang
     *
     * @return array<int, array{id_group: int, name: string}>
     */
    private static function groups(int $idCarrier, int $idShop, int $idLang): array
    {
        $query = new DbQuery();
        $query->select('cg.`id_group`, gl.`name`');
        $query->from('carrier_group', 'cg');
        $query->innerJoin('group_shop', 'gs', 'gs.`id_group` = cg.`id_group` AND gs.`id_shop` = ' . $idShop);
        $query->leftJoin('group_lang', 'gl', 'gl.`id_group` = cg.`id_group` AND gl.`id_lang` = ' . $idLang);
        $query->where('cg.`id_carrier` = ' . $idCarrier);
        $query->orderBy('cg.`id_group` ASC');

        $groups = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $groups[] = ['id_group' => (int) $row['id_group'], 'name' => (string) $row['name']];
        }

        return $groups;
    }

    /**
     * The zones the carrier delivers to, sorted by name as the form sorts them.
     *
     * @param int $idCarrier
     *
     * @return array<int, array{id_zone: int, name: string}>
     */
    private static function zones(int $idCarrier): array
    {
        $query = new DbQuery();
        $query->select('z.`id_zone`, z.`name`');
        $query->from('carrier_zone', 'cz');
        $query->innerJoin('zone', 'z', 'z.`id_zone` = cz.`id_zone`');
        $query->where('cz.`id_carrier` = ' . $idCarrier);
        $query->orderBy('z.`name` ASC, z.`id_zone` ASC');

        $zones = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $zones[] = ['id_zone' => (int) $row['id_zone'], 'name' => (string) $row['name']];
        }

        return $zones;
    }

    /**
     * The line for a carrier the shop does not answer.
     *
     * An edit gives the carrier a new identifier and keeps the old row,
     * flagged: an identifier copied from an old order is one of those, and
     * the reference leads to the carrier as it is now. Public so the carrier
     * writes refuse an old version with the same line.
     *
     * @param int $idCarrier
     * @param int $idShop
     *
     * @return string
     */
    public static function notFound(int $idCarrier, int $idShop): string
    {
        $line = SomcpTool::notFound('id_carrier', 'carrier', $idCarrier, 'somcp_list_carriers');
        $idReference = (int) Db::getInstance()->getValue(
            'SELECT c.`id_reference` FROM `' . _DB_PREFIX_ . 'carrier` c'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'carrier_shop` cs'
            . ' ON cs.`id_carrier` = c.`id_carrier` AND cs.`id_shop` = ' . $idShop
            . ' WHERE c.`id_carrier` = ' . $idCarrier . ' AND c.`deleted` = 1'
        );

        return $idReference > 0
            ? $line . ', by the id_reference ' . $idReference . ': this identifier is an older version of that carrier'
            : $line;
    }
}
