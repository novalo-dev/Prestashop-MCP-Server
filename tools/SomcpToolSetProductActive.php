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
 * somcp_set_product_active - the Status toggle of the back office product list.
 *
 * Kept apart from somcp_update_product on purpose. "Switch this product off"
 * has to touch the status and nothing else, and a tool that can rewrite a whole
 * product is one mistaken argument away from doing more. This one cannot.
 *
 * The sequence is the core's own, read from ProductStatusUpdater on 8.0.0 and
 * BulkUpdateProductStatusHandler on 9.0.0: load the product for the shop, set
 * active, write that single field through Product::update() so actionProductSave
 * and actionProductUpdate fire, then bring the search index in line. Only the
 * shop of the token is written, as the toggle does in a single-shop context:
 * on a multistore installation the other shops keep their own status.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolSetProductActive
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_set_product_active',
            Somcp::t('Switch a product on or off'),
            'Switches one product of this shop on or off on the storefront, exactly like the Status toggle '
                . 'of the back office product list, and changes nothing else. Switching off hides the product '
                . 'from customers and is reversible: it is not a deletion. Find the product with '
                . 'somcp_search_products first and use the identifier it returns; never guess one. '
                . 'When the product already has the status asked for, nothing is written and the answer says '
                . 'changed: false. A product with no name in the shop default language cannot be switched on. '
                . 'Do not use it to change anything else about a product (somcp_update_product), nor to delete '
                . 'one (somcp_delete_product). One product per call: for several, call it once per product and '
                . 'report each result. Not to be retried on a lost answer: read the product with '
                . 'somcp_get_product to see whether the change went through.',
            [
                'type' => 'object',
                'properties' => [
                    'id_product' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the product, taken from somcp_search_products.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true to put the product online, false to take it offline.',
                    ],
                ],
                'required' => ['id_product', 'active'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // The toggle sits on the products page, and it takes the edit right
            // there: switching a product off is a change, not a deletion.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminProducts')
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

        // A boolean only: a string such as "false" cast to bool is true, and
        // would put online a product the merchant asked to take offline.
        if (!array_key_exists('active', $arguments) || !is_bool($arguments['active'])) {
            return SomcpTool::text('Nothing was changed: active must be true or false.', true);
        }
        $active = $arguments['active'];

        $product = self::locate(self::identifier($arguments['id_product'] ?? null), $idShop);
        if ($product === null) {
            return SomcpTool::text(
                'No product of this shop has this identifier. Nothing was changed. Find it with somcp_search_products.',
                true
            );
        }

        if ((bool) $product->active === $active) {
            return SomcpTool::json([
                'changed' => false,
                'id_product' => (int) $product->id,
                'active' => $active,
                'reason' => $active
                    ? 'The product is already online. Nothing was written.'
                    : 'The product is already offline. Nothing was written.',
            ]);
        }

        // PrestaShop 9 refuses this in ProductValidator: a product online with
        // no name in the default language shows an empty title on the
        // storefront. Refused here on 8 too, so the tool answers the same.
        if ($active && self::defaultName($product) === '') {
            return SomcpTool::text(
                'Nothing was changed: the product has no name in the shop default language, and cannot be '
                    . 'switched on without one. Give it a name with somcp_update_product first.',
                true
            );
        }

        return self::apply($product, $active, $idShop, $idEmployee);
    }

    /**
     * Writes the status, then brings the search index in line, as the core does.
     *
     * @param Product $product
     * @param bool $active
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Product $product, bool $active, int $idShop, int $idEmployee): array
    {
        $product->active = $active;
        // The single field the toggle writes, so a value another process
        // changed since the product was loaded is not written back over.
        $product->setFieldsToUpdate(['active' => true]);
        // The shop of the token only: product_shop of the other shops keeps
        // its own status.
        $product->id_shop_list = [$idShop];

        try {
            $done = $product->update();
        } catch (Throwable $e) {
            $done = false;
        }

        if (!$done) {
            SomcpLogger::log(
                'product_status_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'product ' . (int) $product->id . ' to ' . ($active ? 'online' : 'offline'),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The status could not be changed. Read the product with somcp_get_product to see what it holds '
                    . 'before calling again.',
                true
            );
        }

        // What ProductIndexationUpdater does after the toggle: a product the
        // search can find is indexed, one it can no longer find leaves the
        // index. Skipped when the shop has indexing switched off, as there.
        if (Configuration::get('PS_SEARCH_INDEXATION')) {
            if ($active && in_array($product->visibility, ['both', 'search'], true)) {
                Search::indexation(false, (int) $product->id);
            } else {
                Search::removeProductsSearchIndex([(int) $product->id]);
            }
        }

        SomcpLogger::log(
            'product_status_changed',
            SomcpLogger::OUTCOME_SUCCESS,
            'product ' . (int) $product->id . ' to ' . ($active ? 'online' : 'offline'),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'changed' => true,
            'id_product' => (int) $product->id,
            'active' => $active,
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
