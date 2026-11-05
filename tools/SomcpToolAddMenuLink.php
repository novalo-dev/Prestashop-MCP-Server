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
 * somcp_add_menu_link - the Add button of the Menu Top Link panel of the
 * ps_mainmenu configuration page, followed by Save, and for a new custom link
 * the Add a new link panel first.
 *
 * The list is written the way Save writes it, on the token's shop row only,
 * and read back through somcp_list_menu_links so both tools answer with the
 * same shape. A new custom link is put in the menu in the same call: outside
 * the menu it shows nowhere on the storefront. The module accepts an empty
 * label and an empty link, so this tool checks them itself.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends, and it reads the
// menu as somcp_list_menu_links does.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolListMenuLinks.php';

class SomcpToolAddMenuLink extends SomcpToolCreateProduct
{
    /** @var int The size of the label and link columns of linksmenutop_lang. */
    protected const TEXT_MAX = 128;

    /** @var array<int, string> The arguments a new custom link is made of. */
    private const LINK_FIELDS = ['languages', 'label', 'url', 'new_window'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $ids = static function (string $description): array {
            return ['type' => 'integer', 'minimum' => 1, 'description' => $description];
        };

        return new SomcpTool(
            'somcp_add_menu_link',
            Somcp::t('Add a main menu entry'),
            'Adds one entry to the storefront\'s main menu, as Add then Save in the Menu Top Link panel of the '
                . 'Main menu module (ps_mainmenu), at the end or at a given position. type says what it points '
                . 'to; give the matching id_* from the list tool its field names, none for all_brands and '
                . 'all_suppliers. For type link without id_link it creates the custom link from label '
                . 'and url. An entry already in the menu is refused; a custom link once created is changed '
                . 'with somcp_update_menu_link, the menu read with somcp_list_menu_links. One entry per call. '
                . 'Do not retry on a lost answer: read somcp_list_menu_links.',
            [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'enum' => array_values(array_map(static function (array $prefix): string {
                            return $prefix[0];
                        }, SomcpToolListMenuLinks::PREFIXES)),
                        'description' => 'What the entry points to, the type of entries[] of somcp_list_menu_links.',
                    ],
                    'id_category' => $ids('type category: the category, from somcp_list_categories.'),
                    'id_product' => $ids('type product: the product, from somcp_search_products.'),
                    'id_cms' => $ids('type cms_page: the CMS page, from somcp_list_cms_pages.'),
                    'id_cms_category' => $ids('type cms_category: the CMS category, from somcp_list_cms_pages.'),
                    'id_manufacturer' => $ids('type brand: the brand, from somcp_list_manufacturers.'),
                    'id_supplier' => $ids('type supplier: the supplier, from somcp_list_suppliers.'),
                    'id_link' => $ids('type link: an existing custom link, id_link of custom_links of '
                        . 'somcp_list_menu_links. Leave out to create a new one from label and url.'),
                    'id_shop' => $ids('type shop: the shop, on a multistore installation.'),
                    'languages' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'New custom link only: ISO codes of the languages filled, among the '
                            . 'shop\'s active languages; all of them when left out. A language left out takes '
                            . 'the text of the default one.',
                    ],
                    'label' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'string'],
                        'description' => 'New custom link only, required: the text of the menu entry, keyed by '
                            . 'ISO code, for example {"en": "Sale", "fr": "Soldes"}. 128 characters at most.',
                    ],
                    'url' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'string'],
                        'description' => 'New custom link only, required: the address, keyed by ISO code like '
                            . 'label; an absolute http or https address, or a path starting with /. 128 '
                            . 'characters at most.',
                    ],
                    'new_window' => [
                        'type' => 'boolean',
                        'description' => 'New custom link only: true to open it in a new window. false when '
                            . 'left out.',
                    ],
                    'position' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'Where the entry goes, 1 for the first, as position in '
                            . 'somcp_list_menu_links. At the end when left out.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages for the labels of the '
                            . 'answer. The shop default language when left out.',
                    ],
                ],
                'required' => ['type'],
                'additionalProperties' => false,
            ],
            [SomcpScope::APPEARANCE_WRITE],
            [self::class, 'handle'],
            // A module's configuration page has no tab of its own: saving it
            // edits the module's configuration, under the Module Manager's
            // right, as somcp_set_module_active.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminModulesSf')
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
        // The shop comes from the token, never from the caller.
        $idShop = Somcp::currentShopId();

        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;

        if (!SomcpToolListMenuLinks::moduleActive($idShop)) {
            return SomcpTool::text(
                'The Main menu module (ps_mainmenu) is not installed or not enabled on this shop, so the '
                    . 'storefront has no main menu from it. Nothing was changed.',
                true
            );
        }

        $errors = [];
        $plan = self::plan($arguments, $idLang, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
        }

        $idEmployee = (int) ($context['id_employee'] ?? 0);

        return self::asEmployee($idEmployee, static function () use ($plan, $idLang, $iso, $idShop): array {
            return self::apply($plan, $idLang, $iso, $idShop);
        });
    }

    /**
     * Checks every argument and says what to write, collecting one message
     * per refused field.
     *
     * @param array<string, mixed> $arguments
     * @param int $idLang
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function plan(array $arguments, int $idLang, int $idShop, array &$errors): ?array
    {
        $idFields = [];
        $prefixes = [];
        foreach (SomcpToolListMenuLinks::PREFIXES as $prefix => [$type, $idField]) {
            $prefixes[$type] = [$prefix, $idField];
            if ($idField !== null) {
                $idFields[] = $idField;
            }
        }

        $known = array_merge(['type', 'position', 'language'], $idFields, self::LINK_FIELDS);
        foreach (array_diff(array_keys($arguments), $known) as $key) {
            $errors[] = $key . ': not a field of this tool';
        }

        $type = $arguments['type'] ?? null;
        if (!is_string($type) || !isset($prefixes[$type])) {
            $errors[] = 'type: one of ' . implode(', ', array_keys($prefixes));

            return null;
        }
        [$prefix, $idField] = $prefixes[$type];

        // An identifier meant for another type would be silently ignored,
        // and the assistant would believe it had been used.
        foreach ($idFields as $field) {
            if ($field !== $idField && array_key_exists($field, $arguments)) {
                $errors[] = $field . ': does not go with type ' . $type
                    . ($idField !== null ? ', which takes ' . $idField : ', which takes no identifier');
            }
        }

        $newLink = $type === 'link' && !array_key_exists('id_link', $arguments);
        if (!$newLink) {
            foreach (self::LINK_FIELDS as $field) {
                if (array_key_exists($field, $arguments)) {
                    $errors[] = $field . ($type === 'link'
                        ? ': only for a new custom link, not with id_link, whose texts this tool does not change'
                        : ': only for a new custom link, type link');
                }
            }
        }

        $id = 0;
        if ($idField !== null && !$newLink) {
            $value = $arguments[$idField] ?? null;
            if (is_string($value) && ctype_digit($value)) {
                $value = (int) $value;
            }
            if (!is_int($value) || $value < 1) {
                $errors[] = $idField . ': required with type ' . $type . ', a positive integer';

                return null;
            }
            $id = $value;
        }

        $items = SomcpToolListMenuLinks::storedItems($idShop);
        $links = SomcpToolListMenuLinks::customLinks($idLang, $idShop);

        $item = null;
        if (!$newLink) {
            $item = $prefix . $id;
            // The module's own lookup names the object, so whatever it cannot
            // name would show nowhere on the storefront either.
            if (SomcpToolListMenuLinks::entry($item, $idLang, $idShop, $links) === null) {
                $errors[] = $idField . ': no ' . str_replace('_', ' ', $type) . ' ' . $id . ' on this shop';
            } elseif (in_array($item, $items, true)) {
                // The screen no longer offers what is already selected.
                $errors[] = ($idField ?? 'type') . ': already in the menu (' . $item . '), nothing to add';
            }
        }

        $count = 0;
        foreach ($items as $stored) {
            if (SomcpToolListMenuLinks::entry($stored, $idLang, $idShop, $links) !== null) {
                ++$count;
            }
        }
        $position = $count + 1;
        if (array_key_exists('position', $arguments)) {
            $value = $arguments['position'];
            if (!is_int($value) || $value < 1 || $value > $count + 1) {
                $errors[] = 'position: an integer from 1 to ' . ($count + 1) . ', the menu having ' . $count . ' entries';
            } else {
                $position = $value;
            }
        }

        $link = $newLink ? self::newLink($arguments, $idShop, $errors) : null;

        if ($errors !== []) {
            return null;
        }

        return ['item' => $item, 'position' => $position, 'link' => $link, 'id_field' => $idField];
    }

    /**
     * The label, address and window of a new custom link, one text per
     * language of the shop.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array{labels: array<int, string>, urls: array<int, string>, new_window: bool}|null
     */
    private static function newLink(array $arguments, int $idShop, array &$errors): ?array
    {
        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }

        $newWindow = $arguments['new_window'] ?? false;
        if (!is_bool($newWindow)) {
            $errors[] = 'new_window: true or false';
        }

        // Required in every language filled: the module stores an empty
        // label or link without a word, and the storefront then shows an
        // entry with no name or one that leads nowhere.
        $labels = self::texts($arguments, 'label', 'label', $languages, $errors, true);
        $urls = self::texts($arguments, 'url', 'url', $languages, $errors, true);
        if ($labels === null || $urls === null) {
            return null;
        }

        self::checkLinkTexts($labels, $urls, $languages['filled'], $errors);

        // Every language gets a row, as the form posts them all: a language
        // left out takes the fallback's text rather than an empty entry.
        $allLabels = [];
        $allUrls = [];
        foreach ($languages['all'] as $idLang) {
            $allLabels[$idLang] = $labels[$idLang] ?? ($labels[$languages['fallback']] ?? '');
            $allUrls[$idLang] = $urls[$idLang] ?? ($urls[$languages['fallback']] ?? '');
        }

        return ['labels' => $allLabels, 'urls' => $allUrls, 'new_window' => $newWindow === true];
    }

    /**
     * Trims the labels and addresses of a custom link and checks them - shared
     * with somcp_update_menu_link, so a text accepted at creation is accepted
     * when it is changed, and refused for the same reason.
     *
     * The emptiness of a text given is checked by texts(); what is left is
     * what the module never checks: the size of its columns and the form of
     * the address.
     *
     * @param array<int, string> $labels keyed by id_lang
     * @param array<int, string> $urls keyed by id_lang
     * @param array<int, string> $isos the ISO code of each language filled, keyed by id_lang
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function checkLinkTexts(array &$labels, array &$urls, array $isos, array &$errors): void
    {
        foreach ($labels as $idLang => $label) {
            $label = trim($label);
            $labels[$idLang] = $label;
            if (Tools::strlen($label) > self::TEXT_MAX) {
                $errors[] = 'label (' . $isos[$idLang] . '): ' . self::TEXT_MAX . ' characters at most, '
                    . Tools::strlen($label) . ' given';
            }
        }
        foreach ($urls as $idLang => $url) {
            $url = trim($url);
            $urls[$idLang] = $url;
            $where = 'url (' . $isos[$idLang] . ')';
            if ($url === '') {
                continue;
            }
            if (!preg_match('#^(https?://[^/\s]+|/)#i', $url) || !Validate::isUrl($url)) {
                $errors[] = $where . ': an absolute http or https address, or a path starting with /';
            } elseif (Tools::strlen($url) > self::TEXT_MAX) {
                $errors[] = $where . ': ' . self::TEXT_MAX . ' characters at most, ' . Tools::strlen($url) . ' given';
            }
        }
    }

    /**
     * Loads the module's class for its custom links, which only the module
     * itself requires.
     *
     * @return bool whether the class can be called
     */
    protected static function linksClass(): bool
    {
        $class = _PS_MODULE_DIR_ . SomcpToolListMenuLinks::MODULE . '/ps_menutoplinks.class.php';
        if (!class_exists('Ps_MenuTopLinks') && is_file($class)) {
            require_once $class;
        }

        return class_exists('Ps_MenuTopLinks');
    }

    /**
     * Empties the module's cache of the storefront menu, so the change shows at
     * once - through its public hook, the way the module empties it itself.
     *
     * @return void
     */
    protected static function clearMenuCache(): void
    {
        $module = Module::getInstanceByName(SomcpToolListMenuLinks::MODULE);
        if ($module && method_exists($module, 'hookActionCategoryUpdate')) {
            $module->hookActionCategoryUpdate([]);
        }
    }

    /**
     * Creates the custom link when asked, writes the list as Save does and
     * answers with the menu read back.
     *
     * @param array<string, mixed> $plan
     * @param int $idLang
     * @param string $iso
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    private static function apply(array $plan, int $idLang, string $iso, int $idShop): array
    {
        $item = $plan['item'];
        $createdLink = false;
        if ($plan['link'] !== null) {
            if (!self::linksClass()) {
                return SomcpTool::text('The Main menu module could not create the link. Nothing was changed.', true);
            }

            // The module answers true or false, not the new identifier: the
            // highest one before and after the insert tells which it is.
            $highest = static function (): int {
                return (int) Db::getInstance()->getValue(
                    'SELECT MAX(`id_linksmenutop`) FROM `' . _DB_PREFIX_ . 'linksmenutop`'
                );
            };
            $before = $highest();
            // Called by name: the class belongs to the module, which is not
            // part of the PrestaShop sources the module is analysed against.
            $done = (bool) call_user_func(
                ['Ps_MenuTopLinks', 'add'],
                $plan['link']['urls'],
                $plan['link']['labels'],
                $plan['link']['new_window'] ? 1 : 0,
                $idShop
            );
            $idLink = $highest();
            if (!$done || $idLink <= $before) {
                return SomcpTool::text('The Main menu module could not create the link. Nothing was changed.', true);
            }
            $item = 'LNK' . $idLink;
            $createdLink = true;
        }

        // Re-read just before writing: the list is stored whole, so whatever
        // changed since the checks is kept rather than overwritten.
        $items = SomcpToolListMenuLinks::storedItems($idShop);
        $links = SomcpToolListMenuLinks::customLinks($idLang, $idShop);
        $index = count($items);
        $visible = 0;
        foreach ($items as $i => $stored) {
            if (SomcpToolListMenuLinks::entry($stored, $idLang, $idShop, $links) === null) {
                continue;
            }
            ++$visible;
            if ($visible === $plan['position']) {
                $index = $i;
                break;
            }
        }
        // Codes the screen hides stay where they are, as Save keeps them.
        array_splice($items, $index, 0, [$item]);

        // The shop's own row, never the global one: on a multistore
        // installation that would change the menu of every shop.
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        if (!Configuration::updateValue('MOD_BLOCKTOPMENU_ITEMS', implode(',', $items), false, $idShopGroup, $idShop)) {
            return SomcpTool::text(
                'The menu could not be saved' . ($createdLink ? ', but the custom link ' . substr($item, 3)
                    . ' was created: add it with id_link' : '') . '. Read somcp_list_menu_links to check.',
                true
            );
        }

        self::clearMenuCache();

        $menu = SomcpToolListMenuLinks::menu($idLang, $idShop);
        $added = null;
        foreach ($menu['entries'] as $entry) {
            if ($entry['item'] === $item) {
                $added = $entry;
                break;
            }
        }

        return SomcpTool::json([
            'added' => $added,
            'created_link' => $createdLink,
            'entries' => $menu['entries'],
            'language' => $iso,
        ]);
    }
}
