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
 * somcp_get_cms_category - everything the edit form of a page category of
 * Design > Pages shows, its SEO texts included.
 *
 * somcp_list_cms_pages gives the columns of the list only. The texts are read
 * here under the names the page tools use, so what this tool answers can be
 * given back as it is to the tool that changes a category.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// reading of a translated field lives in that class.
require_once __DIR__ . '/SomcpToolUpdateCmsSeo.php';

class SomcpToolGetCmsCategory
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_cms_category',
            Somcp::t('Read a CMS page category'),
            'Returns one page category of Design > Pages as its edit form shows it: Name, Displayed, Parent '
                . 'category, Description, Meta title, Meta description, Meta keywords (PrestaShop 8 only) and '
                . 'Friendly URL, with its level, position and how many pages and categories it holds. Take '
                . 'id_cms_category from categories of somcp_list_cms_pages, never guess one. For the pages of '
                . 'a category, use somcp_list_cms_pages.',
            [
                'type' => 'object',
                'properties' => [
                    'id_cms_category' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the page category, from categories of somcp_list_cms_pages.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, '
                            . 'for the texts, which are translated; a code the shop does not have is refused. '
                            . 'The shop default language when left out.',
                    ],
                ],
                'required' => ['id_cms_category'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CONTENT_READ],
            [self::class, 'handle'],
            // The page categories are managed on Design > Pages, the page a
            // merchant would open to look at one.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminCmsContent')
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

        // Every problem is collected, so one call corrects them all.
        $errors = SomcpTool::unknownFields($arguments, ['id_cms_category', 'language']);
        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            $errors[] = SomcpTool::languageProblem($idShop);
        }

        $category = self::lookup($arguments, $idShop, $errors);
        if ($errors !== [] || $category === null || $language === null) {
            return SomcpTool::refused('read', $errors);
        }
        [$idLang, $iso] = $language;

        return SomcpTool::json(self::cmsCategory($category, $idLang, $iso, $idShop));
    }

    /**
     * The category named by id_cms_category, or the line that says why there
     * is none. Public so the tools that change or delete a category refuse
     * the same identifiers with the same words.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return CMSCategory|null
     */
    public static function lookup(array $arguments, int $idShop, array &$errors): ?CMSCategory
    {
        if (!array_key_exists('id_cms_category', $arguments) || $arguments['id_cms_category'] === null) {
            $errors[] = 'id_cms_category: required - find it with somcp_list_cms_pages';

            return null;
        }
        // A cast would read an array or a word as a category nobody asked for.
        $id = SomcpTool::id($arguments, 'id_cms_category');
        if ($id === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_cms_category');

            return null;
        }
        $category = self::category($id, $idShop);
        if ($category === null) {
            $errors[] = SomcpTool::notFound('id_cms_category', 'page category', $id, 'somcp_list_cms_pages');
        }

        return $category;
    }

    /**
     * The category as this shop holds it, every language loaded, or null.
     *
     * @param int $id
     * @param int $idShop
     *
     * @return CMSCategory|null
     */
    public static function category(int $id, int $idShop): ?CMSCategory
    {
        if ($id <= 0) {
            return null;
        }
        // The constructor would load a category of another shop too: one the
        // shop is not associated with is answered like one that does not
        // exist, as somcp_list_cms_pages leaves it out of its list.
        $inShop = (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'cms_category_shop` WHERE `id_cms_category` = ' . (int) $id
            . ' AND `id_shop` = ' . (int) $idShop
        );
        if (!$inShop) {
            return null;
        }
        $category = new CMSCategory($id, null, $idShop);

        return Validate::isLoadedObject($category) ? $category : null;
    }

    /**
     * One category in one language, under the names the page tools take and
     * answer with. Public so the tools that change, create or delete a
     * category answer with this very read.
     *
     * @param CMSCategory $category the category, every language loaded
     * @param int $idLang
     * @param string $iso
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    public static function cmsCategory(CMSCategory $category, int $idLang, string $iso, int $idShop): array
    {
        $id = (int) $category->id;
        $idParent = (int) $category->id_parent;

        $parentName = null;
        if ($idParent > 0) {
            $parent = new CMSCategory($idParent, $idLang, $idShop);
            $parentName = Validate::isLoadedObject($parent)
                ? SomcpToolUpdateCmsSeo::textOf($parent->name, $idLang)
                : null;
        }

        $read = [
            'id_cms_category' => $id,
            'language' => $iso,
            'name' => SomcpToolUpdateCmsSeo::textOf($category->name, $idLang),
            'active' => (bool) $category->active,
            // 0 for Home, the root every other category hangs from.
            'id_parent' => $idParent,
            'parent_name' => $parentName,
            'level_depth' => (int) $category->level_depth,
            // From 0, as somcp_list_cms_pages gives a position.
            'position' => (int) $category->position,
            'description' => SomcpToolUpdateCmsSeo::textOf($category->description, $idLang),
            'meta_title' => SomcpToolUpdateCmsSeo::textOf($category->meta_title, $idLang),
            'meta_description' => SomcpToolUpdateCmsSeo::textOf($category->meta_description, $idLang),
        ];
        // meta_keywords is gone from PrestaShop 9: left out of the answer
        // there, so the read given back never carries a field the shop does
        // not have. Read from the object's properties, since a property
        // access is an unknown property to static analysis against that
        // version even behind the definition check.
        if (isset(CMSCategory::$definition['fields']['meta_keywords'])) {
            $properties = get_object_vars($category);
            $read['meta_keywords'] = SomcpToolUpdateCmsSeo::textOf($properties['meta_keywords'] ?? '', $idLang);
        }

        return $read + [
            'link_rewrite' => SomcpToolUpdateCmsSeo::textOf($category->link_rewrite, $idLang),
            'date_add' => (string) $category->date_add,
            'date_upd' => (string) $category->date_upd,
            'pages_count' => self::pagesCount($id, $idShop),
            'categories_count' => self::categoriesCount($id, $idShop),
        ];
    }

    /**
     * The pages of this shop filed directly in the category, as the list
     * counts them.
     *
     * @param int $id
     * @param int $idShop
     *
     * @return int
     */
    private static function pagesCount(int $id, int $idShop): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cms` c'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'cms_shop` cs ON cs.`id_cms` = c.`id_cms` AND cs.`id_shop` = ' . (int) $idShop
            . ' WHERE c.`id_cms_category` = ' . (int) $id
        );
    }

    /**
     * The categories of this shop directly under the category.
     *
     * @param int $id
     * @param int $idShop
     *
     * @return int
     */
    private static function categoriesCount(int $id, int $idShop): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cms_category` c'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'cms_category_shop` cs ON cs.`id_cms_category` = c.`id_cms_category`'
            . ' AND cs.`id_shop` = ' . (int) $idShop
            . ' WHERE c.`id_parent` = ' . (int) $id
        );
    }
}
