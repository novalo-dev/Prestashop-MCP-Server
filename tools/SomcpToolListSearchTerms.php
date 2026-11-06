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
 * somcp_list_search_terms - what visitors typed in the shop's search box.
 *
 * Counted the way Stats > Shop search (statssearch) counts it: the rows the
 * module writes on each search, grouped by the keywords column over the
 * period, Results being the most products a term ever found, and only the
 * terms searched more than once. The grouping is left to SQL so the column's
 * collation decides, as on the screen: "Mug" and "mug" are one term.
 *
 * Without the module nothing is recorded, so the call is refused rather
 * than answered with an empty list that would read as "nobody searched".
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListSearchTerms
{
    /** @var int Terms returned when the caller asks for no particular number. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Most a single call will return. */
    private const MAX_LIMIT = 100;

    /** @var int The screen lists the terms searched more than once. */
    private const DEFAULT_MIN_SEARCHES = 2;

    /** @var string The module that records the searches. */
    private const MODULE = 'statssearch';

    /** @var array<int, string> */
    private const FIELDS = ['date_from', 'date_to', 'min_searches', 'no_results_only', 'limit', 'offset'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_search_terms',
            Somcp::t('List shop search terms'),
            'Lists what visitors typed in the shop\'s own search box over a period, as Stats > Shop search: '
                . 'per term, how many times it was searched and the most products it found (results). By '
                . 'default only the terms searched more than once are listed, as the screen; no_results_only '
                . 'gives what visitors looked for and the catalogue does not have. Refused when the Shop '
                . 'search module (statssearch) is not active, since nothing is recorded without it. Not for '
                . 'search engine queries from outside the shop (Search Console); the products that sold are '
                . 'somcp_list_best_sellers.',
            [
                'type' => 'object',
                'properties' => [
                    'date_from' => [
                        'type' => 'string',
                        'description' => 'First day of the period (From:), YYYY-MM-DD, that day included.',
                    ],
                    'date_to' => [
                        'type' => 'string',
                        'description' => 'Last day of the period (To:), YYYY-MM-DD, that day included. Not before date_from.',
                    ],
                    'min_searches' => [
                        'type' => 'integer',
                        'description' => 'Fewest searches a term needs to be listed: 2 by default, as the screen; '
                            . '1 to include the terms searched once.',
                    ],
                    'no_results_only' => [
                        'type' => 'boolean',
                        'description' => 'True for the terms that found no product at all in the period.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many terms to return, 20 by default and 100 at most.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'How many to skip, to read the next page.',
                    ],
                ],
                'required' => ['date_from', 'date_to'],
                'additionalProperties' => false,
            ],
            [SomcpScope::SALES_READ],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminStats')
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
        // Every mistake is reported in one answer, so the call is corrected
        // in one go (the shape of #169).
        $problems = [];
        foreach (array_keys($arguments) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                $problems[] = '- ' . $field . ': not a field of this tool';
            }
        }

        // The texts of somcp_list_best_sellers, which takes the period the same way.
        $dateFrom = SomcpTool::day($arguments['date_from'] ?? null);
        if ($dateFrom === null) {
            $problems[] = '- date_from: a date, YYYY-MM-DD';
        }
        $dateTo = SomcpTool::day($arguments['date_to'] ?? null);
        if ($dateTo === null) {
            $problems[] = '- date_to: a date, YYYY-MM-DD';
        }
        if ($dateFrom !== null && $dateTo !== null && $dateTo < $dateFrom) {
            $problems[] = '- date_to: a date not before date_from';
        }

        $minSearches = self::DEFAULT_MIN_SEARCHES;
        if (array_key_exists('min_searches', $arguments) && $arguments['min_searches'] !== null) {
            if (!is_int($arguments['min_searches']) || $arguments['min_searches'] < 1) {
                $problems[] = '- min_searches: a whole number, 1 at least';
            } else {
                $minSearches = (int) $arguments['min_searches'];
            }
        }

        if (array_key_exists('no_results_only', $arguments) && $arguments['no_results_only'] !== null
            && !is_bool($arguments['no_results_only'])) {
            $problems[] = '- no_results_only: true or false';
        }
        $noResultsOnly = ($arguments['no_results_only'] ?? false) === true;

        $limit = self::DEFAULT_LIMIT;
        if (array_key_exists('limit', $arguments) && $arguments['limit'] !== null) {
            if (!is_int($arguments['limit']) || $arguments['limit'] < 1 || $arguments['limit'] > self::MAX_LIMIT) {
                $problems[] = '- limit: a whole number from 1 to ' . self::MAX_LIMIT;
            } else {
                // Cast although checked: the value goes into the LIMIT clause.
                $limit = (int) $arguments['limit'];
            }
        }
        $offset = 0;
        if (array_key_exists('offset', $arguments) && $arguments['offset'] !== null) {
            if (!is_int($arguments['offset']) || $arguments['offset'] < 0) {
                $problems[] = '- offset: a whole number, 0 at least';
            } else {
                $offset = (int) $arguments['offset'];
            }
        }

        if ($problems !== [] || $dateFrom === null || $dateTo === null) {
            return SomcpTool::text(
                "Nothing was read. Correct these and call again:\n" . implode("\n", array_unique($problems)),
                true
            );
        }

        if (!Module::isInstalled(self::MODULE) || !Module::isEnabled(self::MODULE)) {
            return SomcpTool::text(
                'Nothing was read: the Shop search module (' . self::MODULE . ') is not installed and enabled, '
                    . 'and the searches typed on the shop are recorded only by it. Without it there is no '
                    . 'search to list, which does not mean nobody searched.',
                true
            );
        }

        // The shop comes from the token: the searches are recorded per shop.
        $idShop = Somcp::currentShopId();
        // The screen hides the terms shorter than the minimum word length of
        // Shop Parameters > Search; doing it in SQL keeps every page full.
        $minLength = max(1, (int) Configuration::get('PS_SEARCH_MINWORDLEN', null, null, $idShop));

        // Grouped by the column itself, so the collation of the install
        // merges the spellings exactly as the screen does.
        $grouped = 'SELECT ss.`keywords`,
                    COUNT(ss.`id_statssearch`) AS `searches`,
                    MAX(ss.`results`) AS `results`,
                    MAX(ss.`date_add`) AS `last_searched`
             FROM `' . _DB_PREFIX_ . 'statssearch` ss
             WHERE ss.`id_shop` = ' . (int) $idShop . '
                AND ss.`date_add` BETWEEN \'' . pSQL($dateFrom) . ' 00:00:00\' AND \'' . pSQL($dateTo) . ' 23:59:59\'
                AND CHAR_LENGTH(ss.`keywords`) >= ' . (int) $minLength . '
             GROUP BY ss.`keywords`
             HAVING COUNT(ss.`id_statssearch`) >= ' . (int) $minSearches
                . ($noResultsOnly ? ' AND MAX(ss.`results`) = 0' : '');

        $db = Db::getInstance();
        $total = (int) $db->getValue('SELECT COUNT(*) FROM (' . $grouped . ') t');

        $rows = $db->executeS(
            $grouped . '
             ORDER BY `searches` DESC, ss.`keywords` ASC
             LIMIT ' . (int) $offset . ', ' . (int) $limit
        ) ?: [];

        $terms = [];
        foreach ($rows as $row) {
            $terms[] = [
                // What the visitor typed, as text: never turned into a link.
                'keywords' => (string) $row['keywords'],
                'searches' => (int) $row['searches'],
                // The most products the term found in the period, not an average.
                'results' => (int) $row['results'],
                // Not on the screen: tells an old gap in the catalogue from a new one.
                'last_searched' => (string) $row['last_searched'],
            ];
        }

        return SomcpTool::json([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'terms' => $terms,
            'returned' => count($terms),
            'total_count' => $total,
            'offset' => $offset,
        ]);
    }
}
