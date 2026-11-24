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
 * somcp_install_language_pack - the card Add / Update a language of
 * International > Translations: a language added with its translations, or
 * the translations of an installed one replaced, from PrestaShop's server.
 *
 * somcp_create_language leaves the shop untranslated in the language it
 * creates; this is how a merchant gets a storefront, e-mails and back office
 * in it. The download and the installation are PrestaShop's own, called as
 * the back office calls them.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// answer is the row of the list, the caches are cleared as the Clear cache
// button clears them, and the shops counted as the other tools count them.
require_once __DIR__ . '/SomcpToolListLanguages.php';
require_once __DIR__ . '/SomcpToolClearCache.php';
require_once __DIR__ . '/SomcpToolCreateTax.php';

class SomcpToolInstallLanguagePack
{
    /**
     * Seconds PrestaShop waits for a pack, Language::PACK_DOWNLOAD_TIMEOUT on
     * 8.0 and 9.0, for the messages when the core does not say it.
     */
    public const TIMEOUT = 20;

    /**
     * Seconds of PHP the call asks for: after the download come the
     * extraction, the translation of every *_lang table and the e-mails.
     */
    private const REQUEST_SECONDS = 120;

    private const FIELDS = ['iso_code'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_install_language_pack',
            Somcp::t('Add or update a language from PrestaShop'),
            'Adds a language with its translations, or updates the translations of an installed one, from '
                . 'PrestaShop\'s translation server, as Add / Update a language in International > Translations. '
                . 'The call can take up to two minutes. Every shop of the installation is reached. Do not retry a '
                . 'success: read the languages with somcp_list_languages. To add a language without translations, '
                . 'somcp_create_language; to change one, somcp_update_language.',
            [
                'type' => 'object',
                'properties' => [
                    'iso_code' => [
                        'type' => 'string',
                        'description' => 'Required. Please select the language you want to add or update: the two '
                            . 'letter ISO code of the language, for example fr or de. An installed language is '
                            . 'updated, another one added.',
                    ],
                ],
                'required' => ['iso_code'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // The card posts to an action that asks create on the page.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminTranslations')
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

        // Every problem in one answer (#169), all before any download.
        $problems = SomcpTool::unknownFields($arguments, self::FIELDS);
        $iso = '';
        if (!array_key_exists('iso_code', $arguments) || $arguments['iso_code'] === null || $arguments['iso_code'] === '') {
            $problems[] = 'iso_code: required';
        } else {
            $iso = is_string($arguments['iso_code']) ? Tools::strtolower(trim($arguments['iso_code'])) : '';
            if (!self::offered($iso)) {
                $problems[] = 'iso_code: a language code PrestaShop offers, for example fr or de - the list of Add / '
                    . 'Update a language';
            }
        }
        if ($problems !== []) {
            return SomcpTool::refused('changed', $problems);
        }

        // PHP may stop the request between the download and the last table.
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0 && $limit < self::REQUEST_SECONDS && function_exists('set_time_limit')) {
            @set_time_limit(self::REQUEST_SECONDS);
        }

        $before = (int) Language::getIdByIso($iso, true);
        // As LanguagePackInstaller calls it: a fresh install for a language
        // the shop does not have, an update of the translations otherwise.
        try {
            $done = Language::downloadAndInstallLanguagePack($iso, _PS_VERSION_, null, $before === 0);
        } catch (Throwable $e) {
            $done = [$e->getMessage()];
        }
        $errors = [];
        if ($done === false) {
            $errors[] = 'Fatal error: ISO code is not correct';
        } elseif (is_array($done)) {
            foreach ($done as $error) {
                // A message of the core may name a folder of the server.
                $errors[] = trim(str_replace(_PS_ROOT_DIR_, '', (string) $error));
            }
        }
        if ($errors === []) {
            // As LanguagePackImporter does after a pack: the translations and
            // the class index are read from the caches.
            SomcpToolClearCache::clear();
        }
        $after = (int) Language::getIdByIso($iso, true);

        if ($errors !== []) {
            SomcpLogger::log('language_pack_install_failed', SomcpLogger::OUTCOME_FAILURE, $iso, '', $idEmployee);

            return SomcpTool::text(self::failure($iso, $errors, $before, $after), true);
        }
        $added = $before === 0;
        SomcpLogger::log('language_pack_installed', SomcpLogger::OUTCOME_SUCCESS, $iso . ' ' . ($added ? 'added' : 'updated'), '', $idEmployee);

        $row = SomcpToolListLanguages::language($idShop, $after);
        $language = $row !== null ? SomcpToolListLanguages::row($row, SomcpToolListLanguages::defaultLanguage($idShop), $idShop) : null;
        $result = ['changed' => true, 'added' => $added, 'id_lang' => $after];

        $warnings = [];
        if (!$added) {
            $warnings[] = 'The translations of ' . ($language !== null ? $language['name'] : $iso) . ' were replaced by '
                . 'PrestaShop\'s, and the texts PrestaShop ships in the database (order statuses, page titles, '
                . 'contacts...) were translated again in this language, as the back office does.';
        }
        $otherShops = SomcpToolCreateTax::otherShops();
        if ($otherShops > 0) {
            $warnings[] = 'Languages are shared by every shop: this reaches every shop of this installation ('
                . ($otherShops + 1) . ').';
        }
        if ($added && $language !== null && $language['active']) {
            $warnings[] = 'The language is installed: check its Status with somcp_update_language before customers see it.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        $result['language'] = $language;

        return SomcpTool::json($result);
    }

    /**
     * Whether PrestaShop offers a pack for this code, as the list of the card does.
     *
     * @param string $iso
     *
     * @return bool
     */
    private static function offered(string $iso): bool
    {
        if ($iso === '' || !Validate::isLanguageIsoCode($iso)) {
            return false;
        }
        try {
            return Language::getLangDetails($iso) !== false;
        } catch (Throwable $e) {
            // The core throws when its list of languages cannot be read.
            return false;
        }
    }

    /**
     * The answer to a pack that did not install, from what the core reported.
     *
     * @param string $iso
     * @param array<int, string> $errors
     * @param int $before the language before the call, 0 when there was none
     * @param int $after the language after the call, 0 when there is none
     *
     * @return string
     */
    private static function failure(string $iso, array $errors, int $before, int $after): string
    {
        $unavailable = Somcp::legacyContext()->getTranslator()->trans('Language pack unavailable.', [], 'Admin.International.Notification');
        if ($after === $before) {
            foreach ($errors as $error) {
                if (strpos($error, $unavailable) === 0 || strpos($error, 'Language pack unavailable.') === 0) {
                    // The address of the pack the core appends is left out.
                    $wait = defined('Language::PACK_DOWNLOAD_TIMEOUT') ? (int) constant('Language::PACK_DOWNLOAD_TIMEOUT') : self::TIMEOUT;

                    return 'PrestaShop\'s translation server did not send the ' . $iso . ' pack within ' . $wait
                        . ' seconds. Nothing was changed. Try again later.';
                }
            }
        }
        $messages = implode('; ', array_map(static function (string $error): string {
            return rtrim($error, '. ');
        }, $errors));
        if ($before === 0 && $after > 0) {
            return 'The language ' . $iso . ' was created (id_lang ' . $after . '), but its translations could not all '
                . 'be installed: ' . $messages . '. Call again to finish, or delete it with somcp_delete_language.';
        }

        return 'The language pack could not be installed: ' . $messages . '. Nothing was changed.';
    }
}
