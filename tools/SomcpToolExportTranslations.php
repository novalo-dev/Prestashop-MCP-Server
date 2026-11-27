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
 * somcp_export_translations - the card Export a language of International >
 * Translations: the translation pack of one language (core parts, a theme, a
 * module) built as a zip of XLF files, handed over as a download_ref.
 *
 * The pack is built by the core's own exporter, inside the back office's
 * kernel: its catalogue builder reads the shop's own translations through
 * Doctrine, which the MCP endpoint's container does not hold.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// themes are read from the repository somcp_get_theme reads them from.
require_once __DIR__ . '/SomcpToolGetTheme.php';

class SomcpToolExportTranslations
{
    /**
     * The boxes of PrestaShop translations, by the names the tool takes, and
     * the type the core's exporter knows them by (ProviderDefinitionInterface).
     */
    public const CORE = [
        'back_office' => 'back',
        'front_office' => 'front',
        'email' => 'mails',
        'other' => 'others',
    ];

    /**
     * Seconds of PHP the call asks for: the back office catalogue of a
     * language is over 10 000 wordings, as somcp_install_language_pack asks.
     */
    private const REQUEST_SECONDS = 120;

    private const FIELDS = ['language', 'core', 'theme', 'module'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_export_translations',
            Somcp::t('Export the translations of a language'),
            'Builds the translation pack of one language as a zip of XLF files, as the card Export a language of '
                . 'International > Translations does: the core parts, a theme and a module, any of them together. '
                . 'Answers a download_ref to hand to somcp_download_file, kept 15 minutes. Nothing of the shop is '
                . 'changed. The pack is what Add / Update a language of another shop imports; to install a language '
                . 'from PrestaShop, somcp_install_language_pack.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'Required. Language: the ISO code of an installed language of the shop, '
                            . 'as somcp_list_languages answers iso_code, for example en or fr.',
                    ],
                    'core' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'enum' => array_keys(self::CORE)],
                        'description' => 'PrestaShop translations: any of back_office, front_office, email, other '
                            . '(the boxes Back office, Front office, Email, Other). email also exports the bodies '
                            . 'of the e-mails, as the screen does.',
                    ],
                    'theme' => [
                        'type' => 'string',
                        'description' => 'Theme translations: the name of an installed theme other than classic, as '
                            . 'somcp_get_theme answers name. The wordings of classic are the core\'s Front office.',
                    ],
                    'module' => [
                        'type' => 'string',
                        'description' => 'Installed module translations: the technical name of an installed module, '
                            . 'as somcp_list_modules answers name.',
                    ],
                ],
                'required' => ['language'],
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

        // Every problem in one answer, all before the core is called.
        $problems = SomcpTool::unknownFields($arguments, self::FIELDS);

        $iso = '';
        $locale = '';
        if (!isset($arguments['language']) || $arguments['language'] === '') {
            $problems[] = 'language: required';
        } else {
            $iso = is_string($arguments['language']) ? Tools::strtolower(trim($arguments['language'])) : '';
            $locale = self::locale($iso, $idShop);
            if ($locale === '') {
                $problems[] = SomcpTool::notFound('language', 'language', is_string($arguments['language']) ? $arguments['language'] : json_encode($arguments['language']), 'somcp_list_languages', 'iso_code');
            }
        }

        $selections = [];
        $parts = [];
        $core = $arguments['core'] ?? [];
        if (!is_array($core) || array_values($core) !== $core) {
            $problems[] = 'core: a list of ' . implode(', ', array_keys(self::CORE));
        } else {
            foreach ($core as $i => $part) {
                if (!is_string($part) || !isset(self::CORE[$part])) {
                    $problems[] = SomcpTool::oneOf('core[' . $i . ']', array_keys(self::CORE));
                    continue;
                }
                if (in_array($part, $parts, true)) {
                    continue;
                }
                $parts[] = $part;
                $selections[] = ['type' => self::CORE[$part], 'selected' => null];
                // As the screen does: exporting the e-mails exports their bodies too.
                if ($part === 'email') {
                    $selections[] = ['type' => 'mails_body', 'selected' => null];
                }
            }
        }

        if (isset($arguments['theme'])) {
            $theme = self::theme($arguments['theme'], $problems);
            if ($theme !== '') {
                $parts[] = 'theme ' . $theme;
                $selections[] = ['type' => 'themes', 'selected' => $theme];
            }
        }
        if (isset($arguments['module'])) {
            $module = self::module($arguments['module'], $problems);
            if ($module !== '') {
                $parts[] = 'module ' . $module;
                $selections[] = ['type' => 'modules', 'selected' => $module];
            }
        }

        if ($problems === [] && $selections === []) {
            $problems[] = 'core, theme or module: at least one - You must select at least one translation type to '
                . 'export translations.';
        }
        if ($problems !== []) {
            return SomcpTool::refused('exported', $problems);
        }

        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0 && $limit < self::REQUEST_SECONDS && function_exists('set_time_limit')) {
            @set_time_limit(self::REQUEST_SECONDS);
        }

        $id = '';
        $error = '';
        $restore = SomcpBackOffice::asBackOffice();
        try {
            $exporter = SomcpBackOffice::coreService('prestashop.translation.export.translation_catalogue');
            if ($exporter === null || !method_exists($exporter, 'export')) {
                $error = 'the exporter of the back office is not available';
            } else {
                $zip = (string) $exporter->export($selections, $locale);
                $id = SomcpDownload::keepExport($zip);
                if ($id === '') {
                    $error = 'the pack could not be kept';
                }
            }
        } catch (Throwable $e) {
            // A message of the core may name a folder of the server.
            $error = trim(str_replace(_PS_ROOT_DIR_, '', $e->getMessage()));
        } finally {
            $restore();
        }

        if ($error !== '') {
            SomcpLogger::log('translations_export_failed', SomcpLogger::OUTCOME_FAILURE, $iso . ' ' . implode(', ', $parts), '', $idEmployee);

            return SomcpTool::text('The translations could not be exported: ' . Tools::substr($error, 0, 500) . '.', true);
        }

        $path = rtrim((string) _PS_ROOT_DIR_, '/\\') . '/' . SomcpDownload::EXPORTS . $id . '.zip';
        $reference = SomcpDownload::reference('translation_export', $id, $idEmployee, $idShop);
        SomcpLogger::log('translations_exported', SomcpLogger::OUTCOME_SUCCESS, $iso . ' ' . implode(', ', $parts), '', $idEmployee);

        return SomcpTool::json([
            'exported' => true,
            'language' => $iso,
            'locale' => $locale,
            'parts' => $parts,
            'file' => [SomcpDownload::ARG_REF => $reference[SomcpDownload::ARG_REF]],
            'bytes' => (int) @filesize($path),
            'expires_at' => $reference['expires_at'],
            'then' => 'somcp_download_file with this download_ref',
        ]);
    }

    /**
     * The locale of an installed language of the shop, as the core's exporter takes it.
     *
     * @param string $iso
     * @param int $idShop
     *
     * @return string empty when the shop has no such language
     */
    private static function locale(string $iso, int $idShop): string
    {
        if ($iso === '') {
            return '';
        }
        foreach (Language::getLanguages(false, $idShop) ?: [] as $language) {
            if (Tools::strtolower((string) $language['iso_code']) === $iso) {
                return (string) Language::getLocaleByIso($iso);
            }
        }

        return '';
    }

    /**
     * The theme given, checked against the themes installed.
     *
     * @param mixed $value
     * @param array<int, string> $problems
     *
     * @return string the theme's name, empty when refused
     */
    private static function theme($value, array &$problems): string
    {
        $name = is_string($value) ? trim($value) : '';
        if ($name === 'classic') {
            // ExportCataloguesType leaves it out: its wordings are the core's.
            $problems[] = 'theme: the wordings of classic are the core\'s Front office - give front_office in core';

            return '';
        }
        $installed = [];
        try {
            foreach (SomcpToolGetTheme::repository()->getList() as $theme) {
                // Typed as any add-on by the repository; only a theme has a name here.
                if ($theme instanceof PrestaShop\PrestaShop\Core\Addon\Theme\Theme) {
                    $installed[] = (string) $theme->getName();
                }
            }
        } catch (Exception $e) {
            // A broken theme folder leaves the list as read so far.
        }
        if ($name === '' || !in_array($name, $installed, true)) {
            $problems[] = SomcpTool::notFound('theme', 'theme', is_string($value) ? $value : json_encode($value), 'somcp_get_theme', 'name');

            return '';
        }

        return $name;
    }

    /**
     * The module given, checked against the installed modules without case,
     * as somcp_update_customer_group compares them.
     *
     * @param mixed $value
     * @param array<int, string> $problems
     *
     * @return string the module's technical name, empty when refused
     */
    private static function module($value, array &$problems): string
    {
        $key = is_string($value) ? Tools::strtolower(trim($value)) : '';
        foreach (Module::getModulesInstalled() ?: [] as $row) {
            if ($key !== '' && Tools::strtolower((string) $row['name']) === $key) {
                return (string) $row['name'];
            }
        }
        $problems[] = SomcpTool::notFound('module', 'installed module', is_string($value) ? $value : json_encode($value), 'somcp_list_modules', 'name');

        return '';
    }
}
