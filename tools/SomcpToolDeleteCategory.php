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
 * somcp_delete_category - the Delete action of Catalog > Categories.
 *
 * Category::delete() takes every subcategory with it, so the tool reads what
 * it is about to remove before calling it: the subcategories, and the
 * products linked to any of them. The core handlers of 8.0 and 9.0 then act
 * on every product of the shop that has no category left, including ones that
 * had none before the call; this tool acts on the products it read only, so a
 * product nobody asked about is never moved, switched off or deleted.
 *
 * A product that keeps other categories but whose default category went is
 * given the parent of the deleted category as its default, as 9.0 does; 8.0
 * leaves it pointing at a category that no longer exists.
 *
 * Which categories exist in the shop of the token, and which are the root or
 * a Home, is decided by somcp_update_category's own checks, so the category
 * tools never disagree on what may be touched.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// checks and the shared steps below live in these classes.
require_once __DIR__ . '/SomcpToolUpdateCategory.php';
require_once __DIR__ . '/SomcpToolCreateCategory.php';
require_once __DIR__ . '/SomcpToolDeleteProduct.php';

class SomcpToolDeleteCategory
{
    /**
     * The choices of the back office dialog, under the core's own values.
     */
    private const MODES = ['associate_and_disable', 'associate_only', 'remove_associated'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_category',
            Somcp::t('Delete a category'),
            'Deletes one category with all its subcategories, as Catalog > Categories does. Irreversible: read '
                . 'it with somcp_get_category, tell the merchant how many subcategories go and what products '
                . 'does, confirm by name. Only products left in no category are touched. Root and Home refused. '
                . 'id_category from somcp_list_categories. To hide one use somcp_set_category_active. One '
                . 'category per call. Not to be retried: if the answer is lost, look with somcp_list_categories.',
            [
                'type' => 'object',
                'properties' => [
                    'id_category' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the category, taken from somcp_list_categories.',
                    ],
                    'products' => [
                        'type' => 'string',
                        'enum' => self::MODES,
                        'description' => 'Products left in no category: moved to the parent and switched off '
                            . '(associate_and_disable, recommended by the back office), moved only '
                            . '(associate_only), or deleted (remove_associated).',
                    ],
                ],
                'required' => ['id_category', 'products'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // The right the Delete action of the categories page takes: edit
            // alone does not let the merchant do it by hand.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminCategories')
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
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = [];
        foreach (array_keys($arguments) as $key) {
            if (!in_array($key, ['id_category', 'products'], true)) {
                $errors[] = $key . ': not a field of this tool';
            }
        }
        // Required although the dialog checks the first choice: the deletion
        // cannot be undone, so the merchant's choice is given, never assumed.
        $mode = $arguments['products'] ?? null;
        if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
            $errors[] = 'products: one of ' . implode(', ', self::MODES);
        }
        if ($errors !== []) {
            return SomcpTool::text(
                "Nothing was deleted. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
        }

        // Refused, not ignored: a second call for a category already deleted
        // must not read as a success, nor fall on another category.
        $category = SomcpToolUpdateCategory::existingCategory(SomcpTool::id($arguments, 'id_category'), $idShop);
        if ($category === null) {
            return SomcpTool::text(
                'No category of this shop has this identifier. Nothing was deleted. Find it with somcp_list_categories.',
                true
            );
        }
        // Without its root or its Home the shop has no tree to hang anything
        // on, and the back office offers no Delete on them.
        if (SomcpToolUpdateCategory::isTop($category, $shop)) {
            return SomcpTool::text(
                'Category ' . (int) $category->id . ' is the root category or the Home of a shop, which cannot be '
                    . 'deleted. Nothing was deleted.',
                true
            );
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($category, $mode, $shop, $idEmployee): array {
            return self::delete($category, (string) $mode, $shop, $idEmployee);
        });
    }

    /**
     * Deletes the category, then settles the products it read beforehand.
     *
     * @param Category $category
     * @param string $mode
     * @param Shop $shop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function delete(Category $category, string $mode, Shop $shop, int $idEmployee): array
    {
        $idShop = (int) $shop->id;
        $idCategory = (int) $category->id;
        $idLang = (SomcpTool::readLanguage([], $idShop) ?? [0, ''])[0];

        // Everything below is read before the deletion: afterwards the names
        // and the links to the deleted categories are gone.
        $described = SomcpToolCreateCategory::category($idCategory, $idLang, $idShop);
        $subcategories = [];
        foreach ($category->getAllChildren($idLang) as $child) {
            $subcategories[] = [
                'id_category' => (int) $child->id,
                'name' => SomcpToolCreateCategory::textOf($child->name, $idLang),
            ];
        }
        $deletedIds = array_merge([$idCategory], array_column($subcategories, 'id_category'));
        $products = self::linkedProducts($deletedIds, $idShop, $idLang);

        $category->id_shop_list = [$idShop];
        try {
            $done = (bool) $category->delete();
        } catch (Throwable $e) {
            $done = false;
        }
        // Category::delete() answers true even when a subcategory could not
        // be removed, so whether the category is still here is asked of the
        // shop rather than inferred from that answer.
        if (!$done || SomcpToolUpdateCategory::existingCategory($idCategory, $idShop) !== null) {
            SomcpLogger::log('category_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'category ' . $idCategory, '', $idEmployee);

            return SomcpTool::text(
                'The category could not be deleted. Read it with somcp_get_category to see what it still holds '
                    . 'before anything else.',
                true
            );
        }

        // The parent the products go to, as the core picks it; Home when the
        // parent went too or is not in this shop.
        $idParent = (int) $category->id_parent;
        if (SomcpToolUpdateCategory::existingCategory($idParent, $idShop) === null) {
            $idParent = (int) $shop->id_category;
        }

        $result = self::settleProducts($products, $deletedIds, $idParent, $mode, $idShop);

        $stillInShops = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'category_shop` WHERE `id_category` = ' . $idCategory
        );

        SomcpLogger::log(
            'category_deleted',
            SomcpLogger::OUTCOME_SUCCESS,
            'category ' . $idCategory . ' with ' . count($subcategories) . ' subcategories, products ' . $mode
                . ($stillInShops > 0 ? ', still in ' . $stillInShops . ' other shop(s)' : ''),
            '',
            $idEmployee
        );

        $answer = [
            'deleted' => true,
            'id_category' => $idCategory,
            'name' => (string) $described['name'],
            'id_parent' => (int) $described['id_parent'],
            'parent_name' => (string) $described['parent_name'],
            'deleted_subcategories' => $subcategories,
            'products_moved' => $result['moved'],
            'products_disabled' => $result['disabled'],
            'products_deleted' => $result['deleted'],
            'products_default_category_changed' => $result['default_changed'],
            'still_in_shops' => $stillInShops,
        ];
        if ($result['failed'] !== []) {
            $answer['products_not_settled'] = $result['failed'];
        }

        return SomcpTool::json($answer);
    }

    /**
     * The products of this shop linked to the categories about to go, by
     * category or as their default one, with their name.
     *
     * @param array<int, int> $categoryIds
     * @param int $idShop
     * @param int $idLang
     *
     * @return array<int, string> names keyed by id_product
     */
    private static function linkedProducts(array $categoryIds, int $idShop, int $idLang): array
    {
        $in = implode(',', array_map('intval', $categoryIds));
        $rows = Db::getInstance()->executeS(
            'SELECT ps.`id_product`, pl.`name` FROM `' . _DB_PREFIX_ . 'product_shop` ps'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = ps.`id_product`'
            . ' AND pl.`id_shop` = ps.`id_shop` AND pl.`id_lang` = ' . (int) $idLang
            . ' WHERE ps.`id_shop` = ' . (int) $idShop
            . ' AND (ps.`id_category_default` IN (' . $in . ') OR EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'category_product` cp'
            . ' WHERE cp.`id_product` = ps.`id_product` AND cp.`id_category` IN (' . $in . ')))'
            . ' ORDER BY ps.`id_product`'
        ) ?: [];

        $products = [];
        foreach ($rows as $row) {
            $products[(int) $row['id_product']] = trim((string) $row['name']);
        }

        return $products;
    }

    /**
     * Moves, switches off or deletes the products left in no category, and
     * gives a new default category to the others whose default one went.
     *
     * @param array<int, string> $products names keyed by id_product
     * @param array<int, int> $deletedIds
     * @param int $idParent
     * @param string $mode
     * @param int $idShop
     *
     * @return array{moved: array<int, array<string, mixed>>, disabled: array<int, array<string, mixed>>, deleted: array<int, array<string, mixed>>, default_changed: array<int, array<string, mixed>>, failed: array<int, array<string, mixed>>}
     */
    private static function settleProducts(array $products, array $deletedIds, int $idParent, string $mode, int $idShop): array
    {
        $result = ['moved' => [], 'disabled' => [], 'deleted' => [], 'default_changed' => [], 'failed' => []];

        foreach ($products as $idProduct => $name) {
            $entry = ['id_product' => $idProduct, 'name' => $name];
            $product = new Product($idProduct, false, null, $idShop);
            if (!Validate::isLoadedObject($product)) {
                continue;
            }
            $remaining = array_map('intval', Product::getProductCategories($idProduct));
            $defaultGone = in_array((int) $product->id_category_default, $deletedIds, true)
                && !in_array((int) $product->id_category_default, $remaining, true);

            try {
                if ($remaining === []) {
                    if ($mode === 'remove_associated') {
                        [, $gone] = SomcpToolDeleteProduct::removeFromShop($product, $idShop);
                        $result[$gone ? 'deleted' : 'failed'][] = $entry;
                        continue;
                    }
                    $disable = $mode === 'associate_and_disable' && (bool) $product->active;
                    if ($disable) {
                        $product->active = false;
                    }
                    self::giveDefault($product, $idParent, $idShop);
                    $result['moved'][] = $entry;
                    if ($disable) {
                        $result['disabled'][] = $entry;
                    }
                } elseif ($defaultGone) {
                    self::giveDefault($product, $idParent, $idShop);
                    $result['default_changed'][] = $entry;
                }
            } catch (Throwable $e) {
                $result['failed'][] = $entry;
            }
        }

        return $result;
    }

    /**
     * Makes the category the product's default one and links it, as the
     * core's addProductDefaultCategory() does.
     *
     * @param Product $product
     * @param int $idCategory
     * @param int $idShop
     *
     * @return void
     */
    private static function giveDefault(Product $product, int $idCategory, int $idShop): void
    {
        $product->id_category_default = $idCategory;
        // The default category and Displayed are per shop: the other shops of
        // the product keep theirs.
        $product->id_shop_list = [$idShop];
        if (!$product->update()) {
            throw new RuntimeException('product not saved');
        }
        $product->addToCategories([$idCategory]);
    }
}
