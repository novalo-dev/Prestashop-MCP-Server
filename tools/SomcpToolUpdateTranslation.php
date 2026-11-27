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
 * somcp_update_translation - the card Modify translations of International >
 * Translations, its changing half: the Save and Reset of one wording.
 *
 * Written through the core's own translation service inside the back office's
 * kernel, as the editor's API writes it: the row lives in a Doctrine entity,
 * and saveTranslationMessage() is the one rule (the placeholders, the key, the
 * theme) - the tool never writes the table itself.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The selection, the domains and the wordings are read as the list reads them.
require_once __DIR__ . '/SomcpToolListTranslations.php';

class SomcpToolUpdateTranslation
{
    private const MAX_LENGTH = 4000;

    private const READ_ONLY = ['file_translation', 'shop_translation', 'shown'];

    private const FIELDS = ['language', 'type', 'theme', 'module', 'domain', 'source', 'translation', 'reset',
        'file_translation', 'shop_translation', 'shown'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_translation',
            Somcp::t('Change a translation of a language'),
            'Saves the shop\'s own translation of one wording, or removes it (reset) so the translation of the '
                . 'files shows again, as Save and Reset of the card Modify translations of International > '
                . 'Translations. language, type, theme, module, domain and source are those somcp_list_translations '
                . 'answers. The change reaches every shop.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'Required. The ISO code of an installed language, as somcp_list_languages '
                            . 'answers iso_code.',
                    ],
                    'type' => [
                        'type' => 'string',
                        'enum' => array_keys(SomcpToolListTranslations::TYPES),
                        'description' => 'Required. Type of translation, as somcp_list_translations takes it.',
                    ],
                    'theme' => [
                        'type' => 'string',
                        'description' => 'With type front_office only: the name of an installed theme. Left out or '
                            . 'classic: the core\'s front office wordings.',
                    ],
                    'module' => [
                        'type' => 'string',
                        'description' => 'Required with type module: the technical name of an installed module.',
                    ],
                    'domain' => [
                        'type' => 'string',
                        'description' => 'Required. The domain of the wording, as somcp_list_translations answers it.',
                    ],
                    'source' => [
                        'type' => 'string',
                        'description' => 'Required. The source of the wording, as somcp_list_translations answers it.',
                    ],
                    'translation' => [
                        'type' => 'string',
                        'description' => 'The shop\'s translation, 4000 characters at most, with the same placeholders '
                            . '(%s, %d...) as the source. Required unless reset is true.',
                    ],
                    'reset' => [
                        'type' => 'boolean',
                        'description' => 'true: the shop\'s translation is removed and the translation of the files '
                            . 'shows again. Not with translation.',
                    ],
                    'file_translation' => [
                        'type' => ['string', 'null'],
                        'description' => 'Read only: accepted when equal to what somcp_list_translations answers.',
                    ],
                    'shop_translation' => [
                        'type' => ['string', 'null'],
                        'description' => 'Read only: accepted when equal to what somcp_list_translations answers.',
                    ],
                    'shown' => [
                        'type' => 'string',
                        'description' => 'Read only: accepted when equal to what somcp_list_translations answers.',
                    ],
                ],
                'required' => ['language', 'type', 'domain', 'source'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // The editor's Save asks create or update on 8 and 9: a change on both.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminTranslations')
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
        $problems = SomcpTool::unknownFields($arguments, self::FIELDS);
        $selection = SomcpToolListTranslations::selection($arguments, Somcp::currentShopId(), $problems);

        $domain = isset($arguments['domain']) && is_string($arguments['domain']) ? trim($arguments['domain']) : '';
        if ($domain === '') {
            $problems[] = 'domain: required, the name of a domain as somcp_list_translations answers it';
        }
        $source = isset($arguments['source']) && is_string($arguments['source']) ? $arguments['source'] : '';
        if ($source === '') {
            $problems[] = 'source: required, the source of a wording as somcp_list_translations answers it';
        }

        $reset = false;
        if (isset($arguments['reset'])) {
            if (!is_bool($arguments['reset'])) {
                $problems[] = 'reset: true or false';
            } else {
                $reset = $arguments['reset'];
            }
        }
        $translation = null;
        if (array_key_exists('translation', $arguments)) {
            if ($reset) {
                $problems[] = 'reset: without translation';
            } elseif (!is_string($arguments['translation']) || trim($arguments['translation']) === '') {
                $problems[] = 'translation: a text - use reset to show the translation of the files again';
            } elseif (Tools::strlen($arguments['translation']) > self::MAX_LENGTH) {
                $problems[] = 'translation: ' . self::MAX_LENGTH . ' characters at most';
            } else {
                $translation = $arguments['translation'];
            }
        } elseif (!$reset) {
            $problems[] = 'translation: required, or reset: true';
        }
        if ($problems !== []) {
            return SomcpTool::refused('changed', $problems);
        }

        $answer = null;
        $error = '';
        $restore = SomcpBackOffice::asBackOffice();
        try {
            $answer = self::change($selection, $domain, $source, $translation, $arguments);
        } catch (Throwable $e) {
            // A message of the core may name a folder of the server.
            $error = trim(str_replace(_PS_ROOT_DIR_, '', $e->getMessage()));
        } finally {
            $restore();
        }

        if ($error !== '' || $answer === null) {
            return SomcpTool::text('The translation could not be saved: ' . Tools::substr($error !== '' ? $error : 'the services of the back office are not available', 0, 500) . '.', true);
        }

        return $answer;
    }

    /**
     * Inside the back office's kernel: the wording found, checked, then saved
     * or reset as translationEditAction() and translationResetAction() do.
     *
     * @param array{language:string, locale:string, type:string, core_type:string, theme:string|null, module:string|null} $selection
     * @param string $domain
     * @param string $source
     * @param string|null $translation null for a reset
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private static function change(array $selection, string $domain, string $source, ?string $translation, array $arguments): array
    {
        $domains = SomcpToolListTranslations::domains($selection, []);
        if (!in_array($domain, array_column($domains['domains'], 'domain'), true)) {
            return SomcpTool::refused('changed', [SomcpTool::notFound('domain', 'domain', $domain, 'somcp_list_translations', 'domain')]);
        }
        $before = self::wording($selection, $domain, $source);
        if ($before === null) {
            return SomcpTool::refused('changed', [SomcpTool::notFound('source', 'wording', Tools::substr($source, 0, 100), 'somcp_list_translations', 'source')]);
        }

        $problems = [];
        // Given back whole from the list, the read-only fields change nothing.
        foreach (self::READ_ONLY as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== $before[$field]) {
                $problems[] = $field . ': read only - change translation';
            }
        }
        if ($translation !== null) {
            // The rule of PassVsprintf, checked first so that the answer can
            // name the placeholders; saveTranslationMessage() checks it again.
            $placeholders = self::placeholders($source);
            if (count($placeholders) !== count(self::placeholders($translation))) {
                $problems[] = 'translation: keep the placeholders of the source (' . implode(' ', $placeholders) . ')';
            }
        }
        if ($problems !== []) {
            return SomcpTool::refused('changed', $problems);
        }

        $header = ['language' => $selection['language'], 'type' => $selection['type'], 'domain' => $domain];
        if ($translation === $before['shop_translation'] || ($translation === null && $before['shop_translation'] === null)) {
            return SomcpTool::json(['changed' => false] + $header + ['translation' => $before]);
        }

        $service = SomcpBackOffice::coreService('prestashop.service.translation');
        if ($service === null || !method_exists($service, 'findLanguageByLocale')
            || !method_exists($service, 'saveTranslationMessage') || !method_exists($service, 'resetTranslationMessage')) {
            throw new RuntimeException('the translation service of the back office is not available');
        }
        $lang = $service->findLanguageByLocale($selection['locale']);
        // As the editor: the domain Others of the tree is the catalogue messages.
        $catalogueDomain = $domain === ucfirst('messages') ? 'messages' : $domain;
        if ($translation !== null) {
            $saved = $service->saveTranslationMessage($lang, $catalogueDomain, $source, $translation, $selection['theme']);
        } else {
            $saved = $service->resetTranslationMessage($lang->getId(), $catalogueDomain, $source, $selection['theme']);
        }
        if ($saved !== true) {
            return SomcpTool::text('The translation could not be saved. Read the wording with somcp_list_translations before calling again.', true);
        }

        // As ApiController::clearCache(): the Symfony cache, where the shop
        // keeps its catalogues, is removed. PrestaShop 9's clearer does it once
        // the answer is sent; clearSf2Cache() does the same on 8 and 9, where
        // 8's in-process cache:clear would empty the cache of the kernel still
        // answering this call.
        Tools::clearSf2Cache();

        $warnings = [];
        if ($catalogueDomain === 'AdminNavigationMenu') {
            // As translateMultilingualContent(): the menu is translated again.
            $translator = SomcpBackOffice::coreService('translator');
            if ($translator !== null && method_exists($translator, 'clearLanguage')) {
                $translator->clearLanguage($selection['locale']);
            }
            if ($translator instanceof PrestaShopBundle\Translation\TranslatorInterface) {
                (new PrestaShop\PrestaShop\Adapter\EntityTranslation\EntityTranslatorFactory($translator))
                    ->buildFromTableName('tab', $selection['locale'])
                    ->translate((int) $lang->getId(), Somcp::currentShopId());
            }
            $warnings[] = 'The back office menu is translated again for this language.';
        }
        if (Shop::isFeatureActive()) {
            $warnings[] = 'The translations are shared by every shop.';
        }

        SomcpLogger::log('translation_updated', SomcpLogger::OUTCOME_SUCCESS, 'language ' . $selection['language']
            . ', domain ' . $domain . ': ' . ($translation !== null ? 'saved' : 'reset'));

        // Read back rather than echoed: what the merchant sees is what the shop stored.
        $after = self::wording($selection, $domain, $source) ?? $before;
        $result = ['changed' => true] + $header + [
            'changes' => [['field' => 'translation', 'old' => $before['shop_translation'], 'new' => $after['shop_translation']]],
            'translation' => $after,
        ];
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }

    /**
     * One wording of the domain, as somcp_list_translations answers it.
     *
     * @param array{language:string, locale:string, type:string, core_type:string, theme:string|null, module:string|null} $selection
     * @param string $domain
     * @param string $source
     *
     * @return array{source:string, file_translation:string|null, shop_translation:string|null, shown:string}|null
     */
    private static function wording(array $selection, string $domain, string $source): ?array
    {
        $read = SomcpToolListTranslations::wordings($selection, $domain, [], false, PHP_INT_MAX, 0);
        foreach (is_array($read['translations'] ?? null) ? $read['translations'] : [] as $row) {
            if (is_array($row) && ($row['source'] ?? null) === $source) {
                /* @var array{source:string, file_translation:string|null, shop_translation:string|null, shown:string} $row */
                return $row;
            }
        }

        return null;
    }

    /**
     * The sprintf placeholders of a text, read by the core's own pattern.
     *
     * @param string $text
     *
     * @return array<int, string>
     */
    private static function placeholders(string $text): array
    {
        $matches = [];
        preg_match_all(PrestaShopBundle\Translation\Translator::$regexSprintfParams, $text, $matches);

        return $matches[0];
    }
}
