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
 * somcp_update_menu_link - the Update link panel of the ps_mainmenu
 * configuration page, opened by Edit in the Link list.
 *
 * The form posts every field, so a field left empty there is written empty.
 * This tool reads the link first and changes only what is given: each value
 * is compared with what the shop holds, and a call that would change nothing
 * writes nothing. It extends somcp_add_menu_link so a label or an address is
 * accepted and refused for the same reasons in both tools.
 *
 * Ps_MenuTopLinks::update() only updates rows that exist and writes the shop
 * it is given on the link: a language installed after the link was created
 * gets its row first, and the link's own shop is passed, so a link shared by
 * every shop stays shared.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolAddMenuLink.php';
require_once __DIR__ . '/SomcpToolListMenuLinks.php';

class SomcpToolUpdateMenuLink extends SomcpToolAddMenuLink
{
    /** @var array<int, string> The arguments this tool takes. */
    private const ARGUMENTS = ['id_link', 'languages', 'label', 'url', 'new_window', 'language'];

    /**
     * @var array<int, string> What a row of custom_links of somcp_list_menu_links holds and this tool does
     *                         not write: accepted when it holds what the list reads, so a row read there can be
     *                         given back as it is, and refused with what changes it when it differs
     */
    private const KEPT_AS_READ = ['in_menu', 'translated'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_menu_link',
            Somcp::t('Change a main menu custom link'),
            'Changes one custom link of the storefront\'s main menu, as Edit then Update in the Link list of '
                . 'the Main menu module (ps_mainmenu): its label, url and new_window. Take id_link from '
                . 'custom_links of somcp_list_menu_links, never guessed. Only what is given changes; with '
                . 'languages left out every language is filled, so pass languages to change one. Nothing is '
                . 'written unless the whole call is valid. It never moves the entry. Use somcp_add_menu_link '
                . 'to add an entry, somcp_list_menu_links to read. One link per call. Do not retry on a lost '
                . 'answer: read somcp_list_menu_links first.',
            [
                'type' => 'object',
                'properties' => [
                    'id_link' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'The custom link to change, id_link of custom_links of somcp_list_menu_links.',
                    ],
                    'languages' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'ISO codes of the languages filled, among the shop\'s active languages; '
                            . 'all of them when left out. The other languages keep their texts.',
                    ],
                    'label' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'string'],
                        'description' => 'The text of the menu entry, keyed by ISO code, for example {"en": '
                            . '"Sale", "fr": "Soldes"}; a plain string when one language is filled. Never empty, '
                            . '128 characters at most. Left out: unchanged.',
                    ],
                    'url' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'string'],
                        'description' => 'The address, keyed by ISO code like label; an absolute http or https '
                            . 'address, or a path starting with /. Never empty, 128 characters at most. Left '
                            . 'out: unchanged.',
                    ],
                    'new_window' => [
                        'type' => 'boolean',
                        'description' => 'true to open the link in a new window, false in the same one. Left '
                            . 'out: unchanged.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages for the labels of the '
                            . 'answer. The shop default language when left out.',
                    ],
                    'in_menu' => [
                        'type' => 'boolean',
                        'description' => 'As custom_links of somcp_list_menu_links returns it, accepted unchanged '
                            . 'only: somcp_add_menu_link puts a link in the menu; taking it out is done in Modules > '
                            . 'Module Manager > Main menu > Configure.',
                    ],
                    'translated' => [
                        'type' => 'boolean',
                        'description' => 'As custom_links of somcp_list_menu_links returns it, accepted unchanged '
                            . 'only. false: the default-language label and url given back leave this language empty.',
                    ],
                ],
                'required' => ['id_link'],
                'additionalProperties' => false,
            ],
            [SomcpScope::APPEARANCE_WRITE],
            [self::class, 'handle'],
            // A module's configuration page has no tab of its own: saving it
            // edits the module's configuration, under the Module Manager's
            // right, as somcp_add_menu_link.
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
        $plan = self::planUpdate($arguments, $idLang, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
        }

        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_link' => $plan['id_link'],
                'changes' => [],
                'link' => self::readLink($plan['id_link'], $idLang, $idShop),
                'reason' => 'The custom link already holds every value given. Nothing was written.',
                'language' => $iso,
            ]);
        }

        $idEmployee = (int) ($context['id_employee'] ?? 0);

        return self::asEmployee($idEmployee, static function () use ($plan, $idLang, $iso, $idShop): array {
            return self::applyUpdate($plan, $idLang, $iso, $idShop);
        });
    }

    /**
     * Checks every argument against the link as stored and keeps only what
     * differs, collecting one message per refused field.
     *
     * @param array<string, mixed> $arguments
     * @param int $idLang the language of the answer
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function planUpdate(array $arguments, int $idLang, int $idShop, array &$errors): ?array
    {
        foreach (SomcpTool::unknownFields($arguments, array_merge(self::ARGUMENTS, self::KEPT_AS_READ)) as $problem) {
            $errors[] = $problem;
        }

        if (!array_key_exists('id_link', $arguments)) {
            $errors[] = 'id_link: required, from custom_links of somcp_list_menu_links';

            return null;
        }
        $idLink = SomcpTool::id($arguments, 'id_link');
        if ($idLink === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_link');

            return null;
        }

        $current = self::storedLink($idLink, $idShop);
        if ($current === null) {
            $errors[] = SomcpTool::notFound('id_link', 'custom link', $idLink, 'somcp_list_menu_links');

            return null;
        }

        if (array_intersect(['label', 'url', 'new_window'], array_keys($arguments)) === []) {
            $errors[] = 'label, url or new_window: give at least one, the link has nothing else to change';

            return null;
        }

        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }

        // Given, a text is never empty: the module would store it, and the
        // storefront would show an entry with no name or one leading nowhere.
        $labels = array_key_exists('label', $arguments)
            ? self::texts($arguments, 'label', 'label', $languages, $errors, true) ?? []
            : [];
        $urls = array_key_exists('url', $arguments)
            ? self::texts($arguments, 'url', 'url', $languages, $errors, true) ?? []
            : [];
        self::checkLinkTexts($labels, $urls, $languages['filled'], $errors);

        $newWindow = null;
        if (array_key_exists('new_window', $arguments)) {
            if (!is_bool($arguments['new_window'])) {
                $errors[] = 'new_window: true or false';
            } else {
                $newWindow = $arguments['new_window'];
            }
        }

        $kept = self::keptAsRead($arguments, $idLink, $languages['filled'], $idLang, $idShop, $errors);

        if ($errors !== []) {
            return null;
        }

        // A row read in a language without texts of its own showed the
        // default language's: given back, those texts are what the list
        // showed, not a translation, so that language is left as it is.
        if ($kept !== null) {
            [$idLangRead, $shown] = $kept;
            if (($labels[$idLangRead] ?? null) === $shown['label']) {
                unset($labels[$idLangRead]);
            }
            if (($urls[$idLangRead] ?? null) === $shown['url']) {
                unset($urls[$idLangRead]);
            }
        }

        $changes = [];
        $merged = $current;
        foreach (['label' => $labels, 'url' => $urls] as $field => $texts) {
            foreach ($texts as $idLangFilled => $text) {
                $old = $current['texts'][$idLangFilled][$field] ?? null;
                if ($old === $text) {
                    continue;
                }
                $merged['texts'][$idLangFilled][$field] = $text;
                $changes[] = ['field' => $field . ' (' . $languages['filled'][$idLangFilled] . ')', 'old' => $old, 'new' => $text];
            }
        }
        if ($newWindow !== null && $newWindow !== $current['new_window']) {
            $merged['new_window'] = $newWindow;
            $changes[] = ['field' => 'new_window', 'old' => $current['new_window'], 'new' => $newWindow];
        }

        return [
            'id_link' => $idLink,
            'current' => $current,
            'merged' => $merged,
            'fallback' => (int) $languages['fallback'],
            'changes' => $changes,
        ];
    }

    /**
     * Checks in_menu and translated against what somcp_list_menu_links reads
     * for the link, so a row given back whole is not refused for them.
     *
     * The row was read in one language: the one filled when a single one is,
     * otherwise the language of the answer.
     *
     * @param array<string, mixed> $arguments
     * @param int $idLink
     * @param array<int, string> $filled ISO codes of the languages filled, by language identifier
     * @param int $idLang
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array{0: int, 1: array{label: string, url: string}}|null the language read and the
     *                                                                  default-language texts it showed,
     *                                                                  when translated is false
     */
    private static function keptAsRead(array $arguments, int $idLink, array $filled, int $idLang, int $idShop, array &$errors): ?array
    {
        $given = array_intersect_key($arguments, array_flip(self::KEPT_AS_READ));
        if ($given === []) {
            return null;
        }
        $idLangRead = count($filled) === 1 ? (int) array_key_first($filled) : $idLang;

        $read = null;
        foreach (SomcpToolListMenuLinks::menu($idLangRead, $idShop)['custom_links'] as $link) {
            if ($link['id_link'] === $idLink) {
                $read = $link;
            }
        }
        if ($read === null) {
            return null;
        }

        if (array_key_exists('in_menu', $given)) {
            if (!is_bool($given['in_menu'])) {
                $errors[] = 'in_menu: true or false';
            } elseif ($given['in_menu'] !== $read['in_menu']) {
                // Each direction names what does it: no tool takes an entry out of the menu yet,
                // so sending that case to somcp_add_menu_link would have the assistant add instead.
                $errors[] = $given['in_menu']
                    ? 'in_menu: not changed by this tool - put the link in the menu with somcp_add_menu_link'
                    : 'in_menu: not changed by this tool - taking a link out of the menu is done in '
                        . 'Modules > Module Manager > Main menu > Configure';
            }
        }

        if (!array_key_exists('translated', $given)) {
            return null;
        }
        if (!is_bool($given['translated'])) {
            $errors[] = 'translated: true or false';

            return null;
        }
        if ($given['translated'] !== $read['translated']) {
            $errors[] = 'translated: worked out by the shop - false when the link has no label in the language '
                . 'read and shows the default language\'s';

            return null;
        }

        return $read['translated'] ? null : [$idLangRead, ['label' => (string) $read['label'], 'url' => (string) $read['url']]];
    }

    /**
     * The link as stored: its shop, its window and its texts per language,
     * read raw rather than through Ps_MenuTopLinks::getLinkLang(), which
     * escapes them for display and would make every text look changed.
     *
     * @param int $idLink
     * @param int $idShop
     *
     * @return array{id_shop: int, new_window: bool, texts: array<int, array{label: string, url: string}>}|null
     */
    private static function storedLink(int $idLink, int $idShop): ?array
    {
        // id_shop 0 is a link shared by every shop, as Ps_MenuTopLinks::gets()
        // reads it; a link of another shop is unknown here.
        $row = Db::getInstance()->getRow(
            'SELECT `id_shop`, `new_window` FROM `' . _DB_PREFIX_ . 'linksmenutop`'
                . ' WHERE `id_linksmenutop` = ' . $idLink . ' AND `id_shop` IN (0, ' . $idShop . ')'
        );
        if (!is_array($row)) {
            return null;
        }
        $linkShop = (int) $row['id_shop'];

        $query = new DbQuery();
        $query->select('`id_lang`, `id_shop`, `label`, `link`');
        $query->from('linksmenutop_lang');
        $query->where('`id_linksmenutop` = ' . $idLink);

        $texts = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $line) {
            $idLang = (int) $line['id_lang'];
            // update() matches the language alone, so any row of it counts;
            // the row of the link's own shop wins when there are several.
            if (isset($texts[$idLang]) && (int) $line['id_shop'] !== $linkShop) {
                continue;
            }
            $texts[$idLang] = ['label' => (string) $line['label'], 'url' => (string) $line['link']];
        }

        return ['id_shop' => $linkShop, 'new_window' => (bool) $row['new_window'], 'texts' => $texts];
    }

    /**
     * Writes the link through the module and answers with it read back.
     *
     * @param array<string, mixed> $plan
     * @param int $idLang
     * @param string $iso
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    private static function applyUpdate(array $plan, int $idLang, string $iso, int $idShop): array
    {
        if (!self::linksClass()) {
            return SomcpTool::text('The Main menu module could not change the link. Nothing was changed.', true);
        }

        $idLink = (int) $plan['id_link'];
        $linkShop = (int) $plan['current']['id_shop'];
        $texts = $plan['merged']['texts'];
        $fallback = $texts[$plan['fallback']] ?? (reset($texts) ?: ['label' => '', 'url' => '']);

        // update() only runs UPDATE: a language without a row would lose its
        // text without a word, so the row is created first, with the columns
        // Ps_MenuTopLinks::add() writes.
        foreach ($texts as $idLangRow => $text) {
            if (isset($plan['current']['texts'][$idLangRow])) {
                continue;
            }
            $label = $text['label'] ?? $fallback['label'] ?? '';
            $url = $text['url'] ?? $fallback['url'] ?? '';
            $texts[$idLangRow] = ['label' => $label, 'url' => $url];
            Db::getInstance()->insert('linksmenutop_lang', [
                'id_linksmenutop' => $idLink,
                'id_lang' => (int) $idLangRow,
                'id_shop' => $linkShop,
                'label' => pSQL($label),
                'link' => pSQL($url),
            ]);
        }

        // Every language with its full text: what is not given is sent as
        // it is stored, never half-filled as the form would post it.
        $labels = [];
        $urls = [];
        foreach ($texts as $idLangRow => $text) {
            $labels[(int) $idLangRow] = (string) ($text['label'] ?? '');
            $urls[(int) $idLangRow] = (string) ($text['url'] ?? '');
        }

        // Called by name: the class belongs to the module, which is not part
        // of the PrestaShop sources the module is analysed against. It returns
        // nothing, so the link is read back to tell whether it was written.
        call_user_func(
            ['Ps_MenuTopLinks', 'update'],
            $urls,
            $labels,
            $plan['merged']['new_window'] ? 1 : 0,
            $linkShop,
            $idLink
        );

        $stored = self::storedLink($idLink, $idShop);
        $written = false;
        if ($stored !== null) {
            $written = $stored['new_window'] === $plan['merged']['new_window'];
            foreach ($labels as $idLangRow => $label) {
                $row = $stored['texts'][$idLangRow] ?? null;
                if ($row === null || $row['label'] !== $label || $row['url'] !== $urls[$idLangRow]) {
                    $written = false;
                }
            }
        }
        if (!$written) {
            return SomcpTool::text(
                'The custom link could not be changed, or only in part. Read somcp_list_menu_links, tell the '
                    . 'merchant what the link holds now, and do not call again before.',
                true
            );
        }

        self::clearMenuCache();

        return SomcpTool::json([
            'changed' => true,
            'id_link' => $idLink,
            'changes' => $plan['changes'],
            'link' => self::readLink($idLink, $idLang, $idShop),
            'language' => $iso,
        ]);
    }

    /**
     * The link as custom_links of somcp_list_menu_links lists it.
     *
     * @param int $idLink
     * @param int $idLang
     * @param int $idShop
     *
     * @return array<string, mixed>|null
     */
    private static function readLink(int $idLink, int $idLang, int $idShop): ?array
    {
        foreach (SomcpToolListMenuLinks::menu($idLang, $idShop)['custom_links'] as $link) {
            if ($link['id_link'] === $idLink) {
                return $link;
            }
        }

        return null;
    }
}
