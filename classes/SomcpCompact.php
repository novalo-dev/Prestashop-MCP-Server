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
 * SomcpCompact - the shop's tools behind three, for the assistants that
 * struggle with a long list.
 *
 * Each tool an assistant is offered costs it room on every request, and some
 * clients cap the count (128 in VS Code). In compact mode tools/list carries
 * somcp_ping and the three tools below: the assistant searches the tool it
 * needs, reads its parameters, then runs it through one tool for reads and one
 * for changes.
 *
 * Two runners rather than one, because clients decide from a tool's own
 * annotations whether to ask the merchant first. One runner marked as a read
 * would let a refund through unasked; marked as a change, it would ask before
 * every lookup. Each runner refuses the tools of the other kind.
 *
 * Nothing is remembered between calls: the protocol's current revision has no
 * session, so every call names the tool it runs. And nothing is allowed that
 * the full list would not allow: the endpoint unwraps a runner's call into the
 * call of the tool it names, which then meets every check a direct call meets -
 * switch, scope, back office permission - on the same path.
 *
 * The descriptions below are read by the assistant, not by the merchant, so
 * they stay in English like every tool's.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpCompact
{
    public const SEARCH_TOOL = 'somcp_search_tools';
    public const READ_TOOL = 'somcp_run_read_tool';
    public const WRITE_TOOL = 'somcp_run_write_tool';

    /** @var int Tools a search describes in full, schemas included, at most. */
    private const MAX_DESCRIBED = 10;

    /**
     * Whether this shop lists its tools in compact form.
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        return SomcpConfig::get(SomcpConfig::TOOLS_COMPACT) === '1';
    }

    /**
     * The three tools, as the registry's own are built.
     *
     * Not registered: they are neither switched, nor searched, nor offered to
     * the built-in assistant, which has its own search.
     *
     * @return array<int, SomcpTool>
     */
    public static function tools(): array
    {
        $runArguments = [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Exact name of the tool to run, as somcp_search_tools gives it, e.g. somcp_get_order.',
                ],
                'arguments' => [
                    'type' => 'object',
                    'description' => 'The arguments of that tool, following the inputSchema somcp_search_tools gives for it. {} when it takes none.',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
        $nothing = SomcpTool::actsOnNothing();

        return [
            new SomcpTool(
                self::SEARCH_TOOL,
                Somcp::t('Search the shop tools'),
                'Finds the tools of this PrestaShop shop you may use, and gives their parameters. '
                    . 'The shop lists its tools in compact form: call this first, then run what you found with '
                    . 'somcp_run_read_tool (reads) or somcp_run_write_tool (changes), as each result says. '
                    . 'Give keywords in English ("order status", "stock", "category"), or the exact names a guide gives. '
                    . 'With neither, lists every tool you may use, by group, names only. Reads only.',
                [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Keywords in English, e.g. "refund order". Up to ' . self::MAX_DESCRIBED . ' tools come back, best match first, each with its inputSchema.',
                        ],
                        'names' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Exact tool names to describe in full, e.g. the ones a guide names. Up to ' . self::MAX_DESCRIBED . '.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                [SomcpScope::ACCESS],
                [self::class, 'search'],
                $nothing
            ),
            new SomcpTool(
                self::READ_TOOL,
                Somcp::t('Run a read tool'),
                'Runs one tool of this shop that only reads, found with somcp_search_tools, and returns its answer. '
                    . 'Refuses a tool that changes the shop: run that one with somcp_run_write_tool. '
                    . 'Checked like a direct call: the switch, the scope of the connection and the back office permission of the account.',
                $runArguments,
                [SomcpScope::ACCESS],
                [self::class, 'unreachable'],
                $nothing
            ),
            new SomcpTool(
                self::WRITE_TOOL,
                Somcp::t('Run a tool that changes the shop'),
                'Runs one tool of this shop that changes something - an order, a product, a setting - found with '
                    . 'somcp_search_tools, and returns its answer. Say to the merchant what it will change before running it. '
                    . 'Refuses a tool that only reads: run that one with somcp_run_read_tool. '
                    . 'Checked like a direct call: the switch, the scope of the connection and the back office permission of the account.',
                $runArguments,
                [SomcpScope::ACCESS],
                [self::class, 'unreachable'],
                [
                    'permissions' => [],
                    // The flags of a change, whatever it runs: they are what
                    // makes a client ask the merchant before the call, and the
                    // runner cannot know in advance which tool it will be given.
                    'annotations' => [
                        'readOnlyHint' => false,
                        'destructiveHint' => true,
                        'idempotentHint' => false,
                        'openWorldHint' => false,
                    ],
                ]
            ),
        ];
    }

    /**
     * The names of the three tools.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return [self::SEARCH_TOOL, self::READ_TOOL, self::WRITE_TOOL];
    }

    /**
     * The tools/list of compact mode: somcp_ping when the token may see it,
     * then the three tools.
     *
     * @param array<int, array<string, mixed>> $full what the full list would carry for this token
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listFrom(array $full): array
    {
        $listed = [];
        foreach ($full as $described) {
            if (($described['name'] ?? '') === 'somcp_ping') {
                $listed[] = $described;
            }
        }
        foreach (self::tools() as $tool) {
            $listed[] = $tool->describe();
        }

        return $listed;
    }

    /**
     * Turns a runner's call into the call of the tool it names.
     *
     * @param string $name the tool called
     * @param array<string, mixed> $arguments its arguments
     *
     * @return array{name:string, arguments:array<string, mixed>, writes:bool, error:string}|null
     *                                                                                            null when the call is not a runner's
     */
    public static function unwrap(string $name, array $arguments): ?array
    {
        if ($name !== self::READ_TOOL && $name !== self::WRITE_TOOL) {
            return null;
        }

        $inner = trim((string) ($arguments['name'] ?? ''));
        $innerArguments = $arguments['arguments'] ?? [];
        $error = '';
        if ($inner === '') {
            $error = 'Give the name of the tool to run, as somcp_search_tools gives it.';
        } elseif (in_array($inner, self::names(), true) || $inner === 'somcp_ping') {
            // Nothing gained by running one of these through a runner, and a
            // runner running a runner is a loop to refuse rather than unroll.
            $error = $inner . ' is called directly, not through ' . $name . '.';
        } elseif (!is_array($innerArguments)) {
            $error = 'arguments must be an object: the arguments of ' . Tools::substr($inner, 0, 64) . ', {} when it takes none.';
        }

        return [
            'name' => Tools::substr($inner, 0, SomcpToolRegistry::MAX_NAME_LENGTH),
            'arguments' => is_array($innerArguments) ? $innerArguments : [],
            'writes' => $name === self::WRITE_TOOL,
            'error' => $error,
        ];
    }

    /**
     * Why a runner may not run this tool, empty when it may.
     *
     * @param SomcpTool $tool the tool named
     * @param bool $writes true for somcp_run_write_tool
     *
     * @return string
     */
    public static function wrongRunner(SomcpTool $tool, bool $writes): string
    {
        $changes = SomcpToolRegistry::writes($tool);
        if ($changes === $writes) {
            return '';
        }

        return $changes
            ? $tool->getName() . ' changes the shop: run it with ' . self::WRITE_TOOL . ', after saying to the merchant what it will change.'
            : $tool->getName() . ' only reads: run it with ' . self::READ_TOOL . '.';
    }

    /**
     * A failed call through a runner, with the parameters of the tool it ran.
     *
     * An assistant that called a tool it found but did not describe guesses
     * the arguments; handing the schema back with the error saves it a search
     * and a second guess.
     *
     * @param array<string, mixed> $result the tool's own answer
     * @param SomcpTool $tool
     *
     * @return array<string, mixed>
     */
    public static function withSchema(array $result, SomcpTool $tool): array
    {
        if (($result['isError'] ?? false) !== true || !is_array($result['content'] ?? null)) {
            return $result;
        }
        $schema = json_encode($tool->describe()['inputSchema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $result['content'][] = [
            'type' => 'text',
            'text' => 'Parameters of ' . $tool->getName() . ': ' . ($schema === false ? '{}' : $schema),
        ];

        return $result;
    }

    /**
     * The answer to a name the runners do not know, or may not run.
     *
     * The same whether the tool does not exist, is switched off or is out of
     * this account's reach, as for a direct call: telling them apart maps the
     * shop.
     *
     * @param string $name
     *
     * @return string
     */
    public static function unknown(string $name): string
    {
        return 'Unknown tool: ' . Tools::substr($name, 0, 60) . '. Find the tools you may use with ' . self::SEARCH_TOOL . '.';
    }

    /**
     * somcp_search_tools.
     *
     * Only what the token and its account may use, and only what is switched
     * on for connected assistants: the search shows no more than the full
     * list would.
     *
     * @param array<string, mixed> $arguments query and names
     * @param array<string, mixed> $context the token's scopes and employee
     *
     * @return array<string, mixed>
     */
    public static function search(array $arguments, array $context): array
    {
        $registry = SomcpToolRegistry::getInstance();
        $scopes = (string) ($context['scopes'] ?? '');
        $idEmployee = (int) ($context['id_employee'] ?? 0);
        $query = is_string($arguments['query'] ?? null) ? $arguments['query'] : '';
        $names = array_values(array_unique(array_filter(array_map(
            static function ($name): string {
                return is_string($name) ? trim($name) : '';
            },
            is_array($arguments['names'] ?? null) ? $arguments['names'] : []
        ))));
        $keywords = preg_match('/[a-z0-9_]/', strtolower($query)) === 1;

        if (!$keywords && $names === []) {
            return SomcpTool::json(['groups' => self::groups($registry->search('', $scopes, $idEmployee))]);
        }

        $found = [];
        $unknown = [];
        $usable = $registry->search('', $scopes, $idEmployee);
        $byName = [];
        foreach ($usable as $tool) {
            $byName[$tool->getName()] = $tool;
        }
        foreach (array_slice($names, 0, self::MAX_DESCRIBED) as $name) {
            if (isset($byName[$name])) {
                $found[$name] = $byName[$name];
            } else {
                $unknown[] = Tools::substr($name, 0, 64);
            }
        }
        if ($keywords) {
            foreach ($registry->search($query, $scopes, $idEmployee) as $tool) {
                if (count($found) >= self::MAX_DESCRIBED) {
                    break;
                }
                $found[$tool->getName()] = $tool;
            }
        }

        $tools = [];
        foreach ($found as $tool) {
            $described = $tool->describe();
            $tools[] = [
                'name' => $tool->getName(),
                'title' => $described['title'],
                'run_with' => SomcpToolRegistry::writes($tool) ? self::WRITE_TOOL : self::READ_TOOL,
                'description' => $described['description'],
                'inputSchema' => $described['inputSchema'],
            ];
        }

        $answer = ['tools' => $tools];
        if ($unknown !== []) {
            // One list for every reason a name is not here, as for a call.
            $answer['unknown'] = $unknown;
        }
        if ($tools === []) {
            $answer['hint'] = 'Nothing matches. Try other keywords in English, or call with no argument for every tool you may use.';
        }

        return SomcpTool::json($answer);
    }

    /**
     * Every tool a search with no argument lists, by the group the settings
     * page shows it in.
     *
     * @param array<int, SomcpTool> $tools
     *
     * @return array<int, array{group:string, tools:array<int, array{name:string, title:string, run_with:string}>}>
     */
    private static function groups(array $tools): array
    {
        $groups = [];
        foreach ($tools as $tool) {
            $group = SomcpScope::groupOf($tool->getRequiredScopes());
            $groups[$group][] = [
                'name' => $tool->getName(),
                'title' => (string) $tool->describe()['title'],
                'run_with' => SomcpToolRegistry::writes($tool) ? self::WRITE_TOOL : self::READ_TOOL,
            ];
        }
        $list = [];
        foreach ($groups as $group => $members) {
            $list[] = ['group' => (string) $group, 'tools' => $members];
        }

        return $list;
    }

    /**
     * The block put in front of a guide in compact mode.
     *
     * A guide names the tools it uses, and in compact mode none of them is in
     * the assistant's list: without this it looks for tools it cannot see.
     * First, so it is read before the steps that name them. Built from the
     * tools the guide declares and from each tool's own annotation, never from
     * the guide's text.
     *
     * @param SomcpPrompt $prompt
     *
     * @return string empty when the guide names no tool of the shop
     */
    public static function promptBlock(SomcpPrompt $prompt): string
    {
        $registry = SomcpToolRegistry::getInstance();
        $reads = [];
        $writes = [];
        foreach ($prompt->getTools() as $name) {
            $tool = $registry->get($name);
            if ($tool === null || in_array($name, self::names(), true) || $name === 'somcp_ping') {
                continue;
            }
            if (SomcpToolRegistry::writes($tool)) {
                $writes[] = $name;
            } else {
                $reads[] = $name;
            }
        }
        if ($reads === [] && $writes === []) {
            return '';
        }

        $lines = [
            '## How to call the tools of this guide',
            '',
            'This shop lists its tools in compact form: the tools this guide names are not in your tool list. '
                . 'Read the parameters of the ones you need with ' . self::SEARCH_TOOL . ' (names), then run them:',
        ];
        if ($reads !== []) {
            $lines[] = '- through ' . self::READ_TOOL . ': ' . implode(', ', $reads);
        }
        if ($writes !== []) {
            $lines[] = '- through ' . self::WRITE_TOOL . ': ' . implode(', ', $writes);
        }

        return implode("\n", $lines) . "\n\n";
    }

    /**
     * The handler of the two runners, which the endpoint never reaches.
     *
     * A runner's call is unwrapped before any tool runs. Something calling
     * this directly - another caller of the registry, a test - is told so
     * rather than handed a silent success.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public static function unreachable(array $arguments, array $context): array
    {
        return SomcpTool::text('This tool is run by the MCP endpoint only.', true);
    }
}
