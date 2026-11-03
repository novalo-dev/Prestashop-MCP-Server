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
 * somcp_update_theme_setting - the layout of one page of the theme of the
 * token's shop, one row of Design > Theme & Logo > Choose layouts then Save.
 *
 * The core setter (Theme::setPageLayouts) replaces every stored layout with
 * the array it is given, and the screen always sends every row. This tool
 * changes one row, so it reads the stored layouts, sets the one page and
 * hands the whole array back: no other page loses its own setting. The file
 * is written by ThemeManager::saveTheme() for the token's shop only, and the
 * Smarty cache cleared afterwards, as ThemePageLayoutsCustomizer does.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// theme loading and the employee switch below are theirs.
require_once __DIR__ . '/SomcpToolGetTheme.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';

class SomcpToolUpdateThemeSetting
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_theme_setting',
            Somcp::t('Change the layout of a page'),
            'Sets the layout of one page of the shop\'s theme, as one row of Design > Theme & Logo > Choose layouts '
                . 'then Save does: page is the page name as somcp_get_theme gives it in pages[].page (index, '
                . 'category, product, contact...), layout one of the keys of available_layouts[].layout of '
                . 'somcp_get_theme, for example layout-left-column. Read the theme first with somcp_get_theme and '
                . 'confirm the new layout with the merchant before calling. One call changes one page; every other '
                . 'page keeps its setting. A call that would change nothing writes nothing and says changed: false. '
                . 'The answer gives previous_layout (the default layout when the page had none of its own) and '
                . 'the layout now set. Not to be retried: if the answer is lost, read somcp_get_theme again before '
                . 'calling. Do not use it to install or switch themes, upload a logo (Design > Theme & Logo) or '
                . 'change the title or URL of a page (somcp_update_meta_page).',
            [
                'type' => 'object',
                'properties' => [
                    'page' => [
                        'type' => 'string',
                        'description' => 'The page to change, its name as somcp_get_theme gives it in pages[].page '
                            . 'and somcp_list_meta_pages in page, for example index or product.',
                    ],
                    'layout' => [
                        'type' => 'string',
                        'description' => 'The layout to set, a key of available_layouts[].layout of '
                            . 'somcp_get_theme, for example layout-full-width; a key the theme does not offer is '
                            . 'refused.',
                    ],
                ],
                'required' => ['page', 'layout'],
                'additionalProperties' => false,
            ],
            [SomcpScope::APPEARANCE_WRITE],
            [self::class, 'handle'],
            // Saving Choose layouts takes the edit right on the themes page.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminThemes')
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

        // The back office refuses to save the layouts in demo mode.
        if (SomcpBackOffice::constant('_PS_MODE_DEMO_')) {
            return SomcpTool::text('This shop runs in demo mode, where the theme cannot be changed. Nothing was changed.', true);
        }

        $theme = SomcpToolGetTheme::shopTheme();
        if ($theme === null) {
            return SomcpToolGetTheme::themeMissing();
        }

        $errors = [];
        foreach (array_diff(array_keys($arguments), ['page', 'layout']) as $key) {
            $errors[] = $key . ': not a field of this tool';
        }

        $page = is_string($arguments['page'] ?? null) ? trim($arguments['page']) : '';
        if ($page === '') {
            $errors[] = 'page: required, a page name from pages[].page of somcp_get_theme';
        } elseif (!in_array($page, self::pages($idShop), true)) {
            $errors[] = 'page: "' . $page . '" is not a page of Choose layouts; take it from pages[].page of somcp_get_theme';
        }

        $offered = array_map('strval', array_keys((array) $theme->getAvailableLayouts()));
        $layout = is_string($arguments['layout'] ?? null) ? trim($arguments['layout']) : '';
        if ($layout === '') {
            $errors[] = 'layout: required, one of ' . implode(', ', $offered);
        } elseif (!in_array($layout, $offered, true)) {
            $errors[] = 'layout: "' . $layout . '" is not offered by the theme ' . $theme->getName()
                . '; it offers ' . implode(', ', $offered);
        }

        if ($errors !== []) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
        }

        $layouts = (array) $theme->getPageLayouts();
        $own = isset($layouts[$page]) && $layouts[$page] !== '' ? (string) $layouts[$page] : null;
        $previous = $own ?? (string) $theme->get('theme_settings.default_layout');

        if ($own === $layout || ($own === null && $previous === $layout)) {
            return SomcpTool::json([
                'changed' => false,
                'page' => $page,
                'layout' => $layout,
                'theme' => (string) $theme->getName(),
                'reason' => 'The page already uses this layout. Nothing was written.',
            ]);
        }

        // The setter replaces the whole array: every other page is handed back
        // with the value it had.
        $layouts[$page] = $layout;
        $theme->setPageLayouts($layouts);

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($theme, $page, $previous, $layout): array {
            $legacy = Somcp::legacyContext();
            try {
                // build() writes into config/themes/<theme>/shop<id>.json of
                // the context shop, the token's, and of no other shop.
                $manager = (new PrestaShop\PrestaShop\Core\Addon\Theme\ThemeManagerBuilder($legacy, Db::getInstance()))->build();
                $manager->saveTheme($theme);
            } catch (Exception $e) {
                return SomcpTool::text(
                    'The layouts of the theme could not be saved on the server. Nothing was changed.',
                    true
                );
            }
            // The storefront caches its rendered pages: without this the old
            // columns would keep showing.
            Tools::clearSmartyCache();

            return SomcpTool::json([
                'changed' => true,
                'page' => $page,
                'previous_layout' => $previous,
                'layout' => $layout,
                'theme' => (string) $theme->getName(),
            ]);
        });
    }

    /**
     * The page names of the rows of Choose layouts for this shop.
     *
     * @param int $idShop
     *
     * @return array<int, string>
     */
    private static function pages(int $idShop): array
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $pages = [];
        foreach (Meta::getAllMeta($idLang) ?: [] as $meta) {
            $pages[] = (string) $meta['page'];
        }

        return $pages;
    }
}
