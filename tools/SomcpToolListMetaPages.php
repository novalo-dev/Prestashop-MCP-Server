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
 * somcp_list_meta_pages - the shop's own pages (home, contact, new products,
 * search...) with the title, description and friendly URL search engines read.
 *
 * These are the rows of Shop Parameters > Traffic & SEO, which the back office
 * reads from meta and meta_lang of the current shop and language. The same
 * tables are read here, on the shop of the token. The back office hides the
 * pages that are not configurable - the product, category and CMS templates,
 * whose texts come from each object, and a few payment module pages. They are
 * returned too, with configurable saying which is which, so an assistant knows
 * why a page it found has no title rather than concluding the shop forgot it.
 *
 * Nothing is ever written: somcp_update_meta_page changes a page.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolAuditProductSeo.php';

class SomcpToolListMetaPages
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_meta_pages',
            Somcp::t('List the shop pages and their SEO'),
            'Lists the shop\'s own pages - home, contact, new products, best sales, prices drop, search, '
                . 'sitemap, account and checkout pages, and the pages of modules - with their identifier, the '
                . 'page (controller) name, and in one language the meta title, the meta description, the friendly '
                . 'URL (url_rewrite) and the public address. These are the pages of Shop Parameters > Traffic & '
                . 'SEO. configurable is false for the product, category and CMS templates and a few module pages: '
                . 'the back office does not list them, their texts come from each product, category or page, and '
                . 'they have no address of their own (url is null). The home page has no url_rewrite. Read only. '
                . 'Do not use it for products, categories, brands or CMS pages, which have their own fields and '
                . 'tools; somcp_update_meta_page changes one of these pages.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one language of the shop, for example en. The shop default '
                            . 'is used otherwise.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_READ],
            [self::class, 'handle'],
            // The pages are listed under Shop Parameters > Traffic & SEO, and
            // reading them takes the view right there.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminMeta')
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

        if (!array_key_exists('language', $arguments) || $arguments['language'] === null || $arguments['language'] === '') {
            $idLang = (int) Somcp::legacyContext()->language->id;
            $iso = (string) Language::getIsoById($idLang);
        } else {
            // The same rule as the SEO audits: a language the shop does not
            // have is refused rather than silently read in another one.
            $languages = SomcpToolAuditProductSeo::languages($arguments, $idShop);
            if ($languages === null) {
                return SomcpTool::text(
                    'This language is not one of the shop\'s. Give the ISO code of one of its languages, or leave '
                        . 'language out for the shop default.',
                    true
                );
            }
            $idLang = (int) array_key_first($languages);
            $iso = (string) $languages[$idLang];
        }

        $link = Somcp::legacyContext()->link ?? new Link();

        $pages = [];
        foreach (self::rows($idShop, $idLang) as $row) {
            $page = (string) $row['page'];
            $configurable = (bool) $row['configurable'];
            $pages[] = [
                'id_meta' => (int) $row['id_meta'],
                'page' => $page,
                'title' => (string) ($row['title'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'url_rewrite' => (string) ($row['url_rewrite'] ?? ''),
                'url' => $configurable ? self::url($link, $page, $idLang, $idShop) : null,
                'configurable' => $configurable,
            ];
        }

        return SomcpTool::json([
            'pages' => $pages,
            'count' => count($pages),
            'language' => $iso,
        ]);
    }

    /**
     * Every page of the shop, with its texts in one language.
     *
     * @param int $idShop
     * @param int $idLang
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rows(int $idShop, int $idLang): array
    {
        $query = new DbQuery();
        $query->select('m.`id_meta`, m.`page`, m.`configurable`');
        $query->select('ml.`title`, ml.`description`, ml.`url_rewrite`');
        $query->from('meta', 'm');
        // meta_lang is per shop as well as per language, and the shop comes
        // from the token. A page without a row in it, such as the product
        // template, is still listed, with empty texts.
        $query->leftJoin(
            'meta_lang',
            'ml',
            'ml.`id_meta` = m.`id_meta` AND ml.`id_lang` = ' . $idLang . ' AND ml.`id_shop` = ' . $idShop
        );
        $query->orderBy('m.`id_meta` ASC');

        return Db::getInstance()->executeS($query) ?: [];
    }

    /**
     * The public address of one page, as the storefront builds it.
     *
     * @param Link $link
     * @param string $page
     * @param int $idLang
     * @param int $idShop
     *
     * @return string
     */
    private static function url(Link $link, string $page, int $idLang, int $idShop): string
    {
        // A module page is named module-<module>-<controller>, the pattern
        // Meta::getPages() writes, and is reached through the module's own
        // link: getPageLink() would send it to a core controller that does
        // not exist.
        if (preg_match('#^module-([a-z0-9_-]+)-([a-z0-9]+)$#i', $page, $match)) {
            return (string) $link->getModuleLink($match[1], $match[2], [], null, $idLang, $idShop);
        }

        return (string) $link->getPageLink($page, null, $idLang, null, false, $idShop);
    }
}
