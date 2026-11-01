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
 * Shop test: every tool, called for real on an installed PrestaShop.
 *
 * Syntax checks, PHPStan and the coding standard all read the code; none of
 * them runs it against a database. A tool that names a column PrestaShop 8.0
 * does not have, or calls a method PrestaShop 9 removed, passes all of them
 * and fails at the merchant's. This script runs inside a freshly installed
 * shop with its demo data and calls each tool the way the MCP endpoint does.
 *
 * Each tool has a scenario in dev/shop-tests/scenarios/<tool name>.json:
 *
 *   {"calls": [{"why": "...", "arguments": {...}, "expect": "ok", "contains": ["..."]}]}
 *
 * "expect" is ok, error (the tool answers with isError), input (the tool asks
 * the client for something before finishing) or any (only the absence of a
 * crash is checked - for a write whose outcome depends on the demo data).
 * "contains" lists strings the text of the answer must hold.
 *
 * A call fails when the tool throws, returns something that is not a tool
 * result, answers differently from "expect", or makes PHP raise a notice,
 * warning or deprecation from a file of the module. Notices from the core are
 * left alone: they are PrestaShop's, and some versions raise them on every
 * request under recent PHP.
 *
 * Then the permissions: every tool must be refused to an employee whose
 * profile holds no right, to an account that does not exist and to a token
 * without its scope, and only somcp_ping may ask for no right at all.
 *
 * Usage: php dev/shop-tests/run.php <prestashop root>
 * Exit code 0 when every call behaved, 1 otherwise.
 */

$psRoot = realpath($argv[1] ?? '');
if ($psRoot === false || !is_file($psRoot . '/config/config.inc.php')) {
    fwrite(STDERR, "Usage: php dev/shop-tests/run.php <prestashop root>\n");
    exit(1);
}

$scenarioDir = __DIR__ . '/scenarios';
$moduleDir = $psRoot . '/modules/somcp/';

// The back-office side of PrestaShop: a command line has no host to pick a
// shop from, and the admin side falls back on the default shop, which is the
// one the tools answer for here. The source tree names its folder admin-dev.
foreach (['admin-dev', 'admin'] as $folder) {
    if (is_dir($psRoot . '/' . $folder)) {
        define('_PS_ADMIN_DIR_', $psRoot . '/' . $folder);
        break;
    }
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require $psRoot . '/config/config.inc.php';

// A back-office request runs inside the Symfony kernel, and the core reaches
// for its container from legacy code - an order changing status does, through
// the hooks it fires. PrestaShop finds that container through the global
// $kernel, so the back-office kernel is booted here as a request would.
// PrestaShop 9 split it into AdminKernel; PrestaShop 8 has AppKernel.
$kernelClass = class_exists('AdminKernel') ? 'AdminKernel' : 'AppKernel';
$kernel = new $kernelClass('prod', false);
$kernel->boot();

// Collected rather than thrown, so one notice does not hide the next one, and
// kept to the module's own files: the core is not what is being tested.
$moduleNotices = [];
set_error_handler(static function (int $level, string $message, string $file, int $line) use (&$moduleNotices, $moduleDir): bool {
    if (strpos($file, $moduleDir) === 0) {
        $moduleNotices[] = substr($file, strlen($moduleDir)) . ':' . $line . ' ' . $message;
    }

    // false lets PHP carry on with its own handling for the core's notices.
    return strpos($file, $moduleDir) === 0;
});

$context = Context::getContext();
$employee = new Employee(1);
if (!Validate::isLoadedObject($employee)) {
    fwrite(STDERR, "No employee #1: the shop is not installed.\n");
    exit(1);
}
$context->employee = $employee;
$context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
Shop::setContext(Shop::CONTEXT_SHOP, (int) $context->shop->id);
$context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
$context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
$context->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));

// Writes such as a status change email the customer. The runner has no mail
// server, and a test shop has no customer to write to anyway.
Configuration::updateValue('PS_MAIL_METHOD', 3);

$module = Module::getInstanceByName('somcp');
if (!$module instanceof Somcp || !Module::isInstalled('somcp')) {
    fwrite(STDERR, "The somcp module is not installed in this shop.\n");
    exit(1);
}
Somcp::bootClasses();

$registry = SomcpToolRegistry::getInstance();
$failures = [];
$calls = 0;

// Every file in tools/ must have become a tool. The registry skips a file it
// cannot load and only says so in its log, which nobody reads in CI.
$shipped = [];
foreach (glob($moduleDir . 'tools/Somcp*.php') ?: [] as $file) {
    if (preg_match("/^\\s*'(somcp_[a-z0-9_]+)',?\\s*$/m", (string) file_get_contents($file), $m)) {
        $shipped[$m[1]] = basename($file);
    }
}
foreach ($shipped as $name => $file) {
    if (!$registry->has($name)) {
        $failures[] = "$name: tools/$file did not register";
    }
}

printf("PrestaShop %s, PHP %s, %d tools\n\n", _PS_VERSION_, PHP_VERSION, count($shipped));

foreach (array_keys($shipped) as $name) {
    $tool = $registry->get($name);
    if ($tool === null) {
        continue;
    }

    if ($tool->getSchemaProblem() !== '') {
        $failures[] = "$name: schema - " . $tool->getSchemaProblem();
    }

    $scenarioFile = $scenarioDir . '/' . $name . '.json';
    $scenario = is_file($scenarioFile) ? json_decode((string) file_get_contents($scenarioFile), true) : null;
    if (!is_array($scenario) || !is_array($scenario['calls'] ?? null) || $scenario['calls'] === []) {
        $failures[] = "$name: no scenario in dev/shop-tests/scenarios/$name.json";
        continue;
    }

    foreach ($scenario['calls'] as $i => $call) {
        ++$calls;
        $label = $name . ' #' . ($i + 1) . ' (' . (string) ($call['why'] ?? '') . ')';
        $arguments = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
        $expect = (string) ($call['expect'] ?? 'ok');
        $moduleNotices = [];

        try {
            // The same context the endpoint hands a tool, for a superadmin
            // whose token carries exactly the scopes this tool needs.
            $result = $tool->call($arguments, [
                'id_shop' => (int) $context->shop->id,
                'id_employee' => (int) $employee->id,
                'id_lang' => (int) $context->language->id,
                'id_client' => 0,
                'scopes' => implode(' ', $tool->getRequiredScopes()),
                'responses' => [],
                'resumed' => [],
                'capabilities' => [],
                'arguments' => $arguments,
            ]);
        } catch (Throwable $e) {
            $failures[] = "$label: " . get_class($e) . ' - ' . $e->getMessage()
                . ' at ' . str_replace($psRoot . '/', '', $e->getFile()) . ':' . $e->getLine();
            continue;
        }

        foreach ($moduleNotices as $notice) {
            $failures[] = "$label: PHP notice - $notice";
        }

        $input = SomcpTool::isInputRequired($result);
        if (!$input && !is_array($result['content'] ?? null)) {
            $failures[] = "$label: not a tool result (no content)";
            continue;
        }

        $outcome = $input ? 'input' : (!empty($result['isError']) ? 'error' : 'ok');
        if ($expect !== 'any' && $outcome !== $expect) {
            $failures[] = "$label: expected $expect, got $outcome - " . excerpt($result);
            continue;
        }

        $text = '';
        foreach ($result['content'] ?? [] as $block) {
            $text .= (string) ($block['text'] ?? '') . "\n";
        }
        foreach ((array) ($call['contains'] ?? []) as $needle) {
            if (strpos($text, (string) $needle) === false) {
                $failures[] = "$label: answer does not contain \"$needle\" - " . excerpt($result);
            }
        }

        printf("  %-6s %s\n", $outcome, $label);
    }
}

// Permissions. The calls above run as a superadmin holding exactly the scopes
// each tool needs; what matters as much is who is turned away. Checked on the
// registry's own gate - isAvailableTo(), which decides both the listing and
// the call - so a tool that forgets its acts() fails here and not at a shop.
$checks = 0;
$nobody = permissionlessEmployee((int) $context->language->id);
if ($nobody === 0) {
    $failures[] = 'permissions: the employee without rights could not be created';
} else {
    foreach (array_keys($shipped) as $name) {
        $tool = $registry->get($name);
        if ($tool === null) {
            continue;
        }

        $scopes = implode(' ', $tool->getRequiredScopes());
        $required = $tool->getRequiredPermissions();
        $checks += 5;

        // Only the connection check is meant to ask for no back-office right.
        if ($required === [] && $name !== 'somcp_ping') {
            $failures[] = "$name: asks for no back-office permission - declare it with SomcpTool::acts()";
        }
        if (!$tool->isAvailableTo($scopes, (int) $employee->id)) {
            $failures[] = "$name: refused to a superadmin holding its scopes";
        }
        if ($required !== [] && $tool->isAvailableTo($scopes, $nobody)) {
            $failures[] = "$name: open to an employee whose profile has no right at all";
        }
        if ($tool->getRequiredScopes() !== [] && $tool->isAvailableTo('', (int) $employee->id)) {
            $failures[] = "$name: open to a token without its scope";
        }
        // An account that does not exist - deleted since the token was issued.
        if ($tool->isAvailableTo($scopes, 999999)) {
            $failures[] = "$name: open to an employee that does not exist";
        }
    }
}

// The built-in assistant, end to end, with Neuron AI's own fake provider: no
// network, no key, but the real framework, the real tables and the real tools.
// A read-only tool runs on the next step; a tool that changes the shop stops
// on an approval, a refusal runs nothing, an approval runs it. Then another
// employee must not be able to open the conversation.
$assistantChecks = 0;
if (!class_exists('NeuronAI\\Testing\\FakeAIProvider')) {
    $failures[] = 'assistant: Neuron AI is not installed - run composer install in the module';
} else {
    $order = new Order(2);
    $status = (int) $order->current_state;
    $call = static function (string $name, string $id, array $inputs): NeuronAI\Chat\Messages\ToolCallMessage {
        return new NeuronAI\Chat\Messages\ToolCallMessage(null, [new NeuronAI\Tools\ToolCall($name, $id, $inputs, null, true)]);
    };
    $fake = new NeuronAI\Testing\FakeAIProvider(
        $call(SomcpAssistantChat::FIND_TOOL, 'call_find', ['load' => ['somcp_set_order_status']]),
        $call('somcp_ping', 'call_ping', []),
        $call('somcp_set_order_status', 'call_refused', ['id_order' => 2, 'status_id' => $status === 8 ? 6 : 8]),
        new NeuronAI\Chat\Messages\AssistantMessage('Nothing was changed.'),
        $call('somcp_set_order_status', 'call_approved', ['id_order' => 2, 'status_id' => $status]),
        new NeuronAI\Chat\Messages\AssistantMessage('Done.')
    );
    SomcpAssistantChat::overrideProvider($fake);
    $idAssistant = SomcpAssistant::save(0, [
        'provider' => 'anthropic', 'api_key' => 'test-key', 'level' => 'economy',
        'model' => 'claude-shop-test', 'role' => '', 'active' => true,
    ]);
    $chat = new SomcpAssistantChat($employee, (int) $context->shop->id);
    $thread = $chat->newThread(0);
    $expect = static function (array $view, string $status, string $label) use (&$failures, &$assistantChecks): void {
        ++$assistantChecks;
        if ($view['status'] !== $status || $view['error'] !== '') {
            $failures[] = "assistant: $label - expected $status, got {$view['status']}" . ($view['error'] !== '' ? " ({$view['error']})" : '');
        }
    };

    try {
        // The shop's tools load on demand: the first call offers the finder
        // and the base tools, not the 76 descriptions; the status tool is
        // loaded by the finder, then offered and called.
        $view = $chat->send($thread, 'Check the connection, then change order 2.');
        $expect($view, 'working', 'the finder waits for the next step');
        ++$assistantChecks;
        $first = $fake->getRecorded()[0]->tools ?? [];
        $names = array_map(static function ($tool): string {
            return (string) $tool->getName();
        }, $first);
        if (!in_array(SomcpAssistantChat::FIND_TOOL, $names, true) || in_array('somcp_set_order_status', $names, true) || count($names) > 6) {
            $failures[] = 'assistant: the first call offers ' . count($names) . ' tools instead of the finder and the base tools';
        }
        $view = $chat->step($thread);
        $expect($view, 'working', 'a read-only tool waits for the next step');
        $view = $chat->step($thread);
        $expect($view, 'approval', 'a change asks for approval');
        if (count($view['actions']) !== 1) {
            $failures[] = 'assistant: one action to approve expected, got ' . count($view['actions']);
        }
        $view = $chat->decide($thread, ['call_refused' => 'reject']);
        $expect($view, 'idle', 'a refusal ends the turn');
        ++$assistantChecks;
        if ((int) (new Order(2))->current_state !== $status) {
            $failures[] = 'assistant: a refused change was applied anyway';
        }
        $view = $chat->send($thread, 'Set it back as it was.');
        $expect($view, 'approval', 'the second change asks for approval');
        $view = $chat->decide($thread, ['call_approved' => 'approve']);
        $expect($view, 'working', 'an approved change runs on the next step');
        $view = $chat->step($thread);
        $expect($view, 'idle', 'the answer after the approved change');
        ++$assistantChecks;
        $last = end($view['messages']);
        if (!is_array($last) || $last['text'] !== 'Done.') {
            $failures[] = 'assistant: the final answer is missing from the conversation';
        }

        // Ownership: the same thread id, asked for by another employee.
        ++$assistantChecks;
        $other = new Employee($nobody > 0 ? $nobody : 999999);
        if ((new SomcpAssistantChat($other, (int) $context->shop->id))->thread((string) $thread['thread_id']) !== null) {
            $failures[] = 'assistant: another employee could open the conversation';
        }
    } catch (Throwable $e) {
        $failures[] = 'assistant: ' . get_class($e) . ' - ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    $chat->deleteThread($thread);

    // An image generated by another assistant, put on a product. The second
    // assistant draws it, the module keeps it as a deposit, the leading one
    // calls somcp_update_product with its upload_ref, and after approval the
    // product has one more image - while the conversation keeps its own copy.
    $png = base64_encode((string) file_get_contents(__DIR__ . '/../../logo.png'));
    $placing = new NeuronAI\Tools\ToolCall('somcp_update_product', 'call_place', [], null, true);
    $idPainter = SomcpAssistant::save(0, [
        'provider' => 'gemini', 'api_key' => 'test-key', 'level' => 'economy',
        'model' => 'gemini-shop-test-image', 'role' => 'images', 'active' => true,
    ]);
    SomcpAssistantChat::overrideProvider(new NeuronAI\Testing\FakeAIProvider(
        $call(SomcpAssistantChat::DELEGATE_TOOL, 'call_draw', ['assistant' => (string) $idPainter, 'task' => 'A logo']),
        new NeuronAI\Chat\Messages\AssistantMessage([
            new NeuronAI\Chat\Messages\ContentBlocks\TextContent('Here it is.'),
            new NeuronAI\Chat\Messages\ContentBlocks\ImageContent($png, NeuronAI\Chat\Enums\SourceType::BASE64, 'image/png'),
        ]),
        new NeuronAI\Chat\Messages\AssistantMessage('The image is ready.'),
        new NeuronAI\Chat\Messages\ToolCallMessage(null, [$placing]),
        new NeuronAI\Chat\Messages\AssistantMessage('Image added.')
    ));
    $thread = $chat->newThread(0);
    $chat->loadTools($thread, ['somcp_update_product']);
    $before = count(Image::getImages((int) $context->language->id, 1));

    try {
        $view = $chat->send($thread, 'Draw a logo.');
        $expect($view, 'working', 'the leader hands the drawing over');
        $view = $chat->step($thread);
        $expect($view, 'idle', 'the drawing comes back');
        ++$assistantChecks;
        $drawn = array_filter($view['messages'], static function (array $message): bool {
            return $message['role'] === 'images';
        });
        if ($drawn === []) {
            $failures[] = 'assistant: the generated image is not shown in the conversation';
        }

        $files = json_decode((string) Db::getInstance()->getValue(
            'SELECT `files` FROM `' . _DB_PREFIX_ . "somcp_assistant_thread` WHERE `thread_id` = '" . pSQL((string) $thread['thread_id']) . "'"
        ), true);
        $reference = is_array($files) && isset($files[0]['ref']) ? (string) $files[0]['ref'] : '';
        ++$assistantChecks;
        if ($reference === '') {
            $failures[] = 'assistant: the generated image was not kept as a deposit';
        } else {
            $placing->setInputs(['id_product' => 1, 'images' => [['upload_ref' => $reference]]]);
            $view = $chat->send($thread, 'Put it on product 1.');
            $expect($view, 'approval', 'adding the image asks for approval');
            $view = $chat->decide($thread, ['call_place' => 'approve']);
            $expect($view, 'working', 'the approved image runs on the next step');
            $view = $chat->step($thread);
            $expect($view, 'idle', 'the answer after adding the image');
            ++$assistantChecks;
            if (count(Image::getImages((int) $context->language->id, 1)) !== $before + 1) {
                $failures[] = 'assistant: the product did not get the generated image';
            }
            ++$assistantChecks;
            if (SomcpUpload::find($reference, (int) $employee->id) === null) {
                $failures[] = 'assistant: the conversation lost its image when the tool used it';
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'assistant: ' . get_class($e) . ' - ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    $chat->deleteThread($thread);
    SomcpAssistant::delete($idPainter);

    // The switches of the + menu: kept on the conversation, offered as the
    // model allows, and with thinking and web search on, the thoughts and
    // the cited pages reach the chat - a page that is no web address does not.
    SomcpAssistantChat::overrideProvider(new NeuronAI\Testing\FakeAIProvider(
        (new NeuronAI\Chat\Messages\AssistantMessage([
            new NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent('Compare the prices first.'),
            new NeuronAI\Chat\Messages\ContentBlocks\TextContent('Your mugs cost '),
            new NeuronAI\Chat\Messages\ContentBlocks\TextContent('2 euros more'),
            new NeuronAI\Chat\Messages\ContentBlocks\TextContent('.'),
        ]))->addMetadata('citations', [
            new NeuronAI\Chat\Messages\Citation('c1', 'https://example.com/mugs', 'Mugs'),
            new NeuronAI\Chat\Messages\Citation('c2', 'javascript:alert(1)', 'Not a page'),
        ])
    ));
    // An automatic level offers deep thinking once its model is known, as
    // after a first answer: the model it resolved to is set by hand here,
    // with a fresh date so nothing asks the provider for it.
    Db::getInstance()->update('somcp_assistant', [
        'resolved_model' => 'claude-shop-test',
        'resolved_at' => date('Y-m-d H:i:s'),
    ], '`id_assistant` = ' . (int) $idAssistant);
    $thread = $chat->setSwitches($chat->newThread(0), ['think' => true, 'web' => true, 'memory' => false]);

    try {
        $view = $chat->send($thread, 'Are my mugs too expensive?');
        $expect($view, 'idle', 'an answer with thinking and web search');
        ++$assistantChecks;
        if ($view['switches'] !== ['think' => true, 'web' => true, 'memory' => false]) {
            $failures[] = 'assistant: the switches were not kept on the conversation';
        }
        ++$assistantChecks;
        if (!$view['can']['think'] || !$view['can']['web']) {
            $failures[] = 'assistant: thinking and web search are not offered for Claude';
        }
        $last = end($view['messages']);
        ++$assistantChecks;
        if (!is_array($last) || ($last['reasoning'] ?? '') !== 'Compare the prices first.' || $last['text'] !== 'Your mugs cost 2 euros more.') {
            $failures[] = 'assistant: the thinking or the cited answer is not drawn as sent';
        }
        ++$assistantChecks;
        if (!is_array($last) || ($last['sources'] ?? null) !== [['url' => 'https://example.com/mugs', 'title' => 'Mugs']]) {
            $failures[] = 'assistant: the sources are not the cited web pages alone';
        }
    } catch (Throwable $e) {
        $failures[] = 'assistant: ' . get_class($e) . ' - ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    $chat->deleteThread($thread);

    // Gemini searches through the chat's own tool: a second call of the same
    // model with Google Search alone, whose pages are listed under the step.
    $idSearcher = SomcpAssistant::save(0, [
        'provider' => 'gemini', 'api_key' => 'test-key', 'level' => 'economy',
        'model' => '', 'role' => '', 'active' => true,
    ]);
    SomcpAssistantChat::overrideProvider(new NeuronAI\Testing\FakeAIProvider(
        $call(SomcpAssistantChat::SEARCH_TOOL, 'call_search', ['query' => 'price of a custom mug']),
        (new NeuronAI\Chat\Messages\AssistantMessage('About 13 euros.'))->addMetadata('citations', [
            new NeuronAI\Chat\Messages\Citation('g1', 'https://example.org/prices', 'Mug prices'),
        ]),
        new NeuronAI\Chat\Messages\AssistantMessage('The market is about 13 euros.')
    ));
    $thread = $chat->setSwitches($chat->newThread($idSearcher), ['think' => false, 'web' => true, 'memory' => true]);

    try {
        $view = $chat->send($thread, 'What do custom mugs cost?');
        $expect($view, 'working', 'Gemini asks for a web search');
        $view = $chat->step($thread);
        $expect($view, 'idle', 'the answer after the web search');
        $listed = array_values(array_filter($view['messages'], static function (array $message): bool {
            return $message['role'] === 'sources';
        }));
        ++$assistantChecks;
        if (($listed[0]['sources'] ?? null) !== [['url' => 'https://example.org/prices', 'title' => 'Mug prices']]) {
            $failures[] = 'assistant: the pages of a Gemini web search are not listed';
        }
    } catch (Throwable $e) {
        $failures[] = 'assistant: ' . get_class($e) . ' - ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    $chat->deleteThread($thread);
    SomcpAssistant::delete($idSearcher);

    // An assistant that cannot search borrows one: with Claude set to search
    // for the others, a Mistral assistant gets the search tool, and its
    // pages are listed as for Gemini. Without the setting it gets nothing.
    $idBorrower = SomcpAssistant::save(0, [
        'provider' => 'mistral', 'api_key' => 'test-key', 'level' => 'economy',
        'model' => '', 'role' => '', 'active' => true,
    ]);
    $borrower = SomcpAssistant::get($idBorrower);
    ++$assistantChecks;
    if ($borrower === null || SomcpAssistant::searches($borrower)) {
        $failures[] = 'assistant: Mistral is offered web search with no assistant set to search for it';
    }
    Configuration::updateValue(SomcpAssistant::SEARCH, 'anthropic');
    ++$assistantChecks;
    if ($borrower === null || !SomcpAssistant::searches($borrower)) {
        $failures[] = 'assistant: Mistral is not offered the web search Claude lends';
    }
    SomcpAssistantChat::overrideProvider(new NeuronAI\Testing\FakeAIProvider(
        $call(SomcpAssistantChat::SEARCH_TOOL, 'call_borrowed', ['query' => 'price of a custom mug']),
        (new NeuronAI\Chat\Messages\AssistantMessage('About 13 euros.'))->addMetadata('citations', [
            new NeuronAI\Chat\Messages\Citation('b1', 'https://example.net/mugs', 'Mugs compared'),
        ]),
        new NeuronAI\Chat\Messages\AssistantMessage('Claude found about 13 euros.')
    ));
    $thread = $chat->setSwitches($chat->newThread($idBorrower), ['think' => false, 'web' => true, 'memory' => true]);

    try {
        $view = $chat->send($thread, 'What do custom mugs cost?');
        $expect($view, 'working', 'Mistral asks for the lent web search');
        $view = $chat->step($thread);
        $expect($view, 'idle', 'the answer after the lent web search');
        $listed = array_values(array_filter($view['messages'], static function (array $message): bool {
            return $message['role'] === 'sources';
        }));
        ++$assistantChecks;
        if (($listed[0]['sources'] ?? null) !== [['url' => 'https://example.net/mugs', 'title' => 'Mugs compared']]) {
            $failures[] = 'assistant: the pages of a lent web search are not listed';
        }
    } catch (Throwable $e) {
        $failures[] = 'assistant: ' . get_class($e) . ' - ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    $chat->deleteThread($thread);

    // The module's settings from the chat: a super administrator reads them,
    // and a change waits for approval, then is written through the page's own
    // code - here the security log switched off, and one tool.
    $logWas = SomcpConfig::get(SomcpConfig::LOG_ENABLED);
    $read = SomcpModuleSettings::read((int) $context->shop->id, 'https://example.com/docs');
    ++$assistantChecks;
    if (!in_array(SomcpConfig::LOG_ENABLED, array_column($read['settings'], 'key'), true) || $read['tools']['list'] === []) {
        $failures[] = 'assistant: the module settings read from the chat miss a setting or the tools';
    }
    ++$assistantChecks;
    if (strpos((string) json_encode($read), 'test-key') !== false) {
        $failures[] = 'assistant: the module settings read from the chat carry an API key';
    }
    SomcpAssistantChat::overrideProvider(new NeuronAI\Testing\FakeAIProvider(
        $call(SomcpAssistantChat::SETTINGS_WRITE_TOOL, 'call_setting', ['key' => SomcpConfig::LOG_ENABLED, 'value' => 'off']),
        new NeuronAI\Chat\Messages\AssistantMessage('The security log is off.')
    ));
    $thread = $chat->newThread(0);

    try {
        $view = $chat->send($thread, 'Switch the security log off.');
        $expect($view, 'approval', 'a module setting asks for approval');
        ++$assistantChecks;
        if (SomcpConfig::get(SomcpConfig::LOG_ENABLED) !== $logWas) {
            $failures[] = 'assistant: a module setting changed before it was approved';
        }
        $view = $chat->decide($thread, ['call_setting' => 'approve']);
        $view = $chat->step($thread);
        $expect($view, 'idle', 'the answer after the module setting');
        ++$assistantChecks;
        if (SomcpConfig::get(SomcpConfig::LOG_ENABLED, (int) $context->shop->id) !== '0') {
            $failures[] = 'assistant: the approved module setting was not written';
        }
        ++$assistantChecks;
        if (SomcpModuleSettings::write(SomcpModuleSettings::TOOL_PREFIX . 'somcp_ping', 'off', (int) $context->shop->id) !== ''
            || SomcpToolRegistry::isEnabled('somcp_ping')
            || SomcpModuleSettings::write(SomcpModuleSettings::TOOL_PREFIX . 'somcp_ping', 'on', (int) $context->shop->id) !== ''
            || !SomcpToolRegistry::isEnabled('somcp_ping')) {
            $failures[] = 'assistant: a tool could not be switched off and on again from the chat';
        }
        // Strict on what ties settings together, as the form is: a refresh
        // token shorter than the access token, or a profile that does not
        // exist, is refused and nothing changes.
        ++$assistantChecks;
        $refreshWas = SomcpConfig::get(SomcpConfig::REFRESH_TTL, (int) $context->shop->id);
        if (SomcpModuleSettings::write(SomcpConfig::REFRESH_TTL, '600', (int) $context->shop->id) === ''
            && (int) SomcpConfig::get(SomcpConfig::ACCESS_TTL, (int) $context->shop->id) >= 600) {
            $failures[] = 'assistant: the chat set a refresh token shorter than the access token';
        }
        SomcpConfig::set(SomcpConfig::REFRESH_TTL, $refreshWas);
        ++$assistantChecks;
        if (SomcpModuleSettings::write(SomcpConfig::REQUIRED_PROFILE, '999999', (int) $context->shop->id) === '') {
            $failures[] = 'assistant: the chat set a profile that does not exist';
        }
        ++$assistantChecks;
        if (SomcpModuleSettings::write('SOMCP_STATE_KEY', 'x', (int) $context->shop->id) === '') {
            $failures[] = 'assistant: the chat could write a setting that is not on the settings page';
        }
    } catch (Throwable $e) {
        $failures[] = 'assistant: ' . get_class($e) . ' - ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    SomcpConfig::set(SomcpConfig::LOG_ENABLED, $logWas);

    $chat->deleteThread($thread);
    Configuration::deleteByName(SomcpAssistant::SEARCH);
    SomcpAssistant::delete($idBorrower);
    SomcpAssistant::delete($idAssistant);
    SomcpAssistantChat::overrideProvider(null);
}

echo "\n";
if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "::error::$failure\n";
    }
    printf("%d problem(s) over %d calls, %d permission checks and %d assistant checks.\n", count($failures), $calls, $checks, $assistantChecks);
    exit(1);
}

// A notice too, so the run summary shows how much was exercised and a green
// job cannot hide a test that called nothing.
printf("::notice::Shop test OK on PrestaShop %s, PHP %s: %d calls, %d permission checks and %d assistant checks over %d tools.\n", _PS_VERSION_, PHP_VERSION, $calls, $checks, $assistantChecks, count($shipped));
exit(0);

/**
 * The start of an answer, enough to see why a call went the wrong way.
 *
 * @param array<string, mixed> $result
 */
function excerpt(array $result): string
{
    $text = '';
    foreach ($result['content'] ?? [] as $block) {
        $text .= (string) ($block['text'] ?? '') . ' ';
    }

    return substr(preg_replace('/\s+/', ' ', $text) ?? '', 0, 200);
}

/**
 * An active employee whose profile holds no back-office right at all.
 *
 * A new profile starts with every access off, which is the account a tool
 * must refuse. Its id, or 0 when PrestaShop would not create it.
 */
function permissionlessEmployee(int $idLang): int
{
    $profile = new Profile();
    $profile->name = [$idLang => 'Shop test - no rights'];
    if (!$profile->add()) {
        return 0;
    }

    $employee = new Employee();
    $employee->firstname = 'No';
    $employee->lastname = 'Rights';
    $employee->email = 'no-rights@example.com';
    // Never used to log in: the test asks the registry, not the login page.
    $employee->passwd = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
    $employee->id_profile = (int) $profile->id;
    $employee->id_lang = $idLang;
    $employee->active = true;

    return $employee->add() ? (int) $employee->id : 0;
}
