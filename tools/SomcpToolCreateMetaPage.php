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
 * somcp_create_meta_page - Add new page then Save in Shop Parameters >
 * Traffic & SEO > SEO & URLs.
 *
 * The back office (AddMetaHandler on 8 and 9) takes a page name from
 * Meta::getPages(true), the core and module front controllers that have no
 * row yet, requires the Rewritten URL in the default language but for the
 * home page, gives an empty language the text of the default one, adds the
 * Meta and runs actionAdminMetaSave. The texts are checked by the rules of
 * somcp_update_meta_page, called rather than copied, so a page is created
 * under the rules it is later changed with.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// checks of the texts, the page address, the languages and the employee
// switch are theirs.
require_once __DIR__ . '/SomcpToolUpdateMetaPage.php';
require_once __DIR__ . '/SomcpToolListMetaPages.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolUpdateCmsSeo.php';

class SomcpToolCreateMetaPage
{
    /** The home page, served at the root of the shop: it has no Rewritten URL. */
    private const HOME = 'index';

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_meta_page',
            Somcp::t('Add a shop page to SEO & URLs'),
            'Adds a page to Shop Parameters > Traffic & SEO > SEO & URLs, as Add new page: a page name taken '
                . 'from addable_pages of somcp_list_meta_pages, never guessed, with its Page title, Meta '
                . 'description and Rewritten URL per ISO code; the Rewritten URL is required in the default '
                . 'language. Same rules as somcp_update_meta_page. One page per call. Changes the shop: ask '
                . 'first. A page already there is refused: change it with somcp_update_meta_page.',
            [
                'type' => 'object',
                'properties' => [
                    'page' => [
                        'type' => 'string',
                        'description' => 'The Page name, one of addable_pages of somcp_list_meta_pages, for example '
                            . 'order-return or module-productcomments-listcomments.',
                    ],
                    'languages' => [
                        'type' => 'array',
                        'description' => 'ISO codes of the languages to fill, among the active languages of the shop. '
                            . 'All of them by default. Every text given must then be given in each of them.',
                        'items' => ['type' => 'string'],
                    ],
                    'meta_title' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. The Page title, per ISO code: 128 '
                            . 'at most; over 70 cut in search. Without < > = { }.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'meta_description' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. The Meta description, per ISO code: '
                            . '255 at most; over 160 cut in search. Without < > = { }.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'meta_keywords' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. The Meta keywords, per ISO code, '
                            . 'separated by commas: 255 characters at most. PrestaShop 8 only.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'link_rewrite' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. The Rewritten URL, per ISO code: '
                            . 'letters, digits and hyphens, required in the default language, unique; an empty '
                            . 'language takes the default one.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                ],
                'required' => ['page'],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_WRITE],
            [self::class, 'handle'],
            // Add new page of Traffic & SEO, whose controller is AdminMeta,
            // takes the add right there.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminMeta')
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

        $errors = [];
        $page = self::pageProblem($arguments['page'] ?? null, array_key_exists('page', $arguments), $errors);

        // A Meta not saved yet, its page set, so the checks of
        // somcp_update_meta_page know the home page and compare every text
        // with an empty one: what remains in the plan is what is written.
        $meta = new Meta();
        $meta->page = $page;
        $plan = SomcpToolUpdateMetaPage::plan($arguments, $meta, $idShop, $errors, ['page']);

        // The languages the plan filled, read again for the fallback; their
        // mistakes are already in $errors.
        $unused = [];
        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $unused);
        if ($languages !== null && $page !== self::HOME) {
            $fallback = (int) $languages['fallback'];
            $iso = (string) $languages['filled'][$fallback];
            $named = false;
            foreach ($errors as $error) {
                $named = $named || strpos($error, 'link_rewrite') === 0;
            }
            if (!$named && trim((string) ($plan['texts']['url_rewrite'][$fallback] ?? '')) === '') {
                $errors[] = 'link_rewrite (' . $iso . '): required in the shop default language, the page needs an '
                    . 'address';
            }
        }

        if ($errors !== [] || $languages === null) {
            return SomcpTool::refused('created', $errors);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($meta, $plan, $languages, $idShop, $idEmployee): array {
            return self::create($meta, $plan, $languages, $idShop, $idEmployee);
        });
    }

    /**
     * The page name to create, or '' with the reason in $errors.
     *
     * @param mixed $value
     * @param bool $given
     * @param array<int, string> $errors
     *
     * @return string
     */
    private static function pageProblem($value, bool $given, array &$errors): string
    {
        if (!$given || $value === null || $value === '') {
            $errors[] = 'page: required - take one of addable_pages of somcp_list_meta_pages';

            return '';
        }
        $page = is_string($value) ? trim($value) : '';
        if ($page !== '') {
            $idMeta = (int) Db::getInstance()->getValue(
                'SELECT `id_meta` FROM `' . _DB_PREFIX_ . 'meta` WHERE `page` = \'' . pSQL($page) . '\''
            );
            if ($idMeta > 0) {
                $errors[] = 'page: ' . $page . ' already has its page - change it with somcp_update_meta_page (id_meta '
                    . $idMeta . ')';

                return '';
            }
        }
        // The list the Page name field of the form offers: the core and
        // module front controllers without a row.
        if ($page === '' || !in_array($page, array_map('strval', Meta::getPages(true)), true)) {
            $errors[] = 'page: not a page that can be added - take one of addable_pages of somcp_list_meta_pages';

            return '';
        }

        return $page;
    }

    /**
     * One translated field of the page, by a name only known at run time:
     * keywords is declared on PrestaShop 8 and gone from the class on 9, so
     * a literal property access would not type-check against both versions.
     *
     * @param Meta $meta
     * @param string $field
     * @param array<int, string> $values
     *
     * @return void
     */
    private static function fill(Meta $meta, string $field, array $values): void
    {
        $meta->{$field} = $values;
    }

    /**
     * Adds the page as AddMetaHandler does and answers with what was stored.
     *
     * @param Meta $meta
     * @param array{texts: array<string, array<int, string>>, changes: array<int, array<string, mixed>>, moved: array<int, string>, isos: array<int, string>} $plan
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function create(Meta $meta, array $plan, array $languages, int $idShop, int $idEmployee): array
    {
        $page = (string) $meta->page;
        $fallback = (int) $languages['fallback'];
        foreach (['title', 'description', 'keywords', 'url_rewrite'] as $field) {
            if ($field === 'keywords' && !SomcpToolUpdateMetaPage::hasKeywords()) {
                continue;
            }
            $values = [];
            foreach ($languages['all'] as $idLang) {
                $values[$idLang] = (string) ($plan['texts'][$field][$idLang] ?? '');
            }
            if ($field === 'url_rewrite') {
                // A language left empty takes the address of the default
                // one, as the handler of the form does.
                foreach ($values as $idLang => $value) {
                    if ($value === '') {
                        $values[$idLang] = (string) ($plan['texts']['url_rewrite'][$fallback] ?? '');
                    }
                }
            }
            self::fill($meta, $field, $values);
        }
        // An unsigned integer in the definition of Meta: the screen adds
        // every page configurable.
        $meta->configurable = 1;
        // meta_lang is kept by shop: the texts go to the shop of the token.
        $meta->id_shop_list = [$idShop];

        try {
            $done = $meta->validateFields(false) === true && $meta->validateFieldsLang(false) === true && $meta->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done || (int) $meta->id <= 0) {
            SomcpLogger::log('meta_page_create_failed', SomcpLogger::OUTCOME_FAILURE, 'page ' . $page . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text('The page could not be created: ' . $failure . '.', true);
        }
        $idMeta = (int) $meta->id;

        // As the screen does: a module that keeps its own list of addresses,
        // a sitemap, hears of the new page.
        Hook::exec('actionAdminMetaSave');

        // The identifier and the controller name only: the texts are the
        // merchant's.
        SomcpLogger::log('meta_page_created', SomcpLogger::OUTCOME_SUCCESS, 'meta ' . $idMeta . ' (' . $page . ')', '', $idEmployee);

        // Read back rather than echoed from the arguments: what the
        // assistant shows the merchant is what the shop stored.
        $stored = new Meta($idMeta, null, $idShop);
        $idLangDefault = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $link = Somcp::legacyContext()->link ?? new Link();
        $keywords = SomcpToolUpdateMetaPage::hasKeywords();
        $result = [
            'created' => true,
            'id_meta' => $idMeta,
            'page' => $page,
            'meta_title' => SomcpToolUpdateCmsSeo::textOf($stored->title, $idLangDefault),
            'meta_description' => SomcpToolUpdateCmsSeo::textOf($stored->description, $idLangDefault),
            'meta_keywords' => $keywords ? SomcpToolUpdateCmsSeo::textOf($stored->{'keywords'} ?? null, $idLangDefault) : null,
            'link_rewrite' => SomcpToolUpdateCmsSeo::textOf($stored->url_rewrite, $idLangDefault),
            'url' => SomcpToolListMetaPages::url($link, $page, $idLangDefault, $idShop),
            'configurable' => true,
            'texts' => SomcpToolUpdateMetaPage::describe($stored, $languages['filled'])['texts'],
        ];
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $result['warning'] = 'The page exists for every shop; its texts were written for this shop only.';
        }

        return SomcpTool::json($result);
    }
}
