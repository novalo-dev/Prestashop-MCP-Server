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
 * somcp_list_cms_pages - the CMS pages and page categories of this shop, as
 * Design > Pages lists them.
 *
 * The screen's Title is the column meta_title and its Meta title the column
 * head_seo_title. They are returned as title and meta_title, the names
 * somcp_update_cms_seo takes and returns, never by their column names: an
 * assistant reading meta_title here and writing it there must be talking
 * about the same field.
 *
 * A shop holds a few dozen pages at most, so every page of the shop is read in
 * one query and filtered here, without paging. The categories are returned
 * too: no other tool gives an id_cms_category, and the filter would otherwise
 * take an identifier nothing hands out.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListCmsPages
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_cms_pages',
            Somcp::t('List content pages'),
            'Lists the CMS pages of this shop as Design > Pages shows them, displayed or not: '
                . 'identifier (id_cms), URL (link_rewrite), Title (title), Meta title (meta_title, '
                . 'the title of the browser tab and search results), position (0 for the first), '
                . 'whether it is displayed (active), its category and its address on the storefront. '
                . 'Pages are sorted by category, then in the order of the list. Also returns every '
                . 'page category of the shop, root included, with its identifier for id_cms_category. '
                . 'Take id_cms from here for somcp_update_cms_seo: identifiers are specific to each '
                . 'shop, so never assume one. The content of a page is not returned. Do not use it '
                . 'for the SEO checks of the pages (somcp_audit_cms_seo), nor to change a page\'s '
                . 'title, meta title, meta description or URL (somcp_update_cms_seo).',
            [
                'type' => 'object',
                'properties' => [
                    'id_cms_category' => [
                        'type' => 'integer',
                        'description' => 'Only the pages of this page category, from categories[] of a previous '
                            . 'call, not those of its sub-categories, as the screen shows one category at a time. '
                            . 'Left out, the pages of every category.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'The Displayed filter: true for displayed pages only, false for hidden '
                            . 'ones only. Left out, both.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Part of the page Title, case-insensitive. Left out, every page.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, '
                            . 'for the texts, which are translated; a code the shop does not have is refused. '
                            . 'The shop default language when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CONTENT_READ],
            [self::class, 'handle'],
            // The pages are managed on Design > Pages, the page a merchant
            // would open to look at them.
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
        $idShop = (int) Somcp::currentShop()->id;

        $active = $arguments['active'] ?? null;
        if ($active !== null && !is_bool($active)) {
            return SomcpTool::text('active: true or false.', true);
        }
        $title = $arguments['title'] ?? null;
        if ($title !== null && !is_string($title)) {
            return SomcpTool::text('title: a string, part of the page Title.', true);
        }
        $idCategory = $arguments['id_cms_category'] ?? null;
        if ($idCategory !== null && !is_int($idCategory)) {
            return SomcpTool::text('id_cms_category: an integer, from categories[] of somcp_list_cms_pages.', true);
        }

        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;

        $pages = self::pages($idLang, $idShop);

        $counts = [];
        foreach ($pages as $page) {
            $counts[$page['id_cms_category']] = ($counts[$page['id_cms_category']] ?? 0) + 1;
        }
        $categories = self::categories($idLang, $idShop, $counts);

        // A category asked for must be one of this shop: an identifier that
        // matches nothing is said, not answered with an empty list the
        // assistant would read as "this category has no pages".
        if ($idCategory !== null && !in_array($idCategory, array_column($categories, 'id_cms_category'), true)) {
            return SomcpTool::text(
                'id_cms_category: no page category of this shop has this identifier. '
                    . 'Call somcp_list_cms_pages without it to see them in categories[].',
                true
            );
        }

        $needle = $title !== null ? Tools::strtolower(trim($title)) : '';
        $link = Somcp::legacyContext()->link ?? new Link();

        $listed = [];
        foreach ($pages as $page) {
            if ($idCategory !== null && $page['id_cms_category'] !== $idCategory) {
                continue;
            }
            if ($active !== null && $page['active'] !== $active) {
                continue;
            }
            // Compared in PHP rather than with LIKE, so that % or _ in what
            // the merchant typed are plain characters, as on the screen.
            if ($needle !== '' && strpos(Tools::strtolower($page['title']), $needle) === false) {
                continue;
            }
            // Given the alias, the link is built from the route without
            // loading each page again.
            $page['url'] = (string) $link->getCMSLink($page['id_cms'], $page['link_rewrite'], null, $idLang, $idShop);
            $listed[] = $page;
        }

        return SomcpTool::json([
            'pages' => $listed,
            'categories' => $categories,
            'total_count' => count($listed),
            'language' => $iso,
        ]);
    }

    /**
     * Every page of the shop, in the order of the back office list.
     *
     * @param int $idLang
     * @param int $idShop
     *
     * @return array<int, array<string, mixed>>
     */
    private static function pages(int $idLang, int $idShop): array
    {
        // A query rather than CMS::getCMSPages(), which reads the shop from
        // the global context and leaves the hidden pages out unless asked:
        // here the shop comes from the token.
        $query = new DbQuery();
        $query->select('c.`id_cms`, c.`id_cms_category`, c.`position`, c.`active`, cl.`link_rewrite`, cl.`meta_title`, cl.`head_seo_title`');
        $query->from('cms', 'c');
        $query->innerJoin('cms_shop', 'cs', 'cs.`id_cms` = c.`id_cms` AND cs.`id_shop` = ' . $idShop);
        // A left join, so a page with no text in this language is still
        // listed rather than missing from the only list of identifiers.
        $query->leftJoin(
            'cms_lang',
            'cl',
            'cl.`id_cms` = c.`id_cms` AND cl.`id_lang` = ' . $idLang . ' AND cl.`id_shop` = ' . $idShop
        );
        $query->orderBy('c.`id_cms_category` ASC, c.`position` ASC, c.`id_cms` ASC');

        $pages = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $pages[] = [
                'id_cms' => (int) $row['id_cms'],
                'link_rewrite' => (string) ($row['link_rewrite'] ?? ''),
                'title' => (string) ($row['meta_title'] ?? ''),
                'meta_title' => (string) ($row['head_seo_title'] ?? ''),
                // From 0, as the other tools give a position; the list shows
                // it from 1.
                'position' => (int) $row['position'],
                'active' => (bool) $row['active'],
                'id_cms_category' => (int) $row['id_cms_category'],
            ];
        }

        return $pages;
    }

    /**
     * Every page category of the shop, root included, with how many pages
     * each one holds directly.
     *
     * @param int $idLang
     * @param int $idShop
     * @param array<int, int> $counts pages of the shop per category
     *
     * @return array<int, array<string, mixed>>
     */
    private static function categories(int $idLang, int $idShop, array $counts): array
    {
        $query = new DbQuery();
        $query->select('c.`id_cms_category`, c.`id_parent`, c.`position`, c.`active`, cl.`name`, cl.`description`');
        $query->from('cms_category', 'c');
        $query->innerJoin(
            'cms_category_shop',
            'cs',
            'cs.`id_cms_category` = c.`id_cms_category` AND cs.`id_shop` = ' . $idShop
        );
        $query->leftJoin(
            'cms_category_lang',
            'cl',
            'cl.`id_cms_category` = c.`id_cms_category` AND cl.`id_lang` = ' . $idLang . ' AND cl.`id_shop` = ' . $idShop
        );
        // The order of the back office: each level, then its positions.
        $query->orderBy('c.`id_parent` ASC, c.`position` ASC, c.`id_cms_category` ASC');

        $categories = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $id = (int) $row['id_cms_category'];
            $categories[] = [
                'id_cms_category' => $id,
                'name' => (string) ($row['name'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'id_parent' => (int) $row['id_parent'],
                'position' => (int) $row['position'],
                'active' => (bool) $row['active'],
                'pages_count' => $counts[$id] ?? 0,
            ];
        }

        return $categories;
    }
}
