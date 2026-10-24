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
 * somcp_get_category - everything the back office category form shows for one
 * category.
 *
 * The fields are the ones the category write tools take and answer with, read
 * by the very method they answer with. A second reader would be a second place
 * for a field to be named differently, and then an assistant reading a
 * category here could not hand it back to somcp_update_category as it is.
 *
 * Whether the category belongs to the shop of the token is decided by the same
 * check somcp_update_category makes, so the two never disagree on what exists.
 *
 * It takes an identifier only. Finding a category from a name is the job of
 * somcp_list_categories; two categories can share a name in different branches.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// readers below live in those classes.
require_once __DIR__ . '/SomcpToolUpdateCategory.php';

class SomcpToolGetCategory
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_category',
            Somcp::t('Get a category'),
            'Returns everything the back office category form shows for one category of this shop: name, '
                . 'Displayed (active), parent and its name, level in the tree, position, description, additional '
                . 'description, the customer groups that can see it, friendly URL and full URL, meta title, meta '
                . 'description, meta keywords (PrestaShop 8, null on 9), cover image and menu thumbnails '
                . '(addresses; menu thumbnails null on PrestaShop 9), the number of products in it and of '
                . 'subcategories, and what the page does while hidden (redirect_type and id_type_redirected, '
                . 'PrestaShop 9, null on 8). The field names are the ones somcp_create_category and '
                . 'somcp_update_category take, so a value read here can be given back as it is. '
                . 'Identify the category by its identifier, from somcp_list_categories: never guess one. Use it '
                . 'to tell apart two categories of the same name, by their parent. '
                . 'Do not use it for the list of categories, which is somcp_list_categories, or for the products '
                . 'of a category, which somcp_search_products finds with id_category.',
            [
                'type' => 'object',
                'properties' => [
                    'id_category' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the category, from somcp_list_categories.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of the language to read the texts in, for example fr. '
                            . 'The shop default is used otherwise.',
                    ],
                ],
                'required' => ['id_category'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Categories are managed on their own back office page, and that is
            // the page a merchant would open to look at one.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminCategories')
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
        $idShop = (int) Somcp::currentShop()->id;
        $idLang = self::language($arguments);

        // A cast would read an array as 1 and answer about a category nobody
        // asked for: only a whole number is an identifier.
        $value = $arguments['id_category'] ?? null;
        $idCategory = is_int($value) || (is_string($value) && preg_match('/^\d{1,10}$/', trim($value)))
            ? (int) trim((string) $value)
            : 0;

        $category = SomcpToolUpdateCategory::existingCategory($idCategory, $idShop);
        if ($category === null) {
            return SomcpTool::text('No category of this shop has this identifier. Find it with somcp_list_categories.', true);
        }

        return SomcpTool::json(SomcpToolCreateCategory::category((int) $category->id, $idLang, $idShop));
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
