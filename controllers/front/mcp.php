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
 * The MCP endpoint.
 *
 * Reachable at https://shop.example/module/somcp/mcp - the PrestaShop dispatcher
 * routes /module/{name}/{controller} to a front controller on its own, which is
 * why this is a front controller and not a PHP file inside the module folder:
 * the shop's .htaccess blocks direct hits on those.
 *
 * Text in the responses below is not translated on purpose. None of it is read
 * by the merchant: these are protocol strings, consumed by an assistant, and
 * the specification fixes their vocabulary. Everything the merchant does see
 * lives in the back office and goes through the translation files.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpMcpModuleFrontController extends ModuleFrontController
{
    /** @var Somcp the module instance, narrowed from the generic Module the parent declares */
    public $module;

    /** @var bool No customer session: callers authenticate with a bearer token. */
    public $auth = false;

    /** @var bool */
    public $ssl = true;

    /** @var bool No template is rendered, JSON is emitted directly. */
    public $display_header = false;

    /** @var bool */
    public $display_footer = false;

    /** @var int Floor and ceiling for the cache the shop announces, in seconds. */
    private const TOOLS_TTL_MIN = 10;
    private const TOOLS_TTL_MAX = 3600;

    /**
     * The MCP endpoints answer even while the shop is closed.
     *
     * Maintenance mode hides the storefront from visitors. These addresses are
     * not the storefront: they are the merchant's own, guarded by an OAuth
     * token, and a shop being prepared is exactly when an assistant gets
     * connected. Left alone, PrestaShop answers 503 here before the controller
     * ever runs - and it would do so unevenly, since PS_MAINTENANCE_ALLOW_ADMINS
     * lets the merchant's browser through the consent screen while every
     * machine call still fails, leaving a connection broken halfway with
     * nothing to explain it. The setting that closes this server is the one in
     * the module, which says so plainly.
     */
    protected function displayMaintenancePage()
    {
    }

    /**
     * Handles the request from end to end and stops the script.
     */
    public function initContent()
    {
        Somcp::bootClasses();

        $method = Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            // The current revision has no server-initiated stream to open and
            // no session to delete, so every other verb is simply not allowed.
            SomcpHttp::noContent(405, ['Allow' => 'POST']);
        }

        if (!SomcpConfig::isOn(SomcpConfig::ENABLED)) {
            SomcpHttp::json(['error' => 'server_disabled'], 503);
        }

        $this->rejectForeignOrigin();

        $token = SomcpHttp::bearerToken();
        $validation = SomcpOAuthToken::validateAccessToken($token, SomcpConfig::resourceUri());
        if ($validation['row'] === null) {
            $this->challenge($validation['error']);
        }

        $message = SomcpJsonRpc::decode((string) file_get_contents('php://input'));
        if ($message === null) {
            SomcpHttp::json(
                SomcpJsonRpc::error(null, SomcpJsonRpc::PARSE_ERROR, 'Invalid JSON body'),
                400
            );
        }
        if (array_is_list($message) && $message !== []) {
            // Batching was dropped by the specification; answering half of a
            // batch would be worse than saying plainly that it is not supported.
            SomcpHttp::json(
                SomcpJsonRpc::error(null, SomcpJsonRpc::INVALID_REQUEST, 'Batched requests are not supported'),
                400
            );
        }

        $this->dispatch($message, $validation['row']);
    }

    /**
     * Refuses a request whose Origin points somewhere else.
     *
     * Assistants are native applications and send no Origin at all; a browser
     * page does, and one served by another site pointing here is the shape of a
     * DNS rebinding attempt against a shop reachable on a local network.
     */
    private function rejectForeignOrigin(): void
    {
        $origin = SomcpHttp::header('Origin');
        if ($origin === '') {
            return;
        }

        $originHost = Tools::strtolower((string) parse_url($origin, PHP_URL_HOST));
        $shop = $this->context->shop;
        $allowed = array_filter([
            Tools::strtolower((string) $shop->domain),
            Tools::strtolower((string) $shop->domain_ssl),
        ]);

        if (!in_array($originHost, $allowed, true)) {
            SomcpLogger::log('origin_refused', SomcpLogger::OUTCOME_FAILURE, 'Origin: ' . Tools::substr($origin, 0, 100));
            SomcpHttp::json(['error' => 'forbidden_origin'], 403);
        }
    }

    /**
     * Sends the 401 that tells an assistant where to sign in.
     *
     * The body repeats whether an Authorization header arrived at all. That one
     * field is what lets the diagnostic tell a client that sent no credential
     * from a hosting that ate the header on the way in.
     *
     * @param string $error
     */
    private function challenge(string $error): void
    {
        // Every scope the server understands, not just the one that opens a
        // session. A client asks for what the challenge names, so announcing
        // less than that leaves it with a token that cannot reach the tools -
        // and the merchant with a consent screen offering one permission where
        // the shop has several.
        $header = SomcpHttp::challenge(
            SomcpScope::toString(SomcpScope::supported()),
            $error === 'missing_token' ? '' : 'invalid_token'
        );

        SomcpHttp::json(
            [
                'error' => $error === 'missing_token' ? 'unauthorized' : 'invalid_token',
                'authorization_received' => SomcpHttp::hasAuthorizationHeader(),
            ],
            401,
            ['WWW-Authenticate' => $header]
        );
    }

    /**
     * Routes one JSON-RPC message.
     *
     * @param array<string, mixed> $message
     * @param array<string, mixed> $tokenRow
     */
    private function dispatch(array $message, array $tokenRow): void
    {
        $id = $message['id'] ?? null;
        $negotiation = SomcpProtocol::negotiate($message);

        if ($negotiation['error'] === 'mismatch') {
            SomcpHttp::json(
                SomcpJsonRpc::error($id, SomcpJsonRpc::HEADER_MISMATCH, 'Transport header and request body disagree'),
                400
            );
        }
        if ($negotiation['error'] === 'unsupported') {
            SomcpHttp::json(
                SomcpJsonRpc::error($id, SomcpJsonRpc::UNSUPPORTED_VERSION, 'Unsupported protocol version', [
                    'supported' => SomcpProtocol::supportedVersions(),
                    'requested' => $negotiation['requested'],
                ]),
                400
            );
        }

        $modern = $negotiation['era'] === SomcpProtocol::ERA_MODERN;
        if ($modern && !SomcpProtocol::headersMatchBody($message)) {
            SomcpHttp::json(
                SomcpJsonRpc::error($id, SomcpJsonRpc::HEADER_MISMATCH, 'Routing headers do not match the request body'),
                400
            );
        }

        $method = (string) ($message['method'] ?? '');
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if (SomcpJsonRpc::isNotification($message)) {
            // Nothing the module does needs to react to a notification, and the
            // handshake ones vanish in the current revision anyway.
            SomcpHttp::noContent(202);
        }

        switch ($method) {
            case 'initialize':
                if ($modern) {
                    SomcpHttp::json(
                        SomcpJsonRpc::error($id, SomcpJsonRpc::METHOD_NOT_FOUND, 'initialize was removed in this revision'),
                        200
                    );
                }
                SomcpHttp::json(SomcpJsonRpc::result($id, $this->initializeResult($params)), 200);
                break;

            case 'server/discover':
                SomcpHttp::json(SomcpJsonRpc::result($id, $this->discoverResult($modern)), 200);
                break;

            case 'ping':
                SomcpHttp::json(SomcpJsonRpc::result($id, $modern ? ['resultType' => 'complete'] : []), 200);
                break;

            case 'tools/list':
                SomcpHttp::json(SomcpJsonRpc::result($id, $this->toolsListResult($tokenRow, $modern)), 200);
                break;

            case 'tools/call':
                SomcpHttp::json(SomcpJsonRpc::result($id, $this->toolsCallResult($params, $tokenRow, $modern)), 200);
                break;

            case 'prompts/list':
                SomcpHttp::json(SomcpJsonRpc::result($id, $this->promptsListResult($tokenRow, $modern)), 200);
                break;

            case 'prompts/get':
                SomcpHttp::json($this->promptsGetResult($id, $params, $tokenRow, $modern), 200);
                break;

            default:
                SomcpHttp::json(
                    SomcpJsonRpc::error($id, SomcpJsonRpc::METHOD_NOT_FOUND, 'Unknown method: ' . Tools::substr($method, 0, 60)),
                    200
                );
        }
    }

    /**
     * Answer to the handshake of the older revisions.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function initializeResult(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');

        return [
            'protocolVersion' => SomcpProtocol::negotiateLegacyVersion($requested),
            'capabilities' => self::capabilities(),
            'serverInfo' => $this->serverInfo(),
        ];
    }

    /**
     * What this server can do, in both eras of the protocol.
     *
     * Tools and prompts, and nothing else. Resources and the Skills extension
     * were declared while skills existed; skills are gone, and a capability
     * announced for nothing makes a client ask for lists that are always empty.
     * listChanged is announced for neither: it promises a connection this
     * server does not keep open.
     *
     * @return array<string, mixed>
     */
    private static function capabilities(): array
    {
        return [
            'tools' => new stdClass(),
            'prompts' => new stdClass(),
        ];
    }

    /**
     * The prompts this token may be handed.
     *
     * @param array<string, mixed> $tokenRow
     * @param bool $modern
     *
     * @return array<string, mixed>
     */
    private function promptsListResult(array $tokenRow, bool $modern): array
    {
        $result = ['prompts' => SomcpPromptRegistry::getInstance()->describeFor(
            (string) $tokenRow['scopes'],
            (int) $tokenRow['id_employee']
        )];

        if ($modern) {
            $result['resultType'] = 'complete';
            $result['ttlMs'] = self::toolsCacheTtlMs();
            // Private: what a token may be handed depends on the account
            // behind it, so one client's list must never be served to another.
            $result['cacheScope'] = 'private';
        }

        return $result;
    }

    /**
     * One prompt, filled with what the merchant typed.
     *
     * @param mixed $id
     * @param array<string, mixed> $params
     * @param array<string, mixed> $tokenRow
     * @param bool $modern
     *
     * @return array<string, mixed>
     */
    private function promptsGetResult($id, array $params, array $tokenRow, bool $modern): array
    {
        $name = (string) ($params['name'] ?? '');
        $idEmployee = (int) $tokenRow['id_employee'];
        $prompt = SomcpPromptRegistry::getInstance()->locate($name, (string) $tokenRow['scopes'], $idEmployee);

        if ($prompt === null) {
            SomcpLogger::log('prompt_refused', SomcpLogger::OUTCOME_FAILURE, Tools::substr($name, 0, 100), '', $idEmployee);

            // The same answer whether the prompt does not exist, is switched
            // off, or is out of this account's reach: the three are not a
            // caller's business, and telling them apart maps the shop.
            return SomcpJsonRpc::error($id, SomcpJsonRpc::INVALID_PARAMS, 'Prompt not found', ['name' => $name]);
        }

        $given = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $missing = $prompt->missingArgument($given);
        if ($missing !== '') {
            return SomcpJsonRpc::error($id, SomcpJsonRpc::INVALID_PARAMS, 'Missing required argument: ' . $missing);
        }

        $result = $prompt->render($given);
        if ($result === null) {
            // Readable when it was registered, so something changed on disk
            // since. The detail goes to the log, not to the caller.
            SomcpLogger::log('prompt_unreadable', SomcpLogger::OUTCOME_FAILURE, $name, '', $idEmployee);

            return SomcpJsonRpc::error($id, SomcpJsonRpc::INTERNAL_ERROR, 'Prompt unavailable');
        }

        if ($modern) {
            $result['resultType'] = 'complete';
            $result['ttlMs'] = self::toolsCacheTtlMs();
            $result['cacheScope'] = 'private';
        }

        return SomcpJsonRpc::result($id, $result);
    }

    /**
     * How long the shop tells a client it may keep the list of tools.
     *
     * Announced rather than enforced: the server is stateless, so it has no
     * connection to push a change down and no way of shortening a cache a
     * client already holds. Set it low while tools are being written, and back
     * up in production where the list barely moves.
     *
     * @return int milliseconds
     */
    private static function toolsCacheTtlMs(): int
    {
        $seconds = (int) SomcpConfig::get(SomcpConfig::TOOLS_TTL);
        $seconds = max(self::TOOLS_TTL_MIN, min(self::TOOLS_TTL_MAX, $seconds));

        return $seconds * 1000;
    }

    /**
     * Answer to server/discover, which replaces the handshake.
     *
     * @param bool $modern
     *
     * @return array<string, mixed>
     */
    private function discoverResult(bool $modern): array
    {
        $result = [
            'supportedVersions' => SomcpProtocol::supportedVersions(),
            'capabilities' => self::capabilities(),
            'instructions' => 'Tools exposed by the PrestaShop shop "'
                . (string) Configuration::get('PS_SHOP_NAME') . '" through O\'So MCP Server.',
            '_meta' => [SomcpProtocol::META_SERVER_INFO => $this->serverInfo()],
        ];

        if ($modern) {
            $result['resultType'] = 'complete';
            $result['ttlMs'] = self::toolsCacheTtlMs();
            // Private: what this server exposes depends on the token presented,
            // so one client's answer must never be reused for another.
            $result['cacheScope'] = 'private';
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $tokenRow
     * @param bool $modern
     *
     * @return array<string, mixed>
     */
    private function toolsListResult(array $tokenRow, bool $modern): array
    {
        $result = ['tools' => SomcpToolRegistry::getInstance()->describeFor(
            (string) $tokenRow['scopes'],
            (int) $tokenRow['id_employee']
        )];

        if ($modern) {
            $result['resultType'] = 'complete';
            $result['ttlMs'] = self::toolsCacheTtlMs();
            $result['cacheScope'] = 'private';
        }

        return $result;
    }

    /**
     * Runs a tool.
     *
     * A failure inside a tool comes back as a tool result carrying isError,
     * not as a JSON-RPC error: the call itself succeeded, and the assistant is
     * meant to read what went wrong and try something else.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $tokenRow
     * @param bool $modern
     *
     * @return array<string, mixed>
     */
    private function toolsCallResult(array $params, array $tokenRow, bool $modern): array
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $tool = SomcpToolRegistry::getInstance()->get($name);
        if ($tool === null) {
            return $this->finish(SomcpTool::text('Unknown tool: ' . Tools::substr($name, 0, 60), true), $modern);
        }

        $idEmployee = (int) $tokenRow['id_employee'];

        if (!SomcpToolRegistry::isEnabled($name)) {
            // Checked again even though the tool was left out of the listing,
            // for the same reason the permission is: an assistant keeps that
            // listing for minutes, and a tool switched off this morning would
            // otherwise stay callable until the cache expires.
            SomcpLogger::log(
                'tool_disabled',
                SomcpLogger::OUTCOME_FAILURE,
                'tool ' . $name . ' is switched off for this shop',
                '',
                $idEmployee
            );

            return $this->finish(
                SomcpTool::text('This tool is switched off for this shop.', true),
                $modern
            );
        }

        if (!SomcpScope::covers((string) $tokenRow['scopes'], $tool->getRequiredScopes())) {
            $missing = implode(' ', $tool->getRequiredScopes());
            SomcpLogger::log(
                'scope_refused',
                SomcpLogger::OUTCOME_FAILURE,
                'tool ' . $name . ' needs ' . $missing,
                '',
                $idEmployee
            );

            // 403 with the scopes needed, so the assistant can ask the merchant
            // for them instead of guessing.
            SomcpHttp::json(
                ['error' => 'insufficient_scope', 'scope' => $missing],
                403,
                ['WWW-Authenticate' => SomcpHttp::challenge($missing, 'insufficient_scope')]
            );
        }

        if (!SomcpPermission::employeeHolds($idEmployee, $tool->getRequiredPermissions())) {
            // Checked again even though the tool was filtered out of the
            // listing: an assistant caches that listing for minutes, so a
            // permission taken away this morning would otherwise leave a window
            // in which the call still goes through.
            SomcpLogger::log(
                'permission_refused',
                SomcpLogger::OUTCOME_FAILURE,
                'tool ' . $name . ' needs ' . SomcpPermission::describe($tool->getRequiredPermissions()),
                '',
                $idEmployee
            );

            // A tool result rather than a protocol error: nothing is wrong with
            // the call, the account simply may not do this, and the assistant
            // should say so to the merchant rather than retry.
            return $this->finish(
                SomcpTool::text('This account does not have the back office permission this tool requires.', true),
                $modern
            );
        }

        $result = SomcpToolRegistry::run($tool, $arguments, [
            // The shop this call is answered for, decided here and never
            // read from the assistant. Handed down so a module asks the
            // context instead of calling into this one to find out.
            'id_shop' => Somcp::currentShopId(),
            'id_employee' => $idEmployee,
            'id_lang' => self::languageOf($idEmployee),
            'id_client' => (int) $tokenRow['id_client'],
            'scopes' => (string) $tokenRow['scopes'],
            // What the client fetched since the first half of this call,
            // and what the tool asked to have handed back. Both are empty
            // on a first call, which is how a tool tells the two apart.
            'responses' => $modern && is_array($params['inputResponses'] ?? null)
                ? $params['inputResponses']
                : [],
            'resumed' => $modern
                ? SomcpRequestState::open(
                    (string) ($params['requestState'] ?? ''),
                    $name,
                    $arguments,
                    $idEmployee
                ) ?? []
                : [],
            'capabilities' => $modern ? SomcpProtocol::clientCapabilities($params) : [],
            // The arguments again, beside the handler's own copy. A tool
            // resuming a step the client could not carry for it reads what
            // the merchant was asked to pass back by hand, and the shared
            // helpers that do that are given the context, not the
            // arguments.
            'arguments' => $arguments,
        ]);

        if (SomcpTool::isInputRequired($result)) {
            return $this->askResult(
                $result,
                $name,
                $arguments,
                $idEmployee,
                $modern,
                $modern ? SomcpProtocol::clientCapabilities($params) : []
            );
        }

        return $this->finish($result, $modern);
    }

    /**
     * Turns a tool's question into the answer the protocol defines for one.
     *
     * The client fetches what is asked, then replays the very same call under a
     * new JSON-RPC id, adding what it got and returning `requestState` exactly
     * as it was given. Nothing is kept here between the two halves: the state is
     * sealed and travels through the client, which is how a stateless server is
     * able to ask a question at all.
     *
     * Two things can stop it. An older client has no multi-round mechanism, and
     * a modern one may not have declared the kind of question being asked -
     * which the specification forbids sending regardless. In both cases the
     * merchant is told plainly, because the alternative is an assistant left
     * waiting for a step it has no way to take.
     *
     * @param array<string, mixed> $result what the tool returned
     * @param string $name
     * @param array<string, mixed> $arguments
     * @param int $idEmployee
     * @param bool $modern
     * @param array<string, mixed> $capabilities what the client declared on this request
     *
     * @return array<string, mixed>
     */
    private function askResult(
        array $result,
        string $name,
        array $arguments,
        int $idEmployee,
        bool $modern,
        array $capabilities,
    ): array {
        $requests = is_array($result['inputRequests'] ?? null) ? $result['inputRequests'] : [];

        $unanswerable = '';
        foreach ($requests as $key => $request) {
            if (!is_array($request) || !SomcpProtocol::clientCanAnswer($capabilities, $request)) {
                $unanswerable = (string) $key;
                break;
            }
        }

        if (!$modern || $requests === [] || $unanswerable !== '') {
            SomcpLogger::log(
                'input_request_impossible',
                SomcpLogger::OUTCOME_FAILURE,
                $this->whyNotAsked($name, $modern, $requests === [], $unanswerable),
                '',
                $idEmployee
            );

            return $this->finish(
                SomcpTool::text(
                    'This tool needs to ask you something, and this assistant cannot carry the question.'
                    . ' Ask it to do the same thing from the shop back office instead.',
                    true
                ),
                $modern
            );
        }

        $state = SomcpRequestState::seal(
            is_array($result['state'] ?? null) ? $result['state'] : [],
            $name,
            $arguments,
            $idEmployee
        );

        if ($state === '') {
            return $this->finish(SomcpTool::text('The tool failed while running.', true), $modern);
        }

        return [
            'resultType' => 'input_required',
            'inputRequests' => $requests,
            'requestState' => $state,
        ];
    }

    /**
     * Why a question could not be put to the client, for the journal.
     *
     * The merchant is told one thing - the assistant cannot carry the question -
     * while the log says which of the three reasons it was, because that is the
     * difference between a client to replace and a tool to fix.
     *
     * @param string $name
     * @param bool $modern
     * @param bool $empty
     * @param string $unanswerable key of the first question the client cannot answer
     *
     * @return string
     */
    private function whyNotAsked(string $name, bool $modern, bool $empty, string $unanswerable): string
    {
        if (!$modern) {
            return $name . ' needs a revision this client does not speak';
        }
        if ($empty) {
            return $name . ' asked for nothing';
        }

        return $name . ' asked for ' . Tools::substr($unanswerable, 0, 40)
            . ', which this client did not say it can answer';
    }

    /**
     * @param array<string, mixed> $result
     * @param bool $modern
     *
     * @return array<string, mixed>
     */
    private function finish(array $result, bool $modern): array
    {
        if ($modern) {
            $result['resultType'] = 'complete';
        }

        return $result;
    }

    /**
     * Who this server says it is.
     *
     * The shape of Implementation in the specification: name and version are
     * required, title, description, websiteUrl and icons are not. It is
     * self-reported metadata for display, and a client is told not to make any
     * security decision from it - authorization stays with OAuth, the scopes,
     * the employee and the shop.
     *
     * websiteUrl names the publisher, not the merchant: it identifies who wrote
     * this server, and would be wrong as the address of whichever shop happens
     * to run it. The icon goes the other way - it is served by the shop, from
     * its own domain, as a static public file.
     *
     * @return array<string, mixed>
     */
    private function serverInfo(): array
    {
        return [
            'name' => "O'So MCP Server",
            'title' => "O'So MCP Server",
            'version' => (string) $this->module->version,
            'description' => 'MCP server for connecting PrestaShop data and capabilities'
                . ' to MCP-compatible assistants.',
            'websiteUrl' => 'https://novalo.fr',
            'icons' => [
                [
                    'src' => SomcpConfig::iconUrl(),
                    'mimeType' => 'image/png',
                    'sizes' => ['48x48'],
                ],
            ],
        ];
    }

    /**
     * The language of the account the assistant acts as.
     *
     * A front request carries the visitor's language, and there is no visitor
     * here: the answers belong to the employee who approved the connection.
     *
     * @param int $idEmployee
     *
     * @return int
     */
    private static function languageOf(int $idEmployee): int
    {
        $employee = new Employee($idEmployee);
        if (Validate::isLoadedObject($employee) && (int) $employee->id_lang > 0) {
            return (int) $employee->id_lang;
        }

        return (int) Configuration::get('PS_LANG_DEFAULT');
    }
}
