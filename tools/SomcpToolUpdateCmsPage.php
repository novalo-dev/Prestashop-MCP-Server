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
 * somcp_update_cms_page - the Title, Page content, Page category and Displayed
 * of one page of Design > Pages, as Edit then Save do: the fields
 * somcp_update_cms_seo leaves to the back office, so every field of the form
 * has a tool.
 *
 * The page is found and the languages taken as somcp_update_cms_seo does, the
 * HTML cleaned by the rule of the product descriptions, then written as
 * EditCmsPageHandler writes it: validateFields() and validateFieldsLang(),
 * then CMS::update(), for the shop of the token.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The page, the languages and the read given back are those of the SEO tool.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolUpdateCmsSeo.php';
require_once __DIR__ . '/SomcpToolGetCmsPage.php';
require_once __DIR__ . '/SomcpToolListCmsPages.php';

class SomcpToolUpdateCmsPage
{
    /**
     * @var array<string, string> what somcp_get_cms_page returns that is not written here, with where it is
     *                            changed: accepted when given back unchanged, so a page read there can be
     *                            given back whole
     */
    private const KEPT_AS_READ = [
        'meta_title' => 'not changed by this tool - change it with somcp_update_cms_seo',
        'meta_description' => 'not changed by this tool - change it with somcp_update_cms_seo',
        'link_rewrite' => 'not changed by this tool - change it with somcp_update_cms_seo',
        'meta_keywords' => 'not changed by this tool - change it with somcp_update_cms_seo',
        'indexation' => 'not changed by this tool - change it with somcp_update_cms_seo',
        'position' => 'not changed by this tool - the merchant changes it in Design > Pages',
        'seo_title' => 'worked out by the shop from the Meta title and the Title',
        'url' => 'worked out by the shop from the Friendly URL',
    ];

    /** @var int The size of cms_lang.meta_title, the Title of the form. */
    private const TITLE_MAX = 255;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $kept = [];
        foreach (self::KEPT_AS_READ as $field => $where) {
            $kept[$field] = ['description' => 'As somcp_get_cms_page returns it, accepted unchanged only: ' . $where . '.'];
        }

        return new SomcpTool(
            'somcp_update_cms_page',
            Somcp::t('Change a CMS page'),
            'Changes the Title, Page content, Page category and Displayed of one page of Design > Pages, as Edit '
                . 'then Save, in the languages filled; only what is given. What somcp_get_cms_page returns can be '
                . 'given back. Read the page first and show the merchant the new text before calling. Not the Meta '
                . 'title, meta description, Friendly URL or Indexation (somcp_update_cms_seo). Replayable: the same '
                . 'call twice changes nothing the second time.',
            [
                'type' => 'object',
                'properties' => [
                    'id_cms' => [
                        'type' => 'integer',
                        'description' => 'The CMS page to change, from somcp_list_cms_pages.',
                    ],
                    'languages' => [
                        'type' => 'array',
                        'description' => 'ISO codes of the languages to fill, among the active languages of the shop. '
                            . 'All of them by default. Every text must then be given in each of them.',
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
                            . 'cleans it; an empty text empties the page in that language.',
                    ],
                    'id_cms_category' => [
                        'type' => 'integer',
                        'description' => 'Merchant. The Page category, an id_cms_category of somcp_list_cms_pages.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Displayed: whether the page is shown on the storefront.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'As somcp_get_cms_page returns it: the language its plain texts are in, '
                            . 'filled alone when languages is left out.',
                    ],
                ] + $kept,
                'required' => ['id_cms'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CONTENT_WRITE],
            [self::class, 'handle'],
            // Saving a page of Design > Pages takes the edit right on AdminCmsContent.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminCmsContent')
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
        $fields = array_merge(['id_cms', 'languages', 'language', 'title', 'content', 'id_cms_category', 'active'], array_keys(self::KEPT_AS_READ));
        $errors = SomcpTool::unknownFields($arguments, $fields);

        // An unknown page is reported with the misspelt fields of the same
        // call, so one answer lists every mistake.
        $idCms = SomcpTool::id($arguments, 'id_cms');
        $cms = SomcpToolUpdateCmsSeo::page($idCms, $idShop);
        if ($cms === null) {
            $errors[] = $idCms > 0
                ? SomcpTool::notFound('id_cms', 'CMS page', $idCms, 'somcp_list_cms_pages')
                : SomcpTool::notAnIdentifier('id_cms');

            return SomcpTool::refused('changed', $errors);
        }

        $arguments = SomcpToolUpdateCmsSeo::withLanguage($arguments, $idShop, $errors);
        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $errors);
        SomcpToolUpdateCmsSeo::kept($arguments, $cms, $idShop, $errors, self::KEPT_AS_READ);
        if ($languages === null) {
            return SomcpTool::refused('changed', $errors);
        }
        $plan = self::plan($arguments, $cms, $languages, $idShop, $errors);
        if ($errors !== []) {
            return SomcpTool::refused('changed', $errors);
        }

        $idLang = (int) array_key_first($languages['filled']);
        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_cms' => (int) $cms->id,
                'reason' => 'The page already holds every value given. Nothing was written.',
                'page' => SomcpToolGetCmsPage::cmsPage($cms, $idLang, $languages['filled'][$idLang], $idShop),
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($cms, $plan, $idShop, $idEmployee, $idLang, $languages): array {
            return self::apply($cms, $plan, $idShop, $idEmployee, $idLang, $languages['filled'][$idLang]);
        });
    }

    /**
     * What will be written, or every reason the call is refused, in $errors.
     *
     * A value equal to what the page holds is left out, so what remains is
     * exactly what changes. Public because somcp_create_cms_page checks its
     * fields here, against a page not saved yet, so both tools hold one rule.
     *
     * @param array<string, mixed> $arguments
     * @param CMS $cms
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array{texts: array<string, array<int, string>>, set: array<string, mixed>, changes: array<int, array<string, mixed>>, emptied: array<int, string>, cleaned: bool}
     */
    public static function plan(array $arguments, CMS $cms, array $languages, int $idShop, array &$errors): array
    {
        $plan = ['texts' => [], 'set' => [], 'changes' => [], 'emptied' => [], 'cleaned' => false];
        $default = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

        $titles = SomcpToolCreateProduct::texts($arguments, 'title', 'title', $languages, $errors);
        $size = SomcpTool::columnSize($cms, 'meta_title', self::TITLE_MAX);
        foreach ($titles ?? [] as $idLang => $text) {
            $iso = $languages['filled'][$idLang];
            $text = trim($text);
            if ($text === '') {
                $errors[] = $idLang === $default
                    ? 'title: required in the default language ' . $iso
                    : 'title (' . $iso . '): a text, never empty';
                continue;
            }
            if (Tools::strlen($text) > $size) {
                $errors[] = 'title (' . $iso . '): ' . $size . ' characters at most';
                continue;
            }
            if (!Validate::isGenericName($text)) {
                $errors[] = 'title (' . $iso . '): no <>={}';
                continue;
            }
            $old = SomcpToolUpdateCmsSeo::textOf($cms->meta_title, $idLang);
            if ($text !== $old) {
                $plan['texts']['meta_title'][$idLang] = $text;
                $plan['changes'][] = ['field' => 'title', 'language' => $iso, 'old' => $old, 'new' => $text];
            }
        }

        $contents = SomcpToolCreateProduct::texts($arguments, 'content', 'content', $languages, $errors);
        foreach ($contents ?? [] as $idLang => $text) {
            $iso = $languages['filled'][$idLang];
            $old = SomcpToolUpdateCmsSeo::textOf($cms->content, $idLang);
            // The stored HTML given back is no change, even where a second
            // cleaning would write it a little differently.
            if ($text === $old) {
                continue;
            }
            $html = SomcpToolCreateProduct::html($text, 'content (' . $iso . ')', $errors);
            if ($html !== trim($text)) {
                $plan['cleaned'] = true;
            }
            if ($html !== $old) {
                $plan['texts']['content'][$idLang] = $html;
                // The length rather than the HTML: a page runs to pages of text.
                $plan['changes'][] = ['field' => 'content', 'language' => $iso, 'old' => Tools::strlen($old), 'new' => Tools::strlen($html)];
                if ($html === '') {
                    $plan['emptied'][] = $iso;
                }
            }
        }

        if (array_key_exists('id_cms_category', $arguments)) {
            $category = SomcpTool::id($arguments, 'id_cms_category');
            $known = array_map('intval', array_column(SomcpToolListCmsPages::categories($default, $idShop, []), 'id_cms_category'));
            if ($category === 0) {
                $errors[] = SomcpTool::notAnIdentifier('id_cms_category');
            } elseif (!in_array($category, $known, true)) {
                $errors[] = SomcpTool::notFound('id_cms_category', 'page category', $category, 'somcp_list_cms_pages');
            } elseif ($category !== (int) $cms->id_cms_category) {
                $plan['set']['id_cms_category'] = $category;
                $plan['changes'][] = ['field' => 'id_cms_category', 'old' => (int) $cms->id_cms_category, 'new' => $category];
            }
        }

        if (array_key_exists('active', $arguments)) {
            // A boolean only: "false" cast to bool is true, and would show a
            // page the merchant meant to hide.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } elseif ($arguments['active'] !== (bool) $cms->active) {
                $plan['set']['active'] = $arguments['active'];
                $plan['changes'][] = ['field' => 'active', 'old' => (bool) $cms->active, 'new' => $arguments['active']];
            }
        }

        return $plan;
    }

    /**
     * Writes the change and answers with what was stored.
     *
     * @param CMS $cms
     * @param array{texts: array<string, array<int, string>>, set: array<string, mixed>, changes: array<int, array<string, mixed>>, emptied: array<int, string>, cleaned: bool} $plan
     * @param int $idShop
     * @param int $idEmployee
     * @param int $idLang the language the page is answered in
     * @param string $iso
     *
     * @return array<string, mixed>
     */
    private static function apply(CMS $cms, array $plan, int $idShop, int $idEmployee, int $idLang, string $iso): array
    {
        $idCms = (int) $cms->id;
        foreach ($plan['texts'] as $column => $values) {
            $current = is_array($cms->{$column}) ? $cms->{$column} : [];
            $cms->{$column} = array_replace($current, $values);
        }
        $left = null;
        if (array_key_exists('id_cms_category', $plan['set'])) {
            // Put last of its new category, as CMS::add() places a new page:
            // the old position number could tie with a page already there.
            $left = (int) $cms->id_cms_category;
            $cms->id_cms_category = (int) $plan['set']['id_cms_category'];
            $cms->position = (int) CMS::getLastPosition((int) $cms->id_cms_category);
        }
        if (array_key_exists('active', $plan['set'])) {
            $cms->active = (bool) $plan['set']['active'];
        }
        // The shop of the token only: cms_lang of the other shops keeps its texts.
        $cms->id_shop_list = [$idShop];

        try {
            $done = $cms->validateFields(false) === true && $cms->validateFieldsLang(false) === true && $cms->update();
            $failure = $done ? '' : 'PrestaShop refused to save it';
            if ($done && $left !== null) {
                CMS::cleanPositions($left);
            }
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log(
                'cms_page_update_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'cms ' . $idCms . ': ' . Tools::substr($failure, 0, 500),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The page could not be changed: ' . $failure . '. Read it with somcp_get_cms_page before calling again.',
                true
            );
        }

        $fields = array_values(array_unique(array_column($plan['changes'], 'field')));
        SomcpLogger::log('cms_page_updated', SomcpLogger::OUTCOME_SUCCESS, 'cms ' . $idCms . ': ' . implode(', ', $fields), '', $idEmployee);

        $warnings = [];
        if (($plan['set']['active'] ?? null) === false) {
            $warnings[] = 'The page is no longer shown on the storefront: its links in the footer and the menu lead to a page not found.';
        }
        foreach ($plan['emptied'] as $emptied) {
            $warnings[] = 'The page shows only its title in ' . $emptied . '.';
        }
        if ($plan['cleaned']) {
            $warnings[] = 'Some HTML of the content was removed by the shop\'s cleaning: read the page again to see what was kept.';
        }
        if (SomcpToolCreateTax::otherShops() > 0) {
            $warnings[] = 'The texts changed for this shop only; the category and Displayed are shared by every shop of the page.';
        }

        $stored = SomcpToolUpdateCmsSeo::page($idCms, $idShop);
        $result = [
            'changed' => true,
            'id_cms' => $idCms,
            'changes' => $plan['changes'],
            'page' => $stored === null ? null : SomcpToolGetCmsPage::cmsPage($stored, $idLang, $iso, $idShop),
        ];
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }
}
