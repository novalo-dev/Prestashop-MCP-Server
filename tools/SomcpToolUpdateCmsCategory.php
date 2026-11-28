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
 * somcp_update_cms_category - what Edit then Save do on a page category of
 * Design > Pages: Name, Displayed, Parent category, Description and the SEO
 * texts, only the fields given.
 *
 * The texts go through the rules the page tools apply (the languages filled,
 * the cleaning of the HTML, the Friendly URL and the meta texts), called from
 * their classes, so a category and a page never answer one mistake two ways.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The languages, the HTML, the SEO texts and the read given back are those of
// the page tools and of the category read.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolUpdateCmsSeo.php';
require_once __DIR__ . '/SomcpToolGetCmsCategory.php';

class SomcpToolUpdateCmsCategory
{
    /** @var array<int, string> The SEO texts, checked by the rule of somcp_update_cms_seo. */
    private const SEO_TEXTS = ['meta_title', 'meta_description', 'link_rewrite', 'meta_keywords'];

    /**
     * @var array<int, string> what somcp_get_cms_category returns that the shop works out itself: accepted
     *                         when given back unchanged, so a category read there can be given back whole
     */
    private const COMPUTED = ['parent_name', 'level_depth', 'position', 'pages_count', 'categories_count', 'date_add', 'date_upd'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_cms_category',
            Somcp::t('Update a CMS page category'),
            'Changes one page category of Design > Pages, as Edit then Save: Name, Displayed, Parent category, '
                . 'Description, Meta title, Meta description, Meta keywords (PrestaShop 8 only) and Friendly URL, '
                . 'per language for the texts; only what is given. What somcp_get_cms_category returns can be '
                . 'given back. Read the category first and show the merchant what changes before calling. Not '
                . 'its pages (somcp_update_cms_page). Replayable: the same call twice changes nothing the second time.',
            [
                'type' => 'object',
                'properties' => self::properties() + [
                    'id_cms_category' => [
                        'type' => 'integer',
                        'description' => 'The page category to change, from categories of somcp_list_cms_pages.',
                    ],
                ] + self::computedProperties(),
                'required' => ['id_cms_category'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CONTENT_WRITE],
            [self::class, 'handle'],
            // Saving a page category of Design > Pages takes the edit right on AdminCmsContent.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminCmsContent')
        );
    }

    /**
     * The fields of the form, public for the tool that creates a category, so both
     * describe them in the same words.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function properties(): array
    {
        return [
            'languages' => [
                'type' => 'array',
                'description' => 'ISO codes of the languages to fill, among the active languages of the shop. '
                    . 'All of them by default. Every text must then be given in each of them.',
                'items' => ['type' => 'string'],
            ],
            'name' => [
                'type' => ['object', 'string'],
                'description' => 'Merchant. The Name of the category, per ISO code, without ; = # { }, never empty.',
            ],
            'description' => [
                'type' => ['object', 'string'],
                'description' => 'Merchant. The Description, HTML per ISO code, cleaned as the back office cleans it.',
            ],
            'meta_title' => [
                'type' => ['object', 'string'],
                'description' => 'Merchant. The Meta title, per ISO code, 255 characters at most.',
            ],
            'meta_description' => [
                'type' => ['object', 'string'],
                'description' => 'Merchant. The Meta description, per ISO code, 512 characters at most.',
            ],
            'meta_keywords' => [
                'type' => ['object', 'string', 'null'],
                'description' => 'Merchant. The Meta keywords, per ISO code, PrestaShop 8 only.',
            ],
            'link_rewrite' => [
                'type' => ['object', 'string'],
                'description' => 'Merchant. The Friendly URL, per ISO code, made lower case and joined by hyphens.',
            ],
            'active' => [
                'type' => 'boolean',
                'description' => 'Merchant. Displayed: whether the category is shown on the storefront.',
            ],
            'id_parent' => [
                'type' => 'integer',
                'description' => 'Merchant. The Parent category, an id_cms_category of somcp_list_cms_pages; 1 is Home.',
            ],
            'language' => [
                'type' => 'string',
                'description' => 'As somcp_get_cms_category returns it: the language its plain texts are in, '
                    . 'filled alone when languages is left out.',
            ],
        ];
    }

    /**
     * The worked-out fields of the read, described so it can be given back.
     *
     * @return array<string, array<string, string>>
     */
    private static function computedProperties(): array
    {
        $properties = [];
        foreach (self::COMPUTED as $field) {
            $properties[$field] = ['description' => 'As somcp_get_cms_category returns it, accepted unchanged only: worked out by the shop.'];
        }

        return $properties;
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

        // Every mistake of the call in one refusal, so it is corrected in one go.
        $fields = array_merge(['id_cms_category'], array_keys(self::properties()), self::COMPUTED);
        $errors = SomcpTool::unknownFields($arguments, $fields);
        $category = SomcpToolGetCmsCategory::lookup($arguments, $idShop, $errors);
        if ($category === null) {
            return SomcpTool::refused('changed', $errors);
        }

        $arguments = SomcpToolUpdateCmsSeo::withLanguage($arguments, $idShop, $errors);
        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return SomcpTool::refused('changed', $errors);
        }
        $idLang = (int) array_key_first($languages['filled']);
        $iso = $languages['filled'][$idLang];
        // Compared with the read in the language it was made in, the shop
        // default when none is given, as somcp_get_cms_category reads it.
        $readIn = SomcpTool::readLanguage(
            array_key_exists('language', $arguments) ? ['language' => $arguments['language']] : [],
            $idShop
        );
        if ($readIn !== null) {
            self::computed($arguments, SomcpToolGetCmsCategory::cmsCategory($category, $readIn[0], $readIn[1], $idShop), $errors);
        }

        $plan = self::plan($arguments, $category, $languages, $idShop, $errors);
        if ($errors !== []) {
            return SomcpTool::refused('changed', $errors);
        }

        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_cms_category' => (int) $category->id,
                'reason' => 'The page category already holds every value given. Nothing was written.',
                'category' => SomcpToolGetCmsCategory::cmsCategory($category, $idLang, $iso, $idShop),
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($category, $plan, $idShop, $idEmployee, $idLang, $iso): array {
            return self::apply($category, $plan, $idShop, $idEmployee, $idLang, $iso);
        });
    }

    /**
     * Refuses a worked-out field of the read that differs from what the
     * category holds: the shop sets it, a value given cannot be written.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $read the category as somcp_get_cms_category answers it
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function computed(array $arguments, array $read, array &$errors): void
    {
        foreach (self::COMPUTED as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== $read[$field]) {
                $errors[] = $field . ': not changed by this tool';
            }
        }
    }

    /**
     * What will be written, or every reason the call is refused, in $errors.
     *
     * A value equal to what the category holds is left out, so what remains
     * is exactly what changes. Public because the tool that creates a category checks
     * its fields here, against a category not saved yet, so both tools hold
     * one rule.
     *
     * @param array<string, mixed> $arguments
     * @param CMSCategory $category
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array{texts: array<string, array<int, string>>, set: array<string, mixed>, changes: array<int, array<string, mixed>>}
     */
    public static function plan(array $arguments, CMSCategory $category, array $languages, int $idShop, array &$errors): array
    {
        $plan = ['texts' => [], 'set' => [], 'changes' => []];
        $default = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

        $names = SomcpToolCreateProduct::texts($arguments, 'name', 'name', $languages, $errors);
        foreach ($names ?? [] as $idLang => $text) {
            $iso = $languages['filled'][$idLang];
            $text = trim($text);
            if ($text === '') {
                $errors[] = $idLang === $default
                    ? 'name: required in the default language ' . $iso
                    : 'name (' . $iso . '): a text, never empty';
                continue;
            }
            // The category's own rule, its size on this PrestaShop included.
            $check = $category->validateField('name', $text, $idLang);
            if ($check !== true) {
                $errors[] = 'name (' . $iso . '): ' . (is_string($check) ? strip_tags($check) : 'not accepted by PrestaShop');
                continue;
            }
            self::text($plan, $category, 'name', $idLang, $iso, $text);
        }

        $descriptions = SomcpToolCreateProduct::texts($arguments, 'description', 'description', $languages, $errors);
        foreach ($descriptions ?? [] as $idLang => $text) {
            $iso = $languages['filled'][$idLang];
            // The stored HTML given back is no change, even where a second
            // cleaning would write it a little differently.
            if ($text === SomcpToolUpdateCmsSeo::textOf($category->description, $idLang)) {
                continue;
            }
            $before = count($errors);
            $html = SomcpToolCreateProduct::html($text, 'description (' . $iso . ')', $errors);
            if (count($errors) > $before) {
                continue;
            }
            $check = $category->validateField('description', $html, $idLang);
            if ($check !== true) {
                $errors[] = 'description (' . $iso . '): ' . (is_string($check) ? strip_tags($check) : 'not accepted by PrestaShop');
                continue;
            }
            self::text($plan, $category, 'description', $idLang, $iso, $html);
        }

        foreach (self::SEO_TEXTS as $field) {
            if ($field === 'meta_keywords' && !isset(CMSCategory::$definition['fields']['meta_keywords'])) {
                // PrestaShop 9 dropped the column: a value is refused, since
                // storing nothing would tell the merchant something false.
                if (($arguments['meta_keywords'] ?? null) !== null) {
                    $errors[] = SomcpTool::notOnThisVersion('meta_keywords', 'meta keywords');
                }
                continue;
            }
            $texts = SomcpToolCreateProduct::texts($arguments, $field, $field, $languages, $errors);
            foreach ($texts ?? [] as $idLang => $text) {
                $iso = $languages['filled'][$idLang];
                $new = SomcpToolUpdateCmsSeo::checked($field, trim($text), $idLang, $category, $field . ' (' . $iso . ')', $errors);
                if ($new !== null) {
                    self::text($plan, $category, $field, $idLang, $iso, $new);
                }
            }
        }

        if (array_key_exists('active', $arguments)) {
            // A boolean only: "false" cast to bool is true, and would show a
            // category the merchant meant to hide.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } elseif ($arguments['active'] !== (bool) $category->active) {
                $plan['set']['active'] = $arguments['active'];
                $plan['changes'][] = ['field' => 'active', 'old' => (bool) $category->active, 'new' => $arguments['active']];
            }
        }

        if (array_key_exists('id_parent', $arguments)) {
            self::parent($arguments, $category, $idShop, $plan, $errors);
        }

        return $plan;
    }

    /**
     * The Parent category given, checked as the form checks a move.
     *
     * @param array<string, mixed> $arguments
     * @param CMSCategory $category
     * @param int $idShop
     * @param array{texts: array<string, array<int, string>>, set: array<string, mixed>, changes: array<int, array<string, mixed>>} $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function parent(array $arguments, CMSCategory $category, int $idShop, array &$plan, array &$errors): void
    {
        $id = (int) $category->id;
        $idParent = SomcpTool::id($arguments, 'id_parent');
        $old = (int) $category->id_parent;
        if ($id === 1) {
            // Home is the root of the tree: the form gives it no parent.
            if ($arguments['id_parent'] !== 0 && $arguments['id_parent'] !== $old) {
                $errors[] = 'id_parent: Home has no parent';
            }

            return;
        }
        if ($idParent === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_parent');

            return;
        }
        if (SomcpToolGetCmsCategory::category($idParent, $idShop) === null) {
            $errors[] = SomcpTool::notFound('id_parent', 'page category', $idParent, 'somcp_list_cms_pages');

            return;
        }
        if ($idParent === $old) {
            return;
        }
        // The core's own test of a move, the one the form runs: a new
        // category (id 0) can go under any category.
        if ($id > 0 && !CMSCategory::checkBeforeMove($id, $idParent)) {
            $errors[] = 'id_parent: a page category cannot be moved under itself or one of its own categories';

            return;
        }
        $plan['set']['id_parent'] = $idParent;
        $plan['changes'][] = ['field' => 'id_parent', 'old' => $old, 'new' => $idParent];
    }

    /**
     * One text added to the plan when it differs from what is stored.
     *
     * @param array{texts: array<string, array<int, string>>, set: array<string, mixed>, changes: array<int, array<string, mixed>>} $plan
     * @param CMSCategory $category
     * @param string $field
     * @param int $idLang
     * @param string $iso
     * @param string $new
     *
     * @return void
     */
    private static function text(array &$plan, CMSCategory $category, string $field, int $idLang, string $iso, string $new): void
    {
        // Read from the object's properties: meta_keywords is gone from
        // PrestaShop 9, unknown to static analysis there.
        $properties = get_object_vars($category);
        $old = SomcpToolUpdateCmsSeo::textOf($properties[$field] ?? '', $idLang);
        if ($new === $old) {
            return;
        }
        $plan['texts'][$field][$idLang] = $new;
        // The length rather than the HTML for a description, which can run long.
        $plan['changes'][] = $field === 'description'
            ? ['field' => $field, 'language' => $iso, 'old' => Tools::strlen($old), 'new' => Tools::strlen($new)]
            : ['field' => $field, 'language' => $iso, 'old' => $old, 'new' => $new];
    }

    /**
     * Writes the change and answers with what was stored.
     *
     * @param CMSCategory $category
     * @param array{texts: array<string, array<int, string>>, set: array<string, mixed>, changes: array<int, array<string, mixed>>} $plan
     * @param int $idShop
     * @param int $idEmployee
     * @param int $idLang the language the category is answered in
     * @param string $iso
     *
     * @return array<string, mixed>
     */
    private static function apply(CMSCategory $category, array $plan, int $idShop, int $idEmployee, int $idLang, string $iso): array
    {
        $id = (int) $category->id;
        foreach ($plan['texts'] as $field => $values) {
            $current = is_array($category->{$field}) ? $category->{$field} : [];
            $category->{$field} = array_replace($current, $values);
        }
        $left = null;
        if (array_key_exists('id_parent', $plan['set'])) {
            // Put last of its new parent, as CMSCategory::add() places a new
            // category: the old position could tie with a category there.
            $left = (int) $category->id_parent;
            $category->id_parent = (int) $plan['set']['id_parent'];
            $category->position = (int) CMSCategory::getLastPosition((int) $category->id_parent);
        }
        if (array_key_exists('active', $plan['set'])) {
            $category->active = (bool) $plan['set']['active'];
        }
        // The shop of the token only: cms_category_lang of the other shops keeps its texts.
        $category->id_shop_list = [$idShop];

        try {
            $done = $category->validateFields(false) === true && $category->validateFieldsLang(false) === true && $category->update();
            $failure = $done ? '' : 'PrestaShop refused to save it';
            if ($done && $left !== null) {
                CMSCategory::cleanPositions($left);
            }
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log(
                'cms_category_update_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'cms category ' . $id . ': ' . Tools::substr($failure, 0, 500),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The page category could not be changed: ' . $failure . '. Read it with somcp_get_cms_category before calling again.',
                true
            );
        }

        $fields = array_values(array_unique(array_column($plan['changes'], 'field')));
        SomcpLogger::log('cms_category_updated', SomcpLogger::OUTCOME_SUCCESS, 'cms category ' . $id . ': ' . implode(', ', $fields), '', $idEmployee);

        $stored = SomcpToolGetCmsCategory::category($id, $idShop);
        $read = $stored === null ? null : SomcpToolGetCmsCategory::cmsCategory($stored, $idLang, $iso, $idShop);
        $result = [
            'changed' => true,
            'id_cms_category' => $id,
            'changes' => $plan['changes'],
            'category' => $read,
        ];
        if (($plan['set']['active'] ?? null) === false && self::displayedPages($id, $idShop) > 0) {
            $result['warning'] = 'Its pages are no longer listed under this category on the storefront.';
        }

        return SomcpTool::json($result);
    }

    /**
     * The displayed pages of this shop filed in the category.
     *
     * @param int $id
     * @param int $idShop
     *
     * @return int
     */
    private static function displayedPages(int $id, int $idShop): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cms` c'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'cms_shop` cs ON cs.`id_cms` = c.`id_cms` AND cs.`id_shop` = ' . (int) $idShop
            . ' WHERE c.`active` = 1 AND c.`id_cms_category` = ' . (int) $id
        );
    }
}
