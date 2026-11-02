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
 * somcp_update_meta_page - the title, meta description and friendly URL of one
 * of the shop's own pages (contact, new products, search...), the rows of
 * Shop Parameters > Traffic & SEO.
 *
 * Every value is checked and compared with what the page holds before
 * anything is written, so a call that would change nothing writes nothing.
 * The write is one Meta::update() in the shop of the token: meta_lang is per
 * shop, and the other shops keep their own texts. The back office handler
 * (EditMetaHandler on 8 and 9) only checks the shape of a friendly URL; this
 * tool also refuses one another page of the shop already uses in that
 * language, since the dispatcher would then send both addresses to one page.
 * Meta keywords exist on PrestaShop 8 only: PrestaShop 9 dropped the field
 * and its column.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// employee switch, the search result lengths and the translated field reader
// below are theirs.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolAuditProductSeo.php';
require_once __DIR__ . '/SomcpToolUpdateCmsSeo.php';

class SomcpToolUpdateMetaPage
{
    /** @var array<int, string> The translatable fields this tool writes, named as somcp_list_meta_pages returns them. */
    private const TEXTS = ['title', 'description', 'keywords', 'url_rewrite'];

    /** The longest title accepted: search results cut well before. */
    private const TITLE_MAX = 70;

    /** The column's own size. */
    private const DESCRIPTION_MAX = 255;

    /** The column's own size, the smaller of the two: varchar(254) on PrestaShop 8, 255 on 9. */
    private const URL_REWRITE_MAX = 254;

    /** The column's own size. */
    private const KEYWORDS_MAX = 255;

    /** The home page, served at the root of the shop: it has no friendly URL. */
    private const HOME = 'index';

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_meta_page',
            Somcp::t('Change the SEO of a shop page'),
            'Changes the search engine fields of one of the shop\'s own pages - home, contact, new products, best '
                . 'sales, prices drop, search, sitemap, account and checkout pages, module pages - as the page form '
                . 'of Shop Parameters > Traffic & SEO does: title (the Page title, the title tag), description (the '
                . 'Meta description) and url_rewrite (the Rewritten URL). Texts are objects keyed by ISO code, '
                . '{"fr": "...", "en": "..."}; only the languages given change, the others keep their text. keywords '
                . '(the Meta keywords) exists on PrestaShop 8 only and is refused on 9. Read '
                . 'the page first with somcp_list_meta_pages, which gives id_meta, and show the merchant the new '
                . 'values in each language before calling. A new url_rewrite makes the old address of the page stop '
                . 'working: say so to the merchant before the call. The home page has no url_rewrite (its stored '
                . 'empty value is accepted unchanged), and the pages '
                . 'whose configurable is false (product, category and CMS templates) are refused: their texts come '
                . 'from each product, category or page. Every problem is reported at once and nothing is written '
                . 'until the whole call is valid. The answer gives each value changed with its previous and new '
                . 'value, seo_warnings, and the page as stored. A call that would change nothing writes nothing and '
                . 'says changed: false. Do not use it for a product, category, brand or CMS page (their own update '
                . 'tools, somcp_update_cms_seo for a CMS page). One page per call. Not to be retried: if the answer '
                . 'is lost, read the page again with somcp_list_meta_pages before calling again.',
            [
                'type' => 'object',
                'properties' => [
                    'id_meta' => [
                        'type' => 'integer',
                        'description' => 'The page to change, from somcp_list_meta_pages.',
                    ],
                    'title' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. The Page title, per ISO code: 70 '
                            . 'characters at most, without < > = { }; an empty text removes it. Over 60 characters, '
                            . 'search results cut it.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'description' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. The Meta description, per ISO code: '
                            . '255 characters at most, without < > = { }; search results show about 160.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'keywords' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. The Meta keywords, per ISO code, '
                            . 'separated by commas: 255 characters at most; an empty text removes them. PrestaShop 8 '
                            . 'only.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'url_rewrite' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. The Rewritten URL, per ISO code, '
                            . 'checked as typed: letters, digits and hyphens only, 254 characters at most, required in '
                            . 'the shop default language and may be empty in the others, refused when another page of '
                            . 'the shop already uses it. The old address stops working. The home page has none.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                ],
                'required' => ['id_meta'],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_WRITE],
            [self::class, 'handle'],
            // The pages are edited under Shop Parameters > Traffic & SEO,
            // whose controller is AdminMeta, and changing one takes the edit
            // right there.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminMeta')
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

        $meta = self::page($arguments['id_meta'] ?? null, $idShop);
        if ($meta === null) {
            return SomcpTool::text(
                'No page of this shop has this identifier. Nothing was changed. Find it with somcp_list_meta_pages.',
                true
            );
        }
        if (!(bool) $meta->configurable) {
            return SomcpTool::text(
                'The page ' . (string) $meta->page . ' takes its texts from each product, category or CMS page, '
                    . 'and Traffic & SEO does not list it. Nothing was changed. Change the product, category or page '
                    . 'itself with its own tool.',
                true
            );
        }

        $errors = [];
        $plan = self::plan($arguments, $meta, $idShop, $errors);
        if ($errors !== []) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
        }

        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_meta' => (int) $meta->id,
                'reason' => 'The page already holds every value given. Nothing was written.',
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($meta, $plan, $idShop, $idEmployee): array {
            return self::apply($meta, $plan, $idShop, $idEmployee);
        });
    }

    /**
     * The page with its texts in this shop, every language loaded, or null.
     *
     * @param mixed $id
     * @param int $idShop
     *
     * @return Meta|null
     */
    private static function page($id, int $idShop): ?Meta
    {
        $idMeta = is_int($id) ? $id : 0;
        if ($idMeta <= 0) {
            return null;
        }
        // meta has no shop association: every page exists in every shop, and
        // only its texts in meta_lang are per shop, loaded for this one.
        $meta = new Meta($idMeta, null, $idShop);

        return Validate::isLoadedObject($meta) ? $meta : null;
    }

    /**
     * What will be written, or every reason the call is refused.
     *
     * A value equal to what the page holds is left out, so what remains is
     * exactly what changes.
     *
     * @param array<string, mixed> $arguments
     * @param Meta $meta
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array{texts: array<string, array<int, string>>, changes: array<int, array<string, mixed>>, moved: array<int, string>, isos: array<int, string>}
     */
    private static function plan(array $arguments, Meta $meta, int $idShop, array &$errors): array
    {
        $plan = ['texts' => [], 'changes' => [], 'moved' => [], 'isos' => []];

        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if ($key !== 'id_meta' && !in_array($key, self::TEXTS, true)) {
                $errors[] = $key . ': not a field of this tool';
            }
        }

        if (array_key_exists('keywords', $arguments) && !self::hasKeywords()) {
            $errors[] = 'keywords: the Meta keywords field exists on PrestaShop 8 only, and this shop runs PrestaShop ' . _PS_VERSION_;
            unset($arguments['keywords']);
        }

        $active = [];
        foreach (Language::getLanguages(true, $idShop) as $row) {
            $active[Tools::strtolower((string) $row['iso_code'])] = (int) $row['id_lang'];
        }
        $idLangDefault = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

        foreach (self::TEXTS as $field) {
            if (!array_key_exists($field, $arguments)) {
                continue;
            }
            $value = $arguments[$field];
            if (!is_array($value) || $value === [] || array_is_list($value)) {
                $errors[] = $field . ': an object keyed by ISO code, for example {"' . (string) array_key_first($active) . '": "..."}';
                continue;
            }
            foreach ($value as $iso => $text) {
                $iso = Tools::strtolower(trim((string) $iso));
                $where = $field . ' (' . $iso . ')';
                if (!isset($active[$iso])) {
                    $errors[] = $where . ': not an active language of this shop, which has ' . implode(', ', array_keys($active));
                    continue;
                }
                if (!is_string($text)) {
                    $errors[] = $where . ': a text';
                    continue;
                }
                $idLang = $active[$iso];
                $previous = SomcpToolUpdateCmsSeo::textOf(self::stored($meta, $field), $idLang);
                if ($field === 'url_rewrite' && (string) $meta->page === self::HOME) {
                    // The dispatcher serves the home page at the root of the
                    // shop and never reads a rewritten URL for it. The value
                    // it holds is accepted unchanged, so a page read can be
                    // given back as it is.
                    if (trim($text) !== $previous) {
                        $errors[] = $where . ': the home page is served at the root of the shop and has no friendly URL';
                    }
                    continue;
                }
                $new = self::checked($field, trim($text), $idLang, $idLang === $idLangDefault, $meta, $idShop, $where, $errors);
                if ($new === null) {
                    continue;
                }
                if ($new === $previous) {
                    continue;
                }
                $plan['texts'][$field][$idLang] = $new;
                $plan['isos'][$idLang] = $iso;
                $plan['changes'][] = ['field' => $field, 'language' => $iso, 'previous' => $previous, 'new' => $new];
                if ($field === 'url_rewrite') {
                    $plan['moved'][$idLang] = $iso;
                }
            }
        }

        return $plan;
    }

    /**
     * One text as it will be stored, or null with the reason in $errors.
     *
     * @param string $field
     * @param string $text
     * @param int $idLang
     * @param bool $isDefaultLanguage
     * @param Meta $meta
     * @param int $idShop
     * @param string $where
     * @param array<int, string> $errors
     *
     * @return string|null
     */
    private static function checked(string $field, string $text, int $idLang, bool $isDefaultLanguage, Meta $meta, int $idShop, string $where, array &$errors): ?string
    {
        if ($field === 'url_rewrite') {
            // The form requires the Rewritten URL in the shop default
            // language only: the other languages fall back on it.
            if ($text === '') {
                if ($isDefaultLanguage) {
                    $errors[] = $where . ': required in the shop default language, the page would lose its address';

                    return null;
                }

                return $text;
            }
            // Checked as typed, as IsUrlRewriteValidator does in the form,
            // rather than rewritten: a URL changed without a word is not the
            // one the merchant approved. isLinkRewrite() follows
            // PS_ALLOW_ACCENTED_CHARS_URL like the form.
            if (!Validate::isLinkRewrite($text)) {
                $errors[] = $where . ': "Only letters and hyphens are allowed." - as the form says; no spaces, no other characters';

                return null;
            }
            if (Tools::strlen($text) > self::URL_REWRITE_MAX) {
                $errors[] = $where . ': ' . self::URL_REWRITE_MAX . ' characters at most';

                return null;
            }
            $taken = Db::getInstance()->getValue(
                'SELECT m.`page` FROM `' . _DB_PREFIX_ . 'meta_lang` ml'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'meta` m ON m.`id_meta` = ml.`id_meta`'
                . ' WHERE ml.`id_shop` = ' . (int) $idShop . ' AND ml.`id_lang` = ' . (int) $idLang
                . ' AND ml.`id_meta` != ' . (int) $meta->id
                . ' AND ml.`url_rewrite` = \'' . pSQL($text) . '\''
            );
            if ($taken !== false && $taken !== null) {
                $errors[] = $where . ': "' . $text . '" is already the friendly URL of the page ' . (string) $taken . ' in this language';

                return null;
            }
        } elseif ($field === 'title' && Tools::strlen($text) > self::TITLE_MAX) {
            $errors[] = $where . ': ' . self::TITLE_MAX . ' characters at most, ' . Tools::strlen($text) . ' given';

            return null;
        } elseif ($field === 'description' && Tools::strlen($text) > self::DESCRIPTION_MAX) {
            $errors[] = $where . ': ' . self::DESCRIPTION_MAX . ' characters at most, ' . Tools::strlen($text) . ' given';

            return null;
        } elseif ($field === 'keywords' && Tools::strlen($text) > self::KEYWORDS_MAX) {
            $errors[] = $where . ': ' . self::KEYWORDS_MAX . ' characters at most, ' . Tools::strlen($text) . ' given';

            return null;
        }

        // The page's own rule for the column, the one update() would apply.
        $check = $meta->validateField($field, $text, $idLang);
        if ($check !== true) {
            $errors[] = $where . ': ' . (is_string($check) ? strip_tags($check) : 'not accepted by PrestaShop');

            return null;
        }

        return $text;
    }

    /**
     * Writes the change and answers with what was stored.
     *
     * @param Meta $meta
     * @param array{texts: array<string, array<int, string>>, changes: array<int, array<string, mixed>>, moved: array<int, string>, isos: array<int, string>} $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Meta $meta, array $plan, int $idShop, int $idEmployee): array
    {
        $idMeta = (int) $meta->id;
        foreach ($plan['texts'] as $field => $values) {
            $current = is_array($meta->{$field}) ? $meta->{$field} : [];
            $meta->{$field} = array_replace($current, $values);
        }
        // The shop of the token only: meta_lang of the other shops keeps its texts.
        $meta->id_shop_list = [$idShop];

        try {
            $done = $meta->validateFields(false) === true && $meta->validateFieldsLang(false) === true && $meta->update();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log(
                'meta_page_update_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'meta ' . $idMeta . ': ' . Tools::substr($failure, 0, 500),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The page could not be changed: ' . $failure . '. Read it again with somcp_list_meta_pages, tell the '
                    . 'merchant what it holds now, and do not call again before.',
                true
            );
        }

        $stored = new Meta($idMeta, null, $idShop);
        SomcpLogger::log(
            'meta_page_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'meta ' . $idMeta . ', ' . count($plan['changes']) . ' change(s)',
            '',
            $idEmployee
        );

        $warnings = self::warnings($stored, $plan);

        return SomcpTool::json([
            'changed' => true,
            'id_meta' => $idMeta,
            'changes' => $plan['changes'],
            'seo_warnings' => $warnings === [] ? new stdClass() : $warnings,
            // Read back rather than echoed from the arguments: what the
            // assistant shows the merchant is what the shop stored.
            'page' => self::describe($stored, $plan['isos']),
        ]);
    }

    /**
     * What search results will make of the languages changed.
     *
     * @param Meta $stored
     * @param array{texts: array<string, array<int, string>>, changes: array<int, array<string, mixed>>, moved: array<int, string>, isos: array<int, string>} $plan
     *
     * @return array<string, array<int, string>>
     */
    private static function warnings(Meta $stored, array $plan): array
    {
        $warnings = [];
        foreach ($plan['isos'] as $idLang => $iso) {
            $title = SomcpToolUpdateCmsSeo::textOf($stored->title, $idLang);
            if (Tools::strlen($title) > SomcpToolAuditProductSeo::TITLE_MAX) {
                $warnings[$iso][] = 'title is over ' . SomcpToolAuditProductSeo::TITLE_MAX
                    . ' characters: search results cut it';
            }
            $description = SomcpToolUpdateCmsSeo::textOf($stored->description, $idLang);
            if (Tools::strlen($description) > SomcpToolAuditProductSeo::DESCRIPTION_MAX) {
                $warnings[$iso][] = 'description is over ' . SomcpToolAuditProductSeo::DESCRIPTION_MAX
                    . ' characters: search results cut it';
            }
            if (isset($plan['moved'][$idLang])) {
                $warnings[$iso][] = 'url_rewrite changed: the old address of the page no longer works';
            }
        }

        return $warnings;
    }

    /**
     * The page's search engine fields, in the languages changed, under the
     * names somcp_list_meta_pages uses.
     *
     * @param Meta $stored
     * @param array<int, string> $isos
     *
     * @return array<string, mixed>
     */
    private static function describe(Meta $stored, array $isos): array
    {
        $texts = [];
        foreach ($isos as $idLang => $iso) {
            $texts[$iso] = [
                'title' => SomcpToolUpdateCmsSeo::textOf($stored->title, $idLang),
                'description' => SomcpToolUpdateCmsSeo::textOf($stored->description, $idLang),
                'keywords' => self::hasKeywords() ? SomcpToolUpdateCmsSeo::textOf(self::stored($stored, 'keywords'), $idLang) : null,
                'url_rewrite' => SomcpToolUpdateCmsSeo::textOf($stored->url_rewrite, $idLang),
            ];
        }

        return [
            'id_meta' => (int) $stored->id,
            'page' => (string) $stored->page,
            'texts' => $texts === [] ? new stdClass() : $texts,
        ];
    }

    /**
     * One field of the page as the object holds it.
     *
     * Read by a name only known at run time: keywords is declared on
     * PrestaShop 8 and gone from the class on 9, so a literal property
     * access would not type-check against both versions.
     *
     * @param Meta $meta
     * @param string $field
     *
     * @return mixed
     */
    private static function stored(Meta $meta, string $field)
    {
        return $meta->{$field} ?? null;
    }

    /**
     * Whether this PrestaShop still has the Meta keywords field.
     *
     * Read from the object's definition rather than the version number: it
     * is what validateFieldsLang() and update() write.
     *
     * @return bool
     */
    public static function hasKeywords(): bool
    {
        return isset(Meta::$definition['fields']['keywords']);
    }
}
