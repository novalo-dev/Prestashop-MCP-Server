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
 * somcp_generate_llms_txt - writes an llms.txt at the root of the
 * installation, the plain text summary some AI tools read at the root of a
 * site, from the shop's own data: name, a summary agreed with the merchant,
 * main categories, the policy and information pages, and the contact page.
 *
 * No back office screen writes an llms.txt on PrestaShop 8 or 9, so the file
 * is assembled here. It sits beside robots.txt and is written the same way
 * somcp_generate_robots_txt writes that file: one file at the root, replaced
 * whole, the previous content returned in full, the same three rights on
 * AdminMeta, the same refusal when the root is not writable. The file is read
 * with somcp_get_robots_txt's read() and capped at its MAX_BYTES, and the
 * marker that records somcp wrote it is the one constant of somcp_get_llms_txt,
 * so the two tools never read the file or recognise their own work two
 * different ways. Which CMS page is a policy page is decided by the matching
 * of somcp_audit_store_policies, called here, never a second copy.
 *
 * Only what the shop holds is written: no claim, review, price or figure is
 * invented. Nothing is fetched over the network.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGenerateLlmsTxt
{
    /** @var int The summary is one paragraph, not a page: longer is refused. */
    private const SUMMARY_MAX = 1000;

    /** @var int Categories listed by default, and the most a call may ask for. */
    private const CATEGORIES_DEFAULT = 20;
    private const CATEGORIES_MAX = 100;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        // The file is replaced whole at the root, like robots.txt, so the
        // tool asks for the same three rights the Generate robots.txt file
        // button of Shop Parameters > Traffic & SEO asks for together, rather
        // than less than the screen it stands beside.
        $acts = SomcpTool::acts(SomcpPermission::EDIT, 'AdminMeta');
        $acts['permissions'][] = SomcpPermission::on('AdminMeta', SomcpPermission::ADD);
        $acts['permissions'][] = SomcpPermission::on('AdminMeta', SomcpPermission::DELETE);

        return new SomcpTool(
            'somcp_generate_llms_txt',
            Somcp::t('Generate the llms.txt file'),
            'Writes an llms.txt at the root of the shop, the plain text summary some AI tools read at the root of '
                . 'a site, from the shop\'s own data: name, a summary, the main categories, the policy and '
                . 'information pages, the contact page. llms.txt is a proposed convention, not a standard: no '
                . 'crawler or assistant is bound to read it. A file written by hand is replaced only with '
                . 'overwrite: read it first with somcp_get_llms_txt, show the merchant what will go, and call once '
                . 'they agree. One file holds one language. On a multistore installation the file is shared by '
                . 'every shop. Not to be retried: if the answer is lost, read the file with somcp_get_llms_txt. '
                . 'For robots.txt use somcp_generate_robots_txt; to read this file use somcp_get_llms_txt.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, the '
                            . 'language of the file and of its links; the shop default when left out; a code the '
                            . 'shop does not have is refused.',
                    ],
                    'summary' => [
                        'type' => 'string',
                        'description' => 'The paragraph describing the shop, written with the merchant; the home '
                            . 'page meta description when left out; ' . self::SUMMARY_MAX . ' characters at most.',
                    ],
                    'max_categories' => [
                        'type' => 'integer',
                        'description' => 'How many of the first categories under Home, in their Position order, to '
                            . 'list: ' . self::CATEGORIES_DEFAULT . ' by default, ' . self::CATEGORIES_MAX . ' at most.',
                    ],
                    'overwrite' => [
                        'type' => 'boolean',
                        'description' => 'Required to replace a file somcp did not write, that is one without its '
                            . 'marker. A file somcp wrote is always replaced.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_WRITE],
            [self::class, 'handle'],
            $acts
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

        // Every mistake is reported in one answer, so the call is corrected in
        // one go (the shape of #169), before anything is read or written.
        $allowed = ['language', 'summary', 'max_categories', 'overwrite'];
        $problems = [];
        foreach (array_keys($arguments) as $field) {
            if (!in_array($field, $allowed, true)) {
                $problems[] = '- ' . $field . ': not a field of this tool';
            }
        }

        $askedLang = array_key_exists('language', $arguments) && $arguments['language'] !== null && $arguments['language'] !== '';
        $language = SomcpTool::readLanguage($askedLang ? $arguments : [], $idShop);
        if ($askedLang && $language === null) {
            $problems[] = '- language: not a language of this shop, which has '
                . implode(', ', SomcpTool::activeLanguages($idShop));
        }

        $summary = null;
        if (array_key_exists('summary', $arguments) && $arguments['summary'] !== null) {
            if (!is_string($arguments['summary'])) {
                $problems[] = '- summary: must be text';
            } else {
                $summary = trim($arguments['summary']);
                if (Tools::strlen($summary) > self::SUMMARY_MAX) {
                    $problems[] = '- summary: longer than ' . self::SUMMARY_MAX . ' characters';
                }
            }
        }

        $maxCategories = self::CATEGORIES_DEFAULT;
        if (array_key_exists('max_categories', $arguments) && $arguments['max_categories'] !== null) {
            if (!is_int($arguments['max_categories']) || $arguments['max_categories'] < 1 || $arguments['max_categories'] > self::CATEGORIES_MAX) {
                $problems[] = '- max_categories: a whole number from 1 to ' . self::CATEGORIES_MAX;
            } else {
                $maxCategories = $arguments['max_categories'];
            }
        }

        $overwrite = false;
        if (array_key_exists('overwrite', $arguments) && $arguments['overwrite'] !== null) {
            if (!is_bool($arguments['overwrite'])) {
                $problems[] = '- overwrite: true or false';
            } else {
                $overwrite = $arguments['overwrite'];
            }
        }

        if ($problems !== []) {
            return SomcpTool::text(
                "Nothing was written. Correct these and call again:\n" . implode("\n", array_unique($problems)),
                true
            );
        }
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;

        $file = _PS_ROOT_DIR_ . '/llms.txt';
        $previous = is_file($file) && is_readable($file) ? SomcpToolGetRobotsTxt::read($file) : null;

        // A file somcp did not write (no marker) is the merchant's own: it is
        // never replaced without overwrite, and its content comes back so the
        // merchant sees what would be lost.
        if ($previous !== null && strpos($previous, SomcpToolGetLlmsTxt::MARKER) === false && !$overwrite) {
            return SomcpTool::json([
                'changed' => false,
                'written' => false,
                'reason' => 'existing_file_not_written_by_somcp',
                'message' => 'There is already an llms.txt at the root that somcp did not write. Call again with '
                    . 'overwrite set to true to replace it. Its current content is returned so the merchant can '
                    . 'see what would be lost.',
                'previous_content' => $previous,
            ]);
        }

        $shop = Somcp::currentShop();
        if ($summary === null || $summary === '') {
            $summary = self::metaDescription($idShop, $idLang);
        }

        $content = self::build($idShop, $idLang, (string) $shop->name, $summary, $maxCategories);

        $changed = $content !== $previous;
        if ($changed && !self::write($file, $content)) {
            return SomcpTool::text(
                'The llms.txt file could not be written at the root of the shop: the web server may not write '
                    . 'there. Nothing was changed. The merchant or their host has to allow it, then generate the '
                    . 'file again.',
                true
            );
        }

        $https = (bool) Configuration::get('PS_SSL_ENABLED');
        // AI tools ask for /llms.txt at the root of the host, whatever folder
        // the shop sits in, exactly as somcp_get_llms_txt reports the address.
        $url = ($https ? 'https://' . (string) $shop->domain_ssl : 'http://' . (string) $shop->domain) . '/llms.txt';

        return SomcpTool::json([
            'changed' => $changed,
            'written' => $changed,
            'previous_content' => $previous,
            'content' => $content,
            'url' => $url,
            'language' => $iso,
            'warnings' => self::warnings($idShop, $shop),
            'note' => 'llms.txt is a proposed convention, not a standard: no crawler or assistant is bound to read '
                . 'it. Only what the shop holds was written, no claim, review, price or figure was invented.',
        ]);
    }

    /**
     * The llms.txt text: a "# " title with the shop name, a "> " line with the
     * summary, then Categories, Pages (policy pages first, in the order of
     * somcp_audit_store_policies) and Contact, one "- [name](url)" per line,
     * the marker on a line of its own at the end.
     *
     * @param int $idShop
     * @param int $idLang
     * @param string $shopName
     * @param string $summary
     * @param int $maxCategories
     *
     * @return string
     */
    private static function build(int $idShop, int $idLang, string $shopName, string $summary, int $maxCategories): string
    {
        $link = Somcp::legacyContext()->link ?? new Link();

        $lines = ['# ' . self::clean($shopName)];
        if ($summary !== '') {
            $lines[] = '';
            $lines[] = '> ' . self::clean($summary);
        }

        $categories = self::categories($idShop, $idLang, $maxCategories, $link);
        if ($categories !== []) {
            $lines[] = '';
            $lines[] = '## Categories';
            $lines[] = '';
            foreach ($categories as $entry) {
                $lines[] = '- [' . self::clean($entry['name']) . '](' . $entry['url'] . ')';
            }
        }

        $pages = self::pages($idShop, $idLang, $link);
        if ($pages !== []) {
            $lines[] = '';
            $lines[] = '## Pages';
            $lines[] = '';
            foreach ($pages as $entry) {
                $lines[] = '- [' . self::clean($entry['title']) . '](' . $entry['url'] . ')';
            }
        }

        $contactUrl = (string) $link->getPageLink('contact', null, $idLang, null, false, $idShop);
        $contactName = self::metaTitle($idShop, $idLang, 'contact');
        $lines[] = '';
        $lines[] = '## Contact';
        $lines[] = '';
        $lines[] = '- [' . self::clean($contactName !== '' ? $contactName : $shopName) . '](' . $contactUrl . ')';

        $lines[] = '';
        $lines[] = SomcpToolGetLlmsTxt::MARKER;

        return implode("\n", $lines) . "\n";
    }

    /**
     * The first active categories under the shop's home category, in their
     * Position order, with their name and link in the chosen language.
     *
     * @param int $idShop
     * @param int $idLang
     * @param int $max
     * @param Link $link
     *
     * @return array<int, array{name: string, url: string}>
     */
    private static function categories(int $idShop, int $idLang, int $max, Link $link): array
    {
        $shop = Somcp::currentShop();
        // The home of a shop is its root display category, PS_HOME_CATEGORY on
        // a single shop; the same category somcp_create_category places under.
        $home = (int) $shop->id_category > 0 ? (int) $shop->id_category : (int) Configuration::get('PS_HOME_CATEGORY');

        $query = new DbQuery();
        $query->select('c.`id_category`, cl.`name`, cl.`link_rewrite`');
        $query->from('category', 'c');
        $query->innerJoin('category_shop', 'cs', 'cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $idShop);
        $query->leftJoin(
            'category_lang',
            'cl',
            'cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . $idLang . ' AND cl.`id_shop` = ' . $idShop
        );
        $query->where('c.`id_parent` = ' . $home);
        $query->where('c.`active` = 1');
        // The Position order of the Categories screen is category_shop.position.
        $query->orderBy('cs.`position` ASC, c.`id_category` ASC');

        $categories = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $categories[] = [
                'name' => $name,
                'url' => (string) $link->getCategoryLink((int) $row['id_category'], (string) ($row['link_rewrite'] ?? ''), $idLang, null, $idShop),
            ];
            if (count($categories) >= $max) {
                break;
            }
        }

        return $categories;
    }

    /**
     * The displayed CMS pages of the shop, the policy pages first in the order
     * of somcp_audit_store_policies, then the rest by id, with their title and
     * link in the chosen language.
     *
     * @param int $idShop
     * @param int $idLang
     * @param Link $link
     *
     * @return array<int, array{title: string, url: string}>
     */
    private static function pages(int $idShop, int $idLang, Link $link): array
    {
        $query = new DbQuery();
        $query->select('c.`id_cms`, cl.`meta_title`, cl.`link_rewrite`');
        $query->from('cms', 'c');
        $query->innerJoin('cms_shop', 'cs', 'cs.`id_cms` = c.`id_cms` AND cs.`id_shop` = ' . $idShop);
        $query->leftJoin(
            'cms_lang',
            'cl',
            'cl.`id_cms` = c.`id_cms` AND cl.`id_lang` = ' . $idLang . ' AND cl.`id_shop` = ' . $idShop
        );
        // Only Displayed pages reach the storefront, so only they belong in a
        // file that points AI tools to real addresses.
        $query->where('c.`active` = 1');
        $query->orderBy('c.`id_cms` ASC');

        $rows = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $rows[(int) $row['id_cms']] = [
                'title' => trim((string) ($row['meta_title'] ?? '')),
                'link_rewrite' => (string) ($row['link_rewrite'] ?? ''),
            ];
        }

        // Which displayed page is a policy page, in the order the policy audit
        // reports them, so the pages a buyer looks for come first.
        $ordered = [];
        foreach (array_keys(SomcpToolAuditStorePolicies::policyPages($idShop)) as $idCms) {
            if (isset($rows[$idCms])) {
                $ordered[$idCms] = true;
            }
        }
        foreach (array_keys($rows) as $idCms) {
            $ordered[$idCms] = true;
        }

        $pages = [];
        foreach (array_keys($ordered) as $idCms) {
            $row = $rows[$idCms];
            $title = $row['title'] !== '' ? $row['title'] : $row['link_rewrite'];
            if ($title === '') {
                continue;
            }
            $pages[] = [
                'title' => $title,
                'url' => (string) $link->getCMSLink($idCms, $row['link_rewrite'], null, $idLang, $idShop),
            ];
        }

        return $pages;
    }

    /**
     * The warnings about the written file, in the shape of
     * somcp_get_robots_txt.
     *
     * @param int $idShop
     * @param Shop $shop
     *
     * @return array<int, array{code: string, why: string}>
     */
    private static function warnings(int $idShop, Shop $shop): array
    {
        $warnings = [];
        if ((string) $shop->physical_uri !== '/') {
            $warnings[] = [
                'code' => 'not_at_domain_root',
                'why' => 'The shop is installed in the folder ' . (string) $shop->physical_uri . ', so this file is '
                    . 'served there and not at the root of the domain, where AI tools look for it. The llms.txt at '
                    . 'the root of the domain is the one that is read.',
            ];
        }
        if (Shop::isFeatureActive()) {
            $warnings[] = [
                'code' => 'shared_by_shops',
                'why' => 'There is one llms.txt at the root for the whole installation: it was written for this '
                    . 'shop and is served to every shop of the installation.',
            ];
        }
        // A listed CMS page set not to be indexed still points AI tools to a
        // page the shop asks search engines to keep out of their index.
        $notIndexed = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cms` c
             INNER JOIN `' . _DB_PREFIX_ . 'cms_shop` cs ON cs.`id_cms` = c.`id_cms` AND cs.`id_shop` = ' . $idShop . '
             WHERE c.`active` = 1 AND c.`indexation` = 0'
        );
        if ($notIndexed > 0) {
            $warnings[] = [
                'code' => 'pages_not_indexable',
                'why' => $notIndexed . ' of the listed pages have Indexation by search engines set to No, so they '
                    . 'ask search engines and answer engines to keep them out of their index.',
            ];
        }

        return $warnings;
    }

    /**
     * The meta description of the home page in a language, the shop default
     * summary; empty when there is none.
     *
     * @param int $idShop
     * @param int $idLang
     *
     * @return string
     */
    private static function metaDescription(int $idShop, int $idLang): string
    {
        return trim((string) Db::getInstance()->getValue(
            'SELECT ml.`description` FROM `' . _DB_PREFIX_ . 'meta` m
             INNER JOIN `' . _DB_PREFIX_ . 'meta_lang` ml ON ml.`id_meta` = m.`id_meta`
                 AND ml.`id_lang` = ' . $idLang . ' AND ml.`id_shop` = ' . $idShop . "
             WHERE m.`page` = 'index'"
        ));
    }

    /**
     * The meta title of a core page in a language; empty when there is none.
     *
     * @param int $idShop
     * @param int $idLang
     * @param string $page
     *
     * @return string
     */
    private static function metaTitle(int $idShop, int $idLang, string $page): string
    {
        return trim((string) Db::getInstance()->getValue(
            'SELECT ml.`title` FROM `' . _DB_PREFIX_ . 'meta` m
             INNER JOIN `' . _DB_PREFIX_ . 'meta_lang` ml ON ml.`id_meta` = m.`id_meta`
                 AND ml.`id_lang` = ' . $idLang . ' AND ml.`id_shop` = ' . $idShop . "
             WHERE m.`page` = '" . pSQL($page) . "'"
        ));
    }

    /**
     * A name or summary fit for a single line of the file: the line breaks a
     * title or description could hold would otherwise split one entry in two.
     *
     * @param string $value
     *
     * @return string
     */
    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Writes the file atomically: a temporary file in the same folder, then a
     * rename, so a reader never gets half a file. False when the root cannot
     * be written, as somcp_generate_robots_txt reports it.
     *
     * @param string $file
     * @param string $content
     *
     * @return bool
     */
    private static function write(string $file, string $content): bool
    {
        $dir = dirname($file);
        if (!is_writable($dir) || (is_file($file) && !is_writable($file))) {
            return false;
        }
        $tmp = tempnam($dir, 'llms');
        if ($tmp === false) {
            return false;
        }
        if (file_put_contents($tmp, $content) === false) {
            @unlink($tmp);

            return false;
        }
        // A file left by tempnam is not world readable; the served file is a
        // public one, read like robots.txt beside it.
        @chmod($tmp, 0644);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }
        clearstatcache(true, $file);

        return true;
    }
}
