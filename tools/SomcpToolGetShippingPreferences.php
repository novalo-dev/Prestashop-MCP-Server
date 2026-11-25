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
 * somcp_get_shipping_preferences - the Handling and Carrier options cards of
 * Shipping > Preferences.
 *
 * These settings decide what every order pays on top of the carriers' ranges
 * and which carrier a customer finds selected; without this read an
 * assistant only knew the handling charges, through one carrier. Read by
 * their configuration keys for the shop of the token, as the core's
 * HandlingConfiguration and CarrierOptionsConfiguration read them.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// default carrier is found and named as the carrier list finds and names it.
require_once __DIR__ . '/SomcpToolListCarriers.php';

class SomcpToolGetShippingPreferences
{
    /** @var array<int, string> The two choices of Default carrier that are not a carrier. */
    public const DEFAULT_CHOICES = [-1 => 'best_price', -2 => 'best_grade'];

    /** @var array<int, string> Sort by, as stored. */
    public const SORTS = [0 => 'price', 1 => 'position'];

    /** @var array<int, string> Order by, as stored. */
    public const ORDERS = [0 => 'ascending', 1 => 'descending'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_shipping_preferences',
            Somcp::t('Read the shipping preferences'),
            'Reads Shipping > Preferences: Handling charges, Free shipping starts at a price and at a weight (0 '
                . 'means off), the Default carrier (best price, best grade or one carrier, with its id_carrier from '
                . 'somcp_list_carriers), and how carriers are sorted on the storefront. Read only. For the ranges '
                . 'and costs of one carrier use somcp_get_carrier; for the free shipping of a cart rule, '
                . 'somcp_get_cart_rule; to change them, somcp_update_shipping_preferences.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, for the '
                            . 'transit time of the default carrier. The shop default language when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_READ],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminShipping')
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
        $language = SomcpTool::readLanguage($arguments, $idShop);

        // Every problem in one answer (#169), so the call is corrected in one go.
        $problems = SomcpTool::unknownFields($arguments, ['language']);
        if ($language === null) {
            $problems[] = SomcpTool::languageProblem($idShop);
        }
        if ($problems !== [] || $language === null) {
            return SomcpTool::refused('read', $problems);
        }

        $result = self::preferences($idShop, $language[0]);
        if ($result['default_carrier'] === 'carrier' && $result['default_id_carrier'] === null) {
            $result['warning'] = 'The default carrier no longer exists: customers get the first carrier available; '
                . 'choose another in Shipping > Preferences.';
        }
        $result['language'] = $language[1];

        return SomcpTool::json($result);
    }

    /**
     * The shipping preferences of a shop, under the names the update takes back.
     *
     * Public so a tool that changes them compares with, and answers with,
     * this very reading rather than a second shape that could drift.
     *
     * @param int $idShop
     * @param int $idLang the language of the transit time of the default carrier
     *
     * @return array<string, mixed>
     */
    public static function preferences(int $idShop, int $idLang): array
    {
        // The group of the shop too, so a value set for the group of shops is
        // read as the storefront of that shop applies it.
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $get = static function (string $key) use ($idShopGroup, $idShop) {
            return Configuration::get($key, null, $idShopGroup, $idShop);
        };
        $idCurrency = (int) $get('PS_CURRENCY_DEFAULT');

        $stored = (int) $get('PS_CARRIER_DEFAULT');
        $carrier = null;
        if (!isset(self::DEFAULT_CHOICES[$stored]) && $stored > 0) {
            $carrier = self::carrierOfReference($stored, $idShop, $idLang);
        }

        return [
            // The name somcp_get_carrier already gives it.
            'handling_cost_tax_excl' => (float) $get('PS_SHIPPING_HANDLING'),
            'free_shipping_from_price' => (float) $get('PS_SHIPPING_FREE_PRICE'),
            'free_shipping_from_weight' => (float) $get('PS_SHIPPING_FREE_WEIGHT'),
            'currency' => (string) Currency::getIsoCodeById($idCurrency),
            'weight_unit' => (string) $get('PS_WEIGHT_UNIT'),
            'default_carrier' => self::DEFAULT_CHOICES[$stored] ?? 'carrier',
            'default_id_carrier' => $carrier !== null ? $carrier['id_carrier'] : null,
            'default_carrier_name' => $carrier !== null ? $carrier['name'] : null,
            'default_carrier_delay' => $carrier !== null ? $carrier['delay'] : null,
            'carrier_sort' => self::SORTS[(int) $get('PS_CARRIER_DEFAULT_SORT')] ?? self::SORTS[0],
            'carrier_order' => self::ORDERS[(int) $get('PS_CARRIER_DEFAULT_ORDER')] ?? self::ORDERS[0],
        ];
    }

    /**
     * The current version of a carrier of the shop by its reference, as the list answers it, or null.
     *
     * Default carrier stores the reference (CarrierByReferenceChoiceProvider):
     * an edited carrier gets a new id_carrier under the same reference, and
     * the identifier given is the one somcp_list_carriers lists today.
     *
     * @param int $idReference
     * @param int $idShop
     * @param int $idLang
     *
     * @return array<string, mixed>|null
     */
    public static function carrierOfReference(int $idReference, int $idShop, int $idLang): ?array
    {
        $query = SomcpToolListCarriers::query($idShop, $idLang);
        $query->where('c.`id_reference` = ' . (int) $idReference);
        $query->orderBy('c.`id_carrier` DESC');
        $row = Db::getInstance()->getRow($query);

        return is_array($row) && $row !== [] ? SomcpToolListCarriers::entry($row, $idShop) : null;
    }
}
