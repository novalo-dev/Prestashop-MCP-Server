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
 * somcp_create_language - adds one language to International > Localization >
 * Languages, as Add new language does: Name, ISO code, Language code, the two
 * date formats, Flag, "No-picture" image, RTL language and Status.
 *
 * A shop that opens to a new market needs the language before any product,
 * page or e-mail can be written in it. The checks and the image writes are
 * those of somcp_update_language, called rather than copied, so a language
 * added here and one changed there are held to the same rules.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// checks and the image writes live in somcp_update_language, the answer is
// the row of the list, and the count of other shops is the tax tools' one.
require_once __DIR__ . '/SomcpToolListLanguages.php';
require_once __DIR__ . '/SomcpToolUpdateLanguage.php';
require_once __DIR__ . '/SomcpToolCreateTax.php';

class SomcpToolCreateLanguage
{
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
     * The fields the form requires.
     */
    private const REQUIRED = ['name', 'iso_code', 'language_code'];

    /**
     * The date formats taken when left out: the examples of the two help
     * lines of the form, valid everywhere.
     */
    private const DATE_FORMATS = [
        'date_format' => 'Y-m-d',
        'date_format_full' => 'Y-m-d H:i:s',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_language',
            Somcp::t('Add a language'),
            'Adds one language to International > Localization > Languages, as Add new language: Name, ISO code, '
                . 'Language code, Date format, Date format (full), Flag, "No-picture" image, RTL language and Status. '
                . 'Read somcp_list_languages first. Every text of the shop is copied from the default language into '
                . 'it, to translate. To change a language, somcp_update_language.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Merchant. Required. Name, 32 characters at most, without < > = { }.',
                    ],
                    'iso_code' => [
                        'type' => 'string',
                        'description' => 'Merchant. Required. ISO code: two letters no language of the shop has, '
                            . 'for example fr, stored lower case.',
                    ],
                    'language_code' => [
                        'type' => 'string',
                        'description' => 'Merchant. Required. Language code: the IETF language tag, for example '
                            . 'en-us or pt-br, stored lower case.',
                    ],
                    'date_format' => [
                        'type' => 'string',
                        'description' => 'Merchant. Date format: the short PHP date format, 32 characters at most. '
                            . 'Left out, Y-m-d.',
                    ],
                    'date_format_full' => [
                        'type' => 'string',
                        'description' => 'Merchant. Date format (full): the PHP date and time format. Left out, '
                            . 'Y-m-d H:i:s.',
                    ],
                    'flag' => SomcpUpload::schema('Merchant. Required. Flag: the picture shown beside the language.', 'the flag'),
                    'no_picture' => SomcpUpload::schema('Merchant. Required. Shown in it where a product has no picture.', 'the image'),
                    'is_rtl' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. RTL language: true for a language written right to left. Left '
                            . 'out, false.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Status: true offers the language. true by default, as the page.',
                    ],
                ],
                'required' => ['name', 'iso_code', 'language_code', 'flag', 'no_picture'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Adding a language is what Add new language asks for in Team,
            // Permissions; editing one is a different right.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminLanguages'),
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
            array_merge(array_keys(self::TEXTS), ['flag', 'no_picture', 'is_rtl', 'active'])
        );

        $probe = new Language();
        $values = [];
        foreach (self::TEXTS as $field => $column) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                if (in_array($field, self::REQUIRED, true)) {
                    $errors[] = $field . ': required';
                } else {
                    $values[$column] = self::DATE_FORMATS[$field];
                }
                continue;
            }
            $value = SomcpToolUpdateLanguage::text($arguments, $field, $column, $probe, $errors);
            if ($value !== null) {
                $values[$column] = $value;
            }
        }

        if (isset($values['iso_code'])) {
            // As the back office refuses it: two languages with one code would
            // share their translation and e-mail folders.
            $owner = (int) Language::getIdByIso($values['iso_code'], true);
            if ($owner > 0) {
                $errors[] = 'iso_code: ' . $values['iso_code'] . ' is already the code of '
                    . (string) (new Language($owner))->name . ' (' . $owner . ')';
            }
        }

        $flags = ['is_rtl' => false, 'active' => true];
        foreach (array_keys($flags) as $field) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            // A boolean only: "false" cast to bool is true, and would offer a
            // language the merchant wanted kept off.
            if (!is_bool($arguments[$field])) {
                $errors[] = $field . ': true or false';
                continue;
            }
            $flags[$field] = $arguments[$field];
        }

        // Both pictures are required, as the form requires them: without the
        // "No-picture" image, a product without a photo would show a broken
        // picture in that language. One left out is answered as an empty one.
        $sources = [];
        foreach (['flag' => 'flag', 'no_picture' => 'no-picture image'] as $field => $what) {
            $source = array_key_exists($field, $arguments) && $arguments[$field] !== null
                ? SomcpUpload::argument($arguments, $field, $errors, $what)
                : SomcpUpload::source([], $field, $errors, $what);
            if ($source !== null) {
                $sources[$field] = $source;
            }
        }

        if ($errors !== [] || count($sources) !== 2 || !isset($values['name'], $values['iso_code'], $values['language_code'])) {
            return SomcpTool::refused('created', $errors);
        }

        $files = [];
        try {
            // Both pictures are fetched before anything is written: one the
            // shop refuses must not leave a language without its images.
            $refusals = [];
            foreach ($sources as $field => $source) {
                $file = '';
                $refusal = SomcpToolUpdateLanguage::fetch($source, $file);
                if ($file !== '') {
                    $files[$field] = $file;
                }
                if ($refusal !== '') {
                    $refusals[] = $field . ': ' . $refusal;
                }
            }
            if ($refusals !== []) {
                return SomcpTool::refused('created', $refusals);
            }

            return self::write($idShop, $idEmployee, $values, $flags, $sources, $files);
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Adds the language, then writes its pictures, and answers.
     *
     * @param int $idShop
     * @param int $idEmployee
     * @param array<string, string> $values the text columns of Language
     * @param array{is_rtl: bool, active: bool} $flags
     * @param array<string, array{url: string, upload_ref: string}> $sources the pictures, by field
     * @param array<string, string> $files the temporary file of each, from fetch()
     *
     * @return array<string, mixed>
     */
    private static function write(int $idShop, int $idEmployee, array $values, array $flags, array $sources, array $files): array
    {
        $iso = $values['iso_code'];
        $language = new Language();
        foreach ($values as $column => $value) {
            $language->{$column} = $value;
        }
        $language->is_rtl = $flags['is_rtl'];
        $language->active = $flags['active'];
        // The locale follows the code when the shop knows it, as the core
        // handler sets it; Locale is never asked of the merchant.
        $details = Language::getLangDetails($iso);
        if (is_array($details) && (string) ($details['locale'] ?? '') !== '') {
            $language->locale = (string) $details['locale'];
        }
        // Tied to the shop of the token only, as the zone and currency tools
        // write their shop rows.
        $language->id_shop_list = [$idShop];

        $added = false;
        $done = false;
        $failure = '';
        try {
            $check = $language->validateFields(false, true);
            if ($check !== true) {
                $failure = strip_tags(is_string($check) ? $check : 'a field PrestaShop refuses');
            } else {
                // Not $only_add, as the handler calls it: every product,
                // category and page gets a text in the new language at once,
                // copied from the default one.
                $added = (bool) $language->add();
                if (!$added) {
                    $failure = 'PrestaShop refused to save it';
                } else {
                    $idLang = (int) $language->id;
                    // After add(): the flag is named after the new identifier.
                    $refusal = SomcpToolUpdateLanguage::storeFlag($language, $idLang, $files['flag']);
                    if ($refusal !== '') {
                        $failure = 'the flag could not be stored: ' . $refusal;
                    } else {
                        $refusal = SomcpToolUpdateLanguage::storeNoPicture($iso, $files['no_picture']);
                        $failure = $refusal !== '' ? 'the "No-picture" image could not be stored: ' . $refusal : '';
                    }
                    $done = $failure === '';
                }
            }
        } catch (Throwable $e) {
            $failure = $e->getMessage();
        } finally {
            if ($added && !$done) {
                // A half-made language never stays: without its pictures the
                // storefront would show broken images in it.
                self::remove($language);
            }
        }
        if (!$done) {
            SomcpLogger::log('language_create_failed', SomcpLogger::OUTCOME_FAILURE, $iso . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text('Nothing was created. The shop refused language ' . $iso . ': ' . $failure
                . ' - read somcp_list_languages before calling again', true);
        }

        $idLang = (int) $language->id;
        // robots.txt lists a path per language, as the handler writes it
        // again after an addition.
        Tools::generateRobotsFile();
        SomcpLogger::log('language_created', SomcpLogger::OUTCOME_SUCCESS, 'language ' . $idLang . ' (' . $iso . ')',
            '', $idEmployee);

        $default = SomcpToolListLanguages::defaultLanguage($idShop);
        $defaultRow = SomcpToolListLanguages::language($idShop, $default);
        $name = $values['name'];
        $warnings = ['Every text of the shop was copied from '
            . ($defaultRow !== null ? (string) $defaultRow['name'] : 'the default language') . ' into ' . $name
            . ': translate them, or install the translation pack of ' . $iso . ' in International > Translations.'];
        if (SomcpToolCreateTax::otherShops() > 0) {
            $warnings[] = $name . ' was added to this shop only; the others do not list it.';
        }

        $result = ['created' => true, 'id_lang' => $idLang];
        foreach ($sources as $field => $source) {
            $result[$field] = SomcpUpload::origin($source) + ['stored' => true];
        }
        $result['warning'] = implode(' ', $warnings);
        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $after = SomcpToolListLanguages::language($idShop, $idLang);
        $result['language'] = $after !== null ? SomcpToolListLanguages::row($after, $default, $idShop) : null;

        return SomcpTool::json($result);
    }

    /**
     * Deletes a language this call added, on every shop, as
     * somcp_delete_language does; the context of the request is put back.
     *
     * @param Language $language
     *
     * @return void
     */
    private static function remove(Language $language): void
    {
        $previous = Shop::getContext();
        $previousId = $previous === Shop::CONTEXT_GROUP ? Shop::getContextShopGroupID() : Shop::getContextShopID();
        try {
            Shop::setContext(Shop::CONTEXT_ALL);
            $language->delete();
        } catch (Throwable $e) {
            // The failure that led here is the one answered.
        } finally {
            if ($previous === Shop::CONTEXT_ALL) {
                Shop::setContext(Shop::CONTEXT_ALL);
            } else {
                Shop::setContext($previous, $previousId);
            }
        }
    }
}
