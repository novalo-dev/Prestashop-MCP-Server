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
 * somcp_get_seo_settings - the shop settings that decide what its addresses
 * look like to a search engine: friendly URLs, canonical redirection, HTTPS,
 * domains, URL patterns, robots.txt and the sitemap module.
 *
 * These are the settings of Shop Parameters > Traffic & SEO and > General.
 * They are read by their configuration keys for the shop of the token, not
 * through the back office forms, which take the shop from the global context.
 * A key the running version no longer has is returned as null rather than
 * guessed from another one.
 *
 * Nothing is ever written: a wrong value here breaks every address of the
 * shop at once, so changing them stays in the back office.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetSeoSettings
{
    /**
     * The URL patterns returned, by the name the merchant reads, with the
     * route identifier the core stores them under.
     */
    private const ROUTES = [
        'product' => 'product_rule',
        'category' => 'category_rule',
        'brand' => 'manufacturer_rule',
        'supplier' => 'supplier_rule',
        'cms' => 'cms_rule',
        'cms_category' => 'cms_category_rule',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_seo_settings',
            Somcp::t('Read the SEO and URL settings'),
            'Reads the shop\'s SEO and URL settings and says which ones cost rankings: friendly_urls '
                . '(addresses with words rather than ?id_product=12), accented_urls, canonical_redirect (none, '
                . '302 or 301: whether a page reached through another address is sent to its own), https (whether '
                . 'the shop is served in HTTPS; https_everywhere is the PrestaShop 8 setting that serves every page '
                . 'in HTTPS, null on versions without it), domains (main first, with their address and HTTPS '
                . 'domain), routes (the URL patterns of product, category, brand, supplier, CMS and CMS category '
                . 'pages, and whether the merchant changed them; they only apply with friendly URLs on), '
                . 'robots_txt_present, sitemap_module (gsitemap installed and active), languages (each language '
                . 'adds its code to the addresses when the shop has several), and warnings. A setting the running '
                . 'version does not have is null. Read only. Do not use it to change these settings: that stays in '
                . 'the back office, since a wrong value breaks every address of the shop. somcp_list_meta_pages '
                . 'reads the titles and addresses of the shop\'s own pages.',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_READ],
            [self::class, 'handle'],
            // These settings live under Shop Parameters > Traffic & SEO, and
            // reading them takes the view right there.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminMeta')
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
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);

        $friendlyUrls = self::flag('PS_REWRITING_SETTINGS', $idShopGroup, $idShop);
        $https = self::flag('PS_SSL_ENABLED', $idShopGroup, $idShop);
        // PrestaShop 9 serves the whole shop in HTTPS once it is enabled and
        // dropped this key; an upgraded shop may still hold it, unread.
        $httpsEverywhere = version_compare(_PS_VERSION_, '9.0.0', '>=')
            ? null
            : self::flag('PS_SSL_ENABLED_EVERYWHERE', $idShopGroup, $idShop);
        $canonical = self::canonicalRedirect($idShopGroup, $idShop);
        $robots = is_file(_PS_ROOT_DIR_ . '/robots.txt');
        $sitemap = self::sitemapModule($idShop);

        $warnings = [];
        if ($friendlyUrls === false) {
            $warnings[] = [
                'code' => 'friendly_urls_off',
                'why' => 'Addresses carry identifiers instead of words, and URL patterns do not apply.',
            ];
        }
        if ($canonical === 'none') {
            $warnings[] = [
                'code' => 'no_canonical_redirect',
                'why' => 'A page reached through another address is not sent to its own, so search engines may '
                    . 'index the same page several times.',
            ];
        }
        if ($https === false) {
            $warnings[] = [
                'code' => 'not_https',
                'why' => 'The shop is not served in HTTPS: browsers mark it as not secure and search engines rank '
                    . 'it lower.',
            ];
        } elseif ($httpsEverywhere === false) {
            $warnings[] = [
                'code' => 'https_not_everywhere',
                'why' => 'HTTPS is enabled but not on every page: only the account and checkout pages are served '
                    . 'in HTTPS, the rest over plain HTTP.',
            ];
        }
        if (!$robots) {
            $warnings[] = [
                'code' => 'no_robots_txt',
                'why' => 'No robots.txt at the root of the shop: search engines crawl the cart, account and search '
                    . 'pages as well.',
            ];
        }
        if (!$sitemap['installed'] || !$sitemap['active']) {
            $warnings[] = [
                'code' => 'no_sitemap_module',
                'why' => 'The sitemap module (gsitemap) is not installed and active, so no sitemap lists the pages '
                    . 'for search engines.',
            ];
        }

        return SomcpTool::json([
            'friendly_urls' => $friendlyUrls,
            'accented_urls' => self::flag('PS_ALLOW_ACCENTED_CHARS_URL', $idShopGroup, $idShop),
            'canonical_redirect' => $canonical,
            'https' => $https,
            'https_everywhere' => $httpsEverywhere,
            'domains' => self::domains($idShop),
            'routes' => self::routes($idShopGroup, $idShop),
            'robots_txt_present' => $robots,
            'sitemap_module' => $sitemap,
            'languages' => self::languages($idShopGroup, $idShop),
            'warnings' => $warnings,
        ]);
    }

    /**
     * One yes or no setting of the shop, null when this version does not have it.
     *
     * @param string $key
     * @param int $idShopGroup
     * @param int $idShop
     *
     * @return bool|null
     */
    private static function flag(string $key, int $idShopGroup, int $idShop): ?bool
    {
        $value = Configuration::get($key, null, $idShopGroup, $idShop);

        // false is what Configuration::get() returns for a key that does not
        // exist; a key set to off is stored as '0'.
        return $value === false ? null : (bool) $value;
    }

    /**
     * The canonical redirection, in the terms of the back office option.
     *
     * @param int $idShopGroup
     * @param int $idShop
     *
     * @return string|null
     */
    private static function canonicalRedirect(int $idShopGroup, int $idShop): ?string
    {
        $value = Configuration::get('PS_CANONICAL_REDIRECT', null, $idShopGroup, $idShop);
        if ($value === false) {
            return null;
        }

        // FrontController redirects with a 301 for 2 and a 302 for any other
        // value that is not empty.
        if (!$value) {
            return 'none';
        }

        return (int) $value === 2 ? '301' : '302';
    }

    /**
     * Whether gsitemap is installed, and enabled on the shop of the token.
     *
     * @param int $idShop
     *
     * @return array{installed: bool, active: bool}
     */
    private static function sitemapModule(int $idShop): array
    {
        $idModule = (int) Module::getModuleIdByName('gsitemap');
        $installed = $idModule > 0 && Module::isInstalled('gsitemap');

        // module_shop is read for the shop of the token: Module::isEnabled()
        // answers for the shop of the global context.
        $active = $installed && (bool) Db::getInstance()->getValue(
            'SELECT `id_module` FROM `' . _DB_PREFIX_ . 'module_shop`'
            . ' WHERE `id_module` = ' . $idModule . ' AND `id_shop` = ' . (int) $idShop
        );

        return ['installed' => $installed, 'active' => $active];
    }

    /**
     * The addresses of the shop, main one first.
     *
     * @param int $idShop
     *
     * @return array<int, array<string, mixed>>
     */
    private static function domains(int $idShop): array
    {
        $query = new DbQuery();
        $query->select('`domain`, `domain_ssl`, `physical_uri`, `virtual_uri`, `main`, `active`');
        $query->from('shop_url');
        $query->where('`id_shop` = ' . (int) $idShop);
        $query->orderBy('`main` DESC, `id_shop_url` ASC');

        $domains = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $domains[] = [
                'domain' => (string) $row['domain'],
                'domain_ssl' => (string) $row['domain_ssl'],
                'uri' => (string) $row['physical_uri'] . (string) $row['virtual_uri'],
                'main' => (bool) $row['main'],
                'active' => (bool) $row['active'],
            ];
        }

        return $domains;
    }

    /**
     * The URL pattern of each page type, as the storefront applies it.
     *
     * @param int $idShopGroup
     * @param int $idShop
     *
     * @return array<string, array{pattern: string|null, customized: bool}>
     */
    private static function routes(int $idShopGroup, int $idShop): array
    {
        // The defaults are read from the class rather than written here: they
        // differ between versions (PrestaShop 8 puts the category and the
        // EAN-13 in the product address). No instance is needed, which keeps
        // the dispatcher of the request untouched.
        $vars = get_class_vars('Dispatcher');
        $defaults = is_array($vars['default_routes'] ?? null) ? $vars['default_routes'] : [];

        $routes = [];
        foreach (self::ROUTES as $name => $routeId) {
            $default = isset($defaults[$routeId]['rule']) ? (string) $defaults[$routeId]['rule'] : null;
            // The same key and test as Dispatcher::loadRoutes(): an empty
            // value leaves the default in place.
            $custom = Configuration::get('PS_ROUTE_' . $routeId, null, $idShopGroup, $idShop);
            $customized = is_string($custom) && $custom !== '' && $custom !== $default;
            $routes[$name] = [
                'pattern' => $customized ? $custom : $default,
                'customized' => $customized,
            ];
        }

        return $routes;
    }

    /**
     * The languages of the shop, each one a prefix of the addresses when the
     * shop has several active.
     *
     * @param int $idShopGroup
     * @param int $idShop
     *
     * @return array<int, array<string, mixed>>
     */
    private static function languages(int $idShopGroup, int $idShop): array
    {
        $default = (int) Configuration::get('PS_LANG_DEFAULT', null, $idShopGroup, $idShop);

        $languages = [];
        foreach (Language::getLanguages(false, $idShop) as $language) {
            $languages[] = [
                'iso_code' => (string) $language['iso_code'],
                'name' => (string) $language['name'],
                'active' => (bool) $language['active'],
                'default' => (int) $language['id_lang'] === $default,
            ];
        }

        return $languages;
    }
}
