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
 * somcp_set_stock_quantity - the quantity field of the back office Stock page.
 *
 * The sequence is the Stock page's own, read from StockRepository::updateStock()
 * on 8.0.0 and 9.0.0: the change becomes a movement, written through
 * StockAvailable::updateQuantity() with the "employee edition" reason, then the
 * physical and reserved quantities of the product are brought in line. That
 * path, unlike StockAvailable::setQuantity(), carries packs, the low stock
 * alert and actionUpdateQuantity with the movement, so other modules see the
 * change exactly as if the merchant had typed it.
 *
 * A new quantity is turned into the movement that reaches it, as the Stock page
 * does with what the merchant types. A movement given as such is not
 * replayable, which is why the description sends the assistant to
 * somcp_get_stock rather than to a second call.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolSetStockQuantity
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_set_stock_quantity',
            Somcp::t('Set the stock of a product'),
            'Changes the quantity the storefront sells against, for one product without combinations or for '
                . 'one combination, exactly like the quantity field of the back office Stock page: the change '
                . 'is recorded as a stock movement in the name of the account behind the connection. Give either '
                . 'quantity, the new level, or delta, a movement such as -3 or 10, never both; prefer quantity. '
                . 'A product with combinations has no quantity of its own, only the total of its combinations: '
                . 'give id_product_attribute, read from somcp_get_stock. A pack whose stock is worked out from '
                . 'its products cannot be set; one that moves its products with it moves them here too. '
                . 'When the quantity is already the one asked for, or delta is 0, nothing is written and the '
                . 'answer says changed: false. Find the product with somcp_search_products first and never guess '
                . 'an identifier. Do not use it to read stock (somcp_get_stock), nor to change anything else '
                . 'about a product (somcp_update_product). One product or combination per call. Not to be '
                . 'retried on a lost answer, above all with delta, which would move the stock twice: read '
                . 'somcp_get_stock to see whether the change went through.',
            [
                'type' => 'object',
                'properties' => [
                    'id_product' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the product, from somcp_search_products.',
                    ],
                    'id_product_attribute' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the combination, from somcp_get_stock. Required for a '
                            . 'product with combinations, refused for one without.',
                    ],
                    'quantity' => [
                        'type' => 'integer',
                        'description' => 'The new quantity the storefront can sell. Give this or delta.',
                    ],
                    'delta' => [
                        'type' => 'integer',
                        'description' => 'A movement: positive to add stock, negative to remove some. Give this '
                            . 'or quantity. Never retried.',
                    ],
                ],
                'required' => ['id_product'],
                'additionalProperties' => false,
            ],
            [SomcpScope::STOCK_WRITE],
            [self::class, 'handle'],
            // The Stock page is where a merchant changes these quantities by
            // hand, and it takes the edit right there.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminStockManagement')
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
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $quantity = self::integer($arguments, 'quantity');
        $delta = self::integer($arguments, 'delta');
        if ($quantity === false || $delta === false) {
            return SomcpTool::text('Nothing was changed: quantity and delta must be whole numbers.', true);
        }
        if (($quantity === null) === ($delta === null)) {
            return SomcpTool::text(
                'Nothing was changed: give either quantity, the new level, or delta, a movement - one of the two.',
                true
            );
        }

        $product = SomcpToolGetProduct::locate(
            self::identifier($arguments['id_product'] ?? null),
            (int) Configuration::get('PS_LANG_DEFAULT'),
            $idShop
        );
        if ($product === null) {
            return SomcpTool::text(
                'No product of this shop has this identifier. Nothing was changed. Find it with somcp_search_products.',
                true
            );
        }

        $idCombination = self::identifier($arguments['id_product_attribute'] ?? null);
        // A combination that cannot be read is refused rather than taken as
        // none, which would write the product line instead.
        if ($idCombination === 0 && !in_array($arguments['id_product_attribute'] ?? null, [null, 0, '0'], true)) {
            return SomcpTool::text('Nothing was changed: id_product_attribute must be a combination identifier.', true);
        }
        $refusal = self::refusal($product, $idCombination, $idShop);
        if ($refusal !== '') {
            return SomcpTool::text('Nothing was changed: ' . $refusal, true);
        }

        $previous = (int) StockAvailable::getQuantityAvailableByProduct((int) $product->id, $idCombination, $idShop);
        // A level becomes the movement that reaches it, as the Stock page
        // sends what the merchant typed.
        $movement = $delta ?? ((int) $quantity - $previous);
        if ($movement === 0) {
            return SomcpTool::json([
                'changed' => false,
                'id_product' => (int) $product->id,
                'id_product_attribute' => $idCombination,
                'previous_quantity' => $previous,
                'quantity' => $previous,
                'reason' => $delta === null
                    ? 'The quantity is already ' . $previous . '. Nothing was written.'
                    : 'A delta of 0 moves nothing. Nothing was written.',
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($product, $idCombination, $idShop, $idEmployee, $previous, $movement): array {
            return self::apply($product, $idCombination, $idShop, $idEmployee, $previous, $movement);
        });
    }

    /**
     * Writes the movement, then brings the physical quantity in line, as the Stock page does.
     *
     * @param Product $product
     * @param int $idCombination
     * @param int $idShop
     * @param int $idEmployee
     * @param int $previous
     * @param int $movement
     *
     * @return array<string, mixed>
     */
    private static function apply(Product $product, int $idCombination, int $idShop, int $idEmployee, int $previous, int $movement): array
    {
        $what = 'product ' . (int) $product->id . ($idCombination > 0 ? ' combination ' . $idCombination : '');

        try {
            $done = StockAvailable::updateQuantity(
                (int) $product->id,
                $idCombination,
                $movement,
                $idShop,
                true,
                [
                    'id_stock_mvt_reason' => (int) Configuration::get(
                        $movement > 0 ? 'PS_STOCK_MVT_INC_EMPLOYEE_EDITION' : 'PS_STOCK_MVT_DEC_EMPLOYEE_EDITION'
                    ),
                ]
            ) !== false;
        } catch (Throwable $e) {
            $done = false;
        }

        if (!$done) {
            SomcpLogger::log('stock_quantity_failed', SomcpLogger::OUTCOME_FAILURE, $what . ' by ' . $movement, '', $idEmployee);

            return SomcpTool::text(
                'The stock could not be changed. Read it with somcp_get_stock to see what it holds before calling again.',
                true
            );
        }

        // What StockRepository::syncAllStock() does after the Stock page
        // writes: without it, physical_quantity would still show the old level.
        (new PrestaShop\PrestaShop\Adapter\StockManager())->updatePhysicalProductQuantity(
            $idShop,
            (int) Configuration::get('PS_OS_ERROR'),
            (int) Configuration::get('PS_OS_CANCELED'),
            (int) $product->id
        );

        SomcpLogger::log('stock_quantity_changed', SomcpLogger::OUTCOME_SUCCESS, $what . ' by ' . $movement, '', $idEmployee);

        return SomcpTool::json([
            'changed' => true,
            'id_product' => (int) $product->id,
            'id_product_attribute' => $idCombination,
            'previous_quantity' => $previous,
            // Read back rather than worked out: a pack the core does not count
            // itself, or an order placed in between, would make the sum wrong.
            'quantity' => (int) StockAvailable::getQuantityAvailableByProduct((int) $product->id, $idCombination, $idShop),
        ]);
    }

    /**
     * Why this product or combination cannot take a quantity, or an empty string.
     *
     * @param Product $product
     * @param int $idCombination
     * @param int $idShop
     *
     * @return string
     */
    private static function refusal(Product $product, int $idCombination, int $idShop): string
    {
        $combinations = self::combinationCount((int) $product->id, $idShop);

        if ($idCombination > 0) {
            if ($combinations === 0) {
                return 'the product has no combinations; leave id_product_attribute out.';
            }
            if (!SomcpToolGetProduct::combinationBelongs($product, $idCombination, $idShop)) {
                return 'this combination does not belong to this product in this shop. Read them with somcp_get_stock.';
            }

            return '';
        }

        // The core keeps the product line at the total of its combinations
        // and rewrites it on every change to one of them: a quantity written
        // there would be overwritten by the next order.
        if ($combinations > 0) {
            return 'the product has combinations, and each one has its own quantity. Give id_product_attribute, '
                . 'read from somcp_get_stock.';
        }

        if (Pack::isPack((int) $product->id) && self::packStockType($product) === Pack::STOCK_TYPE_PRODUCTS_ONLY) {
            return 'the stock of this pack is worked out from the products it holds. Change theirs instead.';
        }

        return '';
    }

    /**
     * How the pack counts its stock, the shop setting standing in for "default".
     *
     * @param Product $product
     *
     * @return int
     */
    private static function packStockType(Product $product): int
    {
        $type = (int) $product->pack_stock_type;

        return $type === Pack::STOCK_TYPE_DEFAULT ? (int) Configuration::get('PS_PACK_STOCK_TYPE') : $type;
    }

    /**
     * How many combinations the product has in this shop.
     *
     * Counted from product_attribute_shop, as somcp_get_stock lists them, and
     * not with Product::hasAttributes(), which answers 0 whenever the shop has
     * combinations switched off even though their stock rows are still there.
     *
     * @param int $idProduct
     * @param int $idShop
     *
     * @return int
     */
    private static function combinationCount(int $idProduct, int $idShop): int
    {
        $query = new DbQuery();
        $query->select('COUNT(*)');
        $query->from('product_attribute', 'pa');
        $query->innerJoin(
            'product_attribute_shop',
            'pas',
            'pas.`id_product_attribute` = pa.`id_product_attribute` AND pas.`id_shop` = ' . $idShop
        );
        $query->where('pa.`id_product` = ' . $idProduct);

        return (int) Db::getInstance()->getValue($query);
    }

    /**
     * A whole number as a client may send it, null when absent, false when it is not one.
     *
     * @param array<string, mixed> $arguments
     * @param string $key
     *
     * @return int|false|null
     */
    private static function integer(array $arguments, string $key)
    {
        if (!array_key_exists($key, $arguments) || $arguments[$key] === null) {
            return null;
        }
        $value = $arguments[$key];
        // Digits in a string are accepted, as for identifiers; 2.5 or "ten"
        // are refused rather than cut to a quantity nobody asked for.
        if (is_string($value) && preg_match('/^-?\d{1,9}$/', $value)) {
            $value = (int) $value;
        }
        // Under a billion either way, so a level and the movement that reaches
        // it both fit the signed integer column of stock_available.
        if (is_int($value) && abs($value) < 1000000000) {
            return $value;
        }

        return false;
    }

    /**
     * An identifier as a client may send it: an integer, or digits in a string.
     *
     * @param mixed $value
     *
     * @return int
     */
    private static function identifier($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }
}
