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

namespace Novalo\Somcp\Discovery;

use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolGate;
use Novalo\McpToolDiscovery\Tool\Tool;

// The validation checklist asks every PHP file of a module to refuse to run
// outside PrestaShop. It sits after the imports rather than at the very top
// because a namespace and its use statements have to come first, and this
// class is reached through Composer's autoloader, not by being included.
if (!defined('_PS_VERSION_')) {
    exit;
}

/*
 * What novalo/mcp-tool-discovery asks of the shop: which tools a caller may
 * see, and how one is run.
 *
 * The library lists, searches and runs through its modes; every decision of
 * access stays here, on the same rules as the full list. Visible means what
 * the full tools/list would carry for this token: switched on for connected
 * assistants, covered by the token's scopes, allowed by the account's back
 * office permissions. Every refusal with a reason of its own - the 403 that
 * names the missing scope, the message of a tool switched off - is given by
 * the endpoint before the library is reached, as for a direct call.
 *
 * Running is the endpoint's own path, handed in as a function, so a tool run
 * through a runner meets the same context, the same sealed state and the same
 * logging as one called by its name. A tool that asks the merchant something
 * answers with a result the library has no shape for: it is kept here, under
 * the name and arguments of the tool itself, for the endpoint to send.
 */
final class ShopGate implements ToolGate
{
    /** @var \Closure(string, array<string, mixed>): array<string, mixed> */
    private $run;

    /** @var array{name: string, arguments: array<string, mixed>, result: array<string, mixed>}|null */
    private $asked;

    /**
     * @param string $scopes the scopes of the token
     * @param int $idEmployee the account the token acts for
     * @param callable(string, array<string, mixed>): array<string, mixed> $run runs a tool of the shop by
     *                                                                          its name, as the endpoint does
     */
    public function __construct(
        private readonly string $scopes,
        private readonly int $idEmployee,
        callable $run,
    ) {
        $this->run = \Closure::fromCallable($run);
    }

    public function visible(Tool $tool): bool
    {
        $own = \SomcpToolRegistry::getInstance()->get($tool->name);

        return $own !== null
            && \SomcpToolRegistry::isEnabled($tool->name)
            && $own->isAvailableTo($this->scopes, $this->idEmployee);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function authorize(Tool $tool, array $arguments): ?CallResult
    {
        // Read again rather than trusted from the list the library holds: a
        // tool switched off, or a permission taken away, since that list was
        // built must not run.
        return $this->visible($tool) ? null : CallResult::error('Unknown tool: ' . $tool->name . '.');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function call(Tool $tool, array $arguments): CallResult
    {
        $result = ($this->run)($tool->name, $arguments);
        if (\SomcpTool::isInputRequired($result)) {
            $this->asked = ['name' => $tool->name, 'arguments' => $arguments, 'result' => $result];

            // Never shown: the endpoint sends the question instead.
            return CallResult::text('');
        }

        return CallResult::fromArray($result);
    }

    /**
     * The question a tool asked during this call, with the tool and arguments
     * it is sealed under, or null.
     *
     * @return array{name: string, arguments: array<string, mixed>, result: array<string, mixed>}|null
     */
    public function asked(): ?array
    {
        return $this->asked;
    }
}
