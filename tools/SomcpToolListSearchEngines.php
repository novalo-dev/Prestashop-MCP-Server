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
 * somcp_list_search_engines - the rows of Shop Parameters > Traffic & SEO >
 * Search Engines, each Server with its GET variable.
 *
 * A row tells the shop which part of a referrer's address holds the words a
 * visitor typed in that search engine: SearchEngine::getKeywords() reads them
 * for the first row whose Server is part of the referrer's host, and the
 * keyword statistics are built from what it keeps. Without this list an
 * assistant cannot tell why a search engine brings visits but no keyword.
 *
 * The table has no shop and no language: every shop shares the same rows.
 * Nothing is written here: somcp_create_search_engine adds a row and
 * somcp_update_search_engine changes one.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListSearchEngines
{
    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 100;

    /** @var array<int, string> */
    private const FIELDS = ['id_search_engine', 'server', 'get_variable', 'sort', 'limit', 'offset'];

    /**
     * @var array<string, string> The sortable columns of the back office list, each way,
     *                            in the <column>_<way> shape of somcp_list_stores
     */
    private const SORTS = [
        'id_asc' => '`id_search_engine` ASC',
        'id_desc' => '`id_search_engine` DESC',
        'server_asc' => '`server` ASC',
        'server_desc' => '`server` DESC',
        'get_variable_asc' => '`getvar` ASC',
        'get_variable_desc' => '`getvar` DESC',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_search_engines',
            Somcp::t('List the search engines'),
            'Lists the search engines of Shop Parameters > Traffic & SEO > Search Engines: each id_search_engine, '
                . 'Server and GET variable, the part of a referrer\'s address that holds the words a visitor typed, '
                . 'which the keyword statistics are built from. Filters, sort and paging of that list. Read only. '
                . 'Not for the keywords visitors typed (the statistics) nor the shop\'s own search (Shop '
                . 'Parameters > Search).',
            [
                'type' => 'object',
                'properties' => [
                    'id_search_engine' => [
                        'type' => 'integer',
                        'description' => 'Keep the row with this identifier (ID), as the filter of the list: an '
                            . 'identifier no row has gives an empty list.',
                    ],
                    'server' => [
                        'type' => 'string',
                        'description' => 'Part of the Server, without case, as the Server filter of the list.',
                    ],
                    'get_variable' => [
                        'type' => 'string',
                        'description' => 'Part of the GET variable, without case, as the filter of the list.',
                    ],
                    'sort' => [
                        'type' => 'string',
                        'enum' => array_keys(self::SORTS),
                        'description' => 'Order of the results: the sortable columns of the back office list (ID, '
                            . 'Server, GET variable), each way. By ID (id_asc) by default, as the screen.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many rows to return, 20 by default and 100 at most.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'How many to skip, to read the next page.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_READ],
            [self::class, 'handle'],
            // The rows are managed on their own tab of Traffic & SEO, and
            // reading them takes the view right there.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminSearchEngines')
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
        // Every mistake of the call in one refusal, so it is corrected in one go.
        $problems = SomcpTool::unknownFields($arguments, self::FIELDS);

        $idSearchEngine = 0;
        if (array_key_exists('id_search_engine', $arguments) && $arguments['id_search_engine'] !== null) {
            $idSearchEngine = SomcpTool::id($arguments, 'id_search_engine');
            if ($idSearchEngine === 0) {
                $problems[] = SomcpTool::notAnIdentifier('id_search_engine');
            }
        }
        foreach (['server', 'get_variable'] as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== null && !is_string($arguments[$field])) {
                $problems[] = $field . ': a text';
            }
        }

        // An order outside the list is named rather than dropped for the
        // default one, which would answer a different question.
        $sort = 'id_asc';
        if (array_key_exists('sort', $arguments) && $arguments['sort'] !== null) {
            if (!is_string($arguments['sort']) || !isset(self::SORTS[$arguments['sort']])) {
                $problems[] = SomcpTool::oneOf('sort', array_keys(self::SORTS));
            } else {
                $sort = $arguments['sort'];
            }
        }

        $limit = self::DEFAULT_LIMIT;
        if (array_key_exists('limit', $arguments) && $arguments['limit'] !== null) {
            if (!is_int($arguments['limit']) || $arguments['limit'] < 1 || $arguments['limit'] > self::MAX_LIMIT) {
                $problems[] = 'limit: a whole number from 1 to ' . self::MAX_LIMIT;
            } else {
                // Cast although checked: the value goes into the LIMIT clause.
                $limit = (int) $arguments['limit'];
            }
        }
        $offset = 0;
        if (array_key_exists('offset', $arguments) && $arguments['offset'] !== null) {
            if (!is_int($arguments['offset']) || $arguments['offset'] < 0) {
                $problems[] = 'offset: a whole number, 0 at least';
            } else {
                $offset = (int) $arguments['offset'];
            }
        }

        if ($problems !== []) {
            return SomcpTool::refused('read', $problems);
        }

        $conditions = [];
        if ($idSearchEngine > 0) {
            $conditions[] = '`id_search_engine` = ' . (int) $idSearchEngine;
        }
        foreach (['server' => '`server`', 'get_variable' => '`getvar`'] as $field => $column) {
            if (isset($arguments[$field]) && is_string($arguments[$field]) && trim($arguments[$field]) !== '') {
                $conditions[] = $column . " LIKE '%" . self::likeText(trim($arguments[$field])) . "%'";
            }
        }
        $where = $conditions === [] ? '1' : implode(' AND ', $conditions);

        $db = Db::getInstance();
        $total = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'search_engine` WHERE ' . $where);

        $query = new DbQuery();
        $query->select('`id_search_engine`, `server`, `getvar`');
        $query->from('search_engine');
        $query->where($where);
        $query->orderBy(self::order($sort));
        $query->limit($limit, $offset);

        $engines = [];
        foreach ($db->executeS($query) ?: [] as $row) {
            $engines[] = self::row($row);
        }

        return SomcpTool::json([
            'search_engines' => $engines,
            'returned' => count($engines),
            'total_count' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * A search engine as the list answers it, from its row of search_engine.
     * Public so the tools that create and change a row answer it in the same
     * shape, and the assistant can give it back as it is.
     *
     * @param array<string, mixed> $row id_search_engine, server and getvar
     *
     * @return array{id_search_engine: int, server: string, get_variable: string}
     */
    public static function row(array $row): array
    {
        // get_variable, the word of the screen, rather than the column name.
        return [
            'id_search_engine' => (int) ($row['id_search_engine'] ?? 0),
            'server' => (string) ($row['server'] ?? ''),
            'get_variable' => (string) ($row['getvar'] ?? ''),
        ];
    }

    /**
     * The ORDER BY of a sort, taken from the fixed map and never from the
     * argument itself; the identifier breaks ties, so a page read twice holds
     * the same rows.
     *
     * @param string $sort a key of SORTS, already checked
     *
     * @return string
     */
    private static function order(string $sort): string
    {
        $order = self::SORTS[$sort] ?? self::SORTS['id_asc'];

        return $sort === 'id_asc' || $sort === 'id_desc' ? $order : $order . ', `id_search_engine` ASC';
    }

    /**
     * A text for a LIKE pattern: % and _ match themselves, as the merchant
     * typing them in the filter of the list expects, rather than anything.
     *
     * @param string $text
     *
     * @return string
     */
    private static function likeText(string $text): string
    {
        return pSQL(addcslashes($text, '%_\\'));
    }
}
