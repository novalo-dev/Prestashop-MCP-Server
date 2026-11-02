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

class SomcpToolUpdateCmsSeo
{
    /** @var array<int, string> The translatable fields this tool writes, by the name it takes them under. */
    private const TEXTS = ['meta_title', 'meta_description', 'link_rewrite'];

    /** @var array<string, string> The column behind each name. */
    private const COLUMNS = [
        'meta_title' => 'head_seo_title',
        'meta_description' => 'meta_description',
        'link_rewrite' => 'link_rewrite',
    ];

    /** The longest meta title accepted: search results cut well before. */
    private const META_TITLE_MAX = 70;

    /** The column's own size. */
    private const META_DESCRIPTION_MAX = 512;

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
            'Changes the search engine fields of one CMS page of this shop (delivery, legal notice, about us...), '
                . 'as the CMS page form of Design > Pages does: meta_title (the form\'s Meta title, which replaces '
                . 'the page title in search results when filled; an empty text removes it and the page title is '
                . 'shown again), meta_description, link_rewrite (the friendly URL) and indexation. Texts are '
                . 'objects keyed by ISO code, {"fr": "...", "en": "..."}; only the languages given change, the '
                . 'others keep their text. Read the page first with somcp_audit_cms_seo, whose meta_title is the '
                . 'page title, and show the merchant the new values in each language before calling. A new '
                . 'link_rewrite on a displayed page makes its old address stop working: say so to the merchant '
                . 'before the call. Every problem is reported at once and nothing is written until the whole call '
                . 'is valid. The answer gives each value changed with its previous and new value, seo_warnings, '
                . 'and the page as stored. A call that would change nothing writes nothing and says changed: '
                . 'false. Do not use it for the page title or the content of the page, nor for a product, '
                . 'category or brand (their own update tools). One page per call. Not to be retried: if the '
                . 'answer is lost, read the page again with somcp_audit_cms_seo before calling again.',
            [
                'type' => 'object',
                'properties' => [
                    'id_cms' => [
                        'type' => 'integer',
                        'description' => 'The CMS page to change, from somcp_audit_cms_seo.',
                    ],
                    'meta_title' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. The Meta title of the page form, per '
                            . 'ISO code: 70 characters at most, without < > = { }; an empty text removes it. Over 60 '
                            . 'characters, search results cut it.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'meta_description' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. Per ISO code: 512 characters at '
                            . 'most, without < > = { }; search results show about 160.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'link_rewrite' => [
                        'type' => 'object',
                        'description' => 'Assistant, approved by the merchant. The friendly URL, per ISO code: '
                            . 'made into lower case words joined by hyphens, 128 characters at most, never empty. '
                            . 'On a displayed page the old address stops working.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'indexation' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Indexation by search engines: whether the page may appear in '
                            . 'search results. Legal pages are often kept out on purpose: ask before changing it.',
                    ],
                ],
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

        $cms = self::page($arguments['id_cms'] ?? null, $idShop);
        if ($cms === null) {
            return SomcpTool::text(
                'No CMS page of this shop has this identifier. Nothing was changed. Find it with somcp_audit_cms_seo.',
                true
            );
        }

        $errors = [];
        $plan = self::plan($arguments, $cms, $idShop, $errors);
        if ($errors !== []) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
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

        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if ($key !== 'id_cms' && $key !== 'indexation' && !in_array($key, self::TEXTS, true)) {
                $errors[] = $key . ': not a field of this tool';
            }
        }

        $active = [];
        foreach (Language::getLanguages(true, $idShop) as $row) {
            $active[Tools::strtolower((string) $row['iso_code'])] = (int) $row['id_lang'];
        }

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
                $new = self::checked($field, trim($text), $idLang, $cms, $where, $errors);
                if ($new === null) {
                    continue;
                }
                $column = self::COLUMNS[$field];
                $previous = self::textOf($cms->{$column}, $idLang);
                if ($new === $previous) {
                    continue;
                }
                $plan['texts'][$column][$idLang] = $new;
                $plan['isos'][$idLang] = $iso;
                $plan['changes'][] = ['field' => $field, 'language' => $iso, 'previous' => $previous, 'new' => $new];
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
                    'previous' => (bool) $cms->indexation,
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
        } elseif ($field === 'meta_title' && Tools::strlen($text) > self::META_TITLE_MAX) {
            $errors[] = $where . ': ' . self::META_TITLE_MAX . ' characters at most, ' . Tools::strlen($text) . ' given';

            return null;
        } elseif ($field === 'meta_description' && Tools::strlen($text) > self::META_DESCRIPTION_MAX) {
            $errors[] = $where . ': ' . self::META_DESCRIPTION_MAX . ' characters at most, ' . Tools::strlen($text) . ' given';

            return null;
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
                'The page could not be changed: ' . $failure . '. Read it again with somcp_audit_cms_seo, tell the '
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
            $seoTitle = self::seoTitle($stored, $idLang);
            if (Tools::strlen($seoTitle) > SomcpToolAuditProductSeo::TITLE_MAX) {
                $warnings[$iso][] = 'the title shown in search results is over ' . SomcpToolAuditProductSeo::TITLE_MAX
                    . ' characters and will be cut';
            }
            $description = self::textOf($stored->meta_description, $idLang);
            if (Tools::strlen($description) > SomcpToolAuditProductSeo::DESCRIPTION_MAX) {
                $warnings[$iso][] = 'meta_description is over ' . SomcpToolAuditProductSeo::DESCRIPTION_MAX
                    . ' characters: search results cut it';
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
        foreach ($isos as $idLang => $iso) {
            $texts[$iso] = [
                'title' => self::textOf($stored->meta_title, $idLang),
                'meta_title' => self::textOf($stored->head_seo_title, $idLang),
                'seo_title' => self::seoTitle($stored, $idLang),
                'meta_description' => self::textOf($stored->meta_description, $idLang),
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
