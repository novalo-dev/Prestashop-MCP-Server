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
 * somcp_audit_store_policies - whether the pages a buyer, a search engine or
 * an answer engine looks for before trusting a shop exist, are displayed, can
 * be indexed, have real content and are linked from the home page, with the
 * shop settings those pages depend on.
 *
 * The pages are CMS pages, reported under the field names of
 * somcp_list_cms_pages and somcp_get_cms_page. The home page is fetched and
 * its links read with the code of somcp_audit_page (hosts(), follow(),
 * load(), links()), and the words counted with the counter of every other
 * audit, so the tools never disagree on what a page says. Nothing is written.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolAuditStorePolicies
{
    /** @var int Seconds allowed to fetch the home page, redirects included, as somcp_audit_page. */
    private const TIMEOUT = 10;

    /** @var int Under this many words a policy page says too little to be relied on. */
    private const WORDS_MIN = 100;

    /** @var array<int, string> The pages expected, in the order they are reported. */
    private const TYPES = ['legal_notice', 'terms', 'shipping', 'returns', 'privacy', 'about', 'contact'];

    /**
     * The core cms_role rows that name a policy page. Only these two exist
     * without a legal compliance module; the roles such a module adds are
     * not mapped until their names are read in that module.
     *
     * @var array<string, string>
     */
    private const ROLES = ['LEGAL_NOTICE' => 'legal_notice', 'LEGAL_CONDITIONS' => 'terms'];

    /**
     * The words that name each page in a friendly URL or a title, already in
     * the form normalize() gives: lower case, no accent, words joined by a
     * dash. English, French, Spanish and German; English also serves every
     * other language. A word matches at the start of a word of the page, so
     * "retour" finds "retours" and "datenschutz" finds "datenschutzerklarung".
     *
     * @var array<string, array<int, string>>
     */
    private const KEYWORDS = [
        'legal_notice' => [
            'legal-notice', 'legal-information', 'imprint',
            'mentions-legales', 'aviso-legal', 'impressum',
        ],
        'terms' => [
            'terms-and-conditions', 'terms-of-service', 'terms-of-use', 'conditions-of-use',
            'conditions-generales', 'cgv', 'cgu', 'conditions-d-utilisation',
            'terminos-y-condiciones', 'condiciones-generales', 'condiciones-de-uso',
            'agb', 'allgemeine-geschaftsbedingungen', 'nutzungsbedingungen',
        ],
        'shipping' => [
            'shipping', 'delivery',
            'livraison', 'expedition',
            'envio', 'entrega',
            'versand', 'lieferung',
        ],
        'returns' => [
            'returns', 'return-policy', 'refund',
            'retour', 'remboursement', 'retractation',
            'devolucion', 'reembolso', 'desistimiento',
            'ruckgabe', 'rueckgabe', 'retoure', 'widerruf',
        ],
        'privacy' => [
            'privacy', 'data-protection',
            'confidentialite', 'donnees-personnelles', 'vie-privee',
            'privacidad', 'proteccion-de-datos',
            'datenschutz',
        ],
        'about' => [
            'about-us', 'about', 'who-we-are', 'our-story',
            'a-propos', 'qui-sommes-nous',
            'sobre-nosotros', 'quienes-somos',
            'uber-uns', 'ueber-uns',
        ],
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_audit_store_policies',
            Somcp::t('Audit the store policy pages'),
            'Checks the pages buyers and answer engines look for before trusting a shop - legal notice, terms, '
                . 'shipping, returns, privacy, about us, contact: found or not and how, Displayed, Indexation by '
                . 'search engines, word count, linked from the home page - plus the terms, returns and delivery '
                . 'time settings. Read only; what the pages say stays the merchant\'s responsibility. Use '
                . 'somcp_get_cms_page for one page, somcp_audit_cms_seo for their SEO fields.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr; a code '
                            . 'the shop does not have is refused. The shop default language when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_READ],
            [self::class, 'handle'],
            // The policy pages are managed on Design > Pages, and reading them
            // takes the view right there, as the other CMS page tools.
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

        // Every mistake found before reading anything is reported in one
        // answer, so the call is corrected in one go (the shape of #169).
        $problems = [];
        foreach (array_keys($arguments) as $field) {
            if ($field !== 'language') {
                $problems[] = '- ' . $field . ': not a field of this tool';
            }
        }
        $asked = array_key_exists('language', $arguments) && $arguments['language'] !== null && $arguments['language'] !== '';
        $language = SomcpTool::readLanguage($asked ? $arguments : [], $idShop);
        if ($asked && $language === null) {
            $problems[] = '- language: not a language of this shop, which has '
                . implode(', ', SomcpTool::activeLanguages($idShop));
        }
        if ($problems !== []) {
            return SomcpTool::text(
                "Nothing was read. Correct these and call again:\n" . implode("\n", array_unique($problems)),
                true
            );
        }
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;

        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $termsRequired = (bool) Configuration::get('PS_CONDITIONS', null, $idShopGroup, $idShop);
        $termsIdCms = (int) Configuration::get('PS_CONDITIONS_CMS_ID', null, $idShopGroup, $idShop);
        $returnsEnabled = (bool) Configuration::get('PS_ORDER_RETURN', null, $idShopGroup, $idShop);
        $returnsDays = (int) Configuration::get('PS_ORDER_RETURN_NB_DAYS', null, $idShopGroup, $idShop);
        $inStock = trim((string) Configuration::get('PS_LABEL_DELIVERY_TIME_AVAILABLE', $idLang, $idShopGroup, $idShop));
        $outOfStock = trim((string) Configuration::get('PS_LABEL_DELIVERY_TIME_OOSBOA', $idLang, $idShopGroup, $idShop));

        $cms = self::cmsPages($idShop, array_keys(SomcpTool::activeLanguages($idShop)));
        $roles = self::roles();

        $link = Somcp::legacyContext()->link ?? new Link();
        $homeUrl = (string) $link->getPageLink('index', null, $idLang, null, false, $idShop);
        $home = self::readHome($homeUrl, SomcpToolAuditPage::hosts($idShop));
        $homeLinks = [];
        foreach ($home['links'] as $target) {
            $homeLinks[self::comparable($target)] = true;
        }

        $taken = [];
        $pages = [];
        $warnings = [];
        foreach (self::TYPES as $type) {
            if ($type === 'contact') {
                // The contact page is a front controller, not a CMS page: it
                // always exists, and only its address and link can be checked.
                $url = (string) $link->getPageLink('contact', null, $idLang, null, false, $idShop);
                $page = [
                    'type' => $type,
                    'found' => true,
                    'matched_by' => 'controller',
                    'id_cms' => null,
                    'title' => null,
                    'active' => null,
                    'indexation' => null,
                    'url' => $url,
                    'word_count' => null,
                    'linked' => $home['fetched'] ? isset($homeLinks[self::comparable($url)]) : null,
                ];
                $pages[] = $page;
                if ($page['linked'] === false) {
                    $warnings[] = self::warning('page_not_linked', $type, 'The home page has no link to the contact page.');
                }
                continue;
            }

            $match = self::find($type, $cms, $roles, $termsIdCms, $taken);
            if ($match === null) {
                $pages[] = [
                    'type' => $type,
                    'found' => false,
                    'matched_by' => null,
                    'id_cms' => null,
                    'title' => null,
                    'active' => null,
                    'indexation' => null,
                    'url' => null,
                    'word_count' => null,
                    'linked' => null,
                ];
                $warnings[] = self::warning('page_missing', $type, 'No CMS page of this shop could be identified as this page.');
                continue;
            }

            $idCms = $match['id_cms'];
            $taken[$idCms] = true;
            $row = $cms[$idCms];
            $text = $row['langs'][$idLang] ?? ['title' => '', 'link_rewrite' => '', 'content' => ''];
            $url = (string) $link->getCMSLink($idCms, $text['link_rewrite'], null, $idLang, $idShop);
            $page = [
                'type' => $type,
                'found' => true,
                'matched_by' => $match['matched_by'],
                'id_cms' => $idCms,
                // The screen's Title, the meta_title column, as somcp_get_cms_page names it.
                'title' => $text['title'],
                'active' => $row['active'],
                'indexation' => $row['indexation'],
                'url' => $url,
                'word_count' => SomcpToolAuditProductSeo::words($text['content']),
                'linked' => $home['fetched'] ? isset($homeLinks[self::comparable($url)]) : null,
            ];
            if (count($match['candidates']) > 1) {
                // Several pages could be this one: the merchant chooses,
                // the first is only reported until then.
                $page['candidates'] = [];
                foreach ($match['candidates'] as $candidate) {
                    $page['candidates'][] = [
                        'id_cms' => $candidate,
                        'title' => $cms[$candidate]['langs'][$idLang]['title'] ?? '',
                    ];
                }
            }
            $pages[] = $page;

            if (!$page['active']) {
                $warnings[] = self::warning('page_hidden', $type, 'The page is not Displayed, so visitors and crawlers get a page not found.');
            }
            if (!$page['indexation']) {
                $warnings[] = self::warning('page_noindex', $type, 'Indexation by search engines is set to No, so the page tells search engines and answer engines to keep it out of their index.');
            }
            if ($page['word_count'] < self::WORDS_MIN) {
                $warnings[] = self::warning('page_too_short', $type, 'The Page content has ' . $page['word_count'] . ' words, under ' . self::WORDS_MIN . '.');
            }
            if ($page['linked'] === false) {
                $warnings[] = self::warning('page_not_linked', $type, 'The home page has no link to this page.');
            }
        }

        if (!$termsRequired) {
            $warnings[] = [
                'code' => 'terms_not_required',
                'why' => 'Terms of service is set to No in Order Settings: buyers are not asked to accept the terms before ordering.',
            ];
        }
        $rolePage = $roles['terms'] ?? 0;
        if ($termsIdCms > 0 && (($rolePage > 0 && $rolePage !== $termsIdCms) || (isset($cms[$termsIdCms]) && !$cms[$termsIdCms]['active']))) {
            $warnings[] = [
                'code' => 'terms_page_mismatch',
                'why' => 'The Page for terms and conditions of Order Settings is hidden, or is not the page the shop uses as its terms and conditions elsewhere.',
            ];
        }
        $returnsFound = false;
        foreach ($pages as $page) {
            if ($page['type'] === 'returns') {
                $returnsFound = $page['found'];
            }
        }
        if ($returnsFound && !$returnsEnabled) {
            $warnings[] = [
                'code' => 'returns_page_but_disabled',
                'why' => 'The shop has a returns page, but Enable returns is set to No in Merchandise Returns.',
            ];
        }
        if (!$returnsFound && $returnsEnabled) {
            $warnings[] = [
                'code' => 'returns_enabled_no_page',
                'why' => 'Enable returns is set to Yes, but no page tells buyers how returns work.',
            ];
        }
        $withoutDelivery = self::productsWithoutDeliveryTime($idShop, $inStock === '');
        if ($withoutDelivery > 0) {
            $warnings[] = [
                'code' => 'no_delivery_time',
                'why' => $withoutDelivery . ' active products show no delivery time.',
            ];
        }

        $homeReport = ['url' => $homeUrl, 'fetched' => $home['fetched']];
        if (!$home['fetched']) {
            $homeReport['error'] = $home['error'];
        }

        return SomcpTool::json([
            'language' => $iso,
            'pages' => $pages,
            'checkout' => [
                'terms_required' => $termsRequired,
                'terms_id_cms' => $termsIdCms > 0 ? $termsIdCms : null,
            ],
            'returns' => [
                'enabled' => $returnsEnabled,
                'days' => $returnsDays,
            ],
            'delivery_times' => [
                'in_stock' => $inStock === '' ? null : $inStock,
                'out_of_stock' => $outOfStock === '' ? null : $outOfStock,
                'products_without' => $withoutDelivery,
            ],
            'home' => $homeReport,
            'warnings' => $warnings,
            'note' => 'This reports which pages exist and how they are shown, not what they say: the legal content '
                . 'of these pages is the merchant\'s responsibility. A page with candidates was matched by its '
                . 'address or title among several: confirm with the merchant which one it is.',
        ]);
    }

    /**
     * The page of one type: the Order Settings page for the terms, then the
     * cms_role rows, then the friendly URL, then the Title, in any language
     * of the shop. The first step that answers wins; a page already given to
     * another type is not offered again.
     *
     * @param string $type
     * @param array<int, array{active: bool, indexation: bool, langs: array<int, array{title: string, link_rewrite: string, content: string}>}> $cms
     * @param array<string, int> $roles type => id_cms
     * @param int $termsIdCms
     * @param array<int, true> $taken
     *
     * @return array{id_cms: int, matched_by: string, candidates: array<int, int>}|null
     */
    private static function find(string $type, array $cms, array $roles, int $termsIdCms, array $taken): ?array
    {
        if ($type === 'terms' && $termsIdCms > 0 && isset($cms[$termsIdCms])) {
            return ['id_cms' => $termsIdCms, 'matched_by' => 'setting', 'candidates' => [$termsIdCms]];
        }
        if (isset($roles[$type]) && isset($cms[$roles[$type]])) {
            return ['id_cms' => $roles[$type], 'matched_by' => 'role', 'candidates' => [$roles[$type]]];
        }

        foreach (['url' => 'link_rewrite', 'title' => 'title'] as $matchedBy => $field) {
            $candidates = [];
            foreach ($cms as $idCms => $row) {
                if (isset($taken[$idCms])) {
                    continue;
                }
                foreach ($row['langs'] as $text) {
                    if (self::names($type, $text[$field])) {
                        $candidates[] = $idCms;
                        break;
                    }
                }
            }
            if ($candidates !== []) {
                return ['id_cms' => $candidates[0], 'matched_by' => $matchedBy, 'candidates' => $candidates];
            }
        }

        return null;
    }

    /**
     * Whether a friendly URL or a title names a page of this type.
     *
     * @param string $type
     * @param string $value
     *
     * @return bool
     */
    private static function names(string $type, string $value): bool
    {
        $haystack = '-' . self::normalize($value);
        foreach (self::KEYWORDS[$type] as $keyword) {
            if (strpos($haystack, '-' . $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * A title or friendly URL in the form of KEYWORDS: lower case, accents
     * removed, every other character a dash.
     *
     * @param string $value
     *
     * @return string
     */
    private static function normalize(string $value): string
    {
        $value = Tools::strtolower(Tools::replaceAccentedChars($value));

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $value), '-');
    }

    /**
     * The CMS pages of this shop, keyed by id_cms, with their texts in each
     * active language.
     *
     * @param int $idShop
     * @param array<int, int> $idLangs
     *
     * @return array<int, array{active: bool, indexation: bool, langs: array<int, array{title: string, link_rewrite: string, content: string}>}>
     */
    private static function cmsPages(int $idShop, array $idLangs): array
    {
        if ($idLangs === []) {
            return [];
        }
        $query = new DbQuery();
        $query->select('c.`id_cms`, c.`active`, c.`indexation`, cl.`id_lang`, cl.`meta_title`, cl.`link_rewrite`, cl.`content`');
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

        $pages = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $idCms = (int) $row['id_cms'];
            if (!isset($pages[$idCms])) {
                $pages[$idCms] = [
                    'active' => (bool) $row['active'],
                    'indexation' => (bool) $row['indexation'],
                    'langs' => [],
                ];
            }
            $pages[$idCms]['langs'][(int) $row['id_lang']] = [
                'title' => (string) $row['meta_title'],
                'link_rewrite' => (string) $row['link_rewrite'],
                'content' => (string) $row['content'],
            ];
        }

        return $pages;
    }

    /**
     * The page each core role points to, keyed by the type it stands for.
     *
     * @return array<string, int>
     */
    private static function roles(): array
    {
        $roles = [];
        $rows = Db::getInstance()->executeS(
            'SELECT `name`, `id_cms` FROM `' . _DB_PREFIX_ . 'cms_role` WHERE `id_cms` > 0'
        ) ?: [];
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            if (isset(self::ROLES[$name])) {
                $roles[self::ROLES[$name]] = (int) $row['id_cms'];
            }
        }

        return $roles;
    }

    /**
     * The number of active products of this shop that show no delivery time:
     * set to none, or to the shop default while the shop's in-stock text is
     * empty.
     *
     * @param int $idShop
     * @param bool $defaultEmpty whether the shop's in-stock text is empty
     *
     * @return int
     */
    private static function productsWithoutDeliveryTime(int $idShop, bool $defaultEmpty): int
    {
        // Stored as 0 (none), 1 (default) and 2 (product), as somcp_get_product reads it.
        $modes = $defaultEmpty ? '0, 1' : '0';

        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product` p
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
             WHERE ps.`active` = 1 AND p.`additional_delivery_times` IN (' . $modes . ')'
        );
    }

    /**
     * Fetches the home page once with the code of somcp_audit_page and
     * returns every address its links point to.
     *
     * @param string $url
     * @param array<int, string> $hosts the shop's active domains
     *
     * @return array{fetched: bool, error: string|null, links: array<int, string>}
     */
    private static function readHome(string $url, array $hosts): array
    {
        $failed = static function (string $error): array {
            return ['fetched' => false, 'error' => $error, 'links' => []];
        };
        if ($hosts === []) {
            return $failed('This shop has no active domain to fetch its pages from.');
        }
        if (!in_array(SomcpHttp::authority($url), $hosts, true)) {
            return $failed('The home page is not on one of the shop\'s domains.');
        }

        $fetched = SomcpToolAuditPage::follow($url, $hosts, self::TIMEOUT);
        $response = $fetched['response'];
        if ($response === null || $response['status'] === 0) {
            return $failed('The home page did not answer within ' . self::TIMEOUT . ' seconds.');
        }
        if ($fetched['outside'] !== null) {
            return $failed('The home page redirects to an address outside the shop, which was not fetched.');
        }
        if ($response['status'] !== 200) {
            return $failed('The home page answers ' . $response['status'] . ' instead of 200.');
        }
        $dom = SomcpToolAuditPage::load($response['body']);
        if ($dom === null) {
            return $failed('The home page could not be read as HTML.');
        }

        return [
            'fetched' => true,
            'error' => null,
            'links' => SomcpToolAuditPage::links(new DOMXPath($dom), $fetched['final_url']),
        ];
    }

    /**
     * An address in a form two links to the same page share: no scheme, the
     * host in lower case, no trailing slash.
     *
     * @param string $url
     *
     * @return string
     */
    private static function comparable(string $url): string
    {
        $url = (string) preg_replace('#^https?://#i', '', trim($url));
        $slash = strpos($url, '/');
        if ($slash === false) {
            return Tools::strtolower($url);
        }

        return Tools::strtolower(substr($url, 0, $slash)) . rtrim(substr($url, $slash), '/');
    }

    /**
     * A warning about one of the expected pages.
     *
     * @param string $code
     * @param string $type
     * @param string $why
     *
     * @return array{code: string, why: string, type: string}
     */
    private static function warning(string $code, string $type, string $why): array
    {
        return ['code' => $code, 'why' => $why, 'type' => $type];
    }
}
