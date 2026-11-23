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
 * list do: Name, ISO code, Language code, the two date formats, RTL language,
 * Status, Flag and "No-picture" image.
 *
 * A shop that opens a second language had its dates printed in the format of
 * the first, and could not switch a language on or off without the back
 * office. The flag and the "No-picture" image are taken as every image of the
 * module is.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolUpdateLanguage
{
    /**
     * The fields of the list the shop works out: taken only as the list gives them.
     */
    private const COMPUTED = ['locale', 'default'];

    /**
     * The text fields of the form, with the column of Language they are stored in.
     */
    private const TEXTS = [
        'name' => 'name',
        'iso_code' => 'iso_code',
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
            'Changes one language of International > Localization > Languages, as its Edit page: Name, ISO code, '
                . 'Language code, Date format, Date format (full), RTL language, Status, Flag and "No-picture" image. Take id_lang from '
                . 'somcp_list_languages; a row of that list can be given back whole, only what differs is written. '
                . 'The default language stays enabled. Not for the texts of a product or a page in that language: '
                . 'the update tool of that object. To delete one, somcp_delete_language.',
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
                    'flag' => SomcpUpload::schema('Merchant. Flag: a new picture, which replaces the current one.', 'the flag'),
                    'no_picture' => SomcpUpload::schema('Merchant. Shown in this language where a product has no picture.', 'the image'),
                    'iso_code' => [
                        'type' => 'string',
                        'description' => 'Merchant. ISO code: two letters, for example fr, stored lower case; the '
                            . 'translation files and e-mail templates of the language move with it. Left out, kept.',
                    ],
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
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminLanguages'),
            files: ['flag' => SomcpUpload::images(), 'no_picture' => SomcpUpload::images()]
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
            array_merge(['id_lang', 'is_rtl', 'active', 'flag', 'no_picture'], array_keys(self::TEXTS), self::COMPUTED)
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
        $row = $stored !== null ? SomcpToolListLanguages::row($stored, $default, $idShop) : null;

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

        if (isset($set['iso_code'])) {
            // As the back office refuses it: two languages with one code would
            // share their translation and e-mail folders.
            $owner = (int) Language::getIdByIso($set['iso_code'], true);
            if ($owner > 0 && $owner !== $idLang) {
                $errors[] = 'iso_code: ' . $set['iso_code'] . ' is already the code of '
                    . (string) (new Language($owner))->name . ' (' . $owner . ')';
            } else {
                // The locale follows the code when the shop knows it, as the
                // Edit page sets it.
                $details = Language::getLangDetails($set['iso_code']);
                $locale = is_array($details) ? (string) ($details['locale'] ?? '') : '';
                if ($locale !== '' && $row !== null && $locale !== $row['locale']) {
                    $set['locale'] = $locale;
                    self::record($changes, 'locale', $row['locale'], $locale);
                }
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

        // The flag the list answers for this language is no change: nothing
        // is downloaded for it.
        $flag = null;
        if (array_key_exists('flag', $arguments)
            && !SomcpUpload::same($arguments['flag'], 'language_flag', (string) $idLang)) {
            $flag = SomcpUpload::argument($arguments, 'flag', $errors, 'flag');
            if ($flag !== null && $row !== null) {
                self::record($changes, 'flag', $row['flag'], SomcpUpload::origin($flag));
            }
        }

        // Nothing reads the "No-picture" image back, so a value given is
        // never the stored one: it is always written.
        $noPicture = null;
        if (array_key_exists('no_picture', $arguments) && $arguments['no_picture'] !== null) {
            $noPicture = SomcpUpload::argument($arguments, 'no_picture', $errors, 'no-picture image');
            if ($noPicture !== null && $row !== null) {
                self::record($changes, 'no_picture', null, SomcpUpload::origin($noPicture));
            }
        }

        // Taken only as the list answers them, so a row given back whole is
        // not refused; any other value would claim a change this tool does
        // not make.
        foreach (self::COMPUTED as $field) {
            if (!array_key_exists($field, $arguments) || $row === null || $arguments[$field] === $row[$field]) {
                continue;
            }
            $errors[] = $field . ': worked out by the shop';
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

        return self::apply($idShop, $idLang, $idEmployee, $default, $row, $set, $changes, ['flag' => $flag, 'no_picture' => $noPicture]);
    }

    /**
     * Fetches the pictures given, then writes.
     *
     * The pictures are fetched before anything is written: one the shop
     * refuses must not leave the other fields half done.
     *
     * @param int $idShop
     * @param int $idLang
     * @param int $idEmployee
     * @param int $default the identifier of the shop default language
     * @param array<string, mixed> $row the language as the list answered it before
     * @param array<string, mixed> $set the columns of Language to set
     * @param array<int, array<string, mixed>> $changes
     * @param array<string, array{url: string, upload_ref: string}|null> $sources the pictures given, by field
     *
     * @return array<string, mixed>
     */
    private static function apply(int $idShop, int $idLang, int $idEmployee, int $default, array $row, array $set, array $changes, array $sources): array
    {
        $sources = array_filter($sources, static function (?array $source): bool {
            return $source !== null;
        });
        $files = [];
        $answers = [];
        try {
            foreach ($sources as $field => $source) {
                $file = '';
                $refusal = self::fetch($source, $file);
                if ($file !== '') {
                    $files[$field] = $file;
                }
                if ($refusal !== '') {
                    $answers[$field] = SomcpUpload::origin($source) + ['refused' => $refusal];
                    unset($sources[$field]);
                    $changes = self::without($changes, $field);
                }
            }
            if ($changes === []) {
                // Nothing else to write: the refusal is the answer.
                $refusals = [];
                foreach ($answers as $field => $answer) {
                    $refusals[] = $field . ': ' . (string) $answer['refused'];
                }

                return SomcpTool::refused('changed', $refusals);
            }

            return self::write($idShop, $idLang, $idEmployee, $default, $row, $set, $changes, $sources, $files, $answers);
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Writes the fields that differ, then the pictures in hand, and answers.
     *
     * @param int $idShop
     * @param int $idLang
     * @param int $idEmployee
     * @param int $default the identifier of the shop default language
     * @param array<string, mixed> $row the language as the list answered it before
     * @param array<string, mixed> $set the columns of Language to set
     * @param array<int, array<string, mixed>> $changes
     * @param array<string, array{url: string, upload_ref: string}> $sources the pictures in hand, by field
     * @param array<string, string> $files the temporary file of each, from fetch()
     * @param array<string, array<string, mixed>> $answers the answer of a picture already refused
     *
     * @return array<string, mixed>
     */
    private static function write(int $idShop, int $idLang, int $idEmployee, int $default, array $row, array $set, array $changes, array $sources, array $files, array $answers): array
    {
        // Only the fields that differ are set: the shops the language is tied
        // to keep what the shop stored.
        $language = new Language($idLang);
        $oldIso = (string) $language->iso_code;
        $renamed = false;
        $moved = 0;
        try {
            if (isset($set['iso_code'])) {
                // The folders move first, while the object still holds the old
                // code moveToIso() reads. The core handler passes it the
                // language identifier instead, which leaves the e-mail
                // templates under a folder named after a number.
                $language->moveToIso($set['iso_code']);
                $renamed = true;
            }
            foreach ($set as $column => $value) {
                $language->{$column} = $value;
            }
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
            if ($set === []) {
                // Only a picture to write: the row itself stays as it is.
                $done = true;
                $failure = '';
            } else {
                $check = $language->validateFields(false, true);
                if ($check !== true) {
                    $done = false;
                    $failure = strip_tags(is_string($check) ? $check : 'a field PrestaShop refuses');
                } else {
                    $done = $language->update();
                    $failure = $done ? '' : 'PrestaShop refused to save it';
                }
            }
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done && $renamed) {
            // The code was not stored: the folders go back under it, or no
            // code would read them.
            try {
                $language->iso_code = (string) $set['iso_code'];
                $language->moveToIso($oldIso);
            } catch (Throwable $e) {
                $failure .= ' - the translation folders could not be moved back to ' . $oldIso;
            }
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

        // The pictures go after the row, as the Edit page writes them; one the
        // shop cannot store is answered beside the fields that were saved.
        foreach ($sources as $field => $source) {
            // After update(): the "No-picture" image is named after the ISO
            // code, which the same call may have changed.
            $refusal = $field === 'flag'
                ? self::storeFlag($language, $idLang, $files[$field])
                : self::storeNoPicture((string) $language->iso_code, $files[$field]);
            $answers[$field] = SomcpUpload::origin($source)
                + ($refusal === '' ? ['stored' => true] : ['refused' => $refusal]);
            if ($refusal !== '') {
                $changes = self::without($changes, $field);
            }
        }
        if ($changes === []) {
            // Nothing but pictures was asked, and none could be stored.
            $refusals = [];
            foreach ($answers as $field => $answer) {
                $refusals[] = $field . ': ' . (string) ($answer['refused'] ?? 'not stored');
            }

            return SomcpTool::refused('changed', $refusals);
        }

        SomcpLogger::log('language_updated', SomcpLogger::OUTCOME_SUCCESS, 'language ' . $idLang . ': '
            . implode(', ', array_column($changes, 'field')), '', $idEmployee);
        // The shape of every update tool: changed a boolean, changes what was
        // written with its old value.
        $result = ['changed' => true, 'id_lang' => $idLang, 'changes' => $changes];

        $warnings = [];
        foreach ($answers as $field => $answer) {
            $result[$field] = $answer;
            if (isset($answer['refused'])) {
                $warnings[] = 'The other changes were saved, but the '
                    . ($field === 'flag' ? 'flag' : '"No-picture" image') . ' was not: ' . $answer['refused'] . '.';
            }
        }
        if (isset($set['iso_code'])) {
            $warnings[] = 'The translation files and e-mail templates of ' . $oldIso . ' now sit under '
                . $set['iso_code'] . '; a module or theme that ships files for ' . $oldIso . ' only will not find them.';
        }
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
        $result['language'] = $after !== null ? SomcpToolListLanguages::row($after, $default, $idShop) : null;

        return SomcpTool::json($result);
    }

    /**
     * Fetches a picture given into a temporary file of the shop, through the
     * one path every image of the module takes.
     *
     * @param array{url: string, upload_ref: string} $source
     * @param string $file set to the temporary file, '' without one
     *
     * @return string the reason it was refused, '' once in hand
     */
    private static function fetch(array $source, string &$file): string
    {
        $made = tempnam(_PS_TMP_IMG_DIR_, 'somcp');
        if ($made === false) {
            return 'the shop has no room to receive it';
        }
        $file = $made;

        try {
            return SomcpUpload::fetchImage($source, $file);
        } catch (Throwable $e) {
            return 'the shop could not save it';
        }
    }

    /**
     * Writes a flag in hand where both versions of the core keep it, as
     * EditLanguageHandler::uploadFlagImageIfChanged() does.
     *
     * One JPEG at img/l/<id_lang>.jpg, shared by every shop. Forced to JPEG
     * as the carrier logo is: the file is named .jpg whatever the image
     * quality setting would pick for a PNG sent in the chat.
     *
     * @param Language $language
     * @param int $idLang
     * @param string $file from fetch()
     *
     * @return string the reason it was refused, '' once stored
     */
    private static function storeFlag(Language $language, int $idLang, string $file): string
    {
        try {
            $language->deleteImage();
            if (!ImageManager::resize($file, _PS_LANG_IMG_DIR_ . $idLang . '.jpg', null, null, 'jpg', true)) {
                return 'the shop could not read it as an image';
            }
            // The small copy the language lists draw is cached per shop; the
            // core drops the one of its own shop only, and the flag is the
            // same for every shop.
            foreach (Shop::getShops(false, null, true) as $idShop) {
                $cached = _PS_TMP_IMG_DIR_ . 'lang_mini_' . $idLang . '_' . (int) $idShop . '.jpg';
                if (is_file($cached)) {
                    @unlink($cached);
                }
            }

            return '';
        } catch (Throwable $e) {
            return 'the shop could not save it';
        }
    }

    /**
     * Writes a "No-picture" image in hand as
     * AbstractLanguageHandler::copyNoPictureImage() does on 8 and 9.
     *
     * <iso>.jpg at its own size, then <iso>-default-<type>.jpg at the size of
     * every product image type, in the folders of the products, categories
     * and brands. Forced to JPEG, as the flag: the storefront asks for .jpg.
     * The first resize that fails stops the copy, as the core stops.
     *
     * @param string $iso the ISO code the language holds now
     * @param string $file from fetch()
     *
     * @return string the reason it was refused, '' once stored
     */
    private static function storeNoPicture(string $iso, string $file): string
    {
        $folders = [_PS_IMG_DIR_ . 'p/', _PS_IMG_DIR_ . 'c/', _PS_IMG_DIR_ . 'm/'];
        try {
            foreach ($folders as $folder) {
                if (!ImageManager::resize($file, $folder . $iso . '.jpg', null, null, 'jpg', true)) {
                    return 'the shop could not resize the image';
                }
            }
            foreach (ImageType::getImagesTypes('products') as $type) {
                $name = $iso . '-default-' . stripslashes((string) $type['name']) . '.jpg';
                foreach ($folders as $folder) {
                    if (!ImageManager::resize($file, $folder . $name, (int) $type['width'], (int) $type['height'], 'jpg', true)) {
                        return 'the shop could not resize the image';
                    }
                }
            }

            return '';
        } catch (Throwable $e) {
            return 'the shop could not resize the image';
        }
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
        if ($field === 'language_code' || $field === 'iso_code') {
            // Lower case, as the help line of the form asks and the core
            // stores the ISO code.
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
            case 'iso_code':
                return 'two letters, for example fr';
            case 'language_code':
                return 'an IETF language tag, two letters and optionally a dash and two more, for example en-us';
            default:
                return 'a PHP date format, without < or >';
        }
    }

    /**
     * The changes without the one of a field that was not written.
     *
     * @param array<int, array<string, mixed>> $changes
     * @param string $field
     *
     * @return array<int, array<string, mixed>>
     */
    private static function without(array $changes, string $field): array
    {
        return array_values(array_filter($changes, static function (array $change) use ($field): bool {
            return $change['field'] !== $field;
        }));
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
