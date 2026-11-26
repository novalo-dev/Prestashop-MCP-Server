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
 * somcp_get_product_settings - the four cards of Shop Parameters > Product
 * Settings: General product settings, Product page, Products stock and
 * Pagination.
 *
 * Catalog mode, stock management and out-of-stock ordering decide whether a
 * visitor can buy at all, so the assistant reads them before explaining an
 * empty cart. The keys are those the core's GeneralConfiguration,
 * PageConfiguration, StockConfiguration and PaginationConfiguration read, for
 * the shop of the token.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetProductSettings
{
    /** @var array<int, string> PS_PRODUCTS_ORDER_BY, the values of the Default order by select. */
    public const ORDER_BY = [0 => 'name', 1 => 'price', 2 => 'date_add', 3 => 'date_upd', 4 => 'position', 5 => 'brand', 6 => 'quantity', 7 => 'reference', 8 => 'sales'];

    /** @var array<string, string> PS_SPECIFIC_PRICE_PRIORITIES, its stored columns to the words of the four selects. */
    public const PRIORITIES = ['id_shop' => 'store', 'id_currency' => 'currency', 'id_country' => 'country', 'id_group' => 'group'];

    /** @var array<int, string> The order PriorityList::AVAILABLE_PRIORITIES gives when nothing is stored, a class 8.0 lacks. */
    private const DEFAULT_PRIORITIES = ['id_group', 'id_currency', 'id_country', 'id_shop'];

    /** @var array<string, string> The five texts of Products stock, their names to their configuration keys. */
    public const LABELS = [
        'label_in_stock' => 'PS_LABEL_IN_STOCK_PRODUCTS',
        'label_backorders_allowed' => 'PS_LABEL_OOS_PRODUCTS_BOA',
        'label_backorders_denied' => 'PS_LABEL_OOS_PRODUCTS_BOD',
        'delivery_time_in_stock' => 'PS_LABEL_DELIVERY_TIME_AVAILABLE',
        'delivery_time_backorders' => 'PS_LABEL_DELIVERY_TIME_OOSBOA',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_product_settings',
            Somcp::t('Read the product settings'),
            'Reads Shop Parameters > Product Settings: catalog mode, new product days, short description size, '
                . 'specific price priorities, the product page options, stock management, out-of-stock ordering and '
                . 'its labels per language, pack stock, and products per page with their order. Read only; change '
                . 'them with somcp_update_product_settings. For the stock or the out-of-stock behavior of one product use somcp_get_stock or somcp_get_product.',
            [
                'type' => 'object',
                'properties' => [
                    'languages' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'ISO codes among the shop\'s active languages for the stock labels, for '
                            . 'example ["en", "fr"]; every active language when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminPPreferences')
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

        // Every problem in one answer (#169), the languages by the rule of #149.
        $problems = SomcpTool::unknownFields($arguments, ['languages']);
        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $problems);
        if ($problems !== [] || $languages === null) {
            return SomcpTool::refused('read', $problems);
        }

        $result = self::settings($idShop, $languages['filled']);
        $warnings = [];
        if ($result['catalog_mode']) {
            $warnings[] = 'Catalog mode is on: visitors can browse the products but cannot add them to the cart.';
        }
        if (!$result['stock_management']) {
            $warnings[] = 'Stock management is off: quantities are not counted and every product can be ordered.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }

    /**
     * The product settings of a shop, under the names the update takes back.
     *
     * Public so the tool that changes them compares with, and answers with,
     * this very reading rather than a second shape that could drift.
     *
     * @param int $idShop
     * @param array<int, string> $idLangs ISO codes keyed by id_lang, the languages of the texts
     *
     * @return array<string, mixed>
     */
    public static function settings(int $idShop, array $idLangs): array
    {
        // The group of the shop too, so a value set for the group of shops is
        // read as the shop applies it.
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $get = static function (string $key, ?int $idLang = null) use ($idShopGroup, $idShop) {
            return Configuration::get($key, $idLang, $idShopGroup, $idShop);
        };
        // Each a field of the screen from the version given only: before it,
        // null says there is nothing to read rather than a value never shown.
        $from = static function (string $version, callable $read) {
            return version_compare(_PS_VERSION_, $version, '>=') ? $read() : null;
        };

        $stored = (string) $get('PS_SPECIFIC_PRICE_PRIORITIES');
        $priorities = [];
        foreach ($stored !== '' ? explode(';', $stored) : self::DEFAULT_PRIORITIES as $column) {
            $priorities[] = self::PRIORITIES[$column] ?? $column;
        }

        $result = [
            // A key with no row, as on a fresh shop, reads as the core's
            // adapters read it: false, and 0 or empty.
            'catalog_mode' => (bool) $get('PS_CATALOG_MODE'),
            'catalog_mode_show_prices' => (bool) $get('PS_CATALOG_MODE_WITH_PRICES'),
            'new_product_days' => (int) $get('PS_NB_DAYS_NEW_PRODUCT'),
            'short_description_limit' => (int) $get('PS_PRODUCT_SHORT_DESC_LIMIT'),
            'quantity_discounts_based_on' => (int) $get('PS_QTY_DISCOUNT_ON_COMBINATION') === 1 ? 'combinations' : 'products',
            'force_friendly_url' => (bool) $get('PS_FORCE_FRIENDLY_PRODUCT'),
            'breadcrumb_category' => $from('9.0.0', static function () use ($get): string {
                return (string) $get('PS_PRODUCT_BREADCRUMB_CATEGORY');
            }),
            'activate_new_products' => (bool) $get('PS_PRODUCT_ACTIVATION_DEFAULT'),
            'specific_price_priorities' => $priorities,
            'disabled_product_behavior' => $from('8.1.0', static function () use ($get): string {
                return (string) $get('PS_PRODUCT_REDIRECTION_DEFAULT');
            }),
            'display_available_quantities' => (bool) $get('PS_DISPLAY_QTIES'),
            'add_to_cart_with_combinations' => (bool) $get('PS_ATTRIBUTE_CATEGORY_DISPLAY'),
            'combination_image_in_listings' => $from('9.0.0', static function () use ($get): bool {
                return (bool) $get('PS_USE_COMBINATION_IMAGE_IN_LISTING');
            }),
            'attribute_anchor_separator' => (string) $get('PS_ATTRIBUTE_ANCHOR_SEPARATOR'),
            'display_discounted_unit_price' => (bool) $get('PS_DISPLAY_DISCOUNT_PRICE'),
            'notify_product_in_cart' => $from('9.0.0', static function () use ($get): bool {
                return (bool) $get('PS_DISPLAY_AMOUNT_IN_CART');
            }),
            'feature_values_order' => $from('9.0.0', static function () use ($get): string {
                return (string) $get('PS_FEATURE_VALUES_ORDER') === 'position' ? 'position' : 'alphabetical';
            }),
            'stock_management' => (bool) $get('PS_STOCK_MANAGEMENT'),
            'pack_stock_type' => SomcpToolGetProduct::PACK_STOCK_TYPES[(int) $get('PS_PACK_STOCK_TYPE')] ?? 'pack',
            'display_unavailable_attributes' => (bool) $get('PS_DISP_UNAVAILABLE_ATTR'),
            'remaining_quantities_below' => (int) $get('PS_LAST_QTIES'),
            'allow_out_of_stock_ordering' => (bool) $get('PS_ORDER_OUT_OF_STOCK'),
        ];

        // Each language read from configuration_lang, as the page shows it:
        // the value without a language, which a fresh shop also holds, is
        // never displayed there.
        foreach (self::LABELS as $name => $key) {
            $texts = [];
            foreach ($idLangs as $idLang => $iso) {
                $texts[$iso] = (string) $get($key, (int) $idLang);
            }
            // An object even when empty, so the answer keeps one shape.
            $result[$name] = $texts === [] ? new stdClass() : $texts;
        }

        $result['out_of_stock_label_in_listings'] = (bool) $get('PS_SHOW_LABEL_OOS_LISTING_PAGES');
        $result['products_per_page'] = (int) $get('PS_PRODUCTS_PER_PAGE');
        $result['default_order_by'] = self::ORDER_BY[(int) $get('PS_PRODUCTS_ORDER_BY')] ?? 'position';
        $result['default_order_way'] = (int) $get('PS_PRODUCTS_ORDER_WAY') === 1 ? 'desc' : 'asc';
        $result['languages'] = array_values($idLangs);

        return $result;
    }
}
