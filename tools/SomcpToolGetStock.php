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
 * somcp_get_stock - the quantities of one product, per combination.
 *
 * Read from stock_available, the quantities the storefront sells against,
 * with the same reader somcp_get_product uses, so the two tools never give
 * two quantities for one product. It sits under its own scope and the back
 * office Stock page, because a merchant may let an assistant count stock
 * without letting it read the whole product page, or the other way round.
 *
 * It takes an identifier only. Finding a product from a name or a reference
 * is somcp_search_products' job.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetStock
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_stock',
            Somcp::t('Get the stock of a product'),
            'Returns the stock of one product of this shop, as the storefront sells against it: '
                . 'quantity is what can be sold, physical_quantity what is on the shelves, and '
                . 'reserved_quantity what orders not shipped yet already hold. For a product with '
                . 'combinations, the product line is the total and each combination comes with its '
                . 'attributes (Size: M) and its own quantities; low_stock_threshold is the level the '
                . 'merchant set for an alert, null when none is set. Identify the product by its '
                . 'identifier, from somcp_search_products, which finds it from a name, a reference or '
                . 'an EAN13. Do not use it to find what runs low across the catalogue: '
                . 'somcp_list_low_stock does that when the shop offers it.',
            [
                'type' => 'object',
                'properties' => [
                    'id_product' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the product, from somcp_search_products.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of the language to read the product and attribute '
                            . 'names in, for example fr. The shop default is used otherwise.',
                    ],
                ],
                'required' => ['id_product'],
                'additionalProperties' => false,
            ],
            [SomcpScope::STOCK_READ],
            [self::class, 'handle'],
            // The Stock page of the catalogue is where a merchant reads these
            // quantities by hand.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminStockManagement')
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

        $product = SomcpToolGetProduct::locate((int) ($arguments['id_product'] ?? 0), $idLang, $idShop);
        if ($product === null) {
            return SomcpTool::text('No product of this shop has this identifier. Find it with somcp_search_products.', true);
        }

        $stock = SomcpToolGetProduct::stockRows($product, $shop);
        // The row without a combination holds the total of all of them.
        $total = $stock[0] ?? null;

        return SomcpTool::json([
            'id_product' => (int) $product->id,
            'name' => (string) $product->name,
            'quantity' => $total['quantity'] ?? 0,
            'physical_quantity' => $total['physical_quantity'] ?? 0,
            'reserved_quantity' => $total['reserved_quantity'] ?? 0,
            // Loaded for this shop, the product carries the threshold of
            // product_shop, which is the one the back office edits.
            'low_stock_threshold' => self::threshold($product->low_stock_threshold),
            'combinations' => self::combinations((int) $product->id, $idLang, $idShop, $stock),
        ]);
    }

    /**
     * Every combination of the product in this shop, with its quantities.
     *
     * Listed from product_attribute_shop rather than from the stock rows, so a
     * combination with no stock row yet is still there, at zero, instead of
     * missing from the answer.
     *
     * @param int $idProduct
     * @param int $idLang
     * @param int $idShop
     * @param array<int, array{quantity: int, physical_quantity: int, reserved_quantity: int, location: string, out_of_stock: int}> $stock
     *
     * @return array<int, array<string, mixed>>
     */
    private static function combinations(int $idProduct, int $idLang, int $idShop, array $stock): array
    {
        $query = new DbQuery();
        $query->select('pa.`id_product_attribute`, pas.`low_stock_threshold`');
        $query->from('product_attribute', 'pa');
        $query->innerJoin(
            'product_attribute_shop',
            'pas',
            'pas.`id_product_attribute` = pa.`id_product_attribute` AND pas.`id_shop` = ' . $idShop
        );
        $query->where('pa.`id_product` = ' . $idProduct);
        $query->orderBy('pa.`id_product_attribute` ASC');
        $rows = Db::getInstance()->executeS($query) ?: [];
        if ($rows === []) {
            return [];
        }

        $attributes = SomcpToolGetProduct::combinationAttributes(array_map(static function (array $row): int {
            return (int) $row['id_product_attribute'];
        }, $rows), $idLang);

        $combinations = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_product_attribute'];
            $combinations[] = [
                'id_product_attribute' => $id,
                'attributes' => $attributes[$id] ?? [],
                'quantity' => $stock[$id]['quantity'] ?? 0,
                'physical_quantity' => $stock[$id]['physical_quantity'] ?? 0,
                'reserved_quantity' => $stock[$id]['reserved_quantity'] ?? 0,
                'low_stock_threshold' => self::threshold($row['low_stock_threshold']),
            ];
        }

        return $combinations;
    }

    /**
     * The stored threshold, or null when the merchant set none.
     *
     * The column is nullable and null means no alert; reading it as 0 would
     * tell the assistant an alert is set at zero.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    private static function threshold($value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * The language to read the names in.
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
