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
 * somcp_create_cms_category - what Add new page category then Save do on
 * Design > Pages.
 *
 * Every field is checked by the plan of somcp_update_cms_category, run on a
 * category not saved yet, so creating and changing a category hold one rule.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The checks, the languages and the category answered are those of the
// tools that change and read a category.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolUpdateCmsSeo.php';
require_once __DIR__ . '/SomcpToolGetCmsCategory.php';
require_once __DIR__ . '/SomcpToolUpdateCmsCategory.php';

class SomcpToolCreateCmsCategory
{
    /** @var int Home, the root the tree of the form opens on. */
    private const HOME = 1;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_cms_category',
            Somcp::t('Create a CMS page category'),
            'Creates a page category of Design > Pages, as Add new page category then Save: Name, Displayed, '
                . 'Parent category, Description, Meta title, Meta description, Meta keywords (PrestaShop 8 only) '
                . 'and Friendly URL, per language for the texts. Only the Name is needed: the Friendly URL is then '
                . 'made from it, the category goes under Home and is displayed. Show the merchant the name and the '
                . 'parent before calling. Not replayable: a second call creates a second category, so when an answer '
                . 'is lost, look for it in somcp_list_cms_pages first; somcp_get_cms_category reads it.',
            [
                'type' => 'object',
                'properties' => SomcpToolUpdateCmsCategory::properties(),
                'required' => ['name'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CONTENT_WRITE],
            [self::class, 'handle'],
            // Add new page category of Design > Pages takes the add right on AdminCmsContent.
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
        $errors = SomcpTool::unknownFields($arguments, array_keys(SomcpToolUpdateCmsCategory::properties()));

        $arguments = SomcpToolUpdateCmsSeo::withLanguage($arguments, $idShop, $errors);
        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return SomcpTool::refused('created', $errors);
        }
        if (!array_key_exists('name', $arguments)) {
            // The required line every creation gives for a missing text.
            SomcpToolCreateProduct::texts($arguments, 'name', 'name', $languages, $errors, true);
        }

        // A category not saved yet: every check compares with an empty one,
        // so what remains in the plan is exactly what is written.
        $category = new CMSCategory();
        $plan = SomcpToolUpdateCmsCategory::plan($arguments, $category, $languages, $idShop, $errors);
        $made = self::linkRewrites($plan, $languages, $category, $errors);
        if ($errors !== []) {
            return SomcpTool::refused('created', $errors);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($category, $plan, $made, $languages, $idShop, $idEmployee): array {
            return self::create($category, $plan, $made, $languages, $idShop, $idEmployee);
        });
    }

    /**
     * The Friendly URL of each language filled without one, made from its
     * Name as the copier of the form does, or the reason it cannot be.
     *
     * @param array{texts: array<string, array<int, string>>} $plan
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param CMSCategory $category
     * @param array<int, string> $errors
     *
     * @return array<int, string> the addresses made, by language
     */
    private static function linkRewrites(array $plan, array $languages, CMSCategory $category, array &$errors): array
    {
        $made = [];
        foreach ($languages['filled'] as $idLang => $iso) {
            $name = $plan['texts']['name'][$idLang] ?? null;
            if (isset($plan['texts']['link_rewrite'][$idLang]) || $name === null) {
                continue;
            }
            $where = 'link_rewrite (' . $iso . '), made from the name';
            $url = SomcpToolUpdateCmsSeo::checked('link_rewrite', $name, $idLang, $category, $where, $errors);
            if ($url !== null) {
                $made[$idLang] = $url;
            }
        }

        return $made;
    }

    /**
     * One translated field of the category, by a name only known at run
     * time: meta_keywords is gone from PrestaShop 9, so a literal property
     * access would not type-check against both versions.
     *
     * @param CMSCategory $category
     * @param string $field
     * @param array<int, string> $values
     *
     * @return void
     */
    private static function fill(CMSCategory $category, string $field, array $values): void
    {
        $category->{$field} = $values;
    }

    /**
     * Adds the category as AddCmsPageCategoryHandler does and answers with
     * what was stored.
     *
     * @param CMSCategory $category
     * @param array{texts: array<string, array<int, string>>, set: array<string, mixed>} $plan
     * @param array<int, string> $made
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function create(CMSCategory $category, array $plan, array $made, array $languages, int $idShop, int $idEmployee): array
    {
        $texts = $plan['texts'];
        $texts['link_rewrite'] = ($texts['link_rewrite'] ?? []) + $made;
        $fields = ['name', 'description', 'meta_title', 'meta_description', 'link_rewrite'];
        if (isset(CMSCategory::$definition['fields']['meta_keywords'])) {
            $fields[] = 'meta_keywords';
        }
        $fallback = (int) $languages['fallback'];
        foreach ($fields as $field) {
            // A language not filled takes the text of the default one, as the
            // back office saves a field left empty in another language.
            $values = [];
            foreach ($languages['all'] as $idLang) {
                $values[$idLang] = (string) ($texts[$field][$idLang] ?? ($texts[$field][$fallback] ?? ''));
            }
            self::fill($category, $field, $values);
        }
        $category->id_parent = (int) ($plan['set']['id_parent'] ?? self::HOME);
        // On, as the switch of this form opens.
        $category->active = (bool) ($plan['set']['active'] ?? true);
        // cms_category_lang is kept by shop: the category goes to the shop of the token.
        $category->id_shop_list = [$idShop];

        try {
            // add() puts it last of its parent and works its level out.
            $done = $category->validateFields(false) === true && $category->validateFieldsLang(false) === true && $category->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done || (int) $category->id <= 0) {
            SomcpLogger::log('cms_category_create_failed', SomcpLogger::OUTCOME_FAILURE, Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text('The page category could not be created: ' . $failure . '.', true);
        }
        $id = (int) $category->id;
        // The identifier only: the texts are the merchant's.
        SomcpLogger::log('cms_category_created', SomcpLogger::OUTCOME_SUCCESS, 'cms category ' . $id, '', $idEmployee);

        $warnings = [];
        if ($made !== []) {
            $urls = [];
            foreach ($made as $idLang => $url) {
                $urls[] = $url . ' (' . $languages['filled'][$idLang] . ')';
            }
            $warnings[] = 'Its Friendly URL was made from the name: ' . implode(', ', $urls) . '.';
        }
        if (SomcpToolCreateTax::otherShops() > 0) {
            $warnings[] = 'The page category was created for this shop only.';
        }

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $idLang = (int) array_key_first($languages['filled']);
        $stored = SomcpToolGetCmsCategory::category($id, $idShop);
        $result = [
            'created' => true,
            'id_cms_category' => $id,
            'category' => $stored === null ? null : SomcpToolGetCmsCategory::cmsCategory($stored, $idLang, $languages['filled'][$idLang], $idShop),
        ];
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }
}
