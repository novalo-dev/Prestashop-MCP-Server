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
 * somcp_get_cms_page - everything the edit form of Design > Pages shows for
 * one CMS page, content included.
 *
 * It takes an identifier only. Finding a page from its title is the job of
 * somcp_list_cms_pages. The page is found, and its SEO title built, by the
 * same functions as somcp_update_cms_seo, so both tools agree on which pages
 * exist and on what a value read here means when it is given back there.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// page lookup and the SEO title live in that class.
require_once __DIR__ . '/SomcpToolUpdateCmsSeo.php';

class SomcpToolGetCmsPage
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_cms_page',
            Somcp::t('Get a content page'),
            'Returns everything the edit form of Design > Pages shows for one CMS page of this shop: page '
                . 'category (id_cms_category), Title (title), Meta title (meta_title, the title of the browser '
                . 'tab and search results, empty when not set), the title search engines read (seo_title), meta '
                . 'description, meta keywords (PrestaShop 8, null on 9), Friendly URL (link_rewrite), the page '
                . 'content as stored HTML, never cut, Indexation by search engines (indexation), Displayed '
                . '(active), position (0 for the first) and the page\'s address on the storefront (url). '
                . 'meta_title, meta_description, link_rewrite and indexation are named as somcp_update_cms_seo '
                . 'takes them. Identify the page by its id_cms, from somcp_list_cms_pages: never guess one. '
                . 'Do not use it to list pages or find one by its title (somcp_list_cms_pages), for the SEO '
                . 'checks of the pages (somcp_audit_cms_seo), nor to change a page\'s SEO texts '
                . '(somcp_update_cms_seo).',
            [
                'type' => 'object',
                'properties' => [
                    'id_cms' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the page, from somcp_list_cms_pages.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, '
                            . 'for the texts, which are translated; a code the shop does not have is refused. '
                            . 'The shop default language when left out.',
                    ],
                ],
                'required' => ['id_cms'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CONTENT_READ],
            [self::class, 'handle'],
            // The pages are managed on Design > Pages, the page a merchant
            // would open to look at one.
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

        // A cast would read an array or a word as a page nobody asked for:
        // only a whole number is an identifier, as somcp_update_cms_seo takes it.
        $idCms = $arguments['id_cms'] ?? null;
        if (!is_int($idCms)) {
            return SomcpTool::text('id_cms: an integer, from somcp_list_cms_pages.', true);
        }

        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;

        $cms = SomcpToolUpdateCmsSeo::page($idCms, $idShop);
        if ($cms === null) {
            return SomcpTool::text('No CMS page of this shop has this identifier. Find it with somcp_list_cms_pages.', true);
        }

        return SomcpTool::json(self::cmsPage($cms, $idLang, $iso, $idShop));
    }

    /**
     * One page in one language, under the names the page tools take and
     * answer with.
     *
     * @param CMS $cms the page, every language loaded
     * @param int $idLang
     * @param string $iso
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    private static function cmsPage(CMS $cms, int $idLang, string $iso, int $idShop): array
    {
        $link = Somcp::legacyContext()->link ?? new Link();
        $linkRewrite = SomcpToolUpdateCmsSeo::textOf($cms->link_rewrite, $idLang);

        // meta_keywords is gone from PrestaShop 9: read from the object's
        // properties, since a property access is an unknown property to static
        // analysis against that version even behind the definition check.
        $properties = get_object_vars($cms);

        return [
            'id_cms' => (int) $cms->id,
            'language' => $iso,
            'id_cms_category' => (int) $cms->id_cms_category,
            // The screen's Title is the column meta_title and its Meta title
            // is head_seo_title: named as the screen and the other page tools,
            // never by the column, so one is never written in place of the other.
            'title' => SomcpToolUpdateCmsSeo::textOf($cms->meta_title, $idLang),
            'meta_title' => SomcpToolUpdateCmsSeo::textOf($cms->head_seo_title, $idLang),
            'seo_title' => SomcpToolUpdateCmsSeo::seoTitle($cms, $idLang),
            'meta_description' => SomcpToolUpdateCmsSeo::textOf($cms->meta_description, $idLang),
            'meta_keywords' => isset(CMS::$definition['fields']['meta_keywords'])
                ? SomcpToolUpdateCmsSeo::textOf($properties['meta_keywords'] ?? '', $idLang)
                : null,
            'link_rewrite' => $linkRewrite,
            'content' => SomcpToolUpdateCmsSeo::textOf($cms->content, $idLang),
            'indexation' => (bool) $cms->indexation,
            'active' => (bool) $cms->active,
            // From 0, as somcp_list_cms_pages and the other tools give a
            // position; the back office list shows it from 1.
            'position' => (int) $cms->position,
            'url' => (string) $link->getCMSLink((int) $cms->id, $linkRewrite, null, $idLang, $idShop),
        ];
    }
}
