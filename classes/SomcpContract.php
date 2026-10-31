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
 * SomcpContract - what another module may hand this one without naming it.
 *
 * The Addons validator analyses a module on its own. A module that writes
 * `new SomcpTool(...)` names a class that does not exist there, and is
 * refused for it - even though the line only runs when this module is
 * installed. So the other modules describe their tools and prompts as plain
 * arrays returned from the hook, read back their results as plain arrays,
 * and this class turns both into what the rest of the server already
 * understands.
 *
 * It is a translation layer and nothing more. Every check a tool or a prompt
 * goes through - name, schema, scopes, permission, the merchant's switches -
 * still happens in the registries, exactly as it does for an object.
 *
 * The contract is written out for module authors in contrat-somcp-tableaux.md.
 * A key renamed here is a key renamed there, in the same pass.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpContract
{
    /** @var array<int, string> The back office actions a declaration may name. */
    private const ACTIONS = [
        SomcpPermission::VIEW,
        SomcpPermission::ADD,
        SomcpPermission::EDIT,
        SomcpPermission::DELETE,
    ];

    /** @var array<int, string> The context keys a module is promised; everything else stays inside. */
    private const PUBLIC_CONTEXT = ['id_shop', 'id_employee', 'id_lang', 'files'];

    /**
     * What one module returned from the hook.
     *
     * Hook::exec() is called with its array flag, which files each module's
     * return value under the module's name. Without the flag, PrestaShop
     * throws an array return away - and complains about it in debug mode.
     *
     * @param mixed $output what Hook::exec() gave back
     * @param string $module folder of the module that was called
     *
     * @return array<mixed>
     */
    public static function returned($output, string $module): array
    {
        if (!is_array($output)) {
            return [];
        }

        $value = $output[$module] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * The permissions a module declared alongside its tools.
     *
     * Written as short name to label, the label being what the merchant reads
     * on the consent screen. A bare list is accepted too, and then the name is
     * all the merchant gets to read.
     *
     * @param array<mixed> $declared
     *
     * @return array<string, string>
     */
    public static function scopes(array $declared): array
    {
        $scopes = [];
        foreach (is_array($declared['scopes'] ?? null) ? $declared['scopes'] : [] as $key => $value) {
            if (is_int($key)) {
                $scopes[(string) $value] = '';
            } else {
                $scopes[$key] = (string) $value;
            }
        }

        return $scopes;
    }

    /**
     * The list of entries under one key, or the return value itself when it
     * is already a list.
     *
     * A module with nothing but tools may return them as they are; one that
     * also declares permissions wraps them under `tools`. Both are read here
     * so neither has to know about the other form.
     *
     * @param array<mixed> $declared
     * @param string $key tools or prompts
     *
     * @return array<int, mixed>
     */
    public static function entries(array $declared, string $key): array
    {
        if (is_array($declared[$key] ?? null)) {
            return array_values($declared[$key]);
        }

        return array_is_list($declared) ? $declared : [];
    }

    /**
     * A tool, from the array a module described it with.
     *
     * The name stays short: the registry prefixes it with the module's folder,
     * the same way it does for an object.
     *
     * @param array<mixed> $definition
     * @param string $module folder of the module declaring it
     *
     * @return SomcpTool|null null when the description cannot be turned into a tool
     */
    public static function tool(array $definition, string $module): ?SomcpTool
    {
        $name = is_string($definition['name'] ?? null) ? trim($definition['name']) : '';
        $description = is_string($definition['description'] ?? null) ? trim($definition['description']) : '';
        $handler = $definition['handler'] ?? null;

        if ($name === '' || $description === '' || !is_callable($handler)) {
            return self::refused('tool_declaration_refused', $module, $name,
                'a tool needs a name, a description and a callable handler');
        }

        $input = is_array($definition['input'] ?? null)
            ? $definition['input']
            : ['type' => 'object', 'properties' => []];

        if (($definition['accepts_files'] ?? false) === true) {
            $input = self::withUploadReference($input);
        }

        $scopes = self::qualifiedScopes($definition['scopes'] ?? [], $module);
        if ($scopes === null) {
            return self::refused('tool_declaration_refused', $module, $name, 'its scopes are not a list of names of its own');
        }
        // Every tool of this server sits behind the basic connection scope.
        // A declaration listing none of its own would otherwise be reachable
        // by any token at all, including one granted nothing.
        $scopes = array_values(array_unique(array_merge([SomcpScope::ACCESS], $scopes)));

        $acts = self::acts($definition['permission'] ?? null);
        if ($acts === null) {
            return self::refused('tool_declaration_refused', $module, $name,
                'permission must be [action, tab] with action among ' . implode(', ', self::ACTIONS));
        }

        $title = is_string($definition['title'] ?? null) && trim($definition['title']) !== ''
            ? trim($definition['title'])
            : $name;

        return new SomcpTool(
            $name,
            $title,
            $description,
            $input,
            $scopes,
            static function (array $arguments, array $context) use ($handler, $module): array {
                return self::run($handler, $arguments, $context, $module);
            },
            $acts
        );
    }

    /**
     * A prompt, from the array a module described it with.
     *
     * The file must sit inside the module's own directory. It is the one thing
     * in a declaration that reaches the filesystem, and its text is handed to
     * whoever holds a token: a declaration pointing at config/ would publish
     * the shop's configuration as a guide.
     *
     * @param array<mixed> $definition
     * @param string $module folder of the module declaring it
     *
     * @return SomcpPrompt|null
     */
    public static function prompt(array $definition, string $module): ?SomcpPrompt
    {
        $name = is_string($definition['name'] ?? null) ? trim($definition['name']) : '';
        $description = is_string($definition['description'] ?? null) ? trim($definition['description']) : '';
        $path = is_string($definition['file'] ?? null) ? trim($definition['file']) : '';

        if ($name === '' || $description === '' || $path === '') {
            return self::refusedPrompt($module, $name, 'a prompt needs a name, a description and a file');
        }

        // Byte lengths on purpose: these are paths, compared byte for byte.
        $home = realpath(_PS_MODULE_DIR_ . $module);
        $file = realpath($path[0] === '/' ? $path : _PS_MODULE_DIR_ . $module . '/' . $path);
        if ($home === false || $file === false || !is_file($file)
            || strncmp($file, $home . '/', strlen($home) + 1) !== 0) {
            return self::refusedPrompt($module, $name, 'its file is missing or outside the module directory');
        }

        $scopes = self::qualifiedScopes($definition['scopes'] ?? [], $module);
        $acts = self::acts($definition['permission'] ?? null);
        $arguments = self::arguments($definition['arguments'] ?? []);
        if ($scopes === null || $acts === null || $arguments === null) {
            return self::refusedPrompt($module, $name, 'its scopes are not its own, or its permission or its arguments are malformed');
        }

        $tools = [];
        foreach (is_array($definition['tools'] ?? null) ? $definition['tools'] : [] as $tool) {
            if (is_string($tool) && $tool !== '') {
                $tools[] = $tool;
            }
        }

        $title = is_string($definition['title'] ?? null) && trim($definition['title']) !== ''
            ? trim($definition['title'])
            : $name;

        return new SomcpPrompt(
            $name,
            $title,
            $description,
            $file,
            // The same floor as a tool: nothing this server hands out is
            // reachable without the basic connection scope.
            array_values(array_unique(array_merge([SomcpScope::ACCESS], $scopes))),
            $tools,
            $acts,
            $arguments
        );
    }

    /**
     * The arguments a prompt asks the merchant for.
     *
     * A name is what the assistant's form sends back as a key, so it is held
     * to the characters every client can carry.
     *
     * @param mixed $arguments
     *
     * @return array<int, array{name:string, description:string, required:bool}>|null null when malformed
     */
    private static function arguments($arguments): ?array
    {
        if (!is_array($arguments)) {
            return null;
        }

        $clean = [];
        $seen = [];
        foreach ($arguments as $argument) {
            $name = is_array($argument) && is_string($argument['name'] ?? null) ? $argument['name'] : '';
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1 || isset($seen[$name])) {
                return null;
            }
            $seen[$name] = true;
            $clean[] = [
                'name' => $name,
                'description' => is_string($argument['description'] ?? null) ? trim($argument['description']) : '',
                'required' => ($argument['required'] ?? false) === true,
            ];
        }

        return $clean;
    }

    /**
     * Runs a handler declared as an array, and turns what it returns into a
     * tool result.
     *
     * Two things happen around the call. On the way back from a deposit, the
     * files are redeemed here and put in the context, so the handler reads
     * them instead of calling anything. On the way out, an `upload` answer is
     * turned into the deposit request, so the handler asks for files instead
     * of calling anything either.
     *
     * @param callable $handler
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context the full context the endpoint built
     * @param string $module folder of the module the tool belongs to
     *
     * @return array<string, mixed>
     */
    public static function run(callable $handler, array $arguments, array $context, string $module): array
    {
        // The reference is plumbing. The contract promises the handler the
        // same arguments on both halves of a deposit, and it was never one of
        // them.
        unset($arguments[SomcpUpload::ARG_REF]);

        if (SomcpUpload::reference($context) !== '') {
            $invitation = SomcpUpload::pending($context);

            if ($invitation === null) {
                // A reference that leads nowhere: invented, expired, already
                // redeemed or cancelled. Answering "nothing came back" is a dead
                // end: the assistant has nothing to offer but the same call.
                // It is treated as a first one instead, so a handler that
                // wants files asks again and a fresh address goes out.
                SomcpLogger::log('upload_reference_unknown', SomcpLogger::OUTCOME_INFO, $module, '',
                    (int) ($context['id_employee'] ?? 0));
            } else {
                $files = SomcpUpload::take($context);
                if ($files === []) {
                    // Open and still empty, whichever way the call came back:
                    // the client may have taken the question without ever
                    // showing the page, or the merchant has not sent anything
                    // yet. Either way the same address goes out again as text
                    // - a second deposit would lose what is sent to the first.
                    return SomcpUpload::handOver($context, $invitation);
                }
                $context['files'] = $files;
            }
        }

        $result = call_user_func($handler, $arguments, self::publicContext($context));

        return self::translate($result, $context, $module);
    }

    /**
     * The part of the context a module is promised.
     *
     * Filtered rather than passed whole: whatever a module can read, it will
     * come to depend on, and the rest - the sealed state, the client's
     * capabilities, the raw responses - is this server's to change.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public static function publicContext(array $context): array
    {
        return array_intersect_key($context, array_flip(self::PUBLIC_CONTEXT));
    }

    /**
     * What a handler returned, as a tool result.
     *
     * @param mixed $result
     * @param array<string, mixed> $context
     * @param string $module
     *
     * @return array<string, mixed>
     */
    public static function translate($result, array $context, string $module): array
    {
        if (!is_array($result)) {
            return self::broken($module, 'the handler returned something that is not an array');
        }

        // Already a result. A module half-way through its migration may still
        // build some answers with the old helpers, and refusing them would
        // punish the migration rather than help it.
        if (isset($result['content']) || SomcpTool::isInputRequired($result)) {
            return $result;
        }

        if (is_array($result['upload'] ?? null)) {
            return self::upload($result['upload'], $context, $module);
        }

        if (array_key_exists('json', $result)) {
            if (is_array($result['json'])) {
                return SomcpTool::json($result['json']);
            }
            $encoded = json_encode($result['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return SomcpTool::text($encoded === false ? 'null' : $encoded);
        }

        if (array_key_exists('text', $result) && is_scalar($result['text'])) {
            return SomcpTool::text((string) $result['text'], ($result['error'] ?? false) === true);
        }

        return self::broken($module, 'the handler returned none of json, text or upload');
    }

    /**
     * A deposit asked for by returning `upload`.
     *
     * @param array<mixed> $upload
     * @param array<string, mixed> $context
     * @param string $module
     *
     * @return array<string, mixed>
     */
    private static function upload(array $upload, array $context, string $module): array
    {
        $purpose = is_string($upload['purpose'] ?? null) ? trim($upload['purpose']) : '';
        $accept = $upload['accept'] ?? null;

        if ($purpose === '' || !(is_string($accept) || is_array($accept))) {
            return self::broken($module, 'upload needs a purpose and an accept');
        }

        $limits = [];
        if (isset($upload['files'])) {
            $limits['files'] = (int) $upload['files'];
        }
        if (isset($upload['bytes'])) {
            $limits['bytes'] = (int) $upload['bytes'];
        }

        if (is_array($accept)) {
            $accept = array_values(array_filter($accept, 'is_string'));
        }

        return SomcpUpload::request($context, $purpose, $accept, $module, [], $limits);
    }

    /**
     * Scopes as the registry expects them: qualified with the module folder.
     *
     * A name the module already wrote in full - mcp.mymodule.tickets.read -
     * is taken as it is, so a module can move to arrays without rewriting
     * every scope it already uses. Only under its own prefix, though: the
     * registry accepts any core scope, so a full name left unchecked would let
     * a module require mcp.orders.read and slip its tool behind a consent line
     * the merchant gave for somcp's own tools. Whether the scope was declared
     * is the registry's check, made for objects and arrays alike.
     *
     * @param mixed $scopes
     * @param string $module
     *
     * @return array<int, string>|null null when the value is not a list of the module's own names
     */
    private static function qualifiedScopes($scopes, string $module): ?array
    {
        if (!is_array($scopes)) {
            return null;
        }

        $own = SomcpToolRegistry::scopePrefix($module);
        if ($own === '') {
            return null;
        }
        $qualified = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) || trim($scope) === '') {
                return null;
            }
            $full = strncmp($scope, 'mcp.', 4) === 0
                ? $scope
                : SomcpToolRegistry::qualifyScope($scope, $module);
            // mcp.access is added to every tool anyway, so writing it out
            // borrows nothing.
            if ($full === '' || ($full !== SomcpScope::ACCESS && strncmp($full, $own, strlen($own)) !== 0)) {
                return null;
            }
            $qualified[] = $full;
        }

        return $qualified;
    }

    /**
     * The back office right a declaration names, in the shape SomcpTool takes.
     *
     * Absent means no right is required - and no hint is given either, as for
     * an object built without one: claiming a tool only reads would be a
     * promise nobody checked.
     *
     * @param mixed $permission
     *
     * @return array<string, mixed>|null null when the value is malformed
     */
    private static function acts($permission): ?array
    {
        if ($permission === null) {
            return [];
        }

        if (!is_array($permission) || count($permission) !== 2) {
            return null;
        }

        [$action, $controller] = array_values($permission);
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)
            || !is_string($controller) || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $controller) !== 1) {
            return null;
        }

        return SomcpTool::acts($action, $controller);
    }

    /**
     * The schema with room for the reference an assistant hands back.
     *
     * The plain-text fallback tells the assistant to call the tool again with
     * the reference added. A client that checks arguments against the schema
     * refuses a property it was never told about, and the deposit could then
     * never be collected - so a tool that asks for files says so, and gets the
     * property declared for it.
     *
     * @param array<mixed> $input
     *
     * @return array<mixed>
     */
    private static function withUploadReference(array $input): array
    {
        $properties = is_array($input['properties'] ?? null) ? $input['properties'] : [];
        if (!isset($properties[SomcpUpload::ARG_REF])) {
            $properties[SomcpUpload::ARG_REF] = [
                'type' => 'string',
                'description' => 'Leave this out. Set it only to the deposit reference this tool gave in its previous answer, and only when that answer asked for it.',
            ];
        }
        $input['properties'] = $properties;

        return $input;
    }

    /**
     * @param string $event
     * @param string $module
     * @param string $name
     * @param string $reason
     *
     * @return null
     */
    private static function refused(string $event, string $module, string $name, string $reason)
    {
        SomcpLogger::log($event, SomcpLogger::OUTCOME_FAILURE,
            $module . ($name === '' ? '' : ' ' . Tools::substr($name, 0, 64)) . ': ' . $reason);

        return null;
    }

    /**
     * @param string $module
     * @param string $name
     * @param string $reason
     *
     * @return null
     */
    private static function refusedPrompt(string $module, string $name, string $reason)
    {
        return self::refused('prompt_declaration_refused', $module, $name, $reason);
    }

    /**
     * A result the handler got wrong, logged for the module author and
     * reported to the assistant as a failure without the detail.
     *
     * @param string $module
     * @param string $reason
     *
     * @return array<string, mixed>
     */
    private static function broken(string $module, string $reason): array
    {
        SomcpLogger::log('tool_result_refused', SomcpLogger::OUTCOME_FAILURE, $module . ': ' . $reason);

        return SomcpTool::text('This tool failed to produce an answer. The shop has logged why.', true);
    }
}
