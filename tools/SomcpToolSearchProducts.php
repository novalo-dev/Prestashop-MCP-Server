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
 * somcp_search_products - the way into the catalogue of a shop.
 *
 * The filters are the ones of the back office product list, because that is
 * the screen a merchant already thinks in: "the inactive products under 10
 * euros", "the one with reference TSHIRT". One short line per product; the
 * full product is somcp_get_product's job.
 *
 * Read through a query rather than through Product objects: a page of twenty
 * would otherwise load twenty products with every language, price and
 * combination they carry. Nothing is written here, so no hook is missed by
 * going this way.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolSearchProducts
{
    /** @var int Products returned when the caller asks for no particular number. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Most a single call will return, whatever it asks for. */
    private const MAX_LIMIT = 100;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_search_products',
            Somcp::t('Search products'),
            'Finds products of this shop with the filters of the back office product list - '
                . 'identifier, name, reference, category, price without or with tax, quantity, '
                . 'status, EAN13, MPN, UPC, ISBN - and returns a short line for each: reference, '
                . 'name, base price without and with tax, total quantity, status, default category '
                . '(identifier and name) and the small cover image URL (null without a cover). Every filter is optional and they combine. A reference or an EAN13 '
                . 'also finds the product through one of its combinations, and says which ones '
                . 'matched. Prices are the base price of the product, before specific prices and '
                . 'combination impacts; the price with tax uses the tax rule of the shop default '
                . 'country. Use it to find a product, or to answer questions about several. '
                . 'Do not use it to read a product already identified - somcp_get_product returns '
                . 'everything about it. Do not use it to review stock levels across the catalogue - '
                . 'that is somcp_list_low_stock. Category identifiers are specific to each shop: '
                . 'take them from somcp_list_categories, never guess one.',
            [
                'type' => 'object',
                'properties' => [
                    'id_product' => [
                        'type' => 'integer',
                        'description' => 'Exact product identifier.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Part of the product name, in the language of the call.',
                    ],
                    'reference' => [
                        'type' => 'string',
                        'description' => 'Part of the reference of the product or of one of its combinations.',
                    ],
                    'id_category' => [
                        'type' => 'integer',
                        'description' => 'Keep the products associated with this category, its default '
                            . 'one or not. From somcp_list_categories, never guessed.',
                    ],
                    'price_tax_excl_min' => [
                        'type' => 'number',
                        'description' => 'Lowest base price without tax, included.',
                    ],
                    'price_tax_excl_max' => [
                        'type' => 'number',
                        'description' => 'Highest base price without tax, included.',
                    ],
                    'price_tax_incl_min' => [
                        'type' => 'number',
                        'description' => 'Lowest base price with tax, included.',
                    ],
                    'price_tax_incl_max' => [
                        'type' => 'number',
                        'description' => 'Highest base price with tax, included.',
                    ],
                    'quantity_min' => [
                        'type' => 'integer',
                        'description' => 'Lowest total quantity, combinations included, included.',
                    ],
                    'quantity_max' => [
                        'type' => 'integer',
                        'description' => 'Highest total quantity, combinations included, included.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true for active products only, false for inactive ones only.',
                    ],
                    'ean13' => [
                        'type' => 'string',
                        'description' => 'Part of the EAN13 of the product or of one of its combinations.',
                    ],
                    'mpn' => [
                        'type' => 'string',
                        'description' => 'Part of the MPN of the product.',
                    ],
                    'upc' => [
                        'type' => 'string',
                        'description' => 'Part of the UPC barcode of the product.',
                    ],
                    'isbn' => [
                        'type' => 'string',
                        'description' => 'Part of the ISBN of the product.',
                    ],
                    'sort' => [
                        'type' => 'string',
                        'enum' => ['date_add_desc', 'name_asc', 'price_asc', 'price_desc', 'quantity_asc', 'quantity_desc'],
                        'description' => 'Order of the results. Most recently created first by default.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of the language to read and search the names in, '
                            . 'for example fr: one of the shop\'s active languages, refused otherwise. '
                            . 'The shop default is used when left out.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many products to return, 20 by default and 100 at most.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'How many to skip, to read the next page.',
                    ],
                ],
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
        $idLang = self::language($arguments, $idShop);
        if ($idLang === null) {
            return SomcpTool::text(
                'This language is not one of the shop\'s. Give the ISO code of one of its languages, or leave '
                    . 'language out for the shop default.',
                true
            );
        }
        $prices = self::priceExpressions($idShop);

        $count = self::query($arguments, $shop, $idLang, $prices);
        $count->select('COUNT(*)');
        $total = (int) Db::getInstance()->getValue($count);

        $limit = self::limit($arguments);
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        $page = self::query($arguments, $shop, $idLang, $prices);
        $page->select('p.`id_product`, p.`reference`, p.`ean13`, pl.`name`, pl.`link_rewrite`, ps.`active`, ps.`id_category_default`');
        $page->select($prices['tax_excl'] . ' AS `price_tax_excl`');
        $page->select($prices['tax_incl'] . ' AS `price_tax_incl`');
        $page->select('IFNULL(sa.`quantity`, 0) AS `quantity`');
        $page->orderBy(self::sort($arguments, $prices['tax_excl']));
        $page->limit($limit, $offset);
        $rows = Db::getInstance()->executeS($page) ?: [];

        $matched = self::matchedCombinations($arguments, $idShop, array_map(static function (array $row): int {
            return (int) $row['id_product'];
        }, $rows));

        $categories = self::categoryNames(array_map(static function (array $row): int {
            return (int) $row['id_category_default'];
        }, $rows), $idLang, $idShop);
        $covers = self::covers($rows, $idShop);
        $link = Somcp::legacyContext()->link ?? new Link();

        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop));
        $precision = Validate::isLoadedObject($currency) ? (int) $currency->precision : 2;

        $products = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_product'];
            $product = [
                'id_product' => $id,
                'reference' => (string) $row['reference'],
                'name' => (string) $row['name'],
                'price_tax_excl' => round((float) $row['price_tax_excl'], $precision),
                'price_tax_incl' => round((float) $row['price_tax_incl'], $precision),
                // Product prices are stored in the shop default currency,
                // whatever currency a visitor later sees them in.
                'currency' => Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '',
                'quantity' => (int) $row['quantity'],
                'active' => (bool) $row['active'],
                'id_category_default' => (int) $row['id_category_default'],
                'category_default' => $categories[(int) $row['id_category_default']] ?? '',
                // The thumbnail of the Image column, so the assistant can show
                // which product it means without reading each one.
                'cover_image' => isset($covers[$id])
                    ? SomcpProductImage::url($link, $id, (string) $row['link_rewrite'], $covers[$id], 'small')
                    : null,
                'ean13' => (string) $row['ean13'],
            ];
            if (isset($matched[$id])) {
                // Says why a product came back whose own reference does not
                // look like what was asked, and which variant the merchant
                // was actually holding.
                $product['matched_combinations'] = $matched[$id];
            }
            $products[] = $product;
        }

        return SomcpTool::json([
            'products' => $products,
            'returned' => count($products),
            // So the assistant knows there is more instead of concluding from
            // one page that it has seen everything.
            'total_count' => $total,
            'offset' => $offset,
        ]);
    }

    /**
     * The query both the count and the page are built on: the joins and the
     * filters, without what is selected.
     *
     * The joins are the ones of the back office product list, so a product the
     * merchant sees there is a product found here, and the other way round.
     *
     * @param array<string, mixed> $arguments
     * @param Shop $shop
     * @param int $idLang
     * @param array{tax_excl: string, tax_incl: string} $prices
     *
     * @return DbQuery
     */
    private static function query(array $arguments, Shop $shop, int $idLang, array $prices): DbQuery
    {
        $idShop = (int) $shop->id;

        $query = new DbQuery();
        $query->from('product', 'p');
        // The shop comes from the token, never from the caller, and a product
        // not associated with that shop is not part of its catalogue.
        $query->innerJoin('product_shop', 'ps', 'ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop);
        $query->leftJoin(
            'product_lang',
            'pl',
            'pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $idLang . ' AND pl.`id_shop` = ' . $idShop
        );
        // The total quantity sits on the row without a combination. Whether it
        // belongs to the shop or to its group depends on stock sharing, which
        // the core decides for us.
        $query->leftJoin(
            'stock_available',
            'sa',
            'sa.`id_product` = p.`id_product` AND sa.`id_product_attribute` = 0'
                . (string) StockAvailable::addSqlShopRestriction(null, $shop, 'sa')
        );
        // A product the back office is still creating is not in its list
        // either: it has no name yet and nobody can sell it.
        $query->where('p.`state` = ' . (int) Product::STATE_SAVED);

        if (!empty($arguments['id_product'])) {
            $query->where('p.`id_product` = ' . (int) $arguments['id_product']);
        }
        if (self::text($arguments, 'name') !== '') {
            $query->where("pl.`name` LIKE '%" . pSQL(self::text($arguments, 'name')) . "%'");
        }
        foreach (['reference', 'ean13'] as $field) {
            // A merchant holding a size M shirt reads the reference of the
            // combination on the label, not the one of the product.
            if (self::text($arguments, $field) !== '') {
                $like = "LIKE '%" . pSQL(self::text($arguments, $field)) . "%'";
                $query->where(
                    '(p.`' . $field . '` ' . $like . ' OR EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` pa'
                    . ' INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas'
                    . ' ON pas.`id_product_attribute` = pa.`id_product_attribute` AND pas.`id_shop` = ' . $idShop
                    . ' WHERE pa.`id_product` = p.`id_product` AND pa.`' . $field . '` ' . $like . '))'
                );
            }
        }
        foreach (['mpn', 'upc', 'isbn'] as $field) {
            if (self::text($arguments, $field) !== '') {
                $query->where('p.`' . $field . "` LIKE '%" . pSQL(self::text($arguments, $field)) . "%'");
            }
        }
        if (!empty($arguments['id_category'])) {
            // Any category the product is filed under, not only its default
            // one: that is what the category filter of the back office does.
            $query->where(
                'EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'category_product` cp'
                . ' WHERE cp.`id_product` = p.`id_product` AND cp.`id_category` = ' . (int) $arguments['id_category'] . ')'
            );
        }
        $bounds = [
            'price_tax_excl_min' => [$prices['tax_excl'], '>='],
            'price_tax_excl_max' => [$prices['tax_excl'], '<='],
            'price_tax_incl_min' => [$prices['tax_incl'], '>='],
            'price_tax_incl_max' => [$prices['tax_incl'], '<='],
        ];
        foreach ($bounds as $key => [$expression, $operator]) {
            if (isset($arguments[$key]) && is_numeric($arguments[$key])) {
                $query->where($expression . ' ' . $operator . ' ' . (float) $arguments[$key]);
            }
        }
        if (isset($arguments['quantity_min']) && is_numeric($arguments['quantity_min'])) {
            $query->where('IFNULL(sa.`quantity`, 0) >= ' . (int) $arguments['quantity_min']);
        }
        if (isset($arguments['quantity_max']) && is_numeric($arguments['quantity_max'])) {
            $query->where('IFNULL(sa.`quantity`, 0) <= ' . (int) $arguments['quantity_max']);
        }
        if (isset($arguments['active']) && is_bool($arguments['active'])) {
            $query->where('ps.`active` = ' . (int) $arguments['active']);
        }

        return $query;
    }

    /**
     * The base price without and with tax, as SQL, the way the back office
     * product list works them out.
     *
     * The list shows the price with tax of the shop default country, and adds
     * the ecotax to both when the shop uses it. Doing the same in SQL is what
     * lets the price filters and the sort agree with what the merchant reads
     * there. The rates come from the core, one per tax rule; they are numbers
     * we cast ourselves, never text from the caller.
     *
     * @param int $idShop
     *
     * @return array{tax_excl: string, tax_incl: string}
     */
    private static function priceExpressions(int $idShop): array
    {
        $taxEnabled = (bool) Configuration::get('PS_TAX', null, null, $idShop);
        $useEcotax = (bool) Configuration::get('PS_USE_ECOTAX', null, null, $idShop);

        $rates = $taxEnabled
            ? TaxRulesGroup::getAssociatedTaxRatesByIdCountry((int) Configuration::get('PS_COUNTRY_DEFAULT', null, null, $idShop))
            : [];

        $ratio = '1';
        if ($rates !== []) {
            $cases = '';
            foreach ($rates as $idGroup => $rate) {
                $cases .= ' WHEN ' . (int) $idGroup . ' THEN ' . (float) $rate;
            }
            // A tax rule with nothing for the default country taxes nothing
            // there, which is what ELSE 0 says.
            $ratio = '(1 + (CASE ps.`id_tax_rules_group`' . $cases . ' ELSE 0 END) / 100)';
        }

        $taxExcl = 'ps.`price`';
        $taxIncl = 'ps.`price` * ' . $ratio;
        if ($useEcotax) {
            // The ecotax has its own tax rule, set in the shop's tax settings.
            $ecoRate = (float) ($rates[(int) Configuration::get('PS_ECOTAX_TAX_RULES_GROUP_ID', null, null, $idShop)] ?? 0);
            $taxExcl = '(ps.`price` + ps.`ecotax`)';
            $taxIncl = '(ps.`price` * ' . $ratio . ' + ps.`ecotax` * ' . (1 + $ecoRate / 100) . ')';
        }

        return ['tax_excl' => $taxExcl, 'tax_incl' => $taxIncl];
    }

    /**
     * The combinations that matched a reference or an EAN13, per product of
     * the page.
     *
     * Read for the page only, after it is known: running it over the whole
     * catalogue would be paying for lines nobody will see.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, int> $ids
     *
     * @return array<int, array<int, array{id_product_attribute: int, reference: string, ean13: string}>>
     */
    private static function matchedCombinations(array $arguments, int $idShop, array $ids): array
    {
        $conditions = [];
        foreach (['reference', 'ean13'] as $field) {
            if (self::text($arguments, $field) !== '') {
                $conditions[] = 'pa.`' . $field . "` LIKE '%" . pSQL(self::text($arguments, $field)) . "%'";
            }
        }
        if ($conditions === [] || $ids === []) {
            return [];
        }

        $query = new DbQuery();
        $query->select('pa.`id_product`, pa.`id_product_attribute`, pa.`reference`, pa.`ean13`');
        $query->from('product_attribute', 'pa');
        // A combination another shop of the installation has and this one
        // does not is not one the merchant can be holding.
        $query->innerJoin(
            'product_attribute_shop',
            'pas',
            'pas.`id_product_attribute` = pa.`id_product_attribute` AND pas.`id_shop` = ' . $idShop
        );
        $query->where('pa.`id_product` IN (' . implode(',', array_map('intval', $ids)) . ')');
        // Either filter is enough for a combination to be named: with both
        // given, a product can match its reference on itself and its EAN13
        // on a combination, and that combination is still the one to show.
        $query->where('(' . implode(' OR ', $conditions) . ')');
        $query->orderBy('pa.`id_product_attribute` ASC');

        $matched = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $matched[(int) $row['id_product']][] = [
                'id_product_attribute' => (int) $row['id_product_attribute'],
                'reference' => (string) $row['reference'],
                'ean13' => (string) $row['ean13'],
            ];
        }

        return $matched;
    }

    /**
     * The name of each default category of the page, in the language read.
     *
     * One query for the page rather than one per line: the Category column of
     * the back office list is what an assistant is asked about, and a second
     * call per product to learn it would be paying twenty times for one word.
     *
     * @param array<int, int> $ids
     * @param int $idLang
     * @param int $idShop
     *
     * @return array<int, string>
     */
    private static function categoryNames(array $ids, int $idLang, int $idShop): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $query = new DbQuery();
        $query->select('cl.`id_category`, cl.`name`');
        $query->from('category_lang', 'cl');
        $query->where('cl.`id_category` IN (' . implode(',', $ids) . ')');
        $query->where('cl.`id_lang` = ' . $idLang);
        // Category names are stored per shop: the one the merchant sees is
        // the one of the shop of the token.
        $query->where('cl.`id_shop` = ' . $idShop);

        $names = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $names[(int) $row['id_category']] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * The cover image of each product of the page, in this shop.
     *
     * The cover is chosen per shop (`image_shop`), so a product can show a
     * different one in each shop of the installation.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param int $idShop
     *
     * @return array<int, int> image identifier per product identifier
     */
    private static function covers(array $rows, int $idShop): array
    {
        $ids = array_map(static function (array $row): int {
            return (int) $row['id_product'];
        }, $rows);
        if ($ids === []) {
            return [];
        }

        $query = new DbQuery();
        $query->select('ims.`id_product`, ims.`id_image`');
        $query->from('image_shop', 'ims');
        $query->where('ims.`id_product` IN (' . implode(',', array_map('intval', $ids)) . ')');
        $query->where('ims.`id_shop` = ' . $idShop);
        $query->where('ims.`cover` = 1');

        $covers = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $covers[(int) $row['id_product']] = (int) $row['id_image'];
        }

        return $covers;
    }

    /**
     * A text filter, trimmed, or an empty string when it was not given.
     *
     * @param array<string, mixed> $arguments
     * @param string $key
     *
     * @return string
     */
    private static function text(array $arguments, string $key): string
    {
        return isset($arguments[$key]) && is_scalar($arguments[$key]) ? trim((string) $arguments[$key]) : '';
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $priceTaxExcl the price expression the filters use, so the
     *                             sort and the bounds agree
     *
     * @return string
     */
    private static function sort(array $arguments, string $priceTaxExcl): string
    {
        // An allowed list, not the caller's string: this goes into an ORDER BY.
        // The identifier last, so two products with the same value keep the
        // same order from one page to the next.
        switch ((string) ($arguments['sort'] ?? '')) {
            case 'name_asc':
                return 'pl.`name` ASC, p.`id_product` ASC';
            case 'price_asc':
                return $priceTaxExcl . ' ASC, p.`id_product` ASC';
            case 'price_desc':
                return $priceTaxExcl . ' DESC, p.`id_product` DESC';
            case 'quantity_asc':
                return 'IFNULL(sa.`quantity`, 0) ASC, p.`id_product` ASC';
            case 'quantity_desc':
                return 'IFNULL(sa.`quantity`, 0) DESC, p.`id_product` DESC';
            default:
                return 'p.`date_add` DESC, p.`id_product` DESC';
        }
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return int
     */
    private static function limit(array $arguments): int
    {
        $limit = (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT);

        return max(1, min(self::MAX_LIMIT, $limit));
    }

    /**
     * The language to read and search the names in, or null when the caller
     * named one the shop does not have.
     *
     * Refused rather than read in the default language, as the other read
     * tools do: names searched in the wrong language find nothing, and the
     * assistant would conclude the product does not exist.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     *
     * @return int|null
     */
    private static function language(array $arguments, int $idShop): ?int
    {
        if (!array_key_exists('language', $arguments) || $arguments['language'] === null || $arguments['language'] === '') {
            return (int) Somcp::legacyContext()->language->id;
        }
        $languages = SomcpToolAuditProductSeo::languages($arguments, $idShop);

        return $languages === null ? null : (int) array_key_first($languages);
    }
}
