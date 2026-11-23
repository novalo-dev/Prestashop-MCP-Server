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
 * somcp_update_language - changes one language of International >
 * Localization > Languages, as its Edit page and the Enabled switch of the
 * list do: Name, Language code, the two date formats, RTL language and
 * Status.
 *
 * A shop that opens a second language had its dates printed in the format of
 * the first, and could not switch a language on or off without the back
 * office. The ISO code, the flag and the "No-picture" image move files and
 * translations, and are left to their own parts.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolUpdateLanguage
{
    /**
     * The fields of the list the shop works out: taken only as the list gives them.
     */
    private const COMPUTED = ['iso_code', 'locale', 'default'];

    /**
     * The text fields of the form, with the column of Language they are stored in.
     */
    private const TEXTS = [
        'name' => 'name',
        'language_code' => 'language_code',
        'date_format' => 'date_format_lite',
        'date_format_full' => 'date_format_full',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $computed = ['description' => 'Worked out by the shop: taken only as somcp_list_languages gives it, so a '
            . 'row of that list can be given back whole.'];

        return new SomcpTool(
            'somcp_update_language',
            Somcp::t('Change a language'),
            'Changes one language of International > Localization > Languages, as its Edit page: Name, Language '
                . 'code, Date format, Date format (full), RTL language and Status. Take id_lang from '
                . 'somcp_list_languages; a row of that list can be given back whole, only what differs is written. '
                . 'The default language stays enabled. Not for the texts of a product or a page in that language: '
                . 'the update tool of that object.',
            [
                'type' => 'object',
                'properties' => [
                    'id_lang' => [
                        'type' => 'integer',
                        'description' => 'Required. The language to change (ID), from somcp_list_languages.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Merchant. Name, 32 characters at most, without < > = { }. Left out, kept.',
                    ],
                    'language_code' => [
                        'type' => 'string',
                        'description' => 'Merchant. Language code: the IETF language tag, for example en-us or '
                            . 'pt-br, stored lower case. Left out, kept.',
                    ],
                    'date_format' => [
                        'type' => 'string',
                        'description' => 'Merchant. Date format: the short PHP date format, for example Y-m-d, 32 '
                            . 'characters at most. Left out, kept.',
                    ],
                    'date_format_full' => [
                        'type' => 'string',
                        'description' => 'Merchant. Date format (full): the PHP date and time format, for example '
                            . 'Y-m-d H:i:s. Left out, kept.',
                    ],
                    'is_rtl' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. RTL language: true for a language written right to left. Left '
                            . 'out, kept.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Status: false stops offering the language; the employees who use '
                            . 'it move to the default language. Left out, kept.',
                    ],
                    'iso_code' => ['type' => 'string'] + $computed,
                    'locale' => ['type' => 'string'] + $computed,
                    'default' => ['type' => 'boolean'] + $computed,
                ],
                'required' => ['id_lang'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Editing a language is what its Edit page and the Enabled switch
            // ask for in Team, Permissions; adding one is a different right.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminLanguages')
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
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = SomcpTool::unknownFields(
            $arguments,
            array_merge(['id_lang', 'is_rtl', 'active'], array_keys(self::TEXTS), self::COMPUTED)
        );
        $stored = null;
        $idLang = SomcpTool::id($arguments, 'id_lang');
        if ($idLang === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_lang');
        } else {
            // Only a language the list of the token's shop shows: one tied to
            // another shop is not the merchant's to change from here.
            $stored = SomcpToolListLanguages::language($idShop, $idLang);
            if ($stored === null) {
                $errors[] = SomcpTool::notFound('id_lang', 'language', $idLang, 'somcp_list_languages');
            }
        }
        $default = SomcpToolListLanguages::defaultLanguage($idShop);
        $row = $stored !== null ? SomcpToolListLanguages::row($stored, $default) : null;

        $probe = new Language();
        $set = [];
        $changes = [];
        foreach (self::TEXTS as $field => $column) {
            $value = self::text($arguments, $field, $column, $probe, $errors);
            if ($value !== null && $row !== null && $value !== $row[$field]) {
                $set[$column] = $value;
                self::record($changes, $field, $row[$field], $value);
            }
        }

        foreach (['is_rtl', 'active'] as $field) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            // A boolean only: "false" cast to bool is true, and would keep
            // offering a language the merchant wanted switched off.
            if (!is_bool($arguments[$field])) {
                $errors[] = $field . ': true or false';
                continue;
            }
            if ($row === null || $arguments[$field] === $row[$field]) {
                continue;
            }
            if ($field === 'active' && $arguments[$field] === false && $idLang === $default) {
                // As the back office refuses it: every tool reads and writes
                // in the default when no language is given.
                $errors[] = 'active: ' . $row['name'] . ' is the default language of the shop - it cannot be disabled';
                continue;
            }
            $set[$field] = $arguments[$field];
            self::record($changes, $field, $row[$field], $arguments[$field]);
        }

        // Taken only as the list answers them, so a row given back whole is
        // not refused; any other value would claim a change this tool does
        // not make.
        foreach (self::COMPUTED as $field) {
            if (!array_key_exists($field, $arguments) || $row === null || $arguments[$field] === $row[$field]) {
                continue;
            }
            $errors[] = $field === 'iso_code'
                ? 'iso_code: changed by somcp_update_language part 2, not written yet'
                : $field . ': worked out by the shop';
        }
        if ($errors !== [] || $stored === null || $row === null) {
            return SomcpTool::refused('changed', $errors);
        }

        if ($changes === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_lang' => $idLang,
                'reason' => 'The language already holds every value given. Nothing was written.',
                'language' => $row,
            ]);
        }
        $fields = array_column($changes, 'field');

        // Only the fields that differ are set: the ISO code, the locale and
        // the shops the language is tied to keep what the shop stored.
        $language = new Language($idLang);
        foreach ($set as $column => $value) {
            $language->{$column} = $value;
        }
        $moved = 0;
        try {
            if (($set['active'] ?? null) === false) {
                // The employees of a disabled language move to the default
                // first, as the Edit page does it: a back office in a language
                // the shop no longer offers would fall back without a word.
                $moved = (int) Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'employee` WHERE `id_lang` = ' . (int) $idLang
                );
                if ($moved > 0) {
                    Db::getInstance()->update('employee', ['id_lang' => (int) $default], '`id_lang` = ' . (int) $idLang);
                }
            }
            $check = $language->validateFields(false, true);
            if ($check !== true) {
                $done = false;
                $failure = strip_tags(is_string($check) ? $check : 'a field PrestaShop refuses');
            } else {
                $done = $language->update();
                $failure = $done ? '' : 'PrestaShop refused to save it';
            }
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('language_update_failed', SomcpLogger::OUTCOME_FAILURE, 'language ' . $idLang . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text(
                'The language could not be changed: ' . $failure . '. Read it with somcp_list_languages before '
                    . 'calling again.',
                true
            );
        }
        SomcpLogger::log('language_updated', SomcpLogger::OUTCOME_SUCCESS, 'language ' . $idLang . ': '
            . implode(', ', $fields), '', $idEmployee);
        // The shape of every update tool: changed a boolean, changes what was
        // written with its old value.
        $result = ['changed' => true, 'id_lang' => $idLang, 'changes' => $changes];

        $warnings = [];
        if ($moved > 0) {
            $defaultRow = SomcpToolListLanguages::language($idShop, $default);
            $warnings[] = $moved . ' employees used ' . $row['name'] . ' in the back office; they now use '
                . ($defaultRow !== null ? (string) $defaultRow['name'] : 'the default language') . '.';
        }
        // A language has one row for the whole installation: its name, its
        // formats and its status are those of every shop.
        $otherShops = SomcpToolCreateTax::otherShops();
        if ($otherShops > 0) {
            $result['also_in_other_shops'] = $otherShops;
            $warnings[] = 'A language is shared by every shop: this change reaches every shop of this installation ('
                . ($otherShops + 1) . ').';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $after = SomcpToolListLanguages::language($idShop, $idLang);
        $result['language'] = $after !== null ? SomcpToolListLanguages::row($after, $default) : null;

        return SomcpTool::json($result);
    }

    /**
     * A text field given, trimmed and checked as Language checks it, or null when left out or refused.
     *
     * @param array<string, mixed> $arguments
     * @param string $field the name of the argument
     * @param string $column the field of Language
     * @param Language $probe
     * @param array<int, string> $errors
     *
     * @return string|null
     */
    private static function text(array $arguments, string $field, string $column, Language $probe, array &$errors): ?string
    {
        if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
            return null;
        }
        if (!is_string($arguments[$field])) {
            $errors[] = $field . ': a text';

            return null;
        }
        $value = trim($arguments[$field]);
        if ($field === 'language_code') {
            // Lower case, as the help line of the form asks.
            $value = Tools::strtolower($value);
        }
        if ($value === '') {
            $errors[] = $field . ': required - it cannot be emptied';

            return null;
        }
        $max = SomcpTool::columnSize($probe, $column, 32);
        if (Tools::strlen($value) > $max) {
            $errors[] = $field . ': ' . $max . ' characters at most, ' . Tools::strlen($value) . ' given';

            return null;
        }
        $check = $probe->validateField($column, $value);
        if ($check !== true) {
            $errors[] = $field . ': ' . self::expected($field) . ' - '
                . strip_tags(is_string($check) ? $check : 'invalid');

            return null;
        }

        return $value;
    }

    /**
     * What a text field takes, for the line of a value PrestaShop refuses.
     *
     * @param string $field
     *
     * @return string
     */
    private static function expected(string $field): string
    {
        switch ($field) {
            case 'name':
                return 'a character PrestaShop refuses in a language name (< > = { })';
            case 'language_code':
                return 'an IETF language tag, two letters and optionally a dash and two more, for example en-us';
            default:
                return 'a PHP date format, without < or >';
        }
    }

    /**
     * One entry of changes, as the other update tools record it.
     *
     * @param array<int, array<string, mixed>> $changes
     * @param string $field
     * @param mixed $old
     * @param mixed $new
     *
     * @return void
     */
    private static function record(array &$changes, string $field, $old, $new): void
    {
        $changes[] = ['field' => $field, 'old' => $old, 'new' => $new];
    }
}
