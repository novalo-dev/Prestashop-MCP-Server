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
 * somcp_delete_product - deletes one product, as the back office does.
 *
 * The only tool that cannot be undone from the shop, so it does as little as
 * it can on its own: one product, found in the shop of the token, deleted
 * through Product::delete() so actionProductDelete fires and the modules that
 * keep data about products clean up after it.
 *
 * The shop comes from the token, never from an argument. id_shop_list is set
 * to that shop before deleting, so on a multistore installation the product
 * leaves this shop only; the core removes it for good when no shop carries it
 * any more. The answer says how many shops still do, so the assistant does
 * not report "deleted" to a merchant whose other stores still sell it.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolDeleteProduct
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_product',
            Somcp::t('Delete a product'),
            'Deletes one product of this shop, with its combinations, images and stock, as the back office '
                . 'does. Irreversible. Before calling it, read the product with somcp_get_product, ask the '
                . 'merchant to confirm naming it by its name and reference, and offer to switch it off instead '
                . 'with somcp_set_product_active, which hides it from customers and can be undone. Find the '
                . 'product with somcp_search_products first and use the identifier it returns; never guess one. '
                . 'Orders that contain the product keep their lines as sold. On a multistore installation it '
                . 'leaves this shop only: still_in_shops in the answer says how many other shops still carry '
                . 'it, which the merchant removes from a connection to each of them. One product per call: for '
                . 'several, confirm and call once per product. Not to be retried on a lost answer: search for '
                . 'the product with somcp_search_products to see whether it is gone.',
            [
                'type' => 'object',
                'properties' => [
                    'id_product' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the product, taken from somcp_search_products.',
                    ],
                ],
                'required' => ['id_product'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // The right a merchant needs to delete a product by hand, on the
            // products page: edit alone does not let them do it.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminProducts')
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

        // Refused, not ignored: a second call for a product already deleted
        // must not read as a success, nor fall on another product.
        $product = self::locate(self::identifier($arguments['id_product'] ?? null), $idShop);
        if ($product === null) {
            return SomcpTool::text(
                'No product of this shop has this identifier. Nothing was deleted. Find it with somcp_search_products.',
                true
            );
        }

        $idProduct = (int) $product->id;
        // Read before deleting: afterwards there is nothing left to read them
        // from, and the merchant needs them to know which product went.
        $name = self::defaultName($product);
        $reference = (string) $product->reference;

        // The shop of the token only, whatever the context would otherwise
        // list: Product::delete() and ObjectModel::delete() both take
        // id_shop_list over the context when it is set.
        $product->id_shop_list = [$idShop];

        try {
            $done = (bool) $product->delete();
        } catch (Throwable $e) {
            $done = false;
        }

        // Product::delete() also answers false when a clean-up step after the
        // removal fails, so whether the product is still here is asked of
        // the shop rather than inferred from that answer.
        $gone = !self::inShop($idProduct, $idShop);

        if (!$done || !$gone) {
            SomcpLogger::log(
                'product_delete_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'product ' . $idProduct . ($gone ? ' removed, clean-up incomplete' : ' still in the shop'),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                $gone
                    ? 'The product was removed from this shop, but PrestaShop could not clean up everything '
                        . 'attached to it. Tell the merchant; there is nothing to call again.'
                    : 'The product could not be deleted, and may have lost part of its combinations or images. '
                        . 'Read it with somcp_get_product to see what it holds before anything else.',
                true
            );
        }

        $stillInShops = self::shopCount($idProduct);

        SomcpLogger::log(
            'product_deleted',
            SomcpLogger::OUTCOME_SUCCESS,
            'product ' . $idProduct . ($stillInShops > 0 ? ', still in ' . $stillInShops . ' other shop(s)' : ''),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'deleted' => true,
            'id_product' => $idProduct,
            'name' => $name,
            'reference' => $reference,
            'still_in_shops' => $stillInShops,
        ]);
    }

    /**
     * The product as this shop holds it, every language loaded.
     *
     * @param int $idProduct
     * @param int $idShop
     *
     * @return Product|null
     */
    private static function locate(int $idProduct, int $idShop): ?Product
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
     * Whether the shop still carries the product.
     *
     * @param int $idProduct
     * @param int $idShop
     *
     * @return bool
     */
    private static function inShop(int $idProduct, int $idShop): bool
    {
        $query = new DbQuery();
        $query->select('1');
        $query->from('product_shop');
        $query->where('`id_product` = ' . $idProduct . ' AND `id_shop` = ' . $idShop);

        return (bool) Db::getInstance()->getValue($query);
    }

    /**
     * How many shops still carry the product: 0 once it is gone for good.
     *
     * @param int $idProduct
     *
     * @return int
     */
    private static function shopCount(int $idProduct): int
    {
        $query = new DbQuery();
        $query->select('COUNT(*)');
        $query->from('product_shop');
        $query->where('`id_product` = ' . $idProduct);

        return (int) Db::getInstance()->getValue($query);
    }

    /**
     * The name of the product in the shop default language.
     *
     * @param Product $product
     *
     * @return string
     */
    private static function defaultName(Product $product): string
    {
        $name = $product->name;
        // Loaded with every language, the name is an array keyed by language;
        // an override or a hook may still leave a single string.
        if (is_array($name)) {
            $name = $name[(int) Configuration::get('PS_LANG_DEFAULT')] ?? '';
        }

        return trim((string) $name);
    }

    /**
     * A product identifier as a client may send it: an integer, or digits in a string.
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
