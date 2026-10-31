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
 * somcp_audit_cms_seo - the CMS pages whose search engine fields have a
 * problem, per language.
 *
 * Built like somcp_audit_product_seo, whose language rule, word count and
 * length limits it reuses, so every audit judges a title the same way. A shop
 * holds a handful of pages, so they are read in one query on cms, cms_shop and
 * cms_lang of this shop and returned whole, without paging; the duplicates
 * are still grouped by the database, whose collation decides what "the same
 * title" is, as it does for the shop's URLs.
 *
 * Nothing is ever written: corrections go through somcp_update_cms_seo.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolAuditProductSeo.php';

class SomcpToolAuditCmsSeo
{
    /** @var array<int, string> Every check, in the order they are reported. */
    private const CHECKS = [
        'meta_title_too_long',
        'meta_title_duplicate',
        'meta_description_missing',
        'meta_description_long',
        'content_short',
        'link_rewrite_duplicate',
        'not_indexable',
    ];

    /**
     * The title search engines read: the page's own meta title when the
     * merchant filled it, its title otherwise - the rule of Meta::getCmsMetas()
     * on PrestaShop 8 and 9. Written for the alias cl of cms_lang.
     */
    private const TITLE_SQL = 'TRIM(IF(TRIM(IFNULL(cl.`head_seo_title`, \'\')) <> \'\', cl.`head_seo_title`, cl.`meta_title`))';

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_audit_cms_seo',
            Somcp::t('Audit the SEO of CMS pages'),
            'Lists the CMS pages of this shop (delivery, legal notice, about us...) whose search engine fields '
                . 'have a problem, one entry per page and language, each with its issues: the check that failed '
                . 'and the value at fault. The title checked is the one search engines read: the page\'s meta '
                . 'title when it is filled, its title otherwise. Checks: meta_title_too_long (over 60 '
                . 'characters), meta_title_duplicate (another page has it in that language), '
                . 'meta_description_missing, meta_description_long (over 160 characters), content_short (the '
                . 'content holds under 150 words, tags removed; value is the number of words), '
                . 'link_rewrite_duplicate (another page has the same friendly URL in that language) and '
                . 'not_indexable (the page asks search engines not to index it). Every page is listed, shown or '
                . 'not, with active saying which. counts gives the entries raising each check. Read only. Do '
                . 'not use it to change a page: somcp_update_cms_seo changes its search engine fields.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one language of the shop, for example en. Every language of '
                            . 'the shop when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_READ],
            [self::class, 'handle'],
            // The search engine fields of a page are in its form under Design
            // > Pages, and reading them takes the view right there.
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
        $idShop = Somcp::currentShopId();

        $languages = SomcpToolAuditProductSeo::languages($arguments, $idShop);
        if ($languages === null) {
            return SomcpTool::text(
                'This language is not one of the shop\'s. Give the ISO code of one of its languages, or leave '
                    . 'language out for all of them.',
                true
            );
        }

        $idLangs = array_keys($languages);
        $duplicateTitles = self::duplicates(self::TITLE_SQL, $idShop, $idLangs);
        $duplicateUrls = self::duplicates('TRIM(cl.`link_rewrite`)', $idShop, $idLangs);

        $counts = array_fill_keys(self::CHECKS, 0);
        $pages = [];

        foreach (self::rows($idShop, $idLangs) as $row) {
            $idCms = (int) $row['id_cms'];
            $idLang = (int) $row['id_lang'];
            $key = $idCms . '-' . $idLang;

            $issues = self::issues($row, isset($duplicateTitles[$key]), isset($duplicateUrls[$key]));
            if ($issues === []) {
                continue;
            }

            foreach ($issues as $issue) {
                ++$counts[$issue['check']];
            }
            $pages[] = [
                'id_cms' => $idCms,
                'meta_title' => (string) $row['meta_title'],
                'language' => $languages[$idLang],
                'active' => (bool) $row['active'],
                'issues' => $issues,
            ];
        }

        return SomcpTool::json([
            'pages' => $pages,
            'total_count' => count($pages),
            'counts' => $counts,
            'languages' => array_values($languages),
        ]);
    }

    /**
     * The issues of one page in one language.
     *
     * @param array<string, mixed> $row
     * @param bool $duplicateTitle
     * @param bool $duplicateUrl
     *
     * @return array<int, array<string, mixed>>
     */
    private static function issues(array $row, bool $duplicateTitle, bool $duplicateUrl): array
    {
        $title = trim((string) $row['seo_title']);
        $description = trim((string) $row['meta_description']);
        $words = SomcpToolAuditProductSeo::words((string) $row['content']);

        $found = [
            'meta_title_too_long' => Tools::strlen($title) > SomcpToolAuditProductSeo::TITLE_MAX ? $title : null,
            'meta_title_duplicate' => $duplicateTitle ? $title : null,
            'meta_description_missing' => $description === '' ? '' : null,
            'meta_description_long' => Tools::strlen($description) > SomcpToolAuditProductSeo::DESCRIPTION_MAX ? $description : null,
            // The value is the number of words, the thing to raise.
            'content_short' => $words < SomcpToolAuditProductSeo::WORDS_MIN ? $words : null,
            'link_rewrite_duplicate' => $duplicateUrl ? (string) $row['link_rewrite'] : null,
            // "Indexation by search engines" off in the page form: the page
            // tells search engines to keep it out of their results.
            'not_indexable' => !(bool) $row['indexation'] ? '' : null,
        ];

        $issues = [];
        foreach (self::CHECKS as $check) {
            if ($found[$check] !== null) {
                $issues[] = ['check' => $check, 'value' => $found[$check]];
            }
        }

        return $issues;
    }

    /**
     * The fields of every page of this shop, one row per page and language.
     *
     * @param int $idShop
     * @param array<int, int> $idLangs
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rows(int $idShop, array $idLangs): array
    {
        $query = new DbQuery();
        $query->select('c.`id_cms`, c.`active`, c.`indexation`, cl.`id_lang`, cl.`meta_title`, cl.`meta_description`');
        $query->select('cl.`content`, cl.`link_rewrite`, ' . self::TITLE_SQL . ' AS `seo_title`');
        $query->from('cms', 'c');
        // The shop comes from the token, and a page not associated with it
        // is not one of its pages.
        $query->innerJoin('cms_shop', 'cs', 'cs.`id_cms` = c.`id_cms` AND cs.`id_shop` = ' . $idShop);
        $query->innerJoin(
            'cms_lang',
            'cl',
            'cl.`id_cms` = c.`id_cms` AND cl.`id_shop` = ' . $idShop
                . ' AND cl.`id_lang` IN (' . implode(',', array_map('intval', $idLangs)) . ')'
        );
        $query->orderBy('c.`id_cms` ASC, cl.`id_lang` ASC');

        return Db::getInstance()->executeS($query) ?: [];
    }

    /**
     * The pages whose value another page of this shop has too, in the same
     * language, keyed "id_cms-id_lang".
     *
     * @param string $value an SQL expression of this class, never from the caller
     * @param int $idShop
     * @param array<int, int> $idLangs
     *
     * @return array<string, true>
     */
    private static function duplicates(string $value, int $idShop, array $idLangs): array
    {
        $langs = implode(',', array_map('intval', $idLangs));
        $inShop = 'INNER JOIN `' . _DB_PREFIX_ . 'cms_shop` cs ON cs.`id_cms` = cl.`id_cms` AND cs.`id_shop` = ' . $idShop;

        // Compared by the database's own collation, as the shop compares its
        // friendly URLs.
        $rows = Db::getInstance()->executeS(
            'SELECT cl.`id_cms`, cl.`id_lang`
             FROM `' . _DB_PREFIX_ . 'cms_lang` cl
             ' . $inShop . '
             INNER JOIN (
                SELECT cl.`id_lang`, ' . $value . ' AS `value`
                FROM `' . _DB_PREFIX_ . 'cms_lang` cl
                ' . $inShop . '
                WHERE cl.`id_shop` = ' . $idShop . ' AND cl.`id_lang` IN (' . $langs . ')
                  AND ' . $value . ' <> \'\'
                GROUP BY cl.`id_lang`, ' . $value . '
                HAVING COUNT(*) > 1
             ) d ON d.`id_lang` = cl.`id_lang` AND d.`value` = ' . $value . '
             WHERE cl.`id_shop` = ' . $idShop
        ) ?: [];

        $keys = [];
        foreach ($rows as $row) {
            $keys[(int) $row['id_cms'] . '-' . (int) $row['id_lang']] = true;
        }

        return $keys;
    }
}
