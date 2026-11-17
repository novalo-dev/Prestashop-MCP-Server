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
 * somcp_update_cms_seo - the search engine fields of one CMS page: its meta
 * title, meta description, friendly URL and indexing switch.
 *
 * The names follow the CMS page form of Design > Pages rather than the
 * columns. The form's "Title" is the column meta_title, the page's h1 and its
 * title tag by default; its "Meta title" is the column head_seo_title, which
 * overrides the title tag when filled (EditCmsPageHandler on 8 and 9). This
 * tool writes the second one under the name meta_title, the field the
 * merchant fills to change what search results show, and never the page
 * title, which belongs with the content.
 *
 * Every value is checked and compared with what the page holds before
 * anything is written, so a call that would change nothing writes nothing and
 * no hook fires. The write is one CMS::update() in the shop of the token, as
 * the back office handler does after validateFields(), so the CMS hooks fire
 * and the other shops keep their own texts.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// limits and the employee switch below are theirs.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolAuditProductSeo.php';
// The page read the way somcp_get_cms_page answers, to compare what is given back.
require_once __DIR__ . '/SomcpToolGetCmsPage.php';

class SomcpToolUpdateCmsSeo
{
    /** @var array<int, string> The translatable fields this tool writes, by the name it takes them under. */
    private const TEXTS = ['meta_title', 'meta_description', 'link_rewrite', 'meta_keywords'];

    /** @var array<string, string> The column behind each name. */
    private const COLUMNS = [
        'meta_title' => 'head_seo_title',
        'meta_description' => 'meta_description',
        'link_rewrite' => 'link_rewrite',
        'meta_keywords' => 'meta_keywords',
    ];

    /**
     * @var array<string, string> what somcp_get_cms_page returns that is not written here, with where it is
     *                            changed: accepted when given back unchanged, so a page read there can be
     *                            given back as is
     */
    private const KEPT_AS_READ = [
        'title' => 'not changed by this tool - the merchant changes it in Design > Pages > Edit',
        'content' => 'not changed by this tool - the merchant changes it in Design > Pages > Edit',
        'id_cms_category' => 'not changed by this tool - the merchant changes it in Design > Pages > Edit',
        'active' => 'not changed by this tool - the merchant changes it in Design > Pages > Edit',
        'position' => 'not changed by this tool - the merchant changes it in Design > Pages > Edit',
        'seo_title' => 'worked out by the shop from the Meta title and the Title',
        'url' => 'worked out by the shop from the Friendly URL',
    ];

    /** The column's own size. */
    private const LINK_REWRITE_MAX = 128;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_cms_seo',
            Somcp::t('Change the SEO of a CMS page'),
            'Changes the Meta title, meta description, Friendly URL and Indexation of one CMS page, like '
                . 'its form in Design > Pages, in the languages filled. Not the Title or the content; what '
                . 'somcp_get_cms_page returns can be given back. '
                . 'Any page, from somcp_list_cms_pages; somcp_audit_cms_seo finds those to fix. Read the page '
                . 'with somcp_get_cms_page first and show the merchant the new values; warn that a '
                . 'new friendly URL breaks the old address of a displayed page. Nothing is written until the '
                . 'whole call is valid. One page per call. Not to be retried on a lost answer: check with '
                . 'somcp_get_cms_page.',
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
                    'meta_title' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. The Meta title of the page form, per '
                            . 'ISO code: 255 at most; over 70 cut in search. Without < > = { }; an empty text removes it.',
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
                        'description' => 'Assistant, approved by the merchant. The friendly URL, per ISO code: '
                            . 'lower case words joined by hyphens, 128 characters at most, never empty.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'meta_keywords' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. Meta keywords of the page form, per '
                            . 'ISO code, comma separated; PrestaShop 8 only.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'indexation' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Indexation by search engines: whether the page may appear in '
                            . 'search results. Legal pages are often kept out on purpose: ask before changing it.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'As somcp_get_cms_page returns it: the language its plain texts are in, '
                            . 'filled alone when languages is left out.',
                    ],
                ] + self::keptProperties(),
                'required' => ['id_cms'],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_WRITE],
            [self::class, 'handle'],
            // Pages are edited under Design > Pages, whose controller is
            // AdminCmsContent, and changing one takes the edit right there.
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

        // An unknown page is reported with the misspelt fields of the same
        // call, so one answer lists every mistake.
        $idCms = SomcpTool::id($arguments, 'id_cms');
        $cms = self::page($idCms, $idShop);
        if ($cms === null) {
            $errors = SomcpTool::unknownFields($arguments, self::fields());
            $errors[] = $idCms > 0
                ? SomcpTool::notFound('id_cms', 'CMS page', $idCms, 'somcp_list_cms_pages')
                : SomcpTool::notAnIdentifier('id_cms');

            return SomcpTool::refused('changed', $errors);
        }

        $errors = [];
        $plan = self::plan($arguments, $cms, $idShop, $errors);
        if ($errors !== []) {
            return SomcpTool::refused('changed', $errors);
        }

        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_cms' => (int) $cms->id,
                'reason' => 'The page already holds every value given. Nothing was written.',
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($cms, $plan, $idShop, $idEmployee): array {
            return self::apply($cms, $plan, $idShop, $idEmployee);
        });
    }

    /**
     * What somcp_get_cms_page returns and is not written here, offered so a
     * page read there can be given back whole.
     *
     * @return array<string, array<string, string>>
     */
    private static function keptProperties(): array
    {
        $properties = [];
        foreach (self::KEPT_AS_READ as $field => $where) {
            $properties[$field] = ['description' => 'As somcp_get_cms_page returns it, accepted unchanged only: ' . $where . '.'];
        }

        return $properties;
    }

    /**
     * Every field of the inputSchema, refused when misspelt rather than ignored.
     *
     * @return array<int, string>
     */
    private static function fields(): array
    {
        return array_merge(['id_cms', 'languages', 'indexation', 'language'], self::TEXTS, array_keys(self::KEPT_AS_READ));
    }

    /**
     * The language given back with a page read by somcp_get_cms_page, as the
     * languages to fill, or the reason it does not fit them.
     *
     * The plain texts of that answer are in this one language, so it is what
     * they are filled in when no languages are given.
     *
     * @param array<string, mixed> $arguments
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>
     */
    private static function withLanguage(array $arguments, array &$errors): array
    {
        if (!array_key_exists('language', $arguments)) {
            return $arguments;
        }
        $language = $arguments['language'];
        if (!is_string($language) || trim($language) === '') {
            $errors[] = 'language: an ISO code, as somcp_get_cms_page returns it';

            return $arguments;
        }
        $language = Tools::strtolower(trim($language));
        if (!array_key_exists('languages', $arguments)) {
            $arguments['languages'] = [$language];
        } elseif (is_array($arguments['languages'])) {
            $isos = array_map(static function ($iso): string {
                return is_string($iso) ? Tools::strtolower(trim($iso)) : '';
            }, $arguments['languages']);
            if (!in_array($language, $isos, true)) {
                $errors[] = 'language: not one of languages';
            }
        }

        return $arguments;
    }

    /**
     * Refuses each field of somcp_get_cms_page this tool does not write when
     * it differs from what the page holds, with where it is changed.
     *
     * Compared with the page read exactly as somcp_get_cms_page reads it, in
     * the language it was read in, rather than a second reading of it here.
     *
     * @param array<string, mixed> $arguments
     * @param CMS $cms
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function kept(array $arguments, CMS $cms, int $idShop, array &$errors): void
    {
        if (array_intersect(array_keys(self::KEPT_AS_READ), array_keys($arguments)) === []) {
            return;
        }
        // The language of the answer given back; the shop default otherwise,
        // as somcp_get_cms_page reads it when no language is asked.
        $language = SomcpTool::readLanguage(
            is_string($arguments['language'] ?? null) ? ['language' => Tools::strtolower(trim($arguments['language']))] : [],
            $idShop
        );
        if ($language === null) {
            $errors[] = 'language: not an active language of this shop';

            return;
        }
        [$idLang, $iso] = $language;
        $read = SomcpToolGetCmsPage::cmsPage($cms, $idLang, $iso, $idShop);
        foreach (self::KEPT_AS_READ as $field => $where) {
            // Compared as JSON, the shape both tools speak.
            if (array_key_exists($field, $arguments) && json_encode($arguments[$field]) !== json_encode($read[$field])) {
                $errors[] = $field . ': ' . $where;
            }
        }
    }

    /**
     * The page as this shop holds it, every language loaded, or null.
     * Public so somcp_get_cms_page finds pages exactly as this tool does.
     *
     * @param mixed $id
     * @param int $idShop
     *
     * @return CMS|null
     */
    public static function page($id, int $idShop): ?CMS
    {
        $idCms = is_int($id) ? $id : 0;
        if ($idCms <= 0) {
            return null;
        }
        // The constructor would load a page of another shop too: one that is
        // not associated with this shop is answered like one that does not exist.
        $inShop = (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'cms_shop` WHERE `id_cms` = ' . (int) $idCms
            . ' AND `id_shop` = ' . (int) $idShop
        );
        if (!$inShop) {
            return null;
        }
        $cms = new CMS($idCms, null, $idShop);

        return Validate::isLoadedObject($cms) ? $cms : null;
    }

    /**
     * What will be written, or every reason the call is refused.
     *
     * A value equal to what the page holds is left out, so what remains is
     * exactly what changes.
     *
     * @param array<string, mixed> $arguments
     * @param CMS $cms
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array{texts: array<string, array<int, string>>, indexation: bool|null, changes: array<int, array<string, mixed>>, moved: array<int, string>, isos: array<int, string>}
     */
    private static function plan(array $arguments, CMS $cms, int $idShop, array &$errors): array
    {
        $plan = ['texts' => [], 'indexation' => null, 'changes' => [], 'moved' => [], 'isos' => []];

        $errors = array_merge($errors, SomcpTool::unknownFields($arguments, self::fields()));

        // The rule of the catalogue write tools (#149): the languages being
        // filled, all active ones by default, and each text keyed by them, so
        // a text read from one tool is given back to this one the same way.
        $arguments = self::withLanguage($arguments, $errors);
        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return $plan;
        }

        self::kept($arguments, $cms, $idShop, $errors);

        foreach (self::TEXTS as $field) {
            if ($field === 'meta_keywords' && !isset(CMS::$definition['fields']['meta_keywords'])) {
                // PrestaShop 9 dropped the column. The null somcp_get_cms_page
                // returns there is skipped, so its answer can be given back; a
                // value is refused, since storing nothing would tell the
                // merchant something false.
                if (($arguments['meta_keywords'] ?? null) !== null) {
                    $errors[] = SomcpTool::notOnThisVersion('meta_keywords', 'meta keywords');
                }
                continue;
            }
            $texts = SomcpToolCreateProduct::texts($arguments, $field, $field, $languages, $errors);
            foreach ($texts ?? [] as $idLang => $text) {
                $iso = $languages['filled'][$idLang];
                $where = $field . ' (' . $iso . ')';
                $new = self::checked($field, trim($text), $idLang, $cms, $where, $errors);
                if ($new === null) {
                    continue;
                }
                $column = self::COLUMNS[$field];
                // Read from the object's properties: meta_keywords is gone
                // from PrestaShop 9, unknown to static analysis there.
                $properties = get_object_vars($cms);
                $previous = self::textOf($properties[$column] ?? '', $idLang);
                if ($new === $previous) {
                    continue;
                }
                $plan['texts'][$column][$idLang] = $new;
                $plan['isos'][$idLang] = $iso;
                $plan['changes'][] = ['field' => $where, 'old' => $previous, 'new' => $new];
                if ($field === 'link_rewrite') {
                    $plan['moved'][$idLang] = $iso;
                }
            }
        }

        if (array_key_exists('indexation', $arguments)) {
            // A boolean only: "false" cast to bool is true, and would open to
            // search engines a page the merchant meant to keep out.
            if (!is_bool($arguments['indexation'])) {
                $errors[] = 'indexation: true or false';
            } elseif ($arguments['indexation'] !== (bool) $cms->indexation) {
                $plan['indexation'] = $arguments['indexation'];
                $plan['changes'][] = [
                    'field' => 'indexation',
                    'old' => (bool) $cms->indexation,
                    'new' => $arguments['indexation'],
                ];
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
     * @param CMS $cms
     * @param string $where
     * @param array<int, string> $errors
     *
     * @return string|null
     */
    private static function checked(string $field, string $text, int $idLang, CMS $cms, string $where, array &$errors): ?string
    {
        if ($field === 'link_rewrite') {
            // As the back office makes a friendly URL from what is typed.
            $text = (string) Tools::str2url($text);
            if ($text === '' || !Validate::isLinkRewrite($text)) {
                $errors[] = $where . ': no valid friendly URL can be made from it; give words, which become lower case joined by hyphens';

                return null;
            }
            if (Tools::strlen($text) > self::LINK_REWRITE_MAX) {
                $errors[] = $where . ': ' . self::LINK_REWRITE_MAX . ' characters at most';

                return null;
            }
        } elseif ($field === 'meta_title' || $field === 'meta_description') {
            // Refused only past the column the back office stores, through the
            // rule every SEO write shares; the advice it collects is dropped here
            // because warnings() advises on the text actually stored, the page
            // title included when no meta title is set.
            $max = SomcpTool::columnSize($cms, self::COLUMNS[$field], $field === 'meta_title' ? 255 : 512);
            $recommended = $field === 'meta_title' ? SomcpTool::META_TITLE_RECOMMENDED : SomcpTool::metaDescriptionRecommended();
            $advice = [];
            if (!SomcpTool::seoTextLength($where, Tools::strlen($text), $max, $recommended, $errors, $advice)) {
                return null;
            }
        }

        // The page's own rule for the column, the one update() would apply.
        $check = $cms->validateField(self::COLUMNS[$field], $text, $idLang);
        if ($check !== true) {
            $errors[] = $where . ': ' . (is_string($check) ? strip_tags($check) : 'not accepted by PrestaShop');

            return null;
        }

        return $text;
    }

    /**
     * Writes the change and answers with what was stored.
     *
     * @param CMS $cms
     * @param array{texts: array<string, array<int, string>>, indexation: bool|null, changes: array<int, array<string, mixed>>, moved: array<int, string>, isos: array<int, string>} $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(CMS $cms, array $plan, int $idShop, int $idEmployee): array
    {
        $idCms = (int) $cms->id;
        foreach ($plan['texts'] as $column => $values) {
            $current = is_array($cms->{$column}) ? $cms->{$column} : [];
            $cms->{$column} = array_replace($current, $values);
        }
        if ($plan['indexation'] !== null) {
            $cms->indexation = $plan['indexation'];
        }
        // The shop of the token only: cms_lang of the other shops keeps its texts.
        $cms->id_shop_list = [$idShop];

        try {
            $done = $cms->validateFields(false) === true && $cms->validateFieldsLang(false) === true && $cms->update();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log(
                'cms_seo_update_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'cms ' . $idCms . ': ' . Tools::substr($failure, 0, 500),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The page could not be changed: ' . $failure . '. Read it again with somcp_get_cms_page, tell the '
                    . 'merchant what it holds now, and do not call again before.',
                true
            );
        }

        $stored = new CMS($idCms, null, $idShop);
        SomcpLogger::log(
            'cms_seo_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'cms ' . $idCms . ', ' . count($plan['changes']) . ' change(s)',
            '',
            $idEmployee
        );

        $warnings = self::warnings($stored, $plan);

        return SomcpTool::json([
            'changed' => true,
            'id_cms' => $idCms,
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
     * @param CMS $stored
     * @param array{texts: array<string, array<int, string>>, indexation: bool|null, changes: array<int, array<string, mixed>>, moved: array<int, string>, isos: array<int, string>} $plan
     *
     * @return array<string, array<int, string>>
     */
    private static function warnings(CMS $stored, array $plan): array
    {
        $warnings = [];
        foreach ($plan['isos'] as $idLang => $iso) {
            // The title search engines read, as Meta::getCmsMetas() builds it.
            // It falls back on the page title when no meta title is set, and
            // is advised in the sentence every tool gives, under meta_title.
            $seoTitle = self::seoTitle($stored, $idLang);
            $description = self::textOf($stored->meta_description, $idLang);
            foreach ([
                SomcpTool::metaLengthAdvice('meta_title', Tools::strlen($seoTitle), SomcpTool::META_TITLE_RECOMMENDED),
                SomcpTool::metaLengthAdvice('meta_description', Tools::strlen($description), SomcpTool::metaDescriptionRecommended()),
            ] as $advice) {
                if ($advice !== null) {
                    $warnings[$iso][] = $advice;
                }
            }
            if (isset($plan['moved'][$idLang]) && (bool) $stored->active) {
                $warnings[$iso][] = 'link_rewrite changed on a displayed page: the old address of the page no longer works';
            }
        }

        return $warnings;
    }

    /**
     * The page's search engine fields, in the languages changed.
     *
     * @param CMS $stored
     * @param array<int, string> $isos
     *
     * @return array<string, mixed>
     */
    private static function describe(CMS $stored, array $isos): array
    {
        $texts = [];
        $properties = get_object_vars($stored);
        foreach ($isos as $idLang => $iso) {
            $texts[$iso] = [
                'title' => self::textOf($stored->meta_title, $idLang),
                'meta_title' => self::textOf($stored->head_seo_title, $idLang),
                'seo_title' => self::seoTitle($stored, $idLang),
                'meta_description' => self::textOf($stored->meta_description, $idLang),
                // null on PrestaShop 9, which has no meta keywords, as somcp_get_cms_page says it.
                'meta_keywords' => isset(CMS::$definition['fields']['meta_keywords'])
                    ? self::textOf($properties['meta_keywords'] ?? '', $idLang)
                    : null,
                'link_rewrite' => self::textOf($stored->link_rewrite, $idLang),
            ];
        }

        return [
            'id_cms' => (int) $stored->id,
            'active' => (bool) $stored->active,
            'indexation' => (bool) $stored->indexation,
            'texts' => $texts === [] ? new stdClass() : $texts,
        ];
    }

    /**
     * The title tag of the page: the meta title when filled, the page title otherwise.
     * Public so somcp_get_cms_page reports the same seo_title this tool answers with.
     *
     * @param CMS $cms
     * @param int $idLang
     *
     * @return string
     */
    public static function seoTitle(CMS $cms, int $idLang): string
    {
        $meta = trim(self::textOf($cms->head_seo_title, $idLang));

        return $meta !== '' ? $meta : trim(self::textOf($cms->meta_title, $idLang));
    }

    /**
     * One language of a translated field, which the core loads as an array
     * per language or, for a single one, as a string.
     *
     * @param mixed $value
     * @param int $idLang
     *
     * @return string
     */
    public static function textOf($value, int $idLang): string
    {
        if (is_array($value)) {
            return (string) ($value[$idLang] ?? '');
        }

        return is_string($value) ? $value : '';
    }
}
