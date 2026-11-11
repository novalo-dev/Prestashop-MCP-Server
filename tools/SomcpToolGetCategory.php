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
            'Returns what the form of Catalog > Categories > Edit shows for one category: names, Displayed, '
                . 'parent, position, descriptions, customer groups, friendly and full URL, meta fields, images, '
                . 'product and subcategory counts, and what the page does while hidden; a field this PrestaShop '
                . 'version lacks is null. Same names as somcp_create_category and somcp_update_category, so a value '
                . 'read here can be given back. id_category from somcp_list_categories: never guess one. Read only. '
                . 'For the list use somcp_list_categories, for its products somcp_search_products.',
            [
                'type' => 'object',
                'properties' => [
                    'id_category' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the category, from somcp_list_categories.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr; a code '
                            . 'the shop does not have is refused. The shop default language when left out.',
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
        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;

        // A cast would read an array as 1 and answer about a category nobody
        // asked for: only a whole number is an identifier.
        $idCategory = SomcpTool::id($arguments, 'id_category');
        $errors = SomcpTool::unknownFields($arguments, ['id_category', 'language']);
        $category = null;
        if ($idCategory === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_category');
        } else {
            $category = SomcpToolUpdateCategory::existingCategory($idCategory, $idShop);
            if ($category === null) {
                $errors[] = SomcpTool::notFound('id_category', 'category', $idCategory, 'somcp_list_categories');
            }
        }
        if ($errors !== [] || $category === null) {
            return SomcpTool::refused('read', $errors);
        }

        return SomcpTool::json(['language' => $iso] + SomcpToolCreateCategory::category((int) $category->id, $idLang, $idShop));
    }
}
