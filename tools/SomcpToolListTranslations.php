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
 * somcp_list_translations - the card Modify translations of International >
 * Translations, its reading half: the domains of one kind of wordings with
 * their counts (the tree of the editor), then the wordings of one domain with
 * the source, the translation of the files and the shop's own.
 *
 * Read through the core's own builders inside the back office's kernel, as
 * the editor's API reads them: the shop's translations come from Doctrine,
 * which the MCP endpoint's container does not hold.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// language, the themes and the modules are checked as the export checks them.
require_once __DIR__ . '/SomcpToolExportTranslations.php';

class SomcpToolListTranslations
{
    /**
     * The kinds of Type of translation (and the email content type), by the
     * names the tool takes, and the type the core knows them by.
     */
    public const TYPES = [
        'back_office' => 'back',
        'front_office' => 'themes',
        'module' => 'modules',
        'email_subject' => 'mails',
        'email_body' => 'mails_body',
        'other' => 'others',
    ];

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 100;

    private const FIELDS = ['language', 'type', 'theme', 'module', 'domain', 'search', 'missing_only', 'limit', 'offset'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_translations',
            Somcp::t('List the translations of a language'),
            'Reads the translations of a language as the card Modify translations of International > Translations '
                . 'shows them. Without domain: the domains of that type of wordings with their counts, as the tree '
                . 'of the editor. With domain: its wordings, each with source, file_translation (the translation of '
                . 'the files), shop_translation (the shop\'s own, null when none) and shown (what the shop uses).',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'Required. Select your language: the ISO code of an installed language, as '
                            . 'somcp_list_languages answers iso_code.',
                    ],
                    'type' => [
                        'type' => 'string',
                        'enum' => array_keys(self::TYPES),
                        'description' => 'Required. Type of translation: back_office, front_office, module, other, '
                            . 'or the e-mails by Select the type of email content: email_subject, email_body.',
                    ],
                    'theme' => [
                        'type' => 'string',
                        'description' => 'Select your theme, with type front_office only: the name of an installed '
                            . 'theme (somcp_get_theme, name). Left out or classic: the core\'s front office wordings.',
                    ],
                    'module' => [
                        'type' => 'string',
                        'description' => 'Select your module, required with type module: the technical name of an '
                            . 'installed module (somcp_list_modules, name).',
                    ],
                    'domain' => [
                        'type' => 'string',
                        'description' => 'A domain this tool answers without it, for example AdminGlobal. Left out: '
                            . 'the domains are listed.',
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' => 'Search translations: words that must all be found, without case, in the '
                            . 'source, the translation of the files or the shop\'s.',
                    ],
                    'missing_only' => [
                        'type' => 'boolean',
                        'description' => 'Only the wordings with no translation at all. Default false.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'With domain: how many wordings, 20 by default, 100 at most.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'With domain: how many wordings to skip, 0 by default.',
                    ],
                ],
                'required' => ['language', 'type'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // The editor's API asks read on the page.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminTranslations')
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
        $selection = self::selection($arguments, Somcp::currentShopId(), $problems);

        $domain = null;
        if (isset($arguments['domain'])) {
            $domain = is_string($arguments['domain']) ? trim($arguments['domain']) : '';
            if ($domain === '') {
                $problems[] = 'domain: the name of a domain, as this tool answers it without domain';
            }
        }
        $search = [];
        if (isset($arguments['search'])) {
            if (!is_string($arguments['search'])) {
                $problems[] = 'search: words, as a text';
            } else {
                $search = preg_split('/\s+/u', trim($arguments['search']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
        }
        $missingOnly = false;
        if (isset($arguments['missing_only'])) {
            if (!is_bool($arguments['missing_only'])) {
                $problems[] = 'missing_only: true or false';
            } else {
                $missingOnly = $arguments['missing_only'];
            }
        }
        $limit = self::DEFAULT_LIMIT;
        if (isset($arguments['limit'])) {
            if (!is_int($arguments['limit']) || $arguments['limit'] < 1 || $arguments['limit'] > self::MAX_LIMIT) {
                $problems[] = 'limit: a whole number from 1 to ' . self::MAX_LIMIT;
            } else {
                $limit = $arguments['limit'];
            }
        }
        $offset = 0;
        if (isset($arguments['offset'])) {
            if (!is_int($arguments['offset']) || $arguments['offset'] < 0) {
                $problems[] = 'offset: a whole number, 0 at least';
            } else {
                $offset = $arguments['offset'];
            }
        }
        if ($problems !== []) {
            return SomcpTool::refused('read', $problems);
        }

        $answer = null;
        $error = '';
        $restore = SomcpBackOffice::asBackOffice();
        try {
            $domains = self::domains($selection, $search);
            if ($domain === null) {
                $answer = self::header($selection) + [
                    'total_translations' => $domains['total_translations'],
                    'total_missing_translations' => $domains['total_missing_translations'],
                    'domains' => $missingOnly ? array_values(array_filter($domains['domains'], static function (array $node): bool {
                        return $node['total_missing_translations'] > 0;
                    })) : $domains['domains'],
                ];
            } else {
                $known = $search === [] ? $domains : self::domains($selection, []);
                if (!in_array($domain, array_column($known['domains'], 'domain'), true)) {
                    $answer = SomcpTool::refused('read', [SomcpTool::notFound('domain', 'domain', $domain, 'somcp_list_translations', 'domain')]);
                } else {
                    $answer = self::wordings($selection, $domain, $search, $missingOnly, $limit, $offset);
                }
            }
        } catch (Throwable $e) {
            // A message of the core may name a folder of the server.
            $error = trim(str_replace(_PS_ROOT_DIR_, '', $e->getMessage()));
        } finally {
            $restore();
        }

        if ($error !== '' || $answer === null) {
            return SomcpTool::text('The translations could not be read: ' . Tools::substr($error !== '' ? $error : 'the builders of the back office are not available', 0, 500) . '.', true);
        }

        return isset($answer['content']) ? $answer : SomcpTool::json($answer);
    }

    /**
     * The language, the type, the theme and the module given, checked as the
     * card checks them. Public so that the change of a wording, which takes
     * the same fields, checks them with the same rules.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $problems
     *
     * @return array{language:string, locale:string, type:string, core_type:string, theme:string|null, module:string|null}
     */
    public static function selection(array $arguments, int $idShop, array &$problems): array
    {
        $selection = ['language' => '', 'locale' => '', 'type' => '', 'core_type' => '', 'theme' => null, 'module' => null];

        if (!isset($arguments['language']) || $arguments['language'] === '') {
            $problems[] = 'language: required';
        } else {
            $selection['language'] = is_string($arguments['language']) ? Tools::strtolower(trim($arguments['language'])) : '';
            $selection['locale'] = SomcpToolExportTranslations::locale($selection['language'], $idShop);
            if ($selection['locale'] === '') {
                $problems[] = SomcpTool::notFound('language', 'language', is_string($arguments['language']) ? $arguments['language'] : json_encode($arguments['language']), 'somcp_list_languages', 'iso_code');
            }
        }

        $type = $arguments['type'] ?? null;
        if ($type === null || $type === '') {
            $problems[] = 'type: required, one of ' . implode(', ', array_keys(self::TYPES));
        } elseif (!is_string($type) || !isset(self::TYPES[$type])) {
            $problems[] = SomcpTool::oneOf('type', array_keys(self::TYPES));
        } else {
            $selection['type'] = $type;
            $selection['core_type'] = self::TYPES[$type];
        }

        if (isset($arguments['theme'])) {
            if ($selection['type'] !== '' && $selection['type'] !== 'front_office') {
                $problems[] = 'theme: only with type front_office';
            } else {
                $name = is_string($arguments['theme']) ? trim($arguments['theme']) : '';
                if ($name === '' || !in_array($name, SomcpToolExportTranslations::installedThemes(), true)) {
                    $problems[] = SomcpTool::notFound('theme', 'theme', is_string($arguments['theme']) ? $arguments['theme'] : json_encode($arguments['theme']), 'somcp_get_theme', 'name');
                } elseif ($name !== 'classic') {
                    // classic is Core (no theme selected): its wordings are the core's.
                    $selection['theme'] = $name;
                }
            }
        }

        if ($selection['type'] === 'module') {
            if (!isset($arguments['module'])) {
                $problems[] = 'module: required with type module - find it with somcp_list_modules';
            } else {
                $module = SomcpToolExportTranslations::module($arguments['module'], $problems);
                $selection['module'] = $module !== '' ? $module : null;
            }
        } elseif (isset($arguments['module']) && $selection['type'] !== '') {
            $problems[] = 'module: only with type module';
        }

        return $selection;
    }

    /**
     * The leaves of the editor's tree, flattened in its order: the nodes that
     * hold wordings, their parents given as their path.
     *
     * @param array{language:string, locale:string, type:string, core_type:string, theme:string|null, module:string|null} $selection
     * @param array<int, string> $search
     *
     * @return array{total_translations:int, total_missing_translations:int, domains:array<int, array<string, mixed>>}
     */
    public static function domains(array $selection, array $search): array
    {
        $factory = SomcpBackOffice::coreService('prestashop.translation.factory.provider_definition');
        $builder = SomcpBackOffice::coreService('prestashop.translation.builder.translation_tree');
        if ($factory === null || $builder === null || !method_exists($factory, 'build') || !method_exists($builder, 'getTree')) {
            throw new RuntimeException('the builders of the back office are not available');
        }

        // As listTreeAction(): the front office of no theme, or of classic,
        // is the core's front office.
        $type = $selection['core_type'];
        $selected = $selection['module'] ?? $selection['theme'];
        if ($type === 'themes' && $selected === null) {
            $type = 'front';
        }
        $tree = $builder->getTree($factory->build($type, $selected), $selection['locale'], $search);
        $root = is_array($tree['tree'] ?? null) ? $tree['tree'] : [];

        $domains = [];
        self::flatten($root, [], $domains);

        return [
            'total_translations' => (int) ($root['total_translations'] ?? 0),
            'total_missing_translations' => (int) ($root['total_missing_translations'] ?? 0),
            'domains' => $domains,
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string> $path
     * @param array<int, array<string, mixed>> $domains
     */
    private static function flatten(array $node, array $path, array &$domains): void
    {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($children === [] && isset($node['full_name'])) {
            $domains[] = [
                'domain' => (string) $node['full_name'],
                'name' => (string) ($node['name'] ?? $node['full_name']),
                'path' => $path,
                'total_translations' => (int) ($node['total_translations'] ?? 0),
                'total_missing_translations' => (int) ($node['total_missing_translations'] ?? 0),
            ];

            return;
        }
        foreach ($children as $child) {
            if (is_array($child)) {
                self::flatten($child, isset($node['name']) ? array_merge($path, [(string) $node['name']]) : $path, $domains);
            }
        }
    }

    /**
     * The wordings of one domain, as listDomainTranslationAction() reads them.
     *
     * @param array{language:string, locale:string, type:string, core_type:string, theme:string|null, module:string|null} $selection
     * @param string $domain
     * @param array<int, string> $search
     * @param bool $missingOnly
     * @param int $limit
     * @param int $offset
     *
     * @return array<string, mixed>
     */
    public static function wordings(array $selection, string $domain, array $search, bool $missingOnly, int $limit, int $offset): array
    {
        $builder = SomcpBackOffice::coreService('prestashop.translation.builder.translation_catalogue');
        if ($builder === null || !method_exists($builder, 'getDomainCatalogue')) {
            throw new RuntimeException('the builders of the back office are not available');
        }

        // The domain Others of the tree is the catalogue messages.
        $catalogueDomain = $domain === ucfirst('messages') ? 'messages' : $domain;
        if ($selection['module'] !== null) {
            $definition = new PrestaShop\PrestaShop\Core\Translation\Storage\Provider\Definition\ModuleProviderDefinition($selection['module']);
        } elseif ($selection['theme'] !== null) {
            $definition = new PrestaShop\PrestaShop\Core\Translation\Storage\Provider\Definition\ThemeProviderDefinition($selection['theme']);
        } else {
            $definition = new PrestaShop\PrestaShop\Core\Translation\Storage\Provider\Definition\CoreDomainProviderDefinition($catalogueDomain);
        }
        $catalogue = $builder->getDomainCatalogue($definition, $selection['locale'], $catalogueDomain, $search);

        $rows = [];
        foreach (is_array($catalogue['data'] ?? null) ? $catalogue['data'] : [] as $message) {
            if (!is_array($message)) {
                continue;
            }
            $row = self::row($message);
            if (!$missingOnly || ($row['file_translation'] === null && $row['shop_translation'] === null)) {
                $rows[] = $row;
            }
        }

        $info = is_array($catalogue['info'] ?? null) ? $catalogue['info'] : [];
        $page = array_slice($rows, $offset, $limit);

        return self::header($selection) + [
            'domain' => $domain,
            'total_translations' => (int) ($info['total_translations'] ?? 0),
            'total_missing_translations' => (int) ($info['total_missing_translations'] ?? 0),
            'returned' => count($page),
            'total_count' => count($rows),
            'offset' => $offset,
            'translations' => $page,
        ];
    }

    /**
     * One wording, under the names of what the editor shows.
     *
     * @param array<string, mixed> $message Message::toArray()
     *
     * @return array{source:string, file_translation:string|null, shop_translation:string|null, shown:string}
     */
    public static function row(array $message): array
    {
        $source = (string) ($message['default'] ?? '');
        $file = isset($message['project']) ? (string) $message['project'] : null;
        $shop = isset($message['user']) ? (string) $message['user'] : null;

        return [
            'source' => $source,
            'file_translation' => $file,
            'shop_translation' => $shop,
            'shown' => $shop ?? $file ?? $source,
        ];
    }

    /**
     * @param array{language:string, locale:string, type:string, core_type:string, theme:string|null, module:string|null} $selection
     *
     * @return array<string, mixed>
     */
    private static function header(array $selection): array
    {
        return [
            'language' => $selection['language'],
            'locale' => $selection['locale'],
            'type' => $selection['type'],
            'theme' => $selection['theme'],
            'module' => $selection['module'],
        ];
    }
}
