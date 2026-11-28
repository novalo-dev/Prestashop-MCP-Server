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
 * somcp_create_cms_page - Add new page then Save on Design > Pages.
 *
 * Every field is checked by the tool that changes it afterwards, against a
 * page not saved yet: the Title, Page content, Page category and Displayed by
 * somcp_update_cms_page, the SEO fields by somcp_update_cms_seo, so a page
 * created here is one those tools would have accepted. The page is then added
 * as AddCmsPageHandler adds it: validateFields() and validateFieldsLang(),
 * then CMS::add(), which puts it last of its category, for the shop of the
 * token.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The checks, the languages and the read given back are those of the page tools.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolUpdateCmsSeo.php';
require_once __DIR__ . '/SomcpToolUpdateCmsPage.php';
require_once __DIR__ . '/SomcpToolGetCmsPage.php';
// The count of the other shops, for the warning of a multistore.
require_once __DIR__ . '/SomcpToolCreateTax.php';

class SomcpToolCreateCmsPage
{
    /** @var array<int, string> The fields somcp_update_cms_seo checks, with the languages they are given in. */
    private const SEO_FIELDS = ['languages', 'meta_title', 'meta_description', 'link_rewrite', 'meta_keywords', 'indexation'];

    /** @var array<int, string> The fields somcp_update_cms_page checks. */
    private const PAGE_FIELDS = ['title', 'content', 'id_cms_category', 'active'];

    /** @var int The root category of the pages, where the tree of the form opens. */
    private const ROOT_CATEGORY = 1;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_cms_page',
            Somcp::t('Create a CMS page'),
            'Creates a page of Design > Pages, as Add new page then Save: Title (required), Page content, Page '
                . 'category, Displayed (off by default), and its Meta title, meta description, Friendly URL (made '
                . 'from the Title when left out) and Indexation, per language for the texts. Show the merchant the '
                . 'title and the content and wait for their yes. Not replayable: a second call creates a second '
                . 'page; on a lost answer, read somcp_list_cms_pages first. To change a page: somcp_update_cms_page.',
            [
                'type' => 'object',
                'properties' => [
                    'languages' => [
                        'type' => 'array',
                        'description' => 'ISO codes of the languages to fill, among the active languages of the shop. '
                            . 'All of them by default. Every text must then be given in each of them; the other '
                            . 'languages take the text of the default one.',
                        'items' => ['type' => 'string'],
                    ],
                    'title' => [
                        'type' => ['object', 'string'],
                        'description' => 'Merchant. The Title of the page, per ISO code: 255 characters at most, '
                            . 'without < > = { }, never empty.',
                    ],
                    'content' => [
                        'type' => ['object', 'string'],
                        'description' => 'Merchant. The Page content, HTML per ISO code, cleaned as the back office '
                            . 'cleans it. Empty when left out.',
                    ],
                    'id_cms_category' => [
                        'type' => 'integer',
                        'description' => 'Merchant. The Page category, an id_cms_category of somcp_list_cms_pages. '
                            . 'The root category (1) when left out.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Displayed: whether the page is shown on the storefront. False '
                            . 'when left out, as the form opens.',
                    ],
                    'meta_title' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. The Meta title, per ISO code: 255 at '
                            . 'most; over 70 cut in search. Without < > = { }.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'meta_description' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. Per ISO code: 512 at most; over '
                            . '160 cut in search. Without < > = { }.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'link_rewrite' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. The Friendly URL, per ISO code: lower '
                            . 'case words joined by hyphens, 128 characters at most. Made from the Title when left out.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'meta_keywords' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. Meta keywords, per ISO code, comma '
                            . 'separated; PrestaShop 8 only.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'indexation' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Indexation by search engines: whether the page may appear in '
                            . 'search results. False when left out, as the form opens.',
                    ],
                ],
                'required' => ['title'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CONTENT_WRITE],
            [self::class, 'handle'],
            // Add new page of Design > Pages takes the add right on AdminCmsContent.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminCmsContent')
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
        // The identifier and what the read works out are answered "not a
        // field of this tool", as any other key the creation does not take.
        $errors = SomcpTool::unknownFields($arguments, array_merge(self::SEO_FIELDS, self::PAGE_FIELDS));

        // A page not saved yet: every check compares with an empty page, so
        // what remains in each plan is exactly what is written.
        $cms = new CMS();
        $seo = SomcpToolUpdateCmsSeo::plan(
            array_intersect_key($arguments, array_flip(self::SEO_FIELDS)),
            $cms,
            $idShop,
            $errors
        );
        // The languages read again for the page fields; their mistakes are
        // already in $errors from the SEO plan, and refused() lists a line once.
        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return SomcpTool::refused('created', $errors);
        }
        if (!array_key_exists('title', $arguments)) {
            // The required line every creation gives for a missing text.
            SomcpToolCreateProduct::texts($arguments, 'title', 'title', $languages, $errors, true);
        }
        $page = SomcpToolUpdateCmsPage::plan(
            array_intersect_key($arguments, array_flip(self::PAGE_FIELDS)),
            $cms,
            $languages,
            $idShop,
            $errors
        );
        $made = self::linkRewrites($seo, $page, $languages, $cms, $errors);
        if ($errors !== []) {
            return SomcpTool::refused('created', $errors);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($cms, $seo, $page, $made, $languages, $idShop, $idEmployee): array {
            return self::create($cms, $seo, $page, $made, $languages, $idShop, $idEmployee);
        });
    }

    /**
     * The Friendly URL of each language filled without one, made from its
     * Title as the copier of the form does, or the reason it cannot be.
     *
     * @param array{texts: array<string, array<int, string>>} $seo
     * @param array{texts: array<string, array<int, string>>} $page
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param CMS $cms
     * @param array<int, string> $errors
     *
     * @return array<int, string> the addresses made, by language
     */
    private static function linkRewrites(array $seo, array $page, array $languages, CMS $cms, array &$errors): array
    {
        $made = [];
        foreach ($languages['filled'] as $idLang => $iso) {
            $title = $page['texts']['meta_title'][$idLang] ?? null;
            if (isset($seo['texts']['link_rewrite'][$idLang]) || $title === null) {
                continue;
            }
            $where = 'link_rewrite (' . $iso . '), made from the title';
            $url = SomcpToolUpdateCmsSeo::checked('link_rewrite', $title, $idLang, $cms, $where, $errors);
            if ($url !== null) {
                $made[$idLang] = $url;
            }
        }

        return $made;
    }

    /**
     * One translated field of the page, by a name only known at run time:
     * meta_keywords is gone from PrestaShop 9, so a literal property access
     * would not type-check against both versions.
     *
     * @param CMS $cms
     * @param string $column
     * @param array<int, string> $values
     *
     * @return void
     */
    private static function fill(CMS $cms, string $column, array $values): void
    {
        $cms->{$column} = $values;
    }

    /**
     * Adds the page as AddCmsPageHandler does and answers with what was stored.
     *
     * @param CMS $cms
     * @param array{texts: array<string, array<int, string>>, indexation: bool|null} $seo
     * @param array{texts: array<string, array<int, string>>, set: array<string, mixed>, cleaned: bool} $page
     * @param array<int, string> $made
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function create(CMS $cms, array $seo, array $page, array $made, array $languages, int $idShop, int $idEmployee): array
    {
        $texts = $seo['texts'] + $page['texts'];
        $texts['link_rewrite'] = ($texts['link_rewrite'] ?? []) + $made;
        $columns = ['meta_title', 'head_seo_title', 'meta_description', 'link_rewrite', 'content'];
        if (isset(CMS::$definition['fields']['meta_keywords'])) {
            $columns[] = 'meta_keywords';
        }
        $fallback = (int) $languages['fallback'];
        foreach ($columns as $column) {
            // A language not filled takes the text of the default one, as the
            // back office saves a field left empty in another language.
            $values = [];
            foreach ($languages['all'] as $idLang) {
                $values[$idLang] = (string) ($texts[$column][$idLang] ?? ($texts[$column][$fallback] ?? ''));
            }
            self::fill($cms, $column, $values);
        }
        $cms->id_cms_category = (int) ($page['set']['id_cms_category'] ?? self::ROOT_CATEGORY);
        $cms->active = (bool) ($page['set']['active'] ?? false);
        $cms->indexation = (bool) ($seo['indexation'] ?? false);
        // cms_lang is kept by shop: the page goes to the shop of the token.
        $cms->id_shop_list = [$idShop];

        try {
            $done = $cms->validateFields(false) === true && $cms->validateFieldsLang(false) === true && $cms->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done || (int) $cms->id <= 0) {
            SomcpLogger::log('cms_page_create_failed', SomcpLogger::OUTCOME_FAILURE, Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text('The page could not be created: ' . $failure . '.', true);
        }
        $idCms = (int) $cms->id;
        // The identifier only: the texts are the merchant's.
        SomcpLogger::log('cms_page_created', SomcpLogger::OUTCOME_SUCCESS, 'cms ' . $idCms, '', $idEmployee);

        $warnings = [];
        if (!$cms->active) {
            $warnings[] = 'The page is not shown on the storefront yet: switch it on with somcp_update_cms_page once its texts are checked.';
        }
        if ($made !== []) {
            $urls = [];
            foreach ($made as $idLang => $url) {
                $urls[] = $url . ' (' . $languages['filled'][$idLang] . ')';
            }
            $warnings[] = 'Its Friendly URL was made from the title: ' . implode(', ', $urls) . '.';
        }
        if ($page['cleaned']) {
            $warnings[] = 'Some HTML of the content was removed by the shop\'s cleaning: read the page again to see what was kept.';
        }
        if (SomcpToolCreateTax::otherShops() > 0) {
            $warnings[] = 'The page was created for this shop only.';
        }

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $idLang = (int) array_key_first($languages['filled']);
        $stored = SomcpToolUpdateCmsSeo::page($idCms, $idShop);
        $result = [
            'created' => true,
            'id_cms' => $idCms,
            'page' => $stored === null ? null : SomcpToolGetCmsPage::cmsPage($stored, $idLang, $languages['filled'][$idLang], $idShop),
        ];
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }
}
