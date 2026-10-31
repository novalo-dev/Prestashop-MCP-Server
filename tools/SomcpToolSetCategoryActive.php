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
 * somcp_set_category_active - the Displayed toggle of the back office category
 * list.
 *
 * Kept apart from somcp_update_category on purpose. "Hide this category" has
 * to touch Displayed and nothing else, and a tool that can rewrite a whole
 * category is one mistaken argument away from doing more. This one cannot.
 *
 * The sequence is the core's own, read from SetCategoryIsEnabledHandler on
 * 8.0.0 and 9.0.0, which calls ObjectModel::toggleStatus(): the active field
 * alone, written through Category::update() so actionCategoryUpdate fires. The
 * value asked for is written rather than flipped, so a call that crossed
 * another change never undoes it.
 *
 * Which categories exist in the shop of the token, and which are the root or
 * a Home, is decided by somcp_update_category's own checks, so the two tools
 * never disagree on what may be touched.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// checks below live in that class.
require_once __DIR__ . '/SomcpToolUpdateCategory.php';

class SomcpToolSetCategoryActive
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_set_category_active',
            Somcp::t('Show or hide a category'),
            'Shows or hides one category of this shop on the storefront, exactly like the Displayed toggle '
                . 'of the back office category list, and changes nothing else. Hiding a category does not '
                . 'switch its products off: they stay reachable through search and through their other '
                . 'categories, so tell the merchant. It is reversible: it is not a deletion. Find the category '
                . 'with somcp_list_categories first and use the identifier it returns; never guess one. When the '
                . 'category already has the state asked for, nothing is written and the answer says '
                . 'changed: false. The root category and Home cannot be hidden. On a multistore installation, '
                . 'PrestaShop keeps Displayed once for every shop sharing the category, so the change shows in '
                . 'each of them. Do not use it to change anything else about a category (somcp_update_category). '
                . 'One category per call: for several, call it once per category and report each result. Not '
                . 'to be retried on a lost answer: read the category with somcp_get_category to see whether the '
                . 'change went through.',
            [
                'type' => 'object',
                'properties' => [
                    'id_category' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the category, taken from somcp_list_categories.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true to show the category on the storefront, false to hide it.',
                    ],
                ],
                'required' => ['id_category', 'active'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // The toggle sits on the categories page, and it takes the edit
            // right there: hiding a category is a change, not a deletion.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminCategories')
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

        // A boolean only: a string such as "false" cast to bool is true, and
        // would show a category the merchant asked to hide.
        if (!array_key_exists('active', $arguments) || !is_bool($arguments['active'])) {
            return SomcpTool::text('Nothing was changed: active must be true or false.', true);
        }
        $active = $arguments['active'];

        $category = SomcpToolUpdateCategory::existingCategory(self::identifier($arguments['id_category'] ?? null), $idShop);
        if ($category === null) {
            return SomcpTool::text(
                'No category of this shop has this identifier. Nothing was changed. Find it with somcp_list_categories.',
                true
            );
        }
        // A hidden Home leaves the whole storefront tree unreachable, and the
        // back office offers no toggle for the root either.
        if (SomcpToolUpdateCategory::isTop($category, $shop)) {
            return SomcpTool::text(
                'Category ' . (int) $category->id . ' is the root category or the Home of a shop, which cannot be '
                    . 'hidden. Nothing was changed.',
                true
            );
        }

        if ((bool) $category->active === $active) {
            return SomcpTool::json([
                'changed' => false,
                'id_category' => (int) $category->id,
                'active' => $active,
                'reason' => $active
                    ? 'The category is already displayed. Nothing was written.'
                    : 'The category is already hidden. Nothing was written.',
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($category, $active, $idShop, $idEmployee): array {
            return self::apply($category, $active, $idShop, $idEmployee);
        });
    }

    /**
     * Writes Displayed, and only that.
     *
     * @param Category $category
     * @param bool $active
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Category $category, bool $active, int $idShop, int $idEmployee): array
    {
        $category->active = $active;
        // What toggleStatus() does: the single field the toggle writes, so a
        // value another process changed since the category was loaded is not
        // written back over.
        $category->setFieldsToUpdate(['active' => true]);
        // Category::update() re-places the category in every shop when it
        // finds a duplicate position; the other shops keep theirs.
        $category->id_shop_list = [$idShop];

        try {
            $done = $category->update();
        } catch (Throwable $e) {
            $done = false;
        }

        if (!$done) {
            SomcpLogger::log(
                'category_status_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'category ' . (int) $category->id . ' to ' . ($active ? 'displayed' : 'hidden'),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'Displayed could not be changed. Read the category with somcp_get_category to see what it holds '
                    . 'before calling again.',
                true
            );
        }

        SomcpLogger::log(
            'category_status_changed',
            SomcpLogger::OUTCOME_SUCCESS,
            'category ' . (int) $category->id . ' to ' . ($active ? 'displayed' : 'hidden'),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'changed' => true,
            'id_category' => (int) $category->id,
            'active' => $active,
        ]);
    }

    /**
     * A category identifier as a client may send it: an integer, or digits in a string.
     *
     * A cast would read an array as 1 and act on a category nobody named.
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
        if (is_string($value) && preg_match('/^\d{1,10}$/', trim($value))) {
            return (int) trim($value);
        }

        return 0;
    }
}
