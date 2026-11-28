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
 * somcp_delete_search_engine - Delete on a row of Shop Parameters > Traffic &
 * SEO > Search Engines.
 *
 * The back office (DeleteSearchEngineHandler on 9, the same list on 8) loads
 * the row and calls SearchEngine::delete(), the plain ObjectModel deletion:
 * the table has no shop and no language, and no other table points to it.
 * The keywords already read stay in the statistics.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in:
// the row answered is the one the list gives.
require_once __DIR__ . '/SomcpToolCreateSearchEngine.php';
require_once __DIR__ . '/SomcpToolListSearchEngines.php';

class SomcpToolDeleteSearchEngine
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_search_engine',
            Somcp::t('Delete a search engine'),
            'Deletes a search engine of Shop Parameters > Traffic & SEO > Search Engines, as Delete, by the '
                . 'id_search_engine of somcp_list_search_engines, one row per call. Answers the row it was, so it '
                . 'can be added again with somcp_create_search_engine. The keywords of its visitors stop being '
                . 'read; those already read stay. Changes the shop and cannot be undone: ask first, naming the '
                . 'Server. To correct a row, use somcp_update_search_engine.',
            [
                'type' => 'object',
                'properties' => [
                    'id_search_engine' => [
                        'type' => 'integer',
                        'description' => 'The ID of the row, from somcp_list_search_engines.',
                    ],
                ],
                'required' => ['id_search_engine'],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_WRITE],
            [self::class, 'handle'],
            // Delete on a row of the search engines takes the delete right on
            // their tab of Traffic & SEO.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminSearchEngines')
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
        $errors = SomcpTool::unknownFields($arguments, ['id_search_engine']);

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

        if ($errors !== [] || $stored === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        try {
            $engine = new SearchEngine($idSearchEngine);
            $done = Validate::isLoadedObject($engine) && (bool) $engine->delete();
            $failure = $done ? '' : 'PrestaShop refused to delete it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('search_engine_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'search engine '
                . $idSearchEngine . ': ' . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text('The search engine could not be deleted: ' . $failure . '.', true);
        }

        SomcpLogger::log('search_engine_deleted', SomcpLogger::OUTCOME_SUCCESS, 'search engine ' . $idSearchEngine
            . ' (' . $stored['server'] . ')', '', $idEmployee);

        $warning = 'Visitors sent by ' . $stored['server'] . ' are no longer told apart: their keywords are not '
            . 'read any more. The keywords already read stay in the statistics.';
        $other = self::matching((string) $stored['server']);
        if ($other !== null) {
            $warning .= ' Their visits are now read with search engine ' . $other['id_search_engine'] . ' ('
                . $other['server'] . ').';
        }

        return SomcpTool::json([
            'deleted' => true,
            'id_search_engine' => $idSearchEngine,
            'search_engine' => $stored,
            'warning' => $warning,
        ]);
    }

    /**
     * The row that now reads the visitors of a deleted server, if any.
     *
     * SearchEngine::getKeywords() takes the first row whose Server is part of
     * the referrer's host, so a shorter row (yahoo for search.yahoo) goes on
     * reading those visits once the longer one is gone.
     *
     * @param string $server
     *
     * @return array<string, mixed>|null
     */
    private static function matching(string $server): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT `id_search_engine`, `server`, `getvar` FROM `' . _DB_PREFIX_ . 'search_engine`'
            . ' WHERE `server` <> \'\' AND LOCATE(`server`, \'' . pSQL($server) . '\') > 0'
            . ' ORDER BY `id_search_engine` ASC'
        );

        return is_array($row) ? SomcpToolListSearchEngines::row($row) : null;
    }
}
