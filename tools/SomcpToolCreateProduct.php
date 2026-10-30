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
 * somcp_create_product - one complete product in one call.
 *
 * It takes the groups and field names somcp_get_product returns, so what one
 * tool reads the other writes, and it answers with that same reading of the
 * product it created: the assistant checks what was stored, not what it sent.
 *
 * Everything is checked before anything is written. A product created half -
 * its combinations refused after the product row went in - is the worst
 * outcome here: it shows up in the back office, it has a URL, and an
 * assistant told "it failed" would create a second one next to it. So the
 * whole call is validated first and every problem reported at once; then, if
 * writing still fails, what was written is deleted through Product::delete(),
 * which takes the combinations, stock, prices and associations with it.
 *
 * Images are the exception, as the specification says: they are downloaded
 * from the merchant's URLs after the product exists, and one that cannot be
 * fetched is reported without undoing the product.
 *
 * Writing goes through the core objects - Product, Combination, StockAvailable,
 * SpecificPrice, Image - so the hooks fire and other modules see an ordinary
 * product creation. The rules a value must follow are read from each class's
 * own definition (ObjectModel::validateField()), so an EAN13 is checked the
 * way PrestaShop 8 checks it on 8 and the way PrestaShop 9 does on 9.
 *
 * The checks and writes are protected rather than private because
 * somcp_update_product extends this class: changing a product follows the
 * same rules as creating one, and two copies of a rule would drift apart.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolCreateProduct
{
    /** @var array<string, int> out_of_stock as the merchant says it, and as it is stored. */
    protected const OUT_OF_STOCK = ['deny' => 0, 'allow' => 1, 'default' => 2];

    /** @var array<string, int> additional_delivery_times as the merchant says it, and as it is stored. */
    protected const DELIVERY_TIMES = ['none' => 0, 'default' => 1, 'product' => 2];

    /** @var int Stricter than PrestaShop's 64, as the specification asks. */
    protected const MAX_REFERENCE = 50;

    /** @var int Summary bounds, counted on the visible text. */
    protected const SUMMARY_MIN = 100;
    protected const SUMMARY_MAX = 200;

    /** @var int Long enough for search engines to show the title whole. */
    protected const META_TITLE_MAX = 55;

    /** @var int The limit of Product::$definition. */
    protected const META_DESCRIPTION_MAX = 512;

    /** @var int What search results show before cutting. */
    protected const META_DESCRIPTION_SHOWN = 160;

    /** @var int 10 MB per downloaded image. */
    protected const IMAGE_MAX_BYTES = 10485760;

    /** @var array<int, string> What a downloaded image may be, by the type getimagesize() reads in its bytes. */
    protected const IMAGE_TYPES = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_GIF => 'image/gif',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    /** @var array<int, string> The criteria a merchant can order; id_customer always comes first and is not one of them. */
    protected const PRIORITIES = ['id_shop', 'id_currency', 'id_country', 'id_group'];

    /** @var array<string, array<int, string>> The fields each group accepts. */
    protected const ACCEPTED = [
        'basics' => ['product_type', 'name', 'description_short', 'description'],
        'categories' => ['categories', 'id_category_default'],
        'brand' => ['id_manufacturer'],
        'references' => ['reference', 'mpn', 'upc', 'ean13', 'isbn'],
        'condition' => ['condition', 'show_condition'],
        'customization' => ['fields'],
        'stock' => [
            'quantity', 'location', 'out_of_stock', 'available_now', 'available_later', 'available_date',
            'minimal_quantity', 'low_stock_threshold', 'low_stock_alert', 'pack_stock_type',
        ],
        'pack' => ['items'],
        'virtual_file' => ['file', 'display_filename', 'nb_downloadable', 'date_expiration', 'nb_days_accessible'],
        'shipping' => [
            'width', 'height', 'depth', 'weight', 'additional_delivery_times', 'delivery_in_stock',
            'delivery_out_stock', 'additional_shipping_cost', 'carriers',
        ],
        'pricing' => ['price_tax_excl', 'id_tax_rules_group', 'wholesale_price', 'unit_price_tax_excl', 'unity', 'on_sale', 'ecotax_tax_excl'],
        'specific_prices' => ['rules', 'priorities'],
        'seo' => ['meta_title', 'meta_description', 'link_rewrite', 'redirect_type', 'id_type_redirected', 'meta_keywords', 'tags'],
        'options' => ['visibility', 'available_for_order', 'show_price', 'online_only', 'active'],
        'suppliers' => ['id_supplier_default', 'suppliers'],
        'feature' => ['id_feature', 'id_feature_value', 'value'],
        'customization_field' => ['type', 'name', 'required'],
        'rule' => [
            'combination', 'currency', 'country', 'id_group', 'id_customer', 'from_quantity', 'price',
            'reduction', 'reduction_type', 'reduction_tax', 'from', 'to',
        ],
        'supplier' => ['id_supplier', 'combination', 'product_supplier_reference', 'product_supplier_price_te', 'currency'],
        'image' => ['url', 'upload_ref', 'legend', 'cover', 'position', 'combinations'],
        'pack_item' => ['id_product', 'id_product_attribute', 'quantity'],
        'file' => ['url', 'upload_ref'],
        'combination' => [
            'attributes', 'default_on', 'reference', 'mpn', 'upc', 'ean13', 'isbn', 'quantity', 'location',
            'available_date', 'available_now', 'available_later', 'price_impact_tax_excl',
            'unit_price_impact_tax_excl', 'weight_impact', 'wholesale_price', 'minimal_quantity',
            'low_stock_threshold', 'low_stock_alert',
        ],
    ];

    /** @var array<string, int> pack_stock_type as the merchant names it, to the values of Pack::STOCK_TYPE_*. */
    protected const PACK_STOCK_TYPES = ['pack' => 0, 'products' => 1, 'both' => 2, 'default' => 3];

    /** @var array<int, string> The groups that are items of a list or a field of a group, not groups of the call. */
    protected const NESTED = ['feature', 'customization_field', 'rule', 'supplier', 'image', 'combination', 'pack_item', 'file'];

    /**
     * @var array<int, string> What somcp_get_product returns and this tool refuses: worked out by the shop, or
     *                         read from another object. Named, so the assistant learns why rather than meeting
     *                         an "unknown field" for a value it was just given.
     */
    protected const COMPUTED = [
        'basics.id_product', 'categories.name', 'brand.manufacturer_name', 'seo.url', 'pricing.tax_rules_group_name',
        'pricing.tax_rate', 'pricing.price_tax_incl', 'pricing.unit_price_tax_incl', 'pricing.currency',
        'shipping.currency', 'customization.customizable', 'customization.uploadable_files',
        'customization.text_fields', 'suppliers.supplier_name', 'rule.id_specific_price', 'rule.id_shop',
        'rule.id_product_attribute', 'rule.amounts_currency', 'supplier.supplier_name',
        'supplier.id_product_attribute', 'image.id_image', 'combination.id_product_attribute',
        'combination.price_impact_tax_incl', 'combination.unit_price_impact_tax_incl', 'combination.currency',
        'combination.images', 'feature.name', 'feature.custom', 'customization_field.id_customization_field',
        'pricing.ecotax_tax_incl', 'pack_item.name', 'pack_item.reference', 'virtual_file.active',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_product',
            Somcp::t('Create a product'),
            'Creates one product in this shop, inactive unless options.active says otherwise. Everything '
                . 'somcp_get_product reads can be given, in the same groups and under the same names: texts, '
                . 'categories, brand, related products, references, features, attachments, condition, '
                . 'customization, stock, the products of a pack, the downloadable file of a virtual product, '
                . 'shipping and carriers, pricing and tax, specific prices, SEO, options, suppliers, images and '
                . 'combinations. Only basics.name and pricing.price_tax_excl '
                . 'are required. '
                . 'Who fills each field is said at the start of its description, and is a rule for you: '
                . '"Merchant" - ask the merchant and never invent the value; you may suggest one for them to '
                . 'confirm. "Merchant, from the shop" - show the list read with the matching somcp_list_* tool '
                . '(categories, brands, carriers, tax rules, suppliers, attributes, features, attachments, '
                . 'customer groups) and let the merchant pick; never guess an identifier, and never create '
                . 'the object - this tool refuses one that does not exist. "Assistant" - write it yourself in '
                . 'every language being filled, and show it to the merchant before the call. "Merchant and '
                . 'assistant" - the merchant gives the material, you prepare the rest and show it first. '
                . 'Texts are objects keyed by ISO code and must be given for every language in languages; a '
                . 'language of the shop left out takes the default language\'s text. Lengths count the visible '
                . 'text, spaces included, HTML tags not counted. '
                . 'Every problem is reported at once and nothing is written until the whole call is valid. '
                . 'Images are downloaded by the shop from public https addresses, or taken from a file already '
                . 'on the shop by its upload_ref; one that cannot be had is reported in images and does not stop '
                . 'the creation. The products of a pack and the file of a virtual product are reported the same '
                . 'way, in pack_items and virtual_file. '
                . 'Do not use it to change an existing product, which is somcp_update_product. One product per '
                . 'call: for several, call it once per product and report each result. '
                . 'Not to be retried: if the answer is lost, search with somcp_search_products by the '
                . 'reference or the name before anything else, because a second call creates a second product.',
            self::inputSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Adding a product is what the products page asks for in Team,
            // Permissions; editing is a different right, used by
            // somcp_update_product.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminProducts')
        );
    }

    /**
     * The arguments, in the groups and under the names somcp_get_product returns.
     *
     * @return array<string, mixed>
     */
    protected static function inputSchema(): array
    {
        $text = static function (string $description): array {
            return [
                'type' => 'object',
                'description' => $description . ' One text per language being filled, keyed by ISO code: {"fr": "...", "en": "..."}.',
                'additionalProperties' => ['type' => 'string'],
            ];
        };
        $ids = static function (string $description): array {
            return ['type' => 'array', 'description' => $description, 'items' => ['type' => 'integer']];
        };

        return [
            'type' => 'object',
            'properties' => [
                'languages' => [
                    'type' => 'array',
                    'description' => 'ISO codes of the languages to fill, among the active languages of the shop. '
                        . 'All of them by default. Every text must then be given in each of them.',
                    'items' => ['type' => 'string'],
                ],
                'basics' => [
                    'type' => 'object',
                    'properties' => [
                        'product_type' => [
                            'type' => 'string',
                            'enum' => ['standard', 'combinations', 'pack', 'virtual'],
                            'description' => 'Merchant. combinations when combinations are given, standard '
                                . 'otherwise. A pack takes its products in pack, a virtual product its file in '
                                . 'virtual_file.',
                        ],
                        'name' => $text('Merchant. Required, 128 characters at most.'),
                        'description_short' => $text('Assistant. The summary, 100 to 200 characters.'),
                        'description' => $text('Assistant. The description, HTML allowed.'),
                    ],
                    'required' => ['name'],
                    'additionalProperties' => false,
                ],
                'categories' => [
                    'type' => 'object',
                    'properties' => [
                        'categories' => $ids('Merchant, from the shop: category identifiers from somcp_list_categories.'),
                        'id_category_default' => [
                            'type' => 'integer',
                            'description' => 'Merchant, from the shop: the main category, one of categories (added '
                                . 'to them if missing). The first category given otherwise, the home category '
                                . 'when none is.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'brand' => [
                    'type' => 'object',
                    'properties' => [
                        'id_manufacturer' => [
                            'type' => 'integer',
                            'description' => 'Merchant, from the shop: brand identifier from somcp_list_manufacturers.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'related_products' => $ids('Merchant: identifiers of related products. Find candidates with '
                    . 'somcp_search_products and let the merchant pick.'),
                'references' => [
                    'type' => 'object',
                    'properties' => [
                        'reference' => [
                            'type' => 'string',
                            'description' => 'Merchant. 50 characters at most, used by no other product or combination.',
                        ],
                        'mpn' => ['type' => 'string', 'description' => 'Merchant. Manufacturer part number.'],
                        'upc' => ['type' => 'string', 'description' => 'Merchant. UPC barcode.'],
                        'ean13' => ['type' => 'string', 'description' => 'Merchant. EAN-13 or JAN barcode.'],
                        'isbn' => ['type' => 'string', 'description' => 'Merchant. ISBN.'],
                    ],
                    'additionalProperties' => false,
                ],
                'features' => [
                    'type' => 'array',
                    'description' => 'Merchant, from the shop: features from somcp_list_features, each with an '
                        . 'existing value or a custom value typed for this product only.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id_feature' => ['type' => 'integer', 'description' => 'Feature identifier.'],
                            'id_feature_value' => ['type' => 'integer', 'description' => 'One of its values. Leave out to give value instead.'],
                            'value' => $text('A custom value, instead of id_feature_value.'),
                        ],
                        'required' => ['id_feature'],
                        'additionalProperties' => false,
                    ],
                ],
                'attachments' => $ids('Merchant, from the shop: document identifiers from somcp_list_attachments. '
                    . 'Files do not travel through the call.'),
                'condition' => [
                    'type' => 'object',
                    'properties' => [
                        'condition' => [
                            'type' => 'string',
                            'description' => 'Merchant. new, used or refurbished; PrestaShop 9 adds open_box, damaged '
                                . 'and new_with_defects.',
                        ],
                        'show_condition' => ['type' => 'boolean', 'description' => 'Merchant. Show it on the product page.'],
                    ],
                    'additionalProperties' => false,
                ],
                'customization' => [
                    'type' => 'object',
                    'properties' => [
                        'fields' => [
                            'type' => 'array',
                            'description' => 'Merchant: what the customer fills in when ordering.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'type' => ['type' => 'string', 'enum' => ['file', 'text']],
                                    'name' => $text('The label the customer reads.'),
                                    'required' => ['type' => 'boolean'],
                                ],
                                'required' => ['type', 'name'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'stock' => [
                    'type' => 'object',
                    'properties' => [
                        'quantity' => [
                            'type' => 'integer',
                            'description' => 'Merchant. Quantity in stock; with combinations, give each one its own instead.',
                        ],
                        'location' => ['type' => 'string', 'description' => 'Merchant. Where it is stored.'],
                        'out_of_stock' => [
                            'type' => 'string',
                            'enum' => ['deny', 'allow', 'default'],
                            'description' => 'Merchant. Orders when out of stock: refused, accepted, or the shop default.',
                        ],
                        'available_now' => $text('Merchant. Label when in stock; left out, the shop default label shows.'),
                        'available_later' => $text('Merchant. Label when out of stock and orders are accepted.'),
                        'available_date' => ['type' => 'string', 'description' => 'Merchant. Availability date, YYYY-MM-DD.'],
                        'minimal_quantity' => [
                            'type' => 'integer',
                            'description' => 'Merchant. Minimum quantity for sale, 1 or more; 1 by default.',
                        ],
                        'low_stock_threshold' => [
                            'type' => ['integer', 'null'],
                            'description' => 'Merchant. Low stock level; null for none.',
                        ],
                        'low_stock_alert' => [
                            'type' => 'boolean',
                            'description' => 'Merchant. Send an email when the quantity reaches the low stock level.',
                        ],
                        'pack_stock_type' => [
                            'type' => 'string',
                            'enum' => ['pack', 'products', 'both', 'default'],
                            'description' => 'Merchant. Pack stock behaviour: decrement the pack only, the products '
                                . 'only, both, or the shop default. A pack only.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'pack' => [
                    'type' => 'object',
                    'description' => 'A pack only (product_type pack).',
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'description' => 'Merchant, from the shop: the products of the pack, found with '
                                . 'somcp_search_products. A pack cannot hold a pack.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id_product' => ['type' => 'integer', 'description' => 'From somcp_search_products.'],
                                    'id_product_attribute' => [
                                        'type' => 'integer',
                                        'description' => 'A combination of that product, from somcp_get_product; 0 or '
                                            . 'left out for a product without combinations.',
                                    ],
                                    'quantity' => ['type' => 'integer', 'description' => '1 or more; 1 by default.'],
                                ],
                                'required' => ['id_product'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'virtual_file' => [
                    'type' => 'object',
                    'description' => 'A virtual product only (product_type virtual): the file customers download.',
                    'properties' => [
                        'file' => [
                            'type' => 'object',
                            'description' => 'Merchant. Required. url: a public https address the shop downloads '
                                . '(no redirect); upload_ref: a file already on the shop - attached, deposited or '
                                . 'generated in the built-in assistant. One of the two, never file content. Within '
                                . 'the shop\'s maximum attachment size.',
                            'properties' => [
                                'url' => ['type' => 'string'],
                                'upload_ref' => ['type' => 'string'],
                            ],
                            'additionalProperties' => false,
                        ],
                        'display_filename' => [
                            'type' => 'string',
                            'description' => 'Merchant. Required. The file name customers see.',
                        ],
                        'nb_downloadable' => [
                            'type' => 'integer',
                            'description' => 'Merchant. Number of allowed downloads, 0 for unlimited.',
                        ],
                        'date_expiration' => [
                            'type' => ['string', 'null'],
                            'description' => 'Merchant. Expiration date, YYYY-MM-DD; null for none.',
                        ],
                        'nb_days_accessible' => [
                            'type' => 'integer',
                            'description' => 'Merchant. Number of days the link works after the order, 0 for unlimited.',
                        ],
                    ],
                    'required' => ['file', 'display_filename'],
                    'additionalProperties' => false,
                ],
                'shipping' => [
                    'type' => 'object',
                    'properties' => [
                        'width' => ['type' => 'number', 'description' => 'Merchant. In the shop dimension unit.'],
                        'height' => ['type' => 'number', 'description' => 'Merchant. In the shop dimension unit.'],
                        'depth' => ['type' => 'number', 'description' => 'Merchant. In the shop dimension unit.'],
                        'weight' => ['type' => 'number', 'description' => 'Merchant. In the shop weight unit.'],
                        'additional_delivery_times' => [
                            'type' => 'string',
                            'enum' => ['none', 'default', 'product'],
                            'description' => 'Merchant. Delivery time shown: none, the shop default, or this product\'s.',
                        ],
                        'delivery_in_stock' => $text('Merchant. Delivery time of this product when in stock.'),
                        'delivery_out_stock' => $text('Merchant. Delivery time of this product when out of stock.'),
                        'additional_shipping_cost' => ['type' => 'number', 'description' => 'Merchant. Tax excluded.'],
                        'carriers' => $ids('Merchant, from the shop: carrier identifiers from somcp_list_carriers. '
                            . 'None means every carrier, as in the back office.'),
                    ],
                    'additionalProperties' => false,
                ],
                'pricing' => [
                    'type' => 'object',
                    'properties' => [
                        'price_tax_excl' => ['type' => 'number', 'description' => 'Merchant. Required. Selling price, tax excluded.'],
                        'id_tax_rules_group' => [
                            'type' => 'integer',
                            'description' => 'Merchant, from the shop: tax rule from somcp_list_tax_rules, 0 for no '
                                . 'tax. Left out, the rule the back office preselects for a new product.',
                        ],
                        'wholesale_price' => ['type' => 'number', 'description' => 'Merchant. Purchase price, tax excluded.'],
                        'unit_price_tax_excl' => ['type' => 'number', 'description' => 'Merchant. Price per unit, tax excluded.'],
                        'unity' => ['type' => 'string', 'description' => 'Merchant. The unit, for example per kilo.'],
                        'on_sale' => ['type' => 'boolean', 'description' => 'Merchant. Show the "on sale" flag.'],
                        'ecotax_tax_excl' => [
                            'type' => 'number',
                            'description' => 'Merchant. Ecotax, tax excluded. Refused on PrestaShop 9 while the '
                                . 'ecotax is switched off in the shop\'s tax settings.',
                        ],
                    ],
                    'required' => ['price_tax_excl'],
                    'additionalProperties' => false,
                ],
                'specific_prices' => [
                    'type' => 'object',
                    'properties' => [
                        'rules' => [
                            'type' => 'array',
                            'description' => 'Merchant: specific prices, each for this shop.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'combination' => ['type' => 'integer', 'description' => 'Position in combinations, from 0. Left out: the whole product.'],
                                    'currency' => ['type' => 'string', 'description' => 'ISO code, left out for all currencies.'],
                                    'country' => ['type' => 'string', 'description' => 'ISO code, left out for all countries.'],
                                    'id_group' => ['type' => 'integer', 'description' => 'From somcp_list_customer_groups, 0 for all.'],
                                    'id_customer' => ['type' => 'integer', 'description' => '0 for all customers.'],
                                    'from_quantity' => ['type' => 'integer', 'description' => '1 by default.'],
                                    'price' => ['type' => 'number', 'description' => 'A fixed price tax excluded, or -1 (the default) to keep the product price.'],
                                    'reduction' => ['type' => 'number', 'description' => 'An amount, or for a percentage the fraction PrestaShop stores: 0.2 for 20 %.'],
                                    'reduction_type' => ['type' => 'string', 'enum' => ['amount', 'percentage']],
                                    'reduction_tax' => ['type' => 'boolean', 'description' => 'Whether an amount is tax included; true by default.'],
                                    'from' => ['type' => 'string', 'description' => 'Start, YYYY-MM-DD HH:MM:SS. Left out: no start.'],
                                    'to' => ['type' => 'string', 'description' => 'End, YYYY-MM-DD HH:MM:SS. Left out: no end.'],
                                ],
                                'additionalProperties' => false,
                            ],
                        ],
                        'priorities' => [
                            'type' => 'array',
                            'description' => 'Merchant: the order used to pick between rules, the four of id_shop, '
                                . 'id_currency, id_country, id_group.',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'seo' => [
                    'type' => 'object',
                    'properties' => [
                        'meta_title' => $text('Assistant. 55 characters at most.'),
                        'meta_description' => $text('Assistant. 512 characters at most; past 160, search results cut it.'),
                        'link_rewrite' => $text('Assistant. The friendly URL; generated from the name when left out.'),
                        'redirect_type' => [
                            'type' => 'string',
                            'description' => 'Assistant. What the product page does while inactive: 404, 410, '
                                . '301-product, 302-product, 301-category, 302-category; PrestaShop 9 adds default, '
                                . '200-displayed, 404-displayed, 410-displayed. Left out: the shop default.',
                        ],
                        'id_type_redirected' => [
                            'type' => 'integer',
                            'description' => 'Assistant. The product or category a 301 or 302 redirects to.',
                        ],
                        'meta_keywords' => $text('Assistant. PrestaShop 8 only; refused on 9, which dropped them.'),
                        'tags' => $text('Assistant and merchant. The tags (Étiquettes), a comma separated list per '
                            . 'language: "pixel 8, smartphone google, android 14". Written to the shop tag field, '
                            . 'which is separate from meta_keywords and kept on every PrestaShop version. Each tag is '
                            . '32 characters at most and may not hold < > = # { }.'),
                    ],
                    'additionalProperties' => false,
                ],
                'options' => [
                    'type' => 'object',
                    'properties' => [
                        'visibility' => [
                            'type' => 'string',
                            'enum' => ['both', 'catalog', 'search', 'none'],
                            'description' => 'Merchant. Where the product shows.',
                        ],
                        'available_for_order' => ['type' => 'boolean', 'description' => 'Merchant.'],
                        'show_price' => ['type' => 'boolean', 'description' => 'Merchant.'],
                        'online_only' => ['type' => 'boolean', 'description' => 'Merchant. Web exclusive, not sold in store.'],
                        'active' => ['type' => 'boolean', 'description' => 'Merchant. false by default: the product is created offline.'],
                    ],
                    'additionalProperties' => false,
                ],
                'suppliers' => [
                    'type' => 'object',
                    'properties' => [
                        'id_supplier_default' => [
                            'type' => 'integer',
                            'description' => 'Merchant, from the shop: default supplier from somcp_list_suppliers. '
                                . 'The first supplier given otherwise.',
                        ],
                        'suppliers' => [
                            'type' => 'array',
                            'description' => 'Merchant: per supplier, and per combination when it differs.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id_supplier' => ['type' => 'integer'],
                                    'combination' => ['type' => 'integer', 'description' => 'Position in combinations, from 0. Left out: the product.'],
                                    'product_supplier_reference' => ['type' => 'string', 'description' => 'The supplier\'s own reference.'],
                                    'product_supplier_price_te' => ['type' => 'number', 'description' => 'Purchase price at this supplier, tax excluded.'],
                                    'currency' => ['type' => 'string', 'description' => 'ISO code among the shop currencies; the shop default otherwise.'],
                                ],
                                'required' => ['id_supplier'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'images' => [
                    'type' => 'array',
                    'description' => 'Merchant and assistant: the merchant gives the images, the assistant '
                        . 'writes the legends. Each image is a url, downloaded by the shop (public https only, '
                        . 'no redirect, 10 MB at most), or the upload_ref of a file already on the shop - one '
                        . 'the merchant attached or deposited, or an image generated in the built-in assistant. '
                        . 'JPEG, PNG, GIF or WebP. Never file content.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'url' => ['type' => 'string', 'description' => 'Public https address of the image. Give url or upload_ref, not both.'],
                            'upload_ref' => ['type' => 'string', 'description' => 'Reference of an image already on the shop, exactly as given in the conversation. Give url or upload_ref, not both.'],
                            'legend' => $text('Alternative text of the image.'),
                            'cover' => ['type' => 'boolean', 'description' => 'The main image; the first one otherwise.'],
                            'position' => ['type' => 'integer', 'description' => 'Order of the images, from 1; the order given otherwise.'],
                            'combinations' => [
                                'type' => 'array',
                                'description' => 'Positions in combinations, from 0, of the combinations this image illustrates.',
                                'items' => ['type' => 'integer'],
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'combinations' => [
                    'type' => 'array',
                    'description' => 'Merchant and assistant: the merchant gives the attributes (sizes, colours), '
                        . 'the assistant prepares codes and shows them first. One is the default.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'attributes' => $ids('Merchant, from the shop: attribute values from somcp_list_attributes, '
                                . 'one per attribute group. Two combinations cannot have the same ones.'),
                            'default_on' => ['type' => 'boolean', 'description' => 'The default combination; the first one otherwise.'],
                            'reference' => ['type' => 'string', 'description' => '50 characters at most, used nowhere else.'],
                            'mpn' => ['type' => 'string'],
                            'upc' => ['type' => 'string'],
                            'ean13' => ['type' => 'string'],
                            'isbn' => ['type' => 'string'],
                            'quantity' => ['type' => 'integer'],
                            'location' => ['type' => 'string'],
                            'available_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD.'],
                            'available_now' => $text('Label when in stock. From PrestaShop 8.1.'),
                            'available_later' => $text('Label when out of stock. From PrestaShop 8.1.'),
                            'price_impact_tax_excl' => ['type' => 'number', 'description' => 'Added to the product price, tax excluded; negative to lower it.'],
                            'unit_price_impact_tax_excl' => ['type' => 'number'],
                            'weight_impact' => ['type' => 'number'],
                            'wholesale_price' => ['type' => 'number'],
                            'minimal_quantity' => ['type' => 'integer', 'description' => '1 by default.'],
                            'low_stock_threshold' => ['type' => ['integer', 'null'], 'description' => 'Low stock level; null for none.'],
                            'low_stock_alert' => ['type' => 'boolean', 'description' => 'Email at the low stock level.'],
                        ],
                        'required' => ['attributes'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['basics', 'pricing'],
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
        $shop = Somcp::currentShop();
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = [];
        $plan = self::prepare($arguments, $shop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was created. Correct these and call again:\n- " . implode("\n- ", $errors),
                true
            );
        }

        return self::asEmployee($idEmployee, static function () use ($plan, $context, $shop, $idEmployee): array {
            return self::create($plan, $context, (int) $shop->id, $idEmployee);
        });
    }

    /**
     * Runs a write with the employee of the token on the context.
     *
     * The core reads the employee from the context when it records a stock
     * movement. The MCP endpoint is a front controller, which has none, so the
     * movement would be anonymous: the account behind the token is put there
     * for the length of the write, and the context given back as it was.
     *
     * @param int $idEmployee
     * @param callable(): array<string, mixed> $write
     *
     * @return array<string, mixed>
     */
    public static function asEmployee(int $idEmployee, callable $write): array
    {
        $legacy = Somcp::legacyContext();
        $previousEmployee = $legacy->employee;
        if (!$previousEmployee instanceof Employee || (int) $previousEmployee->id !== $idEmployee) {
            $employee = new Employee($idEmployee);
            if (Validate::isLoadedObject($employee)) {
                $legacy->employee = $employee;
            }
        }

        try {
            return $write();
        } finally {
            $legacy->employee = $previousEmployee;
        }
    }

    /**
     * Writes the product, its images, and answers with what was stored.
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $context
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    protected static function create(array $plan, array $context, int $idShop, int $idEmployee): array
    {
        $product = new Product(null, false, null, $idShop);
        $combinationIds = [];

        try {
            $combinationIds = self::write($product, $plan, $idShop);
        } catch (Throwable $e) {
            $kept = self::rollback($product);
            SomcpLogger::log(
                'product_create_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr($e->getMessage(), 0, 500) . ($kept ? ', product ' . (int) $product->id . ' could not be removed' : ''),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The product could not be created: ' . $e->getMessage() . ' '
                    . ($kept
                        ? 'It could not be removed either: product ' . (int) $product->id . ' exists in an incomplete '
                            . 'state. Tell the merchant to check it in the back office; do not call again.'
                        : 'What had been written was removed, so nothing was kept.'),
                true
            );
        }

        $images = self::images($product, $plan, $combinationIds, $idShop);
        $packItems = self::writePack((int) $product->id, $plan['pack'] ?? []);
        $virtualFile = $plan['virtual_file'] === null ? null : self::writeVirtualFile((int) $product->id, $plan['virtual_file']);
        $warnings = self::seoWarnings($product, $plan, $idShop);

        SomcpLogger::log(
            'product_created',
            SomcpLogger::OUTCOME_SUCCESS,
            'product ' . (int) $product->id . ', ' . count($combinationIds) . ' combination(s), '
                . count(array_filter($images, static function (array $image): bool {
                    return isset($image['id_image']);
                })) . ' image(s)',
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'created' => true,
            'id_product' => (int) $product->id,
            'seo_warnings' => $warnings === [] ? new stdClass() : $warnings,
            'images' => $images,
            'pack_items' => $packItems,
            'virtual_file' => $virtualFile,
            // Read back with the reading tool rather than echoed from the
            // arguments: what the assistant shows the merchant is what the shop
            // stored, defaults and generated URLs included.
            'product' => self::read((int) $product->id, (string) $plan['read_language'], $context),
        ]);
    }

    /**
     * Turns the arguments into what will be written, or says everything that is wrong with them.
     *
     * Nothing is written here. Every lookup that can refuse the call - an
     * identifier that does not exist, a reference already taken, a text missing
     * in one language - happens before the first INSERT, so a refused call
     * leaves the shop exactly as it was.
     *
     * @param array<string, mixed> $arguments
     * @param Shop $shop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    protected static function prepare(array $arguments, Shop $shop, array &$errors): ?array
    {
        $idShop = (int) $shop->id;
        $groups = [];
        foreach (array_keys(self::ACCEPTED) as $group) {
            if (in_array($group, self::NESTED, true)) {
                continue;
            }
            $groups[$group] = self::group($arguments, $group, $errors);
        }
        foreach (array_keys($arguments) as $key) {
            if (!isset($groups[$key]) && !in_array($key, ['languages', 'related_products', 'features', 'attachments', 'images', 'combinations'], true)) {
                $errors[] = $key . ': not a field of this tool';
            }
        }

        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }

        $probe = new Product();
        $plan = [
            'languages' => $languages,
            // The answer reads the product back in the language the texts
            // were checked against first.
            'read_language' => $languages['filled'][$languages['fallback']],
            'fields' => [
                'state' => Product::STATE_SAVED,
                'id_shop_default' => $idShop,
                'active' => false,
            ],
            'texts' => [],
        ];

        self::basics($groups['basics'], $languages, $probe, $plan, $errors);
        self::categorise($groups['categories'], $shop, $plan, $errors);
        self::brand($groups['brand'], $plan, $errors);
        $plan['related_products'] = self::relatedProducts($arguments['related_products'] ?? null, $idShop, $errors);
        self::references($groups['references'], $probe, $plan, $errors);
        $plan['features'] = self::features($arguments['features'] ?? null, $languages, $errors);
        $plan['attachments'] = self::attachments($arguments['attachments'] ?? null, $errors);
        self::condition($groups['condition'], $probe, $plan, $errors);
        self::customization($groups['customization'], $languages, $plan, $errors);
        self::stock($groups['stock'], $languages, $probe, $plan, $errors);
        self::shipping($groups['shipping'], $languages, $idShop, $probe, $plan, $errors);
        self::pricing($groups['pricing'], $probe, $plan, $errors);
        self::options($groups['options'], $probe, $plan, $errors);

        // The groups below point at combinations by their position, so the
        // combinations are read first and the others checked against them.
        $plan['combinations'] = self::combinations($arguments['combinations'] ?? null, $languages, $groups['stock'], $errors);
        $count = count($plan['combinations']);
        self::productType($groups['basics'], $count, $plan, $errors);
        $isPack = !empty($plan['fields']['cache_is_pack']);
        if (isset($plan['fields']['pack_stock_type']) && !$isPack) {
            $errors[] = 'stock.pack_stock_type: a pack only; give basics.product_type pack';
        }
        $plan['pack'] = self::packPlan($groups['pack'], $isPack, 0, $idShop, $errors);
        $plan['virtual_file'] = self::virtualFilePlan($groups['virtual_file'], !empty($plan['fields']['is_virtual']), $errors);
        self::specificPrices($groups['specific_prices'], $count, $idShop, $plan, $errors);
        self::suppliers($groups['suppliers'], $count, $idShop, $plan, $errors);
        $plan['images'] = self::imagePlan($arguments['images'] ?? null, $count, $languages, $errors);
        self::seo($groups['seo'], $languages, $idShop, $probe, $plan, $errors);
        self::uniqueReferences($plan, $errors);

        // The same check the core runs in add(), on the whole object, as a last
        // net for a rule of the running version none of the above knew about.
        if ($errors === []) {
            $candidate = new Product(null, false, null, $idShop);
            self::fill($candidate, $plan['fields'] + self::completeTexts($plan['texts'], $languages));
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'product: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array{filled: array<int, string>, all: array<int, int>, fallback: int}|null
     */
    protected static function languages(array $arguments, int $idShop, array &$errors): ?array
    {
        $active = [];
        foreach (Language::getLanguages(true, $idShop) as $row) {
            $active[Tools::strtolower((string) $row['iso_code'])] = (int) $row['id_lang'];
        }

        $filled = [];
        if (array_key_exists('languages', $arguments)) {
            if (!is_array($arguments['languages']) || $arguments['languages'] === []) {
                $errors[] = 'languages: a list of ISO codes among ' . implode(', ', array_keys($active));

                return null;
            }
            foreach ($arguments['languages'] as $iso) {
                $iso = is_string($iso) ? Tools::strtolower(trim($iso)) : '';
                if (!isset($active[$iso])) {
                    $errors[] = 'languages: "' . $iso . '" is not an active language of this shop, which has '
                        . implode(', ', array_keys($active));
                    continue;
                }
                $filled[$active[$iso]] = $iso;
            }
            if ($filled === []) {
                return null;
            }
        } else {
            $filled = array_flip($active);
        }

        // A language left out takes the text of the default one, as the back
        // office does - or of the first one filled, when the default is not.
        $default = (int) Configuration::get('PS_LANG_DEFAULT');
        $fallback = isset($filled[$default]) ? $default : (int) array_key_first($filled);

        return [
            'filled' => $filled,
            'all' => array_map('intval', Language::getIDs(false)),
            'fallback' => $fallback,
        ];
    }

    /**
     * @param array<string, mixed> $group
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param Product $probe
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     * @param bool $nameRequired false when changing a product, whose name is already there
     *
     * @return void
     */
    protected static function basics(array $group, array $languages, Product $probe, array &$plan, array &$errors, bool $nameRequired = true): void
    {
        // Given, a name is never empty, whether the product is new or not.
        $names = self::texts($group, 'name', 'basics.name', $languages, $errors, $nameRequired || array_key_exists('name', $group));
        if ($names !== null) {
            foreach ($names as $idLang => $name) {
                $name = trim($name);
                $names[$idLang] = $name;
                $where = 'basics.name (' . $languages['filled'][$idLang] . ')';
                if (Tools::strlen($name) > PrestaShop\PrestaShop\Core\Domain\Product\ProductSettings::MAX_NAME_LENGTH) {
                    $errors[] = $where . ': ' . PrestaShop\PrestaShop\Core\Domain\Product\ProductSettings::MAX_NAME_LENGTH . ' characters at most';
                } elseif (($check = $probe->validateField('name', $name, $idLang)) !== true) {
                    $errors[] = $where . ': ' . self::reason($check) . ' (no <>;=#{} in a product name)';
                }
            }
            $plan['texts']['name'] = $names;
        }

        $summaries = self::texts($group, 'description_short', 'basics.description_short', $languages, $errors);
        if ($summaries !== null) {
            foreach ($summaries as $idLang => $html) {
                $html = self::html($html, 'basics.description_short (' . $languages['filled'][$idLang] . ')', $errors);
                $length = self::visibleLength($html);
                if ($length < self::SUMMARY_MIN || $length > self::SUMMARY_MAX) {
                    $errors[] = 'basics.description_short (' . $languages['filled'][$idLang] . '): ' . self::SUMMARY_MIN
                        . ' to ' . self::SUMMARY_MAX . ' characters of visible text, ' . $length . ' given';
                }
                $summaries[$idLang] = $html;
            }
            $plan['texts']['description_short'] = $summaries;
        }

        $descriptions = self::texts($group, 'description', 'basics.description', $languages, $errors);
        if ($descriptions !== null) {
            foreach ($descriptions as $idLang => $html) {
                $descriptions[$idLang] = self::html($html, 'basics.description (' . $languages['filled'][$idLang] . ')', $errors);
            }
            $plan['texts']['description'] = $descriptions;
        }
    }

    /**
     * The product type, which only makes sense once the combinations are known.
     *
     * @param array<string, mixed> $group
     * @param int $combinations
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function productType(array $group, int $combinations, array &$plan, array &$errors): void
    {
        $types = PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::AVAILABLE_TYPES;
        $type = $combinations > 0
            ? PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::TYPE_COMBINATIONS
            : PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::TYPE_STANDARD;

        if (array_key_exists('product_type', $group)) {
            $given = self::string($group['product_type']);
            if ($given === null || !in_array($given, $types, true)) {
                $errors[] = 'basics.product_type: one of ' . implode(', ', $types);

                return;
            }
            if ($combinations > 0 && $given !== PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::TYPE_COMBINATIONS) {
                $errors[] = 'basics.product_type: combinations were given, so the type is combinations, not ' . $given;

                return;
            }
            $type = $given;
        }

        // The same three fields the core's own product creation sets from the type.
        $plan['fields']['product_type'] = $type;
        $plan['fields']['is_virtual'] = $type === PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::TYPE_VIRTUAL;
        $plan['fields']['cache_is_pack'] = $type === PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::TYPE_PACK;
    }

    /**
     * @param array<string, mixed> $group
     * @param Shop $shop
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function categorise(array $group, Shop $shop, array &$plan, array &$errors): void
    {
        $categories = self::ids($group['categories'] ?? [], 'id_category', 'categories.categories', $errors);
        $default = array_key_exists('id_category_default', $group) ? self::integer($group['id_category_default']) : null;
        if (array_key_exists('id_category_default', $group) && ($default === null || $default <= 0)) {
            $errors[] = 'categories.id_category_default: a category identifier';

            return;
        }

        if ($default === null) {
            // The core's own creation files a new product under the home
            // category of its shop when nothing else is said.
            $default = $categories[0] ?? (int) $shop->id_category;
        }
        if (!in_array($default, $categories, true)) {
            array_unshift($categories, $default);
        }

        $root = (int) Configuration::get('PS_ROOT_CATEGORY');
        foreach ($categories as $idCategory) {
            $category = new Category($idCategory);
            if (!Validate::isLoadedObject($category) || $idCategory === $root || !$category->isAssociatedToShop((int) $shop->id)) {
                $errors[] = 'categories: no category ' . $idCategory . ' in this shop. Take identifiers from somcp_list_categories.';
            }
        }

        $plan['categories'] = $categories;
        $plan['fields']['id_category_default'] = $default;
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function brand(array $group, array &$plan, array &$errors): void
    {
        if (!array_key_exists('id_manufacturer', $group)) {
            return;
        }
        $id = self::integer($group['id_manufacturer']);
        if ($id === null || $id < 0) {
            $errors[] = 'brand.id_manufacturer: a brand identifier, or 0 for none';

            return;
        }
        if ($id > 0 && !Validate::isLoadedObject(new Manufacturer($id))) {
            $errors[] = 'brand.id_manufacturer: no brand ' . $id . '. Take identifiers from somcp_list_manufacturers.';

            return;
        }
        $plan['fields']['id_manufacturer'] = $id;
    }

    /**
     * @param mixed $value
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<int, int>
     */
    protected static function relatedProducts($value, int $idShop, array &$errors): array
    {
        $ids = array_values(array_unique(self::ids($value ?? [], 'id_product', 'related_products', $errors)));
        if ($ids === []) {
            return [];
        }

        $found = [];
        foreach (Db::getInstance()->executeS(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product_shop`'
            . ' WHERE `id_shop` = ' . (int) $idShop . ' AND `id_product` IN (' . implode(',', array_map('intval', $ids)) . ')'
        ) ?: [] as $row) {
            $found[(int) $row['id_product']] = true;
        }
        foreach ($ids as $id) {
            if (!isset($found[$id])) {
                $errors[] = 'related_products: no product ' . $id . ' in this shop. Find them with somcp_search_products.';
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $group
     * @param Product $probe
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function references(array $group, Product $probe, array &$plan, array &$errors): void
    {
        foreach (self::ACCEPTED['references'] as $field) {
            if (!array_key_exists($field, $group)) {
                continue;
            }
            $value = self::string($group[$field]);
            if ($value === null) {
                $errors[] = 'references.' . $field . ': a text';
                continue;
            }
            $value = trim($value);
            if ($field === 'reference' && Tools::strlen($value) > self::MAX_REFERENCE) {
                $errors[] = 'references.reference: ' . self::MAX_REFERENCE . ' characters at most';
                continue;
            }
            if (($check = $probe->validateField($field, $value)) !== true) {
                $errors[] = 'references.' . $field . ': ' . self::reason($check);
                continue;
            }
            $plan['fields'][$field] = $value;
        }
    }

    /**
     * @param mixed $value
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<int, string> $errors
     *
     * @return array<int, array{id_feature: int, id_feature_value: int, value: array<int, string>|null}>
     */
    protected static function features($value, array $languages, array &$errors): array
    {
        $features = [];
        $seen = [];
        $probe = new FeatureValue();
        foreach (self::items($value, 'features', $errors) as $i => $item) {
            $path = 'features[' . $i . ']';
            self::onlyAccepted($item, 'feature', $path, $errors);
            $idFeature = self::integer($item['id_feature'] ?? null);
            if ($idFeature === null || !Validate::isLoadedObject(new Feature($idFeature))) {
                $errors[] = $path . '.id_feature: no such feature. Take identifiers from somcp_list_features.';
                continue;
            }

            if (array_key_exists('value', $item)) {
                if (array_key_exists('id_feature_value', $item)) {
                    $errors[] = $path . ': id_feature_value or value, not both';
                    continue;
                }
                $texts = self::texts($item, 'value', $path . '.value', $languages, $errors, true);
                foreach ($texts ?? [] as $idLang => $text) {
                    if (($check = $probe->validateField('value', $text, $idLang)) !== true) {
                        $errors[] = $path . '.value (' . $languages['filled'][$idLang] . '): ' . self::reason($check);
                    }
                }
                $features[] = ['id_feature' => $idFeature, 'id_feature_value' => 0, 'value' => $texts];
                continue;
            }

            $idValue = self::integer($item['id_feature_value'] ?? null);
            $featureValue = new FeatureValue((int) $idValue);
            // A custom value belongs to the product it was typed on: sharing it
            // would change that product's page too.
            if ($idValue === null || !Validate::isLoadedObject($featureValue)
                || (int) $featureValue->id_feature !== $idFeature || (bool) $featureValue->custom) {
                $errors[] = $path . '.id_feature_value: not a value of feature ' . $idFeature . '. Take it from somcp_list_features.';
                continue;
            }
            if (isset($seen[$idFeature . '-' . $idValue])) {
                $errors[] = $path . ': this value is given twice';
                continue;
            }
            $seen[$idFeature . '-' . $idValue] = true;
            $features[] = ['id_feature' => $idFeature, 'id_feature_value' => $idValue, 'value' => null];
        }

        return $features;
    }

    /**
     * @param mixed $value
     * @param array<int, string> $errors
     *
     * @return array<int, int>|null null when not given
     */
    protected static function attachments($value, array &$errors): ?array
    {
        if ($value === null) {
            return null;
        }
        $ids = array_values(array_unique(self::ids($value, 'id_attachment', 'attachments', $errors)));
        foreach ($ids as $id) {
            if (!Validate::isLoadedObject(new Attachment($id))) {
                $errors[] = 'attachments: no document ' . $id . '. Take identifiers from somcp_list_attachments.';
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $group
     * @param Product $probe
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function condition(array $group, Product $probe, array &$plan, array &$errors): void
    {
        if (array_key_exists('condition', $group)) {
            $condition = self::string($group['condition']);
            // The values are the running version's: PrestaShop 9 added three.
            $values = (array) (Product::$definition['fields']['condition']['values'] ?? []);
            if ($condition === null || ($check = $probe->validateField('condition', $condition)) !== true) {
                $errors[] = 'condition.condition: one of ' . implode(', ', $values) . ' on this version';
            } else {
                $plan['fields']['condition'] = $condition;
            }
        }
        self::boolean($group, 'show_condition', 'condition', $plan, $errors);
    }

    /**
     * @param array<string, mixed> $group
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function customization(array $group, array $languages, array &$plan, array &$errors): void
    {
        $fields = [];
        $probe = new CustomizationField();
        foreach (self::items($group['fields'] ?? null, 'customization.fields', $errors) as $i => $item) {
            $path = 'customization.fields[' . $i . ']';
            self::onlyAccepted($item, 'customization_field', $path, $errors);
            $type = self::string($item['type'] ?? null);
            if (!in_array($type, ['file', 'text'], true)) {
                $errors[] = $path . '.type: file or text';
                continue;
            }
            $names = self::texts($item, 'name', $path . '.name', $languages, $errors, true);
            foreach ($names ?? [] as $idLang => $name) {
                if (($check = $probe->validateField('name', $name, $idLang)) !== true) {
                    $errors[] = $path . '.name (' . $languages['filled'][$idLang] . '): ' . self::reason($check);
                }
            }
            $required = array_key_exists('required', $item) ? self::bool($item['required']) : false;
            if ($required === null) {
                $errors[] = $path . '.required: true or false';
                continue;
            }
            $fields[] = [
                'type' => $type === 'file' ? Product::CUSTOMIZE_FILE : Product::CUSTOMIZE_TEXTFIELD,
                'required' => $required,
                'name' => $names ?? [],
            ];
        }

        $plan['customization'] = $fields;
        if ($fields === []) {
            return;
        }

        // The three counters the product page derives from the fields: 2 when
        // one field must be filled before ordering, 1 when all are optional.
        $required = array_filter($fields, static function (array $field): bool {
            return $field['required'];
        });
        $plan['fields']['customizable'] = $required !== [] ? 2 : 1;
        $plan['fields']['uploadable_files'] = count(array_filter($fields, static function (array $field): bool {
            return $field['type'] === Product::CUSTOMIZE_FILE;
        }));
        $plan['fields']['text_fields'] = count($fields) - $plan['fields']['uploadable_files'];
    }

    /**
     * @param array<string, mixed> $group
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param Product $probe
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function stock(array $group, array $languages, Product $probe, array &$plan, array &$errors): void
    {
        $plan['stock'] = ['quantity' => null, 'location' => null, 'out_of_stock' => null];

        if (array_key_exists('quantity', $group)) {
            $quantity = self::integer($group['quantity']);
            if ($quantity === null) {
                $errors[] = 'stock.quantity: a whole number';
            } else {
                $plan['stock']['quantity'] = $quantity;
            }
        }
        if (array_key_exists('location', $group)) {
            $plan['stock']['location'] = self::location($group['location'], 'stock.location', $errors);
        }
        if (array_key_exists('out_of_stock', $group)) {
            $value = self::string($group['out_of_stock']);
            if ($value === null || !isset(self::OUT_OF_STOCK[$value])) {
                $errors[] = 'stock.out_of_stock: deny, allow or default';
            } else {
                $plan['stock']['out_of_stock'] = self::OUT_OF_STOCK[$value];
            }
        }
        foreach (['available_now', 'available_later'] as $field) {
            self::labels($group, $field, 'stock', $languages, $probe, $plan, $errors);
        }
        if (array_key_exists('available_date', $group)) {
            $date = self::date($group['available_date'], 'stock.available_date', $errors);
            if ($date !== null) {
                $plan['fields']['available_date'] = $date;
            }
        }
        if (array_key_exists('minimal_quantity', $group)) {
            $minimal = self::integer($group['minimal_quantity']);
            if ($minimal === null || $minimal < 1 || $probe->validateField('minimal_quantity', $minimal) !== true) {
                $errors[] = 'stock.minimal_quantity: a whole number, 1 or more';
            } else {
                $plan['fields']['minimal_quantity'] = $minimal;
            }
        }
        self::lowStock($group, 'stock', $probe, $plan['fields'], $errors);
        if (array_key_exists('pack_stock_type', $group)) {
            $value = self::string($group['pack_stock_type']);
            if ($value === null || !isset(self::PACK_STOCK_TYPES[$value])) {
                $errors[] = 'stock.pack_stock_type: pack, products, both or default';
            } else {
                $plan['fields']['pack_stock_type'] = self::PACK_STOCK_TYPES[$value];
            }
        }
    }

    /**
     * The low stock level and its alert, of the product or of one combination.
     *
     * @param array<string, mixed> $item
     * @param string $path
     * @param ObjectModel $probe a Product or a Combination, whose definition checks the values
     * @param array<string, mixed> $fields
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function lowStock(array $item, string $path, ObjectModel $probe, array &$fields, array &$errors): void
    {
        if (array_key_exists('low_stock_threshold', $item)) {
            if ($item['low_stock_threshold'] === null) {
                // No level: PrestaShop 8 stores NULL, PrestaShop 9 stores 0,
                // and the back office of each writes its own.
                $fields['low_stock_threshold'] = version_compare(_PS_VERSION_, '9.0.0', '>=') ? 0 : null;
            } else {
                $threshold = self::integer($item['low_stock_threshold']);
                if ($threshold === null || $probe->validateField('low_stock_threshold', $threshold) !== true) {
                    $errors[] = $path . '.low_stock_threshold: a whole number, or null for none';
                } else {
                    $fields['low_stock_threshold'] = $threshold;
                }
            }
        }
        if (array_key_exists('low_stock_alert', $item)) {
            $alert = self::bool($item['low_stock_alert']);
            if ($alert === null) {
                $errors[] = $path . '.low_stock_alert: true or false';
            } else {
                $fields['low_stock_alert'] = $alert;
            }
        }
    }

    /**
     * The products of a pack, each checked against the shop as the back office checks it.
     *
     * @param array<string, mixed> $group
     * @param bool $isPack
     * @param int $idProduct the pack itself, 0 while it is being created
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<int, array{id_product: int, id_product_attribute: int, quantity: int}>|null null when not given
     */
    protected static function packPlan(array $group, bool $isPack, int $idProduct, int $idShop, array &$errors): ?array
    {
        if ($group === []) {
            return null;
        }
        if (!$isPack) {
            $errors[] = 'pack: a pack only; give basics.product_type pack';

            return null;
        }
        $items = [];
        $seen = [];
        foreach (self::items($group['items'] ?? null, 'pack.items', $errors) as $i => $item) {
            $path = 'pack.items[' . $i . ']';
            self::onlyAccepted($item, 'pack_item', $path, $errors);
            $id = self::integer($item['id_product'] ?? null);
            $idAttribute = array_key_exists('id_product_attribute', $item) ? self::integer($item['id_product_attribute']) : 0;
            $quantity = array_key_exists('quantity', $item) ? self::integer($item['quantity']) : 1;
            if ($quantity === null || $quantity < 1) {
                $errors[] = $path . '.quantity: a whole number, 1 or more';
                continue;
            }
            if ($id === null || $id <= 0 || !Db::getInstance()->getValue(
                'SELECT 1 FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `id_product` = ' . (int) $id . ' AND `id_shop` = ' . (int) $idShop
            )) {
                $errors[] = $path . '.id_product: no such product in this shop. Take identifiers from somcp_search_products.';
                continue;
            }
            // The back office offers neither the pack itself nor another
            // pack: stock could then never be worked out.
            if ($id === $idProduct || Pack::isPack($id)) {
                $errors[] = $path . '.id_product: a pack cannot hold a pack, nor itself';
                continue;
            }
            if ($idAttribute === null || $idAttribute < 0 || ($idAttribute > 0 && !Db::getInstance()->getValue(
                'SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product_attribute` = ' . (int) $idAttribute
                    . ' AND `id_product` = ' . (int) $id
            ))) {
                $errors[] = $path . '.id_product_attribute: not a combination of this product. Take it from somcp_get_product, 0 for none.';
                continue;
            }
            // The pack table holds one row per product and combination.
            if (isset($seen[$id . '-' . $idAttribute])) {
                $errors[] = $path . ': this product is already in the pack; give its quantity once';
                continue;
            }
            $seen[$id . '-' . $idAttribute] = true;
            $items[] = ['id_product' => $id, 'id_product_attribute' => (int) $idAttribute, 'quantity' => $quantity];
        }

        return $items;
    }

    /**
     * The downloadable file of a virtual product and its settings.
     *
     * @param array<string, mixed> $group
     * @param bool $isVirtual
     * @param array<int, string> $errors
     * @param bool $fileRequired false when the product already has a file whose settings alone may change
     *
     * @return array<string, mixed>|null null when not given
     */
    protected static function virtualFilePlan(array $group, bool $isVirtual, array &$errors, bool $fileRequired = true): ?array
    {
        if ($group === []) {
            return null;
        }
        if (!$isVirtual) {
            $errors[] = 'virtual_file: a virtual product only; give basics.product_type virtual';

            return null;
        }
        $probe = new ProductDownload();
        $plan = ['source' => null, 'fields' => []];

        if (!array_key_exists('file', $group)) {
            if ($fileRequired) {
                $errors[] = 'virtual_file.file: required, the url or upload_ref of the file';
            }
        } elseif (!is_array($group['file']) || ($group['file'] !== [] && array_is_list($group['file']))) {
            $errors[] = 'virtual_file.file: an object with url or upload_ref';
        } else {
            self::onlyAccepted($group['file'], 'file', 'virtual_file.file', $errors);
            $plan['source'] = self::imageSource($group['file'], 'virtual_file.file', $errors, 'file');
        }

        if (array_key_exists('display_filename', $group)) {
            $name = trim((string) (self::string($group['display_filename']) ?? ''));
            if ($name === '' || ($check = $probe->validateField('display_filename', $name)) !== true) {
                $errors[] = 'virtual_file.display_filename: ' . ($name === '' ? 'the file name customers see' : self::reason($check));
            } else {
                $plan['fields']['display_filename'] = $name;
            }
        } elseif ($fileRequired && $plan['source'] !== null) {
            $errors[] = 'virtual_file.display_filename: required with a file, the name customers see';
        }
        foreach (['nb_downloadable', 'nb_days_accessible'] as $field) {
            if (!array_key_exists($field, $group)) {
                continue;
            }
            $number = self::integer($group[$field]);
            if ($number === null || $number < 0 || $probe->validateField($field, $number) !== true) {
                $errors[] = 'virtual_file.' . $field . ': a whole number, 0 for unlimited';
            } else {
                $plan['fields'][$field] = $number;
            }
        }
        if (array_key_exists('date_expiration', $group)) {
            if ($group['date_expiration'] === null) {
                $plan['fields']['date_expiration'] = null;
            } else {
                $date = self::date($group['date_expiration'], 'virtual_file.date_expiration', $errors);
                if ($date !== null) {
                    $plan['fields']['date_expiration'] = $date . ' 00:00:00';
                }
            }
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $group
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idShop
     * @param Product $probe
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function shipping(array $group, array $languages, int $idShop, Product $probe, array &$plan, array &$errors): void
    {
        foreach (['width', 'height', 'depth', 'weight', 'additional_shipping_cost'] as $field) {
            self::number($group, $field, $field, 'shipping', $probe, $plan, $errors);
        }

        $mode = null;
        if (array_key_exists('additional_delivery_times', $group)) {
            $value = self::string($group['additional_delivery_times']);
            if ($value === null || !isset(self::DELIVERY_TIMES[$value])) {
                $errors[] = 'shipping.additional_delivery_times: none, default or product';
            } else {
                $mode = self::DELIVERY_TIMES[$value];
            }
        }
        $texts = false;
        foreach (['delivery_in_stock', 'delivery_out_stock'] as $field) {
            $texts = self::labels($group, $field, 'shipping', $languages, $probe, $plan, $errors) || $texts;
        }
        if ($texts && $mode !== null && $mode !== self::DELIVERY_TIMES['product']) {
            $errors[] = 'shipping: delivery_in_stock and delivery_out_stock only show when additional_delivery_times is product';
        }
        if ($texts && $mode === null) {
            // Texts of its own mean the product's delivery times; leaving the
            // shop default selected would hide what the merchant just typed.
            $mode = self::DELIVERY_TIMES['product'];
        }
        if ($mode !== null) {
            $plan['fields']['additional_delivery_times'] = $mode;
        }

        $plan['carriers'] = null;
        if (array_key_exists('carriers', $group)) {
            $references = [];
            foreach (array_unique(self::ids($group['carriers'], 'id_carrier', 'shipping.carriers', $errors)) as $idCarrier) {
                $carrier = new Carrier($idCarrier);
                if (!Validate::isLoadedObject($carrier) || $carrier->deleted || !$carrier->isAssociatedToShop($idShop)) {
                    $errors[] = 'shipping.carriers: no carrier ' . $idCarrier . ' in this shop. Take identifiers from somcp_list_carriers.';
                    continue;
                }
                // A product is tied to the carrier's reference, which survives
                // the new identifier a carrier gets each time it is edited.
                $references[] = (int) $carrier->id_reference;
            }
            $plan['carriers'] = array_values(array_unique($references));
        }
    }

    /**
     * @param array<string, mixed> $group
     * @param Product $probe
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     * @param bool $creating false when changing a product, which already has a price and a tax rule
     *
     * @return void
     */
    protected static function pricing(array $group, Product $probe, array &$plan, array &$errors, bool $creating = true): void
    {
        if ($creating && !array_key_exists('price_tax_excl', $group)) {
            $errors[] = 'pricing.price_tax_excl: required, the selling price tax excluded';
        }
        self::number($group, 'price_tax_excl', 'price', 'pricing', $probe, $plan, $errors);
        self::number($group, 'wholesale_price', 'wholesale_price', 'pricing', $probe, $plan, $errors);
        self::number($group, 'unit_price_tax_excl', 'unit_price', 'pricing', $probe, $plan, $errors);
        self::boolean($group, 'on_sale', 'pricing', $plan, $errors);
        if (array_key_exists('ecotax_tax_excl', $group)) {
            // PrestaShop 9 shows the field only with the ecotax switched on;
            // PrestaShop 8 always shows it.
            if (version_compare(_PS_VERSION_, '9.0.0', '>=') && !Configuration::get('PS_USE_ECOTAX')) {
                $errors[] = 'pricing.ecotax_tax_excl: the ecotax is switched off in the shop\'s tax settings';
            } else {
                self::number($group, 'ecotax_tax_excl', 'ecotax', 'pricing', $probe, $plan, $errors);
            }
        }

        if (array_key_exists('unity', $group)) {
            $unity = self::string($group['unity']);
            if ($unity === null) {
                $errors[] = 'pricing.unity: a text';
            } elseif (($check = $probe->validateField('unity', trim($unity))) !== true) {
                $errors[] = 'pricing.unity: ' . self::reason($check);
            } else {
                $plan['fields']['unity'] = trim($unity);
            }
        }

        if (!array_key_exists('id_tax_rules_group', $group)) {
            // What the back office preselects on a new product. Saying nothing
            // and storing no tax would sell the product without VAT.
            if ($creating) {
                $plan['fields']['id_tax_rules_group'] = (int) Product::getIdTaxRulesGroupMostUsed();
            }

            return;
        }
        $id = self::integer($group['id_tax_rules_group']);
        $rule = new TaxRulesGroup((int) $id);
        if ($id === null || $id < 0 || ($id > 0 && (!Validate::isLoadedObject($rule) || $rule->deleted))) {
            $errors[] = 'pricing.id_tax_rules_group: no such tax rule. Take identifiers from somcp_list_tax_rules, 0 for no tax.';

            return;
        }
        $plan['fields']['id_tax_rules_group'] = $id;
    }

    /**
     * @param array<string, mixed> $group
     * @param Product $probe
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function options(array $group, Product $probe, array &$plan, array &$errors): void
    {
        if (array_key_exists('visibility', $group)) {
            $visibility = self::string($group['visibility']);
            if ($visibility === null || $probe->validateField('visibility', $visibility) !== true) {
                $errors[] = 'options.visibility: both, catalog, search or none';
            } else {
                $plan['fields']['visibility'] = $visibility;
            }
        }
        foreach (['available_for_order', 'show_price', 'online_only', 'active'] as $field) {
            self::boolean($group, $field, 'options', $plan, $errors);
        }
    }

    /**
     * @param mixed $value
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<string, mixed> $stock the stock group, whose quantity cannot be given next to combinations
     * @param array<int, string> $errors
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function combinations($value, array $languages, array $stock, array &$errors): array
    {
        $items = self::items($value, 'combinations', $errors);
        if ($items === []) {
            return [];
        }
        if (array_key_exists('quantity', $stock)) {
            $errors[] = 'stock.quantity: with combinations, the product quantity is their total; give each combination its quantity';
        }

        $combinations = [];
        $sets = [];
        $defaults = 0;

        foreach ($items as $i => $item) {
            $path = 'combinations[' . $i . ']';
            self::onlyAccepted($item, 'combination', $path, $errors);

            $attributes = self::attributeSet($item['attributes'] ?? null, $path, $errors);
            $set = implode('-', $attributes);
            if ($set !== '' && isset($sets[$set])) {
                $errors[] = $path . ': the same attributes as combinations[' . $sets[$set] . ']';
            }
            $sets[$set] = $i;

            $combination = self::combinationItem($item, $path, $languages, ['minimal_quantity' => 1, 'default_on' => false], $errors);
            $combination['attributes'] = $attributes;
            if ($combination['fields']['default_on']) {
                ++$defaults;
            }

            $combinations[] = $combination;
        }

        if ($defaults > 1) {
            $errors[] = 'combinations: only one can be the default';
        } elseif ($defaults === 0) {
            // Never empty here: every item above adds one entry, and no items returned early.
            $combinations[0]['fields']['default_on'] = true;
        }

        return $combinations;
    }

    /**
     * The attribute values of one combination, checked and sorted.
     *
     * @param mixed $value
     * @param string $path
     * @param array<int, string> $errors
     *
     * @return array<int, int>
     */
    protected static function attributeSet($value, string $path, array &$errors): array
    {
        $attributes = array_values(array_unique(self::ids($value, 'id_attribute', $path . '.attributes', $errors)));
        if ($attributes === []) {
            $errors[] = $path . '.attributes: at least one attribute value, from somcp_list_attributes';
        }
        $groups = [];
        foreach ($attributes as $idAttribute) {
            $attribute = new ProductAttribute($idAttribute);
            if (!Validate::isLoadedObject($attribute)) {
                $errors[] = $path . '.attributes: no attribute value ' . $idAttribute . '. Take identifiers from somcp_list_attributes.';
                continue;
            }
            if (isset($groups[(int) $attribute->id_attribute_group])) {
                $errors[] = $path . '.attributes: two values of the same attribute group';
            }
            $groups[(int) $attribute->id_attribute_group] = true;
        }
        sort($attributes);

        return $attributes;
    }

    /**
     * The fields of one combination given in the call, on top of $fields.
     *
     * Only what the item names is set, so the same reading serves a new
     * combination, which starts from the defaults, and an existing one, which
     * starts from nothing and keeps whatever is left out.
     *
     * @param array<string, mixed> $item
     * @param string $path
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<string, mixed> $fields the values before this item
     * @param array<int, string> $errors
     *
     * @return array{fields: array<string, mixed>, texts: array<string, array<int, string>>, quantity: int|null, location: string|null}
     */
    protected static function combinationItem(array $item, string $path, array $languages, array $fields, array &$errors): array
    {
        $probe = new Combination();
        $combination = ['fields' => $fields, 'texts' => [], 'quantity' => null, 'location' => null];

        if (array_key_exists('default_on', $item)) {
            $default = self::bool($item['default_on']);
            if ($default === null) {
                $errors[] = $path . '.default_on: true or false';
            } else {
                $combination['fields']['default_on'] = $default;
            }
        }

        foreach (['reference', 'mpn', 'upc', 'ean13', 'isbn'] as $field) {
            if (!array_key_exists($field, $item)) {
                continue;
            }
            $code = self::string($item[$field]);
            $code = $code === null ? null : trim($code);
            if ($code === null) {
                $errors[] = $path . '.' . $field . ': a text';
            } elseif ($field === 'reference' && (Tools::strlen($code) > self::MAX_REFERENCE || !Validate::isReference($code))) {
                // Combination::$definition gives the reference no rule of
                // its own; the product's is the one the back office applies.
                $errors[] = $path . '.reference: ' . self::MAX_REFERENCE . ' characters at most, no <>;={}';
            } elseif (($check = $probe->validateField($field, $code)) !== true) {
                $errors[] = $path . '.' . $field . ': ' . self::reason($check);
            } else {
                $combination['fields'][$field] = $code;
            }
        }

        $numbers = [
            'price_impact_tax_excl' => 'price',
            'unit_price_impact_tax_excl' => 'unit_price_impact',
            'weight_impact' => 'weight',
            'wholesale_price' => 'wholesale_price',
        ];
        foreach ($numbers as $given => $field) {
            if (!array_key_exists($given, $item)) {
                continue;
            }
            $number = self::float($item[$given]);
            if ($number === null) {
                $errors[] = $path . '.' . $given . ': a number';
            } elseif (($check = $probe->validateField($field, $number)) !== true) {
                $errors[] = $path . '.' . $given . ': ' . self::reason($check);
            } else {
                $combination['fields'][$field] = $number;
            }
        }
        if (array_key_exists('minimal_quantity', $item)) {
            $minimal = self::integer($item['minimal_quantity']);
            if ($minimal === null || $minimal < 1 || $probe->validateField('minimal_quantity', $minimal) !== true) {
                $errors[] = $path . '.minimal_quantity: a whole number, 1 or more';
            } else {
                $combination['fields']['minimal_quantity'] = $minimal;
            }
        }
        self::lowStock($item, $path, $probe, $combination['fields'], $errors);
        if (array_key_exists('quantity', $item)) {
            $quantity = self::integer($item['quantity']);
            if ($quantity === null) {
                $errors[] = $path . '.quantity: a whole number';
            } else {
                $combination['quantity'] = $quantity;
            }
        }
        if (array_key_exists('location', $item)) {
            $combination['location'] = self::location($item['location'], $path . '.location', $errors);
        }
        if (array_key_exists('available_date', $item)) {
            $date = self::date($item['available_date'], $path . '.available_date', $errors);
            if ($date !== null) {
                $combination['fields']['available_date'] = $date;
            }
        }
        foreach (['available_now', 'available_later'] as $field) {
            if (!array_key_exists($field, $item)) {
                continue;
            }
            // PrestaShop 8.0 has no labels per combination: the table came
            // with 8.1. Dropping them silently would lose what the merchant
            // typed, so the call says so instead.
            if (!isset(Combination::$definition['fields']['available_now'])) {
                $errors[] = $path . '.' . $field . ': this PrestaShop version has no labels per combination (they came with 8.1)';
                continue;
            }
            $texts = self::texts($item, $field, $path . '.' . $field, $languages, $errors);
            foreach ($texts ?? [] as $idLang => $text) {
                if (($check = $probe->validateField($field, $text, $idLang)) !== true) {
                    $errors[] = $path . '.' . $field . ' (' . $languages['filled'][$idLang] . '): ' . self::reason($check);
                }
            }
            if ($texts !== null) {
                $combination['texts'][$field] = $texts;
            }
        }

        return $combination;
    }

    /**
     * @param array<string, mixed> $group
     * @param int $combinations
     * @param int $idShop
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function specificPrices(array $group, int $combinations, int $idShop, array &$plan, array &$errors): void
    {
        $plan['specific_prices'] = [];
        $plan['priorities'] = null;

        foreach (self::items($group['rules'] ?? null, 'specific_prices.rules', $errors) as $i => $item) {
            $path = 'specific_prices.rules[' . $i . ']';
            self::onlyAccepted($item, 'rule', $path, $errors);
            $plan['specific_prices'][] = self::rule($item, $path, [
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
            ], $combinations, $idShop, $errors);
        }

        $plan['priorities'] = self::priorities($group, $errors);
    }

    /**
     * One specific price: the values given in the call, on top of $rule.
     *
     * A new rule starts from the defaults, an existing one from what it holds,
     * so a value left out keeps its meaning in both cases; the checks that
     * span several fields run on the result.
     *
     * @param array<string, mixed> $item
     * @param string $path
     * @param array<string, mixed> $rule the values before this item
     * @param int $combinations
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>
     */
    protected static function rule(array $item, string $path, array $rule, int $combinations, int $idShop, array &$errors): array
    {
        if (isset($item['combination'])) {
            $rule['combination'] = self::position($item, 'combination', $combinations, $path, $errors);
        }
        if (isset($item['currency'])) {
            $rule['id_currency'] = self::currency($item['currency'], $idShop, $path . '.currency', $errors);
        }
        if (isset($item['country'])) {
            $iso = self::string($item['country']);
            // Country::getByIso() stops the request on a malformed code
            // rather than answering false, so the code is checked first.
            $idCountry = $iso !== null && Validate::isLanguageIsoCode($iso) ? (int) Country::getByIso($iso) : 0;
            if ($idCountry <= 0) {
                $errors[] = $path . '.country: no country with this ISO code';
            }
            $rule['id_country'] = $idCountry;
        }
        if (isset($item['id_group'])) {
            $idGroup = self::integer($item['id_group']);
            if ($idGroup === null || $idGroup < 0 || ($idGroup > 0 && !Validate::isLoadedObject(new Group($idGroup)))) {
                $errors[] = $path . '.id_group: no such customer group. Take identifiers from somcp_list_customer_groups, 0 for all.';
            }
            $rule['id_group'] = (int) $idGroup;
        }
        if (isset($item['id_customer'])) {
            $idCustomer = self::integer($item['id_customer']);
            if ($idCustomer === null || $idCustomer < 0 || ($idCustomer > 0 && !Customer::customerIdExistsStatic($idCustomer))) {
                $errors[] = $path . '.id_customer: no such customer, 0 for all';
            }
            $rule['id_customer'] = (int) $idCustomer;
        }
        if (isset($item['from_quantity'])) {
            $from = self::integer($item['from_quantity']);
            if ($from === null || $from < 1) {
                $errors[] = $path . '.from_quantity: a whole number, 1 or more';
            }
            $rule['from_quantity'] = (int) $from;
        }
        if (isset($item['price'])) {
            $price = self::float($item['price']);
            if ($price === null || ($price < 0 && $price !== -1.0)) {
                $errors[] = $path . '.price: a price tax excluded, or -1 to keep the product price';
            }
            $rule['price'] = (float) $price;
        }
        if (isset($item['reduction_type'])) {
            $type = self::string($item['reduction_type']);
            if (!in_array($type, ['amount', 'percentage'], true)) {
                $errors[] = $path . '.reduction_type: amount or percentage';
            }
            $rule['reduction_type'] = (string) $type;
        }
        if (isset($item['reduction'])) {
            $reduction = self::float($item['reduction']);
            if ($reduction === null || $reduction < 0) {
                $errors[] = $path . '.reduction: a positive number';
            }
            $rule['reduction'] = (float) $reduction;
        }
        // Checked on the result rather than on the item: an existing
        // percentage rule given only a new reduction is still a percentage.
        if ($rule['reduction_type'] === 'percentage' && (float) $rule['reduction'] > 1) {
            // Stored as a fraction, which is also how somcp_get_product
            // returns it. 20 here would be a 2000 % reduction.
            $errors[] = $path . '.reduction: for a percentage, the fraction - 0.2 for 20 %';
        }
        if (isset($item['reduction_tax'])) {
            $tax = self::bool($item['reduction_tax']);
            if ($tax === null) {
                $errors[] = $path . '.reduction_tax: true or false';
            }
            $rule['reduction_tax'] = $tax ? 1 : 0;
        }
        foreach (['from', 'to'] as $bound) {
            if (isset($item[$bound])) {
                $rule[$bound] = (string) self::dateTime($item[$bound], $path . '.' . $bound, $errors);
            }
        }
        if (strpos((string) $rule['to'], '0000') !== 0 && strpos((string) $rule['from'], '0000') !== 0 && $rule['to'] < $rule['from']) {
            $errors[] = $path . ': to comes before from';
        }
        if ((float) $rule['price'] < 0 && (float) $rule['reduction'] <= 0) {
            $errors[] = $path . ': neither a price nor a reduction, so the rule would change nothing';
        }
        if (($check = (new SpecificPrice())->validateField('reduction_type', $rule['reduction_type'])) !== true) {
            $errors[] = $path . '.reduction_type: ' . self::reason($check);
        }

        return $rule;
    }

    /**
     * The priorities between specific prices, or null when not given.
     *
     * @param array<string, mixed> $group
     * @param array<int, string> $errors
     *
     * @return array<int, string>|null
     */
    protected static function priorities(array $group, array &$errors): ?array
    {
        if (!array_key_exists('priorities', $group)) {
            return null;
        }
        $priorities = is_array($group['priorities']) ? array_values(array_filter($group['priorities'], static function ($value): bool {
            // somcp_get_product lists id_customer first, because the core
            // always puts it there; it is not the merchant's to order.
            return $value !== 'id_customer';
        })) : [];
        $sorted = $priorities;
        sort($sorted);
        $expected = self::PRIORITIES;
        sort($expected);
        if ($sorted !== $expected) {
            $errors[] = 'specific_prices.priorities: each of ' . implode(', ', self::PRIORITIES) . ' once, in the order wanted';

            return null;
        }

        return $priorities;
    }

    /**
     * @param array<string, mixed> $group
     * @param int $combinations
     * @param int $idShop
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function suppliers(array $group, int $combinations, int $idShop, array &$plan, array &$errors): void
    {
        $rows = [];
        $seen = [];
        $known = [];
        $defaultCurrency = (int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop);

        foreach (self::items($group['suppliers'] ?? null, 'suppliers.suppliers', $errors) as $i => $item) {
            $path = 'suppliers.suppliers[' . $i . ']';
            self::onlyAccepted($item, 'supplier', $path, $errors);
            $idSupplier = self::integer($item['id_supplier'] ?? null);
            if ($idSupplier === null || !self::supplierExists($idSupplier, $known)) {
                $errors[] = $path . '.id_supplier: no such supplier. Take identifiers from somcp_list_suppliers.';
                continue;
            }
            $combination = self::position($item, 'combination', $combinations, $path, $errors);
            $key = $idSupplier . '-' . ($combination ?? 'product');
            if (isset($seen[$key])) {
                $errors[] = $path . ': this supplier is given twice for the same ' . ($combination === null ? 'product' : 'combination');
                continue;
            }
            $seen[$key] = true;

            $rows[] = self::supplierValues($item, $path, [
                'id_supplier' => $idSupplier,
                'combination' => $combination,
                'product_supplier_reference' => '',
                'product_supplier_price_te' => 0.0,
                'id_currency' => $defaultCurrency,
            ], $idShop, $errors);
        }

        $default = null;
        if (array_key_exists('id_supplier_default', $group)) {
            $default = self::integer($group['id_supplier_default']);
            if ($default === null || $default <= 0 || !self::supplierExists($default, $known)) {
                $errors[] = 'suppliers.id_supplier_default: no such supplier. Take identifiers from somcp_list_suppliers.';
                $default = null;
            }
        }
        if ($default === null && $rows !== []) {
            $default = (int) $rows[0]['id_supplier'];
        }

        // Each supplier is associated with the product itself as well as with
        // the combinations given: that row is what the back office lists, and
        // what makes it a supplier of the product at all.
        $associated = [];
        foreach ($rows as $row) {
            if ($row['combination'] === null) {
                $associated[$row['id_supplier']] = true;
            }
        }
        foreach (array_unique(array_merge(array_column($rows, 'id_supplier'), $default === null ? [] : [$default])) as $idSupplier) {
            if (!isset($associated[$idSupplier])) {
                $rows[] = [
                    'id_supplier' => (int) $idSupplier,
                    'combination' => null,
                    'product_supplier_reference' => '',
                    'product_supplier_price_te' => 0.0,
                    'id_currency' => $defaultCurrency,
                ];
            }
        }

        $plan['suppliers'] = $rows;
        if ($default !== null) {
            $plan['fields']['id_supplier'] = $default;
        }
    }

    /**
     * The values of one supplier row given in the call, on top of $row.
     *
     * @param array<string, mixed> $item
     * @param string $path
     * @param array<string, mixed> $row the values before this item
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>
     */
    protected static function supplierValues(array $item, string $path, array $row, int $idShop, array &$errors): array
    {
        $probe = new ProductSupplier();
        if (array_key_exists('product_supplier_reference', $item)) {
            $reference = self::string($item['product_supplier_reference']);
            if ($reference === null) {
                $errors[] = $path . '.product_supplier_reference: a text';
            } elseif (($check = $probe->validateField('product_supplier_reference', trim($reference))) !== true) {
                $errors[] = $path . '.product_supplier_reference: ' . self::reason($check);
            } else {
                $row['product_supplier_reference'] = trim($reference);
            }
        }
        if (array_key_exists('product_supplier_price_te', $item)) {
            $price = self::float($item['product_supplier_price_te']);
            if ($price === null || $probe->validateField('product_supplier_price_te', $price) !== true) {
                $errors[] = $path . '.product_supplier_price_te: a price tax excluded';
            } else {
                $row['product_supplier_price_te'] = $price;
            }
        }
        if (isset($item['currency'])) {
            $row['id_currency'] = self::currency($item['currency'], $idShop, $path . '.currency', $errors);
        }

        return $row;
    }

    /**
     * @param mixed $value
     * @param int $combinations
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<int, string> $errors
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function imagePlan($value, int $combinations, array $languages, array &$errors): array
    {
        $images = [];
        $covers = 0;
        foreach (self::items($value, 'images', $errors) as $i => $item) {
            $path = 'images[' . $i . ']';
            self::onlyAccepted($item, 'image', $path, $errors);
            $source = self::imageSource($item, $path, $errors);
            if ($source === null) {
                continue;
            }

            $fields = self::imageFields($item, $path, $combinations, $languages, $errors);
            if ($fields['cover'] === true) {
                ++$covers;
            }

            $images[] = $source + [
                'legend' => $fields['legend'],
                'cover' => (bool) $fields['cover'],
                'order' => [$fields['position'] ?? PHP_INT_MAX, $i],
                'combinations' => $fields['combinations'] ?? [],
            ];
        }
        if ($covers > 1) {
            $errors[] = 'images: only one can be the cover';
        }

        // Positions given first, in their order, then the others as listed.
        usort($images, static function (array $a, array $b): int {
            return $a['order'] <=> $b['order'];
        });

        return $images;
    }

    /**
     * Where one image comes from: a public address the shop downloads, or
     * the reference of a file already on the shop. One of the two, never
     * both, and never the bytes themselves - the two doors somcp lets a file
     * in through.
     *
     * @param array<string, mixed> $item
     * @param string $path
     * @param array<int, string> $errors
     * @param string $what what the file is, for the message
     *
     * @return array{url: string, upload_ref: string}|null
     */
    protected static function imageSource(array $item, string $path, array &$errors, string $what = 'image'): ?array
    {
        $url = trim((string) (self::string($item['url'] ?? null) ?? ''));
        $reference = trim((string) (self::string($item[SomcpUpload::ARG_REF] ?? null) ?? ''));
        if ($url !== '' && $reference !== '') {
            $errors[] = $path . ': url or upload_ref, not both';

            return null;
        }
        if ($url === '' && $reference === '') {
            $errors[] = $path . ': the public https address of the ' . $what . ' (url), or the upload_ref of a file on the shop';

            return null;
        }

        return ['url' => $url, 'upload_ref' => $reference];
    }

    /**
     * How an image is named in the answer: its address, or its reference.
     *
     * @param array<string, mixed> $image
     *
     * @return array<string, string>
     */
    protected static function imageOrigin(array $image): array
    {
        return ($image['upload_ref'] ?? '') !== '' ? ['upload_ref' => (string) $image['upload_ref']] : ['url' => (string) $image['url']];
    }

    /**
     * What one image item says besides its address, each null when left out.
     *
     * @param array<string, mixed> $item
     * @param string $path
     * @param int $combinations
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<int, string> $errors
     *
     * @return array{legend: array<int, string>|null, cover: bool|null, position: int|null, combinations: array<int, int>|null}
     */
    protected static function imageFields(array $item, string $path, int $combinations, array $languages, array &$errors): array
    {
        $probe = new Image();
        $legends = self::texts($item, 'legend', $path . '.legend', $languages, $errors);
        foreach ($legends ?? [] as $idLang => $legend) {
            if (($check = $probe->validateField('legend', $legend, $idLang)) !== true) {
                $errors[] = $path . '.legend (' . $languages['filled'][$idLang] . '): ' . self::reason($check);
            }
        }

        $cover = null;
        if (array_key_exists('cover', $item)) {
            $cover = self::bool($item['cover']);
            if ($cover === null) {
                $errors[] = $path . '.cover: true or false';
            }
        }

        $position = null;
        if (array_key_exists('position', $item)) {
            $position = self::integer($item['position']);
            if ($position === null || $position < 1) {
                $errors[] = $path . '.position: a whole number from 1';
                $position = null;
            }
        }

        $illustrated = null;
        if (array_key_exists('combinations', $item)) {
            $illustrated = [];
            foreach (self::items($item['combinations'], $path . '.combinations', $errors, false) as $index) {
                $index = self::integer($index);
                if ($index === null || $index < 0 || $index >= $combinations) {
                    $errors[] = $path . '.combinations: not a position in combinations (from 0 to ' . max(0, $combinations - 1) . ')';
                    continue;
                }
                $illustrated[] = $index;
            }
            $illustrated = array_values(array_unique($illustrated));
        }

        return ['legend' => $legends, 'cover' => $cover, 'position' => $position, 'combinations' => $illustrated];
    }

    /**
     * @param array<string, mixed> $group
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idShop
     * @param Product $probe
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function seo(array $group, array $languages, int $idShop, Product $probe, array &$plan, array &$errors): void
    {
        self::metaTexts($group, $languages, $probe, $plan, $errors);
        $plan['tags'] = self::tagPlan($group, $languages, $errors);

        // The friendly URL is never empty in any language: given, it goes
        // through the same conversion the back office applies; left out, it is
        // made from the name.
        $given = self::texts($group, 'link_rewrite', 'seo.link_rewrite', $languages, $errors) ?? [];
        $sources = [];
        foreach (array_keys($languages['filled']) as $idLang) {
            if (isset($given[$idLang])) {
                $sources[$idLang] = [$given[$idLang], 'it'];
            } elseif (isset($plan['texts']['name'][$idLang])) {
                $sources[$idLang] = [$plan['texts']['name'][$idLang], 'the name'];
            }
        }
        $plan['texts']['link_rewrite'] = self::linkRewrites($sources, $languages, $probe, $errors);

        if (array_key_exists('redirect_type', $group)) {
            $type = self::redirectType($group['redirect_type'], $errors);
            if ($type !== null) {
                $plan['fields']['redirect_type'] = $type;
            }
        }
        $target = array_key_exists('id_type_redirected', $group) ? self::integer($group['id_type_redirected']) : 0;
        if ($target === null || $target < 0) {
            $errors[] = 'seo.id_type_redirected: an identifier';
            $target = 0;
        }
        self::redirection((string) ($plan['fields']['redirect_type'] ?? ''), $target, $idShop, $errors);
        if ($target > 0) {
            $plan['fields']['id_type_redirected'] = $target;
        }
    }

    /**
     * The meta title, description and keywords given in the call.
     *
     * @param array<string, mixed> $group
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param Product $probe
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function metaTexts(array $group, array $languages, Product $probe, array &$plan, array &$errors): void
    {
        $limits = ['meta_title' => self::META_TITLE_MAX, 'meta_description' => self::META_DESCRIPTION_MAX];
        foreach ($limits as $field => $max) {
            $texts = self::texts($group, $field, 'seo.' . $field, $languages, $errors);
            foreach ($texts ?? [] as $idLang => $text) {
                $text = trim($text);
                $texts[$idLang] = $text;
                $where = 'seo.' . $field . ' (' . $languages['filled'][$idLang] . ')';
                // Refused rather than cut: a title cut by the tool is a title
                // nobody wrote, and the merchant would not know it changed.
                if (self::visibleLength($text) > $max) {
                    $errors[] = $where . ': ' . $max . ' characters at most, ' . self::visibleLength($text) . ' given';
                } elseif (($check = $probe->validateField($field, $text, $idLang)) !== true) {
                    $errors[] = $where . ': ' . self::reason($check);
                }
            }
            if ($texts !== null) {
                $plan['texts'][$field] = $texts;
            }
        }

        if (array_key_exists('meta_keywords', $group)) {
            if (!isset(Product::$definition['fields']['meta_keywords'])) {
                // PrestaShop 9 dropped the column. Accepting the field and
                // storing nothing would tell the merchant something false.
                $errors[] = 'seo.meta_keywords: this PrestaShop version no longer has meta keywords; leave the field out';
            } else {
                $texts = self::texts($group, 'meta_keywords', 'seo.meta_keywords', $languages, $errors);
                foreach ($texts ?? [] as $idLang => $text) {
                    if (($check = $probe->validateField('meta_keywords', $text, $idLang)) !== true) {
                        $errors[] = 'seo.meta_keywords (' . $languages['filled'][$idLang] . '): ' . self::reason($check);
                    }
                }
                if ($texts !== null) {
                    $plan['texts']['meta_keywords'] = $texts;
                }
            }
        }
    }

    /**
     * The product tags given in the call, by language, validated so the native
     * writer never meets a bad one.
     *
     * Tags are their own objects (Tag::addTags), not a field of the product, so
     * they are planned here and written after the product exists. Each entry is
     * split on the comma, trimmed and de-duplicated; a tag too long or holding a
     * character the shop refuses is reported rather than silently cut, as the
     * meta texts are. An empty list in a language writes nothing for it.
     *
     * @param array<string, mixed> $group
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<int, string> $errors
     *
     * @return array<int, array<int, string>> the tags to write, by language id
     */
    protected static function tagPlan(array $group, array $languages, array &$errors): array
    {
        $texts = self::texts($group, 'tags', 'seo.tags', $languages, $errors);
        if ($texts === null) {
            return [];
        }

        $max = (int) Tag::$definition['fields']['name']['size'];
        $plan = [];
        foreach ($texts as $idLang => $text) {
            $tags = [];
            foreach (explode(',', $text) as $tag) {
                $tag = trim($tag);
                if ($tag === '' || in_array($tag, $tags, true)) {
                    continue;
                }
                $where = 'seo.tags (' . $languages['filled'][$idLang] . ')';
                if (Tools::strlen($tag) > $max) {
                    $errors[] = $where . ': "' . $tag . '" is longer than ' . $max . ' characters';
                } elseif (!Validate::isGenericName($tag)) {
                    $errors[] = $where . ': "' . $tag . '" cannot be a tag; < > = # { } are not allowed';
                } else {
                    $tags[] = $tag;
                }
            }
            if ($tags !== []) {
                $plan[$idLang] = $tags;
            }
        }

        return $plan;
    }

    /**
     * Friendly URLs, converted the way the back office converts them.
     *
     * @param array<int, array{0: string, 1: string}> $sources by language: the text to convert, and what it is
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param Product $probe
     * @param array<int, string> $errors
     *
     * @return array<int, string>
     */
    protected static function linkRewrites(array $sources, array $languages, Product $probe, array &$errors): array
    {
        $rewrites = [];
        foreach ($sources as $idLang => [$source, $what]) {
            $rewrite = (string) Tools::str2url($source);
            $where = 'seo.link_rewrite (' . $languages['filled'][$idLang] . ')';
            if ($rewrite === '') {
                $errors[] = $where . ': nothing usable could be made from ' . $what . '; give one';
                continue;
            }
            if (!Validate::isLinkRewrite($rewrite) || ($check = $probe->validateField('link_rewrite', $rewrite, $idLang)) !== true) {
                $errors[] = $where . ': "' . $rewrite . '" is not a valid friendly URL';
                continue;
            }
            $rewrites[$idLang] = $rewrite;
        }

        return $rewrites;
    }

    /**
     * @param mixed $value
     * @param array<int, string> $errors
     *
     * @return string|null
     */
    protected static function redirectType($value, array &$errors): ?string
    {
        $types = array_values(PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\RedirectType::AVAILABLE_REDIRECT_TYPES);
        $type = self::string($value);
        if ($type === null || !in_array($type, $types, true)) {
            $errors[] = 'seo.redirect_type: one of ' . implode(', ', $types) . ' on this version';

            return null;
        }

        return $type;
    }

    /**
     * Whether a redirection and its target go together.
     *
     * @param string $type
     * @param int $target
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function redirection(string $type, int $target, int $idShop, array &$errors): void
    {
        if (in_array($type, ['301-product', '302-product'], true)) {
            $exists = $target > 0 && (bool) Db::getInstance()->getValue(
                'SELECT 1 FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `id_product` = ' . (int) $target . ' AND `id_shop` = ' . (int) $idShop
            );
            if (!$exists) {
                $errors[] = 'seo.id_type_redirected: a ' . $type . ' needs an existing product of this shop';
            }
        } elseif (in_array($type, ['301-category', '302-category'], true)) {
            $category = new Category($target);
            if ($target <= 0 || !Validate::isLoadedObject($category) || !$category->isAssociatedToShop($idShop)) {
                $errors[] = 'seo.id_type_redirected: a ' . $type . ' needs an existing category of this shop';
            }
        } elseif ($target > 0) {
            $errors[] = 'seo.id_type_redirected: only a 301 or 302 redirection goes somewhere';
        }
    }

    /**
     * References used by no other product or combination, and not twice in this call.
     *
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function uniqueReferences(array $plan, array &$errors): void
    {
        $references = [];
        if (($plan['fields']['reference'] ?? '') !== '') {
            $references['references.reference'] = (string) $plan['fields']['reference'];
        }
        foreach ($plan['combinations'] as $i => $combination) {
            if (($combination['fields']['reference'] ?? '') !== '') {
                $references['combinations[' . $i . '].reference'] = (string) $combination['fields']['reference'];
            }
        }

        self::referencesFree($references, 0, $errors);
    }

    /**
     * References given once in the call, and used by no other product or its combinations.
     *
     * @param array<string, string> $references by the path they were given at
     * @param int $idProduct the product being changed, whose own references do not count; 0 for a new one
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function referencesFree(array $references, int $idProduct, array &$errors): void
    {
        $counts = array_count_values($references);
        foreach ($references as $path => $reference) {
            if ($counts[$reference] > 1) {
                $errors[] = $path . ': "' . $reference . '" is given more than once in this call';
                continue;
            }
            $taken = Db::getInstance()->getValue(
                'SELECT 1 FROM `' . _DB_PREFIX_ . "product` WHERE `reference` = '" . pSQL($reference) . "'"
                . ' AND `id_product` != ' . (int) $idProduct
                . ' UNION SELECT 1 FROM `' . _DB_PREFIX_ . "product_attribute` WHERE `reference` = '" . pSQL($reference) . "'"
                . ' AND `id_product` != ' . (int) $idProduct
            );
            if ($taken) {
                $errors[] = $path . ': "' . $reference . '" is already used by another product or combination'
                    . ($idProduct > 0 ? '.' : '. If this product was just created by a call whose answer was lost, find it with somcp_search_products.');
            }
        }
    }

    /**
     * Writes everything but the images, or throws with what failed.
     *
     * @param Product $product
     * @param array<string, mixed> $plan
     * @param int $idShop
     *
     * @return array<int, int> combination identifiers, by position
     */
    protected static function write(Product $product, array $plan, int $idShop): array
    {
        $languages = $plan['languages'];
        self::fill($product, $plan['fields'] + self::completeTexts($plan['texts'], $languages));
        // The shop of the token only: the context of a back office request
        // could name several, and Product::add() would follow it.
        $product->id_shop_list = [$idShop];
        if (!$product->add()) {
            throw new RuntimeException('the shop refused the product itself');
        }
        $idProduct = (int) $product->id;

        $product->addToCategories($plan['categories']);

        // Tags are written per language with the product's own writer, which
        // creates the ones the shop does not have yet and links them. Validated
        // in the plan, so a language that reaches here has only usable tags.
        foreach ($plan['tags'] as $idLang => $tags) {
            if (!Tag::addTags($idLang, $idProduct, $tags, ',')) {
                throw new RuntimeException('the tags could not be saved');
            }
        }

        // Out of stock before the combinations, which copy the product's
        // setting as they are created; location before them too, because the
        // core writes a product's location on every row it has.
        if ($plan['stock']['out_of_stock'] !== null) {
            StockAvailable::setProductOutOfStock($idProduct, $plan['stock']['out_of_stock'], $idShop);
        }
        if ($plan['stock']['location'] !== null) {
            StockAvailable::setLocation($idProduct, $plan['stock']['location'], $idShop);
        }

        if ($plan['carriers'] !== null && $plan['carriers'] !== []) {
            $product->setCarriers($plan['carriers']);
        }
        if ($plan['related_products'] !== []) {
            $product->changeAccessories($plan['related_products']);
        }
        if ($plan['attachments'] !== null && $plan['attachments'] !== []) {
            if (!Attachment::attachToProduct($idProduct, $plan['attachments'])) {
                throw new RuntimeException('the documents could not be attached');
            }
        }
        self::writeFeatures($idProduct, $plan['features'], $languages);
        self::writeCustomization($idProduct, $plan['customization'], $languages, $idShop);

        $combinationIds = self::writeCombinations($product, $plan['combinations'], $languages, $idShop);
        self::writeSuppliers($idProduct, $plan['suppliers'], $combinationIds);
        self::writeSpecificPrices($idProduct, $plan, $combinationIds, $idShop);

        // Quantities last: each one records a stock movement, and a movement
        // written before a later step failed would outlive the rollback.
        if ($plan['stock']['quantity'] !== null) {
            StockAvailable::setQuantity($idProduct, 0, $plan['stock']['quantity'], $idShop);
        }
        foreach ($plan['combinations'] as $i => $combination) {
            if ($combination['quantity'] !== null) {
                StockAvailable::setQuantity($idProduct, $combinationIds[$i], $combination['quantity'], $idShop);
            }
        }

        // What the back office does on saving a product that can be found by
        // the search: an inactive or hidden one is indexed when it goes live.
        if ($product->active && in_array($product->visibility, ['both', 'search'], true) && Configuration::get('PS_SEARCH_INDEXATION')) {
            Search::indexation(false, $idProduct);
        }

        return $combinationIds;
    }

    /**
     * @param int $idProduct
     * @param array<int, array{id_feature: int, id_feature_value: int, value: array<int, string>|null}> $features
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     *
     * @return void
     */
    protected static function writeFeatures(int $idProduct, array $features, array $languages): void
    {
        foreach ($features as $feature) {
            $idValue = $feature['id_feature_value'];
            if ($feature['value'] !== null) {
                // A custom value is a value of its own, flagged custom, which
                // Product::delete() removes with the product.
                $value = new FeatureValue();
                self::fill($value, [
                    'id_feature' => $feature['id_feature'],
                    'custom' => true,
                    'value' => self::complete($feature['value'], $languages),
                ]);
                if (!$value->add()) {
                    throw new RuntimeException('a custom feature value could not be saved');
                }
                $idValue = (int) $value->id;
            }
            if (!Product::addFeatureProductImport($idProduct, $feature['id_feature'], $idValue)) {
                throw new RuntimeException('feature ' . $feature['id_feature'] . ' could not be set');
            }
        }
    }

    /**
     * @param int $idProduct
     * @param array<int, array{type: int, required: bool, name: array<int, string>}> $fields
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idShop
     *
     * @return void
     */
    protected static function writeCustomization(int $idProduct, array $fields, array $languages, int $idShop): void
    {
        foreach ($fields as $field) {
            $customization = new CustomizationField();
            self::fill($customization, [
                'id_product' => $idProduct,
                'type' => $field['type'],
                'required' => $field['required'],
                'is_module' => false,
                'name' => self::complete($field['name'], $languages),
            ]);
            $customization->id_shop_list = [$idShop];
            if (!$customization->add()) {
                throw new RuntimeException('a customization field could not be saved');
            }
        }
    }

    /**
     * @param Product $product
     * @param array<int, array<string, mixed>> $combinations
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idShop
     *
     * @return array<int, int>
     */
    protected static function writeCombinations(Product $product, array $combinations, array $languages, int $idShop): array
    {
        $ids = [];
        $default = 0;
        foreach ($combinations as $i => $plan) {
            $combination = new Combination();
            $texts = [];
            foreach ($plan['texts'] as $field => $values) {
                $texts[$field] = self::complete($values, $languages);
            }
            self::fill($combination, ['id_product' => (int) $product->id] + $plan['fields'] + $texts);
            $combination->id_shop_list = [$idShop];
            if (!$combination->add()) {
                throw new RuntimeException('combination ' . $i . ' could not be saved');
            }
            if (!$combination->setAttributes($plan['attributes'])) {
                throw new RuntimeException('the attributes of combination ' . $i . ' could not be saved');
            }
            if ($plan['location'] !== null) {
                StockAvailable::setLocation((int) $product->id, $plan['location'], $idShop, (int) $combination->id);
            }
            $ids[$i] = (int) $combination->id;
            if ($plan['fields']['default_on']) {
                $default = (int) $combination->id;
            }
        }

        if ($default > 0) {
            // The back office's own way to name the default, which also keeps
            // the product's cached default combination in step.
            $product->deleteDefaultAttributes();
            $product->setDefaultAttribute($default);
        }

        return $ids;
    }

    /**
     * @param int $idProduct
     * @param array<int, array<string, mixed>> $suppliers
     * @param array<int, int> $combinationIds
     *
     * @return void
     */
    protected static function writeSuppliers(int $idProduct, array $suppliers, array $combinationIds): void
    {
        foreach ($suppliers as $row) {
            $supplier = new ProductSupplier();
            self::fill($supplier, [
                'id_product' => $idProduct,
                'id_product_attribute' => $row['combination'] === null ? 0 : $combinationIds[$row['combination']],
                'id_supplier' => $row['id_supplier'],
                'product_supplier_reference' => $row['product_supplier_reference'],
                'product_supplier_price_te' => $row['product_supplier_price_te'],
                'id_currency' => $row['id_currency'],
            ]);
            if (!$supplier->add()) {
                throw new RuntimeException('supplier ' . $row['id_supplier'] . ' could not be associated');
            }
        }
    }

    /**
     * @param int $idProduct
     * @param array<string, mixed> $plan
     * @param array<int, int> $combinationIds
     * @param int $idShop
     *
     * @return void
     */
    protected static function writeSpecificPrices(int $idProduct, array $plan, array $combinationIds, int $idShop): void
    {
        foreach ($plan['specific_prices'] as $i => $rule) {
            $price = new SpecificPrice();
            $combination = $rule['combination'];
            unset($rule['combination']);
            self::fill($price, [
                'id_product' => $idProduct,
                'id_product_attribute' => $combination === null ? 0 : $combinationIds[$combination],
                // Bound to the shop of the token, like everything else here.
                'id_shop' => $idShop,
                'id_shop_group' => 0,
                'id_cart' => 0,
                'id_specific_price_rule' => 0,
            ] + $rule);
            if (!$price->add()) {
                throw new RuntimeException('specific price ' . $i . ' could not be saved');
            }
        }
        if ($plan['priorities'] !== null && !SpecificPrice::setSpecificPriority($idProduct, $plan['priorities'])) {
            throw new RuntimeException('the specific price priorities could not be saved');
        }
    }

    /**
     * Deletes a product that could not be written whole.
     *
     * @param Product $product
     *
     * @return bool true when a product was left behind
     */
    protected static function rollback(Product $product): bool
    {
        if ((int) $product->id <= 0) {
            return false;
        }

        try {
            // Product::delete() removes its combinations, stock, prices,
            // features, customization fields, suppliers and associations.
            return !$product->delete();
        } catch (Throwable $e) {
            return true;
        }
    }

    /**
     * Downloads the images, and reports each one rather than failing.
     *
     * @param Product $product
     * @param array<string, mixed> $plan
     * @param array<int, int> $combinationIds
     * @param int $idShop
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function images(Product $product, array $plan, array $combinationIds, int $idShop): array
    {
        $report = [];
        $created = [];
        $illustrates = [];
        $position = 0;

        foreach ($plan['images'] as $image) {
            try {
                $idImage = self::addImage($product, $image, ++$position, $plan['languages'], $idShop, $refusal);
            } catch (Throwable $e) {
                $idImage = 0;
                $refusal = 'the shop could not save it';
            }
            if ($idImage <= 0) {
                --$position;
                $report[] = self::imageOrigin($image) + ['refused' => $refusal];
                continue;
            }

            $report[] = self::imageOrigin($image) + ['id_image' => $idImage];
            $created[] = $idImage;
            foreach ($image['combinations'] as $index) {
                $illustrates[$combinationIds[$index]][] = $idImage;
            }
        }

        // The cover is chosen once every download is known: the one asked
        // for if it arrived, the first image that did otherwise.
        $wanted = null;
        foreach ($plan['images'] as $i => $image) {
            if ($image['cover'] && isset($report[$i]['id_image'])) {
                $wanted = (int) $report[$i]['id_image'];
            }
        }
        $cover = $wanted ?? ($created[0] ?? null);
        if ($cover !== null) {
            $image = new Image($cover);
            $image->cover = true;
            $image->id_shop_list = [$idShop];
            $image->update();
        }

        foreach ($illustrates as $idCombination => $ids) {
            (new Combination($idCombination))->setImages($ids);
        }

        return $report;
    }

    /**
     * One image: downloaded, checked, saved with every thumbnail.
     *
     * @param Product $product
     * @param array<string, mixed> $plan
     * @param int $position
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idShop
     * @param string|null $refusal why it was refused, when it was
     *
     * @return int the image identifier, 0 when refused
     */
    protected static function addImage(Product $product, array $plan, int $position, array $languages, int $idShop, ?string &$refusal): int
    {
        $refusal = null;
        $file = tempnam(_PS_TMP_IMG_DIR_, 'somcp');
        if ($file === false) {
            $refusal = 'the shop has no room to receive it';

            return 0;
        }

        try {
            $refusal = ($plan['upload_ref'] ?? '') !== ''
                ? self::fromDeposit((string) $plan['upload_ref'], $file)
                : self::download((string) $plan['url'], $file);
            if ($refusal === '') {
                $refusal = self::checkImage($file);
            }
            if ($refusal !== '') {
                return 0;
            }

            $image = new Image();
            self::fill($image, [
                'id_product' => (int) $product->id,
                'position' => $position,
                'cover' => false,
                'legend' => $plan['legend'] === null ? [] : self::complete($plan['legend'], $languages),
            ]);
            $image->id_shop_list = [$idShop];
            if (!$image->add()) {
                $refusal = 'the shop could not save it';

                return 0;
            }

            $path = (string) $image->getPathForCreation();
            if ($path === '' || !ImageManager::resize($file, $path . '.jpg')) {
                $image->delete();
                $refusal = 'the shop could not read it as an image';

                return 0;
            }
            self::thumbnails($path . '.jpg', $path);

            Hook::exec('actionWatermark', ['id_image' => (int) $image->id, 'id_product' => (int) $product->id]);
            foreach (['product_mini_' . (int) $product->id . '.jpg', 'product_mini_' . (int) $product->id . '_' . $idShop . '.jpg'] as $cached) {
                if (is_file(_PS_TMP_IMG_DIR_ . $cached)) {
                    @unlink(_PS_TMP_IMG_DIR_ . $cached);
                }
            }

            return (int) $image->id;
        } finally {
            @unlink($file);
        }
    }

    /**
     * Puts the products in the pack, and reports each one rather than failing.
     *
     * Written after the product, like the images: the pack exists by then,
     * and a row the shop refuses is said in the answer instead of undoing
     * the whole product.
     *
     * @param int $idProduct
     * @param array<int, array{id_product: int, id_product_attribute: int, quantity: int}> $items
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function writePack(int $idProduct, array $items): array
    {
        $report = [];
        foreach ($items as $item) {
            try {
                $added = Pack::addItem($idProduct, $item['id_product'], $item['quantity'], $item['id_product_attribute']);
            } catch (Throwable $e) {
                $added = false;
            }
            $report[] = $item + ($added ? ['added' => true] : ['refused' => 'the shop could not save it']);
        }

        return $report;
    }

    /**
     * Stores the downloadable file of a virtual product, as the back office does.
     *
     * The file goes under the download folder with a name drawn by the core:
     * that name is what lets a customer download it, so it is never returned.
     *
     * @param int $idProduct
     * @param array<string, mixed> $plan
     *
     * @return array<string, mixed>
     */
    protected static function writeVirtualFile(int $idProduct, array $plan): array
    {
        $origin = self::imageOrigin($plan['source']);
        $filename = '';

        try {
            $refusal = self::receiveFile($plan['source'], $filename);
            if ($refusal === '') {
                $download = new ProductDownload();
                self::fill($download, $plan['fields'] + [
                    'id_product' => $idProduct,
                    'filename' => $filename,
                    'date_add' => date('Y-m-d H:i:s'),
                    'nb_downloadable' => 0,
                    'nb_days_accessible' => 0,
                    'active' => true,
                    'is_shareable' => false,
                ]);
                if (!$download->add()) {
                    $refusal = 'the shop could not save it';
                }
            }
        } catch (Throwable $e) {
            $refusal = 'the shop could not save it';
        }
        if ($refusal !== '') {
            self::dropDownload($filename);

            return $origin + ['refused' => $refusal];
        }

        return $origin + ['added' => true];
    }

    /**
     * Brings a downloadable file into the download folder, under a name drawn by the core.
     *
     * @param array{url: string, upload_ref: string} $source
     * @param string $filename the name on disk, set even when the file is refused so it can be cleared
     *
     * @return string empty on success, the reason otherwise
     */
    protected static function receiveFile(array $source, string &$filename): string
    {
        $filename = ProductDownload::getNewFilename();
        $target = _PS_DOWNLOAD_DIR_ . $filename;
        // The limit the back office puts on a virtual product's file, in MB.
        $maxBytes = (int) Configuration::get('PS_ATTACHMENT_MAXIMUM_SIZE') * 1048576 ?: self::IMAGE_MAX_BYTES;

        return $source['upload_ref'] !== ''
            ? self::fromDeposit((string) $source['upload_ref'], $target, $maxBytes)
            : self::download((string) $source['url'], $target, $maxBytes);
    }

    /**
     * Clears a downloadable file that was drawn a name but is not kept.
     *
     * A refused file may never have reached the disk (an upload_ref that
     * matches nothing), so it is only removed when it is there.
     *
     * @param string $filename the name on disk, empty when none was drawn
     *
     * @return void
     */
    protected static function dropDownload(string $filename): void
    {
        if ($filename !== '' && is_file(_PS_DOWNLOAD_DIR_ . $filename)) {
            @unlink(_PS_DOWNLOAD_DIR_ . $filename);
        }
    }

    /**
     * Every thumbnail of the image types of one kind of object, as the back office makes them.
     *
     * @param string $source the original, already written as JPEG
     * @param string $path the image path without extension
     * @param string $for the image types to make: products, or categories for somcp_create_category
     *
     * @return void
     */
    protected static function thumbnails(string $source, string $path, string $for = 'products'): void
    {
        // PrestaShop 8.1 added image formats besides JPEG, chosen in the
        // settings; the JPEG is always made, the others when switched on.
        $formats = ['jpg'];
        if (class_exists('PrestaShop\PrestaShop\Core\Image\ImageFormatConfiguration')) {
            foreach (explode(',', (string) Configuration::get('PS_IMAGE_FORMAT')) as $format) {
                if (in_array($format, ['png', 'webp', 'avif'], true)) {
                    $formats[] = $format;
                }
            }
        }

        foreach (ImageType::getImagesTypes($for, true) ?: [] as $type) {
            foreach ($formats as $format) {
                $error = 0;
                $targetWidth = $targetHeight = $sourceWidth = $sourceHeight = null;
                $arguments = [
                    $source,
                    $path . '-' . stripslashes((string) $type['name']) . '.' . $format,
                    (int) $type['width'],
                    (int) $type['height'],
                    $format,
                    false,
                    &$error,
                    &$targetWidth,
                    &$targetHeight,
                    5,
                    &$sourceWidth,
                    &$sourceHeight,
                ];
                // PrestaShop 9 lets each image type say how the picture fits
                // its frame, as a thirteenth argument PrestaShop 8 does not
                // have. Passed through an array so neither version sees a call
                // that does not match its own signature.
                if (isset($type['image_fitment'])) {
                    $arguments[] = (string) $type['image_fitment'];
                }
                call_user_func_array(['ImageManager', 'resize'], $arguments);
            }
        }
    }

    /**
     * Fetches a merchant's image without letting the address reach inside.
     *
     * This is a request the shop makes on the strength of a URL typed in a
     * conversation, so it is held to what such a request needs: https only,
     * an address that resolves to the public internet - checked after
     * resolution, then pinned so a second lookup cannot answer differently -
     * no redirect, no proxy, and a size cap enforced while reading.
     *
     * @param string $url
     * @param string $target
     * @param int $maxBytes the largest file accepted
     *
     * @return string empty on success, the reason otherwise
     */
    protected static function download(string $url, string $target, int $maxBytes = self::IMAGE_MAX_BYTES): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || Tools::strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            return 'only public https addresses are accepted';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'an address carrying credentials is refused';
        }
        if ((int) ($parts['port'] ?? 443) !== 443) {
            return 'only the standard https port is accepted';
        }
        if (!function_exists('curl_init')) {
            return 'this shop has no cURL to download with';
        }

        $host = Tools::strtolower(trim((string) $parts['host'], '[]'));
        $addresses = self::resolve($host);
        if ($addresses === []) {
            return 'the host does not resolve';
        }
        foreach ($addresses as $address) {
            if (!self::isPublic($address)) {
                return 'the host resolves to a private, local or reserved address';
            }
        }

        $handle = fopen($target, 'wb');
        if ($handle === false) {
            return 'the shop has no room to receive it';
        }
        $size = 0;
        $tooLarge = false;
        $pinned = strpos($addresses[0], ':') !== false ? '[' . $addresses[0] . ']' : $addresses[0];

        $curl = curl_init();
        if ($curl === false) {
            fclose($handle);

            return 'this shop could not start a download';
        }
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => [$host . ':443:' . $pinned],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            // A proxy would resolve the host itself, out of reach of the check above.
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'PrestaShop somcp',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($handle, &$size, &$tooLarge, $maxBytes): int {
                $size += strlen($chunk);
                if ($size > $maxBytes) {
                    $tooLarge = true;

                    return 0;
                }

                return (int) fwrite($handle, $chunk);
            },
        ]);
        $done = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        unset($curl);
        fclose($handle);

        if ($tooLarge) {
            return self::tooLarge($maxBytes);
        }
        if ($done === false) {
            return 'the download failed';
        }
        if ($status >= 300 && $status < 400) {
            return 'the address redirects elsewhere, and redirections are not followed; give the final address';
        }
        if ($status !== 200) {
            return 'the address answered HTTP ' . $status;
        }

        return '';
    }

    /**
     * Writes the file behind an upload_ref where the image is put together.
     *
     * Redeemed for the employee the write runs as, on this shop: a reference
     * belonging to someone else, expired or already used finds nothing. The
     * built-in assistant hands over a copy, so its conversation keeps the
     * file.
     *
     * @param string $reference
     * @param string $target
     * @param int $maxBytes the largest file accepted
     *
     * @return string empty on success, the reason otherwise
     */
    protected static function fromDeposit(string $reference, string $target, int $maxBytes = self::IMAGE_MAX_BYTES): string
    {
        $employee = Somcp::legacyContext()->employee;
        $idEmployee = $employee instanceof Employee ? (int) $employee->id : 0;
        $files = SomcpUpload::redeem($reference, $idEmployee);
        if ($files === []) {
            return 'no file of this account matches this upload_ref, or it expired or was already used';
        }
        if (strlen($files[0]['contents']) > $maxBytes) {
            return self::tooLarge($maxBytes);
        }

        return @file_put_contents($target, $files[0]['contents']) === false ? 'the shop has no room to receive it' : '';
    }

    /**
     * @param int $maxBytes
     *
     * @return string
     */
    protected static function tooLarge(int $maxBytes): string
    {
        return 'the file is larger than ' . rtrim(rtrim(number_format($maxBytes / 1048576, 1, '.', ''), '0'), '.') . ' MB';
    }

    /**
     * @param string $file
     *
     * @return string empty when the file is an image the shop can use
     */
    protected static function checkImage(string $file): string
    {
        $info = @getimagesize($file);
        $type = is_array($info) ? (int) $info[2] : 0;
        if (!isset(self::IMAGE_TYPES[$type])) {
            return 'it is not a JPEG, PNG, GIF or WebP image';
        }
        if (!ImageManager::isRealImage($file, self::IMAGE_TYPES[$type], array_values(self::IMAGE_TYPES))) {
            return 'its content is not the image it claims to be';
        }
        if (!ImageManager::checkImageMemoryLimit($file)) {
            return 'it is too large for the shop to resize';
        }

        return '';
    }

    /**
     * @param string $host
     *
     * @return array<int, string>
     */
    protected static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // A name that does not resolve makes these functions warn; that is an
        // answer here, not an incident worth a line in the PHP log.
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $addresses = gethostbynamel($host) ?: [];
            if (function_exists('dns_get_record')) {
                foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                    if (isset($record['ipv6'])) {
                        $addresses[] = (string) $record['ipv6'];
                    }
                }
            }
        } finally {
            restore_error_handler();
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Whether an address is on the public internet.
     *
     * PHP's own flags cover the private and reserved ranges; the list below
     * adds what they leave out and what can still lead inside - shared address
     * space, benchmarking, documentation, multicast, and IPv6 forms that carry
     * an IPv4 address.
     *
     * @param string $address
     *
     * @return bool
     */
    protected static function isPublic(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        // ::ffff:a.b.c.d and the NAT64 prefix both end in an IPv4 address,
        // which is the one actually reached.
        if (strlen($packed) === 16
            && (strncmp($packed, str_repeat("\0", 10) . "\xff\xff", 12) === 0 || strncmp($packed, "\x00\x64\xff\x9b" . str_repeat("\0", 8), 12) === 0)) {
            return self::isPublic((string) inet_ntop(substr($packed, 12)));
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $blocked = strlen($packed) === 4
            ? ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3']
            : ['::/8', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8'];
        foreach ($blocked as $range) {
            [$network, $bits] = explode('/', $range);
            $base = (string) inet_pton($network);
            $bytes = intdiv((int) $bits, 8);
            $rest = (int) $bits % 8;
            if (strncmp($packed, $base, $bytes) !== 0) {
                continue;
            }
            if ($rest === 0 || ((ord($packed[$bytes]) ^ ord($base[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * What the merchant should know about the SEO of each language filled.
     *
     * @param Product $product
     * @param array<string, mixed> $plan
     * @param int $idShop
     *
     * @return array<string, array<int, string>>
     */
    protected static function seoWarnings(Product $product, array $plan, int $idShop): array
    {
        $warnings = [];
        foreach ($plan['languages']['filled'] as $idLang => $iso) {
            $list = [];
            $title = (string) ($plan['texts']['meta_title'][$idLang] ?? '');
            $description = (string) ($plan['texts']['meta_description'][$idLang] ?? '');
            if ($title === '') {
                $list[] = 'no meta_title: the storefront uses the product name';
            }
            if ($description === '') {
                $list[] = 'no meta_description: search engines pick a passage of the page';
            } elseif (self::visibleLength($description) > self::META_DESCRIPTION_SHOWN) {
                $list[] = 'meta_description over ' . self::META_DESCRIPTION_SHOWN . ' characters: search results cut it';
            }
            foreach (['link_rewrite' => (string) ($plan['texts']['link_rewrite'][$idLang] ?? ''), 'meta_title' => $title] as $field => $value) {
                if ($value === '') {
                    continue;
                }
                $other = (int) Db::getInstance()->getValue(
                    'SELECT pl.`id_product` FROM `' . _DB_PREFIX_ . 'product_lang` pl'
                    . ' INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = pl.`id_product` AND ps.`id_shop` = pl.`id_shop`'
                    . ' WHERE pl.`id_shop` = ' . $idShop . ' AND pl.`id_lang` = ' . (int) $idLang
                    . ' AND pl.`' . bqSQL($field) . "` = '" . pSQL($value) . "'"
                    . ' AND pl.`id_product` != ' . (int) $product->id
                );
                if ($other > 0) {
                    $list[] = $field . ' already used by product ' . $other;
                }
            }
            if ($list !== []) {
                $warnings[$iso] = $list;
            }
        }

        return $warnings;
    }

    /**
     * The product as somcp_get_product reads it.
     *
     * @param int $idProduct
     * @param string $iso
     * @param array<string, mixed> $context
     *
     * @return mixed
     */
    protected static function read(int $idProduct, string $iso, array $context)
    {
        $result = SomcpToolGetProduct::handle(['id_product' => $idProduct, 'language' => $iso], $context);
        $text = (string) ($result['content'][0]['text'] ?? '');

        return empty($result['isError']) ? json_decode($text, true) : $text;
    }

    /*
     * Reading the arguments.
     */

    /**
     * @param array<string, mixed> $arguments
     * @param string $name
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>
     */
    protected static function group(array $arguments, string $name, array &$errors): array
    {
        if (!array_key_exists($name, $arguments)) {
            return [];
        }
        $group = $arguments[$name];
        if (!is_array($group) || ($group !== [] && array_is_list($group))) {
            $errors[] = $name . ': an object with named fields';

            return [];
        }
        self::onlyAccepted($group, $name, $name, $errors);

        return $group;
    }

    /**
     * Refuses fields this tool does not write, naming why.
     *
     * @param array<mixed> $item
     * @param string $kind a key of ACCEPTED
     * @param string $path
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function onlyAccepted(array $item, string $kind, string $path, array &$errors): void
    {
        foreach (array_keys($item) as $key) {
            $key = (string) $key;
            if (in_array($key, self::ACCEPTED[$kind], true)) {
                continue;
            }
            $errors[] = $path . '.' . $key . (in_array($kind . '.' . $key, self::COMPUTED, true)
                ? ': worked out by the shop or read from another object, so it is not written here'
                : ': not a field of this tool');
        }
    }

    /**
     * A list of objects.
     *
     * @param mixed $value
     * @param string $path
     * @param array<int, string> $errors
     * @param bool $objects whether each item must be an object
     *
     * @return array<int, mixed>
     */
    protected static function items($value, string $path, array &$errors, bool $objects = true): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            $errors[] = $path . ': a list';

            return [];
        }
        if (!$objects) {
            return $value;
        }
        $items = [];
        foreach ($value as $i => $item) {
            if (!is_array($item) || ($item !== [] && array_is_list($item))) {
                $errors[] = $path . '[' . $i . ']: an object with named fields';
                continue;
            }
            $items[$i] = $item;
        }

        return $items;
    }

    /**
     * Identifiers from a list, as integers or as objects somcp_get_product returned.
     *
     * @param mixed $value
     * @param string $key the identifier field when an item is an object
     * @param string $path
     * @param array<int, string> $errors
     *
     * @return array<int, int>
     */
    protected static function ids($value, string $key, string $path, array &$errors): array
    {
        $ids = [];
        foreach (self::items($value, $path, $errors, false) as $item) {
            $id = self::integer(is_array($item) ? ($item[$key] ?? null) : $item);
            if ($id === null || $id <= 0) {
                $errors[] = $path . ': every item must be an identifier, a whole number above 0';
                continue;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * One translatable text, as an object keyed by ISO code.
     *
     * @param array<string, mixed> $group
     * @param string $field
     * @param string $path
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<int, string> $errors
     * @param bool $required
     *
     * @return array<int, string>|null by language identifier, null when not given
     */
    protected static function texts(array $group, string $field, string $path, array $languages, array &$errors, bool $required = false): ?array
    {
        $value = $group[$field] ?? null;
        $isos = implode(', ', $languages['filled']);
        if ($value === null) {
            if ($required) {
                $errors[] = $path . ': required, one text per language (' . $isos . ')';
            }

            return null;
        }
        // A plain string is unambiguous when one language is being filled.
        if (is_string($value) && count($languages['filled']) === 1) {
            $value = [reset($languages['filled']) => $value];
        }
        if (!is_array($value) || array_is_list($value)) {
            $errors[] = $path . ': an object keyed by ISO code, for example {"' . reset($languages['filled']) . '": "..."}';

            return null;
        }

        $byIso = array_flip($languages['filled']);
        $texts = [];
        foreach ($value as $iso => $text) {
            $iso = Tools::strtolower((string) $iso);
            if (!isset($byIso[$iso])) {
                $errors[] = $path . ': "' . $iso . '" is not among the languages being filled (' . $isos . ')';
                continue;
            }
            if (!is_string($text)) {
                $errors[] = $path . ' (' . $iso . '): a text';
                continue;
            }
            $texts[$byIso[$iso]] = $text;
        }
        foreach ($languages['filled'] as $idLang => $iso) {
            if (!isset($texts[$idLang]) || ($required && trim($texts[$idLang]) === '')) {
                $errors[] = $path . ': missing in ' . $iso . ' - every language being filled needs it';
            }
        }

        return $texts;
    }

    /**
     * A product label - stock or delivery - checked against the product's own rules.
     *
     * @param array<string, mixed> $group
     * @param string $field
     * @param string $groupName
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param Product $probe
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return bool whether it was given
     */
    protected static function labels(array $group, string $field, string $groupName, array $languages, Product $probe, array &$plan, array &$errors): bool
    {
        $texts = self::texts($group, $field, $groupName . '.' . $field, $languages, $errors);
        if ($texts === null) {
            return false;
        }
        foreach ($texts as $idLang => $text) {
            $texts[$idLang] = trim($text);
            if (($check = $probe->validateField($field, $texts[$idLang], $idLang)) !== true) {
                $errors[] = $groupName . '.' . $field . ' (' . $languages['filled'][$idLang] . '): ' . self::reason($check);
            }
        }
        $plan['texts'][$field] = $texts;

        return true;
    }

    /**
     * Every language of the shop, the ones not filled taking the fallback's text.
     *
     * @param array<int, string> $texts by language identifier
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     *
     * @return array<int, string>
     */
    protected static function complete(array $texts, array $languages): array
    {
        $fallback = $texts[$languages['fallback']] ?? '';
        $complete = [];
        foreach ($languages['all'] as $idLang) {
            $complete[$idLang] = $texts[$idLang] ?? $fallback;
        }

        return $complete;
    }

    /**
     * @param array<string, array<int, string>> $texts
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     *
     * @return array<string, array<int, string>>
     */
    protected static function completeTexts(array $texts, array $languages): array
    {
        $complete = [];
        foreach ($texts as $field => $values) {
            $complete[$field] = self::complete($values, $languages);
        }

        return $complete;
    }

    /**
     * HTML as the back office cleans it, refused when still unsafe.
     *
     * @param string $html
     * @param string $path
     * @param array<int, string> $errors
     *
     * @return string
     */
    protected static function html(string $html, string $path, array &$errors): string
    {
        $html = (string) Tools::purifyHTML(trim($html));
        if (!Validate::isCleanHtml($html, (bool) Configuration::get('PS_ALLOW_HTML_IFRAME'))) {
            $errors[] = $path . ': scripts, event attributes, forms and (unless the shop allows them) iframes are refused';
        }

        return $html;
    }

    /**
     * Characters of visible text: tags removed, entities decoded, runs of spaces counted as one.
     *
     * @param string $html
     *
     * @return int
     */
    protected static function visibleLength(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (int) Tools::strlen(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * A number field of the product, checked with the product's own rule.
     *
     * @param array<string, mixed> $group
     * @param string $given the name in the arguments
     * @param string $field the property of Product
     * @param string $groupName
     * @param Product $probe
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function number(array $group, string $given, string $field, string $groupName, Product $probe, array &$plan, array &$errors): void
    {
        if (!array_key_exists($given, $group)) {
            return;
        }
        $number = self::float($group[$given]);
        if ($number === null || $number < 0 || $probe->validateField($field, $number) !== true) {
            $errors[] = $groupName . '.' . $given . ': a positive number';

            return;
        }
        $plan['fields'][$field] = $number;
    }

    /**
     * @param array<string, mixed> $group
     * @param string $field
     * @param string $groupName
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function boolean(array $group, string $field, string $groupName, array &$plan, array &$errors): void
    {
        if (!array_key_exists($field, $group)) {
            return;
        }
        $value = self::bool($group[$field]);
        if ($value === null) {
            $errors[] = $groupName . '.' . $field . ': true or false';

            return;
        }
        $plan['fields'][$field] = $value;
    }

    /**
     * A position in the combinations of this call.
     *
     * @param array<string, mixed> $item
     * @param string $key
     * @param int $combinations
     * @param string $path
     * @param array<int, string> $errors
     *
     * @return int|null null when not given
     */
    protected static function position(array $item, string $key, int $combinations, string $path, array &$errors): ?int
    {
        if (!isset($item[$key])) {
            return null;
        }
        $index = self::integer($item[$key]);
        if ($index === null || $index < 0 || $index >= $combinations) {
            $errors[] = $path . '.' . $key . ': not a position in combinations (from 0 to ' . max(0, $combinations - 1) . ')';

            return null;
        }

        return $index;
    }

    /**
     * @param mixed $value
     * @param int $idShop
     * @param string $path
     * @param array<int, string> $errors
     *
     * @return int
     */
    protected static function currency($value, int $idShop, string $path, array &$errors): int
    {
        $iso = self::string($value);
        $id = $iso !== null && preg_match('/^[A-Za-z]{3}$/', $iso) ? (int) Currency::getIdByIsoCode(Tools::strtoupper($iso), $idShop) : 0;
        if ($id <= 0) {
            $errors[] = $path . ': not a currency of this shop';
        }

        return $id;
    }

    /**
     * @param int $idSupplier
     * @param array<int, bool> $known suppliers already looked up
     *
     * @return bool
     */
    protected static function supplierExists(int $idSupplier, array &$known): bool
    {
        if (!isset($known[$idSupplier])) {
            $known[$idSupplier] = $idSupplier > 0 && Validate::isLoadedObject(new Supplier($idSupplier));
        }

        return $known[$idSupplier];
    }

    /**
     * @param mixed $value
     * @param string $path
     * @param array<int, string> $errors
     *
     * @return string|null
     */
    protected static function location($value, string $path, array &$errors): ?string
    {
        $location = self::string($value);
        if ($location === null || !Validate::isString($location) || Tools::strlen(trim($location)) > 255) {
            $errors[] = $path . ': a text of 255 characters at most';

            return null;
        }

        return trim($location);
    }

    /**
     * @param mixed $value
     * @param string $path
     * @param array<int, string> $errors
     *
     * @return string|null YYYY-MM-DD
     */
    protected static function date($value, string $path, array &$errors): ?string
    {
        $date = self::string($value);
        $date = $date === null ? '' : Tools::substr(trim($date), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !Validate::isDateFormat($date)
            || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $errors[] = $path . ': a date, YYYY-MM-DD';

            return null;
        }

        return $date;
    }

    /**
     * @param mixed $value
     * @param string $path
     * @param array<int, string> $errors
     *
     * @return string|null YYYY-MM-DD HH:MM:SS
     */
    protected static function dateTime($value, string $path, array &$errors): ?string
    {
        $text = self::string($value);
        // ISO 8601 as the conventions say, written the way MySQL stores it;
        // a time zone suffix is dropped, the shop's own being the reference.
        if ($text !== null && preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2})(?::(\d{2}))?)?/', trim($text), $m)
            && checkdate((int) substr($m[1], 5, 2), (int) substr($m[1], 8, 2), (int) substr($m[1], 0, 4))) {
            return $m[1] . ' ' . ($m[2] ?? '00:00') . ':' . (($m[3] ?? '') !== '' ? $m[3] : '00');
        }
        $errors[] = $path . ': a date and time, YYYY-MM-DD HH:MM:SS';

        return null;
    }

    /**
     * @param mixed $value
     *
     * @return int|null
     */
    protected static function integer($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,18}$/', trim($value))) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * @param mixed $value
     *
     * @return float|null
     */
    protected static function float($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    /**
     * @param mixed $value
     *
     * @return bool|null
     */
    protected static function bool($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1 || in_array($value, ['0', '1', 'true', 'false'], true)) {
            return in_array($value, [1, '1', 'true'], true);
        }

        return null;
    }

    /**
     * @param mixed $value
     *
     * @return string|null
     */
    protected static function string($value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * The message of ObjectModel::validateField(), without its markup.
     *
     * @param mixed $check
     *
     * @return string
     */
    protected static function reason($check): string
    {
        return is_string($check) ? strip_tags($check) : 'not accepted by PrestaShop';
    }

    /**
     * Sets properties by name.
     *
     * The core declares several of these as a string although they hold one
     * text per language, so they are written the way ObjectModel reads them
     * rather than the way their docblock says.
     *
     * @param ObjectModel $object
     * @param array<string, mixed> $values
     *
     * @return void
     */
    protected static function fill(ObjectModel $object, array $values): void
    {
        foreach ($values as $field => $value) {
            $object->{$field} = $value;
        }
    }
}
