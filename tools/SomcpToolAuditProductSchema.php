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
 * somcp_audit_product_schema - the structured data a product page prints,
 * compared with what the back office holds for that product.
 *
 * Answer engines read the JSON-LD of the page, not the database: a theme or
 * a module can print an old price, no barcode or another brand while the
 * product page of the back office is right. Only the page itself says what
 * they are given.
 *
 * The page is fetched through the code of somcp_audit_page (hosts(),
 * follow(), load(), jsonLd()), so only the shop's own domains are ever
 * requested, and the shop's values are those somcp_get_product returns,
 * under its field names, so the two tools never disagree on a product.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolAuditProductSchema
{
    /** @var array<string, string> itemCondition as schema.org writes it, to the value of the Condition field. */
    private const CONDITIONS = [
        'newcondition' => 'new',
        'usedcondition' => 'used',
        'refurbishedcondition' => 'refurbished',
    ];

    /** @var array<string, string> Barcode properties of schema.org, to the field of somcp_get_product they print. */
    private const GTINS = [
        'gtin13' => 'ean13',
        'gtin12' => 'upc',
        'isbn' => 'isbn',
        'gtin' => 'ean13',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_audit_product_schema',
            Somcp::t('Compare a product page with its structured data'),
            'Fetches the public page of one product, as a visitor, and compares its JSON-LD Product with the '
                . 'product page of the back office: name, brand, Reference, MPN, barcodes, condition, price with '
                . 'tax, currency and stock. Gives what is printed, mismatches and missing fields. Never guess '
                . 'id_product: take it from somcp_search_products. For the stored fields alone use '
                . 'somcp_get_product; for the meta tags somcp_audit_product_seo; for any other page '
                . 'somcp_audit_page.',
            [
                'type' => 'object',
                'properties' => [
                    'id_product' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the product, from somcp_search_products.',
                    ],
                    'id_product_attribute' => [
                        'type' => 'integer',
                        'description' => 'One combination of that product, from somcp_get_product '
                            . 'combinations[].id_product_attribute: the page of that combination. Refused if it '
                            . 'belongs to another product.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of the page\'s language, for example fr: one of the shop\'s '
                            . 'active languages, refused otherwise. The shop default is used when left out.',
                    ],
                ],
                'required' => ['id_product'],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_READ],
            [self::class, 'handle'],
            // Every value compared is on the product page of the catalogue,
            // which the view right there already lets the employee read.
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
        $idShop = Somcp::currentShopId();
        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;

        $product = SomcpToolGetProduct::locate((int) ($arguments['id_product'] ?? 0), $idLang, $idShop);
        if ($product === null) {
            return SomcpTool::text('No product of this shop has this identifier. Find it with somcp_search_products.', true);
        }
        $idCombination = max(0, (int) ($arguments['id_product_attribute'] ?? 0));
        if ($idCombination > 0 && !SomcpToolGetProduct::combinationBelongs($product, $idCombination, $idShop)) {
            return SomcpTool::text('This combination does not belong to this product in this shop.', true);
        }

        // The shop's side is read by somcp_get_product itself, so a value
        // reported here is the one that tool shows and its update writes.
        $read = SomcpToolGetProduct::handle(
            ['id_product' => (int) $product->id, 'id_product_attribute' => $idCombination, 'language' => $iso],
            $context
        );
        $shopData = json_decode((string) ($read['content'][0]['text'] ?? ''), true);
        if (!empty($read['isError']) || !is_array($shopData)) {
            return $read;
        }

        $hosts = SomcpToolAuditPage::hosts($idShop);
        if ($hosts === []) {
            return SomcpTool::text('Nothing was fetched: this shop has no active domain.', true);
        }
        $link = Somcp::legacyContext()->link ?? new Link();
        $url = (string) $link->getProductLink($product, null, null, null, $idLang, $idShop, $idCombination > 0 ? $idCombination : null);
        if (!in_array(SomcpHttp::authority($url), $hosts, true)) {
            return SomcpTool::text(
                'Nothing was fetched: the product address ' . $url . ' is not on one of this shop\'s domains, which '
                    . 'are: ' . implode(', ', $hosts) . '.',
                true
            );
        }

        $fetched = SomcpToolAuditPage::follow($url, $hosts, SomcpToolAuditPage::TIMEOUT);
        if ($fetched['outside'] !== null) {
            return SomcpTool::text(
                'Nothing was read: the product page redirects to ' . $fetched['outside'] . ', outside this shop\'s '
                    . 'domains, which was not fetched.',
                true
            );
        }
        $response = $fetched['response'];
        if ($response === null || $response['status'] === 0) {
            return SomcpTool::text(
                'The product page could not be fetched: the shop did not answer'
                    . ($response !== null && $response['error'] !== '' ? ' (' . $response['error'] . ')' : '')
                    . '. Check that ' . $fetched['final_url'] . ' opens in a browser.',
                true
            );
        }
        if ($response['status'] !== 200) {
            return SomcpTool::text(
                'The product page answers HTTP ' . $response['status'] . ' instead of 200 at '
                    . $fetched['final_url'] . ': search engines and answer engines do not read it. An inactive '
                    . 'product, or one not visible in this shop, has no public page.',
                true
            );
        }

        $dom = SomcpToolAuditPage::load($response['body']);
        $items = $dom === null ? [] : SomcpToolAuditPage::jsonLd(new DOMXPath($dom));

        $found = [];
        $printed = null;
        foreach ($items as $item) {
            if ($item === null) {
                $found[] = 'invalid_json';
                continue;
            }
            $types = self::types($item);
            $found[] = $types === [] ? null : implode(', ', $types);
            if ($printed === null && in_array('product', array_map('strtolower', $types), true)) {
                $printed = $item;
            }
        }

        $answer = [
            'url' => $fetched['final_url'],
            'language' => $iso,
            'found' => $found,
            'product' => null,
            'offer' => null,
            'rating' => null,
            'mismatches' => [],
            'missing' => [],
        ];
        if ($printed === null) {
            // Nothing to compare: the theme decides what is printed, and no
            // tool of the module writes it.
            $answer['note'] = 'The page prints no JSON-LD Product: this is up to the theme or a module, not something '
                . 'a tool can write. Answer engines then read the product only from the visible text.';

            return SomcpTool::json($answer);
        }

        $answer = array_merge($answer, self::compare($printed, $shopData, $product, $idCombination, $idShop));

        return SomcpTool::json($answer);
    }

    /**
     * What the page prints, set against the shop's values.
     *
     * @param array<mixed> $printed the JSON-LD Product object
     * @param array<string, mixed> $shopData the answer of somcp_get_product
     * @param Product $product
     * @param int $idCombination
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    private static function compare(array $printed, array $shopData, Product $product, int $idCombination, int $idShop): array
    {
        $references = is_array($shopData['references'] ?? null) ? $shopData['references'] : [];
        $stock = is_array($shopData['stock'] ?? null) ? $shopData['stock'] : [];
        $combination = null;
        if ($idCombination > 0) {
            foreach ((array) ($shopData['combinations'] ?? []) as $row) {
                if (is_array($row) && (int) ($row['id_product_attribute'] ?? 0) === $idCombination) {
                    $combination = $row;
                }
            }
        }
        // A combination prints its own codes; one left empty falls back on
        // the product's, as the product page of the storefront does.
        $code = static function (string $field) use ($references, $combination): string {
            $own = $combination === null ? '' : trim((string) ($combination[$field] ?? ''));

            return $own !== '' ? $own : trim((string) ($references[$field] ?? ''));
        };

        $offer = self::offer($printed['offers'] ?? null);
        $gtinKey = null;
        $gtin = null;
        foreach (array_keys(self::GTINS) as $key) {
            $value = self::scalar($printed[$key] ?? null);
            if ($value !== null && $value !== '') {
                $gtinKey = $key;
                $gtin = $value;
                break;
            }
        }
        // Measured on the whole text: scalar() caps what is returned.
        $description = is_string($printed['description'] ?? null) ? $printed['description'] : null;
        $rating = is_array($printed['aggregateRating'] ?? null) ? $printed['aggregateRating'] : null;

        $shown = [
            'name' => self::scalar($printed['name'] ?? null),
            'brand' => self::brand($printed['brand'] ?? null),
            'sku' => self::scalar($printed['sku'] ?? null),
            'gtin' => $gtin,
            'mpn' => self::scalar($printed['mpn'] ?? null),
            'image' => self::image($printed['image'] ?? null),
            'description_length' => $description === null ? null : Tools::strlen(trim((string) preg_replace('/\s+/u', ' ', strip_tags($description)))),
        ];
        $shownOffer = $offer === null ? null : [
            'price' => self::scalar($offer['price'] ?? $offer['lowPrice'] ?? null),
            'currency' => self::scalar($offer['priceCurrency'] ?? null),
            'availability' => self::scalar($offer['availability'] ?? null),
            'url' => self::scalar($offer['url'] ?? null),
        ];

        $mismatches = [];
        $missing = [];
        $add = static function (string $field, $printedValue, $inShop) use (&$mismatches): void {
            $mismatches[] = [
                'field' => $field,
                'on_screen' => self::label($field),
                'printed' => $printedValue,
                'in_shop' => $inShop,
            ];
        };
        $absent = static function (string $field, string $property) use (&$missing): void {
            $missing[] = ['field' => $field, 'on_screen' => self::label($field), 'property' => $property];
        };

        $name = trim((string) ($shopData['basics']['name'] ?? ''));
        if ($shown['name'] === null || $shown['name'] === '') {
            $absent('name', 'name');
        } elseif ($name !== '' && Tools::strpos(Tools::strtolower($shown['name']), Tools::strtolower($name)) === false) {
            // A combination's page may add its attributes to the name, so the
            // product name only has to be found in what is printed.
            $add('name', $shown['name'], $name);
        }

        $brand = trim((string) ($shopData['brand']['manufacturer_name'] ?? ''));
        if ($shown['brand'] === null || $shown['brand'] === '') {
            if ($brand !== '') {
                $absent('manufacturer_name', 'brand');
            }
        } elseif (Tools::strtolower($shown['brand']) !== Tools::strtolower($brand)) {
            $add('manufacturer_name', $shown['brand'], $brand);
        }

        foreach (['sku' => 'reference', 'mpn' => 'mpn'] as $property => $field) {
            $inShop = $code($field);
            if ($shown[$property] === null || $shown[$property] === '') {
                if ($inShop !== '') {
                    $absent($field, $property);
                }
            } elseif ($shown[$property] !== $inShop) {
                $add($field, $shown[$property], $inShop);
            }
        }

        $barcodes = array_filter([
            'ean13' => $code('ean13'),
            'upc' => $code('upc'),
            'isbn' => $code('isbn'),
        ], static fn (string $value): bool => $value !== '');
        if ($gtinKey === null) {
            if ($barcodes !== []) {
                $absent((string) array_key_first($barcodes), 'gtin13');
            }
        } else {
            $field = self::GTINS[$gtinKey];
            // gtin alone may carry any of the three barcodes; a named one
            // must carry its own field.
            $matches = $gtinKey === 'gtin' ? in_array($gtin, $barcodes, true) : ($barcodes[$field] ?? '') === $gtin;
            if (!$matches) {
                $add($field, $gtin, $barcodes[$field] ?? '');
            }
        }

        $condition = self::scalar($printed['itemCondition'] ?? ($offer['itemCondition'] ?? null));
        if ($condition !== null && $condition !== '') {
            $key = Tools::strtolower((string) preg_replace('#^.*/#', '', $condition));
            $printedCondition = self::CONDITIONS[$key] ?? $condition;
            $inShop = (string) ($shopData['condition']['condition'] ?? '');
            if ($printedCondition !== $inShop) {
                $add('condition', $condition, $inShop);
            }
        }

        if ($shown['image'] === null || $shown['image'] === '') {
            if (!empty($shopData['images'])) {
                $absent('images', 'image');
            }
        }
        if ($shown['description_length'] === null) {
            $absent('description', 'description');
        }

        if ($shownOffer === null) {
            $absent('price', 'offers');
        } else {
            $currency = (string) ($shopData['pricing']['currency'] ?? '');
            $price = self::price($product, $idCombination, $idShop, $shopData, $combination);
            if ($shownOffer['price'] === null || $shownOffer['price'] === '') {
                $absent('price', 'offers.price');
            } elseif (!is_numeric($shownOffer['price']) || $price === null || abs((float) $shownOffer['price'] - $price) >= 0.01) {
                $add('price', $shownOffer['price'], $price);
            }
            if ($shownOffer['currency'] === null || $shownOffer['currency'] === '') {
                $absent('currency', 'offers.priceCurrency');
            } elseif (Tools::strtoupper($shownOffer['currency']) !== Tools::strtoupper($currency)) {
                $add('currency', $shownOffer['currency'], $currency);
            }
            $expected = self::availability($product, $stock, $combination, $idShop);
            if ($shownOffer['availability'] === null || $shownOffer['availability'] === '') {
                $absent('quantity', 'offers.availability');
            } else {
                $state = (string) preg_replace('#^.*/#', '', $shownOffer['availability']);
                // PreOrder and BackOrder both tell a buyer the product can be
                // ordered without stock; themes print one or the other.
                $same = $state === $expected
                    || ($expected === 'BackOrder' && $state === 'PreOrder');
                if (!$same) {
                    $add('quantity', $shownOffer['availability'], $expected);
                }
            }
        }

        return [
            'product' => $shown,
            'offer' => $shownOffer,
            'rating' => $rating === null ? null : [
                'rating_value' => self::scalar($rating['ratingValue'] ?? null),
                'rating_count' => self::scalar($rating['ratingCount'] ?? null),
                'review_count' => self::scalar($rating['reviewCount'] ?? null),
            ],
            'mismatches' => $mismatches,
            'missing' => $missing,
        ];
    }

    /**
     * The price with tax a visitor of the default country and currency sees,
     * specific prices included, as the storefront computes it; the product's
     * own price with its combination impact when the core cannot compute it
     * outside a storefront request.
     *
     * @param Product $product
     * @param int $idCombination
     * @param int $idShop
     * @param array<string, mixed> $shopData
     * @param array<string, mixed>|null $combination
     *
     * @return float|null
     */
    private static function price(Product $product, int $idCombination, int $idShop, array $shopData, ?array $combination): ?float
    {
        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop));
        $precision = Validate::isLoadedObject($currency) ? (int) $currency->precision : 2;
        try {
            $price = Product::getPriceStatic(
                (int) $product->id,
                true,
                $idCombination > 0 ? $idCombination : null,
                $precision
            );

            return round((float) $price, $precision);
        } catch (Throwable $e) {
            if (!isset($shopData['pricing']['price_tax_incl'])) {
                return null;
            }

            return round(
                (float) $shopData['pricing']['price_tax_incl'] + (float) ($combination['price_impact_tax_incl'] ?? 0),
                $precision
            );
        }
    }

    /**
     * The schema.org availability the storefront prints for this stock: the
     * rule of the core's product presenter.
     *
     * @param Product $product
     * @param array<string, mixed> $stock the stock block of somcp_get_product
     * @param array<string, mixed>|null $combination
     * @param int $idShop
     *
     * @return string
     */
    private static function availability(Product $product, array $stock, ?array $combination, int $idShop): string
    {
        if (!$product->active) {
            return 'Discontinued';
        }
        $quantity = (int) ($combination === null ? ($stock['quantity'] ?? 0) : ($combination['quantity'] ?? 0));
        if ($quantity > 0 || !Configuration::get('PS_STOCK_MANAGEMENT', null, null, $idShop)) {
            return 'InStock';
        }
        $outOfStock = (string) ($stock['out_of_stock'] ?? 'default');
        $allowed = $outOfStock === 'allow'
            || ($outOfStock === 'default' && (bool) Configuration::get('PS_ORDER_OUT_OF_STOCK', null, null, $idShop));

        return $allowed ? 'BackOrder' : 'OutOfStock';
    }

    /**
     * The label of a field on the product page of the back office, in the
     * words of the shop's version.
     *
     * @param string $field
     *
     * @return string
     */
    private static function label(string $field): string
    {
        $nine = version_compare(_PS_VERSION_, '9.0.0', '>=');
        $labels = [
            'name' => 'Product name',
            'manufacturer_name' => 'Brand',
            'reference' => 'Reference',
            'mpn' => 'MPN',
            'ean13' => $nine ? 'GTIN (EAN, JA, ITF or UCC code)' : 'EAN-13 or JAN barcode',
            'upc' => 'UPC barcode',
            'isbn' => 'ISBN',
            'condition' => 'Condition',
            'images' => 'Images',
            'description' => 'Description',
            'price' => 'Retail price (tax incl.)',
            'currency' => 'Default currency',
            'quantity' => 'Quantity',
        ];

        return $labels[$field] ?? $field;
    }

    /**
     * The JSON-LD type of an object, always as a list.
     *
     * @param array<mixed> $item
     *
     * @return array<int, string>
     */
    private static function types(array $item): array
    {
        $type = $item['@type'] ?? null;
        if (is_string($type)) {
            return [$type];
        }

        return is_array($type) ? array_values(array_filter($type, 'is_string')) : [];
    }

    /**
     * The first offer: offers can be one object, a list, or an AggregateOffer.
     *
     * @param mixed $offers
     *
     * @return array<mixed>|null
     */
    private static function offer($offers): ?array
    {
        if (!is_array($offers) || $offers === []) {
            return null;
        }
        if (array_is_list($offers)) {
            return is_array($offers[0]) ? $offers[0] : null;
        }

        return $offers;
    }

    /**
     * @param mixed $brand a name, or an object with one
     *
     * @return string|null
     */
    private static function brand($brand): ?string
    {
        if (is_array($brand)) {
            $brand = $brand['name'] ?? null;
        }

        return self::scalar($brand);
    }

    /**
     * @param mixed $image an address, a list of them, or an ImageObject
     *
     * @return string|null the first address
     */
    private static function image($image): ?string
    {
        if (is_array($image)) {
            $image = array_is_list($image) ? ($image[0] ?? null) : ($image['url'] ?? $image['contentUrl'] ?? null);
            if (is_array($image)) {
                $image = $image['url'] ?? $image['contentUrl'] ?? null;
            }
        }

        return self::scalar($image);
    }

    /**
     * A printed value as text, trimmed and capped, so one runaway field
     * cannot take the whole answer; null for anything else.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private static function scalar($value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return Tools::strlen($value) > 500 ? Tools::substr($value, 0, 500) . '...' : $value;
    }
}
