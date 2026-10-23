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

echo "\n";
if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "::error::$failure\n";
    }
    printf("%d problem(s) over %d calls.\n", count($failures), $calls);
    exit(1);
}

// A notice too, so the run summary shows how much was exercised and a green
// job cannot hide a test that called nothing.
printf("::notice::Shop test OK on PrestaShop %s, PHP %s: %d calls over %d tools.\n", _PS_VERSION_, PHP_VERSION, $calls, count($shipped));
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
