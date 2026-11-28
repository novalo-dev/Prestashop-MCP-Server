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
 * somcp_update_search_engine - Edit then Save on a row of Shop Parameters >
 * Traffic & SEO > Search Engines.
 *
 * The back office (EditSearchEngineHandler on 9, the same form on 8) loads
 * the row, sets its Server and its $_GET variable, validates and updates it.
 * Only the fields given change here, checked by the plan() of
 * somcp_create_search_engine, so a row is changed under the rules it was
 * added with; the row of the list given back as it is changes nothing.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in:
// the checks and the row answered are theirs.
require_once __DIR__ . '/SomcpToolCreateSearchEngine.php';
require_once __DIR__ . '/SomcpToolListSearchEngines.php';

class SomcpToolUpdateSearchEngine
{
    /** @var array<int, string> */
    private const FIELDS = ['id_search_engine', 'server', 'get_variable'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_search_engine',
            Somcp::t('Change a search engine'),
            'Changes a search engine of Shop Parameters > Traffic & SEO > Search Engines, as Edit: its Server and '
                . 'its GET variable, by the id_search_engine of somcp_list_search_engines. Only the fields given '
                . 'change, under the rules of somcp_create_search_engine; the row given back as read changes '
                . 'nothing. One row per call. Changes the shop: ask first. To add a row, use '
                . 'somcp_create_search_engine.',
            [
                'type' => 'object',
                'properties' => [
                    'id_search_engine' => [
                        'type' => 'integer',
                        'description' => 'The ID of the row, from somcp_list_search_engines.',
                    ],
                    'server' => [
                        'type' => 'string',
                        'description' => 'The new Server: a host name or a part of one, such as google or '
                            . 'search.yahoo, 64 characters at most, kept in lower case. Not one another row has.',
                    ],
                    'get_variable' => [
                        'type' => 'string',
                        'description' => 'The new $_GET variable that holds the words typed, such as q: letters, '
                            . 'digits, _ and - only, 16 characters at most.',
                    ],
                ],
                'required' => ['id_search_engine'],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_WRITE],
            [self::class, 'handle'],
            // Edit on a row of the search engines takes the edit right on
            // their tab of Traffic & SEO.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminSearchEngines')
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
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        // Every mistake of the call in one refusal, so it is corrected in one go.
        $errors = SomcpTool::unknownFields($arguments, self::FIELDS);

        $stored = null;
        $idSearchEngine = SomcpTool::id($arguments, 'id_search_engine');
        if (!array_key_exists('id_search_engine', $arguments) || $arguments['id_search_engine'] === null) {
            $errors[] = 'id_search_engine: required - find it with somcp_list_search_engines';
        } elseif ($idSearchEngine === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_search_engine');
        } else {
            $stored = SomcpToolCreateSearchEngine::stored($idSearchEngine);
            if ($stored === null) {
                $errors[] = SomcpTool::notFound('id_search_engine', 'search engine', $idSearchEngine, 'somcp_list_search_engines');
            }
        }

        // Given the row, even an unknown one, so the fields left out stay
        // optional and its own server is not read as another row's.
        $plan = SomcpToolCreateSearchEngine::plan($arguments, $idSearchEngine, $errors);
        if ($errors !== [] || $stored === null) {
            return SomcpTool::refused('changed', $errors);
        }

        // A value equal to the stored one is no change, so a row given back
        // as read writes nothing.
        $changes = [];
        foreach ($plan as $field => $value) {
            if ($value !== $stored[$field]) {
                $changes[$field] = $value;
            }
        }
        if ($changes === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The search engine already holds every value given. Nothing was written.',
                'search_engine' => $stored,
            ]);
        }

        $engine = new SearchEngine($idSearchEngine);
        if (isset($changes['server'])) {
            $engine->server = $changes['server'];
        }
        if (isset($changes['get_variable'])) {
            $engine->getvar = $changes['get_variable'];
        }
        try {
            $done = Validate::isLoadedObject($engine) && $engine->validateFields(false) === true && $engine->update();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('search_engine_update_failed', SomcpLogger::OUTCOME_FAILURE, 'search engine '
                . $idSearchEngine . ': ' . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text('The search engine could not be changed: ' . $failure
                . '. Read it with somcp_list_search_engines before calling again.', true);
        }

        $fields = array_keys($changes);
        SomcpLogger::log('search_engine_updated', SomcpLogger::OUTCOME_SUCCESS, 'search engine ' . $idSearchEngine
            . ': ' . implode(', ', $fields), '', $idEmployee);

        $result = [
            'changed' => true,
            'fields' => $fields,
            'search_engine' => SomcpToolCreateSearchEngine::stored($idSearchEngine),
        ];
        if (isset($changes['server'])) {
            // The old server no longer matches any row: its visitors are
            // counted, but what they typed is not read any more.
            $result['warning'] = 'Visitors sent by ' . $stored['server'] . ' are no longer told apart: their '
                . 'keywords are not read any more.';
        }

        return SomcpTool::json($result);
    }
}
