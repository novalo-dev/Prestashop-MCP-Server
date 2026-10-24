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
 * somcp_get_product - everything the back office product page shows for one
 * product.
 *
 * Grouped like the tabs of that page, because that is where a merchant will
 * go and check what the assistant told them, and because the write tools take
 * the same groups and field names: what this one reads, they write.
 *
 * It takes identifiers only. Finding a product from a name, a reference or a
 * barcode is somcp_search_products' job; letting this tool guess would have it
 * pick one product among several with total confidence.
 *
 * One product is cheap to load, so the core object and the core readers are
 * used where they say what we need. Where they would read another shop's rows
 * or hide some (stock, combinations, features), a query of our own with the
 * shop of the token says it instead.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetProduct
{
    /** @var array<int, string> Stored value of out_of_stock, as the merchant reads it. */
    private const OUT_OF_STOCK = [0 => 'deny', 1 => 'allow', 2 => 'default'];

    /** @var array<int, string> Stored value of additional_delivery_times, as the merchant reads it. */
    private const DELIVERY_TIMES = [0 => 'none', 1 => 'default', 2 => 'product'];

    /** @var array<string, bool> Tables and columns already looked up during this request. */
    private static $schema = [];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_product',
            Somcp::t('Get a product'),
            'Returns everything the back office product page shows for one product of this shop, '
                . 'grouped like its tabs: basics (type, name, summary, description), categories, '
                . 'brand, related products, references (reference, MPN, UPC, EAN13, ISBN), '
                . 'features, attachments, condition, customization, stock and its labels, shipping '
                . 'and carriers, pricing and tax, specific prices and their priorities, SEO, '
                . 'options, suppliers, images, and every combination with its own codes, stock and '
                . 'impacts. Prices are the product\'s own, before specific prices: those are listed '
                . 'apart in specific_prices, so a promotion is never mistaken for the price. '
                . 'Identify the product by its identifier, and optionally one of its combinations. '
                . 'Do not use it to look for products - somcp_search_products finds them from a '
                . 'name, a reference or an EAN13, including the combination that matched, and '
                . 'this tool reads one already identified.',
            [
                'type' => 'object',
                'properties' => [
                    'id_product' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the product, from somcp_search_products.',
                    ],
                    'id_product_attribute' => [
                        'type' => 'integer',
                        'description' => 'Identifier of one combination of that product: combinations then '
                            . 'holds that one only. Refused if it belongs to another product.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of the language to read the texts in, for example fr. '
                            . 'The shop default is used otherwise.',
                    ],
                ],
                'required' => ['id_product'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminProducts')
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
        $shop = Somcp::currentShop();
        $idShop = (int) $shop->id;
        $idLang = self::language($arguments);

        $product = self::locate((int) ($arguments['id_product'] ?? 0), $idLang, $idShop);
        if ($product === null) {
            return SomcpTool::text('No product of this shop has this identifier. Find it with somcp_search_products.', true);
        }

        $idCombination = (int) ($arguments['id_product_attribute'] ?? 0);
        if ($idCombination > 0 && !self::combinationBelongs($product, $idCombination, $idShop)) {
            // Answering with the whole product instead would let the assistant
            // describe a combination it never read.
            return SomcpTool::text('This combination does not belong to this product in this shop.', true);
        }

        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop));
        $money = [
            // Product prices are stored in the shop default currency, whatever
            // currency a visitor later sees them in.
            'iso' => Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '',
            'precision' => Validate::isLoadedObject($currency) ? (int) $currency->precision : 2,
            'tax_ratio' => 1 + self::taxRate($product, $idShop) / 100,
        ];
        $stock = self::stockRows($product, $shop);

        return SomcpTool::json([
            'basics' => [
                'id_product' => (int) $product->id,
                'product_type' => (string) $product->getProductType(),
                'name' => (string) $product->name,
                'description_short' => (string) $product->description_short,
                'description' => (string) $product->description,
            ],
            'categories' => self::categories($product, $idLang),
            'brand' => [
                'id_manufacturer' => (int) $product->id_manufacturer,
                'manufacturer_name' => (int) $product->id_manufacturer > 0
                    ? (string) Manufacturer::getNameById((int) $product->id_manufacturer)
                    : '',
            ],
            'related_products' => self::relatedProducts($product, $idLang),
            'references' => [
                'reference' => (string) $product->reference,
                'mpn' => (string) $product->mpn,
                'upc' => (string) $product->upc,
                'ean13' => (string) $product->ean13,
                'isbn' => (string) $product->isbn,
            ],
            'features' => self::features($product, $idLang),
            'attachments' => self::attachments($product, $idLang),
            'condition' => [
                'condition' => (string) $product->condition,
                'show_condition' => (bool) $product->show_condition,
            ],
            'customization' => self::customization($product, $idLang, $idShop),
            'stock' => self::stock($product, $stock[0] ?? null),
            'shipping' => self::shipping($product, $idLang, $money['iso']),
            'pricing' => self::pricing($product, $money),
            'specific_prices' => self::specificPrices($product, $idShop, $idCombination, $money['iso']),
            'seo' => self::seo($product, $idLang, $idShop),
            'options' => [
                'visibility' => (string) $product->visibility,
                'available_for_order' => (bool) $product->available_for_order,
                'show_price' => (bool) $product->show_price,
                'online_only' => (bool) $product->online_only,
                'active' => (bool) $product->active,
            ],
            'suppliers' => self::suppliers($product, $idCombination),
            'images' => self::images($product, $idLang, $idShop),
            'combinations' => self::combinations($product, $idLang, $idShop, $idCombination, $stock, $money),
        ]);
    }

    /**
     * Loads the product as the shop of the token holds it.
     *
     * Loaded with that shop, the object carries the price, status and options
     * of product_shop for that shop rather than the default shop's. Public
     * because somcp_get_stock finds its product the same way: two tools that
     * disagree on which products exist would answer for different ones.
     *
     * @param int $idProduct
     * @param int $idLang
     * @param int $idShop
     *
     * @return Product|null
     */
    public static function locate(int $idProduct, int $idLang, int $idShop): ?Product
    {
        if ($idProduct <= 0) {
            return null;
        }

        $product = new Product($idProduct, false, $idLang, $idShop);
        if (!Validate::isLoadedObject($product) || !$product->isAssociatedToShop($idShop)) {
            return null;
        }
        // A product the back office is still creating has no name yet and is
        // in no list: somcp_search_products would never have returned it.
        if ((int) $product->state !== Product::STATE_SAVED) {
            return null;
        }

        return $product;
    }

    /**
     * @param Product $product
     * @param int $idCombination
     * @param int $idShop
     *
     * @return bool
     */
    private static function combinationBelongs(Product $product, int $idCombination, int $idShop): bool
    {
        $query = new DbQuery();
        $query->select('1');
        $query->from('product_attribute', 'pa');
        $query->innerJoin(
            'product_attribute_shop',
            'pas',
            'pas.`id_product_attribute` = pa.`id_product_attribute` AND pas.`id_shop` = ' . $idShop
        );
        $query->where('pa.`id_product_attribute` = ' . $idCombination);
        $query->where('pa.`id_product` = ' . (int) $product->id);

        return (bool) Db::getInstance()->getValue($query);
    }

    /**
     * The rate of the product's tax rule in the shop default country.
     *
     * The same rate somcp_search_products applies, so the two tools agree on a
     * price with tax: no rate when the shop has taxes switched off, and none
     * for a rule with nothing for that country.
     *
     * @param Product $product
     * @param int $idShop
     *
     * @return float
     */
    private static function taxRate(Product $product, int $idShop): float
    {
        if (!Configuration::get('PS_TAX', null, null, $idShop)) {
            return 0.0;
        }
        $rates = TaxRulesGroup::getAssociatedTaxRatesByIdCountry(
            (int) Configuration::get('PS_COUNTRY_DEFAULT', null, null, $idShop)
        );

        return (float) ($rates[(int) $product->id_tax_rules_group] ?? 0);
    }

    /**
     * The stock rows of the product and of its combinations, by combination.
     *
     * Read once for all of them. Whether they belong to the shop or to its
     * group depends on stock sharing, which the core decides for us. Public
     * because somcp_get_stock reads the same rows, so the two tools never
     * give different quantities for one product.
     *
     * @param Product $product
     * @param Shop $shop
     *
     * @return array<int, array{quantity: int, physical_quantity: int, reserved_quantity: int, location: string, out_of_stock: int}>
     */
    public static function stockRows(Product $product, Shop $shop): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT sa.`id_product_attribute`, sa.`quantity`, sa.`physical_quantity`, sa.`reserved_quantity`,'
            . ' sa.`location`, sa.`out_of_stock`'
            . ' FROM `' . _DB_PREFIX_ . 'stock_available` sa'
            . ' WHERE sa.`id_product` = ' . (int) $product->id
            . (string) StockAvailable::addSqlShopRestriction(null, $shop, 'sa')
        ) ?: [];

        $stock = [];
        foreach ($rows as $row) {
            $stock[(int) $row['id_product_attribute']] = [
                'quantity' => (int) $row['quantity'],
                'physical_quantity' => (int) $row['physical_quantity'],
                'reserved_quantity' => (int) $row['reserved_quantity'],
                'location' => (string) $row['location'],
                'out_of_stock' => (int) $row['out_of_stock'],
            ];
        }

        return $stock;
    }

    /**
     * @param Product $product
     * @param int $idLang
     *
     * @return array<string, mixed>
     */
    private static function categories(Product $product, int $idLang): array
    {
        $categories = [];
        foreach (Product::getProductCategoriesFull((int) $product->id, $idLang) as $row) {
            $categories[] = ['id_category' => (int) $row['id_category'], 'name' => (string) $row['name']];
        }

        return [
            'id_category_default' => (int) $product->id_category_default,
            'categories' => $categories,
        ];
    }

    /**
     * @param Product $product
     * @param int $idLang
     *
     * @return array<int, array<string, mixed>>
     */
    private static function relatedProducts(Product $product, int $idLang): array
    {
        $related = [];
        // The light reader, not getAccessories(): that one drops inactive
        // products and works out prices, and the back office tab lists every
        // related product by name.
        foreach (Product::getAccessoriesLight($idLang, (int) $product->id) ?: [] as $row) {
            $related[] = [
                'id_product' => (int) $row['id_product'],
                'reference' => (string) $row['reference'],
                'name' => (string) $row['name'],
            ];
        }

        return $related;
    }

    /**
     * Read here rather than with Product::getFrontFeatures(), which returns
     * nothing when the feature system is switched off for the storefront and
     * does not say whether a value was typed on this product only.
     *
     * @param Product $product
     * @param int $idLang
     *
     * @return array<int, array<string, mixed>>
     */
    private static function features(Product $product, int $idLang): array
    {
        $query = new DbQuery();
        $query->select('fp.`id_feature`, fp.`id_feature_value`, fl.`name`, fvl.`value`, fv.`custom`');
        $query->from('feature_product', 'fp');
        $query->leftJoin('feature', 'f', 'f.`id_feature` = fp.`id_feature`');
        $query->leftJoin('feature_lang', 'fl', 'fl.`id_feature` = fp.`id_feature` AND fl.`id_lang` = ' . $idLang);
        $query->leftJoin('feature_value', 'fv', 'fv.`id_feature_value` = fp.`id_feature_value`');
        $query->leftJoin(
            'feature_value_lang',
            'fvl',
            'fvl.`id_feature_value` = fp.`id_feature_value` AND fvl.`id_lang` = ' . $idLang
        );
        $query->where('fp.`id_product` = ' . (int) $product->id);
        $query->orderBy('f.`position` ASC, fp.`id_feature_value` ASC');

        $features = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $features[] = [
                'id_feature' => (int) $row['id_feature'],
                'id_feature_value' => (int) $row['id_feature_value'],
                'name' => (string) $row['name'],
                'value' => (string) $row['value'],
                'custom' => (bool) $row['custom'],
            ];
        }

        return $features;
    }

    /**
     * @param Product $product
     * @param int $idLang
     *
     * @return array<int, array<string, mixed>>
     */
    private static function attachments(Product $product, int $idLang): array
    {
        $attachments = [];
        foreach (Product::getAttachmentsStatic($idLang, (int) $product->id) ?: [] as $row) {
            $attachments[] = [
                'id_attachment' => (int) $row['id_attachment'],
                'name' => (string) $row['name'],
                'description' => (string) $row['description'],
                'file_name' => (string) $row['file_name'],
                'mime' => (string) $row['mime'],
            ];
        }

        return $attachments;
    }

    /**
     * @param Product $product
     * @param int $idLang
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    private static function customization(Product $product, int $idLang, int $idShop): array
    {
        $fields = [];
        // False when the shop has no customization field at all, which is the
        // same as an empty list here.
        $rows = $product->getCustomizationFields($idLang, $idShop);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $fields[] = [
                'id_customization_field' => (int) $row['id_customization_field'],
                'type' => (int) $row['type'] === Product::CUSTOMIZE_FILE ? 'file' : 'text',
                'name' => (string) $row['name'],
                'required' => (bool) $row['required'],
            ];
        }

        return [
            'customizable' => (int) $product->customizable,
            'uploadable_files' => (int) $product->uploadable_files,
            'text_fields' => (int) $product->text_fields,
            'fields' => $fields,
        ];
    }

    /**
     * @param Product $product
     * @param array{quantity: int, physical_quantity: int, reserved_quantity: int, location: string, out_of_stock: int}|null $row the row without combination
     *
     * @return array<string, mixed>
     */
    private static function stock(Product $product, ?array $row): array
    {
        return [
            // The row without a combination holds the total of all of them.
            'quantity' => $row['quantity'] ?? 0,
            'location' => $row['location'] ?? '',
            'out_of_stock' => self::OUT_OF_STOCK[$row['out_of_stock'] ?? 2] ?? 'default',
            'available_now' => (string) $product->available_now,
            'available_later' => (string) $product->available_later,
            'available_date' => self::date((string) $product->available_date),
        ];
    }

    /**
     * @param Product $product
     * @param int $idLang
     * @param string $currency
     *
     * @return array<string, mixed>
     */
    private static function shipping(Product $product, int $idLang, string $currency): array
    {
        $allowed = [];
        foreach ($product->getCarriers() ?: [] as $row) {
            $allowed[(int) $row['id_reference']] = true;
        }

        $carriers = [];
        foreach (Carrier::getCarriers($idLang, false, false, false, null, Carrier::ALL_CARRIERS) ?: [] as $row) {
            $carriers[] = [
                'id_carrier' => (int) $row['id_carrier'],
                // A carrier named 0 is the one the installer creates, and the
                // back office shows it under the shop's name.
                'name' => (string) $row['name'] === '0' ? (string) Configuration::get('PS_SHOP_NAME') : (string) $row['name'],
                // No carrier ticked on the product means every carrier may
                // deliver it, which is how the checkout reads it too.
                'available' => $allowed === [] || isset($allowed[(int) $row['id_reference']]),
            ];
        }

        return [
            'width' => (float) $product->width,
            'height' => (float) $product->height,
            'depth' => (float) $product->depth,
            'weight' => (float) $product->weight,
            'additional_delivery_times' => self::DELIVERY_TIMES[(int) $product->additional_delivery_times] ?? 'default',
            'delivery_in_stock' => self::text($product->delivery_in_stock, $idLang),
            'delivery_out_stock' => self::text($product->delivery_out_stock, $idLang),
            'additional_shipping_cost' => (float) $product->additional_shipping_cost,
            'currency' => $currency,
            'carriers' => $carriers,
        ];
    }

    /**
     * @param Product $product
     * @param array{iso: string, precision: int, tax_ratio: float} $money
     *
     * @return array<string, mixed>
     */
    private static function pricing(Product $product, array $money): array
    {
        $group = new TaxRulesGroup((int) $product->id_tax_rules_group);

        return [
            'price_tax_excl' => (float) $product->price,
            'id_tax_rules_group' => (int) $product->id_tax_rules_group,
            'tax_rules_group_name' => Validate::isLoadedObject($group) ? (string) $group->name : '',
            'tax_rate' => round(($money['tax_ratio'] - 1) * 100, 3),
            'price_tax_incl' => round((float) $product->price * $money['tax_ratio'], $money['precision']),
            'wholesale_price' => (float) $product->wholesale_price,
            'unit_price_tax_excl' => (float) $product->unit_price,
            'unit_price_tax_incl' => round((float) $product->unit_price * $money['tax_ratio'], $money['precision']),
            'unity' => (string) $product->unity,
            'on_sale' => (bool) $product->on_sale,
            'currency' => $money['iso'],
        ];
    }

    /**
     * The specific prices that apply in this shop, and the order used to pick
     * between them.
     *
     * The core reader returns every shop's rules; a rule for another shop of
     * the installation is not one this shop's customers ever see.
     *
     * @param Product $product
     * @param int $idShop
     * @param int $idCombination
     * @param string $defaultCurrency
     *
     * @return array<string, mixed>
     */
    private static function specificPrices(Product $product, int $idShop, int $idCombination, string $defaultCurrency): array
    {
        $rules = [];
        foreach (SpecificPrice::getByProductId((int) $product->id) ?: [] as $row) {
            if (!in_array((int) $row['id_shop'], [0, $idShop], true)) {
                continue;
            }
            // A rule on the whole product applies to the combination asked
            // for too, so it stays; a rule on another combination does not.
            if ($idCombination > 0 && !in_array((int) $row['id_product_attribute'], [0, $idCombination], true)) {
                continue;
            }
            $rules[] = [
                'id_specific_price' => (int) $row['id_specific_price'],
                'id_product_attribute' => (int) $row['id_product_attribute'],
                'id_shop' => (int) $row['id_shop'],
                // 0 everywhere below means "all", as in the back office.
                'currency' => (int) $row['id_currency'] > 0 ? (string) Currency::getIsoCodeById((int) $row['id_currency']) : null,
                'country' => (int) $row['id_country'] > 0 ? (string) Country::getIsoById((int) $row['id_country']) : null,
                'id_group' => (int) $row['id_group'],
                'id_customer' => (int) $row['id_customer'],
                'from_quantity' => (int) $row['from_quantity'],
                // -1 keeps the product's own price and only applies the reduction.
                'price' => (float) $row['price'],
                'reduction' => (float) $row['reduction'],
                'reduction_type' => (string) $row['reduction_type'],
                'reduction_tax' => (bool) $row['reduction_tax'],
                // Amounts of a rule without a currency of its own are in the
                // shop default currency.
                'amounts_currency' => (int) $row['id_currency'] > 0
                    ? (string) Currency::getIsoCodeById((int) $row['id_currency'])
                    : $defaultCurrency,
                'from' => self::date((string) $row['from']),
                'to' => self::date((string) $row['to']),
            ];
        }

        return [
            'rules' => $rules,
            'priorities' => array_values(array_filter(SpecificPrice::getPriority((int) $product->id))),
        ];
    }

    /**
     * @param Product $product
     * @param int $idLang
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    private static function seo(Product $product, int $idLang, int $idShop): array
    {
        $link = Somcp::legacyContext()->link ?? new Link();

        // PrestaShop 9 dropped the column, and the property with it: looking
        // at the column says what this shop has, whatever its version.
        $keywords = null;
        if (self::hasColumn('product_lang', 'meta_keywords')) {
            $keywords = (string) Db::getInstance()->getValue(
                'SELECT `meta_keywords` FROM `' . _DB_PREFIX_ . 'product_lang`'
                . ' WHERE `id_product` = ' . (int) $product->id
                . ' AND `id_lang` = ' . $idLang . ' AND `id_shop` = ' . $idShop
            );
        }

        return [
            'meta_title' => (string) $product->meta_title,
            'meta_description' => (string) $product->meta_description,
            'link_rewrite' => (string) $product->link_rewrite,
            'url' => (string) $link->getProductLink($product, null, null, null, $idLang, $idShop),
            'redirect_type' => (string) $product->redirect_type,
            'id_type_redirected' => (int) $product->id_type_redirected,
            'meta_keywords' => $keywords,
        ];
    }

    /**
     * @param Product $product
     * @param int $idCombination
     *
     * @return array<string, mixed>
     */
    private static function suppliers(Product $product, int $idCombination): array
    {
        $query = new DbQuery();
        $query->select('ps.`id_supplier`, s.`name`, ps.`id_product_attribute`, ps.`product_supplier_reference`');
        $query->select('ps.`product_supplier_price_te`, c.`iso_code`');
        $query->from('product_supplier', 'ps');
        $query->leftJoin('supplier', 's', 's.`id_supplier` = ps.`id_supplier`');
        $query->leftJoin('currency', 'c', 'c.`id_currency` = ps.`id_currency`');
        $query->where('ps.`id_product` = ' . (int) $product->id);
        if ($idCombination > 0) {
            $query->where('ps.`id_product_attribute` IN (0, ' . $idCombination . ')');
        }
        $query->orderBy('ps.`id_supplier` ASC, ps.`id_product_attribute` ASC');

        $suppliers = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $suppliers[] = [
                'id_supplier' => (int) $row['id_supplier'],
                'supplier_name' => (string) $row['name'],
                'id_product_attribute' => (int) $row['id_product_attribute'],
                'product_supplier_reference' => (string) $row['product_supplier_reference'],
                'product_supplier_price_te' => (float) $row['product_supplier_price_te'],
                'currency' => (string) $row['iso_code'],
            ];
        }

        return [
            'id_supplier_default' => (int) $product->id_supplier,
            'suppliers' => $suppliers,
        ];
    }

    /**
     * @param Product $product
     * @param int $idLang
     * @param int $idShop
     *
     * @return array<int, array<string, mixed>>
     */
    private static function images(Product $product, int $idLang, int $idShop): array
    {
        $link = Somcp::legacyContext()->link ?? new Link();
        $type = ImageType::getFormattedName('large');
        // Legacy image names carry the product identifier, and only PrestaShop
        // 8 still has them; PrestaShop 9 wants the image identifier alone and
        // flags the old form as deprecated.
        $legacy = (bool) Configuration::get('PS_LEGACY_IMAGES');

        $images = [];
        foreach (Image::getImages($idLang, (int) $product->id, 0, $idShop) ?: [] as $row) {
            $idImage = (int) $row['id_image'];
            $images[] = [
                'id_image' => $idImage,
                'position' => (int) $row['position'],
                'cover' => (bool) $row['cover'],
                'legend' => (string) $row['legend'],
                'url' => (string) $link->getImageLink(
                    (string) $product->link_rewrite,
                    $legacy ? (int) $product->id . '-' . $idImage : (string) $idImage,
                    $type
                ),
            ];
        }

        return $images;
    }

    /**
     * Every combination with its own codes, stock and impacts, or the one
     * asked for.
     *
     * Read here rather than with Product::getAttributeCombinations(), whose
     * stock join ignores the shop and which returns one line per attribute.
     *
     * @param Product $product
     * @param int $idLang
     * @param int $idShop
     * @param int $idCombination
     * @param array<int, array{quantity: int, physical_quantity: int, reserved_quantity: int, location: string, out_of_stock: int}> $stock
     * @param array{iso: string, precision: int, tax_ratio: float} $money
     *
     * @return array<int, array<string, mixed>>
     */
    private static function combinations(Product $product, int $idLang, int $idShop, int $idCombination, array $stock, array $money): array
    {
        // PrestaShop 8.0 has no labels per combination; 8.1 added the table.
        $labels = self::hasTable('product_attribute_lang');

        $query = new DbQuery();
        $query->select('pa.`id_product_attribute`, pa.`reference`, pa.`mpn`, pa.`upc`, pa.`ean13`, pa.`isbn`');
        $query->select('pas.`default_on`, pas.`price`, pas.`unit_price_impact`, pas.`weight`, pas.`wholesale_price`');
        $query->select('pas.`minimal_quantity`, pas.`available_date`');
        if ($labels) {
            $query->select('pal.`available_now`, pal.`available_later`');
        }
        $query->from('product_attribute', 'pa');
        $query->innerJoin(
            'product_attribute_shop',
            'pas',
            'pas.`id_product_attribute` = pa.`id_product_attribute` AND pas.`id_shop` = ' . $idShop
        );
        if ($labels) {
            $query->leftJoin(
                'product_attribute_lang',
                'pal',
                'pal.`id_product_attribute` = pa.`id_product_attribute` AND pal.`id_lang` = ' . $idLang
            );
        }
        $query->where('pa.`id_product` = ' . (int) $product->id);
        if ($idCombination > 0) {
            $query->where('pa.`id_product_attribute` = ' . $idCombination);
        }
        $query->orderBy('pa.`id_product_attribute` ASC');
        $rows = Db::getInstance()->executeS($query) ?: [];
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static function (array $row): int {
            return (int) $row['id_product_attribute'];
        }, $rows);
        $attributes = self::combinationAttributes($ids, $idLang);
        $images = self::combinationImages($ids);

        $combinations = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_product_attribute'];
            $combinations[] = [
                'id_product_attribute' => $id,
                'attributes' => $attributes[$id] ?? [],
                'default_on' => (bool) $row['default_on'],
                'reference' => (string) $row['reference'],
                'mpn' => (string) $row['mpn'],
                'upc' => (string) $row['upc'],
                'ean13' => (string) $row['ean13'],
                'isbn' => (string) $row['isbn'],
                'quantity' => $stock[$id]['quantity'] ?? 0,
                'location' => $stock[$id]['location'] ?? '',
                'available_date' => self::date((string) $row['available_date']),
                'available_now' => $labels ? (string) $row['available_now'] : null,
                'available_later' => $labels ? (string) $row['available_later'] : null,
                'price_impact_tax_excl' => (float) $row['price'],
                'price_impact_tax_incl' => round((float) $row['price'] * $money['tax_ratio'], $money['precision']),
                'unit_price_impact_tax_excl' => (float) $row['unit_price_impact'],
                'unit_price_impact_tax_incl' => round((float) $row['unit_price_impact'] * $money['tax_ratio'], $money['precision']),
                'weight_impact' => (float) $row['weight'],
                'wholesale_price' => (float) $row['wholesale_price'],
                'minimal_quantity' => (int) $row['minimal_quantity'],
                'currency' => $money['iso'],
                'images' => $images[$id] ?? [],
            ];
        }

        return $combinations;
    }

    /**
     * The group and value of each attribute of the combinations, Size: M.
     *
     * Public because somcp_get_stock names its combinations the same way.
     *
     * @param array<int, int> $ids
     * @param int $idLang
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function combinationAttributes(array $ids, int $idLang): array
    {
        $query = new DbQuery();
        $query->select('pac.`id_product_attribute`, a.`id_attribute`, a.`id_attribute_group`');
        $query->select('agl.`name` AS `group_name`, al.`name` AS `attribute_name`');
        $query->from('product_attribute_combination', 'pac');
        $query->innerJoin('attribute', 'a', 'a.`id_attribute` = pac.`id_attribute`');
        $query->leftJoin('attribute_group', 'ag', 'ag.`id_attribute_group` = a.`id_attribute_group`');
        $query->leftJoin(
            'attribute_group_lang',
            'agl',
            'agl.`id_attribute_group` = a.`id_attribute_group` AND agl.`id_lang` = ' . $idLang
        );
        $query->leftJoin('attribute_lang', 'al', 'al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . $idLang);
        $query->where('pac.`id_product_attribute` IN (' . implode(',', array_map('intval', $ids)) . ')');
        $query->orderBy('ag.`position` ASC, a.`position` ASC');

        $attributes = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $attributes[(int) $row['id_product_attribute']][] = [
                'id_attribute_group' => (int) $row['id_attribute_group'],
                'group' => (string) $row['group_name'],
                'id_attribute' => (int) $row['id_attribute'],
                'value' => (string) $row['attribute_name'],
            ];
        }

        return $attributes;
    }

    /**
     * @param array<int, int> $ids
     *
     * @return array<int, array<int, int>>
     */
    private static function combinationImages(array $ids): array
    {
        $query = new DbQuery();
        $query->select('pai.`id_product_attribute`, pai.`id_image`');
        $query->from('product_attribute_image', 'pai');
        $query->where('pai.`id_product_attribute` IN (' . implode(',', array_map('intval', $ids)) . ')');
        $query->orderBy('pai.`id_image` ASC');

        $images = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            // The core stores 0 for a combination that uses no image of its own.
            if ((int) $row['id_image'] > 0) {
                $images[(int) $row['id_product_attribute']][] = (int) $row['id_image'];
            }
        }

        return $images;
    }

    /**
     * One language of a translated field. PrestaShop declares some of them as
     * string[] although a product loaded in one language holds a plain string,
     * so the value is taken as it comes rather than as the docblock says.
     *
     * @param mixed $value
     * @param int $idLang
     *
     * @return string
     */
    private static function text($value, int $idLang): string
    {
        if (is_array($value)) {
            return (string) ($value[$idLang] ?? '');
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A date as ISO 8601, or null for the empty dates MySQL stores.
     *
     * @param string $value
     *
     * @return string|null
     */
    private static function date(string $value): ?string
    {
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return null;
        }

        return $value;
    }

    /**
     * @param string $table without prefix
     *
     * @return bool
     */
    private static function hasTable(string $table): bool
    {
        $key = $table;
        if (!isset(self::$schema[$key])) {
            self::$schema[$key] = (bool) Db::getInstance()->executeS(
                "SHOW TABLES LIKE '" . pSQL(_DB_PREFIX_ . $table) . "'"
            );
        }

        return self::$schema[$key];
    }

    /**
     * @param string $table without prefix
     * @param string $column
     *
     * @return bool
     */
    private static function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!isset(self::$schema[$key])) {
            self::$schema[$key] = (bool) Db::getInstance()->executeS(
                'SHOW COLUMNS FROM `' . bqSQL(_DB_PREFIX_ . $table) . "` LIKE '" . pSQL($column) . "'"
            );
        }

        return self::$schema[$key];
    }

    /**
     * The language to read the texts in.
     *
     * @param array<string, mixed> $arguments
     *
     * @return int
     */
    private static function language(array $arguments): int
    {
        $iso = isset($arguments['language']) && is_scalar($arguments['language']) ? trim((string) $arguments['language']) : '';
        if ($iso !== '') {
            $idLang = (int) Language::getIdByIso($iso);
            if ($idLang > 0) {
                return $idLang;
            }
        }

        return (int) Somcp::legacyContext()->language->id;
    }
}
