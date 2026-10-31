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
 * SomcpToolRegistry - what the server can be asked to do.
 *
 * The registry is deliberately unaware of the endpoint: it is filled once, then
 * read. Other modules add their own tools through the actionSomcpRegisterTools
 * hook, which is how another module publishes its own without this module
 * knowing it exists.
 *
 * The tools themselves live one per file under tools/. The registry finds them
 * by reading that folder, so adding a tool is adding a file - nothing to
 * declare here, and no list to keep in step with the filesystem.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolRegistry
{
    /** @var string Hook other modules listen to in order to publish their tools. */
    public const HOOK_REGISTER = 'actionSomcpRegisterTools';

    /** @var string Folder holding one file per tool, relative to the modules directory. */
    private const TOOLS_DIR = 'somcp/tools/';

    /** @var int Longest tool name the protocol allows. */
    public const MAX_NAME_LENGTH = 64;

    /** @var array<string, SomcpTool> */
    private $tools = [];

    /** @var string Folder of the module currently being called, empty for our own tools. */
    private $registering = '';

    /** @var array<string, string> Scopes other modules declared, name to merchant label. */
    private $scopes = [];

    /** @var SomcpToolRegistry|null */
    private static $instance;

    /**
     * The registry, filled on first use.
     *
     * @return SomcpToolRegistry
     */
    public static function getInstance(): SomcpToolRegistry
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->registerCoreTools();
            self::$instance->collectFromModules();
        }

        return self::$instance;
    }

    /**
     * Adds a tool. A name already taken is kept: a third-party module must not
     * be able to shadow a core tool by registering the same name later.
     *
     * While another module is being called, the name it chose is prefixed with
     * that module's folder. The module does not get to decide this and cannot
     * opt out of it: the prefix is what stops two modules that both thought of
     * `get_tracking` from silently cancelling each other out, and what stops a
     * third party from publishing something called `somcp_get_order` that an
     * assistant and a merchant would both read as ours.
     *
     * @param SomcpTool $tool
     *
     * @return bool false when the name was already registered, or unusable
     */
    public function register(SomcpTool $tool): bool
    {
        // Checked before the name, and loudly: a tool asking for a permission
        // the server does not know is one the merchant can never grant, so it
        // would sit in the listing reaching nothing. It used to be accepted and
        // silently unreachable, which is the worst of both.
        //
        // Another module may only lean on its own scopes, plus the connection
        // scope every tool carries. Granting it the core ones would let its
        // tool hide behind a consent line the merchant gave for ours, which is
        // exactly what declareScope() refuses when the name is declared.
        $allowed = $this->registering === ''
            ? array_merge(SomcpScope::core(), array_keys($this->scopes))
            : array_merge([SomcpScope::ACCESS], $this->scopesOf($this->registering));
        $unknown = array_diff($tool->getRequiredScopes(), $allowed);
        if ($unknown !== []) {
            SomcpLogger::log(
                'tool_scope_unknown',
                SomcpLogger::OUTCOME_FAILURE,
                $tool->getName() . ' asks for ' . implode(' ', $unknown)
                    . ($this->registering === '' ? '' : ', a module can only require the scopes it declared with declareScope()')
            );

            return false;
        }

        $name = self::qualify($tool->getName(), $this->registering);

        if ($name === '') {
            SomcpLogger::log(
                'tool_name_refused',
                SomcpLogger::OUTCOME_FAILURE,
                'from ' . ($this->registering === '' ? 'this module' : $this->registering)
            );

            return false;
        }

        if (isset($this->tools[$name])) {
            SomcpLogger::log(
                'tool_name_taken',
                SomcpLogger::OUTCOME_FAILURE,
                $name . ' is already registered'
            );

            return false;
        }

        // A schema a client refuses does not break one tool, it breaks the
        // whole listing: the client rejects the tools/list answer and the
        // merchant loses every module's tools at once. So a tool that cannot
        // be served is left out here, where the damage stops at itself.
        $problem = $tool->getSchemaProblem();
        if ($problem !== '') {
            SomcpLogger::log(
                'tool_schema_refused',
                SomcpLogger::OUTCOME_FAILURE,
                $name . ': ' . $problem
            );

            return false;
        }

        if ($name !== $tool->getName()) {
            $tool = $tool->renamed($name);
        }

        $this->tools[$name] = $tool;

        return true;
    }

    /**
     * Lets the module being called add a permission of its own.
     *
     * Call it before registering a tool that needs it: the tool is refused
     * otherwise. Within one hook call that is simply line order.
     *
     * The scope is prefixed with the module's folder, like a tool name and for
     * the same reason. The label is what the merchant reads on the consent
     * screen, so the module passes it already translated - it knows what its
     * tools do and this one does not.
     *
     * @param string $scope short name, e.g. tickets.read
     * @param string $label one line, in the merchant's language
     *
     * @return string the full scope, or an empty string when refused
     */
    public function declareScope(string $scope, string $label): string
    {
        if ($this->registering === '') {
            // Our own scopes live in SomcpScope, where they can be read without
            // building the registry first.
            return '';
        }

        $full = self::qualifyScope($scope, $this->registering);
        if ($full === '' || in_array($full, SomcpScope::core(), true)) {
            SomcpLogger::log(
                'scope_refused',
                SomcpLogger::OUTCOME_FAILURE,
                $this->registering . ' cannot declare ' . Tools::substr($scope, 0, 60)
            );

            return '';
        }

        $this->scopes[$full] = trim($label) === '' ? $full : trim($label);

        return $full;
    }

    /**
     * The scopes other modules declared, name to merchant label.
     *
     * @return array<string, string>
     */
    public function declaredScopes(): array
    {
        return $this->scopes;
    }

    /**
     * The scopes a module declared, under its own prefix.
     *
     * Public for the prompt registry, which holds a module to the same rule.
     *
     * @param string $module folder of the module
     *
     * @return array<int, string>
     */
    public function scopesOf(string $module): array
    {
        $own = self::scopePrefix($module);
        if ($own === '') {
            return [];
        }

        return array_values(array_filter(
            array_keys($this->scopes),
            static function (string $scope) use ($own): bool {
                return strncmp($scope, $own, strlen($own)) === 0;
            }
        ));
    }

    /**
     * The prefix every scope of a module starts with, mcp.mymodule.
     *
     * Read from qualifyScope() rather than rebuilt, so the two can never
     * disagree on how a folder is cleaned.
     *
     * @param string $module folder of the module
     *
     * @return string the prefix, or an empty string when the folder is unusable
     */
    public static function scopePrefix(string $module): string
    {
        return substr(self::qualifyScope('x', $module), 0, -1);
    }

    /**
     * The scope a module is given, from the short name it asked for.
     *
     * @param string $scope
     * @param string $module folder of the module declaring it
     *
     * @return string the qualified scope, or an empty string when unusable
     */
    public static function qualifyScope(string $scope, string $module): string
    {
        // A scope travels in an OAuth parameter, where values are separated by
        // spaces: anything that would need quoting is dropped rather than
        // escaped everywhere it is read.
        $clean = trim((string) preg_replace('/[^a-zA-Z0-9._-]/', '', $scope), '.');
        $folder = (string) preg_replace('/[^a-zA-Z0-9_-]/', '', $module);

        if ($clean === '' || $folder === '') {
            return '';
        }

        return 'mcp.' . $folder . '.' . $clean;
    }

    /**
     * The name a tool is filed under, given the module registering it.
     *
     * Kept public so the rest of the module - and the tests - can work out a
     * name without going through a registration.
     *
     * @param string $name the name the module asked for
     * @param string $module folder of the module registering, empty for our own
     *
     * @return string the qualified name, or an empty string when unusable
     */
    public static function qualify(string $name, string $module): string
    {
        // What a tool name may hold, taken from the protocol rather than from
        // taste: anything else would have to be escaped by every client.
        $clean = (string) preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        if ($clean === '') {
            return '';
        }

        if ($module !== '') {
            $folder = (string) preg_replace('/[^a-zA-Z0-9_-]/', '', $module);
            if ($folder === '') {
                return '';
            }
            // A dash between the two halves, where module folders already use
            // underscores: ps_checkout-capture reads as one module and one
            // tool, ps_checkout_capture reads as neither.
            $clean = $folder . '-' . $clean;
        }

        // The protocol caps a name at 64 characters. Cutting one would risk
        // two tools landing on the same stub, so an over-long name is refused
        // and the module author told in the log.
        return Tools::strlen($clean) > self::MAX_NAME_LENGTH ? '' : $clean;
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function unregister(string $name): bool
    {
        if (!isset($this->tools[$name])) {
            return false;
        }

        unset($this->tools[$name]);

        return true;
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * @param string $name
     *
     * @return SomcpTool|null
     */
    public function get(string $name): ?SomcpTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Every registered tool.
     *
     * @return array<string, SomcpTool>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * The tools a given token is allowed to see.
     *
     * A tool the token cannot use is left out of the listing rather than shown
     * and refused later. An assistant that sees a tool will try it, spend a
     * turn on the attempt and often try again another way; one it never saw
     * costs nothing. This is also why the employee's back office permissions
     * are read here and not only at call time: a bookkeeper with no right to
     * delete products should not be told that deleting products is on offer.
     *
     * @param string $grantedScopes
     * @param int $idEmployee the employee behind the token
     *
     * @return array<int, array<string, mixed>>
     */
    public function describeFor(string $grantedScopes, int $idEmployee): array
    {
        $described = [];
        foreach ($this->tools as $tool) {
            if (self::isEnabled($tool->getName()) && $tool->isAvailableTo($grantedScopes, $idEmployee)) {
                $described[] = $tool->describe();
            }
        }

        return $described;
    }

    /**
     * Runs a tool and turns anything it throws into a tool error.
     *
     * Shared by the MCP endpoint and the assistant built into the back office,
     * so a tool behaves the same way whichever of the two calls it, and a
     * failure is logged once, in one shape.
     *
     * The exception message stays out of the result: an exception from deep
     * inside PrestaShop can carry a query or a path, and the caller reads the
     * result back to a model.
     *
     * @param SomcpTool $tool
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed> a CallToolResult
     */
    public static function run(SomcpTool $tool, array $arguments, array $context): array
    {
        try {
            return $tool->call($arguments, $context);
        } catch (Throwable $e) {
            SomcpLogger::log(
                'tool_failed',
                SomcpLogger::OUTCOME_FAILURE,
                $tool->getName() . ': ' . Tools::substr($e->getMessage(), 0, 200),
                '',
                (int) ($context['id_employee'] ?? 0)
            );

            return SomcpTool::text('The tool failed while running.', true);
        }
    }

    /**
     * Whether the merchant left this tool switched on for this shop.
     *
     * Separate from the permission check: a permission says what an account may
     * do, this says what the shop offers at all. A merchant who installs a
     * module publishing eight tools and wants two of them has no other way of
     * saying so - short of uninstalling the module.
     *
     * The setting is an ordinary module setting, so it follows the shop, group
     * and global inheritance PrestaShop already applies to the rest of them.
     *
     * @param string $name
     *
     * @return bool
     */
    public static function isEnabled(string $name): bool
    {
        return !in_array($name, self::disabled(), true);
    }

    /**
     * The tools switched off for the shop this request belongs to.
     *
     * @return array<int, string>
     */
    public static function disabled(): array
    {
        $raw = trim(SomcpConfig::get(SomcpConfig::TOOLS_OFF));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', $raw) ?: []));
    }

    /**
     * Lets the other modules of the shop publish their tools.
     *
     * The hook is fired once per module rather than once for all of them, so
     * that the registry knows which module it is serving and can prefix the
     * names it is handed. PrestaShop's own dispatcher still does the calling -
     * Hook::exec takes the module to run as its third argument - so a module
     * that is disabled, or not enabled for this shop, is skipped by the core
     * exactly as it would be otherwise.
     */
    private function collectFromModules(): void
    {
        $registered = Hook::getHookModuleExecList(self::HOOK_REGISTER);
        if (!is_array($registered)) {
            return;
        }

        foreach ($registered as $entry) {
            $this->registering = (string) ($entry['module'] ?? '');

            try {
                // The array flag is what keeps a returned declaration: without
                // it PrestaShop discards an array return. The registry is
                // still passed, so a module written against the objects keeps
                // working while it moves to arrays.
                $output = Hook::exec(self::HOOK_REGISTER, ['registry' => $this], (int) ($entry['id_module'] ?? 0), true);
                $this->registerDeclared(SomcpContract::returned($output, $this->registering));
            } catch (Throwable $e) {
                // One broken module must not take the whole server down: the
                // tools that did register still answer, and so do the modules
                // called after this one.
                SomcpLogger::log(
                    'tool_registration_failed',
                    SomcpLogger::OUTCOME_FAILURE,
                    $this->registering . ': ' . Tools::substr($e->getMessage(), 0, 200)
                );
            }
        }

        $this->registering = '';
    }

    /**
     * Files what a module returned from the hook instead of calling register().
     *
     * Permissions first, then tools, for the reason declareScope() gives:
     * a tool asking for a scope not yet declared is refused.
     *
     * @param array<mixed> $declared
     */
    private function registerDeclared(array $declared): void
    {
        if ($declared === []) {
            return;
        }

        foreach (SomcpContract::scopes($declared) as $scope => $label) {
            $this->declareScope($scope, $label);
        }

        foreach (SomcpContract::entries($declared, 'tools') as $definition) {
            $tool = is_array($definition) ? SomcpContract::tool($definition, $this->registering) : null;
            if ($tool !== null) {
                $this->register($tool);
            }
        }
    }

    /**
     * The tools the module ships with, one file each under tools/.
     *
     * A file is picked up when it is named after the class it declares and that
     * class exposes define(). Anything else in the folder is skipped and said
     * so in the log, rather than taking the whole registry down with it.
     */
    private function registerCoreTools(): void
    {
        $files = glob(_PS_MODULE_DIR_ . self::TOOLS_DIR . 'Somcp*.php') ?: [];
        // Sorted, so the order tools are registered does not depend on the
        // order the filesystem happens to return them in.
        sort($files);

        foreach ($files as $file) {
            $class = basename($file, '.php');

            if (!class_exists($class, false)) {
                require_once $file;
            }

            if (!class_exists($class) || !method_exists($class, 'define')) {
                SomcpLogger::log(
                    'tool_file_skipped',
                    SomcpLogger::OUTCOME_FAILURE,
                    basename($file) . ' declares no ' . $class . '::define()'
                );

                continue;
            }

            try {
                $tool = $class::define();
            } catch (Throwable $e) {
                SomcpLogger::log(
                    'tool_file_skipped',
                    SomcpLogger::OUTCOME_FAILURE,
                    basename($file) . ': ' . Tools::substr($e->getMessage(), 0, 150)
                );

                continue;
            }

            if ($tool instanceof SomcpTool) {
                $this->register($tool);
            }
        }
    }
}
