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
 * somcp_get_theme - the theme of the token's shop as Design > Theme & Logo
 * shows it: the theme in use and the other installed ones, the four logos of
 * the Logo panel, and the layout of every page of Choose layouts.
 *
 * The theme is read through the core ThemeRepository, built for the token's
 * shop, so its own config/themes/<theme>/shop<id>.json is the one read, the
 * way the back office reads it - never by parsing the file here. The pages are
 * Meta::getAllMeta(), the list Choose layouts draws its rows from, and a page
 * without its own setting takes the default layout, as the form does.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetTheme
{
    /**
     * The four logos of the Logo panel, with the configuration key holding
     * each file name in img/.
     *
     * @var array<string, string>
     */
    private const LOGOS = [
        'header' => 'PS_LOGO',
        'mail' => 'PS_LOGO_MAIL',
        'invoice' => 'PS_LOGO_INVOICE',
        'favicon' => 'PS_FAVICON',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_theme',
            Somcp::t('Get the theme and page layouts'),
            'Returns the theme of the shop as Design > Theme & Logo shows it: the theme in use (theme: name, '
                . 'display_name, version, author, parent for a child theme), the other installed themes '
                . '(other_themes[]), the addresses of the four logos (logos: header, mail, invoice, favicon; '
                . 'mail and invoice are null with mail_uses_header / invoice_uses_header true when the header '
                . 'logo stands in for them), the layouts the theme offers (available_layouts[]: layout key, name, '
                . 'description), the default layout, and every page of Choose layouts (pages[]: page, the same '
                . 'name somcp_list_meta_pages gives, title, description, layout key, uses_default when the page '
                . 'has no setting of its own). Read only. Do not use it for the meta titles and URLs of the '
                . 'pages (somcp_list_meta_pages) or for the main menu (somcp_list_menu_links).',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, '
                            . 'for the texts, which are translated; a code the shop does not have is refused. '
                            . 'The shop default language when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::APPEARANCE_READ],
            [self::class, 'handle'],
            // Theme & Logo and Choose layouts open with the view right on the
            // themes page.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminThemes')
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

        // A misspelt argument would otherwise be ignored and the default
        // language answered, which the assistant would take for the one it
        // asked for.
        $unknown = array_diff(array_keys($arguments), ['language']);
        if ($unknown !== []) {
            $lines = [];
            foreach ($unknown as $key) {
                $lines[] = '- ' . $key . ': not a field of this tool';
            }

            return SomcpTool::text("Nothing was read. Correct these and call again:\n" . implode("\n", $lines), true);
        }

        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;

        $theme = self::shopTheme();
        if ($theme === null) {
            return self::themeMissing();
        }
        $themeName = (string) $theme->getName();
        $repository = self::repository();

        $otherThemes = [];
        try {
            foreach ($repository->getListExcluding([$themeName]) as $other) {
                $otherThemes[] = self::describe($other);
            }
        } catch (Exception $e) {
            // A broken theme folder elsewhere must not hide the theme in use:
            // the list is information only.
            $otherThemes = [];
        }

        $available = [];
        foreach ((array) $theme->getAvailableLayouts() as $key => $layout) {
            $available[] = [
                'layout' => (string) $key,
                'name' => (string) ($layout['name'] ?? $key),
                'description' => (string) ($layout['description'] ?? ''),
            ];
        }

        $defaultLayout = (string) $theme->get('theme_settings.default_layout');
        $ownLayouts = (array) $theme->getPageLayouts();

        $pages = [];
        // The rows of Choose layouts, in its order.
        foreach (Meta::getAllMeta($idLang) ?: [] as $meta) {
            $page = (string) $meta['page'];
            $title = (string) ($meta['title'] ?? '');
            $own = isset($ownLayouts[$page]) && $ownLayouts[$page] !== '' ? (string) $ownLayouts[$page] : null;
            $pages[] = [
                'page' => $page,
                // The screen shows the page name when the page has no title.
                'title' => $title !== '' ? $title : $page,
                'description' => (string) ($meta['description'] ?? ''),
                'layout' => $own ?? $defaultLayout,
                'uses_default' => $own === null,
            ];
        }

        return SomcpTool::json([
            'theme' => self::describe($theme) + [
                'parent' => $theme->get('parent') !== null ? (string) $theme->get('parent') : null,
            ],
            'other_themes' => $otherThemes,
            'logos' => self::logos($idShop),
            'default_layout' => $defaultLayout,
            'available_layouts' => $available,
            'pages' => $pages,
            'language' => $iso,
        ]);
    }

    /**
     * The theme repository built for the token's shop, so each theme is read
     * with this shop's own configuration, as the back office reads it.
     *
     * @return PrestaShop\PrestaShop\Core\Addon\Theme\ThemeRepository
     */
    public static function repository(): PrestaShop\PrestaShop\Core\Addon\Theme\ThemeRepository
    {
        $legacy = Somcp::legacyContext();
        $builder = new PrestaShop\PrestaShop\Core\Addon\Theme\ThemeManagerBuilder($legacy, Db::getInstance());

        return $builder->buildRepository($legacy->shop);
    }

    /**
     * The theme the token's shop uses, or null when its folder or its
     * configuration is missing from the server. Shared with
     * somcp_update_theme_setting, which writes what this tool reads.
     *
     * @return PrestaShop\PrestaShop\Core\Addon\Theme\Theme|null
     */
    public static function shopTheme(): ?PrestaShop\PrestaShop\Core\Addon\Theme\Theme
    {
        try {
            return self::repository()->getInstanceByName((string) Somcp::legacyContext()->shop->theme_name);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * The answer when shopTheme() finds no theme.
     *
     * @return array<string, mixed>
     */
    public static function themeMissing(): array
    {
        return SomcpTool::text(
            'The theme of this shop (' . (string) Somcp::legacyContext()->shop->theme_name . ') cannot be read: '
                . 'its folder or its configuration is missing from the server.',
            true
        );
    }

    /**
     * The fields of a theme card of My theme for ... shop.
     *
     * @param PrestaShop\PrestaShop\Core\Addon\Theme\Theme $theme
     *
     * @return array<string, string>
     */
    private static function describe(PrestaShop\PrestaShop\Core\Addon\Theme\Theme $theme): array
    {
        return [
            'name' => (string) $theme->getName(),
            'display_name' => (string) $theme->get('display_name', $theme->getName()),
            'version' => (string) $theme->get('version', ''),
            'author' => (string) $theme->get('author.name', ''),
        ];
    }

    /**
     * The four logos of the shop, as absolute addresses.
     *
     * The mail and invoice logos are optional: the shop prints the header logo
     * in their place, which the answer says rather than repeating its address.
     *
     * @param int $idShop
     *
     * @return array<string, string|bool|null>
     */
    private static function logos(int $idShop): array
    {
        $link = Somcp::legacyContext()->link ?? new Link();
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);

        $logos = [];
        foreach (self::LOGOS as $field => $key) {
            $file = (string) Configuration::get($key, null, $idShopGroup, $idShop);
            // basename() keeps a stored value inside img/, and a missing file
            // is null rather than an address that answers 404.
            $file = $file !== '' ? basename($file) : '';
            $logos[$field] = $file !== '' && is_file(_PS_IMG_DIR_ . $file)
                ? (string) $link->getMediaLink(_PS_IMG_ . $file)
                : null;
        }

        $logos['mail_uses_header'] = $logos['mail'] === null;
        $logos['invoice_uses_header'] = $logos['invoice'] === null;

        return $logos;
    }
}
