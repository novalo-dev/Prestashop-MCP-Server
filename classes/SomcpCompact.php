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
 * somcp_ping and three tools: the assistant searches the tool it needs, reads
 * its parameters, then runs it through one tool for reads and one for changes.
 *
 * The mode itself is the compact mode of novalo/mcp-tool-discovery: its three
 * tools, its search over the fields the assistant fills in, the rule that each
 * runner refuses the other kind of tool. This class only says what is the
 * shop's: the names under the somcp_ prefix, the titles in the merchant's
 * language, the groups of the settings page, somcp_ping kept in the list, and
 * the gate (Novalo\Somcp\Discovery\ShopGate) that decides what a caller sees.
 * The endpoint keeps every refusal with a reason of its own, as for a direct
 * call.
 *
 * Nothing is remembered between calls: the protocol's current revision has no
 * session, so every call names the tool it runs.
 */

use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Exception\InvalidTool;
use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Search\SearchQuery;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;
use Novalo\Somcp\Discovery\ShopGate;

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpCompact
{
    /** @var string Put before the library's three names, so they read as the shop's. */
    private const PREFIX = 'somcp_';

    // Written out rather than built from CompactMode's constants: these are
    // read on every request, before the library is loaded, and a constant
    // naming a class that is not loaded yet stops the script. The library's
    // names are its prefix plus search_tools, run_read_tool and run_write_tool.
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
     * The names of the three tools.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return [self::SEARCH_TOOL, self::READ_TOOL, self::WRITE_TOOL];
    }

    /**
     * Whether a call is one of the three, answered whatever the mode: an
     * assistant keeps the list it read for minutes, and one that read the
     * compact list before the merchant turned the mode off must not see its
     * calls fail until its copy expires.
     *
     * @param string $name
     *
     * @return bool
     */
    public static function owns(string $name): bool
    {
        return in_array($name, self::names(), true);
    }

    /**
     * The three tools as the settings page shows them, under Connection.
     *
     * @return array<int, array{name:string, title:string, description:string, writes:bool}>
     */
    public static function describeOwn(): array
    {
        Somcp::dependencies();
        $rows = [];
        foreach (self::mode()->listTools(ToolSet::of([])) as $tool) {
            $rows[] = [
                'name' => $tool->name,
                'title' => $tool->title(),
                'description' => $tool->description(),
                'writes' => !$tool->readsOnly(),
            ];
        }

        return $rows;
    }

    /**
     * The tools/list of compact mode for one caller: somcp_ping when it may
     * see it, then the three tools.
     *
     * @param ShopGate $gate the caller
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listFor(ShopGate $gate): array
    {
        Somcp::dependencies();
        $visible = self::shopTools()->filter(static function (Tool $tool) use ($gate): bool {
            return $gate->visible($tool);
        });

        return array_map(static function (Tool $tool): array {
            return $tool->toArray();
        }, self::mode()->listTools($visible));
    }

    /**
     * Answers a call to one of the three tools.
     *
     * @param string $name one of names()
     * @param array<string, mixed> $arguments
     * @param ShopGate $gate the caller, which runs the tool a runner names
     *
     * @return array<string, mixed> a CallToolResult; when the tool ran asked a
     *                              question, the gate holds it instead
     */
    public static function call(string $name, array $arguments, ShopGate $gate): array
    {
        Somcp::dependencies();
        $result = (new Discovery(self::mode(), $gate, new SearchSetup()))->call($name, $arguments, self::shopTools());

        return self::toArray($result);
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
     * The tool a runner's call names, empty for anything else.
     *
     * @param string $name the tool called
     * @param array<string, mixed> $arguments its arguments
     *
     * @return string
     */
    public static function runs(string $name, array $arguments): string
    {
        if (($name !== self::READ_TOOL && $name !== self::WRITE_TOOL) || !is_string($arguments['name'] ?? null)) {
            return '';
        }

        return $arguments['name'];
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
        Somcp::dependencies();

        return self::mode()->guideHeader($prompt->getTools(), self::shopTools());
    }

    /**
     * The tools of the registry a caller may use that match some keywords,
     * best match first, through the library's search.
     *
     * @param string $query keywords in English
     * @param array<int, SomcpTool> $usable the tools to search, in the registry's order
     *
     * @return array<int, SomcpTool>
     */
    public static function rank(string $query, array $usable): array
    {
        Somcp::dependencies();
        $byName = [];
        $definitions = [];
        foreach ($usable as $tool) {
            $byName[$tool->getName()] = $tool;
            $definitions[] = $tool->describe();
        }
        $found = (new SearchSetup())->searchFor(self::toolSet($definitions))
            ->find(SearchQuery::fromArguments(['query' => $query]), count($byName));

        $ranked = [];
        foreach ($found->tools as $tool) {
            if (isset($byName[$tool->name])) {
                $ranked[] = $byName[$tool->name];
            }
        }

        return $ranked;
    }

    /**
     * The library's compact mode, set up for the shop.
     *
     * @return CompactMode
     */
    private static function mode(): CompactMode
    {
        $registry = SomcpToolRegistry::getInstance();

        return new CompactMode(
            self::PREFIX,
            ['somcp_ping'],
            [
                'search' => Somcp::t('Search the shop tools'),
                'read' => Somcp::t('Run a read tool'),
                'write' => Somcp::t('Run a tool that changes the shop'),
            ],
            // The groups of the settings page, so a search with no argument
            // reads like the list the merchant switches tools in.
            static function (Tool $tool) use ($registry): string {
                $own = $registry->get($tool->name);

                return $own === null ? SomcpScope::ACCESS : SomcpScope::groupOf($own->getRequiredScopes());
            },
            self::MAX_DESCRIBED
        );
    }

    /**
     * Every tool of the registry, as the library reads them. Which of them a
     * caller sees is the gate's to say.
     *
     * @return ToolSet
     */
    private static function shopTools(): ToolSet
    {
        $definitions = [];
        foreach (SomcpToolRegistry::getInstance()->all() as $tool) {
            $definitions[] = $tool->describe();
        }

        return self::toolSet($definitions);
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     *
     * @return ToolSet
     */
    private static function toolSet(array $definitions): ToolSet
    {
        $tools = [];
        foreach ($definitions as $definition) {
            try {
                $tools[] = Tool::fromArray($definition);
            } catch (InvalidTool $e) {
                // A tool of another module with a name the specification does
                // not allow is left out of compact mode rather than taking
                // every other tool down with it; its own call path refuses it.
                continue;
            }
        }

        return ToolSet::of($tools);
    }

    /**
     * @param CallResult $result
     *
     * @return array<string, mixed>
     */
    private static function toArray(CallResult $result): array
    {
        $answer = ['content' => $result->content];
        if ($result->structuredContent !== null) {
            $answer['structuredContent'] = $result->structuredContent;
        }
        if ($result->isError) {
            $answer['isError'] = true;
        }

        return $answer;
    }
}
