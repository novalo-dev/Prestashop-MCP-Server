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
 * somcp_list_menu_links - the storefront's main menu, as the Selected items of
 * the Menu Top Link panel of the ps_mainmenu configuration page show it, and
 * the custom links of its Link list.
 *
 * The stored configuration is read rather than the module's widget: the
 * widget builds the storefront tree for one customer group and caches it,
 * while the configuration page - which the merchant compares with - shows the
 * stored list of codes. Each code is named through the core classes, as the
 * module's makeMenuOption() does, and a code whose object is gone is reported
 * in skipped[] instead of being hidden as the screen hides it.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListMenuLinks
{
    /** @var string The module that owns the main menu. */
    private const MODULE = 'ps_mainmenu';

    /**
     * The prefixes of the stored codes, with the type returned and the name
     * of the identifier as the list tools give it. ALLMAN and ALLSUP carry no
     * identifier of their own.
     *
     * @var array<string, array{0: string, 1: string|null}>
     */
    private const PREFIXES = [
        'CAT' => ['category', 'id_category'],
        'PRD' => ['product', 'id_product'],
        'CMS' => ['cms_page', 'id_cms'],
        'CMS_CAT' => ['cms_category', 'id_cms_category'],
        'ALLMAN' => ['all_brands', null],
        'MAN' => ['brand', 'id_manufacturer'],
        'ALLSUP' => ['all_suppliers', null],
        'SUP' => ['supplier', 'id_supplier'],
        'LNK' => ['link', 'id_link'],
        'SHOP' => ['shop', 'id_shop'],
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_menu_links',
            Somcp::t('List main menu entries'),
            'Lists the entries of the storefront\'s main menu, the Selected items of the Menu Top Link panel '
                . 'of the Main menu module (ps_mainmenu), in menu order: position (1 for the first), the stored '
                . 'code (item, for example CAT3), its type (category, product, cms_page, cms_category, '
                . 'all_brands, brand, all_suppliers, supplier, link, shop), the identifier of its object under '
                . 'the name its list tool gives (id_category, id_product, id_cms, id_cms_category, '
                . 'id_manufacturer, id_supplier, id_link, id_shop), its label, and for a custom link its url '
                . 'and new_window. Codes whose object no longer exists are in skipped[], as the screen hides '
                . 'them. Also returns every custom link of the shop (custom_links[], the Link list), in the '
                . 'menu or not, with the id_link to give to the menu tools. When ps_mainmenu is not installed '
                . 'or not enabled on this shop, module_active is false and the lists are empty. Do not use it '
                . 'for the shop\'s categories (somcp_list_categories) or its CMS pages (somcp_list_cms_pages).',
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
            // A module's configuration page has no tab of its own: the right
            // that opens it is the Module Manager's.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminModulesSf')
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

        // module_shop for the token's shop: Module::isEnabled() answers for
        // the shop of the global context. Without the module its tables may
        // not exist, so nothing is read.
        $state = SomcpModules::states($idShop)[self::MODULE] ?? null;
        if ($state === null || $state['enable_device'] === null) {
            return SomcpTool::json([
                'module_active' => false,
                'entries' => [],
                'skipped' => [],
                'custom_links' => [],
                'language' => $iso,
            ]);
        }

        $links = self::customLinks($idLang, $idShop);
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        // Falls back from the shop to its group and to the global value, as
        // the module reads it on its configuration page.
        $stored = (string) Configuration::get('MOD_BLOCKTOPMENU_ITEMS', null, $idShopGroup, $idShop);

        $entries = [];
        $skipped = [];
        $inMenu = [];
        foreach (explode(',', $stored) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $entry = self::entry($item, $idLang, $idShop, $links);
            if ($entry === null) {
                $skipped[] = $item;
                continue;
            }
            if ($entry['type'] === 'link') {
                $inMenu[$entry['id_link']] = true;
            }
            $entry = ['position' => count($entries) + 1] + $entry;
            $entries[] = $entry;
        }

        $customLinks = [];
        foreach ($links as $idLink => $link) {
            $customLinks[] = [
                'id_link' => $idLink,
                'label' => $link['label'],
                'url' => $link['url'],
                'new_window' => $link['new_window'],
                'in_menu' => isset($inMenu[$idLink]),
            ];
        }

        return SomcpTool::json([
            'module_active' => true,
            'entries' => $entries,
            'skipped' => $skipped,
            'custom_links' => $customLinks,
            'language' => $iso,
        ]);
    }

    /**
     * One stored code as an entry, or null when its object no longer exists.
     *
     * @param string $item
     * @param int $idLang
     * @param int $idShop
     * @param array<int, array{label: string, url: string, new_window: bool}> $links
     *
     * @return array<string, mixed>|null
     */
    private static function entry(string $item, int $idLang, int $idShop, array $links): ?array
    {
        // The module's own parsing: the letters and underscores before the
        // number are the prefix, so CMS_CAT is never read as CMS.
        if (!preg_match('/^([A-Z_]*)([0-9]+)$/', $item, $match) || !isset(self::PREFIXES[$match[1]])) {
            return null;
        }
        [$type, $idField] = self::PREFIXES[$match[1]];
        $id = (int) $match[2];

        $label = null;
        $url = null;
        $newWindow = null;
        switch ($type) {
            case 'category':
                $object = new Category($id, $idLang, $idShop);
                $label = Validate::isLoadedObject($object) ? $object->name : null;
                break;
            case 'product':
                $object = new Product($id, false, $idLang, $idShop);
                $label = Validate::isLoadedObject($object) ? $object->name : null;
                break;
            case 'cms_page':
                $object = new CMS($id, $idLang, $idShop);
                $label = Validate::isLoadedObject($object) ? $object->meta_title : null;
                break;
            case 'cms_category':
                $object = new CMSCategory($id, $idLang, $idShop);
                $label = Validate::isLoadedObject($object) ? $object->name : null;
                break;
            case 'all_brands':
                $label = 'All brands';
                break;
            case 'all_suppliers':
                $label = 'All suppliers';
                break;
            case 'brand':
                $object = new Manufacturer($id, $idLang);
                $label = Validate::isLoadedObject($object) ? $object->name : null;
                break;
            case 'supplier':
                $object = new Supplier($id, $idLang);
                $label = Validate::isLoadedObject($object) ? $object->name : null;
                break;
            case 'shop':
                $object = new Shop($id);
                $label = Validate::isLoadedObject($object) ? $object->name : null;
                break;
            case 'link':
                if (isset($links[$id])) {
                    $label = $links[$id]['label'];
                    $url = $links[$id]['url'];
                    $newWindow = $links[$id]['new_window'];
                }
                break;
        }
        if ($label === null) {
            return null;
        }

        $entry = ['item' => $item, 'type' => $type];
        if ($idField !== null) {
            $entry[$idField] = $id;
        }
        // A translated field loaded with one language is a string, but an
        // object loaded without one would hold an array per language.
        if (is_array($label)) {
            $label = $label[$idLang] ?? reset($label);
        }
        $entry['label'] = (string) $label;
        $entry['url'] = $url;
        $entry['new_window'] = $newWindow;

        return $entry;
    }

    /**
     * The custom links of the shop, keyed by id_linksmenutop, with their label
     * and address in the language asked for.
     *
     * A link with no label in that language takes the default language's, as
     * the module does when it builds the menu, so a link is never listed
     * without a name.
     *
     * @param int $idLang
     * @param int $idShop
     *
     * @return array<int, array{label: string, url: string, new_window: bool}>
     */
    private static function customLinks(int $idLang, int $idShop): array
    {
        $idDefault = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

        $query = new DbQuery();
        $query->select('l.`id_linksmenutop`, l.`new_window`, ll.`label`, ll.`link`, ld.`label` AS `default_label`, ld.`link` AS `default_link`');
        $query->from('linksmenutop', 'l');
        $query->leftJoin(
            'linksmenutop_lang',
            'll',
            'll.`id_linksmenutop` = l.`id_linksmenutop` AND ll.`id_lang` = ' . $idLang . ' AND ll.`id_shop` = ' . $idShop
        );
        $query->leftJoin(
            'linksmenutop_lang',
            'ld',
            'ld.`id_linksmenutop` = l.`id_linksmenutop` AND ld.`id_lang` = ' . $idDefault . ' AND ld.`id_shop` = ' . $idShop
        );
        // id_shop 0 is a link shared by every shop, as Ps_MenuTopLinks::gets()
        // reads it.
        $query->where('l.`id_shop` IN (0, ' . $idShop . ')');
        $query->orderBy('l.`id_linksmenutop` ASC');

        $links = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $useDefault = (string) ($row['label'] ?? '') === '';
            $links[(int) $row['id_linksmenutop']] = [
                'label' => (string) ($useDefault ? ($row['default_label'] ?? '') : $row['label']),
                'url' => (string) ($useDefault ? ($row['default_link'] ?? '') : ($row['link'] ?? '')),
                'new_window' => (bool) $row['new_window'],
            ];
        }

        return $links;
    }
}
