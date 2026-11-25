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
 * somcp_update_product_settings - the Save buttons of the four cards of Shop
 * Parameters > Product Settings: General product settings, Product page,
 * Products stock and Pagination.
 *
 * Saved by their configuration keys for the shop of the token, as the core's
 * GeneralConfiguration, PageConfiguration, StockConfiguration and
 * PaginationConfiguration save them. Compared with, and answered with, the reading of
 * somcp_get_product_settings, so the read given back whole writes nothing.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// read before and after.
require_once __DIR__ . '/SomcpToolGetProductSettings.php';

class SomcpToolUpdateProductSettings
{
    /**
     * The fields saved, in the order of the cards: configuration key, kind,
     * and for a choice its words to the stored codes, for a number its
     * lowest value and the words of its refusal. The last item is the first
     * version whose screen has the field. The five texts of Products stock
     * are SomcpToolGetProductSettings::LABELS, saved per language.
     *
     * @var array<string, array{0: string, 1: string, 2: mixed, 3: string}>
     */
    private const FIELDS = [
        'catalog_mode' => ['PS_CATALOG_MODE', 'bool', null, '8.0.0'],
        'catalog_mode_show_prices' => ['PS_CATALOG_MODE_WITH_PRICES', 'bool', null, '8.0.0'],
        'new_product_days' => ['PS_NB_DAYS_NEW_PRODUCT', 'int', [0, 'a number of days, 0 or more'], '8.0.0'],
        'short_description_limit' => ['PS_PRODUCT_SHORT_DESC_LIMIT', 'int', [1, 'a number of characters, 1 or more'], '8.0.0'],
        'quantity_discounts_based_on' => ['PS_QTY_DISCOUNT_ON_COMBINATION', 'choice', ['products' => 0, 'combinations' => 1], '8.0.0'],
        'force_friendly_url' => ['PS_FORCE_FRIENDLY_PRODUCT', 'bool', null, '8.0.0'],
        'breadcrumb_category' => ['PS_PRODUCT_BREADCRUMB_CATEGORY', 'choice', ['default' => 'default', 'current' => 'current'], '9.0.0'],
        'activate_new_products' => ['PS_PRODUCT_ACTIVATION_DEFAULT', 'bool', null, '8.0.0'],
        'specific_price_priorities' => ['PS_SPECIFIC_PRICE_PRIORITIES', 'priorities', null, '8.0.0'],
        'disabled_product_behavior' => [
            'PS_PRODUCT_REDIRECTION_DEFAULT',
            'choice',
            ['404' => '404', '410' => '410', '200-displayed' => '200-displayed', '404-displayed' => '404-displayed', '410-displayed' => '410-displayed'],
            '8.1.0',
        ],
        'display_available_quantities' => ['PS_DISPLAY_QTIES', 'bool', null, '8.0.0'],
        'add_to_cart_with_combinations' => ['PS_ATTRIBUTE_CATEGORY_DISPLAY', 'bool', null, '8.0.0'],
        'combination_image_in_listings' => ['PS_USE_COMBINATION_IMAGE_IN_LISTING', 'bool', null, '9.0.0'],
        'attribute_anchor_separator' => ['PS_ATTRIBUTE_ANCHOR_SEPARATOR', 'choice', ['-' => '-', ',' => ','], '8.0.0'],
        'display_discounted_unit_price' => ['PS_DISPLAY_DISCOUNT_PRICE', 'bool', null, '8.0.0'],
        'notify_product_in_cart' => ['PS_DISPLAY_AMOUNT_IN_CART', 'bool', null, '9.0.0'],
        'feature_values_order' => ['PS_FEATURE_VALUES_ORDER', 'choice', ['alphabetical' => 'name', 'position' => 'position'], '9.0.0'],
        'stock_management' => ['PS_STOCK_MANAGEMENT', 'bool', null, '8.0.0'],
        'pack_stock_type' => ['PS_PACK_STOCK_TYPE', 'choice', ['pack' => 0, 'products' => 1, 'both' => 2], '8.0.0'],
        'display_unavailable_attributes' => ['PS_DISP_UNAVAILABLE_ATTR', 'bool', null, '8.0.0'],
        'remaining_quantities_below' => ['PS_LAST_QTIES', 'int', [0, 'a quantity, 0 or more'], '8.0.0'],
        'allow_out_of_stock_ordering' => ['PS_ORDER_OUT_OF_STOCK', 'bool', null, '8.0.0'],
        'out_of_stock_label_in_listings' => ['PS_SHOW_LABEL_OOS_LISTING_PAGES', 'bool', null, '8.0.0'],
        'products_per_page' => ['PS_PRODUCTS_PER_PAGE', 'int', [1, 'a number of products, 1 or more'], '8.0.0'],
        'default_order_by' => ['PS_PRODUCTS_ORDER_BY', 'order_by', null, '8.0.0'],
        'default_order_way' => ['PS_PRODUCTS_ORDER_WAY', 'choice', ['asc' => 0, 'desc' => 1], '8.0.0'],
    ];

    /**
     * The words of the screen for a field a version does not have.
     */
    private const SCREEN = [
        'breadcrumb_category' => 'Category used in breadcrumbs',
        'disabled_product_behavior' => 'Default behavior for disabled products',
        'combination_image_in_listings' => 'Use combination image in listings',
        'notify_product_in_cart' => 'Display notifications if the product is already in the cart',
        'feature_values_order' => 'Order of feature values',
    ];

    /**
     * The product field whose rule each text of Products stock follows: the
     * shop shows these texts for every product that has none of its own.
     */
    private const LABEL_RULES = [
        'label_in_stock' => 'available_now',
        'label_backorders_allowed' => 'available_later',
        'label_backorders_denied' => 'available_later',
        'delivery_time_in_stock' => 'delivery_in_stock',
        'delivery_time_backorders' => 'delivery_out_stock',
    ];

    /**
     * What the read answers beside the settings: its warning.
     */
    private const COMPUTED = ['warning'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $switch = static function (string $description): array {
            return ['type' => 'boolean', 'description' => $description];
        };
        $choice = static function (array $words, string $description): array {
            return ['type' => 'string', 'enum' => $words, 'description' => $description];
        };
        $text = static function (string $description): array {
            return [
                'type' => 'object',
                'additionalProperties' => ['type' => 'string'],
                'description' => $description . ', one text per language keyed by ISO code, for example {"en": "In stock"}.',
            ];
        };

        return new SomcpTool(
            'somcp_update_product_settings',
            Somcp::t('Change the product settings'),
            'Changes Shop Parameters > Product Settings, as the Save of its four cards: catalog mode, new product '
                . 'days, short description size, specific price priorities, product page options, stock management, '
                . 'out-of-stock ordering, pack stock, default stock labels and delivery times per language, products '
                . 'per page and their order. Only the fields given change; the answer of somcp_get_product_settings '
                . 'can be given back. One product\'s stock or labels: somcp_update_product, somcp_set_stock_quantity. '
                . 'Show the merchant the change first; never turn catalog mode on or stock management off unasked.',
            [
                'type' => 'object',
                'properties' => [
                    'catalog_mode' => $switch('Catalog mode: visitors browse the products but cannot order.'),
                    'catalog_mode_show_prices' => $switch('Show prices in catalog mode; only with catalog_mode on.'),
                    'new_product_days' => ['type' => 'integer', 'description' => 'Number of days for which the product is considered new, 0 or more.'],
                    'short_description_limit' => ['type' => 'integer', 'description' => 'Max size of short description, in characters, 1 or more.'],
                    'quantity_discounts_based_on' => $choice(['products', 'combinations'], 'Quantity discounts based on.'),
                    'force_friendly_url' => $switch('Force update of friendly URL.'),
                    'breadcrumb_category' => $choice(['default', 'current'], 'Category used in breadcrumbs: the default one, or the one the product was accessed from. PrestaShop 9 only.'),
                    'activate_new_products' => $switch('Activate new products by default.'),
                    'specific_price_priorities' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'enum' => array_values(SomcpToolGetProductSettings::PRIORITIES)],
                        'description' => 'Default order of priority for specific prices: store, currency, country and group, each once, in the order wanted.',
                    ],
                    'disabled_product_behavior' => $choice(
                        array_map('strval', array_keys(self::FIELDS['disabled_product_behavior'][2])),
                        'Default behavior for disabled products: an error page (404, 410) or the product shown as discontinued. PrestaShop 8.1 and later.'
                    ),
                    'display_available_quantities' => $switch('Display available quantities on the product page.'),
                    'add_to_cart_with_combinations' => $switch('Display the Add to cart button when a product has combinations.'),
                    'combination_image_in_listings' => $switch('Use combination image in listings. PrestaShop 9 only.'),
                    'attribute_anchor_separator' => $choice(['-', ','], 'Separator of attribute anchor on the product links.'),
                    'display_discounted_unit_price' => $switch('Display the discounted unit price.'),
                    'notify_product_in_cart' => $switch('Display notifications if the product is already in the cart. PrestaShop 9 only.'),
                    'feature_values_order' => $choice(['alphabetical', 'position'], 'Order of feature values. PrestaShop 9 only.'),
                    'stock_management' => $switch('Enable stock management; off, quantities are not counted and every product can be ordered.'),
                    'pack_stock_type' => $choice(['pack', 'products', 'both'], 'Default pack stock behavior: the stock of the pack, of the products in it, or both.'),
                    'display_unavailable_attributes' => $switch('Display unavailable attributes on the product page.'),
                    'remaining_quantities_below' => ['type' => 'integer', 'description' => 'Display remaining quantities when the quantity is lower than this, 0 or more; 0 turns it off.'],
                    'allow_out_of_stock_ordering' => $switch('Allow ordering of out-of-stock products, for the products that follow the default.'),
                    'label_in_stock' => $text('Label of in-stock products'),
                    'label_backorders_allowed' => $text('Label of out-of-stock products with allowed backorders'),
                    'label_backorders_denied' => $text('Label of out-of-stock products with denied backorders'),
                    'delivery_time_in_stock' => $text('Delivery time of in-stock products; empty shows none'),
                    'delivery_time_backorders' => $text('Delivery time of out-of-stock products with allowed backorders; empty shows none'),
                    'out_of_stock_label_in_listings' => $switch('Display out-of-stock label on product listing pages.'),
                    'languages' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'ISO codes among the shop\'s active languages of the texts given, for example '
                            . '["en", "fr"]; every active language when left out. A language left out keeps its texts.',
                    ],
                    'products_per_page' => ['type' => 'integer', 'description' => 'Products per page, 1 or more.'],
                    'default_order_by' => $choice(SomcpToolGetProductSettings::ORDER_BY, 'Default order by of the product lists; sales on PrestaShop 9 only.'),
                    'default_order_way' => $choice(['asc', 'desc'], 'Default order method.'),
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminPPreferences')
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
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        // What the read worked out is no change and is taken out, so a read
        // given back whole is not refused for it.
        $checked = $arguments;
        foreach (self::COMPUTED as $key) {
            unset($checked[$key]);
        }

        // Every problem in one answer (#169), so the call is corrected in one
        // go; the languages of the texts by the rule of #149.
        $problems = SomcpTool::unknownFields(
            $checked,
            array_merge(array_keys(self::FIELDS), array_keys(SomcpToolGetProductSettings::LABELS), ['languages'])
        );
        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $problems);
        $before = SomcpToolGetProductSettings::settings($idShop, $languages === null ? [] : $languages['filled']);
        $texts = $languages === null ? [] : self::texts($arguments, $languages, $before, $problems);

        $given = [];
        foreach (self::FIELDS as $field => [, $kind, $rule, $since]) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                // Null is what the read gives for a field of a later version.
                continue;
            }
            $value = $arguments[$field];
            if (version_compare(_PS_VERSION_, $since, '<')) {
                $problems[] = SomcpTool::notOnThisVersion($field, self::SCREEN[$field] ?? $field);
                continue;
            }
            if ($kind === 'bool') {
                if (!is_bool($value)) {
                    $problems[] = $field . ': true or false';
                    continue;
                }
            } elseif ($kind === 'int') {
                if (is_string($value) && ctype_digit($value)) {
                    $value = (int) $value;
                }
                if (!is_int($value) || $value < $rule[0]) {
                    $problems[] = $field . ': ' . $rule[1];
                    continue;
                }
            } elseif ($kind === 'choice') {
                if (!is_string($value) || !array_key_exists($value, $rule)) {
                    $problems[] = SomcpTool::oneOf($field, array_map('strval', array_keys($rule)));
                    continue;
                }
            } elseif ($kind === 'order_by') {
                $words = self::orderBy();
                if (!is_string($value) || !in_array($value, $words, true)) {
                    $problems[] = SomcpTool::oneOf($field, $words);
                    continue;
                }
            } else {
                $words = array_values(SomcpToolGetProductSettings::PRIORITIES);
                if (!is_array($value) || !array_is_list($value) || count($value) !== 4
                    || count(array_filter($value, 'is_string')) !== 4 || array_diff($value, $words) !== []) {
                    $problems[] = $field . ': four of ' . implode(', ', $words);
                    continue;
                }
                if (count(array_unique($value)) !== 4) {
                    // The sentence of the core's GeneralConfiguration.
                    $problems[] = $field . ': The selected condition must be different in each field to set an order of priority.';
                    continue;
                }
            }
            $given[$field] = $value;
        }

        // The core saves Show prices off whenever catalog mode is off, so
        // turning catalog mode off turns it off too, named in the changes.
        $catalogMode = $given['catalog_mode'] ?? $before['catalog_mode'];
        if (($given['catalog_mode_show_prices'] ?? false) === true && !$catalogMode) {
            $problems[] = 'catalog_mode_show_prices: only with catalog_mode on - the shop saves it off otherwise';
        } elseif (($given['catalog_mode'] ?? true) === false && $before['catalog_mode_show_prices']) {
            $given['catalog_mode_show_prices'] = false;
        }

        if ($problems !== [] || $languages === null) {
            return SomcpTool::refused('changed', $problems);
        }

        $changes = [];
        foreach (array_keys(self::FIELDS) as $field) {
            if (array_key_exists($field, $given) && $given[$field] !== $before[$field]) {
                $changes[] = ['field' => $field, 'old' => $before[$field], 'new' => $given[$field]];
            }
        }
        $textChanges = [];
        foreach ($texts as $name => $byIso) {
            foreach ($byIso as $iso => $text) {
                $textChanges[] = ['field' => $name . ' (' . $iso . ')', 'old' => $before[$name][$iso] ?? '', 'new' => $text];
            }
        }
        if ($changes === [] && $textChanges === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The product settings already hold every value given. Nothing was written.',
                'settings' => $before,
            ]);
        }

        $saved = [];
        foreach ($changes as $change) {
            $field = $change['field'];
            if (!Configuration::updateValue(self::FIELDS[$field][0], self::stored($field, $given[$field]), false, $idShopGroup, $idShop)) {
                return self::failed($saved, $field . ' could not be saved', $idEmployee);
            }
            $saved[] = $field;
        }
        $byIso = array_flip($languages['filled']);
        foreach ($texts as $name => $byIsoText) {
            // Only the languages given: Configuration::updateValue() leaves
            // the others, inactive ones included, as they were. Plain text,
            // as StockConfiguration saves it.
            $values = [];
            foreach ($byIsoText as $iso => $text) {
                $values[$byIso[$iso]] = $text;
            }
            if (!Configuration::updateValue(SomcpToolGetProductSettings::LABELS[$name], $values, false, $idShopGroup, $idShop)) {
                return self::failed($saved, $name . ' could not be saved', $idEmployee);
            }
            $saved[] = $name . ' (' . implode(', ', array_keys($byIsoText)) . ')';
        }
        // The fields saved, never the texts.
        SomcpLogger::log('product_settings_updated', SomcpLogger::OUTCOME_SUCCESS, implode(', ', $saved), '', $idEmployee);

        // Read back rather than echoed: what the assistant shows is what the
        // shop stored.
        $after = SomcpToolGetProductSettings::settings($idShop, $languages['filled']);
        $result = ['changed' => true, 'changes' => array_merge($changes, $textChanges)];

        $warnings = [];
        if (in_array('catalog_mode', $saved, true) && $after['catalog_mode']) {
            $warnings[] = 'The shop is now in catalog mode: visitors can browse the products but cannot add them to the cart.';
        } elseif ($after['catalog_mode']) {
            $warnings[] = 'Catalog mode is on: visitors can browse the products but cannot add them to the cart.';
        }
        if (in_array('stock_management', $saved, true) && !$after['stock_management']) {
            $warnings[] = 'Stock management is now off: quantities are no longer counted and every product can be ordered.';
        } elseif (!$after['stock_management']) {
            $warnings[] = 'Stock management is off: quantities are not counted and every product can be ordered.';
        }
        if (in_array('allow_out_of_stock_ordering', $saved, true) && $after['allow_out_of_stock_ordering'] && $after['stock_management']) {
            $warnings[] = 'Products out of stock can now be ordered, unless a product says otherwise.';
        }
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'The settings changed for this shop only, as the back office saves them in a shop context.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        $result['settings'] = $after;

        return SomcpTool::json($result);
    }

    /**
     * The texts of Products stock that differ from the stored ones, by name
     * and ISO code, each trimmed and checked by the rule of the product field
     * it stands in for, as a product's own labels are.
     *
     * @param array<string, mixed> $arguments
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<string, mixed> $before the reading of somcp_get_product_settings
     * @param array<int, string> $problems
     *
     * @return array<string, array<string, string>>
     */
    private static function texts(array $arguments, array $languages, array $before, array &$problems): array
    {
        // The rules and sizes of Product::$definition, never a number written
        // here, so the default label holds what a product's own label holds.
        $probe = new Product();
        $texts = [];
        foreach (self::LABEL_RULES as $name => $rule) {
            $byLang = SomcpToolCreateProduct::texts($arguments, $name, $name, $languages, $problems);
            if ($byLang === null) {
                continue;
            }
            $stored = is_array($before[$name]) ? $before[$name] : [];
            foreach ($byLang as $idLang => $text) {
                $iso = $languages['filled'][$idLang];
                // The text given back as read is no change, even when
                // trimming it would rewrite it.
                if (array_key_exists($iso, $stored) && $stored[$iso] === $text) {
                    continue;
                }
                $text = trim($text);
                // An empty text is allowed: the shop then displays nothing.
                if ($text !== '' && ($check = $probe->validateField($rule, $text, $idLang)) !== true) {
                    $problems[] = $name . ' (' . $iso . '): ' . (is_string($check) ? strip_tags($check) : 'not accepted by PrestaShop');
                    continue;
                }
                if (($stored[$iso] ?? '') !== $text) {
                    $texts[$name][$iso] = $text;
                }
            }
        }

        return $texts;
    }

    /**
     * The words of Default order by this version offers: Product sales came with 9.0.0.
     *
     * @return array<int, string>
     */
    private static function orderBy(): array
    {
        $words = SomcpToolGetProductSettings::ORDER_BY;

        return version_compare(_PS_VERSION_, '9.0.0', '<') ? array_slice($words, 0, 8) : $words;
    }

    /**
     * A value as the core's configurations store it: 0 or 1, an integer, the code of a choice.
     *
     * @param string $field a key of FIELDS
     * @param mixed $value checked already
     *
     * @return int|string
     */
    private static function stored(string $field, $value)
    {
        [, $kind, $rule] = self::FIELDS[$field];
        if ($kind === 'bool') {
            return (int) $value;
        }
        if ($kind === 'choice') {
            return $rule[$value];
        }
        if ($kind === 'order_by') {
            return (int) array_search($value, SomcpToolGetProductSettings::ORDER_BY, true);
        }
        if ($kind === 'priorities') {
            $codes = array_flip(SomcpToolGetProductSettings::PRIORITIES);

            return implode(';', array_map(static function (string $word) use ($codes): string {
                return $codes[$word];
            }, $value));
        }

        return (int) $value;
    }

    /**
     * The answer to a save that stopped half way, naming what was saved so
     * the assistant reads the settings before trying again.
     *
     * @param array<int, string> $saved
     * @param string $failure
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function failed(array $saved, string $failure, int $idEmployee): array
    {
        SomcpLogger::log('product_settings_update_failed', SomcpLogger::OUTCOME_FAILURE, $failure, '', $idEmployee);

        return SomcpTool::text(
            'The product settings could not be changed: ' . $failure
                . ($saved !== [] ? ', after ' . implode(', ', $saved) . ' had been saved' : '')
                . '. Read them with somcp_get_product_settings before calling again.',
            true
        );
    }
}
