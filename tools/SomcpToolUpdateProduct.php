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
 * somcp_update_product - one existing product, changed with everything
 * somcp_create_product can write.
 *
 * It extends the creation tool rather than copying it: a field accepted at
 * creation is accepted here under the same name and checked by the same code,
 * so the two tools cannot come to disagree on what a valid EAN13 or summary is.
 *
 * Only what is given changes. Each value is compared with what the shop holds
 * before anything is written, which gives the answer its old and new values
 * and lets a call that would change nothing write nothing at all: no hook
 * fires, no date moves, and the answer says changed: false.
 *
 * Every check runs before the first write, so a call refused for any reason
 * the shop can foresee leaves the product exactly as it was. Undoing a write
 * that fails anyway is not possible the way the creation does it, by deleting
 * the product, and a database transaction does not hold either: the hooks
 * the core fires commit it behind the tool's back - ps_facetedsearch, shipped
 * with every shop, truncates its cache table on each product save, and a
 * TRUNCATE commits. So the writes run in a fixed order and, if one still
 * fails, the answer names the steps already written instead of claiming a
 * rollback that did not happen. Images come last, as at creation, and each one
 * is reported on its own.
 *
 * Removing is never inferred from a missing item. A list that replaces a set
 * (categories, carriers, features...) says so in its description, and
 * combinations, images, specific prices and suppliers are only removed when
 * named in their remove_ list.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolCreateProduct.php';

class SomcpToolUpdateProduct extends SomcpToolCreateProduct
{
    /** @var array<string, string> Product properties as the arguments name them, for the answer. */
    private const PATHS = [
        'name' => 'basics.name',
        'description_short' => 'basics.description_short',
        'description' => 'basics.description',
        'product_type' => 'basics.product_type',
        'id_category_default' => 'categories.id_category_default',
        'id_manufacturer' => 'brand.id_manufacturer',
        'reference' => 'references.reference',
        'mpn' => 'references.mpn',
        'upc' => 'references.upc',
        'ean13' => 'references.ean13',
        'isbn' => 'references.isbn',
        'condition' => 'condition.condition',
        'show_condition' => 'condition.show_condition',
        'available_now' => 'stock.available_now',
        'available_later' => 'stock.available_later',
        'available_date' => 'stock.available_date',
        'minimal_quantity' => 'stock.minimal_quantity',
        'low_stock_threshold' => 'stock.low_stock_threshold',
        'low_stock_alert' => 'stock.low_stock_alert',
        'pack_stock_type' => 'stock.pack_stock_type',
        'width' => 'shipping.width',
        'height' => 'shipping.height',
        'depth' => 'shipping.depth',
        'weight' => 'shipping.weight',
        'additional_delivery_times' => 'shipping.additional_delivery_times',
        'delivery_in_stock' => 'shipping.delivery_in_stock',
        'delivery_out_stock' => 'shipping.delivery_out_stock',
        'additional_shipping_cost' => 'shipping.additional_shipping_cost',
        'price' => 'pricing.price_tax_excl',
        'id_tax_rules_group' => 'pricing.id_tax_rules_group',
        'wholesale_price' => 'pricing.wholesale_price',
        'unit_price' => 'pricing.unit_price_tax_excl',
        'unity' => 'pricing.unity',
        'on_sale' => 'pricing.on_sale',
        'ecotax' => 'pricing.ecotax_tax_excl',
        'meta_title' => 'seo.meta_title',
        'meta_description' => 'seo.meta_description',
        'meta_keywords' => 'seo.meta_keywords',
        'link_rewrite' => 'seo.link_rewrite',
        'redirect_type' => 'seo.redirect_type',
        'id_type_redirected' => 'seo.id_type_redirected',
        'visibility' => 'options.visibility',
        'available_for_order' => 'options.available_for_order',
        'show_price' => 'options.show_price',
        'online_only' => 'options.online_only',
        'active' => 'options.active',
        'id_supplier' => 'suppliers.id_supplier_default',
    ];

    /** @var array<string, string> Combination properties as the arguments name them. */
    private const COMBINATION_PATHS = [
        'price' => 'price_impact_tax_excl',
        'unit_price_impact' => 'unit_price_impact_tax_excl',
        'weight' => 'weight_impact',
    ];

    /** @var array<string, string> The removal lists, and what each one holds. */
    private const REMOVALS = [
        'remove_combinations' => 'id_product_attribute',
        'remove_images' => 'id_image',
        'remove_specific_prices' => 'id_specific_price',
        'remove_suppliers' => 'id_supplier',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_product',
            Somcp::t('Change a product'),
            'Changes one existing product of this shop. Everything somcp_create_product can write can be '
                . 'changed here, in the same groups, under the same names and with the same rules - who fills '
                . 'each field, lengths, identifiers from the somcp_list_* tools. Only what is given changes: a '
                . 'value left out is kept, a text is replaced in the languages filled and kept in the others, '
                . 'and a list that is a set (categories, related products, features, attachments, carriers, '
                . 'customization fields) replaces the current one entirely when given. In combinations, images '
                . 'and specific_prices, an item with its identifier changes that one, an item without adds one. '
                . 'pack.items given replaces the contents of a pack; a file given in virtual_file replaces the '
                . 'file of a virtual product, and its other fields change alone. '
                . 'Removing is only ever explicit, through remove_combinations, remove_images, '
                . 'remove_specific_prices, remove_suppliers, remove_pack_items and remove_virtual_file; a removed '
                . 'combination takes its stock with it and a removed file can no longer be downloaded, so name '
                . 'each one to the merchant first. Read the product with somcp_get_product before, show '
                . 'the merchant what will change, and call once they agree. When languages is left out every '
                . 'language of the shop is filled, so a text must then be given in each; to change one language, '
                . 'pass languages with it alone. A product that still has combinations cannot become another '
                . 'type. Changing link_rewrite on an active product breaks its old address: tell the merchant '
                . 'before. Every problem is reported at once and nothing is written until the whole call is '
                . 'valid; if writing still fails, the answer names what was already written. Images, the pack '
                . 'contents and the file are handled last and each is reported in images, pack_items and '
                . 'virtual_file without stopping the change. The answer gives each field changed with its old and '
                . 'new value. A call that would change nothing writes nothing and says changed: false. '
                . 'Do not use it to create a product (somcp_create_product), to switch one on or off alone '
                . '(somcp_set_product_active) or to delete one (somcp_delete_product). One product per call. '
                . 'Not to be retried blindly: if the answer is lost, read the product with somcp_get_product '
                . 'and compare before calling again - an image added by url would be added twice.',
            self::inputSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Changing a product is the edit right of the products page in
            // Team, Permissions; creating one is a different right.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminProducts')
        );
    }

    /**
     * The creation schema, made to name an existing product and its parts.
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
        $kept = ' Leave it out to keep the current one; given, it replaces it entirely.';

        unset($properties['basics']['required'], $properties['pricing']['required'], $properties['combinations']['items']['required'], $properties['images']['items']['required']);
        $properties['basics']['properties']['name']['description'] = 'Merchant. 128 characters at most, never empty. '
            . 'One text per language being filled, keyed by ISO code: {"fr": "...", "en": "..."}.';
        $properties['basics']['properties']['product_type']['description'] = 'Merchant. Changes as in the back office; '
            . 'a product that still has combinations stays combinations until they are removed, and a pack or a '
            . 'virtual product keeps its type while it has contents or a file.';
        $properties['pricing']['properties']['price_tax_excl']['description'] = 'Merchant. Selling price, tax excluded.';
        $properties['pricing']['properties']['id_tax_rules_group']['description'] = 'Merchant, from the shop: tax rule '
            . 'from somcp_list_tax_rules, 0 for no tax.';
        $properties['options']['properties']['active']['description'] = 'Merchant. Online or offline.';
        $properties['stock']['properties']['quantity']['description'] = 'Merchant. Quantity in stock; refused while the '
            . 'product has combinations, each of which has its own.';
        $properties['stock']['properties']['location']['description'] = 'Merchant. Where it is stored; refused while the '
            . 'product has combinations, each of which has its own.';
        $properties['pack']['properties']['items']['description'] .= ' Given, the list replaces the pack\'s '
            . 'contents entirely; to take some out without restating the others, use remove_pack_items.';
        unset($properties['virtual_file']['required']);
        $properties['virtual_file']['description'] = 'A virtual product only: the file customers download. Only '
            . 'the fields given change. A file given replaces the current one; file and display_filename are '
            . 'required when the product has no file yet.';
        $properties['seo']['properties']['link_rewrite']['description'] = 'Assistant. The friendly URL, only in the '
            . 'languages given. On an active product the old address stops working: tell the merchant first. '
            . 'One text per language being filled, keyed by ISO code: {"fr": "...", "en": "..."}.';
        $properties['categories']['properties']['categories']['description'] .= $kept;
        $properties['categories']['properties']['id_category_default']['description'] = 'Merchant, from the shop: the '
            . 'main category, one of the product\'s categories (added to them if missing).';
        $properties['related_products']['description'] .= $kept;
        $properties['features']['description'] .= $kept;
        $properties['attachments']['description'] .= $kept;
        $properties['shipping']['properties']['carriers']['description'] .= $kept;
        $properties['customization']['properties']['fields']['description'] .= $kept;
        $properties['suppliers']['properties']['id_supplier_default']['description'] = 'Merchant, from the shop: '
            . 'default supplier, one already associated or given in suppliers.';
        $properties['suppliers']['properties']['suppliers']['description'] = 'Merchant: per supplier, and per '
            . 'combination when it differs. A row already there has the values given replaced, the others kept.';

        $position = 'Position in combinations of this call, from 0. To point at an existing combination without '
            . 'changing it, list it in combinations with its id_product_attribute alone.';
        $properties['specific_prices']['properties']['rules']['items']['properties']['combination']['description'] = $position . ' Left out: unchanged, or the whole product for a new rule.';
        $properties['suppliers']['properties']['suppliers']['items']['properties']['combination']['description'] = $position . ' Left out: the product.';
        $properties['images']['items']['properties']['combinations']['description'] = $position . ' Given, replaces '
            . 'the combinations this image illustrates.';

        $properties['specific_prices']['properties']['rules']['description'] = 'Merchant: specific prices of this '
            . 'shop. With id_specific_price, that rule has the values given replaced; without, a rule is added.';
        $properties['specific_prices']['properties']['rules']['items']['properties'] = [
            'id_specific_price' => ['type' => 'integer', 'description' => 'A rule of this product, from somcp_get_product.'],
        ] + $properties['specific_prices']['properties']['rules']['items']['properties'];

        $properties['images']['description'] = 'Merchant and assistant. With id_image, that image has its legend, '
            . 'cover, position or combinations changed; with url or upload_ref, an image is added: a url is '
            . 'downloaded by the shop (public https only, no redirect, 10 MB at most), an upload_ref names a file '
            . 'already on the shop - attached, deposited or generated in the built-in assistant. JPEG, PNG, GIF '
            . 'or WebP. Never file content.';
        $properties['images']['items']['properties'] = [
            'id_image' => ['type' => 'integer', 'description' => 'An image of this product, from somcp_get_product.'],
        ] + $properties['images']['items']['properties'];
        $properties['images']['items']['properties']['url']['description'] = 'Public https address of an image to add. Give url or upload_ref, not both.';

        $properties['combinations']['description'] = 'Merchant and assistant. With id_product_attribute, that '
            . 'combination has the values given replaced and keeps the others; its attributes do not change. '
            . 'Without, a combination is added: attributes are then required, and two combinations cannot have '
            . 'the same ones.';
        $properties['combinations']['items']['properties'] = [
            'id_product_attribute' => ['type' => 'integer', 'description' => 'A combination of this product, from somcp_get_product.'],
        ] + $properties['combinations']['items']['properties'];

        $removals = [
            'remove_combinations' => 'Merchant: combinations to delete, with their stock, by id_product_attribute.',
            'remove_images' => 'Merchant: images to delete, by id_image.',
            'remove_specific_prices' => 'Merchant: specific prices to delete, by id_specific_price.',
            'remove_suppliers' => 'Merchant: suppliers to dissociate from the product and its combinations, by id_supplier.',
        ];
        foreach ($removals as $name => $description) {
            $properties[$name] = ['type' => 'array', 'description' => $description, 'items' => ['type' => 'integer']];
        }
        $properties['remove_pack_items'] = [
            'type' => 'array',
            'description' => 'Merchant: products to take out of the pack, as pack.items of somcp_get_product names '
                . 'them. Name each one to the merchant before the call. Not next to pack.',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id_product' => ['type' => 'integer'],
                    'id_product_attribute' => ['type' => 'integer', 'description' => '0 or left out for none.'],
                ],
                'required' => ['id_product'],
            ],
        ];
        $properties['remove_virtual_file'] = [
            'type' => 'boolean',
            'description' => 'Merchant: true deletes the downloadable file of the virtual product and its settings. '
                . 'Customers who bought it can no longer download it: say so to the merchant before the call. '
                . 'Not next to virtual_file.',
        ];

        $schema['properties'] = [
            'id_product' => ['type' => 'integer', 'description' => 'The product to change, from somcp_search_products.'],
        ] + $properties;
        $schema['required'] = ['id_product'];

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
        $shop = Somcp::currentShop();
        $idShop = (int) $shop->id;
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $product = self::existingProduct(self::integer($arguments['id_product'] ?? null) ?? 0, $idShop);
        if ($product === null) {
            return SomcpTool::text('No product of this shop has this identifier. Nothing was changed. Find it with somcp_search_products.', true);
        }

        $errors = [];
        $plan = self::planUpdate($arguments, $product, $shop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
        }

        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_product' => (int) $product->id,
                'reason' => 'The product already holds every value given. Nothing was written.',
            ]);
        }

        return self::asEmployee($idEmployee, static function () use ($product, $plan, $context, $idShop, $idEmployee): array {
            return self::applyUpdate($product, $plan, $context, $idShop, $idEmployee);
        });
    }

    /**
     * The product as this shop holds it, every language loaded.
     *
     * @param int $idProduct
     * @param int $idShop
     *
     * @return Product|null
     */
    private static function existingProduct(int $idProduct, int $idShop): ?Product
    {
        if ($idProduct <= 0) {
            return null;
        }
        $product = new Product($idProduct, false, null, $idShop);
        // As somcp_get_product: a product of another shop, or one the back
        // office is still creating, is answered like one that does not exist.
        if (!Validate::isLoadedObject($product) || !$product->isAssociatedToShop($idShop) || (int) $product->state !== Product::STATE_SAVED) {
            return null;
        }

        return $product;
    }

    /**
     * Writes the change, then the images, and answers with what was stored.
     *
     * @param Product $product
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $context
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function applyUpdate(Product $product, array $plan, array $context, int $idShop, int $idEmployee): array
    {
        $result = self::writeSteps($product, $plan, $idShop);
        $written = $result['written'];
        $combinationIds = $result['ids'];
        if ($result['error'] !== null) {
            SomcpLogger::log(
                'product_update_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'product ' . (int) $product->id . ': ' . Tools::substr($result['error'], 0, 500)
                    . ($written === [] ? '' : ', already written: ' . implode(', ', $written)),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The product could not be changed: ' . $result['error'] . '. '
                    . ($written === []
                        ? 'No step was complete before the failure, but the one that failed may be partly written. '
                        : 'Already written before the failure: ' . implode(', ', $written) . '; the step that failed '
                            . 'may be partly written, and the rest was not written. ')
                    . 'Read the product with somcp_get_product, tell the merchant what it holds now, and do not '
                    . 'call again before.',
                true
            );
        }

        // Read again from the database: the hooks of the write may have
        // changed the object, and the images and warnings need what was stored.
        $stored = new Product((int) $product->id, false, null, $idShop);
        $images = self::applyImages($stored, $plan, $combinationIds, $idShop);
        $packItems = $plan['pack'] === null ? null : self::replacePack((int) $product->id, $plan['pack']);
        $virtualFile = $plan['virtual_file'] === null ? null : self::applyVirtualFile((int) $product->id, $plan['virtual_file']);

        if ($plan['index']) {
            // What the back office does after saving a product: one the search
            // can find is indexed, one it can no longer find leaves the index.
            if ($stored->active && in_array($stored->visibility, ['both', 'search'], true)) {
                if (Configuration::get('PS_SEARCH_INDEXATION')) {
                    Search::indexation(false, (int) $stored->id);
                }
            } else {
                Search::removeProductsSearchIndex([(int) $stored->id]);
            }
        }

        $warnings = self::updateWarnings($stored, $plan, $idShop);

        SomcpLogger::log(
            'product_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'product ' . (int) $product->id . ', ' . count($plan['changes']) . ' change(s)',
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'changed' => true,
            'id_product' => (int) $product->id,
            'changes' => $plan['changes'],
            'seo_warnings' => $warnings === [] ? new stdClass() : $warnings,
            'images' => $images,
            'pack_items' => $packItems,
            'virtual_file' => $virtualFile,
            'product' => self::read((int) $product->id, (string) $plan['read_language'], $context),
        ]);
    }

    /*
     * Planning: what the arguments ask for, checked, and compared with the shop.
     */

    /**
     * Turns the arguments into what will be written, or says everything that is wrong with them.
     *
     * Nothing is written here. A value equal to what the shop holds is left
     * out of the plan, so what remains is exactly what will change.
     *
     * @param array<string, mixed> $arguments
     * @param Product $product
     * @param Shop $shop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function planUpdate(array $arguments, Product $product, Shop $shop, array &$errors): ?array
    {
        $idShop = (int) $shop->id;
        $groups = [];
        foreach (array_keys(self::ACCEPTED) as $group) {
            if (in_array($group, self::NESTED, true)) {
                continue;
            }
            $groups[$group] = self::group($arguments, $group, $errors);
        }
        $lists = ['id_product', 'languages', 'related_products', 'features', 'attachments', 'images', 'combinations', 'remove_pack_items', 'remove_virtual_file'];
        foreach (array_keys($arguments) as $key) {
            if (!isset($groups[$key]) && !in_array($key, $lists, true) && !isset(self::REMOVALS[$key])) {
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
            'read_language' => $languages['filled'][$languages['fallback']],
            'fields' => [],
            'texts' => [],
            'changes' => [],
            'touched' => [],
            'index' => false,
        ];

        // The creation's own readings, for the groups whose fields are single
        // values: they only fill what is given, and the comparison below keeps
        // what differs.
        self::basics($groups['basics'], $languages, $probe, $plan, $errors, false);
        self::brand($groups['brand'], $plan, $errors);
        self::references($groups['references'], $probe, $plan, $errors);
        self::condition($groups['condition'], $probe, $plan, $errors);
        self::stock($groups['stock'], $languages, $probe, $plan, $errors);
        self::shipping($groups['shipping'], $languages, $idShop, $probe, $plan, $errors);
        self::pricing($groups['pricing'], $probe, $plan, $errors, false);
        self::options($groups['options'], $probe, $plan, $errors);
        self::planSeo($groups['seo'], $languages, $product, $idShop, $probe, $plan, $errors);

        // Combinations first among the lists: the others point at them by
        // their position in this call.
        $combinations = self::planCombinations($arguments, $product, $languages, $idShop, $plan, $errors);
        self::planType($groups['basics'], $product, $combinations, $plan, $errors);
        $isPack = (bool) ($plan['fields']['cache_is_pack'] ?? ((string) $product->getProductType() === PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::TYPE_PACK));
        $isVirtual = (bool) ($plan['fields']['is_virtual'] ?? (bool) $product->is_virtual);
        if (isset($plan['fields']['pack_stock_type']) && !$isPack) {
            $errors[] = 'stock.pack_stock_type: a pack only';
        }
        self::planPack($arguments, $groups['pack'], $product, $isPack, $idShop, $plan, $errors);
        self::planVirtualFile($arguments, $groups['virtual_file'], $product, $isVirtual, $plan, $errors);
        self::planStock($product, $combinations, $idShop, $plan, $errors);
        self::planCategories($groups['categories'], $product, $shop, $plan, $errors);
        self::planSets($arguments, $groups, $product, $languages, $idShop, $plan, $errors);
        self::planSpecificPrices($arguments, $groups['specific_prices'], $product, $idShop, $plan, $errors);
        self::planSuppliers($arguments, $groups['suppliers'], $product, $idShop, $plan, $errors);
        self::planImages($arguments, $product, $languages, $idShop, $plan, $errors);

        $references = [];
        if (isset($plan['fields']['reference']) && $plan['fields']['reference'] !== '') {
            $references['references.reference'] = (string) $plan['fields']['reference'];
        }
        foreach ($plan['combinations']['items'] as $i => $item) {
            if (($item['fields']['reference'] ?? '') !== '') {
                $references['combinations[' . $i . '].reference'] = (string) $item['fields']['reference'];
            }
        }
        // The product's own references do not count: the demo data, and many
        // catalogues, give a combination the reference of its product.
        self::referencesFree($references, (int) $product->id, $errors);

        if ($errors !== []) {
            return $plan;
        }

        self::compareProduct($product, $plan);

        // The same check the core runs in update(), on the whole object as it
        // will be written, as a last net for a rule none of the above knew.
        if ($plan['product'] !== []) {
            $candidate = clone $product;
            self::fill($candidate, $plan['product']);
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'product: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * Keeps the product fields and texts that differ from the shop, and records each one.
     *
     * @param Product $product
     * @param array<string, mixed> $plan
     *
     * @return void
     */
    private static function compareProduct(Product $product, array &$plan): void
    {
        $plan['product'] = [];
        $languages = $plan['languages'];

        foreach ($plan['fields'] as $field => $value) {
            $old = $product->{$field} ?? null;
            if (self::same($old, $value)) {
                continue;
            }
            $plan['product'][$field] = $value;
            if (isset(self::PATHS[$field])) {
                self::record($plan, self::PATHS[$field], self::shown($field, $old, $value), self::shown($field, $value, $value));
            }
        }

        foreach ($plan['texts'] as $field => $texts) {
            $current = is_array($product->{$field} ?? null) ? $product->{$field} : [];
            $merged = $current;
            foreach ($texts as $idLang => $text) {
                $old = (string) ($current[$idLang] ?? '');
                if ($old === $text) {
                    continue;
                }
                $merged[$idLang] = $text;
                self::record($plan, (self::PATHS[$field] ?? $field) . ' (' . $languages['filled'][$idLang] . ')', $old, $text);
                $plan['touched'][$idLang] = true;
            }
            if ($merged !== $current) {
                $plan['product'][$field] = $merged;
            }
        }

        // Whether the product can be found by the search may have changed.
        $plan['index'] = $plan['product'] !== [];
    }

    /**
     * @param array<string, mixed> $group
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param Product $product
     * @param int $idShop
     * @param Product $probe
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function planSeo(array $group, array $languages, Product $product, int $idShop, Product $probe, array &$plan, array &$errors): void
    {
        self::metaTexts($group, $languages, $probe, $plan, $errors);
        // Tags are planned like at creation, then compared with the product's
        // own: a language whose tags are unchanged is dropped, so a call that
        // only repeats them writes nothing. writeUpdate replaces the rest; a
        // language left out of the call keeps the tags it had.
        $plan['tags'] = self::tagPlan($group, $languages, $errors);
        if ($plan['tags'] !== []) {
            // getProductTags() answers false when the product has no tag yet.
            $existing = Tag::getProductTags((int) $product->id);
            $existing = is_array($existing) ? $existing : [];
            foreach ($plan['tags'] as $idLang => $tags) {
                $old = isset($existing[$idLang]) && is_array($existing[$idLang]) ? array_map('strval', $existing[$idLang]) : [];
                $a = $old;
                $b = $tags;
                sort($a);
                sort($b);
                if ($a === $b) {
                    unset($plan['tags'][$idLang]);
                    continue;
                }
                self::record($plan, 'seo.tags (' . $languages['filled'][$idLang] . ')', implode(', ', $old), implode(', ', $tags));
            }
        }

        // Unlike at creation, the friendly URL is not made again from a new
        // name: the page already has an address, and moving it is a decision.
        if (array_key_exists('link_rewrite', $group)) {
            $sources = [];
            foreach (self::texts($group, 'link_rewrite', 'seo.link_rewrite', $languages, $errors) ?? [] as $idLang => $text) {
                $sources[$idLang] = [$text, 'it'];
            }
            $plan['texts']['link_rewrite'] = self::linkRewrites($sources, $languages, $probe, $errors);
        }

        if (!array_key_exists('redirect_type', $group) && !array_key_exists('id_type_redirected', $group)) {
            return;
        }
        $type = (string) $product->redirect_type;
        if (array_key_exists('redirect_type', $group)) {
            $type = self::redirectType($group['redirect_type'], $errors);
            if ($type === null) {
                return;
            }
        }
        // A target is kept only while the redirection still goes somewhere.
        $target = in_array($type, ['301-product', '302-product', '301-category', '302-category'], true) ? (int) $product->id_type_redirected : 0;
        if (array_key_exists('id_type_redirected', $group)) {
            $target = self::integer($group['id_type_redirected']);
            if ($target === null || $target < 0) {
                $errors[] = 'seo.id_type_redirected: an identifier';

                return;
            }
        }
        if (in_array($type, ['301-product', '302-product'], true) && $target === (int) $product->id) {
            $errors[] = 'seo.id_type_redirected: a product cannot redirect to itself';

            return;
        }
        self::redirection($type, $target, $idShop, $errors);
        $plan['fields']['redirect_type'] = $type;
        $plan['fields']['id_type_redirected'] = $target;
    }

    /**
     * The product type, which depends on the combinations it will have.
     *
     * @param array<string, mixed> $group
     * @param Product $product
     * @param int $combinations how many the product will have after this call
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function planType(array $group, Product $product, int $combinations, array &$plan, array &$errors): void
    {
        $types = PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::AVAILABLE_TYPES;
        $standard = PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::TYPE_STANDARD;
        $withCombinations = PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::TYPE_COMBINATIONS;
        $pack = PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::TYPE_PACK;
        $virtual = PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\ProductType::TYPE_VIRTUAL;
        $current = (string) $product->getProductType();
        $type = $current;

        if (array_key_exists('product_type', $group)) {
            $given = self::string($group['product_type']);
            if ($given === null || !in_array($given, $types, true)) {
                $errors[] = 'basics.product_type: one of ' . implode(', ', $types);

                return;
            }
            if ($combinations > 0 && $given !== $withCombinations) {
                // Changing the type in the back office deletes them without a
                // word. Here they are removed by name, so nobody loses stock
                // they did not mean to.
                $errors[] = 'basics.product_type: the product still has combinations; list them in remove_combinations first, naming each one to the merchant';

                return;
            }
            if ($current === $pack && $given !== $pack && Db::getInstance()->getValue(
                'SELECT 1 FROM `' . _DB_PREFIX_ . 'pack` WHERE `id_product_pack` = ' . (int) $product->id
            )) {
                $errors[] = 'basics.product_type: the pack still has contents; remove them first with remove_pack_items, in a call of their own';

                return;
            }
            if ($current === $virtual && $given !== $virtual && (int) ProductDownload::getIdFromIdProduct((int) $product->id, false) > 0) {
                $errors[] = 'basics.product_type: the virtual product still has its file; remove it first with remove_virtual_file, in a call of their own';

                return;
            }
            $type = $given;
        } elseif ($combinations > 0 && $current === $standard) {
            // What the creation does: combinations make a combinations product.
            $type = $withCombinations;
        } elseif ($combinations > 0 && $current !== $withCombinations) {
            $errors[] = 'combinations: a ' . $current . ' product cannot have combinations';

            return;
        }

        if ($type === $current) {
            return;
        }
        $plan['fields']['product_type'] = $type;
        $plan['fields']['is_virtual'] = $type === $virtual;
        $plan['fields']['cache_is_pack'] = $type === $pack;
    }

    /**
     * The product's own stock, compared with what the shop holds.
     *
     * @param Product $product
     * @param int $combinations how many the product will have after this call
     * @param int $idShop
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function planStock(Product $product, int $combinations, int $idShop, array &$plan, array &$errors): void
    {
        $idProduct = (int) $product->id;
        $stock = $plan['stock'];
        $plan['stock'] = ['quantity' => null, 'location' => null, 'out_of_stock' => null];

        // With combinations, the product's quantity is their total, and the
        // core writes a product location on every row it has.
        foreach (['quantity', 'location'] as $field) {
            if ($stock[$field] !== null && $combinations > 0) {
                $errors[] = 'stock.' . $field . ': the product has combinations, and each one has its own; give it to them';
                $stock[$field] = null;
            }
        }

        if ($stock['quantity'] !== null) {
            $old = (int) StockAvailable::getQuantityAvailableByProduct($idProduct, 0, $idShop);
            if ($old !== $stock['quantity']) {
                $plan['stock']['quantity'] = $stock['quantity'];
                self::record($plan, 'stock.quantity', $old, $stock['quantity']);
            }
        }
        if ($stock['location'] !== null) {
            $old = (string) StockAvailable::getLocation($idProduct, 0, $idShop);
            if ($old !== $stock['location']) {
                $plan['stock']['location'] = $stock['location'];
                self::record($plan, 'stock.location', $old, $stock['location']);
            }
        }
        if ($stock['out_of_stock'] !== null) {
            $old = (int) StockAvailable::outOfStock($idProduct, $idShop);
            if ($old !== $stock['out_of_stock']) {
                $plan['stock']['out_of_stock'] = $stock['out_of_stock'];
                $words = array_flip(self::OUT_OF_STOCK);
                self::record($plan, 'stock.out_of_stock', $words[$old] ?? $old, $words[$stock['out_of_stock']]);
            }
        }
    }

    /**
     * @param array<string, mixed> $group
     * @param Product $product
     * @param Shop $shop
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function planCategories(array $group, Product $product, Shop $shop, array &$plan, array &$errors): void
    {
        $plan['categories'] = null;
        if (!array_key_exists('categories', $group) && !array_key_exists('id_category_default', $group)) {
            return;
        }

        $current = array_map('intval', Product::getProductCategories((int) $product->id) ?: []);
        $categories = array_key_exists('categories', $group)
            ? array_values(array_unique(self::ids($group['categories'], 'id_category', 'categories.categories', $errors)))
            : $current;

        $default = (int) $product->id_category_default;
        if (array_key_exists('id_category_default', $group)) {
            $default = self::integer($group['id_category_default']) ?? 0;
            if ($default <= 0) {
                $errors[] = 'categories.id_category_default: a category identifier';

                return;
            }
        } elseif (!in_array($default, $categories, true)) {
            // The main category was taken out of the set: the first one left
            // takes its place, as at creation.
            $default = $categories[0] ?? 0;
        }
        if ($default <= 0) {
            $errors[] = 'categories.categories: at least one category';

            return;
        }
        if (!in_array($default, $categories, true)) {
            array_unshift($categories, $default);
        }

        $root = (int) Configuration::get('PS_ROOT_CATEGORY');
        foreach (array_diff($categories, $current) as $idCategory) {
            $category = new Category($idCategory);
            if (!Validate::isLoadedObject($category) || $idCategory === $root || !$category->isAssociatedToShop((int) $shop->id)) {
                $errors[] = 'categories: no category ' . $idCategory . ' in this shop. Take identifiers from somcp_list_categories.';
            }
        }
        if (!in_array($default, $current, true) && $default === $root) {
            $errors[] = 'categories.id_category_default: not the root category';
        }

        $plan['fields']['id_category_default'] = $default;
        if (!self::sameSet($current, $categories)) {
            $plan['categories'] = $categories;
            self::record($plan, 'categories.categories', self::sorted($current), self::sorted($categories));
        }
    }

    /**
     * The sets a merchant chooses as a whole: related products, features, attachments, carriers, customization fields.
     *
     * Each one given replaces the current one, and is only written when it differs.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, array<string, mixed>> $groups
     * @param Product $product
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idShop
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function planSets(array $arguments, array $groups, Product $product, array $languages, int $idShop, array &$plan, array &$errors): void
    {
        $idProduct = (int) $product->id;
        $db = Db::getInstance();
        $column = static function (string $sql, string $key): array {
            return array_map('intval', array_column(Db::getInstance()->executeS($sql) ?: [], $key));
        };

        $plan['related_products'] = null;
        if (array_key_exists('related_products', $arguments)) {
            $ids = self::relatedProducts($arguments['related_products'], $idShop, $errors);
            if (in_array($idProduct, $ids, true)) {
                $errors[] = 'related_products: a product cannot be related to itself';
            }
            $current = $column('SELECT `id_product_2` FROM `' . _DB_PREFIX_ . 'accessory` WHERE `id_product_1` = ' . $idProduct, 'id_product_2');
            if (!self::sameSet($current, $ids)) {
                $plan['related_products'] = $ids;
                self::record($plan, 'related_products', self::sorted($current), self::sorted($ids));
            }
        }

        $plan['attachments'] = null;
        if (array_key_exists('attachments', $arguments)) {
            $ids = self::attachments($arguments['attachments'], $errors) ?? [];
            $current = $column('SELECT `id_attachment` FROM `' . _DB_PREFIX_ . 'product_attachment` WHERE `id_product` = ' . $idProduct, 'id_attachment');
            if (!self::sameSet($current, $ids)) {
                $plan['attachments'] = $ids;
                self::record($plan, 'attachments', self::sorted($current), self::sorted($ids));
            }
        }

        // shipping() read the carriers as references, which is what a product
        // is tied to; null when they were not given.
        if ($plan['carriers'] !== null) {
            $current = $column(
                'SELECT `id_carrier_reference` FROM `' . _DB_PREFIX_ . 'product_carrier`'
                . ' WHERE `id_product` = ' . $idProduct . ' AND `id_shop` = ' . $idShop,
                'id_carrier_reference'
            );
            if (self::sameSet($current, $plan['carriers'])) {
                $plan['carriers'] = null;
            } else {
                self::record($plan, 'shipping.carriers', self::sorted($current), self::sorted($plan['carriers']));
            }
        }

        $plan['features'] = null;
        if (array_key_exists('features', $arguments)) {
            $features = self::features($arguments['features'], $languages, $errors);
            $current = $db->executeS(
                'SELECT fp.`id_feature`, fp.`id_feature_value`, fv.`custom` FROM `' . _DB_PREFIX_ . 'feature_product` fp'
                . ' LEFT JOIN `' . _DB_PREFIX_ . 'feature_value` fv ON fv.`id_feature_value` = fp.`id_feature_value`'
                . ' WHERE fp.`id_product` = ' . $idProduct
            ) ?: [];
            $customs = [];
            foreach ($current as $row) {
                if ((int) $row['custom'] === 1) {
                    $value = new FeatureValue((int) $row['id_feature_value']);
                    $customs[(int) $row['id_feature']] = is_array($value->value) ? $value->value : [];
                }
            }
            $old = [];
            foreach ($current as $row) {
                $old[] = (int) $row['custom'] === 1
                    ? $row['id_feature'] . ':' . self::textsKey($customs[(int) $row['id_feature']] ?? [], $languages)
                    : $row['id_feature'] . '-' . $row['id_feature_value'];
            }
            $new = [];
            foreach ($features as $i => $feature) {
                if ($feature['value'] === null) {
                    $new[] = $feature['id_feature'] . '-' . $feature['id_feature_value'];
                    continue;
                }
                // A custom value typed in some languages keeps its text in the
                // others, as any other translatable text does.
                $features[$i]['value'] = $feature['value'] + ($customs[$feature['id_feature']] ?? []);
                $new[] = $feature['id_feature'] . ':' . self::textsKey($feature['value'], $languages);
            }
            sort($old);
            sort($new);
            if ($old !== $new) {
                $plan['features'] = $features;
                self::record($plan, 'features', count($current) . ' feature value(s)', count($features) . ' feature value(s)');
            }
        }

        $plan['customization'] = null;
        if (array_key_exists('fields', $groups['customization'])) {
            $step = ['fields' => []];
            self::customization($groups['customization'], $languages, $step, $errors);
            $fields = $step['customization'];
            $current = self::currentCustomization($idProduct, $idShop, $languages);
            $new = [];
            foreach ($fields as $field) {
                $new[] = $field['type'] . '-' . (int) $field['required'] . '-' . self::textsKey($field['name'], $languages);
            }
            if ($current['keys'] !== $new) {
                $plan['customization'] = $fields;
                // The three counters the product page derives from the fields.
                $plan['fields']['customizable'] = $step['fields']['customizable'] ?? 0;
                $plan['fields']['uploadable_files'] = $step['fields']['uploadable_files'] ?? 0;
                $plan['fields']['text_fields'] = $step['fields']['text_fields'] ?? 0;
                self::record($plan, 'customization.fields', count($current['keys']) . ' field(s)', count($fields) . ' field(s)');
            }
        }
    }

    /**
     * The customization fields a merchant set, in the order they were made.
     *
     * Fields another module declared are its own and left out, as are the ones
     * already deleted but kept because an order used them.
     *
     * @param int $idProduct
     * @param int $idShop
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     *
     * @return array{ids: array<int, int>, keys: array<int, string>}
     */
    private static function currentCustomization(int $idProduct, int $idShop, array $languages): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_customization_field`, `type`, `required` FROM `' . _DB_PREFIX_ . 'customization_field`'
            . ' WHERE `id_product` = ' . $idProduct . ' AND `is_deleted` = 0 AND `is_module` = 0'
            . ' ORDER BY `id_customization_field` ASC'
        ) ?: [];
        $ids = array_map('intval', array_column($rows, 'id_customization_field'));
        $names = [];
        if ($ids !== []) {
            foreach (Db::getInstance()->executeS(
                'SELECT `id_customization_field`, `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'customization_field_lang`'
                . ' WHERE `id_shop` = ' . $idShop . ' AND `id_customization_field` IN (' . implode(',', $ids) . ')'
            ) ?: [] as $row) {
                $names[(int) $row['id_customization_field']][(int) $row['id_lang']] = (string) $row['name'];
            }
        }
        $keys = [];
        foreach ($rows as $row) {
            $keys[] = (int) $row['type'] . '-' . (int) $row['required'] . '-'
                . self::textsKey($names[(int) $row['id_customization_field']] ?? [], $languages);
        }

        return ['ids' => $ids, 'keys' => $keys];
    }

    /**
     * Combinations to remove, change and add.
     *
     * @param array<string, mixed> $arguments
     * @param Product $product
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idShop
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return int how many combinations the product will have after this call
     */
    private static function planCombinations(array $arguments, Product $product, array $languages, int $idShop, array &$plan, array &$errors): int
    {
        $idProduct = (int) $product->id;
        $existing = [];
        foreach (Db::getInstance()->executeS(
            'SELECT pa.`id_product_attribute`, pas.`default_on`, GROUP_CONCAT(pac.`id_attribute` ORDER BY pac.`id_attribute` SEPARATOR \'-\') AS `attributes`'
            . ' FROM `' . _DB_PREFIX_ . 'product_attribute` pa'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas ON pas.`id_product_attribute` = pa.`id_product_attribute` AND pas.`id_shop` = ' . $idShop
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac ON pac.`id_product_attribute` = pa.`id_product_attribute`'
            . ' WHERE pa.`id_product` = ' . $idProduct
            . ' GROUP BY pa.`id_product_attribute`, pas.`default_on`'
        ) ?: [] as $row) {
            $existing[(int) $row['id_product_attribute']] = ['default' => (bool) $row['default_on'], 'attributes' => (string) $row['attributes']];
        }

        $remove = self::removals($arguments, 'remove_combinations', array_keys($existing), 'combination of this product', $errors);
        foreach ($remove as $id) {
            self::record($plan, 'combinations[id_product_attribute=' . $id . ']', 'exists', 'removed');
        }

        $sets = [];
        foreach ($existing as $id => $combination) {
            if (!in_array($id, $remove, true)) {
                $sets[$combination['attributes']] = 'combination ' . $id;
            }
        }

        $items = [];
        $named = [];
        $defaults = [];
        $added = 0;
        foreach (self::items($arguments['combinations'] ?? null, 'combinations', $errors) as $i => $item) {
            $path = 'combinations[' . $i . ']';

            if (array_key_exists('id_product_attribute', $item)) {
                $id = self::integer($item['id_product_attribute']) ?? 0;
                unset($item['id_product_attribute']);
                self::onlyAccepted($item, 'combination', $path, $errors);
                if (!isset($existing[$id])) {
                    $errors[] = $path . '.id_product_attribute: not a combination of this product in this shop';
                    continue;
                }
                if (in_array($id, $remove, true) || isset($named[$id])) {
                    $errors[] = $path . '.id_product_attribute: combination ' . $id . ' is named twice in this call';
                    continue;
                }
                $named[$id] = true;
                if (array_key_exists('attributes', $item)) {
                    $errors[] = $path . '.attributes: the attributes of a combination do not change; remove it and add another';
                    continue;
                }
                $combination = self::combinationItem($item, $path, $languages, [], $errors);
                $combination['id'] = $id;
                if (($combination['fields']['default_on'] ?? null) === true) {
                    $defaults[] = $i;
                } elseif (($combination['fields']['default_on'] ?? null) === false && $existing[$id]['default']) {
                    $errors[] = $path . '.default_on: to change the default combination, set default_on on the new one';
                }
                // The default is set apart, through the product, so that the
                // core's one-default rule holds at every step.
                unset($combination['fields']['default_on']);
                self::compareCombination($product, $combination, $path, $languages, $idShop, $plan);
                $items[$i] = $combination;
                continue;
            }

            self::onlyAccepted($item, 'combination', $path, $errors);
            $attributes = self::attributeSet($item['attributes'] ?? null, $path, $errors);
            $set = implode('-', $attributes);
            if ($set !== '' && isset($sets[$set])) {
                $errors[] = $path . ': the same attributes as ' . $sets[$set];
            }
            $sets[$set] = 'combinations[' . $i . ']';
            $combination = self::combinationItem($item, $path, $languages, ['minimal_quantity' => 1, 'default_on' => false], $errors);
            $combination['id'] = 0;
            $combination['attributes'] = $attributes;
            if ($combination['fields']['default_on'] === true) {
                $defaults[] = $i;
            }
            $combination['fields']['default_on'] = false;
            $items[$i] = $combination;
            ++$added;
            self::record($plan, $path, null, 'added, attributes ' . implode(', ', $attributes));
        }

        if (count($defaults) > 1) {
            $errors[] = 'combinations: only one can be the default';
        }
        $remaining = count($existing) - count($remove);
        $default = $defaults[0] ?? null;
        if ($default !== null && $items[$default]['id'] > 0 && $existing[$items[$default]['id']]['default']) {
            // Already the default: nothing to move.
            $default = null;
        } elseif ($default !== null) {
            self::record($plan, 'combinations[' . $default . '].default_on', false, true);
        } elseif ($remaining === 0 && $added > 0) {
            // A product getting its first combinations: the first one is the
            // default, as at creation.
            $default = (int) array_key_first(array_filter($items, static function (array $item): bool {
                return $item['id'] === 0;
            }));
        }

        $plan['combinations'] = ['remove' => $remove, 'items' => $items, 'default' => $default];

        return $remaining + $added;
    }

    /**
     * Keeps the fields of an existing combination that differ, and records each one.
     *
     * @param Product $product
     * @param array<string, mixed> $combination as read by combinationItem(), with its id
     * @param string $path
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idShop
     * @param array<string, mixed> $plan
     *
     * @return void
     */
    private static function compareCombination(Product $product, array &$combination, string $path, array $languages, int $idShop, array &$plan): void
    {
        $id = (int) $combination['id'];
        $object = new Combination($id, null, $idShop);
        $where = $path . ' (id_product_attribute ' . $id . ')';

        foreach ($combination['fields'] as $field => $value) {
            $old = $object->{$field} ?? null;
            if (self::same($old, $value)) {
                unset($combination['fields'][$field]);
                continue;
            }
            self::record($plan, $where . '.' . (self::COMBINATION_PATHS[$field] ?? $field), self::shown($field, $old, $value), $value);
        }
        foreach ($combination['texts'] as $field => $texts) {
            $current = is_array($object->{$field} ?? null) ? $object->{$field} : [];
            $merged = $current;
            foreach ($texts as $idLang => $text) {
                $old = (string) ($current[$idLang] ?? '');
                if ($old !== $text) {
                    $merged[$idLang] = $text;
                    self::record($plan, $where . '.' . $field . ' (' . $languages['filled'][$idLang] . ')', $old, $text);
                }
            }
            if ($merged === $current) {
                unset($combination['texts'][$field]);
            } else {
                $combination['texts'][$field] = $merged;
            }
        }
        if ($combination['quantity'] !== null) {
            $old = (int) StockAvailable::getQuantityAvailableByProduct((int) $product->id, $id, $idShop);
            if ($old === $combination['quantity']) {
                $combination['quantity'] = null;
            } else {
                self::record($plan, $where . '.quantity', $old, $combination['quantity']);
            }
        }
        if ($combination['location'] !== null) {
            $old = (string) StockAvailable::getLocation((int) $product->id, $id, $idShop);
            if ($old === $combination['location']) {
                $combination['location'] = null;
            } else {
                self::record($plan, $where . '.location', $old, $combination['location']);
            }
        }
    }

    /**
     * Specific prices to remove, change and add, and their priorities.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $group
     * @param Product $product
     * @param int $idShop
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function planSpecificPrices(array $arguments, array $group, Product $product, int $idShop, array &$plan, array &$errors): void
    {
        $idProduct = (int) $product->id;
        // The rules somcp_get_product lists: this shop's or every shop's,
        // neither a cart's nor a catalogue price rule's, which have their own pages.
        $owned = array_map('intval', array_column(Db::getInstance()->executeS(
            'SELECT `id_specific_price` FROM `' . _DB_PREFIX_ . 'specific_price`'
            . ' WHERE `id_product` = ' . $idProduct . ' AND `id_shop` IN (0, ' . $idShop . ')'
            . ' AND `id_cart` = 0 AND `id_specific_price_rule` = 0'
        ) ?: [], 'id_specific_price'));

        $remove = self::removals($arguments, 'remove_specific_prices', $owned, 'specific price of this product', $errors);
        foreach ($remove as $id) {
            self::record($plan, 'specific_prices.rules[id_specific_price=' . $id . ']', 'exists', 'removed');
        }

        $listed = count($plan['combinations']['items']);
        $rules = [];
        $named = [];
        foreach (self::items($group['rules'] ?? null, 'specific_prices.rules', $errors) as $i => $item) {
            $path = 'specific_prices.rules[' . $i . ']';
            if (!array_key_exists('id_specific_price', $item)) {
                self::onlyAccepted($item, 'rule', $path, $errors);
                $rule = self::rule($item, $path, [
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
                ], $listed, $idShop, $errors);
                $rules[] = ['id' => 0, 'path' => $path, 'rule' => $rule];
                self::record($plan, $path, null, 'added');
                continue;
            }

            $id = self::integer($item['id_specific_price']) ?? 0;
            unset($item['id_specific_price']);
            self::onlyAccepted($item, 'rule', $path, $errors);
            if (!in_array($id, $owned, true)) {
                $errors[] = $path . '.id_specific_price: not a specific price of this product in this shop';
                continue;
            }
            if (in_array($id, $remove, true) || isset($named[$id])) {
                $errors[] = $path . '.id_specific_price: rule ' . $id . ' is named twice in this call';
                continue;
            }
            $named[$id] = true;
            $object = new SpecificPrice($id);
            $base = [
                'combination' => null,
                'id_currency' => (int) $object->id_currency,
                'id_country' => (int) $object->id_country,
                'id_group' => (int) $object->id_group,
                'id_customer' => (int) $object->id_customer,
                'from_quantity' => (int) $object->from_quantity,
                'price' => (float) $object->price,
                'reduction' => (float) $object->reduction,
                'reduction_type' => (string) $object->reduction_type,
                'reduction_tax' => (int) $object->reduction_tax,
                'from' => (string) $object->from,
                'to' => (string) $object->to,
            ];
            $rule = self::rule($item, $path, $base, $listed, $idShop, $errors);
            $changed = false;
            foreach ($rule as $field => $value) {
                if ($field === 'combination') {
                    if ($value === null) {
                        continue;
                    }
                    $target = (int) ($plan['combinations']['items'][$value]['id'] ?? 0);
                    if ($target > 0 && $target === (int) $object->id_product_attribute) {
                        $rule['combination'] = null;
                        continue;
                    }
                    self::record($plan, $path . ' (id_specific_price ' . $id . ').combination', (int) $object->id_product_attribute, $target > 0 ? $target : 'combinations[' . $value . ']');
                    $changed = true;
                    continue;
                }
                if (!self::same($base[$field], $value)) {
                    self::record($plan, $path . ' (id_specific_price ' . $id . ').' . $field, $base[$field], $value);
                    $changed = true;
                }
            }
            if ($changed) {
                $rules[] = ['id' => $id, 'path' => $path, 'rule' => $rule];
            }
        }

        self::distinctRules($idProduct, $idShop, $owned, $remove, $rules, $plan, $errors);

        $priorities = self::priorities($group, $errors);
        if ($priorities !== null) {
            $current = array_values(array_filter(SpecificPrice::getPriority($idProduct), static function ($value): bool {
                return $value !== 'id_customer';
            }));
            if ($current === $priorities) {
                $priorities = null;
            } else {
                self::record($plan, 'specific_prices.priorities', $current, $priorities);
            }
        }

        $plan['specific_prices'] = ['remove' => $remove, 'rules' => $rules, 'priorities' => $priorities];
    }

    /**
     * Refuses two rules that would apply to exactly the same buyers.
     *
     * The database holds one rule per product, shop, currency, country,
     * group, customer, combination, quantity and dates; a second one fails
     * half way through the writes, so it is caught here, before any of them.
     *
     * @param int $idProduct
     * @param int $idShop
     * @param array<int, int> $owned
     * @param array<int, int> $remove
     * @param array<int, array{id: int, path: string, rule: array<string, mixed>}> $rules
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function distinctRules(int $idProduct, int $idShop, array $owned, array $remove, array $rules, array $plan, array &$errors): void
    {
        $final = [];
        foreach (Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'specific_price` WHERE `id_product` = ' . $idProduct
        ) ?: [] as $row) {
            $id = (int) $row['id_specific_price'];
            if (in_array($id, $owned, true) && !in_array($id, $remove, true)) {
                $final['rule ' . $id] = $row;
            }
        }
        foreach ($rules as $entry) {
            $label = $entry['id'] > 0 ? 'rule ' . $entry['id'] : $entry['path'];
            $row = $final[$label] ?? ['id_shop' => $idShop, 'id_product_attribute' => 0];
            $combination = $entry['rule']['combination'];
            if ($combination !== null) {
                $target = (int) ($plan['combinations']['items'][$combination]['id'] ?? 0);
                $row['id_product_attribute'] = $target > 0 ? $target : 'new ' . $combination;
            }
            $final[$label] = array_merge($row, array_diff_key($entry['rule'], ['combination' => true]));
        }

        $seen = [];
        foreach ($final as $label => $row) {
            $key = implode('|', [
                (int) $row['id_shop'], (string) $row['id_product_attribute'], (int) $row['id_currency'], (int) $row['id_country'],
                (int) $row['id_group'], (int) $row['id_customer'], (int) $row['from_quantity'], (string) $row['from'], (string) $row['to'],
            ]);
            if (isset($seen[$key])) {
                $errors[] = 'specific_prices: ' . $label . ' and ' . $seen[$key] . ' would apply to the same customers, '
                    . 'combination, quantity and dates; change one of them, or remove the other';
                continue;
            }
            $seen[$key] = $label;
        }
    }

    /**
     * Suppliers to dissociate, rows to change or add, and the default supplier.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $group
     * @param Product $product
     * @param int $idShop
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function planSuppliers(array $arguments, array $group, Product $product, int $idShop, array &$plan, array &$errors): void
    {
        $plan['suppliers'] = ['remove' => [], 'rows' => []];
        if ($group === [] && !array_key_exists('remove_suppliers', $arguments)) {
            // Nothing about suppliers was said: the default supplier is left
            // alone, even where the shop's own data disagrees with itself.
            return;
        }
        $idProduct = (int) $product->id;
        $current = [];
        foreach (Db::getInstance()->executeS(
            'SELECT `id_product_supplier`, `id_product_attribute`, `id_supplier`, `product_supplier_reference`,'
            . ' `product_supplier_price_te`, `id_currency` FROM `' . _DB_PREFIX_ . 'product_supplier`'
            . ' WHERE `id_product` = ' . $idProduct
        ) ?: [] as $row) {
            $current[(int) $row['id_supplier'] . '-' . (int) $row['id_product_attribute']] = $row;
        }
        $associated = array_values(array_unique(array_map(static function (array $row): int {
            return (int) $row['id_supplier'];
        }, $current)));

        $remove = self::removals($arguments, 'remove_suppliers', $associated, 'supplier of this product', $errors);
        foreach ($remove as $id) {
            self::record($plan, 'suppliers[id_supplier=' . $id . ']', 'associated', 'removed');
        }

        $listed = count($plan['combinations']['items']);
        $defaultCurrency = (int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop);
        $rows = [];
        $seen = [];
        $known = [];
        $productLevel = [];
        foreach (self::items($group['suppliers'] ?? null, 'suppliers.suppliers', $errors) as $i => $item) {
            $path = 'suppliers.suppliers[' . $i . ']';
            self::onlyAccepted($item, 'supplier', $path, $errors);
            $idSupplier = self::integer($item['id_supplier'] ?? null);
            if ($idSupplier === null || !self::supplierExists($idSupplier, $known)) {
                $errors[] = $path . '.id_supplier: no such supplier. Take identifiers from somcp_list_suppliers.';
                continue;
            }
            if (in_array($idSupplier, $remove, true)) {
                $errors[] = $path . '.id_supplier: supplier ' . $idSupplier . ' is also in remove_suppliers';
                continue;
            }
            $position = self::position($item, 'combination', $listed, $path, $errors);
            $key = $idSupplier . '-' . ($position ?? 'product');
            if (isset($seen[$key])) {
                $errors[] = $path . ': this supplier is given twice for the same ' . ($position === null ? 'product' : 'combination');
                continue;
            }
            $seen[$key] = true;
            $idCombination = $position === null ? 0 : (int) ($plan['combinations']['items'][$position]['id'] ?? 0);
            if ($position === null) {
                $productLevel[$idSupplier] = true;
            }

            $existing = ($position === null || $idCombination > 0) ? ($current[$idSupplier . '-' . $idCombination] ?? null) : null;
            $base = [
                'id_supplier' => $idSupplier,
                'combination' => $position,
                'product_supplier_reference' => $existing === null ? '' : (string) $existing['product_supplier_reference'],
                'product_supplier_price_te' => $existing === null ? 0.0 : (float) $existing['product_supplier_price_te'],
                'id_currency' => $existing === null ? $defaultCurrency : (int) $existing['id_currency'],
            ];
            $row = self::supplierValues($item, $path, $base, $idShop, $errors);
            if ($existing === null) {
                $rows[] = ['id' => 0] + $row;
                self::record($plan, $path, null, 'supplier ' . $idSupplier . ' associated');
                continue;
            }
            $changed = false;
            foreach (['product_supplier_reference', 'product_supplier_price_te', 'id_currency'] as $field) {
                if (!self::same($base[$field], $row[$field])) {
                    self::record($plan, $path . '.' . $field, $base[$field], $row[$field]);
                    $changed = true;
                }
            }
            if ($changed) {
                $rows[] = ['id' => (int) $existing['id_product_supplier']] + $row;
            }
        }

        // As at creation, a supplier is associated with the product itself as
        // well as with its combinations: that row is what the back office lists.
        $after = array_values(array_diff($associated, $remove));
        foreach ($rows as $row) {
            $idSupplier = (int) $row['id_supplier'];
            if (!isset($productLevel[$idSupplier]) && !isset($current[$idSupplier . '-0'])) {
                $productLevel[$idSupplier] = true;
                $rows[] = [
                    'id' => 0,
                    'id_supplier' => $idSupplier,
                    'combination' => null,
                    'product_supplier_reference' => '',
                    'product_supplier_price_te' => 0.0,
                    'id_currency' => $defaultCurrency,
                ];
            }
            $after[] = $idSupplier;
        }
        $after = array_values(array_unique($after));

        $default = (int) $product->id_supplier;
        if (array_key_exists('id_supplier_default', $group)) {
            $default = self::integer($group['id_supplier_default']) ?? 0;
            if ($default <= 0 || !in_array($default, $after, true)) {
                $errors[] = 'suppliers.id_supplier_default: not a supplier of this product; associate it in suppliers.suppliers first';

                return;
            }
        } elseif (!in_array($default, $after, true)) {
            // The default supplier was removed, or there was none: the first
            // one left takes its place, none when nothing is left.
            $default = $after[0] ?? 0;
        }
        if ($default !== (int) $product->id_supplier) {
            $plan['fields']['id_supplier'] = $default;
        }

        $plan['suppliers'] = ['remove' => $remove, 'rows' => $rows];
    }

    /**
     * Images to remove, change and add.
     *
     * @param array<string, mixed> $arguments
     * @param Product $product
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idShop
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function planImages(array $arguments, Product $product, array $languages, int $idShop, array &$plan, array &$errors): void
    {
        $idProduct = (int) $product->id;
        $current = [];
        foreach (Db::getInstance()->executeS(
            'SELECT i.`id_image`, i.`position`, ish.`cover` FROM `' . _DB_PREFIX_ . 'image` i'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'image_shop` ish ON ish.`id_image` = i.`id_image` AND ish.`id_shop` = ' . $idShop
            . ' WHERE i.`id_product` = ' . $idProduct . ' ORDER BY i.`position` ASC'
        ) ?: [] as $row) {
            $current[(int) $row['id_image']] = ['position' => (int) $row['position'], 'cover' => (bool) $row['cover']];
        }

        $remove = self::removals($arguments, 'remove_images', array_keys($current), 'image of this product', $errors);
        foreach ($remove as $id) {
            self::record($plan, 'images[id_image=' . $id . ']', 'exists', 'removed');
        }

        $listed = count($plan['combinations']['items']);
        $edits = [];
        $added = [];
        $named = [];
        $covers = 0;
        foreach (self::items($arguments['images'] ?? null, 'images', $errors) as $i => $item) {
            $path = 'images[' . $i . ']';
            $id = array_key_exists('id_image', $item) ? (self::integer($item['id_image']) ?? 0) : null;
            unset($item['id_image']);
            self::onlyAccepted($item, 'image', $path, $errors);
            $fields = self::imageFields($item, $path, $listed, $languages, $errors);
            if ($fields['cover'] === true) {
                ++$covers;
            }

            if ($id === null) {
                if (!array_key_exists('url', $item) && !array_key_exists(SomcpUpload::ARG_REF, $item)) {
                    $errors[] = $path . ': id_image to change an image of the product, or url or upload_ref to add one';
                    continue;
                }
                $source = self::imageSource($item, $path, $errors);
                if ($source === null) {
                    continue;
                }
                $added[] = ['index' => $i] + $source + $fields;
                self::record($plan, $path, null, 'added from ' . ($source['url'] !== '' ? $source['url'] : 'upload_ref ' . $source['upload_ref']));
                continue;
            }

            if (array_key_exists('url', $item) || array_key_exists(SomcpUpload::ARG_REF, $item)) {
                $errors[] = $path . ': id_image, or url or upload_ref, not both - an image cannot be replaced by another';
                continue;
            }
            if (!isset($current[$id])) {
                $errors[] = $path . '.id_image: not an image of this product in this shop';
                continue;
            }
            if (in_array($id, $remove, true) || isset($named[$id])) {
                $errors[] = $path . '.id_image: image ' . $id . ' is named twice in this call';
                continue;
            }
            $named[$id] = true;
            $where = $path . ' (id_image ' . $id . ')';
            $edit = ['index' => $i, 'id' => $id, 'legend' => null, 'cover' => false, 'position' => null, 'combinations' => null];

            if ($fields['legend'] !== null) {
                $image = new Image($id);
                $old = (array) $image->legend;
                $legend = $old;
                foreach ($fields['legend'] as $idLang => $text) {
                    if ((string) ($old[$idLang] ?? '') !== $text) {
                        $legend[$idLang] = $text;
                        self::record($plan, $where . '.legend (' . $languages['filled'][$idLang] . ')', (string) ($old[$idLang] ?? ''), $text);
                    }
                }
                if ($legend !== $old) {
                    $edit['legend'] = $legend;
                }
            }
            if ($fields['cover'] === true && !$current[$id]['cover']) {
                $edit['cover'] = true;
                self::record($plan, $where . '.cover', false, true);
            } elseif ($fields['cover'] === false && $current[$id]['cover']) {
                $errors[] = $path . '.cover: to change the cover, set cover on the new one';
            }
            if ($fields['position'] !== null && $fields['position'] !== $current[$id]['position']) {
                $edit['position'] = $fields['position'];
                self::record($plan, $where . '.position', $current[$id]['position'], $fields['position']);
            }
            if ($fields['combinations'] !== null) {
                $old = array_map('intval', array_column(Db::getInstance()->executeS(
                    'SELECT `id_product_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute_image` WHERE `id_image` = ' . $id
                ) ?: [], 'id_product_attribute'));
                $new = [];
                $shown = [];
                $pending = false;
                foreach ($fields['combinations'] as $position) {
                    $target = (int) ($plan['combinations']['items'][$position]['id'] ?? 0);
                    $new[] = $target;
                    // A combination added by this call has no identifier yet.
                    $shown[] = $target > 0 ? $target : 'combinations[' . $position . ']';
                    $pending = $pending || $target === 0;
                }
                if ($pending || !self::sameSet($old, $new)) {
                    $edit['combinations'] = $fields['combinations'];
                    self::record($plan, $where . '.combinations', self::sorted($old), $shown);
                }
            }
            if ($edit['legend'] !== null || $edit['cover'] || $edit['position'] !== null || $edit['combinations'] !== null) {
                $edits[] = $edit;
            }
        }
        if ($covers > 1) {
            $errors[] = 'images: only one can be the cover';
        }

        $plan['images'] = ['remove' => $remove, 'edits' => $edits, 'added' => $added];
    }

    /**
     * Identifiers named in a remove_ list, checked against what the product has.
     *
     * @param array<string, mixed> $arguments
     * @param string $list
     * @param array<int, int> $owned
     * @param string $what
     * @param array<int, string> $errors
     *
     * @return array<int, int>
     */
    private static function removals(array $arguments, string $list, array $owned, string $what, array &$errors): array
    {
        if (!array_key_exists($list, $arguments)) {
            return [];
        }
        $ids = array_values(array_unique(self::ids($arguments[$list], self::REMOVALS[$list], $list, $errors)));
        foreach ($ids as $id) {
            if (!in_array($id, $owned, true)) {
                $errors[] = $list . ': ' . $id . ' is not a ' . $what . ' in this shop';
            }
        }

        return $ids;
    }

    /*
     * Writing.
     */

    /**
     * Runs the writes and says how far they went.
     *
     * @param Product $product
     * @param array<string, mixed> $plan
     * @param int $idShop
     *
     * @return array{ids: array<int, int>, written: array<int, string>, error: string|null}
     */
    private static function writeSteps(Product $product, array $plan, int $idShop): array
    {
        $written = [];
        try {
            return ['ids' => self::writeUpdate($product, $plan, $idShop, $written), 'written' => $written, 'error' => null];
        } catch (Throwable $e) {
            // What writeUpdate() had recorded before it threw is still in
            // $written: it is filled step by step, by reference.
            return ['ids' => [], 'written' => $written, 'error' => $e->getMessage()];
        }
    }

    /**
     * Writes everything but the images, or throws with what failed.
     *
     * @param Product $product
     * @param array<string, mixed> $plan
     * @param int $idShop
     * @param array<int, string> $written the steps done so far, for the answer when a later one fails
     *
     * @param-out array<int, string> $written
     *
     * @return array<int, int> combination identifiers, by position in the call
     */
    private static function writeUpdate(Product $product, array $plan, int $idShop, array &$written): array
    {
        $idProduct = (int) $product->id;
        $languages = $plan['languages'];

        if ($plan['product'] !== []) {
            self::fill($product, $plan['product']);
            // The shop of the token only: product_shop and product_lang of
            // the other shops keep their own values.
            $product->id_shop_list = [$idShop];
            if (!$product->update()) {
                throw new RuntimeException('the shop refused the product itself');
            }
            $written[] = 'the product fields';
        }

        if ($plan['categories'] !== null) {
            if (!$product->updateCategories($plan['categories'])) {
                throw new RuntimeException('the categories could not be saved');
            }
            $written[] = 'the categories';
        }
        if ($plan['related_products'] !== null) {
            $product->deleteAccessories();
            if ($plan['related_products'] !== []) {
                $product->changeAccessories($plan['related_products']);
            }
            $written[] = 'the related products';
        }
        if ($plan['attachments'] !== null) {
            if (!Attachment::attachToProduct($idProduct, $plan['attachments'])) {
                throw new RuntimeException('the documents could not be attached');
            }
            $written[] = 'the documents';
        }
        if ($plan['carriers'] !== null) {
            $product->setCarriers($plan['carriers']);
            $written[] = 'the carriers';
        }
        if ($plan['features'] !== null) {
            // deleteFeatures() also removes the custom values of the product,
            // which writeFeatures() makes again from the plan.
            $product->deleteFeatures();
            self::writeFeatures($idProduct, $plan['features'], $languages);
            $written[] = 'the features';
        }
        if ($plan['customization'] !== null) {
            self::removeCustomization($product);
            self::writeCustomization($idProduct, $plan['customization'], $languages, $idShop);
            $written[] = 'the customization fields';
        }
        if ($plan['tags'] !== []) {
            foreach ($plan['tags'] as $idLang => $tags) {
                // Replace, as the back office does on save: the language's old
                // tags are removed and the given ones take their place. Only the
                // tags given were validated, so none is empty here.
                Tag::deleteProductTagsInLang($idProduct, $idLang);
                if (!Tag::addTags($idLang, $idProduct, $tags, ',')) {
                    throw new RuntimeException('the tags could not be saved');
                }
            }
            $written[] = 'the tags';
        }

        if ($plan['stock']['out_of_stock'] !== null || $plan['stock']['location'] !== null) {
            if ($plan['stock']['out_of_stock'] !== null) {
                StockAvailable::setProductOutOfStock($idProduct, $plan['stock']['out_of_stock'], $idShop);
            }
            if ($plan['stock']['location'] !== null) {
                StockAvailable::setLocation($idProduct, $plan['stock']['location'], $idShop);
            }
            $written[] = 'the stock settings';
        }

        $combinationIds = self::writeCombinationChanges($product, $plan, $idShop);
        if ($plan['combinations']['remove'] !== [] || $plan['combinations']['items'] !== []) {
            $written[] = 'the combinations';
        }
        self::writeSupplierChanges($idProduct, $plan['suppliers'], $combinationIds);
        if ($plan['suppliers']['remove'] !== [] || $plan['suppliers']['rows'] !== []) {
            $written[] = 'the suppliers';
        }
        self::writeSpecificPriceChanges($idProduct, $plan['specific_prices'], $combinationIds, $idShop);
        if ($plan['specific_prices']['remove'] !== [] || $plan['specific_prices']['rules'] !== [] || $plan['specific_prices']['priorities'] !== null) {
            $written[] = 'the specific prices';
        }

        // Quantities last, as at creation: each one records a stock movement,
        // which nothing undoes.
        if ($plan['stock']['quantity'] !== null) {
            StockAvailable::setQuantity($idProduct, 0, $plan['stock']['quantity'], $idShop);
        }
        foreach ($plan['combinations']['items'] as $i => $combination) {
            if ($combination['quantity'] !== null) {
                StockAvailable::setQuantity($idProduct, $combinationIds[$i], $combination['quantity'], $idShop);
            }
        }

        return $combinationIds;
    }

    /**
     * The contents of a pack after the call: the list given, or the current one less the items removed.
     *
     * Compared with the pack table first, so the same list again writes nothing.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $group
     * @param Product $product
     * @param bool $isPack whether the product is a pack once the call is written
     * @param int $idShop
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function planPack(array $arguments, array $group, Product $product, bool $isPack, int $idShop, array &$plan, array &$errors): void
    {
        $plan['pack'] = null;
        $removing = array_key_exists('remove_pack_items', $arguments);
        if ($group === [] && !$removing) {
            return;
        }
        if ($group !== [] && $removing) {
            $errors[] = 'remove_pack_items: not next to pack.items, which already gives the whole list';

            return;
        }

        $current = self::currentPack((int) $product->id);
        if ($group !== []) {
            $items = self::packPlan($group, $isPack, (int) $product->id, $idShop, $errors);
            if ($items === null) {
                return;
            }
            $wanted = [];
            foreach ($items as $item) {
                $wanted[$item['id_product'] . '-' . $item['id_product_attribute']] = $item;
            }
        } else {
            if (!$isPack) {
                $errors[] = 'remove_pack_items: a pack only';

                return;
            }
            $wanted = $current;
            foreach (self::items($arguments['remove_pack_items'], 'remove_pack_items', $errors) as $i => $item) {
                $id = self::integer($item['id_product'] ?? null);
                $idAttribute = array_key_exists('id_product_attribute', $item) ? self::integer($item['id_product_attribute']) : 0;
                $key = (int) $id . '-' . (int) $idAttribute;
                if ($id === null || $idAttribute === null || !isset($current[$key])) {
                    $errors[] = 'remove_pack_items[' . $i . ']: not a product of this pack. Take it from pack.items of somcp_get_product.';
                    continue;
                }
                unset($wanted[$key]);
            }
        }

        ksort($wanted);
        if ($wanted === $current) {
            return;
        }
        $plan['pack'] = array_values($wanted);
        self::record($plan, 'pack.items', array_values($current), array_values($wanted));
    }

    /**
     * @param int $idProduct
     *
     * @return array<string, array{id_product: int, id_product_attribute: int, quantity: int}> keyed by product and combination, sorted
     */
    private static function currentPack(int $idProduct): array
    {
        $current = [];
        foreach (Db::getInstance()->executeS(
            'SELECT `id_product_item`, `id_product_attribute_item`, `quantity` FROM `' . _DB_PREFIX_ . 'pack`'
            . ' WHERE `id_product_pack` = ' . (int) $idProduct
        ) ?: [] as $row) {
            $current[(int) $row['id_product_item'] . '-' . (int) $row['id_product_attribute_item']] = [
                'id_product' => (int) $row['id_product_item'],
                'id_product_attribute' => (int) $row['id_product_attribute_item'],
                'quantity' => (int) $row['quantity'],
            ];
        }
        ksort($current);

        return $current;
    }

    /**
     * The file of a virtual product to add, replace, change or remove.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $group
     * @param Product $product
     * @param bool $isVirtual whether the product is virtual once the call is written
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function planVirtualFile(array $arguments, array $group, Product $product, bool $isVirtual, array &$plan, array &$errors): void
    {
        $plan['virtual_file'] = null;
        $removing = array_key_exists('remove_virtual_file', $arguments);
        if ($group === [] && !$removing) {
            return;
        }
        $idDownload = (int) ProductDownload::getIdFromIdProduct((int) $product->id, false);

        if ($removing) {
            $remove = self::bool($arguments['remove_virtual_file']);
            if ($remove === null) {
                $errors[] = 'remove_virtual_file: true or false';
            } elseif ($group !== []) {
                $errors[] = 'remove_virtual_file: not next to virtual_file';
            } elseif ($remove && $idDownload <= 0) {
                $errors[] = 'remove_virtual_file: the product has no file';
            } elseif ($remove) {
                $download = new ProductDownload($idDownload);
                $plan['virtual_file'] = ['remove' => $idDownload];
                self::record($plan, 'virtual_file', ['display_filename' => (string) $download->display_filename], null);
            }

            return;
        }

        $file = self::virtualFilePlan($group, $isVirtual, $errors, $idDownload <= 0);
        if ($file === null) {
            return;
        }
        $existing = $idDownload > 0 ? new ProductDownload($idDownload) : null;
        foreach ($file['fields'] as $field => $value) {
            $old = $existing === null ? null : ($existing->{$field} ?? null);
            if ($existing !== null && self::same($old, $value)) {
                unset($file['fields'][$field]);
                continue;
            }
            self::record($plan, 'virtual_file.' . $field, $old, $value);
        }
        // A file given always counts: an address or a reference cannot be
        // compared with the stored file.
        if ($file['source'] !== null) {
            self::record($plan, 'virtual_file.file', null, self::imageOrigin($file['source']));
        } elseif ($file['fields'] === []) {
            return;
        }
        $file['id'] = $idDownload;
        $plan['virtual_file'] = $file;
    }

    /**
     * Replaces the contents of a pack, and reports each product rather than failing.
     *
     * @param int $idProduct
     * @param array<int, array{id_product: int, id_product_attribute: int, quantity: int}> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private static function replacePack(int $idProduct, array $items): array
    {
        // What the back office does on saving a pack: the rows go, the list
        // given is written again.
        Pack::deleteItems($idProduct);

        return self::writePack($idProduct, $items);
    }

    /**
     * Adds, replaces, changes or removes the file of a virtual product, and reports the outcome rather than failing.
     *
     * @param int $idProduct
     * @param array<string, mixed> $file
     *
     * @return array<string, mixed>
     */
    private static function applyVirtualFile(int $idProduct, array $file): array
    {
        if (isset($file['remove'])) {
            try {
                // delete(true) takes the file on disk with the row.
                $removed = (new ProductDownload((int) $file['remove']))->delete(true);
            } catch (Throwable $e) {
                $removed = false;
            }

            return $removed ? ['removed' => true] : ['refused' => 'the shop could not delete it'];
        }
        if ((int) $file['id'] <= 0) {
            return self::writeVirtualFile($idProduct, $file);
        }

        $origin = $file['source'] === null ? [] : self::imageOrigin($file['source']);
        $download = new ProductDownload((int) $file['id']);
        $previous = basename((string) $download->filename);
        $filename = '';
        try {
            if ($file['source'] !== null) {
                $refusal = self::receiveFile($file['source'], $filename);
                if ($refusal !== '') {
                    self::dropDownload($filename);

                    return $origin + ['refused' => $refusal];
                }
                $download->filename = $filename;
            }
            self::fill($download, $file['fields']);
            $saved = $download->update();
        } catch (Throwable $e) {
            $saved = false;
        }
        if (!$saved) {
            self::dropDownload($filename);

            return $origin + ['refused' => 'the shop could not save it'];
        }
        // The old file goes once the new one is in place, as in the back office.
        if ($filename !== '' && $previous !== '' && $previous !== $filename) {
            self::dropDownload($previous);
        }

        return $origin + ['changed' => true];
    }

    /**
     * Deletes the merchant's customization fields, the way the back office does.
     *
     * A field a customer already filled in for an order is only flagged
     * deleted: the order still points at it, and deleting it would leave that
     * order with a customization nobody can read.
     *
     * @param Product $product
     *
     * @return void
     */
    private static function removeCustomization(Product $product): void
    {
        // Rows, not identifiers, despite the name: each one holds the field in `index`.
        $used = array_map('intval', array_column($product->getUsedCustomizationFieldsIds() ?: [], 'index'));
        $rows = Db::getInstance()->executeS(
            'SELECT `id_customization_field` FROM `' . _DB_PREFIX_ . 'customization_field`'
            . ' WHERE `id_product` = ' . (int) $product->id . ' AND `is_deleted` = 0 AND `is_module` = 0'
        ) ?: [];
        foreach ($rows as $row) {
            $field = new CustomizationField((int) $row['id_customization_field']);
            if (in_array((int) $field->id, $used, true)) {
                $field->is_deleted = true;
                $done = $field->update();
            } else {
                $done = $field->delete();
            }
            if (!$done) {
                throw new RuntimeException('customization field ' . (int) $field->id . ' could not be removed');
            }
        }
    }

    /**
     * @param Product $product
     * @param array<string, mixed> $plan
     * @param int $idShop
     *
     * @return array<int, int> combination identifiers, by position in the call
     */
    private static function writeCombinationChanges(Product $product, array $plan, int $idShop): array
    {
        $idProduct = (int) $product->id;
        $languages = $plan['languages'];

        foreach ($plan['combinations']['remove'] as $id) {
            // Through the product, as the back office does: the hook other
            // modules listen to fires, then the combination goes with its
            // stock, prices, suppliers and images.
            if (!$product->deleteAttributeCombination($id)) {
                throw new RuntimeException('combination ' . $id . ' could not be removed');
            }
        }

        $ids = [];
        $new = [];
        foreach ($plan['combinations']['items'] as $i => $item) {
            if ($item['id'] === 0) {
                $new[$i] = $item;
                continue;
            }
            $ids[$i] = (int) $item['id'];
            if ($item['fields'] === [] && $item['texts'] === [] && $item['location'] === null) {
                continue;
            }
            $combination = new Combination((int) $item['id'], null, $idShop);
            self::fill($combination, $item['fields'] + $item['texts']);
            $combination->id_shop_list = [$idShop];
            if (($item['fields'] !== [] || $item['texts'] !== []) && !$combination->update()) {
                throw new RuntimeException('combination ' . (int) $item['id'] . ' could not be saved');
            }
            if ($item['location'] !== null) {
                StockAvailable::setLocation($idProduct, $item['location'], $idShop, (int) $item['id']);
            }
        }

        // New ones through the creation's own writing, which also makes one
        // of them the default when the plan says so.
        if ($new !== []) {
            $created = self::writeCombinations($product, array_values($new), $languages, $idShop);
            foreach (array_keys($new) as $n => $i) {
                $ids[$i] = $created[$n];
            }
        }

        $default = $plan['combinations']['default'];
        if ($default !== null) {
            $product->deleteDefaultAttributes();
            $product->setDefaultAttribute($ids[$default]);
        } elseif ($plan['combinations']['remove'] !== []) {
            // The default one may have been removed. The core then points the
            // product at another combination without flagging it, so it is
            // flagged here, as the back office does.
            $flagged = (int) Db::getInstance()->getValue(
                'SELECT pas.`id_product_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute_shop` pas'
                . ' WHERE pas.`id_product` = ' . $idProduct . ' AND pas.`id_shop` = ' . $idShop . ' AND pas.`default_on` = 1'
            );
            $first = (int) Db::getInstance()->getValue(
                'SELECT pas.`id_product_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute_shop` pas'
                . ' WHERE pas.`id_product` = ' . $idProduct . ' AND pas.`id_shop` = ' . $idShop
                . ' ORDER BY pas.`id_product_attribute` ASC'
            );
            if ($flagged === 0 && $first > 0) {
                $product->deleteDefaultAttributes();
                $product->setDefaultAttribute($first);
            }
        }

        return $ids;
    }

    /**
     * @param int $idProduct
     * @param array<string, mixed> $suppliers
     * @param array<int, int> $combinationIds
     *
     * @return void
     */
    private static function writeSupplierChanges(int $idProduct, array $suppliers, array $combinationIds): void
    {
        foreach ($suppliers['remove'] as $idSupplier) {
            foreach (Db::getInstance()->executeS(
                'SELECT `id_product_supplier` FROM `' . _DB_PREFIX_ . 'product_supplier`'
                . ' WHERE `id_product` = ' . $idProduct . ' AND `id_supplier` = ' . (int) $idSupplier
            ) ?: [] as $row) {
                if (!(new ProductSupplier((int) $row['id_product_supplier']))->delete()) {
                    throw new RuntimeException('supplier ' . (int) $idSupplier . ' could not be dissociated');
                }
            }
        }

        foreach ($suppliers['rows'] as $row) {
            $supplier = new ProductSupplier($row['id'] > 0 ? (int) $row['id'] : null);
            self::fill($supplier, [
                'id_product' => $idProduct,
                'id_product_attribute' => $row['combination'] === null ? 0 : $combinationIds[$row['combination']],
                'id_supplier' => $row['id_supplier'],
                'product_supplier_reference' => $row['product_supplier_reference'],
                'product_supplier_price_te' => $row['product_supplier_price_te'],
                'id_currency' => $row['id_currency'],
            ]);
            if (!($row['id'] > 0 ? $supplier->update() : $supplier->add())) {
                throw new RuntimeException('supplier ' . (int) $row['id_supplier'] . ' could not be saved');
            }
        }
    }

    /**
     * @param int $idProduct
     * @param array<string, mixed> $prices
     * @param array<int, int> $combinationIds
     * @param int $idShop
     *
     * @return void
     */
    private static function writeSpecificPriceChanges(int $idProduct, array $prices, array $combinationIds, int $idShop): void
    {
        foreach ($prices['remove'] as $id) {
            if (!(new SpecificPrice($id))->delete()) {
                throw new RuntimeException('specific price ' . $id . ' could not be removed');
            }
        }

        foreach ($prices['rules'] as $i => $entry) {
            $rule = $entry['rule'];
            $combination = $rule['combination'];
            unset($rule['combination']);
            if ($entry['id'] > 0) {
                $price = new SpecificPrice((int) $entry['id']);
                if ($combination !== null) {
                    $rule['id_product_attribute'] = $combinationIds[$combination];
                }
                self::fill($price, $rule);
                if (!$price->update()) {
                    throw new RuntimeException('specific price ' . (int) $entry['id'] . ' could not be saved');
                }
                continue;
            }
            $price = new SpecificPrice();
            self::fill($price, [
                'id_product' => $idProduct,
                'id_product_attribute' => $combination === null ? 0 : $combinationIds[$combination],
                // Bound to the shop of the token, as at creation.
                'id_shop' => $idShop,
                'id_shop_group' => 0,
                'id_cart' => 0,
                'id_specific_price_rule' => 0,
            ] + $rule);
            if (!$price->add()) {
                throw new RuntimeException('specific price ' . $i . ' could not be saved');
            }
        }

        if ($prices['priorities'] !== null && !SpecificPrice::setSpecificPriority($idProduct, $prices['priorities'])) {
            throw new RuntimeException('the specific price priorities could not be saved');
        }
    }

    /**
     * Removes, changes and adds the images, and reports each one rather than failing.
     *
     * @param Product $product
     * @param array<string, mixed> $plan
     * @param array<int, int> $combinationIds
     * @param int $idShop
     *
     * @return array<int, array<string, mixed>>
     */
    private static function applyImages(Product $product, array $plan, array $combinationIds, int $idShop): array
    {
        $images = $plan['images'];
        $idProduct = (int) $product->id;
        $report = [];

        foreach ($images['remove'] as $id) {
            try {
                $image = new Image($id);
                $image->id_shop_list = [$idShop];
                $done = $image->delete();
            } catch (Throwable $e) {
                $done = false;
            }
            $report[] = $done ? ['id_image' => $id, 'removed' => true] : ['id_image' => $id, 'refused' => 'the shop could not delete it'];
        }

        $order = array_map('intval', array_column(Db::getInstance()->executeS(
            'SELECT i.`id_image` FROM `' . _DB_PREFIX_ . 'image` i'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'image_shop` ish ON ish.`id_image` = i.`id_image` AND ish.`id_shop` = ' . $idShop
            . ' WHERE i.`id_product` = ' . $idProduct . ' ORDER BY i.`position` ASC'
        ) ?: [], 'id_image'));

        // What each item of the call became: the image it names or the one it
        // added, so positions, cover and combinations can follow.
        $byItem = [];
        foreach ($images['edits'] as $edit) {
            $byItem[$edit['index']] = $edit + ['url' => null];
            if ($edit['legend'] !== null) {
                $image = new Image((int) $edit['id']);
                $image->legend = $edit['legend'];
                $image->id_shop_list = [$idShop];
                $done = false;
                try {
                    $done = $image->update();
                } catch (Throwable $e) {
                }
                $report[] = $done ? ['id_image' => (int) $edit['id'], 'changed' => true] : ['id_image' => (int) $edit['id'], 'refused' => 'the shop could not save its legend'];
            } else {
                $report[] = ['id_image' => (int) $edit['id'], 'changed' => true];
            }
        }

        $position = count($order) > 0 ? (int) Image::getHighestPosition($idProduct) : 0;
        foreach ($images['added'] as $added) {
            try {
                $id = self::addImage($product, $added, ++$position, $plan['languages'], $idShop, $refusal);
            } catch (Throwable $e) {
                $id = 0;
                $refusal = 'the shop could not save it';
            }
            if ($id <= 0) {
                --$position;
                $report[] = self::imageOrigin($added) + ['refused' => $refusal];
                continue;
            }
            $report[] = self::imageOrigin($added) + ['id_image' => $id];
            $order[] = $id;
            $byItem[$added['index']] = ['id' => $id] + $added;
        }

        // Positions asked for, applied lowest first so each lands where it
        // was asked, then everything numbered again from 1.
        $moves = [];
        foreach ($byItem as $entry) {
            if (($entry['position'] ?? null) !== null) {
                $moves[(int) $entry['id']] = (int) $entry['position'];
            }
        }
        asort($moves);
        foreach ($moves as $id => $wanted) {
            $order = array_values(array_diff($order, [$id]));
            array_splice($order, min($wanted - 1, count($order)), 0, [$id]);
        }
        $positions = [];
        foreach (Db::getInstance()->executeS(
            'SELECT `id_image`, `position` FROM `' . _DB_PREFIX_ . 'image` WHERE `id_product` = ' . $idProduct
        ) ?: [] as $row) {
            $positions[(int) $row['id_image']] = (int) $row['position'];
        }
        foreach ($order as $n => $id) {
            if (($positions[$id] ?? 0) !== $n + 1) {
                $image = new Image($id);
                $image->position = $n + 1;
                $image->id_shop_list = [$idShop];
                $image->update();
            }
        }

        // The cover: the one asked for if it is there, otherwise the current
        // one, otherwise the first image - a product with images always has one.
        $wanted = null;
        foreach ($byItem as $entry) {
            if (!empty($entry['cover'])) {
                $wanted = (int) $entry['id'];
            }
        }
        $cover = (int) Db::getInstance()->getValue(
            'SELECT ish.`id_image` FROM `' . _DB_PREFIX_ . 'image_shop` ish'
            . ' WHERE ish.`id_product` = ' . $idProduct . ' AND ish.`id_shop` = ' . $idShop . ' AND ish.`cover` = 1'
        );
        $target = $wanted ?? ($cover > 0 ? $cover : ($order[0] ?? 0));
        if ($target > 0 && $target !== $cover) {
            if ($cover > 0) {
                $old = new Image($cover);
                $old->cover = false;
                $old->id_shop_list = [$idShop];
                $old->update();
            }
            $new = new Image($target);
            $new->cover = true;
            $new->id_shop_list = [$idShop];
            $new->update();
        }

        // Combinations each image illustrates, for the items that said so.
        $links = [];
        foreach ($byItem as $entry) {
            if (($entry['combinations'] ?? null) === null) {
                continue;
            }
            $links[(int) $entry['id']] = array_map(static function (int $index) use ($combinationIds): int {
                return $combinationIds[$index];
            }, $entry['combinations']);
        }
        if ($links !== []) {
            $current = [];
            foreach (Db::getInstance()->executeS(
                'SELECT pai.`id_product_attribute`, pai.`id_image` FROM `' . _DB_PREFIX_ . 'product_attribute_image` pai'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'product_attribute` pa ON pa.`id_product_attribute` = pai.`id_product_attribute`'
                . ' WHERE pa.`id_product` = ' . $idProduct . ' AND pai.`id_image` > 0'
            ) ?: [] as $row) {
                $current[(int) $row['id_product_attribute']][] = (int) $row['id_image'];
            }
            $touched = [];
            foreach ($links as $idImage => $combinations) {
                foreach ($current as $idCombination => $ids) {
                    if (in_array($idImage, $ids, true) && !in_array($idCombination, $combinations, true)) {
                        $current[$idCombination] = array_values(array_diff($ids, [$idImage]));
                        $touched[$idCombination] = true;
                    }
                }
                foreach ($combinations as $idCombination) {
                    if (!in_array($idImage, $current[$idCombination] ?? [], true)) {
                        $current[$idCombination][] = $idImage;
                        $touched[$idCombination] = true;
                    }
                }
            }
            foreach (array_keys($touched) as $idCombination) {
                (new Combination($idCombination))->setImages($current[$idCombination] ?? []);
            }
        }

        return $report;
    }

    /**
     * What the merchant should know about the SEO of each language whose texts changed.
     *
     * @param Product $product as stored after the change
     * @param array<string, mixed> $plan
     * @param int $idShop
     *
     * @return array<string, array<int, string>>
     */
    private static function updateWarnings(Product $product, array $plan, int $idShop): array
    {
        $touched = array_intersect_key($plan['languages']['filled'], $plan['touched'] ?? []);
        if ($touched === []) {
            return [];
        }

        // The creation's warnings, on the texts as they are stored now rather
        // than on the ones this call gave.
        $texts = [];
        foreach (['meta_title', 'meta_description', 'link_rewrite'] as $field) {
            $texts[$field] = is_array($product->{$field}) ? $product->{$field} : [];
        }
        $warnings = self::seoWarnings($product, ['languages' => ['filled' => $touched], 'texts' => $texts], $idShop);

        if ($product->active) {
            foreach (array_keys($plan['product']['link_rewrite'] ?? []) as $idLang) {
                if (isset($touched[$idLang]) && isset($plan['texts']['link_rewrite'][$idLang])) {
                    $warnings[$touched[$idLang]][] = 'link_rewrite changed on an active product: the old address of its page no longer works';
                }
            }
        }

        return $warnings;
    }

    /*
     * Comparing.
     */

    /**
     * Whether the value the shop holds is the one given, whatever type the database returned it as.
     *
     * @param mixed $old
     * @param mixed $new
     *
     * @return bool
     */
    private static function same($old, $new): bool
    {
        if (is_bool($new)) {
            return (bool) $old === $new;
        }
        if (is_int($new)) {
            return is_numeric($old) ? (int) $old === $new : ($new === 0 && ($old === null || $old === ''));
        }
        if (is_float($new)) {
            return is_numeric($old) && abs((float) $old - $new) < 0.000001;
        }
        if ($new === null) {
            return $old === null || $old === '';
        }

        return (string) $old === (string) $new;
    }

    /**
     * An old value as the answer shows it: in the type of the new one, and in words where the merchant uses words.
     *
     * @param string $field
     * @param mixed $value
     * @param mixed $like
     *
     * @return mixed
     */
    private static function shown(string $field, $value, $like)
    {
        if ($field === 'additional_delivery_times') {
            $words = array_flip(self::DELIVERY_TIMES);

            return $words[(int) $value] ?? $value;
        }
        if ($field === 'pack_stock_type') {
            $words = array_flip(self::PACK_STOCK_TYPES);

            return $words[(int) $value] ?? $value;
        }
        if (is_bool($like)) {
            return (bool) $value;
        }
        if (is_int($like)) {
            return (int) $value;
        }
        if (is_float($like)) {
            return (float) $value;
        }

        return $value === null ? null : (string) $value;
    }

    /**
     * @param array<string, mixed> $plan
     * @param string $field
     * @param mixed $old
     * @param mixed $new
     *
     * @return void
     */
    private static function record(array &$plan, string $field, $old, $new): void
    {
        $plan['changes'][] = ['field' => $field, 'old' => $old, 'new' => $new];
    }

    /**
     * @param array<int, int> $a
     * @param array<int, int> $b
     *
     * @return bool
     */
    private static function sameSet(array $a, array $b): bool
    {
        return self::sorted($a) === self::sorted($b);
    }

    /**
     * @param array<int, int> $ids
     *
     * @return array<int, int>
     */
    private static function sorted(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * The texts of the languages being filled, as one comparable string.
     *
     * @param array<int, string> $texts by language identifier
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     *
     * @return string
     */
    private static function textsKey(array $texts, array $languages): string
    {
        $key = [];
        foreach (array_keys($languages['filled']) as $idLang) {
            $key[] = (string) ($texts[$idLang] ?? '');
        }

        return (string) json_encode($key);
    }
}
