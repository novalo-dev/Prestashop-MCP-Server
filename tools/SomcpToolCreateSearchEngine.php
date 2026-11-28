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
 * somcp_create_search_engine - Add new search engine then Save in Shop
 * Parameters > Traffic & SEO > Search Engines.
 *
 * The back office (AddSearchEngineHandler on 9, the same form on 8) fills a
 * SearchEngine, validates it and adds it, and refuses nothing else. A second
 * row for a server already listed is refused here all the same:
 * SearchEngine::getKeywords() takes the first row that matches the referrer,
 * so the second one would never be read and the merchant would wonder why
 * its keywords never show. The checks live in plan(), public and given the
 * row a change is made to, so a tool that changes a row refuses the same
 * values in the same words.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in:
// the answer is the row of the list, built by its function.
require_once __DIR__ . '/SomcpToolListSearchEngines.php';

class SomcpToolCreateSearchEngine
{
    /** @var array<int, string> */
    private const FIELDS = ['server', 'get_variable'];

    /** The sizes of search_engine.server and search_engine.getvar, as the form caps them. */
    private const SERVER_MAX = 64;

    private const GET_VARIABLE_MAX = 16;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_search_engine',
            Somcp::t('Create a search engine'),
            'Adds a search engine to Shop Parameters > Traffic & SEO > Search Engines, as Add new search engine: '
                . 'its Server (a host name or part of one, such as google) and its GET variable (the part of the '
                . 'address that holds the words a visitor typed), so the keyword statistics read them. One row per '
                . 'call. Changes the shop: ask first. A server already listed is refused with the row that has it; '
                . 'read the rows with somcp_list_search_engines.',
            [
                'type' => 'object',
                'properties' => [
                    'server' => [
                        'type' => 'string',
                        'description' => 'The Server: a host name or a part of one, such as google or search.yahoo, '
                            . '64 characters at most, kept in lower case. Not one another row already has.',
                    ],
                    'get_variable' => [
                        'type' => 'string',
                        'description' => 'The $_GET variable of the search engine\'s address that holds the words '
                            . 'typed, such as q: letters, digits, _ and - only, 16 characters at most.',
                    ],
                ],
                'required' => ['server', 'get_variable'],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_WRITE],
            [self::class, 'handle'],
            // Add new search engine of Traffic & SEO takes the add right on
            // the tab of the search engines.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminSearchEngines')
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
        $plan = self::plan($arguments, null, $errors);
        if ($errors !== []) {
            return SomcpTool::refused('created', $errors);
        }

        $engine = new SearchEngine();
        $engine->server = (string) $plan['server'];
        $engine->getvar = (string) $plan['get_variable'];
        try {
            $done = $engine->validateFields(false) === true && $engine->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done || (int) $engine->id <= 0) {
            SomcpLogger::log('search_engine_create_failed', SomcpLogger::OUTCOME_FAILURE, 'server ' . $engine->server
                . ': ' . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text('The search engine could not be created: ' . $failure . '.', true);
        }
        $idSearchEngine = (int) $engine->id;

        SomcpLogger::log('search_engine_created', SomcpLogger::OUTCOME_SUCCESS, 'search engine ' . $idSearchEngine
            . ' (' . $engine->server . ')', '', $idEmployee);

        return SomcpTool::json([
            'created' => true,
            'id_search_engine' => $idSearchEngine,
            'search_engine' => self::stored($idSearchEngine),
        ]);
    }

    /**
     * The values a call writes, checked: server and get_variable, each only
     * when given. On a creation ($idSearchEngine null) both are required; on
     * a change only the fields given are planned, and the row itself does
     * not count as the other row holding its server.
     *
     * @param array<string, mixed> $arguments
     * @param int|null $idSearchEngine the row changed, null for a new one
     * @param array<int, string> $errors the lines of the refusal, appended to
     *
     * @return array<string, string> the cleaned values, keyed by field
     */
    public static function plan(array $arguments, ?int $idSearchEngine, array &$errors): array
    {
        $plan = [];
        $creating = $idSearchEngine === null;

        $server = self::textOf($arguments, 'server', $creating, $errors);
        if ($server !== null) {
            // Lower case, as the list shows them: the referrer's host is
            // compared with it, and two rows differing by case are one server.
            $server = Tools::strtolower($server);
            if (Tools::strlen($server) > self::SERVER_MAX) {
                $errors[] = 'server: ' . self::SERVER_MAX . ' characters at most';
            } elseif (!Validate::isUrl($server)) {
                $errors[] = 'server: a host name or a part of one, such as google or search.yahoo';
            } else {
                $other = (int) Db::getInstance()->getValue(
                    'SELECT `id_search_engine` FROM `' . _DB_PREFIX_ . 'search_engine`'
                    . " WHERE LOWER(`server`) = '" . pSQL($server) . "'"
                    . ($creating ? '' : ' AND `id_search_engine` <> ' . (int) $idSearchEngine)
                    . ' ORDER BY `id_search_engine` ASC'
                );
                if ($other > 0) {
                    $errors[] = 'server: already the server of search engine ' . $other
                        . ' - change that one rather than adding another';
                } else {
                    $plan['server'] = $server;
                }
            }
        }

        $getVariable = self::textOf($arguments, 'get_variable', $creating, $errors);
        if ($getVariable !== null) {
            if (Tools::strlen($getVariable) > self::GET_VARIABLE_MAX) {
                $errors[] = 'get_variable: ' . self::GET_VARIABLE_MAX . ' characters at most';
            } elseif (!Validate::isModuleName($getVariable)) {
                $errors[] = 'get_variable: letters, digits, _ and - only';
            } else {
                $plan['get_variable'] = $getVariable;
            }
        }

        return $plan;
    }

    /**
     * A search engine read back from the table, in the shape of
     * somcp_list_search_engines: what the assistant shows the merchant is
     * what the shop stored.
     *
     * @param int $idSearchEngine
     *
     * @return array{id_search_engine: int, server: string, get_variable: string}|null
     */
    public static function stored(int $idSearchEngine): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT `id_search_engine`, `server`, `getvar` FROM `' . _DB_PREFIX_ . 'search_engine`'
            . ' WHERE `id_search_engine` = ' . (int) $idSearchEngine
        );

        return is_array($row) ? SomcpToolListSearchEngines::row($row) : null;
    }

    /**
     * A text argument trimmed, or null when it is left out or wrong (the
     * reason then in $errors). Left out is a mistake only on a creation; an
     * empty text never is a value, the form refusing it on both.
     *
     * @param array<string, mixed> $arguments
     * @param string $field
     * @param bool $required
     * @param array<int, string> $errors
     *
     * @return string|null
     */
    private static function textOf(array $arguments, string $field, bool $required, array &$errors): ?string
    {
        if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
            if ($required) {
                $errors[] = $field . ': required';
            }

            return null;
        }
        if (!is_string($arguments[$field])) {
            $errors[] = $field . ': a text';

            return null;
        }
        $value = trim($arguments[$field]);
        if ($value === '') {
            $errors[] = $field . ($required ? ': required' : ': cannot be empty');

            return null;
        }

        return $value;
    }
}
