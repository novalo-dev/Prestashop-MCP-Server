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
 * somcp_list_languages - the languages of International > Localization >
 * Languages, with their codes and the shop default.
 *
 * Every translated field of the module is read with a language and written
 * keyed by ISO code, yet the codes were only given as a line of text by
 * somcp_ping, without the disabled languages, the names or the identifiers.
 * A disabled language is listed too, so the assistant can tell the merchant
 * why the other tools refuse it.
 *
 * Under the catalogue scope, as the taxes: languages are read to write the
 * translated fields of the catalogue, by the same assistant and with the
 * same consent.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListLanguages
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_languages',
            Somcp::t('List languages'),
            'Lists the languages of International > Localization > Languages, by identifier as that list: '
                . 'id_lang, name, iso_code, language code, locale, date formats, Enabled, and the shop default. '
                . 'The iso_code is what the language and languages of every other tool take, and only an '
                . 'enabled language is accepted there; a tool reads in the default when language is left out.',
            [
                'type' => 'object',
                'properties' => [
                    'id_lang' => [
                        'type' => 'integer',
                        'description' => 'Keep the language with this identifier (ID), as the filter of the '
                            . 'list: an identifier no language of the shop has gives an empty list.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Part of the language name (Name), case-insensitive. Left out, every '
                            . 'language.',
                    ],
                    'iso_code' => [
                        'type' => 'string',
                        'description' => 'The ISO code of one language (ISO code), exact and case-insensitive, '
                            . 'for example fr.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true for enabled languages only, false for disabled ones only. Left '
                            . 'out, all of them.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Languages are managed on their own back office page, and that is
            // the page a merchant would open to look at them.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminLanguages')
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
        $idShop = (int) Somcp::currentShop()->id;

        // A misspelt filter, or an Enabled that is not a boolean, would answer
        // another list than the one asked for without a word: refused, every
        // problem at once so the call is corrected in one go.
        $errors = SomcpTool::unknownFields($arguments, ['id_lang', 'name', 'iso_code', 'active']);
        $idLang = 0;
        if (array_key_exists('id_lang', $arguments) && $arguments['id_lang'] !== null) {
            $idLang = SomcpTool::id($arguments, 'id_lang');
            if ($idLang === 0) {
                $errors[] = SomcpTool::notAnIdentifier('id_lang');
            }
        }
        if (array_key_exists('active', $arguments) && !is_bool($arguments['active'])) {
            $errors[] = 'active: true or false';
        }
        if ($errors !== []) {
            return SomcpTool::refused('read', $errors);
        }

        // A query rather than Language::getLanguages(), so every filter stays
        // one WHERE; the list of a shop shows the languages lang_shop ties to it.
        $query = new DbQuery();
        $query->select('l.`id_lang`, l.`name`, l.`iso_code`, l.`language_code`, l.`locale`');
        $query->select('l.`date_format_lite`, l.`date_format_full`, l.`active`, l.`is_rtl`');
        $query->from('lang', 'l');
        $query->innerJoin('lang_shop', 'ls', 'ls.`id_lang` = l.`id_lang` AND ls.`id_shop` = ' . (int) $idShop);
        // The ID, Name, ISO code and Enabled (All / Yes / No) filters of the list.
        if ($idLang > 0) {
            $query->where('l.`id_lang` = ' . (int) $idLang);
        }
        $name = isset($arguments['name']) && is_scalar($arguments['name']) ? trim((string) $arguments['name']) : '';
        if ($name !== '') {
            $query->where("l.`name` LIKE '%" . pSQL($name) . "%'");
        }
        $iso = isset($arguments['iso_code']) && is_scalar($arguments['iso_code']) ? strtolower(trim((string) $arguments['iso_code'])) : '';
        if ($iso !== '') {
            $query->where("l.`iso_code` = '" . pSQL($iso) . "'");
        }
        if (isset($arguments['active'])) {
            $query->where('l.`active` = ' . (int) $arguments['active']);
        }
        // Identifier order, as the back office list, so a language sits where
        // the merchant sees it.
        $query->orderBy('l.`id_lang` ASC');

        // Read as somcp_ping reads it: the language every tool falls back to
        // when its language is left out.
        $default = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $languages = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $languages[] = [
                'id_lang' => (int) $row['id_lang'],
                'name' => (string) $row['name'],
                'iso_code' => (string) $row['iso_code'],
                'language_code' => (string) $row['language_code'],
                'locale' => (string) ($row['locale'] ?? ''),
                'date_format' => (string) $row['date_format_lite'],
                'date_format_full' => (string) $row['date_format_full'],
                'active' => (bool) $row['active'],
                'is_rtl' => (bool) $row['is_rtl'],
                'default' => (int) $row['id_lang'] === $default,
            ];
        }

        return SomcpTool::json([
            'languages' => $languages,
            'count' => count($languages),
        ]);
    }
}
