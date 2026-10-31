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
 * somcp_list_categories - the categories of this shop, identifier and name.
 *
 * Kept that short on purpose: it is the list an assistant reads before any
 * call that takes a category - a product search by category, a product
 * created or changed - so that it never guesses an identifier. Category
 * identifiers differ from one shop to the next, and a guessed one files a
 * product in the wrong place with total confidence. Everything else about one
 * category is the job of somcp_get_category.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListCategories
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_categories',
            Somcp::t('List categories'),
            'Lists the categories of this shop: identifier and name, nothing else. Call it '
                . 'before any call that takes a category - filtering products by category, '
                . 'creating or changing a product - and take the identifier from here: category '
                . 'identifiers are specific to each shop, so never assume one. Two categories can '
                . 'share a name in different branches (Men > T-shirts, Women > T-shirts): when a '
                . 'name comes back more than once, ask the merchant which one they mean, or read '
                . 'each with somcp_get_category when the shop offers it, before writing anything. '
                . 'The root category is left out, since nothing can be placed in it; Home is listed. '
                . 'Do not use it to find the products of a category: somcp_search_products does '
                . 'that with id_category.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of the language to read the names in, '
                            . 'for example fr. The shop default is used otherwise.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Categories are managed on their own back office page, and that is
            // the page a merchant would open to look at them.
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

        // A query rather than Category::getSimpleCategories(), which reads the
        // shop from the global context: here it comes from the token and is
        // passed explicitly. Same tables, same exclusion of the root.
        $query = new DbQuery();
        $query->select('c.`id_category`, cl.`name`');
        $query->from('category', 'c');
        $query->innerJoin('category_shop', 'cs', 'cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $idShop);
        // A left join, so a category with no name in this language is still
        // listed rather than silently missing from the only list of identifiers.
        $query->leftJoin(
            'category_lang',
            'cl',
            'cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . $idLang . ' AND cl.`id_shop` = ' . $idShop
        );
        // Nothing can be placed in the root category, so offering it would only
        // invite a write the core refuses.
        $query->where('c.`id_category` != ' . (int) Configuration::get('PS_ROOT_CATEGORY'));
        // Tree order puts each category right after its parent, which keeps
        // two categories of the same name apart for whoever reads the list.
        $query->orderBy('c.`nleft` ASC, c.`id_category` ASC');

        $categories = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $categories[] = [
                'id_category' => (int) $row['id_category'],
                'name' => (string) ($row['name'] ?? ''),
            ];
        }

        return SomcpTool::json([
            'categories' => $categories,
            'count' => count($categories),
        ]);
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
