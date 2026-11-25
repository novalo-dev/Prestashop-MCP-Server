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
 * "contains" lists strings the text of the answer must hold, "lacks" strings
 * it must not: a scenario can then prove that a key stays out of an answer.
 *
 * A call fails when the tool throws, returns something that is not a tool
 * result, answers differently from "expect", or makes PHP raise a notice,
 * warning or deprecation from a file of the module. Notices from the core are
 * left alone: they are PrestaShop's, and some versions raise them on every
 * request under recent PHP.
 *
 * Then the permissions: every tool must be refused to an employee whose
 * profile holds no right, to an account that does not exist and to a token
 * without its scope, and only somcp_ping and the two file tools, which check
 * the rights of what they reach, may ask for no right of their own.
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

require __DIR__ . '/boot.php';
require __DIR__ . '/module-archive.php';

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
        // "contains" alone cannot prove that a stored secret never comes back:
        // a later change returning it would still hold every expected string.
        foreach ((array) ($call['lacks'] ?? []) as $needle) {
            if (strpos($text, (string) $needle) !== false) {
                $failures[] = "$label: answer contains \"$needle\" - " . excerpt($result);
            }
        }

        printf("  %-6s %s\n", $outcome, $label);
    }
}

// A module archive deposited, then installed, as a merchant does it (#252):
// the scenario of somcp_upload_module only covers its refusals. The archive
// goes in the way any assistant puts a file on the shop, a deposit opened for
// the tool and filled with a data URI, and the module is removed afterwards.
$uploadName = 'somcpuploadtest';
$upload = $registry->get('somcp_upload_module');
if ($upload !== null) {
    ++$calls;
    $reference = SomcpUpload::inviteFor((int) $employee->id, $upload, SomcpUpload::ARG_REF);
    $invitation = $reference === '' ? null : SomcpUpload::find($reference, (int) $employee->id);
    $entered = $invitation === null ? 'no deposit could be opened' : SomcpUpload::enter(
        $invitation,
        'data',
        'data:application/zip;base64,' . base64_encode(somcpTestModuleArchive($uploadName, '1.0.0')),
        $uploadName . '.zip'
    );
    if ($entered !== '') {
        $failures[] = 'somcp_upload_module (an archive installed): the archive was not deposited - ' . $entered;
    } else {
        try {
            $result = $upload->call([SomcpUpload::ARG_REF => $reference], [
                'id_shop' => (int) $context->shop->id,
                'id_employee' => (int) $employee->id,
                'id_lang' => (int) $context->language->id,
                'id_client' => 0,
                'scopes' => implode(' ', $upload->getRequiredScopes()),
                'responses' => [],
                'resumed' => [],
                'capabilities' => [],
                'arguments' => [SomcpUpload::ARG_REF => $reference],
            ]);
            $installed = (string) Db::getInstance()->getValue(
                'SELECT `version` FROM `' . _DB_PREFIX_ . "module` WHERE `name` = '" . pSQL($uploadName) . "'"
            );
            if (!empty($result['isError']) || $installed !== '1.0.0') {
                $failures[] = 'somcp_upload_module (an archive installed): expected ' . $uploadName . ' 1.0.0 installed, got "'
                    . $installed . '" - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_upload_module (an archive deposited, then installed)');
            }
        } catch (Throwable $e) {
            $failures[] = 'somcp_upload_module (an archive installed): ' . get_class($e) . ' - ' . $e->getMessage();
        } finally {
            $left = Module::getInstanceByName($uploadName);
            if ($left instanceof Module && Module::isInstalled($uploadName)) {
                $left->uninstall();
            }
            Tools::deleteDirectory(_PS_MODULE_DIR_ . $uploadName);
        }
    }
}

// A product changed by its tags alone, then searched for: the index holds
// the tags, categories, features and combinations as well as the texts, and
// the scenarios only read the tool's answer, never the index. The tag is new,
// so the word can only be in the index if the update indexed the product
// again; the product's tags and the setting are put back afterwards.
$updateProduct = $registry->get('somcp_update_product');
$idIndexed = 1;
$idLangEn = (int) Language::getIdByIso('en');
if ($updateProduct !== null && $idLangEn > 0) {
    ++$calls;
    $indexation = Configuration::get('PS_SEARCH_INDEXATION');
    $tagsBefore = Tag::getProductTags($idIndexed);
    Configuration::updateValue('PS_SEARCH_INDEXATION', 1);
    try {
        $result = $updateProduct->call([
            'id_product' => $idIndexed,
            'languages' => ['en'],
            'seo' => ['tags' => ['en' => 'somcpreindexed']],
        ], [
            'id_shop' => (int) $context->shop->id,
            'id_employee' => (int) $employee->id,
            'id_lang' => (int) $context->language->id,
            'id_client' => 0,
            'scopes' => implode(' ', $updateProduct->getRequiredScopes()),
            'responses' => [],
            'resumed' => [],
            'capabilities' => [],
            'arguments' => [],
        ]);
        $found = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'search_index` si'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'search_word` sw ON sw.`id_word` = si.`id_word`'
            . ' WHERE si.`id_product` = ' . $idIndexed . " AND sw.`word` = 'somcpreindexed'"
            . ' AND sw.`id_lang` = ' . $idLangEn . ' AND sw.`id_shop` = ' . (int) $context->shop->id
        );
        if (!empty($result['isError']) || $found === 0) {
            $failures[] = 'somcp_update_product (tags alone): expected the new tag in the search index, found '
                . $found . ' - ' . excerpt($result);
        } else {
            printf("  %-6s %s\n", 'ok', 'somcp_update_product (tags alone, the product indexed again)');
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_product (tags alone): ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        Tag::deleteTagsForProduct($idIndexed);
        foreach (is_array($tagsBefore) ? $tagsBefore : [] as $idLang => $names) {
            Tag::addTags((int) $idLang, $idIndexed, $names);
        }
        Configuration::updateValue('PS_SEARCH_INDEXATION', $indexation);
        Search::indexation(false, $idIndexed);
    }
}

// An order status icon given by deposit, then the status deleted twice
// (#224): the JSON scenarios cannot hold a real deposit, nor the identifier of
// a status they created, which differs from one version to the other. The
// icon form takes a GIF only, so a PNG is turned away at the deposit and a
// GIF is stored; the status created with it is then deleted, and deleting it
// again must be refused rather than read as a success.
$createStatus = $registry->get('somcp_create_order_status');
$deleteStatus = $registry->get('somcp_delete_order_status');
if ($createStatus !== null && $deleteStatus !== null) {
    $statusCall = static function (SomcpTool $tool, array $arguments) use ($context, $employee): array {
        return $tool->call($arguments, [
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
    };
    $statusText = static function (array $result): string {
        $text = '';
        foreach ($result['content'] ?? [] as $block) {
            $text .= (string) ($block['text'] ?? '') . "\n";
        }

        return $text;
    };
    $deposit = static function (string $dataUri, string $name) use ($createStatus, $employee): array {
        $reference = SomcpUpload::inviteFor((int) $employee->id, $createStatus, 'icon');
        $invitation = $reference === '' ? null : SomcpUpload::find($reference, (int) $employee->id);

        return [
            $reference,
            $invitation === null ? 'no deposit could be opened' : SomcpUpload::enter($invitation, 'data', $dataUri, $name),
        ];
    };

    ++$calls;
    [, $entered] = $deposit('data:image/png;base64,' . base64_encode((string) file_get_contents(__DIR__ . '/../../logo.png')), 'icon.png');
    if ($entered === '' || strpos($entered, 'only gif are accepted') === false) {
        $failures[] = 'somcp_create_order_status (a PNG icon): expected the deposit to take a GIF only, got "' . $entered . '"';
    } else {
        printf("  %-6s %s\n", 'error', 'somcp_create_order_status (a PNG icon turned away at the deposit)');
    }

    ++$calls;
    // The smallest valid GIF: one transparent pixel.
    [$reference, $entered] = $deposit('data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'icon.gif');
    $idCreated = 0;
    if ($entered !== '') {
        $failures[] = 'somcp_create_order_status (a GIF icon): the GIF was not deposited - ' . $entered;
    } else {
        try {
            $result = $statusCall($createStatus, [
                'languages' => ['en'],
                'name' => ['en' => 'Somcp deleted twice'],
                'icon' => ['upload_ref' => $reference],
            ]);
            $answer = json_decode(trim($statusText($result)), true);
            $idCreated = is_array($answer) ? (int) ($answer['status_id'] ?? 0) : 0;
            if (!empty($result['isError']) || $idCreated === 0 || empty($answer['icon']['stored'])) {
                $failures[] = 'somcp_create_order_status (a GIF icon): expected a status created with its icon stored - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_create_order_status (a GIF icon deposited, then stored)');
            }
        } catch (Throwable $e) {
            $failures[] = 'somcp_create_order_status (a GIF icon): ' . get_class($e) . ' - ' . $e->getMessage();
        }
    }

    if ($idCreated > 0) {
        $calls += 2;
        try {
            $result = $statusCall($deleteStatus, ['status_id' => $idCreated]);
            $text = $statusText($result);
            $missing = array_filter(['"deleted": true', '"language": "en"', '"orders_with_it": 0'], static function (string $needle) use ($text): bool {
                return strpos($text, $needle) === false;
            });
            if (!empty($result['isError']) || $missing !== []) {
                $failures[] = 'somcp_delete_order_status (a status the merchant created): expected it deleted - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_delete_order_status (a status the merchant created)');
            }

            $result = $statusCall($deleteStatus, ['status_id' => $idCreated]);
            $text = $statusText($result);
            if (empty($result['isError']) || strpos($text, 'Nothing was deleted') === false
                || strpos($text, 'no order status of this shop has the identifier ' . $idCreated) === false) {
                $failures[] = 'somcp_delete_order_status (the same status again): expected a refusal - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'error', 'somcp_delete_order_status (the same status again, refused)');
            }
        } catch (Throwable $e) {
            $failures[] = 'somcp_delete_order_status (a status the merchant created): ' . get_class($e) . ' - ' . $e->getMessage();
        }
    }
}

// A tax rule created, updated, then deleted twice (#294, #296): the JSON
// scenarios cannot hold the identifier of a rule they created, which differs
// from one version to the other, and every demo rule is still taken by a
// product, a carrier or the lines of the demo orders, so none of them can be
// renamed, switched off or deleted. The rule must leave no row behind, and
// deleting it again must be refused rather than read as a success.
$createTaxRule = $registry->get('somcp_create_tax_rule');
$updateTaxRule = $registry->get('somcp_update_tax_rule');
$deleteTaxRule = $registry->get('somcp_delete_tax_rule');
if ($createTaxRule !== null && $updateTaxRule !== null && $deleteTaxRule !== null) {
    $taxRuleCall = static function (SomcpTool $tool, array $arguments) use ($context, $employee): array {
        return $tool->call($arguments, [
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
    };
    $taxRuleText = static function (array $result): string {
        $text = '';
        foreach ($result['content'] ?? [] as $block) {
            $text .= (string) ($block['text'] ?? '') . "\n";
        }

        return $text;
    };

    $calls += 7;
    try {
        $result = $taxRuleCall($createTaxRule, ['name' => 'Somcp deleted twice', 'active' => true]);
        $answer = json_decode(trim($taxRuleText($result)), true);
        $idRule = is_array($answer) ? (int) ($answer['tax_rule']['id_tax_rules_group'] ?? 0) : 0;
        if (!empty($result['isError']) || $idRule === 0) {
            $failures[] = 'somcp_delete_tax_rule (a rule just created): the rule could not be created - ' . excerpt($result);
        } else {
            // Each step leaves the rule as the next one expects it, and the last
            // one gives it back its name, so the deletion below reads the same.
            $updates = [
                'a new name' => [['name' => 'Somcp renamed tax rule'], ['"changed": true', '"field": "name"', '"old": "Somcp deleted twice"', '"new": "Somcp renamed tax rule"', '"tax_rule"', '"name": "Somcp renamed tax rule"']],
                'the name it already holds, nothing written' => [['name' => 'Somcp renamed tax rule'], ['"changed": false', '"id_tax_rules_group": ' . $idRule, 'Nothing was written.']],
                'switched off, as the Enabled column of the list' => [['active' => false], ['"changed": true', '"field": "active"', '"active": false']],
                'switched back on with its first name' => [['active' => true, 'name' => 'Somcp deleted twice'], ['"changed": true', '"active": true', '"name": "Somcp deleted twice"']],
            ];
            foreach ($updates as $why => [$arguments, $needles]) {
                $result = $taxRuleCall($updateTaxRule, ['id_tax_rules_group' => $idRule] + $arguments);
                $text = $taxRuleText($result);
                $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                    return strpos($text, $needle) === false;
                });
                if (!empty($result['isError']) || $missing !== []) {
                    $failures[] = 'somcp_update_tax_rule (' . $why . '): expected ok with ' . implode(', ', $missing) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_tax_rule (' . $why . ')');
                }
            }

            $result = $taxRuleCall($deleteTaxRule, ['id_tax_rules_group' => $idRule]);
            $text = $taxRuleText($result);
            $left = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rules_group` WHERE `id_tax_rules_group` = ' . $idRule
            );
            $missing = array_filter(['"deleted": true', '"name": "Somcp deleted twice"', '"country_rules_deleted": 0'], static function (string $needle) use ($text): bool {
                return strpos($text, $needle) === false;
            });
            if (!empty($result['isError']) || $missing !== [] || $left !== 0) {
                $failures[] = 'somcp_delete_tax_rule (a rule just created): expected it deleted with no row left - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_delete_tax_rule (a rule just created)');
            }

            $result = $taxRuleCall($deleteTaxRule, ['id_tax_rules_group' => $idRule]);
            $text = $taxRuleText($result);
            if (empty($result['isError']) || strpos($text, 'Nothing was deleted') === false
                || strpos($text, 'no tax rule of this shop has the identifier ' . $idRule) === false) {
                $failures[] = 'somcp_delete_tax_rule (the same rule again): expected a refusal - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'error', 'somcp_delete_tax_rule (the same rule again, refused)');
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_delete_tax_rule (a rule just created): ' . get_class($e) . ' - ' . $e->getMessage();
    }

    // Rows added to a rule just created, changed, then deleted (#303, #306, #304): the scenario cannot hold the
    // identifier of the rule, and every demo rule is taken by orders, so the
    // working addition is tried here, then the rule is deleted whatever happens.
    $addCountryRule = $registry->get('somcp_add_country_rule');
    if ($addCountryRule !== null) {
        $calls += 2;
        $idRows = 0;
        try {
            $result = $taxRuleCall($createTaxRule, ['name' => 'Somcp country rows', 'active' => true]);
            $answer = json_decode(trim($taxRuleText($result)), true);
            $idRows = is_array($answer) ? (int) ($answer['tax_rule']['id_tax_rules_group'] ?? 0) : 0;
            $rowsAsked = [
                'id_tax_rules_group' => $idRows, 'country' => 'US', 'states' => ['AL', 'FL'],
                'zipcode_from' => '36000', 'zipcode_to' => '35000', 'id_tax' => 1,
                'behavior' => 'this_tax_only', 'description' => 'somcp',
            ];
            $result = $taxRuleCall($addCountryRule, $rowsAsked);
            $answer = json_decode(trim($taxRuleText($result)), true);
            $rowsRead = is_array($answer) ? ($answer['tax_rule']['country_rules'] ?? []) : [];
            $folded = array_filter($rowsRead, static function ($row): bool {
                return is_array($row) && ($row['zipcode_from'] ?? null) === '35000' && ($row['zipcode_to'] ?? null) === '36000'
                    && ($row['behavior'] ?? null) === 'this_tax_only' && in_array($row['state'] ?? null, ['AL', 'FL'], true);
            });
            if ($idRows === 0 || !empty($result['isError']) || !is_array($answer) || ($answer['created'] ?? false) !== true
                || count($answer['id_tax_rule'] ?? []) !== 2 || count($rowsRead) !== 2 || count($folded) !== 2) {
                $failures[] = 'somcp_add_country_rule (two states of a rule just created): expected two rows, the range in order - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_add_country_rule (two states of a rule just created)');
            }

            $result = $taxRuleCall($addCountryRule, $rowsAsked);
            $text = $taxRuleText($result);
            if (empty($result['isError']) || strpos($text, 'Nothing was created.') === false
                || strpos($text, 'states: US AL already has a This tax only row in this rule') === false) {
                $failures[] = 'somcp_add_country_rule (the same rows again): expected a refusal - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'error', 'somcp_add_country_rule (the same rows again, refused)');
            }

            // The AL row changed in place (#306): a field, the same call again,
            // the range removed, a country its state does not belong to, and
            // a behavior, the FL row of another state left as it was.
            $updateCountryRule = $registry->get('somcp_update_country_rule');
            $idAl = is_array($answer) ? (int) ($answer['id_tax_rule'][0] ?? 0) : 0;
            if ($updateCountryRule !== null && $idAl > 0) {
                $calls += 5;
                $alRow = static function ($answer) use ($idAl): ?array {
                    foreach (is_array($answer) ? ($answer['tax_rule']['country_rules'] ?? []) : [] as $row) {
                        if (is_array($row) && (int) ($row['id_tax_rule'] ?? 0) === $idAl) {
                            return $row;
                        }
                    }

                    return null;
                };

                $result = $taxRuleCall($updateCountryRule, ['id_tax_rule' => $idAl, 'description' => 'somcp2']);
                $text = $taxRuleText($result);
                $changed = json_decode(trim($text), true);
                if (!empty($result['isError']) || !is_array($changed) || ($changed['changed'] ?? null) !== true
                    || strpos($text, '"field": "description"') === false || ($alRow($changed)['description'] ?? null) !== 'somcp2') {
                    $failures[] = 'somcp_update_country_rule (the description of a row): expected it changed - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_country_rule (the description of a row)');
                }

                $result = $taxRuleCall($updateCountryRule, ['id_tax_rule' => $idAl, 'description' => 'somcp2']);
                $changed = json_decode(trim($taxRuleText($result)), true);
                if (!empty($result['isError']) || !is_array($changed) || ($changed['changed'] ?? null) !== false) {
                    $failures[] = 'somcp_update_country_rule (the same call again): expected nothing changed - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_country_rule (the same call again, nothing changed)');
                }

                $result = $taxRuleCall($updateCountryRule, ['id_tax_rule' => $idAl, 'zipcode_from' => null]);
                $changed = json_decode(trim($taxRuleText($result)), true);
                $row = $alRow($changed);
                if (!empty($result['isError']) || !is_array($changed) || ($changed['changed'] ?? null) !== true
                    || $row === null || $row['zipcode_from'] !== null || $row['zipcode_to'] !== null) {
                    $failures[] = 'somcp_update_country_rule (the range removed): expected no zip code left - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_country_rule (the range removed)');
                }

                $result = $taxRuleCall($updateCountryRule, ['id_tax_rule' => $idAl, 'country' => 'FR']);
                $text = $taxRuleText($result);
                if (empty($result['isError']) || strpos($text, 'Nothing was changed.') === false
                    || strpos($text, '- state: AL is not a state of FR') === false) {
                    $failures[] = 'somcp_update_country_rule (a country without the row\'s state): expected a refusal - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'error', 'somcp_update_country_rule (a country without the row\'s state, refused)');
                }

                $result = $taxRuleCall($updateCountryRule, ['id_tax_rule' => $idAl, 'behavior' => 'combine']);
                $changed = json_decode(trim($taxRuleText($result)), true);
                if (!empty($result['isError']) || !is_array($changed) || ($changed['changed'] ?? null) !== true
                    || ($alRow($changed)['behavior'] ?? null) !== 'combine') {
                    $failures[] = 'somcp_update_country_rule (the behavior of a row): expected it changed - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_country_rule (the behavior of a row)');
                }
            }

            // The two rows deleted one by one (#304), the rule left with none,
            // then the same row again refused rather than read as a success.
            $deleteCountryRule = $registry->get('somcp_delete_country_rule');
            $rowIds = is_array($answer) ? array_map('intval', $answer['id_tax_rule'] ?? []) : [];
            if ($deleteCountryRule !== null && count($rowIds) === 2) {
                $calls += 3;
                $firstDeleted = null;
                foreach ($rowIds as $step => $idRowDeleted) {
                    $result = $taxRuleCall($deleteCountryRule, ['id_tax_rule' => $idRowDeleted]);
                    $answerDeleted = json_decode(trim($taxRuleText($result)), true);
                    if ($step === 0 && is_array($answerDeleted) && is_array($answerDeleted['country_rule'] ?? null)) {
                        $firstDeleted = $answerDeleted['country_rule'];
                    }
                    $rulesLeft = is_array($answerDeleted) ? ($answerDeleted['tax_rule']['country_rules'] ?? null) : null;
                    if (!empty($result['isError']) || !is_array($answerDeleted) || ($answerDeleted['deleted'] ?? false) !== true
                        || (int) ($answerDeleted['country_rule']['id_tax_rule'] ?? 0) !== $idRowDeleted
                        || !is_array($rulesLeft) || count($rulesLeft) !== 1 - $step) {
                        $failures[] = 'somcp_delete_country_rule (row ' . ($step + 1) . ' of a rule just created): expected it deleted - ' . excerpt($result);
                    } else {
                        printf("  %-6s %s\n", 'ok', 'somcp_delete_country_rule (row ' . ($step + 1) . ' of a rule just created)');
                    }
                }
                $result = $taxRuleCall($deleteCountryRule, ['id_tax_rule' => $rowIds[0]]);
                $text = $taxRuleText($result);
                if (empty($result['isError']) || strpos($text, 'Nothing was deleted.') === false
                    || strpos($text, 'has the identifier ' . $rowIds[0]) === false) {
                    $failures[] = 'somcp_delete_country_rule (the same row again): expected a refusal - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'error', 'somcp_delete_country_rule (the same row again, refused)');
                }

                // The row a deletion gives back, added again as it was (#307):
                // what a read returns is what the write takes, state included.
                if (is_array($firstDeleted)) {
                    ++$calls;
                    $again = array_diff_key($firstDeleted, array_flip(['id_tax_rule', 'country_name', 'state_name', 'tax_name', 'rate']));
                    $result = $taxRuleCall($addCountryRule, ['id_tax_rules_group' => $idRows] + $again);
                    $answerAgain = json_decode(trim($taxRuleText($result)), true);
                    $rowAgain = is_array($answerAgain) ? ($answerAgain['tax_rule']['country_rules'][0] ?? null) : null;
                    if (!empty($result['isError']) || !is_array($answerAgain) || ($answerAgain['created'] ?? false) !== true
                        || count($answerAgain['id_tax_rule'] ?? []) !== 1 || !is_array($rowAgain)
                        || ($rowAgain['state'] ?? null) !== 'AL' || ($rowAgain['state'] ?? null) !== ($firstDeleted['state'] ?? '')
                        || ($rowAgain['zipcode_from'] ?? '') !== ($firstDeleted['zipcode_from'] ?? '')
                        || ($rowAgain['zipcode_to'] ?? '') !== ($firstDeleted['zipcode_to'] ?? '')
                        || ($rowAgain['description'] ?? '') !== ($firstDeleted['description'] ?? '')) {
                        $failures[] = 'somcp_add_country_rule (a deleted row given back): expected it added as it was - ' . excerpt($result);
                    } else {
                        printf("  %-6s %s\n", 'ok', 'somcp_add_country_rule (a deleted row given back)');
                    }
                }
            }
        } catch (Throwable $e) {
            $failures[] = 'somcp_add_country_rule (two states of a rule just created): ' . get_class($e) . ' - ' . $e->getMessage();
        } finally {
            if ($idRows > 0) {
                $rule = new TaxRulesGroup($idRows);
                if (Validate::isLoadedObject($rule)) {
                    $rule->delete();
                }
            }
        }
    }

    // Every country at once (#318): the All of Country, on a rule just
    // created, then the same call again and a state and a range with All.
    if ($addCountryRule !== null) {
        $calls += 3;
        $idAll = 0;
        try {
            $result = $taxRuleCall($createTaxRule, ['name' => 'Somcp every country', 'active' => true]);
            $answer = json_decode(trim($taxRuleText($result)), true);
            $idAll = is_array($answer) ? (int) ($answer['tax_rule']['id_tax_rules_group'] ?? 0) : 0;
            $shopCountries = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'country_shop` WHERE `id_shop` = ' . (int) $context->shop->id
            );
            $allAsked = ['id_tax_rules_group' => $idAll, 'country' => null, 'id_tax' => 1];
            $result = $taxRuleCall($addCountryRule, $allAsked);
            $answer = json_decode(trim($taxRuleText($result)), true);
            if ($idAll === 0 || $shopCountries === 0 || !empty($result['isError']) || !is_array($answer)
                || ($answer['created'] ?? false) !== true || ($answer['country_count'] ?? null) !== $shopCountries
                || count($answer['id_tax_rule'] ?? []) !== $shopCountries) {
                $failures[] = 'somcp_add_country_rule (every country, All): expected one row per country of the shop - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_add_country_rule (every country, All)');
            }

            $result = $taxRuleCall($addCountryRule, $allAsked + ['behavior' => 'this_tax_only']);
            $text = $taxRuleText($result);
            if (empty($result['isError']) || strpos($text, 'Nothing was created.') === false
                || strpos($text, 'countries already have a row in this rule that All would contradict:') === false
                || substr_count($text, 'All would contradict') !== 1) {
                $failures[] = 'somcp_add_country_rule (All again): expected one refusal line naming the countries - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'error', 'somcp_add_country_rule (All again, refused)');
            }

            $before = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rule` WHERE `id_tax_rules_group` = ' . $idAll
            );
            $result = $taxRuleCall($addCountryRule, $allAsked + ['states' => ['AL'], 'zipcode_from' => '35000']);
            $text = $taxRuleText($result);
            $after = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rule` WHERE `id_tax_rules_group` = ' . $idAll
            );
            if (empty($result['isError']) || $after !== $before
                || strpos($text, 'states: only with one country, not with All') === false
                || strpos($text, 'zipcode_from: only with one country, not with All') === false) {
                $failures[] = 'somcp_add_country_rule (All with a state and a range): expected both refused, no row written - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'error', 'somcp_add_country_rule (All with a state and a range, refused)');
            }
        } catch (Throwable $e) {
            $failures[] = 'somcp_add_country_rule (every country, All): ' . get_class($e) . ' - ' . $e->getMessage();
        } finally {
            if ($idAll > 0) {
                $rule = new TaxRulesGroup($idAll);
                if (Validate::isLoadedObject($rule)) {
                    $rule->delete();
                }
            }
        }
    }

    // A rule an order line carries (#295): changed, it must stay as it was
    // for that order and the change go on a copy. No scenario can give an
    // order line a rule of its own, so order line 1 is pointed at a rule
    // created here and given back the rule it had, whatever happens.
    $calls += 1;
    $orderLine = (int) Db::getInstance()->getValue(
        'SELECT MIN(`id_order_detail`) FROM `' . _DB_PREFIX_ . 'order_detail`'
    );
    $lineRule = (int) Db::getInstance()->getValue(
        'SELECT `id_tax_rules_group` FROM `' . _DB_PREFIX_ . 'order_detail` WHERE `id_order_detail` = ' . $orderLine
    );
    try {
        $result = $taxRuleCall($createTaxRule, ['name' => 'Somcp used by an order', 'active' => true]);
        $answer = json_decode(trim($taxRuleText($result)), true);
        $idUsed = is_array($answer) ? (int) ($answer['tax_rule']['id_tax_rules_group'] ?? 0) : 0;
        if ($orderLine === 0 || $idUsed === 0) {
            $failures[] = 'somcp_update_tax_rule (a rule used by an order): no order line, or the rule could not be created - ' . excerpt($result);
        } else {
            Db::getInstance()->update('order_detail', ['id_tax_rules_group' => $idUsed], '`id_order_detail` = ' . $orderLine);
            $result = $taxRuleCall($updateTaxRule, ['id_tax_rules_group' => $idUsed, 'name' => 'Somcp used by an order renamed']);
            $answer = json_decode(trim($taxRuleText($result)), true);
            $idCopy = is_array($answer) ? (int) ($answer['id_tax_rules_group'] ?? 0) : 0;
            $old = Db::getInstance()->getRow(
                'SELECT `deleted`, `name` FROM `' . _DB_PREFIX_ . 'tax_rules_group` WHERE `id_tax_rules_group` = ' . $idUsed
            );
            $copy = Db::getInstance()->getRow(
                'SELECT `deleted`, `name` FROM `' . _DB_PREFIX_ . 'tax_rules_group` WHERE `id_tax_rules_group` = ' . $idCopy
            );
            $rows = static function (int $id): int {
                return (int) Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rule` WHERE `id_tax_rules_group` = ' . $id
                );
            };
            $inShop = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rules_group_shop` WHERE `id_tax_rules_group` = ' . $idCopy
                . ' AND `id_shop` = ' . (int) $context->shop->id
            );
            $kept = (int) Db::getInstance()->getValue(
                'SELECT `id_tax_rules_group` FROM `' . _DB_PREFIX_ . 'order_detail` WHERE `id_order_detail` = ' . $orderLine
            );
            if (!empty($result['isError']) || !is_array($answer) || $idCopy === 0 || $idCopy === $idUsed
                || (int) ($answer['replaced_id_tax_rules_group'] ?? 0) !== $idUsed
                || strpos((string) ($answer['warning'] ?? ''), 'a new identifier, ' . $idCopy . '.') === false
                || !is_array($old) || (int) $old['deleted'] !== 1 || $old['name'] !== 'Somcp used by an order'
                || !is_array($copy) || (int) $copy['deleted'] !== 0 || $copy['name'] !== 'Somcp used by an order renamed'
                || $rows($idCopy) !== $rows($idUsed) || $inShop !== 1 || $kept !== $idUsed) {
                $failures[] = 'somcp_update_tax_rule (a rule used by an order): expected a copy under a new identifier, the old rule deleted and kept by the order - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_update_tax_rule (a rule used by an order, changed on a copy)');
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_tax_rule (a rule used by an order): ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        if ($orderLine > 0) {
            Db::getInstance()->update('order_detail', ['id_tax_rules_group' => $lineRule], '`id_order_detail` = ' . $orderLine);
        }
    }
}

// A tax created, changed, then deleted (#308, #309, #310): the JSON scenario
// cannot hold the identifier of a tax it created, and every demo tax is charged
// by a tax rule row, so none can be the one made, changed or removed here. The
// tax below is created, its rate and switch changed, charged by a rule created
// for it (so disabling it tells the two versions apart), then that row removed
// and the tax really deleted; whatever is left is cleaned up at the end.
$createTax = $registry->get('somcp_create_tax');
if ($createTax !== null) {
    $taxCall = static function (SomcpTool $tool, array $arguments) use ($context, $employee): array {
        return $tool->call($arguments, [
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
    };
    $taxText = static function (array $result): string {
        $text = '';
        foreach ($result['content'] ?? [] as $block) {
            $text .= (string) ($block['text'] ?? '') . "\n";
        }

        return $text;
    };

    $calls += 1;
    $idTax = 0;
    try {
        $result = $taxCall($createTax, ['languages' => ['en'], 'name' => ['en' => 'Somcp test tax'], 'rate' => 1.234]);
        $answer = json_decode(trim($taxText($result)), true);
        $idTax = is_array($answer) ? (int) ($answer['id_tax'] ?? 0) : 0;
        $text = $taxText($result);
        $missing = array_filter(
            ['"created": true', '"rate": 1.234', '"active": false', 'enable it with somcp_update_tax'],
            static function (string $needle) use ($text): bool {
                return strpos($text, $needle) === false;
            }
        );
        // The old key of the other shops (#314) must not come back beside also_in_other_shops.
        if (!empty($result['isError']) || $idTax === 0 || $missing !== [] || strpos($text, '"in_every_shop"') !== false) {
            $failures[] = 'somcp_create_tax (a disabled tax): expected it created with its rate and the warning - ' . excerpt($result);
        } else {
            printf("  %-6s %s\n", 'ok', 'somcp_create_tax (a disabled tax)');
        }

        // The tax changed in place (#309): a field, the same call again, enabled.
        $updateTax = $registry->get('somcp_update_tax');
        if ($updateTax !== null && $idTax > 0) {
            $calls += 3;
            $taxUpdates = [
                'a new rate' => [['rate' => 2.5], ['"changed": true', '"field": "rate"', '"new": 2.5']],
                'the same rate again, nothing written' => [['rate' => 2.5], ['"changed": false', 'Nothing was written.']],
                'enabled' => [['active' => true], ['"changed": true', '"field": "active"', '"active": true']],
            ];
            foreach ($taxUpdates as $why => [$arguments, $needles]) {
                $result = $taxCall($updateTax, ['id_tax' => $idTax] + $arguments);
                $text = $taxText($result);
                $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                    return strpos($text, $needle) === false;
                });
                if (!empty($result['isError']) || $missing !== [] || strpos($text, '"in_every_shop"') !== false) {
                    $failures[] = 'somcp_update_tax (' . $why . '): expected ok with ' . implode(', ', $missing) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_tax (' . $why . ')');
                }
            }

            // The row of somcp_list_taxes given back whole changes nothing,
            // its name being one text even on a shop with several languages (#309).
            $listTaxes = $registry->get('somcp_list_taxes');
            if ($listTaxes !== null) {
                $calls += 2;
                $listed = json_decode(trim($taxText($taxCall($listTaxes, ['id_tax' => $idTax]))), true);
                $row = is_array($listed) ? ($listed['taxes'][0] ?? null) : null;
                if (!is_array($row) || (int) ($row['id_tax'] ?? 0) !== $idTax) {
                    $failures[] = 'somcp_update_tax (the row of somcp_list_taxes given back): the row could not be read';
                } else {
                    $result = $taxCall($updateTax, $row);
                    $text = $taxText($result);
                    if (!empty($result['isError']) || strpos($text, '"changed": false') === false) {
                        $failures[] = 'somcp_update_tax (the row of somcp_list_taxes given back): expected no change - ' . excerpt($result);
                    } else {
                        printf("  %-6s %s\n", 'ok', 'somcp_update_tax (the row of somcp_list_taxes given back, nothing written)');
                    }
                }
            }

            // Disabling a tax a tax rule row charges: refused on PrestaShop 8,
            // where the core would delete the row; allowed on 9, where it stays.
            $ruleForTax = $registry->get('somcp_create_tax_rule');
            $rowForTax = $registry->get('somcp_add_country_rule');
            if ($ruleForTax !== null && $rowForTax !== null) {
                $idRuleForTax = 0;
                try {
                    $result = $taxCall($ruleForTax, ['name' => 'Somcp rule charging a test tax', 'active' => true]);
                    $answer = json_decode(trim($taxText($result)), true);
                    $idRuleForTax = is_array($answer) ? (int) ($answer['tax_rule']['id_tax_rules_group'] ?? 0) : 0;
                    $result = $idRuleForTax > 0 ? $taxCall($rowForTax, [
                        'id_tax_rules_group' => $idRuleForTax, 'country' => 'US', 'states' => ['AL'],
                        'id_tax' => $idTax, 'behavior' => 'this_tax_only',
                    ]) : $result;
                    $added = json_decode(trim($taxText($result)), true);
                    $charged = (int) Db::getInstance()->getValue(
                        'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rule` WHERE `id_tax` = ' . (int) $idTax
                    );
                    if ($idRuleForTax === 0 || !is_array($added) || ($added['created'] ?? false) !== true || $charged < 1) {
                        $failures[] = 'somcp_update_tax (disabling a charged tax): the charging row could not be set up - ' . excerpt($result);
                    } else {
                        $calls += 1;
                        $result = $taxCall($updateTax, ['id_tax' => $idTax, 'active' => false]);
                        $text = $taxText($result);
                        $left = (int) Db::getInstance()->getValue(
                            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rule` WHERE `id_tax` = ' . (int) $idTax
                        );
                        if (version_compare(_PS_VERSION_, '9.0.0', '<')) {
                            if (empty($result['isError']) || strpos($text, 'Nothing was changed.') === false
                                || strpos($text, '- active: on PrestaShop 8, disabling this tax deletes its 1 row(s)') === false
                                || $left !== $charged) {
                                $failures[] = 'somcp_update_tax (disabling a charged tax on PrestaShop 8): expected a refusal, the row kept - ' . excerpt($result);
                            } else {
                                printf("  %-6s %s\n", 'error', 'somcp_update_tax (disabling a charged tax, refused on PrestaShop 8)');
                            }
                        } else {
                            if (!empty($result['isError']) || strpos($text, '"changed": true') === false || $left !== $charged) {
                                $failures[] = 'somcp_update_tax (disabling a charged tax on PrestaShop 9): expected it changed, the row kept - ' . excerpt($result);
                            } else {
                                printf("  %-6s %s\n", 'ok', 'somcp_update_tax (disabling a charged tax, allowed on PrestaShop 9)');
                            }
                        }

                        // The tax deleted (#310): refused while the row charges
                        // it, then the row removed and the tax really deleted.
                        $deleteTax = $registry->get('somcp_delete_tax');
                        $deleteRowForTax = $registry->get('somcp_delete_country_rule');
                        $idRowForTax = (int) ($added['id_tax_rule'][0] ?? 0);
                        if ($deleteTax !== null && $deleteRowForTax !== null && $idRowForTax > 0) {
                            $calls += 3;
                            $result = $taxCall($deleteTax, ['id_tax' => $idTax]);
                            $text = $taxText($result);
                            if (empty($result['isError']) || strpos($text, 'Nothing was deleted.') === false
                                || strpos($text, '- id_tax: charged by 1 row(s)') === false) {
                                $failures[] = 'somcp_delete_tax (a tax a rule charges): expected a refusal - ' . excerpt($result);
                            } else {
                                printf("  %-6s %s\n", 'error', 'somcp_delete_tax (a tax a rule charges, refused)');
                            }

                            $taxCall($deleteRowForTax, ['id_tax_rule' => $idRowForTax]);

                            $result = $taxCall($deleteTax, ['id_tax' => $idTax]);
                            $text = $taxText($result);
                            if (!empty($result['isError']) || strpos($text, '"deleted": true') === false
                                || strpos($text, '"kept_for_orders": false') === false
                                || strpos($text, '"in_every_shop"') !== false) {
                                $failures[] = 'somcp_delete_tax (a tax nothing charges): expected it deleted - ' . excerpt($result);
                            } else {
                                printf("  %-6s %s\n", 'ok', 'somcp_delete_tax (a tax nothing charges)');
                            }

                            $gone = (int) Db::getInstance()->getValue(
                                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax` WHERE `id_tax` = ' . (int) $idTax . ' AND `deleted` = 0'
                            );
                            if ($gone !== 0) {
                                $failures[] = 'somcp_delete_tax (the tax after deletion): expected it gone from the list, still there';
                            } else {
                                printf("  %-6s %s\n", 'ok', 'somcp_delete_tax (the tax gone from the list)');
                                $idTax = 0;
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $failures[] = 'somcp_update_tax (disabling a charged tax): ' . get_class($e) . ' - ' . $e->getMessage();
                } finally {
                    // The rule and its row go, so the tax is charged by nothing
                    // and the deletion below removes it.
                    if ($idRuleForTax > 0) {
                        $rule = new TaxRulesGroup($idRuleForTax);
                        if (Validate::isLoadedObject($rule)) {
                            $rule->delete();
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_create_tax (a disabled tax): ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        // No rule charges it and no order carries it, so it is really removed.
        if ($idTax > 0) {
            $tax = new Tax($idTax);
            if (Validate::isLoadedObject($tax)) {
                $tax->delete();
            }
        }
    }
}

// A zone created, changed and deleted (#348, #349, #350): the JSON scenario
// cannot hold the identifier of a zone it created, and every demo zone holds
// countries, so none can be the one made, changed or removed here. Two zones
// are created, the second under the first one's name in other letters, the
// first renamed and disabled, both deleted, then a third deleted while a
// carrier serves it; whatever is left is cleaned up at the end.
$createZone = $registry->get('somcp_create_zone');
if ($createZone !== null) {
    $zoneCall = static function (SomcpTool $tool, array $arguments) use ($context, $employee): array {
        return $tool->call($arguments, [
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
    };
    $zoneText = static function (array $result): string {
        $text = '';
        foreach ($result['content'] ?? [] as $block) {
            $text .= (string) ($block['text'] ?? '') . "\n";
        }

        return $text;
    };
    $zoneMissing = static function (string $text, array $needles): array {
        return array_values(array_filter($needles, static function (string $needle) use ($text): bool {
            return strpos($text, $needle) === false;
        }));
    };

    $calls += 2;
    $zoneIds = [];
    try {
        $result = $zoneCall($createZone, ['name' => 'somcp zone']);
        $text = $zoneText($result);
        $answer = json_decode(trim($text), true);
        $idZone = is_array($answer) ? (int) ($answer['id_zone'] ?? 0) : 0;
        if ($idZone > 0) {
            $zoneIds[] = $idZone;
        }
        $missing = $zoneMissing($text, ['"created": true', '"active": true', '"countries": 0', 'The zone has no country yet']);
        if (!empty($result['isError']) || $idZone === 0 || $missing !== []) {
            $failures[] = 'somcp_create_zone (a new zone): expected it created, enabled, empty, with the warning - ' . excerpt($result);
        } else {
            printf("  %-6s %s\n", 'ok', 'somcp_create_zone (a new zone)');
        }

        // The same name in other letters is taken, as the form takes it, and
        // the answer names the zone that already has it.
        $result = $zoneCall($createZone, ['name' => 'SOMCP ZONE']);
        $text = $zoneText($result);
        $answer = json_decode(trim($text), true);
        $idTwin = is_array($answer) ? (int) ($answer['id_zone'] ?? 0) : 0;
        if ($idTwin > 0) {
            $zoneIds[] = $idTwin;
        }
        if (!empty($result['isError']) || $idTwin === 0
            || strpos($text, 'Zone ' . $idZone . ' already has this name') === false) {
            $failures[] = 'somcp_create_zone (a name another zone has): expected it created with the second warning - ' . excerpt($result);
        } else {
            printf("  %-6s %s\n", 'ok', 'somcp_create_zone (a name another zone has, warned)');
        }

        // The zone changed in place (#349): renamed, disabled, then its row
        // of somcp_list_zones given back whole, which changes nothing.
        $updateZone = $registry->get('somcp_update_zone');
        if ($updateZone !== null && $idZone > 0) {
            $calls += 2;
            $zoneUpdates = [
                'renamed' => [['name' => 'somcp zone 2'], [['field' => 'name', 'old' => 'somcp zone', 'new' => 'somcp zone 2']]],
                'disabled' => [['active' => false], [['field' => 'active', 'old' => true, 'new' => false]]],
            ];
            foreach ($zoneUpdates as $why => [$arguments, $changes]) {
                $result = $zoneCall($updateZone, ['id_zone' => $idZone] + $arguments);
                $text = $zoneText($result);
                $answer = json_decode(trim($text), true);
                // A zone that serves no carrier: disabling it warns of none.
                // The shape of every update tool (#356): changed a boolean,
                // changes with the value before.
                if (!empty($result['isError']) || !is_array($answer) || ($answer['changed'] ?? null) !== true
                    || ($answer['changes'] ?? null) !== $changes || strpos($text, 'Shipping is now disallowed') !== false) {
                    $failures[] = 'somcp_update_zone (' . $why . '): expected changes ' . implode(', ', array_column($changes, 'field')) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_zone (' . $why . ')');
                }
            }

            $listZones = $registry->get('somcp_list_zones');
            if ($listZones !== null) {
                $calls += 2;
                $listed = json_decode(trim($zoneText($zoneCall($listZones, ['id_zone' => $idZone]))), true);
                $row = is_array($listed) ? ($listed['zones'][0] ?? null) : null;
                if (!is_array($row) || (int) ($row['id_zone'] ?? 0) !== $idZone || ($row['name'] ?? '') !== 'somcp zone 2'
                    || ($row['active'] ?? null) !== false) {
                    $failures[] = 'somcp_update_zone (the row of somcp_list_zones given back): the changed row could not be read';
                } else {
                    $result = $zoneCall($updateZone, $row);
                    $answer = json_decode(trim($zoneText($result)), true);
                    if (!empty($result['isError']) || !is_array($answer) || ($answer['changed'] ?? null) !== false
                        || ($answer['reason'] ?? null) !== 'The zone already holds every value given. Nothing was written.'
                        || array_key_exists('changes', $answer)) {
                        $failures[] = 'somcp_update_zone (the row of somcp_list_zones given back): expected no change - ' . excerpt($result);
                    } else {
                        printf("  %-6s %s\n", 'ok', 'somcp_update_zone (the row of somcp_list_zones given back, nothing written)');
                    }
                }
            }
        }

        // The zones deleted (#350): the two empty ones, then a third given to
        // demo carrier 3, which no order uses, so it keeps its identifier.
        // The zone is put on the carrier the way the carrier page does it,
        // Carrier::addZone(), rather than through somcp_update_carrier, whose
        // zones replace the whole list of the carrier.
        $deleteZone = $registry->get('somcp_delete_zone');
        if ($deleteZone !== null) {
            foreach ($zoneIds as $idEmpty) {
                $calls += 1;
                $result = $zoneCall($deleteZone, ['id_zone' => $idEmpty]);
                $answer = json_decode(trim($zoneText($result)), true);
                if (!empty($result['isError']) || !is_array($answer) || ($answer['deleted'] ?? null) !== true
                    || ($answer['carriers_detached'] ?? null) !== []) {
                    $failures[] = 'somcp_delete_zone (an empty zone): expected it deleted, no carrier detached - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_delete_zone (an empty zone)');
                    $zoneIds = array_values(array_diff($zoneIds, [$idEmpty]));
                }
            }

            $calls += 2;
            $result = $zoneCall($createZone, ['name' => 'somcp zone served']);
            $answer = json_decode(trim($zoneText($result)), true);
            $idServed = is_array($answer) ? (int) ($answer['id_zone'] ?? 0) : 0;
            $carrier = new Carrier(3);
            if ($idServed === 0 || !Validate::isLoadedObject($carrier) || !$carrier->addZone($idServed)) {
                $failures[] = 'somcp_delete_zone (a zone a carrier serves): the zone could not be set up - ' . excerpt($result);
                if ($idServed > 0) {
                    $zoneIds[] = $idServed;
                }
            } else {
                $result = $zoneCall($deleteZone, ['id_zone' => $idServed]);
                $text = $zoneText($result);
                $answer = json_decode(trim($text), true);
                $left = (int) Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'carrier_zone` WHERE `id_zone` = ' . (int) $idServed
                );
                if (!empty($result['isError']) || !is_array($answer) || ($answer['deleted'] ?? null) !== true
                    || ($answer['carriers_detached'] ?? null) !== [3] || strpos($text, 'no longer serve this zone') === false
                    || $left !== 0) {
                    $failures[] = 'somcp_delete_zone (a zone a carrier serves): expected it deleted, carrier 3 detached - ' . excerpt($result);
                    $zoneIds[] = $idServed;
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_delete_zone (a zone a carrier serves, carrier detached)');
                }
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_create_zone (a new zone): ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        // What a failed check left behind: no country or state uses these
        // zones, and Zone::delete() takes their carrier rows with them.
        foreach ($zoneIds as $idZoneLeft) {
            $zone = new Zone($idZoneLeft);
            if (Validate::isLoadedObject($zone)) {
                $zone->delete();
            }
        }
    }
}

// A state created (#353): the JSON scenario cannot delete the state it made,
// and a second run would then be refused by the ISO code test. ZZ is a code
// no state of Italy has, on a fresh install as on the test shops; the state
// is deleted at the end, no address uses it.
$createState = $registry->get('somcp_create_state');
if ($createState !== null) {
    $calls += 1;
    $idState = 0;
    try {
        $result = $createState->call(
            ['country' => 'IT', 'name' => 'somcp state', 'iso_code' => 'zz', 'id_zone' => 1],
            [
                'id_shop' => (int) $context->shop->id,
                'id_employee' => (int) $employee->id,
                'id_lang' => (int) $context->language->id,
                'id_client' => 0,
                'scopes' => implode(' ', $createState->getRequiredScopes()),
                'responses' => [],
                'resumed' => [],
                'capabilities' => [],
                'arguments' => [],
            ]
        );
        $text = '';
        foreach ($result['content'] ?? [] as $block) {
            $text .= (string) ($block['text'] ?? '') . "\n";
        }
        $answer = json_decode(trim($text), true);
        $idState = is_array($answer) ? (int) ($answer['id_state'] ?? 0) : 0;
        $missing = array_values(array_filter(
            ['"created": true', '"iso_code": "ZZ"', '"country": "IT"', '"zone": "Europe"'],
            static function (string $needle) use ($text): bool {
                return strpos($text, $needle) === false;
            }
        ));
        if (!empty($result['isError']) || $idState === 0 || $missing !== []) {
            $failures[] = 'somcp_create_state (a new state): expected ' . implode(', ', $missing ?: ['an id_state']) . ' - ' . excerpt($result);
        } else {
            printf("  %-6s %s\n", 'ok', 'somcp_create_state (a new state)');
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_create_state (a new state): ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        if ($idState > 0) {
            $state = new State($idState);
            if (Validate::isLoadedObject($state)) {
                $state->delete();
            }
        }
    }
}

// A state changed, then the same change again (#357): the JSON scenario
// cannot create the state it changes, and demo states are used by addresses
// and tax rule rows the next tools read. The state is made as the check of
// somcp_create_state makes it, then deleted.
$updateState = $registry->get('somcp_update_state');
if ($updateState !== null) {
    $calls += 2;
    $state = new State();
    $state->id_country = (int) Country::getByIso('IT');
    $state->id_zone = 1;
    $state->name = 'somcp state';
    $state->iso_code = 'ZZ';
    $state->active = true;
    $idState = $state->add() ? (int) $state->id : 0;
    $stateContext = [
        'id_shop' => (int) $context->shop->id,
        'id_employee' => (int) $employee->id,
        'id_lang' => (int) $context->language->id,
        'id_client' => 0,
        'scopes' => implode(' ', $updateState->getRequiredScopes()),
        'responses' => [],
        'resumed' => [],
        'capabilities' => [],
        'arguments' => [],
    ];
    $change = ['id_state' => $idState, 'name' => 'somcp state 2', 'active' => false];
    try {
        foreach ([
            'a change' => ['"changed": true', '"field": "name"', '"old": "somcp state"', '"new": "somcp state 2"', '"field": "active"'],
            'the same change again' => ['"changed": false', 'The state already holds every value given.'],
        ] as $label => $needles) {
            $result = $updateState->call($change, $stateContext);
            $text = '';
            foreach ($result['content'] ?? [] as $block) {
                $text .= (string) ($block['text'] ?? '') . "\n";
            }
            $missing = array_values(array_filter($needles, static function (string $needle) use ($text): bool {
                return strpos($text, $needle) === false;
            }));
            // Name before active, the order of the form.
            if ($label === 'a change' && strpos($text, '"field": "name"') > strpos($text, '"field": "active"')) {
                $missing[] = 'name before active in changes';
            }
            if ($idState === 0 || !empty($result['isError']) || $missing !== []) {
                $failures[] = 'somcp_update_state (' . $label . '): expected ' . implode(', ', $missing ?: ['a state to change']) . ' - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_update_state (' . $label . ')');
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_state: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        if ($idState > 0) {
            $left = new State($idState);
            if (Validate::isLoadedObject($left)) {
                $left->delete();
            }
        }
    }
}

// The default currency read in full (#363): its identifier depends on the
// country the CI shop is installed for, so it is taken from
// somcp_list_currencies rather than written in the JSON scenario.
$listCurrencies = $registry->get('somcp_list_currencies');
$getCurrency = $registry->get('somcp_get_currency');
if ($listCurrencies !== null && $getCurrency !== null) {
    ++$calls;
    $currencyCall = static function (SomcpTool $tool, array $arguments) use ($context, $employee): array {
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
        $text = '';
        foreach ($result['content'] ?? [] as $block) {
            $text .= (string) ($block['text'] ?? '') . "\n";
        }
        $answer = json_decode(trim($text), true);

        return [$result, is_array($answer) && empty($result['isError']) ? $answer : []];
    };
    try {
        $idDefault = 0;
        foreach ($currencyCall($listCurrencies, [])[1]['currencies'] ?? [] as $row) {
            if (is_array($row) && ($row['default'] ?? false) === true) {
                $idDefault = (int) $row['id_currency'];
            }
        }
        [$result, $answer] = $currencyCall($getCurrency, ['id_currency' => $idDefault]);
        if ($idDefault === 0 || ($answer['id_currency'] ?? null) !== $idDefault || ($answer['default'] ?? null) !== true
            || !is_int($answer['precision'] ?? null) || ($answer['alternative'] ?? null) !== false) {
            $failures[] = 'somcp_get_currency (the default currency): expected default true, a precision and alternative false - ' . excerpt($result);
        } else {
            printf("  %-6s %s\n", 'ok', 'somcp_get_currency (the default currency)');
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_get_currency: ' . get_class($e) . ' - ' . $e->getMessage();
    }
}

// The default currency changed (#364): its identifier, its rate and its
// decimals depend on the country the CI shop is installed for, so they are
// read first. Switching it off and giving it a rate are refused; its
// decimals change once, the same call writes nothing, then they are put back.
$updateCurrency = $registry->get('somcp_update_currency');
if ($updateCurrency !== null && isset($currencyCall, $idDefault) && $idDefault > 0) {
    ++$calls;
    $restorePrecision = null;
    try {
        $before = $currencyCall($getCurrency, ['id_currency' => $idDefault])[1];
        [$offResult] = $currencyCall($updateCurrency, ['id_currency' => $idDefault, 'active' => false]);
        [$rateResult] = $currencyCall($updateCurrency, ['id_currency' => $idDefault, 'conversion_rate' => 2]);
        $precision = (int) ($before['precision'] ?? 2);
        [$result, $answer] = $currencyCall($updateCurrency, ['id_currency' => $idDefault, 'precision' => $precision + 1]);
        if (($answer['changed'] ?? null) === true) {
            $restorePrecision = $precision;
        }
        [$againResult, $again] = $currencyCall($updateCurrency, ['id_currency' => $idDefault, 'precision' => $precision + 1]);
        if (empty($offResult['isError']) || strpos(excerpt($offResult), 'and therefore cannot be disabled') === false) {
            $failures[] = 'somcp_update_currency (switch off the default currency): expected a refusal - ' . excerpt($offResult);
        } elseif (empty($rateResult['isError']) || strpos(excerpt($rateResult), 'its rate stays 1') === false) {
            $failures[] = 'somcp_update_currency (a rate for the default currency): expected a refusal - ' . excerpt($rateResult);
        } elseif (($answer['changed'] ?? null) !== true || ($answer['currency']['precision'] ?? null) !== $precision + 1) {
            $failures[] = 'somcp_update_currency (the decimals): expected changed true - ' . excerpt($result);
        } elseif (($again['changed'] ?? null) !== false) {
            $failures[] = 'somcp_update_currency (the same call again): expected changed false - ' . excerpt($againResult);
        } else {
            printf("  %-6s %s\n", 'ok', 'somcp_update_currency (the default currency)');
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_currency: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        if ($restorePrecision !== null) {
            Db::getInstance()->update('currency', ['precision' => (int) $restorePrecision], 'id_currency = ' . (int) $idDefault);
        }
    }
}

// The default language changed (#368): its identifier depends on the country
// the CI shop is installed for, so it is read with somcp_list_languages.
// Switching it off is refused; its full date format changes once, the same
// call writes nothing, the row of the list given back whole writes nothing,
// its flag given back alone writes nothing (#370), a flag at an http address is
// refused by the one path every image takes, then its own format is put back.
$listLanguages = $registry->get('somcp_list_languages');
$updateLanguage = $registry->get('somcp_update_language');
if ($listLanguages !== null && $updateLanguage !== null && isset($currencyCall)) {
    ++$calls;
    $restoreFormat = null;
    $idDefaultLang = 0;
    try {
        $defaultRow = null;
        foreach ($currencyCall($listLanguages, [])[1]['languages'] ?? [] as $row) {
            if (is_array($row) && ($row['default'] ?? false) === true) {
                $defaultRow = $row;
                $idDefaultLang = (int) $row['id_lang'];
            }
        }
        if ($defaultRow === null) {
            $failures[] = 'somcp_update_language: somcp_list_languages gave no default language';
        } else {
            [$offResult] = $currencyCall($updateLanguage, ['id_lang' => $idDefaultLang, 'active' => false]);
            $format = $defaultRow['date_format_full'] === 'Y-m-d H:i' ? 'Y-m-d H:i:s' : 'Y-m-d H:i';
            [$result, $answer] = $currencyCall($updateLanguage, ['id_lang' => $idDefaultLang, 'date_format_full' => $format]);
            if (($answer['changed'] ?? null) === true) {
                $restoreFormat = (string) $defaultRow['date_format_full'];
            }
            [$againResult, $again] = $currencyCall($updateLanguage, ['id_lang' => $idDefaultLang, 'date_format_full' => $format]);
            $whole = $answer['language'] ?? [];
            [$wholeResult, $wholeAnswer] = $currencyCall($updateLanguage, is_array($whole) ? $whole : []);
            [$flagResult, $flagAnswer] = $currencyCall($updateLanguage, ['id_lang' => $idDefaultLang, 'flag' => $defaultRow['flag'] ?? null]);
            [$httpResult] = $currencyCall($updateLanguage, ['id_lang' => $idDefaultLang, 'flag' => ['url' => 'http://example.com/flag.jpg']]);
            if (empty($offResult['isError']) || strpos(excerpt($offResult), 'it cannot be disabled') === false) {
                $failures[] = 'somcp_update_language (switch off the default language): expected a refusal - ' . excerpt($offResult);
            } elseif (($answer['changed'] ?? null) !== true || ($answer['language']['date_format_full'] ?? null) !== $format) {
                $failures[] = 'somcp_update_language (the full date format): expected changed true - ' . excerpt($result);
            } elseif (($again['changed'] ?? null) !== false) {
                $failures[] = 'somcp_update_language (the same call again): expected changed false - ' . excerpt($againResult);
            } elseif (($wholeAnswer['changed'] ?? null) !== false) {
                $failures[] = 'somcp_update_language (the row of the list given back): expected changed false - ' . excerpt($wholeResult);
            } elseif (!array_key_exists('flag', $defaultRow) || ($flagAnswer['changed'] ?? null) !== false) {
                $failures[] = 'somcp_update_language (the flag of the list given back): expected changed false - ' . excerpt($flagResult);
            } elseif (empty($httpResult['isError']) || strpos(excerpt($httpResult), 'flag: only public https addresses are accepted') === false) {
                $failures[] = 'somcp_update_language (a flag at an http address): expected a refusal - ' . excerpt($httpResult);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_update_language (the default language)');
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_language: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        if ($restoreFormat !== null && $restoreFormat !== '') {
            Db::getInstance()->update('lang', ['date_format_full' => pSQL($restoreFormat)], 'id_lang = ' . (int) $idDefaultLang);
        }
    }
}

// The ISO code of a language (#369): the default language given the code of
// another language of the shop is refused. The change itself is not run: it
// moves the real translation and e-mail folders of the CI shop. A shop
// installed with one language has no other code to take, and says so.
if ($listLanguages !== null && $updateLanguage !== null && isset($currencyCall)) {
    ++$calls;
    try {
        $defaultIso = null;
        $otherIso = null;
        $idDefaultLang = 0;
        foreach ($currencyCall($listLanguages, [])[1]['languages'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['default'] ?? false) === true) {
                $idDefaultLang = (int) $row['id_lang'];
                $defaultIso = (string) $row['iso_code'];
            } elseif ($otherIso === null) {
                $otherIso = (string) $row['iso_code'];
            }
        }
        if ($idDefaultLang === 0) {
            $failures[] = 'somcp_update_language (the ISO code of another language): somcp_list_languages gave no default language';
        } elseif ($otherIso === null) {
            printf("  %-6s %s\n", 'skip', 'somcp_update_language (the ISO code of another language): the shop has one language');
        } else {
            [$isoResult] = $currencyCall($updateLanguage, ['id_lang' => $idDefaultLang, 'iso_code' => $otherIso]);
            if (empty($isoResult['isError']) || strpos(excerpt($isoResult), 'iso_code: ' . $otherIso . ' is already the code of') === false) {
                $failures[] = 'somcp_update_language (the ISO code of another language): expected a refusal - ' . excerpt($isoResult);
            } else {
                printf("  %-6s %s\n", 'error', 'somcp_update_language (the ISO code of another language, refused)');
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_language (the ISO code of another language): ' . get_class($e) . ' - ' . $e->getMessage();
    }
}

// The "No-picture" image of a language (#376) is not written on the CI shop:
// it replaces the real pictures the storefront shows for every product,
// category and brand without one. Its refusals are in the scenario.

// A language deleted (#371): the default language is refused; a language
// added for the test, without any _lang row copied, is deleted with the tool
// and gone after. The CI shop's own languages are never deleted; the test
// language is removed in finally if the tool failed.
$deleteLanguage = $registry->get('somcp_delete_language');
if ($deleteLanguage !== null && isset($currencyCall, $idDefaultLang) && $idDefaultLang > 0) {
    ++$calls;
    $testLangIso = 'zz';
    try {
        [$defaultResult] = $currencyCall($deleteLanguage, ['id_lang' => $idDefaultLang]);
        $added = new Language();
        $added->name = 'Somcp test language';
        $added->iso_code = $testLangIso;
        $added->language_code = 'zz-zz';
        $added->locale = 'zz-ZZ';
        $added->date_format_lite = 'Y-m-d';
        $added->date_format_full = 'Y-m-d H:i:s';
        $added->active = true;
        $added->id_shop_list = [(int) $context->shop->id];
        // $only_add: no text of the shop is copied into the test language.
        if (!$added->add(true, false, true)) {
            throw new RuntimeException('the test language could not be created');
        }
        $idTestLang = (int) $added->id;
        [$result, $answer] = $currencyCall($deleteLanguage, ['id_lang' => $idTestLang]);
        if (empty($defaultResult['isError']) || strpos(excerpt($defaultResult), 'PrestaShop never deletes it') === false) {
            $failures[] = 'somcp_delete_language (the default language): expected a refusal - ' . excerpt($defaultResult);
        } elseif (($answer['deleted'] ?? null) !== true || ($answer['iso_code'] ?? null) !== $testLangIso) {
            $failures[] = 'somcp_delete_language (a test language): expected deleted true - ' . excerpt($result);
        } elseif (Language::getIdByIso($testLangIso, true)) {
            $failures[] = 'somcp_delete_language (a test language): the language is still there';
        } else {
            printf("  %-6s %s\n", 'ok', 'somcp_delete_language (a test language)');
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_delete_language: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        $left = (int) Db::getInstance()->getValue(
            "SELECT `id_lang` FROM `" . _DB_PREFIX_ . "lang` WHERE `iso_code` = '" . pSQL($testLangIso) . "'"
        );
        if ($left > 0) {
            Db::getInstance()->delete('lang_shop', 'id_lang = ' . $left);
            Db::getInstance()->delete('lang', 'id_lang = ' . $left);
        }
    }
}

// A language added (#372): the flag and the "No-picture" image deposited as
// the order status icon is, one upload_ref each since a reference is used
// once; the language comes back created with the ISO code given, then is
// deleted with somcp_delete_language (#371). The CI shop's own languages are
// never touched; the test language is removed in finally if a tool failed.
$createLanguage = $registry->get('somcp_create_language');
if ($createLanguage !== null && $deleteLanguage !== null && isset($currencyCall)) {
    $calls += 2;
    $testLangIso = 'zz';
    try {
        // The smallest valid GIF: one transparent pixel.
        $gif = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        $references = [];
        $entered = '';
        foreach (['flag', 'no_picture'] as $field) {
            $reference = SomcpUpload::inviteFor((int) $employee->id, $createLanguage, $field);
            $invitation = $reference === '' ? null : SomcpUpload::find($reference, (int) $employee->id);
            $entered .= $invitation === null ? 'no deposit could be opened' : SomcpUpload::enter($invitation, 'data', $gif, $field . '.gif');
            $references[$field] = $reference;
        }
        if ($entered !== '') {
            $failures[] = 'somcp_create_language (a test language): the images were not deposited - ' . $entered;
        } else {
            [$result, $answer] = $currencyCall($createLanguage, [
                'name' => 'Somcp test language',
                'iso_code' => $testLangIso,
                'language_code' => 'zz-zz',
                'flag' => ['upload_ref' => $references['flag']],
                'no_picture' => ['upload_ref' => $references['no_picture']],
            ]);
            $idTestLang = (int) ($answer['id_lang'] ?? 0);
            if (($answer['created'] ?? null) !== true || ($answer['language']['iso_code'] ?? null) !== $testLangIso
                || empty($answer['flag']['stored']) || empty($answer['no_picture']['stored'])) {
                $failures[] = 'somcp_create_language (a test language): expected created true with both images stored - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_create_language (a test language)');
                [$deleteResult, $deleted] = $currencyCall($deleteLanguage, ['id_lang' => $idTestLang]);
                if (($deleted['deleted'] ?? null) !== true) {
                    $failures[] = 'somcp_delete_language (the language somcp_create_language added): expected deleted true - ' . excerpt($deleteResult);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_delete_language (the language somcp_create_language added)');
                }
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_create_language: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        $left = (int) Language::getIdByIso($testLangIso, true);
        if ($left > 0) {
            $previousContext = Shop::getContext();
            Shop::setContext(Shop::CONTEXT_ALL);
            (new Language($left))->delete();
            Shop::setContext($previousContext, $previousContext === Shop::CONTEXT_ALL ? null : (int) $context->shop->id);
        }
    }
}

// The symbol of the default currency changed, then its default settings
// restored (#365): the demo currencies keep the CLDR texts (modified 0), so
// restoring them puts the shop back as it was installed. The symbol is given
// in one language, which a shop with several languages needs for a text.
if ($updateCurrency !== null && isset($currencyCall, $idDefault) && $idDefault > 0) {
    ++$calls;
    $restored = false;
    try {
        $before = $currencyCall($getCurrency, ['id_currency' => $idDefault])[1];
        $symbolCall = ['id_currency' => $idDefault, 'symbol' => 'X$',
            'languages' => [(string) Language::getIsoById((int) $context->language->id)]];
        [$result, $answer] = $currencyCall($updateCurrency, $symbolCall);
        [$againResult, $again] = $currencyCall($updateCurrency, $symbolCall);
        [$restoreResult, $restore] = $currencyCall($updateCurrency, ['id_currency' => $idDefault, 'restore_defaults' => true]);
        $restored = ($restore['changed'] ?? null) === true;
        if (($answer['changed'] ?? null) !== true || ($answer['currency']['modified'] ?? null) !== true) {
            $failures[] = 'somcp_update_currency (a symbol): expected changed true and modified true - ' . excerpt($result);
        } elseif (($again['changed'] ?? null) !== false) {
            $failures[] = 'somcp_update_currency (the same symbol again): expected changed false - ' . excerpt($againResult);
        } elseif (!$restored || ($restore['currency']['symbol'] ?? null) !== ($before['symbol'] ?? false)
            || ($restore['currency']['modified'] ?? null) !== false) {
            $failures[] = 'somcp_update_currency (restore_defaults): expected changed true and the symbol of before - ' . excerpt($restoreResult);
        } else {
            printf("  %-6s %s\n", 'ok', 'somcp_update_currency (a symbol, then the default settings)');
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_currency (texts): ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        if (!$restored) {
            try {
                $currencyCall($updateCurrency, ['id_currency' => $idDefault, 'restore_defaults' => true]);
            } catch (Throwable $e) {
                // The failure is already recorded above.
            }
        }
    }
}

// A currency deleted (#366): the default currency is refused, a currency
// created for the test is deleted, then the same call is refused as
// unknown. The CI shop's own currencies are never deleted; the test
// currency is removed from every table in finally, the soft-deleted row
// included.
$deleteCurrency = $registry->get('somcp_delete_currency');
if ($deleteCurrency !== null && isset($currencyCall, $idDefault) && $idDefault > 0) {
    ++$calls;
    $testIso = 'ZZZ';
    try {
        [$defaultResult] = $currencyCall($deleteCurrency, ['id_currency' => $idDefault]);
        $created = new Currency();
        $created->iso_code = $testIso;
        $created->conversion_rate = 1;
        $created->precision = 2;
        $created->active = true;
        $names = [];
        $symbols = [];
        foreach (Language::getLanguages(false) as $lang) {
            $names[(int) $lang['id_lang']] = 'Somcp test currency';
            $symbols[(int) $lang['id_lang']] = 'Z';
        }
        $created->setLocalizedNames($names);
        $created->setLocalizedSymbols($symbols);
        $created->id_shop_list = [(int) $context->shop->id];
        if (!$created->add()) {
            throw new RuntimeException('the test currency could not be created');
        }
        $idTest = (int) $created->id;
        [$result, $answer] = $currencyCall($deleteCurrency, ['id_currency' => $idTest]);
        [$againResult] = $currencyCall($deleteCurrency, ['id_currency' => $idTest]);
        if (empty($defaultResult['isError']) || strpos(excerpt($defaultResult), 'PrestaShop never deletes it') === false) {
            $failures[] = 'somcp_delete_currency (the default currency): expected a refusal - ' . excerpt($defaultResult);
        } elseif (($answer['deleted'] ?? null) !== true || ($answer['currency']['iso_code'] ?? null) !== $testIso) {
            $failures[] = 'somcp_delete_currency (a test currency): expected deleted true - ' . excerpt($result);
        } elseif (empty($againResult['isError']) || strpos(excerpt($againResult), 'no currency of this shop has the identifier ' . $idTest) === false) {
            $failures[] = 'somcp_delete_currency (the same call again): expected a refusal as unknown - ' . excerpt($againResult);
        } else {
            printf("  %-6s %s\n", 'ok', 'somcp_delete_currency (a test currency)');
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_delete_currency: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        $ids = array_map('intval', array_column(Db::getInstance()->executeS(
            "SELECT `id_currency` FROM `" . _DB_PREFIX_ . "currency` WHERE `iso_code` = '" . pSQL($testIso) . "'"
        ) ?: [], 'id_currency'));
        if ($ids !== []) {
            foreach (['currency', 'currency_lang', 'currency_shop', 'module_currency'] as $table) {
                Db::getInstance()->delete($table, 'id_currency IN (' . implode(', ', $ids) . ')');
            }
        }
    }
}

// A currency added (#367): an official currency the CI shop does not have,
// given in lower case, is created with the names and symbols CLDR gives;
// the same call again is refused as a currency the shop already has. The
// CI shop is installed for France and its currencies are not known in
// advance, so the test is skipped when it already has CHF; the row is
// removed from every table in finally.
$createCurrency = $registry->get('somcp_create_currency');
$createIso = 'CHF';
if ($createCurrency !== null && isset($currencyCall) && (int) Db::getInstance()->getValue(
    "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "currency` WHERE `iso_code` = '" . pSQL($createIso) . "'"
) === 0) {
    ++$calls;
    try {
        [$result, $answer] = $currencyCall($createCurrency, ['iso_code' => 'chf', 'conversion_rate' => 0.95]);
        [$againResult] = $currencyCall($createCurrency, ['iso_code' => 'chf', 'conversion_rate' => 0.95]);
        if (($answer['created'] ?? null) !== true || ($answer['currency']['iso_code'] ?? null) !== $createIso
            || (string) ($answer['currency']['name'] ?? '') === '') {
            $failures[] = 'somcp_create_currency (CHF): expected created true with a name from CLDR - ' . excerpt($result);
        } elseif (empty($againResult['isError'])
            || strpos(excerpt($againResult), 'is already currency ' . (int) $answer['id_currency'] . ' of this shop') === false) {
            $failures[] = 'somcp_create_currency (the same call again): expected a refusal as already a currency of the shop - ' . excerpt($againResult);
        } else {
            printf("  %-6s %s\n", 'ok', 'somcp_create_currency (CHF)');
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_create_currency: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        $testIso = $createIso;
        $ids = array_map('intval', array_column(Db::getInstance()->executeS(
            "SELECT `id_currency` FROM `" . _DB_PREFIX_ . "currency` WHERE `iso_code` = '" . pSQL($testIso) . "'"
        ) ?: [], 'id_currency'));
        if ($ids !== []) {
            foreach (['currency', 'currency_lang', 'currency_shop', 'module_currency'] as $table) {
                Db::getInstance()->delete($table, 'id_currency IN (' . implode(', ', $ids) . ')');
            }
        }
    }
}

// An alternative currency added (#373): ZZP is no ISO 4217 code (ZZ codes
// are not assigned), checked first against CLDR so a future CLDR that knows
// it is said rather than failed on. It is created with the merchant's name
// and symbol and the 2 decimals of the form, then deleted with
// somcp_delete_currency; the row is removed from every table in finally.
$alternativeIso = 'ZZP';
if ($createCurrency !== null && isset($currencyCall) && (int) Db::getInstance()->getValue(
    "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "currency` WHERE `iso_code` = '" . pSQL($alternativeIso) . "'"
) === 0) {
    ++$calls;
    try {
        $repository = SomcpBackOffice::cldrLocaleRepository();
        $cldr = $repository !== null
            ? SomcpToolCreateCurrency::cldrLocale($repository, (int) Configuration::get('PS_LANG_DEFAULT'))
            : null;
        if ($cldr === null) {
            $failures[] = 'somcp_create_currency (alternative ZZP): CLDR could not be read on this shop';
        } elseif ($cldr->getCurrency($alternativeIso) !== null) {
            $failures[] = 'somcp_create_currency (alternative ZZP): CLDR now knows ZZP - pick another code for the test';
        } else {
            [$result, $answer] = $currencyCall($createCurrency, ['iso_code' => $alternativeIso, 'alternative' => true,
                'conversion_rate' => 10, 'name' => 'Points', 'symbol' => 'P',
                'languages' => [(string) Language::getIsoById((int) $context->language->id)]]);
            $deleted = [];
            if (($answer['created'] ?? null) === true && $deleteCurrency !== null) {
                $deleted = $currencyCall($deleteCurrency, ['id_currency' => (int) $answer['id_currency']])[1];
            }
            if (($answer['created'] ?? null) !== true || ($answer['currency']['alternative'] ?? null) !== true
                || ($answer['currency']['precision'] ?? null) !== 2 || ($answer['currency']['name'] ?? null) !== 'Points') {
                $failures[] = 'somcp_create_currency (alternative ZZP): expected created true, alternative true and 2 decimals - ' . excerpt($result);
            } elseif ($deleteCurrency !== null && ($deleted['deleted'] ?? null) !== true) {
                $failures[] = 'somcp_create_currency (alternative ZZP): somcp_delete_currency did not delete it';
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_create_currency (alternative ZZP)');
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_create_currency (alternative ZZP): ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        $ids = array_map('intval', array_column(Db::getInstance()->executeS(
            "SELECT `id_currency` FROM `" . _DB_PREFIX_ . "currency` WHERE `iso_code` = '" . pSQL($alternativeIso) . "'"
        ) ?: [], 'id_currency'));
        if ($ids !== []) {
            foreach (['currency', 'currency_lang', 'currency_shop', 'module_currency'] as $table) {
                Db::getInstance()->delete($table, 'id_currency IN (' . implode(', ', $ids) . ')');
            }
        }
    }
}

// The address format of a country changed, given back, then the same call
// again (#361): the JSON scenario cannot hold the lines France stores, which
// the localization pack of the CI shop decides. France is read first and its
// own lines are written back at the end, so the next tools find it as it was.
$updateCountry = $registry->get('somcp_update_country');
$getCountry = $registry->get('somcp_get_country');
$idFrance = (int) Country::getByIso('FR');
if ($updateCountry !== null && $getCountry !== null && $idFrance > 0) {
    $calls += 4;
    $countryContext = static function (SomcpTool $tool) use ($context, $employee): array {
        return [
            'id_shop' => (int) $context->shop->id,
            'id_employee' => (int) $employee->id,
            'id_lang' => (int) $context->language->id,
            'id_client' => 0,
            'scopes' => implode(' ', $tool->getRequiredScopes()),
            'responses' => [],
            'resumed' => [],
            'capabilities' => [],
            'arguments' => [],
        ];
    };
    $countryAnswer = static function (array $result): array {
        $text = '';
        foreach ($result['content'] ?? [] as $block) {
            $text .= (string) ($block['text'] ?? '') . "\n";
        }
        $answer = json_decode(trim($text), true);

        return is_array($answer) && empty($result['isError']) ? $answer : [];
    };
    $read = $countryAnswer($getCountry->call(['id_country' => $idFrance], $countryContext($getCountry)));
    $lines = is_array($read['address_format'] ?? null) ? $read['address_format'] : [];
    try {
        if ($lines === []) {
            $failures[] = 'somcp_get_country (France): expected the stored address format';
        } else {
            foreach ([
                'the dni line added' => [array_merge($lines, ['dni']), true],
                'the stored lines given back' => [$lines, true],
                'the same call again' => [$lines, false],
            ] as $label => [$format, $expected]) {
                $result = $updateCountry->call(['id_country' => $idFrance, 'address_format' => $format], $countryContext($updateCountry));
                $answer = $countryAnswer($result);
                $stored = $answer['country']['address_format'] ?? null;
                if (($answer['changed'] ?? null) !== $expected || $stored !== $format) {
                    $failures[] = 'somcp_update_country (' . $label . '): expected changed ' . ($expected ? 'true' : 'false') . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_country (' . $label . ')');
                }
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_country (address format): ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        // France keeps its own lines whatever failed above.
        if ($lines !== []) {
            $addressFormat = new AddressFormat($idFrance);
            if (Validate::isLoadedObject($addressFormat) && $addressFormat->format !== implode("\n", $lines)) {
                $addressFormat->format = implode("\n", $lines);
                $addressFormat->save();
            }
        }
    }
}

// A state deleted, then deleted again (#358): the JSON scenario cannot create
// the state it deletes, and every demo state is used by an address or a tax
// rule row, or is one the next tools may read. Made as the check of
// somcp_create_state makes it. Before that, one tax rule row is given to it
// (#362), since the localization pack of the CI shop gives none to a state
// of a country that has states: moving it to another country and deleting
// it must both be refused while the row is there.
$deleteState = $registry->get('somcp_delete_state');
if ($deleteState !== null) {
    $calls += 2;
    $state = new State();
    $state->id_country = (int) Country::getByIso('IT');
    $state->id_zone = 1;
    $state->name = 'somcp state';
    $state->iso_code = 'ZZ';
    $state->active = true;
    $idState = $state->add() ? (int) $state->id : 0;
    $stateContext = [
        'id_shop' => (int) $context->shop->id,
        'id_employee' => (int) $employee->id,
        'id_lang' => (int) $context->language->id,
        'id_client' => 0,
        'scopes' => implode(' ', $deleteState->getRequiredScopes()),
        'responses' => [],
        'resumed' => [],
        'capabilities' => [],
        'arguments' => [],
    ];
    $idTaxGroup = (int) Db::getInstance()->getValue(
        'SELECT `id_tax_rules_group` FROM `' . _DB_PREFIX_ . 'tax_rules_group` WHERE `deleted` = 0 ORDER BY `id_tax_rules_group` ASC'
    );
    try {
        // Inserted as AdminTaxRulesGroupController does, there being no
        // object that writes one row alone without the checks under test.
        $taxRow = $idState > 0 && $idTaxGroup > 0 && Db::getInstance()->insert('tax_rule', [
            'id_tax_rules_group' => $idTaxGroup,
            'id_country' => (int) $state->id_country,
            'id_state' => $idState,
            'zipcode_from' => '0',
            'zipcode_to' => '0',
            'id_tax' => 0,
            'behavior' => 0,
            'description' => '',
        ]);
        $usedChecks = [
            'a state a tax rule row uses' => [$deleteState, ['id_state' => $idState], ['Nothing was deleted.',
                'is used by 1 tax rule rows (tax rules ' . $idTaxGroup . ') - delete them first with somcp_delete_country_rule']],
        ];
        // To the United States rather than France: a country without states
        // is refused before the rows are counted.
        $updateUsedState = $registry->get('somcp_update_state');
        if ($updateUsedState !== null) {
            $usedChecks = [
                'a state a tax rule row uses, moved to another country' => [$updateUsedState, ['id_state' => $idState, 'country' => 'US'],
                    ['0 addresses and 1 tax rule rows - a state of another country would no longer match them']],
            ] + $usedChecks;
        }
        $calls += count($usedChecks);
        foreach ($usedChecks as $label => [$tool, $arguments, $needles]) {
            $result = $tool->call($arguments, ['scopes' => implode(' ', $tool->getRequiredScopes())] + $stateContext);
            $text = '';
            foreach ($result['content'] ?? [] as $block) {
                $text .= (string) ($block['text'] ?? '') . "\n";
            }
            $missing = array_values(array_filter($needles, static function (string $needle) use ($text): bool {
                return strpos($text, $needle) === false;
            }));
            $name = $tool === $deleteState ? 'somcp_delete_state' : 'somcp_update_state';
            if (!$taxRow || empty($result['isError']) || $missing !== []) {
                $failures[] = $name . ' (' . $label . '): expected ' . implode(', ', $missing ?: [$taxRow ? 'an error' : 'a tax rule row to test with']) . ' - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'error', $name . ' (' . $label . ', refused)');
            }
        }
        Db::getInstance()->delete('tax_rule', '`id_state` = ' . (int) $idState);

        foreach ([
            'a state no address uses' => [false, ['"deleted": true', '"iso_code": "ZZ"']],
            'the same state again' => [true, ['Nothing was deleted.', 'no state of this shop has the identifier']],
        ] as $label => [$error, $needles]) {
            $result = $deleteState->call(['id_state' => $idState], $stateContext);
            $text = '';
            foreach ($result['content'] ?? [] as $block) {
                $text .= (string) ($block['text'] ?? '') . "\n";
            }
            $missing = array_values(array_filter($needles, static function (string $needle) use ($text): bool {
                return strpos($text, $needle) === false;
            }));
            if ($idState === 0 || !empty($result['isError']) !== $error || $missing !== []) {
                $failures[] = 'somcp_delete_state (' . $label . '): expected ' . implode(', ', $missing ?: [$error ? 'an error' : 'no error']) . ' - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_delete_state (' . $label . ')');
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_delete_state: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        if ($idState > 0) {
            Db::getInstance()->delete('tax_rule', '`id_state` = ' . (int) $idState);
            $left = new State($idState);
            if (Validate::isLoadedObject($left)) {
                $left->delete();
            }
        }
    }
}

// A country enabled, the same call again, then put back (#360): the JSON
// scenario only covers the refusals, since a change it made would stay for
// the tools read after it. Afghanistan is disabled on a fresh install, in a
// zone no demo carrier serves and with no payment module offered to it, so
// both warnings are due; it is put back as it was whatever happens.
$updateCountry = $registry->get('somcp_update_country');
$idAfghanistan = (int) Country::getByIso('AF');
if ($updateCountry !== null && $idAfghanistan > 0) {
    $calls += 3;
    $before = new Country($idAfghanistan);
    $wasActive = (bool) $before->active;
    $wasLabel = (bool) $before->display_tax_label;
    $countryContext = [
        'id_shop' => (int) $context->shop->id,
        'id_employee' => (int) $employee->id,
        'id_lang' => (int) $context->language->id,
        'id_client' => 0,
        'scopes' => implode(' ', $updateCountry->getRequiredScopes()),
        'responses' => [],
        'resumed' => [],
        'capabilities' => [],
        'arguments' => [],
    ];
    $change = ['id_country' => $idAfghanistan, 'active' => true, 'display_tax_label' => false];
    try {
        foreach ([
            'a country enabled' => [$change, ['"changed": true', '"field": "active"', '"field": "display_tax_label"', 'No payment module is offered to ', 'No carrier serves zone ']],
            'the same call again' => [$change, ['"changed": false', 'The country already holds every value given.']],
            'put back' => [['id_country' => $idAfghanistan, 'active' => $wasActive, 'display_tax_label' => $wasLabel], ['"changed": true']],
        ] as $label => [$arguments, $needles]) {
            $result = $updateCountry->call($arguments, $countryContext);
            $text = '';
            foreach ($result['content'] ?? [] as $block) {
                $text .= (string) ($block['text'] ?? '') . "\n";
            }
            $missing = array_values(array_filter($needles, static function (string $needle) use ($text): bool {
                return strpos($text, $needle) === false;
            }));
            // Active before the tax label, the order of the form.
            if ($label === 'a country enabled' && strpos($text, '"field": "active"') > strpos($text, '"field": "display_tax_label"')) {
                $missing[] = 'active before display_tax_label in changes';
            }
            if (!empty($result['isError']) || $missing !== []) {
                $failures[] = 'somcp_update_country (' . $label . '): expected ' . implode(', ', $missing) . ' - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_update_country (' . $label . ')');
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_country: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        $left = new Country($idAfghanistan);
        if ((bool) $left->active !== $wasActive || (bool) $left->display_tax_label !== $wasLabel) {
            $left->active = $wasActive;
            $left->display_tax_label = $wasLabel;
            $left->update();
        }
    }
}

// A customer group restriction set on a cart rule, read back, then lifted
// (#406): the JSON scenario only covers the refusals, since a restriction
// left on a demo rule would change what the tools after it read. The rule is
// created here for that alone and deleted whatever happens.
$createRule = $registry->get('somcp_create_cart_rule');
$updateRule = $registry->get('somcp_update_cart_rule');
$getRule = $registry->get('somcp_get_cart_rule');
if ($createRule !== null && $updateRule !== null && $getRule !== null) {
    $calls += 4;
    $ruleContext = [
        'id_shop' => (int) $context->shop->id,
        'id_employee' => (int) $employee->id,
        'id_lang' => (int) $context->language->id,
        'id_client' => 0,
        'scopes' => implode(' ', array_unique(array_merge(
            $createRule->getRequiredScopes(),
            $updateRule->getRequiredScopes(),
            $getRule->getRequiredScopes()
        ))),
        'responses' => [],
        'resumed' => [],
        'capabilities' => [],
        'arguments' => [],
    ];
    $idRule = 0;
    $answer = static function (array $result): string {
        $text = '';
        foreach ($result['content'] ?? [] as $block) {
            $text .= (string) ($block['text'] ?? '') . "\n";
        }

        return $text;
    };
    try {
        $created = $createRule->call([
            'languages' => ['en'],
            'name' => ['en' => 'Somcp group restriction'],
            'code' => 'SOMCPGROUP',
            'free_shipping' => true,
        ], $ruleContext);
        $decoded = json_decode(trim($answer($created)), true);
        $idRule = (int) ($decoded['id_cart_rule'] ?? 0);
        if (!empty($created['isError']) || $idRule <= 0) {
            $failures[] = 'somcp_update_cart_rule (restrictions): the rule could not be created - ' . excerpt($created);
        } else {
            foreach ([
                'group set' => [$updateRule, ['id_cart_rule' => $idRule, 'restrictions' => ['group' => [['id_group' => 3]]]], ['"changed": true', '"field": "restrictions.group"', '"new": [']],
                'group read back' => [$getRule, ['id_cart_rule' => $idRule], ['"group": [', '"id_group": 3']],
                'group lifted' => [$updateRule, ['id_cart_rule' => $idRule, 'restrictions' => ['group' => null]], ['"changed": true', '"field": "restrictions.group"', '"new": null']],
                'nothing left' => [$getRule, ['id_cart_rule' => $idRule], ['"group": null']],
            ] as $label => [$tool, $arguments, $needles]) {
                $result = $tool->call($arguments, $ruleContext);
                $text = $answer($result);
                $missing = array_values(array_filter($needles, static function (string $needle) use ($text): bool {
                    return strpos($text, $needle) === false;
                }));
                if (!empty($result['isError']) || $missing !== []) {
                    $failures[] = 'somcp_update_cart_rule (' . $label . '): expected ' . implode(', ', $missing) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_cart_rule (' . $label . ')');
                }
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_cart_rule (restrictions): ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        if ($idRule > 0) {
            $left = new CartRule($idRule);
            if (Validate::isLoadedObject($left)) {
                $left->delete();
            }
        }
    }

    // The same restriction given to the creation (#407): written with the
    // rule, read back, and the rule deleted whatever happens.
    $calls += 2;
    $idRule = 0;
    try {
        $created = $createRule->call([
            'languages' => ['en'],
            'name' => ['en' => 'Somcp group at creation'],
            'code' => 'SOMCPGROUPNEW',
            'free_shipping' => true,
            'restrictions' => ['group' => [['id_group' => 3]]],
        ], $ruleContext);
        $text = $answer($created);
        $decoded = json_decode(trim($text), true);
        $idRule = (int) ($decoded['id_cart_rule'] ?? 0);
        if (!empty($created['isError']) || $idRule <= 0 || strpos($text, '"id_group": 3') === false) {
            $failures[] = 'somcp_create_cart_rule (restrictions): expected the rule with group 3 - ' . excerpt($created);
        } else {
            printf("  %-6s %s\n", 'ok', 'somcp_create_cart_rule (restrictions)');
            $result = $getRule->call(['id_cart_rule' => $idRule], $ruleContext);
            $text = $answer($result);
            if (!empty($result['isError']) || strpos($text, '"group": [') === false || strpos($text, '"id_group": 3') === false) {
                $failures[] = 'somcp_create_cart_rule (restrictions read back): expected group 3 - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_create_cart_rule (restrictions read back)');
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_create_cart_rule (restrictions): ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        if ($idRule > 0) {
            $left = new CartRule($idRule);
            if (Validate::isLoadedObject($left)) {
                $left->delete();
            }
        }
    }
}

// A carrier read, given back, changed and put back (#320): the JSON scenario
// cannot give a read back nor compare two reads. Demo carrier 3 is used by no
// order, so it is changed in place and keeps its identifier; no call is made
// on a carrier with orders, which would leave a new version behind for the
// tools after it.
$getCarrier = $registry->get('somcp_get_carrier');
$updateCarrier = $registry->get('somcp_update_carrier');
if ($getCarrier !== null && $updateCarrier !== null) {
    $carrierCall = static function (SomcpTool $tool, array $arguments) use ($context, $employee): array {
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
        $text = '';
        foreach ($result['content'] ?? [] as $block) {
            $text .= (string) ($block['text'] ?? '') . "\n";
        }

        return [$result, $text, json_decode(trim($text), true)];
    };
    $calls += 22;
    $carrierBefore = null;
    try {
        [$result, $text, $carrierBefore] = $carrierCall($getCarrier, ['id_carrier' => 3]);
        if (!empty($result['isError']) || !is_array($carrierBefore)) {
            $failures[] = 'somcp_update_carrier (reading carrier 3 first): ' . excerpt($result);
            $carrierBefore = null;
        } else {
            $steps = [
                'the read given back as it is, nothing written' => [$carrierBefore, ['"changed": false', 'Nothing was written.']],
                'a new speed grade, changed in place' => [['id_carrier' => 3, 'grade' => 5], ['"changed": true', '"id_carrier": 3', '"field": "grade"', '"new": 5']],
                'the same speed grade again, nothing written' => [['id_carrier' => 3, 'grade' => 5], ['"changed": false']],
                'the read of the start given back, the grade put back' => [$carrierBefore, ['"changed": true', '"id_carrier": 3', '"field": "grade"', '"new": ' . (int) $carrierBefore['grade']]],
                // The costs settings of #323, then the read of the start given
                // back with ranges of another billing than the one stored.
                'billed by weight, disabled out of range, no tax' => [['id_carrier' => 3, 'billing' => 'weight', 'out_of_range' => 'disable_carrier', 'id_tax_rules_group' => 0], ['"changed": true', '"id_carrier": 3', '"field": "billing"', '"field": "out_of_range"', '"field": "id_tax_rules_group"']],
                'made free, the handling costs turned off with it' => [['id_carrier' => 3, 'is_free' => true], ['"changed": true', '"id_carrier": 3', '"field": "is_free"', '"field": "handling_costs"', '"handling_costs": false']],
                'the read of the start given back, the costs settings put back' => [$carrierBefore, ['"changed": true', '"id_carrier": 3', '"field": "billing"', '"field": "is_free"']],
                // The size and weight limits of #330, then put back as read.
                'a width and a weight limit, changed in place' => [['id_carrier' => 3, 'max_width' => 40, 'max_weight' => 2.5], ['"changed": true', '"id_carrier": 3', '"field": "max_width"', '"field": "max_weight"', '"new": 2.5']],
                'the same limits again, nothing written' => [['id_carrier' => 3, 'max_width' => 40, 'max_weight' => 2.5], ['"changed": false']],
                'the limits of the start given back' => [['id_carrier' => 3, 'max_width' => (int) $carrierBefore['max_width'], 'max_weight' => (float) $carrierBefore['max_weight']], ['"changed": true', '"id_carrier": 3', '"field": "max_width"']],
                // The zones of #336: given back as read, a zone added, then
                // the zones of the start given back alone, since the ranges of
                // the start have no price in the added zone.
                'the zones given back as read, nothing written' => [['id_carrier' => 3, 'zones' => $carrierBefore['zones']], ['"changed": false']],
                'a zone added, its missing price said' => [['id_carrier' => 3, 'zones' => [['id_zone' => 1], ['id_zone' => 2], ['id_zone' => 3]]], ['"changed": true', '"id_carrier": 3', '"field": "zones"', 'The zone(s) 3 have no price yet']],
                'the zones of the start given back' => [['id_carrier' => 3, 'zones' => $carrierBefore['zones']], ['"changed": true', '"id_carrier": 3', '"field": "zones"']],
            ];
            // The prices of the zones the carrier keeps are not touched by a zone change.
            $pricesOf = static function (): int {
                return (int) Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'delivery` WHERE `id_carrier` = 3 AND `id_zone` IN (1, 2)'
                );
            };
            $pricesBefore = $pricesOf();
            foreach ($steps as $why => [$arguments, $needles]) {
                [$result, $text] = $carrierCall($updateCarrier, $arguments);
                $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                    return strpos($text, $needle) === false;
                });
                if (!empty($result['isError']) || $missing !== [] || strpos($text, '"replaced_id_carrier"') !== false) {
                    $failures[] = 'somcp_update_carrier (' . $why . '): expected ok with ' . implode(', ', $missing) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_carrier (' . $why . ')');
                }
            }
            // The ranges of #337: given back as read, one price changed and a
            // range added, read again, then the ranges of the start given back.
            $rangesBefore = is_array($carrierBefore['ranges'] ?? null) ? $carrierBefore['ranges'] : [];
            $rangesChanged = $rangesBefore;
            foreach ($rangesChanged[0]['prices'] ?? [] as $i => $price) {
                if ((int) $price['id_zone'] === 1) {
                    $rangesChanged[0]['prices'][$i]['price_tax_excl'] = 9;
                }
            }
            $rangesChanged[] = ['from' => 200, 'to' => 500, 'prices' => [['id_zone' => 1, 'price_tax_excl' => 0], ['id_zone' => 2, 'price_tax_excl' => 0]]];
            $rangeSteps = [
                'the ranges given back as read, nothing written' => [$rangesBefore, ['"changed": false']],
                'a price changed and a range added, in place' => [$rangesChanged, ['"changed": true', '"id_carrier": 3', '"field": "ranges"']],
                'the ranges of the start given back' => [$rangesBefore, ['"changed": true', '"id_carrier": 3', '"field": "ranges"']],
            ];
            foreach ($rangeSteps as $why => [$ranges, $needles]) {
                [$result, $text] = $carrierCall($updateCarrier, ['id_carrier' => 3, 'ranges' => $ranges]);
                $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                    return strpos($text, $needle) === false;
                });
                if ($rangesBefore === [] || !empty($result['isError']) || $missing !== [] || strpos($text, '"replaced_id_carrier"') !== false) {
                    $failures[] = 'somcp_update_carrier (' . $why . '): expected ok with ' . implode(', ', $missing) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_carrier (' . $why . ')');
                }
                if ($why === 'a price changed and a range added, in place') {
                    [$result, , $changed] = $carrierCall($getCarrier, ['id_carrier' => 3]);
                    $first = [];
                    foreach ($changed['ranges'][0]['prices'] ?? [] as $price) {
                        $first[(int) $price['id_zone']] = $price['price_tax_excl'];
                    }
                    if (!empty($result['isError']) || count($changed['ranges'] ?? []) !== count($rangesBefore) + 1 || (float) ($first[1] ?? -1) !== 9.0) {
                        $failures[] = 'somcp_update_carrier (the ranges read after the change): expected ' . (count($rangesBefore) + 1) . ' ranges and 9 in zone 1 - ' . excerpt($result);
                    } else {
                        printf("  %-6s %s\n", 'ok', 'somcp_update_carrier (the ranges read after the change)');
                    }
                }
            }
            [$result, $text, $carrierAfter] = $carrierCall($getCarrier, ['id_carrier' => 3]);
            unset($carrierBefore['logo'], $carrierAfter['logo']);
            if ($pricesOf() !== $pricesBefore) {
                $failures[] = 'somcp_update_carrier (the zones changed and put back): expected the prices of zones 1 and 2 unchanged, ' . $pricesBefore . ' rows before, ' . $pricesOf() . ' after';
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_update_carrier (the prices of the zones kept, unchanged)');
            }
            if (!empty($result['isError']) || $carrierAfter !== $carrierBefore) {
                $failures[] = 'somcp_update_carrier (carrier 3 read again): expected it as read at the start - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_update_carrier (carrier 3 read again, as at the start)');
            }
            // A free carrier is offered in every zone it serves without a
            // price, so adding a zone to demo carrier 1 (free, used by no
            // order) says nothing about a missing price, then is put back.
            [$result, , $freeBefore] = $carrierCall($getCarrier, ['id_carrier' => 1]);
            if (!empty($result['isError']) || !is_array($freeBefore) || !is_array($freeBefore['zones'] ?? null)) {
                $failures[] = 'somcp_update_carrier (reading carrier 1 first): ' . excerpt($result);
            } else {
                $freeSteps = [
                    'a zone added to a free carrier, no missing price said' => [['id_carrier' => 1, 'zones' => [['id_zone' => 1], ['id_zone' => 2]]], ['"changed": true', '"id_carrier": 1']],
                    'the zones of the free carrier given back' => [['id_carrier' => 1, 'zones' => $freeBefore['zones']], ['"changed": true', '"id_carrier": 1']],
                ];
                foreach ($freeSteps as $why => [$arguments, $needles]) {
                    [$result, $text] = $carrierCall($updateCarrier, $arguments);
                    $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                        return strpos($text, $needle) === false;
                    });
                    if (!empty($result['isError']) || $missing !== [] || strpos($text, 'have no price yet') !== false || strpos($text, '"replaced_id_carrier"') !== false) {
                        $failures[] = 'somcp_update_carrier (' . $why . '): expected ok with ' . implode(', ', $missing) . ' and no missing price - ' . excerpt($result);
                    } else {
                        printf("  %-6s %s\n", 'ok', 'somcp_update_carrier (' . $why . ')');
                    }
                }
                [$result, , $freeAfter] = $carrierCall($getCarrier, ['id_carrier' => 1]);
                if (!empty($result['isError']) || ($freeAfter['zones'] ?? null) !== $freeBefore['zones']) {
                    $failures[] = 'somcp_update_carrier (carrier 1 read again): expected the zones read at the start - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_carrier (carrier 1 read again, its zones as at the start)');
                }
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_carrier (a carrier changed and put back): ' . get_class($e) . ' - ' . $e->getMessage();
    }

    // A carrier moved first and put back (#322): the order of the list is
    // read before and after, which the JSON scenario cannot compare. A move
    // never makes a new version, so carrier 4 keeps its identifier.
    $listCarriers = $registry->get('somcp_list_carriers');
    if ($listCarriers !== null) {
        $calls += 6;
        $order = static function () use ($carrierCall, $listCarriers): array {
            [, , $answer] = $carrierCall($listCarriers, []);
            $ids = [];
            foreach (is_array($answer) ? ($answer['carriers'] ?? []) : [] as $entry) {
                $ids[] = (int) ($entry['id_carrier'] ?? 0);
            }

            return $ids;
        };
        try {
            [$result, $text, $fourth] = $carrierCall($getCarrier, ['id_carrier' => 4]);
            $orderBefore = $order();
            if (!empty($result['isError']) || !is_array($fourth) || !isset($fourth['position']) || !in_array(4, $orderBefore, true)) {
                $failures[] = 'somcp_update_carrier (reading carrier 4 first): ' . excerpt($result);
            } else {
                $expected = array_merge([4], array_values(array_diff($orderBefore, [4])));
                $steps = [
                    'carrier 4 moved first, in place' => [['id_carrier' => 4, 'position' => 0], ['"changed": true', '"id_carrier": 4', '"field": "position"', '"new": 0']],
                    'the same place again, nothing written' => [['id_carrier' => 4, 'position' => 0], ['"changed": false']],
                ];
                foreach ($steps as $why => [$arguments, $needles]) {
                    [$result, $text] = $carrierCall($updateCarrier, $arguments);
                    $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                        return strpos($text, $needle) === false;
                    });
                    if (!empty($result['isError']) || $missing !== [] || strpos($text, '"replaced_id_carrier"') !== false) {
                        $failures[] = 'somcp_update_carrier (' . $why . '): expected ok with ' . implode(', ', $missing) . ' - ' . excerpt($result);
                    } else {
                        printf("  %-6s %s\n", 'ok', 'somcp_update_carrier (' . $why . ')');
                    }
                }
                $orderMoved = $order();
                if ($orderMoved !== $expected) {
                    $failures[] = 'somcp_update_carrier (the list after the move): expected ' . implode(', ', $expected) . ', got ' . implode(', ', $orderMoved);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_carrier (carrier 4 first in the list, the others shifted)');
                }
                [$result, $text] = $carrierCall($updateCarrier, ['id_carrier' => 4, 'position' => (int) $fourth['position']]);
                $orderAfter = $order();
                if (!empty($result['isError']) || $orderAfter !== $orderBefore) {
                    $failures[] = 'somcp_update_carrier (carrier 4 put back): expected ' . implode(', ', $orderBefore) . ', got ' . implode(', ', $orderAfter) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_carrier (carrier 4 put back, the list as at the start)');
                }
            }
        } catch (Throwable $e) {
            $failures[] = 'somcp_update_carrier (a carrier moved and put back): ' . get_class($e) . ' - ' . $e->getMessage();
        }
    }

    // The logo of #331, on carrier 3, which no order uses: the read given
    // back changes nothing, a removal changes something only when there was
    // a logo, and a GIF deposited is stored and read back as a download_ref.
    // The carrier is left without a logo only when it had none at the start.
    $calls += 7;
    try {
        [$result, $text, $third] = $carrierCall($getCarrier, ['id_carrier' => 3]);
        // The smallest valid GIF: one transparent pixel.
        $reference = SomcpUpload::inviteFor((int) $employee->id, $updateCarrier, 'logo');
        $invitation = $reference === '' ? null : SomcpUpload::find($reference, (int) $employee->id);
        $entered = $invitation === null
            ? 'no deposit could be opened'
            : SomcpUpload::enter($invitation, 'data', 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'logo.gif');
        if (!empty($result['isError']) || !is_array($third) || !array_key_exists('logo', $third)) {
            $failures[] = 'somcp_update_carrier (reading the logo of carrier 3 first): ' . excerpt($result);
        } elseif ($entered !== '') {
            $failures[] = 'somcp_update_carrier (a GIF logo): the GIF was not deposited - ' . $entered;
        } else {
            $hadLogo = $third['logo'] !== null;
            $steps = [
                'the logo read given back, nothing written' => [['id_carrier' => 3, 'logo' => $third['logo']], ['"changed": false']],
                'the logo removed' => [['id_carrier' => 3, 'remove_logo' => true], $hadLogo ? ['"changed": true', '"field": "logo"', '"removed": true'] : ['"changed": false']],
                'a GIF logo deposited, stored' => [['id_carrier' => 3, 'logo' => ['upload_ref' => $reference]], ['"changed": true', '"id_carrier": 3', '"field": "logo"', '"stored": true']],
            ];
            foreach ($steps as $why => [$arguments, $needles]) {
                [$result, $text] = $carrierCall($updateCarrier, $arguments);
                $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                    return strpos($text, $needle) === false;
                });
                if (!empty($result['isError']) || $missing !== [] || strpos($text, '"replaced_id_carrier"') !== false) {
                    $failures[] = 'somcp_update_carrier (' . $why . '): expected ok with ' . implode(', ', $missing) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_carrier (' . $why . ')');
                }
            }
            [$result, $text, $logoAfter] = $carrierCall($getCarrier, ['id_carrier' => 3]);
            if (!empty($result['isError']) || !is_array($logoAfter) || !isset($logoAfter['logo'][SomcpDownload::ARG_REF])) {
                $failures[] = 'somcp_update_carrier (carrier 3 read after its logo): expected the logo as a download_ref - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_update_carrier (carrier 3 read back with its new logo)');
            }
            if (!$hadLogo) {
                [$result] = $carrierCall($updateCarrier, ['id_carrier' => 3, 'remove_logo' => true]);
                if (!empty($result['isError'])) {
                    $failures[] = 'somcp_update_carrier (the logo removed again): ' . excerpt($result);
                }
            }
            // A refused logo alone on carrier 2, which orders use (#342): the
            // call is refused, and the carrier keeps its identifier.
            [$result, $text] = $carrierCall($updateCarrier, ['id_carrier' => 2, 'logo' => ['upload_ref' => 'somcp-unknown-reference']]);
            if (empty($result['isError']) || strpos($text, 'Nothing was changed. Correct these and call again:') === false
                || strpos($text, '- logo: no file of this account matches this upload_ref') === false) {
                $failures[] = 'somcp_update_carrier (a refused logo alone on carrier 2): expected the refusal - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_update_carrier (a refused logo alone on carrier 2)');
            }
            [$result, $text] = $carrierCall($getCarrier, ['id_carrier' => 2]);
            if (!empty($result['isError']) || strpos($text, '"id_carrier": 2') === false) {
                $failures[] = 'somcp_update_carrier (carrier 2 after a refused logo): expected it under the same identifier - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_update_carrier (carrier 2 kept its identifier)');
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_carrier (a carrier logo changed): ' . get_class($e) . ' - ' . $e->getMessage();
    }
}

// A carrier deleted (#321): the JSON scenario cannot read the default carrier
// of the shop nor delete a carrier it made. The default carrier is refused,
// then a throwaway carrier no order uses is really removed and not found again.
// No demo carrier is deleted, so the tools after this one keep them.
$deleteCarrier = $registry->get('somcp_delete_carrier');
if ($deleteCarrier !== null && isset($carrierCall)) {
    $calls += 3;
    $idThrowaway = 0;
    try {
        $idDefault = (int) Configuration::get('PS_CARRIER_DEFAULT');
        [$result, $text] = $carrierCall($deleteCarrier, ['id_carrier' => $idDefault]);
        if (empty($result['isError']) || strpos($text, '- id_carrier: ') === false || strpos($text, 'default carrier') === false) {
            $failures[] = 'somcp_delete_carrier (the default carrier): expected a refusal - ' . excerpt($result);
        } else {
            printf("  %-6s %s\n", 'error', 'somcp_delete_carrier (the default carrier, refused)');
        }

        $throwaway = new Carrier();
        $throwaway->name = 'somcp test';
        $throwaway->active = false;
        $throwaway->delay = [];
        foreach (Language::getIDs(false) as $idLang) {
            $throwaway->delay[(int) $idLang] = 'somcp test';
        }
        if ($throwaway->add()) {
            $idThrowaway = (int) $throwaway->id;
        }
        [$result, $text] = $carrierCall($deleteCarrier, ['id_carrier' => $idThrowaway]);
        $left = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'carrier` WHERE `id_carrier` = ' . $idThrowaway
        );
        if ($idThrowaway === 0 || !empty($result['isError']) || strpos($text, '"deleted": true') === false
            || strpos($text, '"kept_for_orders": false') === false || $left !== 0) {
            $failures[] = 'somcp_delete_carrier (a carrier no order uses): expected it removed - ' . excerpt($result);
        } else {
            printf("  %-6s %s\n", 'ok', 'somcp_delete_carrier (a carrier no order uses, removed)');
            $idGone = $idThrowaway;
            $idThrowaway = 0;
            [$result, $text] = $carrierCall($deleteCarrier, ['id_carrier' => $idGone]);
            if (empty($result['isError']) || strpos($text, '- id_carrier: no carrier of this shop has the identifier ' . $idGone) === false) {
                $failures[] = 'somcp_delete_carrier (the same call again): expected not found - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'error', 'somcp_delete_carrier (the same call again, not found)');
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_delete_carrier (a carrier deleted): ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        // Left behind only when the tool failed: removed so the tools after
        // this one see the demo carriers alone.
        if ($idThrowaway > 0) {
            $throwaway = new Carrier($idThrowaway);
            if (Validate::isLoadedObject($throwaway)) {
                $throwaway->delete();
            }
        }
    }
}

// The tax options changed and put back (#312): the JSON scenario cannot read
// the options first and give them back, and a change it made would stay for
// the tools after it. The read of somcp_get_tax_options is given back as it
// is (nothing written), one field changed, the same call again, the ecotax
// switched on and off, then every value given back and read again. On
// PrestaShop 8 the product scenarios leave a product carrying an ecotax, so
// the switch off is refused without the confirmation, then confirmed; the
// ecotax the core wipes is written back afterwards for the tools after it.
$getTaxOptions = $registry->get('somcp_get_tax_options');
$updateTaxOptions = $registry->get('somcp_update_tax_options');
if ($getTaxOptions !== null && $updateTaxOptions !== null) {
    $optionsCall = static function (SomcpTool $tool, array $arguments) use ($context, $employee): array {
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
        $text = '';
        foreach ($result['content'] ?? [] as $block) {
            $text .= (string) ($block['text'] ?? '') . "\n";
        }

        return [$result, $text];
    };
    $optionKeys = ['PS_TAX', 'PS_TAX_DISPLAY', 'PS_TAX_ADDRESS_TYPE', 'PS_USE_ECOTAX', 'PS_ECOTAX_TAX_RULES_GROUP_ID'];
    $idShopOptions = (int) $context->shop->id;
    $idGroupOptions = (int) Shop::getGroupFromShop($idShopOptions);
    $optionsWere = [];
    foreach ($optionKeys as $key) {
        $optionsWere[$key] = Configuration::get($key, null, $idGroupOptions, $idShopOptions);
    }
    // Read as the tool counts them, and kept to be written back in finally.
    $ecotaxProducts = Db::getInstance()->executeS(
        'SELECT `id_product`, `ecotax` FROM `' . _DB_PREFIX_ . 'product` WHERE `ecotax` > 0'
    ) ?: [];
    $ecotaxShops = Db::getInstance()->executeS(
        'SELECT `id_product`, `id_shop`, `ecotax` FROM `' . _DB_PREFIX_ . 'product_shop` WHERE `ecotax` > 0'
    ) ?: [];
    $carryingEcotax = count(array_filter($ecotaxShops, static function (array $row) use ($idShopOptions): bool {
        return (int) $row['id_shop'] === $idShopOptions;
    }));
    $calls += $carryingEcotax > 0 ? 8 : 7;
    try {
        [$result, $text] = $optionsCall($getTaxOptions, []);
        $read = json_decode(trim($text), true);
        if (!empty($result['isError']) || !is_array($read)) {
            $failures[] = 'somcp_update_tax_options (the options read first): expected them read - ' . excerpt($result);
        } else {
            $other = ($read['based_on'] ?? '') === 'invoice_address' ? 'delivery_address' : 'invoice_address';
            $steps = [
                'the read given back as it is, nothing written' => [$read, ['"changed": false', 'Nothing was written.']],
                'Based on changed' => [['based_on' => $other], ['"changed": true', '"field": "based_on"', '"new": "' . $other . '"']],
                'the same call again, nothing written' => [['based_on' => $other], ['"changed": false']],
                'the ecotax on' => [['use_ecotax' => true, 'ecotax_id_tax_rules_group' => 0], ['"changed": true', '"use_ecotax": true']],
            ];
            if ($carryingEcotax > 0) {
                $steps['the ecotax off unconfirmed, a product carrying one'] = [['use_ecotax' => false], ['Nothing was changed', 'reset_product_ecotax: true to confirm'], true];
                $steps['the ecotax off, confirmed'] = [['use_ecotax' => false, 'reset_product_ecotax' => true], ['"changed": true', '"field": "use_ecotax"', '"products_reset": ']];
            } else {
                $steps['the ecotax off, no product carrying one'] = [['use_ecotax' => false], ['"changed": true', '"field": "use_ecotax"']];
            }
            $steps['every value given back'] = [$read, []];
            foreach ($steps as $why => $step) {
                [$arguments, $needles] = $step;
                $refusal = $step[2] ?? false;
                [$result, $text] = $optionsCall($updateTaxOptions, $arguments);
                $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                    return strpos($text, $needle) === false;
                });
                if (empty($result['isError']) === $refusal || $missing !== []) {
                    $failures[] = 'somcp_update_tax_options (' . $why . '): expected ' . ($refusal ? 'refused' : 'ok') . ' with ' . implode(', ', $missing) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_tax_options (' . $why . ')');
                }
            }
            [$result, $text] = $optionsCall($getTaxOptions, []);
            $again = json_decode(trim($text), true);
            if (!is_array($again) || $again != $read) {
                $failures[] = 'somcp_update_tax_options (read again): expected the options as read at the start - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_update_tax_options (read again, as at the start)');
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_tax_options: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        // Whatever happened, the tools after this one find the demo options.
        foreach ($optionsWere as $key => $value) {
            // A key the shop never had is left out, as the test never writes it.
            if ($value !== false) {
                Configuration::updateValue($key, $value, false, $idGroupOptions, $idShopOptions);
            }
        }
        // The confirmed switch off set every ecotax to 0, which no tool can
        // give back: the stored amounts are written back as they were read.
        foreach ($ecotaxProducts as $row) {
            Db::getInstance()->update('product', ['ecotax' => (float) $row['ecotax']], '`id_product` = ' . (int) $row['id_product']);
        }
        foreach ($ecotaxShops as $row) {
            Db::getInstance()->update('product_shop', ['ecotax' => (float) $row['ecotax']], '`id_product` = ' . (int) $row['id_product'] . ' AND `id_shop` = ' . (int) $row['id_shop']);
        }
    }
}

// The country options (#375) switched on, the same call again, then put
// back: a JSON scenario cannot put the option back whatever happens, and the
// scenarios after it read the option of a fresh shop.
$updateCountryOptions = $registry->get('somcp_update_country_options');
if ($updateCountryOptions !== null) {
    $idShopCountry = (int) $context->shop->id;
    $idGroupCountry = (int) Shop::getGroupFromShop($idShopCountry);
    $restrictWas = Configuration::get('PS_RESTRICT_DELIVERED_COUNTRIES', null, $idGroupCountry, $idShopCountry);
    $calls += 2;
    try {
        $steps = [
            'the restriction switched on' => ['"changed": true', '"field": "restrict_to_carriers"', '"new": true'],
            'the same call again, nothing written' => ['"changed": false', 'Nothing was written.'],
        ];
        foreach ($steps as $why => $needles) {
            $result = $updateCountryOptions->call(['restrict_to_carriers' => true], [
                'id_shop' => $idShopCountry,
                'id_employee' => (int) $employee->id,
                'id_lang' => (int) $context->language->id,
                'id_client' => 0,
                'scopes' => implode(' ', $updateCountryOptions->getRequiredScopes()),
                'responses' => [],
                'resumed' => [],
                'capabilities' => [],
                'arguments' => ['restrict_to_carriers' => true],
            ]);
            $text = '';
            foreach ($result['content'] ?? [] as $block) {
                $text .= (string) ($block['text'] ?? '') . "\n";
            }
            $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                return strpos($text, $needle) === false;
            });
            if (!empty($result['isError']) || $missing !== []) {
                $failures[] = 'somcp_update_country_options (' . $why . '): expected ok with ' . implode(', ', $missing) . ' - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', 'somcp_update_country_options (' . $why . ')');
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_country_options: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        // Whatever happened, the tools after this one find the option of a
        // fresh shop, false when it was never stored.
        Configuration::updateValue('PS_RESTRICT_DELIVERED_COUNTRIES', (int) $restrictWas, false, $idGroupCountry, $idShopCountry);
    }
}

// The localization settings (#383): the read given back whole writes
// nothing, then the time zone is changed and put back. A JSON scenario cannot
// give back what the read answered, nor put the time zone back whatever
// happens, and the tools after this one date things in it.
$getLocalization = $registry->get('somcp_get_localization_settings');
$updateLocalization = $registry->get('somcp_update_localization_settings');
if ($getLocalization !== null && $updateLocalization !== null) {
    $idShopLocal = (int) $context->shop->id;
    $idGroupLocal = (int) Shop::getGroupFromShop($idShopLocal);
    $timezoneWas = Configuration::get('PS_TIMEZONE', null, $idGroupLocal, $idShopLocal);
    $localContext = static function (SomcpTool $tool, array $arguments) use ($idShopLocal, $employee, $context): array {
        return [
            'id_shop' => $idShopLocal,
            'id_employee' => (int) $employee->id,
            'id_lang' => (int) $context->language->id,
            'id_client' => 0,
            'scopes' => implode(' ', $tool->getRequiredScopes()),
            'responses' => [],
            'resumed' => [],
            'capabilities' => [],
            'arguments' => $arguments,
        ];
    };
    $calls += 4;
    try {
        $read = $getLocalization->call([], $localContext($getLocalization, []));
        $decoded = json_decode((string) ($read['content'][0]['text'] ?? ''), true);
        if (!empty($read['isError']) || !is_array($decoded) || !isset($decoded['localization']) || !is_array($decoded['localization'])) {
            $failures[] = 'somcp_update_localization_settings (the settings read first): expected them read - ' . excerpt($read);
        } else {
            $whole = $decoded['localization'] + ['language' => $decoded['language'] ?? ''];
            $timezone = $decoded['localization']['timezone'] === 'Europe/Paris' ? 'Europe/Berlin' : 'Europe/Paris';
            $steps = [
                ['the read given back whole, nothing written', $whole, ['"changed": false', 'Nothing was written.']],
                ['the time zone changed', ['timezone' => $timezone], ['"changed": true', '"field": "timezone"', '"new": "' . $timezone . '"']],
                ['the time zone put back', ['timezone' => $decoded['localization']['timezone']], ['"changed": true', '"field": "timezone"']],
            ];
            foreach ($steps as [$why, $arguments, $needles]) {
                $result = $updateLocalization->call($arguments, $localContext($updateLocalization, $arguments));
                $text = '';
                foreach ($result['content'] ?? [] as $block) {
                    $text .= (string) ($block['text'] ?? '') . "\n";
                }
                $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                    return strpos($text, $needle) === false;
                });
                if (!empty($result['isError']) || $missing !== []) {
                    $failures[] = 'somcp_update_localization_settings (' . $why . '): expected ok with ' . implode(', ', $missing) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_localization_settings (' . $why . ')');
                }
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_localization_settings: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        // Whatever happened, the tools after this one find the time zone of a
        // fresh shop; a key it never had stays unwritten.
        if ($timezoneWas !== false) {
            Configuration::updateValue('PS_TIMEZONE', $timezoneWas, false, $idGroupLocal, $idShopLocal);
        }
    }
}

// The shipping preferences (#392): the read given back whole writes
// nothing, then Order by is turned to the other value and put back. A JSON
// scenario cannot give back what the read answered, nor put the setting back
// whatever happens, and the carrier tools after this one list in that order.
$getShipping = $registry->get('somcp_get_shipping_preferences');
$updateShipping = $registry->get('somcp_update_shipping_preferences');
if ($getShipping !== null && $updateShipping !== null) {
    $idShopShipping = (int) $context->shop->id;
    $idGroupShipping = (int) Shop::getGroupFromShop($idShopShipping);
    $orderWas = Configuration::get('PS_CARRIER_DEFAULT_ORDER', null, $idGroupShipping, $idShopShipping);
    $shippingContext = static function (SomcpTool $tool, array $arguments) use ($idShopShipping, $employee, $context): array {
        return [
            'id_shop' => $idShopShipping,
            'id_employee' => (int) $employee->id,
            'id_lang' => (int) $context->language->id,
            'id_client' => 0,
            'scopes' => implode(' ', $tool->getRequiredScopes()),
            'responses' => [],
            'resumed' => [],
            'capabilities' => [],
            'arguments' => $arguments,
        ];
    };
    $calls += 4;
    try {
        $read = $getShipping->call([], $shippingContext($getShipping, []));
        $decoded = json_decode((string) ($read['content'][0]['text'] ?? ''), true);
        if (!empty($read['isError']) || !is_array($decoded) || !isset($decoded['carrier_order'])) {
            $failures[] = 'somcp_update_shipping_preferences (the preferences read first): expected them read - ' . excerpt($read);
        } else {
            $other = $decoded['carrier_order'] === 'ascending' ? 'descending' : 'ascending';
            $steps = [
                ['the read given back whole, nothing written', $decoded, ['"changed": false', 'Nothing was written.']],
                ['Order by turned to the other value', ['carrier_order' => $other], ['"changed": true', '"field": "carrier_order"', '"new": "' . $other . '"']],
                ['Order by put back', ['carrier_order' => $decoded['carrier_order']], ['"changed": true', '"field": "carrier_order"']],
            ];
            foreach ($steps as [$why, $arguments, $needles]) {
                $result = $updateShipping->call($arguments, $shippingContext($updateShipping, $arguments));
                $text = '';
                foreach ($result['content'] ?? [] as $block) {
                    $text .= (string) ($block['text'] ?? '') . "\n";
                }
                $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                    return strpos($text, $needle) === false;
                });
                if (!empty($result['isError']) || $missing !== []) {
                    $failures[] = 'somcp_update_shipping_preferences (' . $why . '): expected ok with ' . implode(', ', $missing) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_shipping_preferences (' . $why . ')');
                }
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_shipping_preferences: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        // Whatever happened, the tools after this one find the order of a
        // fresh shop; a key it never had stays unwritten.
        if ($orderWas !== false) {
            Configuration::updateValue('PS_CARRIER_DEFAULT_ORDER', $orderWas, false, $idGroupShipping, $idShopShipping);
        }
    }
}

// The payment restrictions (#396, #397): the read of ps_checkpayment given
// back whole writes nothing, then its groups are set to 1 and 2 and France is
// added to its countries, or taken out where the installer already gave it
// France (a fresh shop gives a payment module its active countries), read
// back, and put back to what was read. A JSON scenario cannot give back what the read
// answered, nor put the rows back whatever happens, and the checkout of the
// order tools after this one offers the module by these rows.
$getPayment = $registry->get('somcp_get_payment_preferences');
$updatePayment = $registry->get('somcp_update_payment_restrictions');
if ($getPayment !== null && $updatePayment !== null) {
    $idShopPayment = (int) $context->shop->id;
    $idModulePayment = (int) Module::getModuleIdByName('ps_checkpayment');
    $paymentRowsWere = [];
    foreach (['group' => 'id_group', 'country' => 'id_country'] as $paymentType => $paymentColumn) {
        $paymentRowsWere[$paymentType] = array_map('intval', array_column(Db::getInstance()->executeS(
            'SELECT `' . $paymentColumn . '` FROM `' . _DB_PREFIX_ . 'module_' . $paymentType . '` WHERE `id_module` = '
            . $idModulePayment . ' AND `id_shop` = ' . $idShopPayment
        ) ?: [], $paymentColumn));
    }
    // Found by its ISO code rather than written as 8, as a fresh shop numbers it.
    $idFrance = (int) Country::getByIso('FR');
    $paymentContext = static function (SomcpTool $tool, array $arguments) use ($idShopPayment, $employee, $context): array {
        return [
            'id_shop' => $idShopPayment,
            'id_employee' => (int) $employee->id,
            'id_lang' => (int) $context->language->id,
            'id_client' => 0,
            'scopes' => implode(' ', $tool->getRequiredScopes()),
            'responses' => [],
            'resumed' => [],
            'capabilities' => [],
            'arguments' => $arguments,
        ];
    };
    $calls += 4;
    try {
        $read = $getPayment->call(['name' => 'ps_checkpayment'], $paymentContext($getPayment, ['name' => 'ps_checkpayment']));
        $decoded = json_decode((string) ($read['content'][0]['text'] ?? ''), true);
        $module = is_array($decoded) ? ($decoded['payment_modules'][0] ?? null) : null;
        if (!empty($read['isError']) || !is_array($module) || $idModulePayment === 0) {
            $failures[] = 'somcp_update_payment_restrictions (the module read first): expected it read - ' . excerpt($read);
        } else {
            $steps = [
                ['the read given back whole, nothing written', $module, ['"changed": false', 'Nothing was written.']],
                ['the groups set to 1 and 2', ['name' => 'ps_checkpayment', 'groups' => [1, 2]], ['"changed": true', '"field": "groups"', '"id_group": 2']],
                in_array($idFrance, $paymentRowsWere['country'], true)
                    ? ['France taken out of the countries', ['name' => 'ps_checkpayment', 'countries' => array_values(array_diff($paymentRowsWere['country'], [$idFrance]))], ['"changed": true', '"field": "countries"']]
                    : ['France added to the countries', ['name' => 'ps_checkpayment', 'countries' => array_merge($paymentRowsWere['country'], [$idFrance])], ['"changed": true', '"field": "countries"', '"iso_code": "FR"']],
            ];
            foreach ($steps as [$why, $arguments, $needles]) {
                $result = $updatePayment->call($arguments, $paymentContext($updatePayment, $arguments));
                $text = '';
                foreach ($result['content'] ?? [] as $block) {
                    $text .= (string) ($block['text'] ?? '') . "\n";
                }
                $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                    return strpos($text, $needle) === false;
                });
                if (!empty($result['isError']) || $missing !== []) {
                    $failures[] = 'somcp_update_payment_restrictions (' . $why . '): expected ok with ' . implode(', ', $missing) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_payment_restrictions (' . $why . ')');
                }
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_payment_restrictions: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        // Whatever happened, the tools after this one find the groups of a
        // fresh shop.
        if ($idModulePayment > 0) {
            foreach ($paymentRowsWere as $paymentType => $paymentIds) {
                SomcpToolUpdatePaymentRestrictions::replace($paymentType, $idModulePayment, $idShopPayment, $paymentIds);
            }
        }
    }
}

// The e-mail settings (#398, #399): the read given back whole writes
// nothing, then Log Emails is turned to the other value, read back, and put
// back whatever happens. A JSON scenario cannot give back what the read
// answered. The method is never changed: the CI shops would stop sending the
// e-mails other tests read.
$getEmail = $registry->get('somcp_get_email_settings');
$updateEmail = $registry->get('somcp_update_email_settings');
if ($getEmail !== null && $updateEmail !== null) {
    $idShopEmail = (int) $context->shop->id;
    $idShopGroupEmail = (int) Shop::getGroupFromShop($idShopEmail);
    $logEmailsWas = Configuration::get('PS_LOG_EMAILS', null, $idShopGroupEmail, $idShopEmail);
    $emailContext = static function (SomcpTool $tool, array $arguments) use ($idShopEmail, $employee, $context): array {
        return [
            'id_shop' => $idShopEmail,
            'id_employee' => (int) $employee->id,
            'id_lang' => (int) $context->language->id,
            'id_client' => 0,
            'scopes' => implode(' ', $tool->getRequiredScopes()),
            'responses' => [],
            'resumed' => [],
            'capabilities' => [],
            'arguments' => $arguments,
        ];
    };
    $calls += 3;
    try {
        $read = $getEmail->call([], $emailContext($getEmail, []));
        $settings = json_decode((string) ($read['content'][0]['text'] ?? ''), true);
        if (!empty($read['isError']) || !is_array($settings) || !array_key_exists('log_emails', $settings)) {
            $failures[] = 'somcp_update_email_settings (the settings read first): expected them read - ' . excerpt($read);
        } else {
            $toggled = !$settings['log_emails'];
            $steps = [
                ['the read given back whole, nothing written', $settings, ['"changed": false', 'Nothing was written.']],
                ['Log Emails turned to the other value', ['log_emails' => $toggled], ['"changed": true', '"field": "log_emails"', '"log_emails": ' . ($toggled ? 'true' : 'false')]],
            ];
            foreach ($steps as [$why, $arguments, $needles]) {
                $result = $updateEmail->call($arguments, $emailContext($updateEmail, $arguments));
                $text = '';
                foreach ($result['content'] ?? [] as $block) {
                    $text .= (string) ($block['text'] ?? '') . "\n";
                }
                $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                    return strpos($text, $needle) === false;
                });
                if (!empty($result['isError']) || $missing !== []) {
                    $failures[] = 'somcp_update_email_settings (' . $why . '): expected ok with ' . implode(', ', $missing) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_email_settings (' . $why . ')');
                }
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_email_settings: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        // Whatever happened, the tools after this one find the e-mail log as
        // the shop had it.
        Configuration::updateValue('PS_LOG_EMAILS', $logEmailsWas, false, $idShopGroupEmail, $idShopEmail);
    }
}

// The order settings (#400, #401): the read given back whole writes nothing,
// then Offer recycled packaging is turned to the other value, read back, and
// put back whatever happens. A JSON scenario cannot give back what the read
// answered, and the checkout of the order tools after this one reads it.
$getOrderSettings = $registry->get('somcp_get_order_settings');
$updateOrderSettings = $registry->get('somcp_update_order_settings');
if ($getOrderSettings !== null && $updateOrderSettings !== null) {
    $idShopOrderSettings = (int) $context->shop->id;
    $idShopGroupOrderSettings = (int) Shop::getGroupFromShop($idShopOrderSettings);
    $recycledWas = Configuration::get('PS_RECYCLABLE_PACK', null, $idShopGroupOrderSettings, $idShopOrderSettings);
    $orderSettingsContext = static function (SomcpTool $tool, array $arguments) use ($idShopOrderSettings, $employee, $context): array {
        return [
            'id_shop' => $idShopOrderSettings,
            'id_employee' => (int) $employee->id,
            'id_lang' => (int) $context->language->id,
            'id_client' => 0,
            'scopes' => implode(' ', $tool->getRequiredScopes()),
            'responses' => [],
            'resumed' => [],
            'capabilities' => [],
            'arguments' => $arguments,
        ];
    };
    $calls += 4;
    try {
        $read = $getOrderSettings->call([], $orderSettingsContext($getOrderSettings, []));
        $settings = json_decode((string) ($read['content'][0]['text'] ?? ''), true);
        if (!empty($read['isError']) || !is_array($settings) || !array_key_exists('recycled_packaging', $settings)) {
            $failures[] = 'somcp_update_order_settings (the settings read first): expected them read - ' . excerpt($read);
        } else {
            $toggled = !$settings['recycled_packaging'];
            $steps = [
                ['the read given back whole, nothing written', $settings, ['"changed": false', 'Nothing was written.']],
                ['Offer recycled packaging turned to the other value', ['recycled_packaging' => $toggled], ['"changed": true', '"field": "recycled_packaging"', '"recycled_packaging": ' . ($toggled ? 'true' : 'false')]],
                ['Offer recycled packaging put back', ['recycled_packaging' => !$toggled], ['"changed": true', '"field": "recycled_packaging"', '"recycled_packaging": ' . ($toggled ? 'false' : 'true')]],
            ];
            foreach ($steps as [$why, $arguments, $needles]) {
                $result = $updateOrderSettings->call($arguments, $orderSettingsContext($updateOrderSettings, $arguments));
                $text = '';
                foreach ($result['content'] ?? [] as $block) {
                    $text .= (string) ($block['text'] ?? '') . "\n";
                }
                $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                    return strpos($text, $needle) === false;
                });
                if (!empty($result['isError']) || $missing !== []) {
                    $failures[] = 'somcp_update_order_settings (' . $why . '): expected ok with ' . implode(', ', $missing) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_order_settings (' . $why . ')');
                }
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_order_settings: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        // Whatever happened, the tools after this one find the recycled
        // packaging as the shop had it; a key it never had stays unwritten.
        if ($recycledWas !== false) {
            Configuration::updateValue('PS_RECYCLABLE_PACK', $recycledWas, false, $idShopGroupOrderSettings, $idShopOrderSettings);
        }
    }
}

// The product settings (#408, #409, #410): the read given back whole writes
// nothing, then Products per page is set to 13 and the English label of
// out-of-stock products with denied backorders to "Somcp out", each read back
// and put back whatever happens. Catalog mode is never turned on, and stock
// management and out-of-stock ordering never change: the order and stock
// tools after this one rely on them.
$getProductSettings = $registry->get('somcp_get_product_settings');
$updateProductSettings = $registry->get('somcp_update_product_settings');
if ($getProductSettings !== null && $updateProductSettings !== null) {
    $idShopProductSettings = (int) $context->shop->id;
    $idShopGroupProductSettings = (int) Shop::getGroupFromShop($idShopProductSettings);
    $perPageWas = Configuration::get('PS_PRODUCTS_PER_PAGE', null, $idShopGroupProductSettings, $idShopProductSettings);
    $idLangEnProductSettings = (int) Language::getIdByIso('en');
    $deniedWas = $idLangEnProductSettings > 0
        ? Configuration::get('PS_LABEL_OOS_PRODUCTS_BOD', $idLangEnProductSettings, $idShopGroupProductSettings, $idShopProductSettings)
        : false;
    $productSettingsContext = static function (SomcpTool $tool, array $arguments) use ($idShopProductSettings, $employee, $context): array {
        return [
            'id_shop' => $idShopProductSettings,
            'id_employee' => (int) $employee->id,
            'id_lang' => (int) $context->language->id,
            'id_client' => 0,
            'scopes' => implode(' ', $tool->getRequiredScopes()),
            'responses' => [],
            'resumed' => [],
            'capabilities' => [],
            'arguments' => $arguments,
        ];
    };
    $calls += $deniedWas !== false ? 4 : 3;
    try {
        $read = $getProductSettings->call([], $productSettingsContext($getProductSettings, []));
        $settings = json_decode((string) ($read['content'][0]['text'] ?? ''), true);
        if (!empty($read['isError']) || !is_array($settings) || !array_key_exists('products_per_page', $settings)) {
            $failures[] = 'somcp_update_product_settings (the settings read first): expected them read - ' . excerpt($read);
        } else {
            $perPage = (int) $settings['products_per_page'] === 13 ? 14 : 13;
            $steps = [
                ['the read given back whole, nothing written', $settings, ['"changed": false', 'Nothing was written.']],
                ['Products per page changed', ['products_per_page' => $perPage], ['"changed": true', '"field": "products_per_page"', '"products_per_page": ' . $perPage]],
            ];
            if ($deniedWas !== false) {
                $denied = (string) $deniedWas === 'Somcp out' ? 'Somcp out again' : 'Somcp out';
                $steps[] = [
                    'a stock label changed in one language',
                    ['languages' => ['en'], 'label_backorders_denied' => ['en' => $denied]],
                    ['"changed": true', '"field": "label_backorders_denied (en)"', '"new": "' . $denied . '"', '"en": "' . $denied . '"'],
                ];
            }
            foreach ($steps as [$why, $arguments, $needles]) {
                $result = $updateProductSettings->call($arguments, $productSettingsContext($updateProductSettings, $arguments));
                $text = '';
                foreach ($result['content'] ?? [] as $block) {
                    $text .= (string) ($block['text'] ?? '') . "\n";
                }
                $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                    return strpos($text, $needle) === false;
                });
                if (!empty($result['isError']) || $missing !== []) {
                    $failures[] = 'somcp_update_product_settings (' . $why . '): expected ok with ' . implode(', ', $missing) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_product_settings (' . $why . ')');
                }
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_product_settings: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        // Whatever happened, the tools after this one find the lists as the
        // shop had them.
        if ($perPageWas !== false) {
            Configuration::updateValue('PS_PRODUCTS_PER_PAGE', $perPageWas, false, $idShopGroupProductSettings, $idShopProductSettings);
        }
        if ($deniedWas !== false) {
            Configuration::updateValue('PS_LABEL_OOS_PRODUCTS_BOD', [$idLangEnProductSettings => (string) $deniedWas], false, $idShopGroupProductSettings, $idShopProductSettings);
        }
    }
}

// The localization pack (#389): the units of the pack of the default country
// are imported, then the units read before are put back. A JSON scenario
// cannot put them back whatever happens, and an import of states or taxes
// would change the shop for good, so only the units are imported.
$importPack = $registry->get('somcp_import_localization_pack');
if ($importPack !== null) {
    $idShopPack = (int) $context->shop->id;
    $unitKeys = ['PS_WEIGHT_UNIT', 'PS_VOLUME_UNIT', 'PS_DIMENSION_UNIT', 'PS_BASE_DISTANCE_UNIT', 'PS_DISTANCE_UNIT'];
    // Read as the core writes them: _installUnits() saves every key for the
    // whole installation.
    $unitsWere = [];
    foreach ($unitKeys as $key) {
        $unitsWere[$key] = Configuration::get($key, null, 0, 0);
    }
    $packIso = Tools::strtolower((string) Country::getIsoById((int) Configuration::get('PS_COUNTRY_DEFAULT')));
    $arguments = ['country' => $packIso, 'content' => ['units']];
    ++$calls;
    try {
        $result = $importPack->call($arguments, [
            'id_shop' => $idShopPack,
            'id_employee' => (int) $employee->id,
            'id_lang' => (int) $context->language->id,
            'id_client' => 0,
            'scopes' => implode(' ', $importPack->getRequiredScopes()),
            'responses' => [],
            'resumed' => [],
            'capabilities' => [],
            'arguments' => $arguments,
        ]);
        $text = (string) ($result['content'][0]['text'] ?? '');
        if (!empty($result['isError']) || strpos($text, '"changed":') === false || strpos($text, '"kept":') === false) {
            $failures[] = 'somcp_import_localization_pack (the units of ' . $packIso . '): expected ok - ' . excerpt($result);
        } else {
            printf("  %-6s %s\n", 'ok', 'somcp_import_localization_pack (the units of ' . $packIso . ')');
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_import_localization_pack: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        // Whatever happened, the tools after this one find the units of a
        // fresh shop; a key it never had stays unwritten.
        foreach ($unitsWere as $key => $value) {
            if ($value !== false) {
                Configuration::updateValue($key, $value);
            }
        }
    }
}

// The general settings (#386): the read given back whole writes nothing,
// then Display suppliers is turned over and put back. A JSON scenario cannot
// give back what the read answered, nor put the switch back whatever
// happens, and the storefront tests after this one read that page.
$getGeneral = $registry->get('somcp_get_general_settings');
$updateGeneral = $registry->get('somcp_update_general_settings');
if ($getGeneral !== null && $updateGeneral !== null) {
    $idShopGeneral = (int) $context->shop->id;
    $idGroupGeneral = (int) Shop::getGroupFromShop($idShopGeneral);
    $suppliersWas = Configuration::get('PS_DISPLAY_SUPPLIERS', null, $idGroupGeneral, $idShopGeneral);
    $generalContext = static function (SomcpTool $tool, array $arguments) use ($idShopGeneral, $employee, $context): array {
        return [
            'id_shop' => $idShopGeneral,
            'id_employee' => (int) $employee->id,
            'id_lang' => (int) $context->language->id,
            'id_client' => 0,
            'scopes' => implode(' ', $tool->getRequiredScopes()),
            'responses' => [],
            'resumed' => [],
            'capabilities' => [],
            'arguments' => $arguments,
        ];
    };
    $calls += 4;
    try {
        $read = $getGeneral->call([], $generalContext($getGeneral, []));
        $decoded = json_decode((string) ($read['content'][0]['text'] ?? ''), true);
        if (!empty($read['isError']) || !is_array($decoded) || !array_key_exists('display_suppliers', $decoded)) {
            $failures[] = 'somcp_update_general_settings (the settings read first): expected them read - ' . excerpt($read);
        } else {
            $suppliers = (bool) $decoded['display_suppliers'];
            $steps = [
                ['the read given back whole, nothing written', $decoded, ['"changed": false', 'Nothing was written.']],
                ['Display suppliers turned over', ['display_suppliers' => !$suppliers], ['"changed": true', '"field": "display_suppliers"', '"new": ' . ($suppliers ? 'false' : 'true')]],
                ['Display suppliers put back', ['display_suppliers' => $suppliers], ['"changed": true', '"field": "display_suppliers"', '"new": ' . ($suppliers ? 'true' : 'false')]],
            ];
            foreach ($steps as [$why, $arguments, $needles]) {
                $result = $updateGeneral->call($arguments, $generalContext($updateGeneral, $arguments));
                $text = '';
                foreach ($result['content'] ?? [] as $block) {
                    $text .= (string) ($block['text'] ?? '') . "\n";
                }
                $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                    return strpos($text, $needle) === false;
                });
                if (!empty($result['isError']) || $missing !== []) {
                    $failures[] = 'somcp_update_general_settings (' . $why . '): expected ok with ' . implode(', ', $missing) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_general_settings (' . $why . ')');
                }
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_general_settings: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        // Whatever happened, the tools after this one find the switch of a
        // fresh shop; a key it never had stays unwritten.
        if ($suppliersWas !== false) {
            Configuration::updateValue('PS_DISPLAY_SUPPLIERS', $suppliersWas, false, $idGroupGeneral, $idShopGeneral);
        }
    }
}

// The maintenance settings (#388): the read given back whole writes nothing,
// then the text of the default language is changed and put back. Enable
// store is never turned off here: the storefront tests after this one read
// the shop as a visitor.
$getMaintenance = $registry->get('somcp_get_maintenance_settings');
$updateMaintenance = $registry->get('somcp_update_maintenance_settings');
if ($getMaintenance !== null && $updateMaintenance !== null) {
    $idShopMaintenance = (int) $context->shop->id;
    $idGroupMaintenance = (int) Shop::getGroupFromShop($idShopMaintenance);
    $idLangMaintenance = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShopMaintenance);
    $isoMaintenance = Tools::strtolower((string) Language::getIsoById($idLangMaintenance));
    $textWas = Configuration::get('PS_MAINTENANCE_TEXT', $idLangMaintenance, $idGroupMaintenance, $idShopMaintenance);
    $maintenanceContext = static function (SomcpTool $tool, array $arguments) use ($idShopMaintenance, $employee, $context): array {
        return [
            'id_shop' => $idShopMaintenance,
            'id_employee' => (int) $employee->id,
            'id_lang' => (int) $context->language->id,
            'id_client' => 0,
            'scopes' => implode(' ', $tool->getRequiredScopes()),
            'responses' => [],
            'resumed' => [],
            'capabilities' => [],
            'arguments' => $arguments,
        ];
    };
    $calls += 4;
    try {
        $read = $getMaintenance->call([], $maintenanceContext($getMaintenance, []));
        $decoded = json_decode((string) ($read['content'][0]['text'] ?? ''), true);
        if (!empty($read['isError']) || !is_array($decoded) || !isset($decoded['maintenance_text'][$isoMaintenance])) {
            $failures[] = 'somcp_update_maintenance_settings (the settings read first): expected them read - ' . excerpt($read);
        } else {
            $original = (string) $decoded['maintenance_text'][$isoMaintenance];
            $steps = [
                ['the read given back whole, nothing written', $decoded, ['"changed": false', 'Nothing was written.']],
                ['the text of the default language changed', ['languages' => [$isoMaintenance], 'maintenance_text' => 'Back soon, the shop tests are running.'], ['"changed": true', '"field": "maintenance_text (' . $isoMaintenance . ')"', 'Back soon, the shop tests are running.']],
                ['the text put back', ['languages' => [$isoMaintenance], 'maintenance_text' => [$isoMaintenance => $original]], ['"changed": true', '"field": "maintenance_text (' . $isoMaintenance . ')"']],
            ];
            foreach ($steps as [$why, $arguments, $needles]) {
                $result = $updateMaintenance->call($arguments, $maintenanceContext($updateMaintenance, $arguments));
                $text = '';
                foreach ($result['content'] ?? [] as $block) {
                    $text .= (string) ($block['text'] ?? '') . "\n";
                }
                $missing = array_filter($needles, static function (string $needle) use ($text): bool {
                    return strpos($text, $needle) === false;
                });
                if (!empty($result['isError']) || $missing !== []) {
                    $failures[] = 'somcp_update_maintenance_settings (' . $why . '): expected ok with ' . implode(', ', $missing) . ' - ' . excerpt($result);
                } else {
                    printf("  %-6s %s\n", 'ok', 'somcp_update_maintenance_settings (' . $why . ')');
                }
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_update_maintenance_settings: ' . get_class($e) . ' - ' . $e->getMessage();
    } finally {
        // Whatever happened, the tools after this one find the text of a
        // fresh shop; a text it never had stays unwritten.
        if ($textWas !== false) {
            Configuration::updateValue('PS_MAINTENANCE_TEXT', [$idLangMaintenance => (string) $textWas], true, $idGroupMaintenance, $idShopMaintenance);
        }
    }
}

// A supplier description too long for its column (#333): a JSON scenario
// cannot hold a 65 KB string in a readable way. On PrestaShop 8 the class
// declares no size and the text column would cut the description without a
// word, so the tool refuses it with the reason; on 9 the declared size keeps
// it in. The name, address and city are left out, so neither version creates
// anything and nothing is left behind.
$createSupplier = $registry->get('somcp_create_supplier');
if ($createSupplier !== null) {
    $calls += 1;
    try {
        $arguments = ['languages' => ['en'], 'description' => ['en' => '<p>' . str_repeat('a', 65536) . '</p>']];
        $result = $createSupplier->call($arguments, [
            'id_shop' => (int) $context->shop->id,
            'id_employee' => (int) $employee->id,
            'id_lang' => (int) $context->language->id,
            'id_client' => 0,
            'scopes' => implode(' ', $createSupplier->getRequiredScopes()),
            'responses' => [],
            'resumed' => [],
            'capabilities' => [],
            'arguments' => $arguments,
        ]);
        $text = '';
        foreach ($result['content'] ?? [] as $block) {
            $text .= (string) ($block['text'] ?? '') . "\n";
        }
        $needles = ['Nothing was created', 'name: required, the supplier name'];
        if (empty(Supplier::$definition['fields']['description']['size'])) {
            $needles[] = 'description (en): longer than the 65535 bytes this shop can store';
        }
        $missing = array_filter($needles, static function (string $needle) use ($text): bool {
            return strpos($text, $needle) === false;
        });
        if (empty($result['isError']) || $missing !== []) {
            $failures[] = 'somcp_create_supplier (a description too long for the shop): expected a refusal with ' . implode(', ', $missing) . ' - ' . excerpt($result);
        } else {
            printf("  %-6s %s\n", 'error', 'somcp_create_supplier (a description too long for the shop, refused)');
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_create_supplier (a description too long for the shop): ' . get_class($e) . ' - ' . $e->getMessage();
    }
}

// The same for a product description (#334): the missing price keeps 9.0.0
// from creating a product, so nothing is left behind on either version.
$createProduct = $registry->get('somcp_create_product');
if ($createProduct !== null) {
    $calls += 1;
    try {
        $arguments = [
            'languages' => ['en'],
            'basics' => ['name' => ['en' => 'Somcp long description'], 'description' => ['en' => '<p>' . str_repeat('a', 65536) . '</p>']],
            'pricing' => [],
        ];
        $result = $createProduct->call($arguments, [
            'id_shop' => (int) $context->shop->id,
            'id_employee' => (int) $employee->id,
            'id_lang' => (int) $context->language->id,
            'id_client' => 0,
            'scopes' => implode(' ', $createProduct->getRequiredScopes()),
            'responses' => [],
            'resumed' => [],
            'capabilities' => [],
            'arguments' => $arguments,
        ]);
        $text = '';
        foreach ($result['content'] ?? [] as $block) {
            $text .= (string) ($block['text'] ?? '') . "\n";
        }
        $needles = ['pricing.price_tax_excl: required, the selling price tax excluded'];
        if (empty(Product::$definition['fields']['description']['size'])) {
            $needles[] = 'basics.description (en): longer than the 65535 bytes this shop can store';
        }
        $missing = array_filter($needles, static function (string $needle) use ($text): bool {
            return strpos($text, $needle) === false;
        });
        if (empty($result['isError']) || $missing !== []) {
            $failures[] = 'somcp_create_product (a description too long for the shop): expected a refusal with ' . implode(', ', $missing) . ' - ' . excerpt($result);
        } else {
            printf("  %-6s %s\n", 'error', 'somcp_create_product (a description too long for the shop, refused)');
        }
    } catch (Throwable $e) {
        $failures[] = 'somcp_create_product (a description too long for the shop): ' . get_class($e) . ' - ' . $e->getMessage();
    }
}

// A category deleted with a subcategory and a product, once per mode (#227):
// the JSON scenario only reaches categories with neither, so the reading of
// the subcategories and the settling of the products never ran. The tree is
// built here with the core's own objects, since its identifiers differ from
// one version to the other; the product sits in the subcategory only, so it
// is left in no category once the parent goes.
$deleteCategory = $registry->get('somcp_delete_category');
if ($deleteCategory !== null) {
    $idLangTree = (int) Configuration::get('PS_LANG_DEFAULT');
    $idShopTree = (int) $context->shop->id;
    $newCategory = static function (string $name, int $idParent) use ($idLangTree, $idShopTree): int {
        $category = new Category();
        $category->name = [$idLangTree => $name];
        $category->link_rewrite = [$idLangTree => Tools::str2url($name)];
        $category->id_parent = $idParent;
        $category->active = true;
        $category->id_shop_list = [$idShopTree];

        return $category->add() ? (int) $category->id : 0;
    };
    $expected = [
        'associate_only' => ['products_moved'],
        'associate_and_disable' => ['products_moved', 'products_disabled'],
        'remove_associated' => ['products_deleted'],
    ];
    foreach ($expected as $mode => $lists) {
        ++$calls;
        $label = "somcp_delete_category (a subcategory and a product, $mode)";
        $idParentTree = $newCategory('Somcp tree ' . $mode, (int) $context->shop->id_category);
        $idChildTree = $idParentTree > 0 ? $newCategory('Somcp branch ' . $mode, $idParentTree) : 0;
        $product = new Product();
        $product->name = [$idLangTree => 'Somcp leaf ' . $mode];
        $product->link_rewrite = [$idLangTree => Tools::str2url('Somcp leaf ' . $mode)];
        $product->price = 1;
        $product->active = true;
        $product->id_category_default = $idChildTree;
        $product->id_shop_list = [$idShopTree];
        if ($idChildTree === 0 || !$product->add() || !$product->addToCategories([$idChildTree])) {
            $failures[] = "$label: the category tree or its product could not be built";
            continue;
        }
        $idLeaf = (int) $product->id;
        // Adding the subcategory loaded the parent through calcLevelDepth()
        // before the tree was renumbered, and the core keeps that copy for the
        // rest of the process. A real call runs in a request of its own, so
        // the copy is dropped here for the tool to read the tree as stored.
        Cache::clean('objectmodel_Category_' . $idParentTree . '_*');
        Cache::clean('objectmodel_Category_' . $idChildTree . '_*');

        try {
            $arguments = ['id_category' => $idParentTree, 'products' => $mode];
            $result = $deleteCategory->call($arguments, [
                'id_shop' => $idShopTree,
                'id_employee' => (int) $employee->id,
                'id_lang' => (int) $context->language->id,
                'id_client' => 0,
                'scopes' => implode(' ', $deleteCategory->getRequiredScopes()),
                'responses' => [],
                'resumed' => [],
                'capabilities' => [],
                'arguments' => $arguments,
            ]);
            $text = '';
            foreach ($result['content'] ?? [] as $block) {
                $text .= (string) ($block['text'] ?? '') . "\n";
            }
            $answer = json_decode(trim($text), true);
            $problems = [];
            if (!empty($result['isError']) || !is_array($answer)) {
                $problems[] = 'not deleted';
            } else {
                if (array_column((array) ($answer['deleted_subcategories'] ?? []), 'id_category') !== [$idChildTree]) {
                    $problems[] = "deleted_subcategories is not [$idChildTree]";
                }
                foreach (['products_moved', 'products_disabled', 'products_deleted'] as $list) {
                    $listed = in_array($idLeaf, array_map('intval', array_column((array) ($answer[$list] ?? []), 'id_product')), true);
                    if ($listed !== in_array($list, $lists, true)) {
                        $problems[] = "product $idLeaf " . ($listed ? 'listed' : 'missing') . " in $list";
                    }
                }
            }
            if ($problems !== []) {
                $failures[] = "$label: " . implode(', ', $problems) . ' - ' . excerpt($result);
            } else {
                printf("  %-6s %s\n", 'ok', $label);
            }
        } catch (Throwable $e) {
            $failures[] = "$label: " . get_class($e) . ' - ' . $e->getMessage();
        }
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

        // Only three tools are meant to ask for no back-office right: the
        // connection check, which reads no shop data, and the two file tools,
        // whose handlers check the rights of what they reach - the tool a
        // deposit is for, the document a reference points at.
        if ($required === [] && !in_array($name, ['somcp_ping', 'somcp_upload_file', 'somcp_download_file'], true)) {
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
        // Once decided, a change shows what was decided: a step left on the
        // clock reads as still waiting, after the employee already answered.
        ++$assistantChecks;
        $shown = [];
        foreach ($view['messages'] as $drawn) {
            foreach ($drawn['tools'] ?? [] as $tool) {
                $shown[] = (string) $tool['approval'];
            }
        }
        if (!in_array('rejected', $shown, true) || !in_array('approved', $shown, true) || in_array('pending', $shown, true)) {
            $failures[] = 'assistant: decided changes are drawn as ' . implode(', ', array_filter($shown)) . ' instead of one rejected and one approved';
        }

        // Ownership: the same thread id, asked for by another employee.
        ++$assistantChecks;
        $other = new Employee($nobody > 0 ? $nobody : 999999);
        if ((new SomcpAssistantChat($other, (int) $context->shop->id))->thread((string) $thread['thread_id']) !== null) {
            $failures[] = 'assistant: another employee could open the conversation';
        }

        // The reads of the page the chat is open on come with the first
        // message, its writes stay behind the finder, and another page gets
        // none of them. An employee without the right on the page gets none
        // either: they go through the same rights check as any tool.
        $firstOffer = static function (string $page) use ($employee, $context): array {
            $fake = new NeuronAI\Testing\FakeAIProvider(new NeuronAI\Chat\Messages\AssistantMessage('Seen.'));
            SomcpAssistantChat::overrideProvider($fake);
            $on = new SomcpAssistantChat($employee, (int) $context->shop->id);
            $on->openOn($page);
            $opened = $on->newThread(0);
            $on->send($opened, 'What is this?');
            $on->deleteThread($opened);

            return array_map(static function ($tool): string {
                return (string) $tool->getName();
            }, $fake->getRecorded()[0]->tools ?? []);
        };
        $pageReads = SomcpToolRegistry::getInstance()->forPage('AdminOrders');
        ++$assistantChecks;
        if (!in_array('somcp_get_order', $pageReads, true) || !in_array('somcp_search_orders', $pageReads, true) || in_array('somcp_set_order_status', $pageReads, true)) {
            $failures[] = 'assistant: the reads of the orders page are ' . implode(', ', $pageReads);
        }
        ++$assistantChecks;
        $names = $firstOffer('AdminOrders');
        if (!in_array('somcp_get_order', $names, true) || in_array('somcp_set_order_status', $names, true)) {
            $failures[] = 'assistant: a chat opened on the orders page first offers ' . implode(', ', $names);
        }
        ++$assistantChecks;
        $names = $firstOffer('AdminDashboard');
        if (in_array('somcp_get_order', $names, true)) {
            $failures[] = 'assistant: a chat opened on the dashboard offers the reads of the orders page';
        }
        ++$assistantChecks;
        foreach ($pageReads as $name) {
            $read = SomcpToolRegistry::getInstance()->get($name);
            if ($nobody > 0 && $read !== null && $read->isAvailableTo(implode(' ', $read->getRequiredScopes()), $nobody)) {
                $failures[] = "assistant: $name of the orders page is offered to an employee without the right";
            }
        }
    } catch (Throwable $e) {
        $failures[] = 'assistant: ' . get_class($e) . ' - ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    $chat->deleteThread($thread);

    // The AI fails right after an approved change ran - a 503 from a busy
    // provider. The change is done; its result must be kept all the same, or
    // the conversation ends on a call without an answer and every later
    // message, whatever the assistant, is refused. The next message must be
    // answered, the change must not run a second time, and the sentence the
    // engine writes for the lost answer must not reach the employee.
    $other = $status === 8 ? 6 : 8;
    $failing = new class($call(SomcpAssistantChat::FIND_TOOL, 'call_find', ['load' => ['somcp_set_order_status']]), $call('somcp_set_order_status', 'call_busy', ['id_order' => 2, 'status_id' => $other]), new NeuronAI\Chat\Messages\AssistantMessage('Back again.')) extends NeuronAI\Testing\FakeAIProvider {
        /** @var int */
        public $calls = 0;

        public function chat(NeuronAI\Chat\Messages\Message ...$messages): NeuronAI\Providers\ProviderResponse
        {
            $this->busy();

            return parent::chat(...$messages);
        }

        public function stream(NeuronAI\Chat\Messages\Message ...$messages): Generator
        {
            $this->busy();

            return yield from parent::stream(...$messages);
        }

        // The third call is the one that follows the approved change.
        private function busy(): void
        {
            if (++$this->calls === 3) {
                throw new NeuronAI\Exceptions\ProviderException('HTTP 503: the model is overloaded');
            }
        }
    };
    SomcpAssistantChat::overrideProvider($failing);
    $thread = $chat->newThread(0);
    $changes = static function (): int {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_history` WHERE `id_order` = 2');
    };
    try {
        $before = $changes();
        $chat->send($thread, 'Change order 2.');
        $view = $chat->step($thread);
        $expect($view, 'approval', 'the change asks for approval before the AI fails');
        $chat->decide($thread, ['call_busy' => 'approve']);
        $chat->step($thread);
        $view = $chat->send($thread, 'Are you there?');
        $expect($view, 'idle', 'a message after the AI failed is answered');
        ++$assistantChecks;
        $last = end($view['messages']);
        if (!is_array($last) || $last['text'] !== 'Back again.') {
            $failures[] = 'assistant: after the AI failed, the answer to the next message is missing';
        }
        ++$assistantChecks;
        foreach ($view['messages'] as $drawn) {
            if (($drawn['text'] ?? '') === SomcpAssistantChat::INTERRUPTED_ANSWER) {
                $failures[] = 'assistant: the engine\'s English sentence for the lost answer is shown to the employee';
            }
        }
        ++$assistantChecks;
        if ($changes() !== $before + 1) {
            $failures[] = 'assistant: the approved change ran ' . ($changes() - $before) . ' times instead of once';
        }
    } catch (Throwable $e) {
        $failures[] = 'assistant: ' . get_class($e) . ' - ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    $chat->deleteThread($thread);
    $restore = new Order(2);
    if ((int) $restore->current_state !== $status) {
        $restore->setCurrentState($status);
    }

    // A model picked in the chat is kept only when the assistant offers it:
    // the page sends a name, and a name nobody checked would be run as is.
    // Its default model is not kept either, so it follows the settings.
    Db::getInstance()->update('somcp_assistant', [
        'models' => pSQL((string) json_encode(['claude-shop-other', 'claude-shop-test'])),
        'models_at' => date('Y-m-d H:i:s'),
    ], '`id_assistant` = ' . (int) $idAssistant);
    $picked = static function (array $thread): string {
        return (string) Db::getInstance()->getValue('SELECT `model` FROM `' . _DB_PREFIX_ . 'somcp_assistant_thread` WHERE `id_thread` = ' . (int) $thread['id_thread']);
    };
    $thread = $chat->newThread($idAssistant, 'claude-shop-other');
    foreach ([
        ['claude-shop-other', 'a model the assistant offers is kept', null],
        ['', 'a model the assistant does not offer is refused', 'claude-forged'],
        ['', 'the default model is not kept as a pick', 'claude-shop-test'],
    ] as [$expected, $label, $asked]) {
        if ($asked !== null) {
            $thread = $chat->useAssistant($thread, $idAssistant, $asked);
        }
        ++$assistantChecks;
        if ($picked($thread) !== $expected) {
            $failures[] = "assistant: $label - the conversation holds '" . $picked($thread) . "'";
        }
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
        if (SomcpModuleSettings::write(SomcpModuleSettings::TOOL_PREFIX . 'somcp_list_order_statuses', 'off', (int) $context->shop->id) !== ''
            || SomcpToolRegistry::isEnabled('somcp_list_order_statuses')
            || SomcpModuleSettings::write(SomcpModuleSettings::TOOL_PREFIX . 'somcp_list_order_statuses', 'on', (int) $context->shop->id) !== ''
            || !SomcpToolRegistry::isEnabled('somcp_list_order_statuses')) {
            $failures[] = 'assistant: a tool could not be switched off and on again from the chat';
        }
        // Test the connection has no switch on the page, and the chat may not
        // switch off what the page does not offer.
        ++$assistantChecks;
        if (SomcpModuleSettings::write(SomcpModuleSettings::TOOL_PREFIX . 'somcp_ping', 'off', (int) $context->shop->id) === ''
            || !SomcpToolRegistry::isEnabled('somcp_ping')) {
            $failures[] = 'assistant: somcp_ping could be switched off from the chat';
        }
        // The two columns of the Tools and prompts tab are two lists: off for
        // the built-in assistant leaves the tool to the connected assistants,
        // and the other way round.
        ++$assistantChecks;
        $idShop = (int) $context->shop->id;
        $builtinOff = SomcpModuleSettings::write(SomcpModuleSettings::BUILTIN_TOOL_PREFIX . 'somcp_list_order_statuses', 'off', $idShop) === ''
            && SomcpToolRegistry::isEnabled('somcp_list_order_statuses')
            && !SomcpToolRegistry::isEnabled('somcp_list_order_statuses', true);
        $mcpOff = SomcpModuleSettings::write(SomcpModuleSettings::BUILTIN_TOOL_PREFIX . 'somcp_list_order_statuses', 'on', $idShop) === ''
            && SomcpModuleSettings::write(SomcpModuleSettings::TOOL_PREFIX . 'somcp_list_order_statuses', 'off', $idShop) === ''
            && !SomcpToolRegistry::isEnabled('somcp_list_order_statuses')
            && SomcpToolRegistry::isEnabled('somcp_list_order_statuses', true);
        SomcpModuleSettings::write(SomcpModuleSettings::TOOL_PREFIX . 'somcp_list_order_statuses', 'on', $idShop);
        $promptOff = SomcpModuleSettings::write(SomcpModuleSettings::BUILTIN_PROMPT_PREFIX . 'somcp-connection-check', 'off', $idShop) === ''
            && SomcpPromptRegistry::isEnabled('somcp-connection-check')
            && !SomcpPromptRegistry::isEnabled('somcp-connection-check', true);
        SomcpModuleSettings::write(SomcpModuleSettings::BUILTIN_PROMPT_PREFIX . 'somcp-connection-check', 'on', $idShop);
        if (!$builtinOff || !$mcpOff || !$promptOff) {
            $failures[] = 'assistant: a tool or prompt switched off in one column of Tools and prompts was switched off in the other too';
        }
        // A whole group: the scope switched off holds back its tools and the
        // prompts needing it in that column only, leaves their own switches
        // as they were, and drops out of what the consent screen offers.
        // Opening a session is not a group anyone can switch off.
        ++$assistantChecks;
        $groupOff = SomcpModuleSettings::write(SomcpModuleSettings::SCOPE_PREFIX . SomcpScope::ORDERS_READ, 'off', $idShop) === ''
            && !SomcpToolRegistry::isEnabled('somcp_search_orders')
            && SomcpToolRegistry::isSwitchedOn('somcp_search_orders')
            && SomcpToolRegistry::isEnabled('somcp_search_orders', true)
            && !SomcpPromptRegistry::isEnabled('somcp-order-lookup')
            && SomcpPromptRegistry::isEnabled('somcp-order-lookup', true)
            && !in_array(SomcpScope::ORDERS_READ, SomcpScope::grantableFor((int) $employee->id, $idShop), true)
            && in_array(SomcpScope::ORDERS_READ, SomcpScope::grantableFor((int) $employee->id, $idShop, true), true);
        $groupOn = SomcpModuleSettings::write(SomcpModuleSettings::SCOPE_PREFIX . SomcpScope::ORDERS_READ, 'on', $idShop) === ''
            && SomcpToolRegistry::isEnabled('somcp_search_orders')
            && SomcpPromptRegistry::isEnabled('somcp-order-lookup');
        $builtinGroup = SomcpModuleSettings::write(SomcpModuleSettings::BUILTIN_SCOPE_PREFIX . SomcpScope::ORDERS_READ, 'off', $idShop) === ''
            && SomcpToolRegistry::isEnabled('somcp_search_orders')
            && !SomcpToolRegistry::isEnabled('somcp_search_orders', true);
        SomcpModuleSettings::write(SomcpModuleSettings::BUILTIN_SCOPE_PREFIX . SomcpScope::ORDERS_READ, 'on', $idShop);
        $accessKept = SomcpModuleSettings::write(SomcpModuleSettings::SCOPE_PREFIX . SomcpScope::ACCESS, 'off', $idShop) !== ''
            && SomcpToolRegistry::isEnabled('somcp_ping');
        if (!$groupOff || !$groupOn || !$builtinGroup || !$accessKept) {
            $failures[] = 'assistant: a group of tools switched off by its scope did not hold back exactly its tools and prompts in its column, or the connection itself could be switched off';
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
        // The memory is never switched on without a provider the employee
        // chose: the chat must ask, not pick one, even when one is offered.
        ++$assistantChecks;
        $providerWas = SomcpAssistantMemory::providerId((int) $context->shop->id);
        Configuration::updateValue(SomcpAssistantMemory::PROVIDER, '');
        if (SomcpModuleSettings::write(SomcpAssistantMemory::ON, 'on', (int) $context->shop->id) === ''
            || SomcpAssistantMemory::providerId((int) $context->shop->id) !== '') {
            $failures[] = 'assistant: the memory was switched on, or given a provider, without the employee choosing one';
        }
        Configuration::updateValue(SomcpAssistantMemory::PROVIDER, $providerWas);
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

    // A local assistant, through O'So AI Relay. A code pairs once, in any case
    // and with or without its dash; the token reports the models and only
    // those that passed the tool test can be chosen; a request the chat
    // queues is taken once, answered and read back; a relay gone quiet fails
    // the chat at once instead of keeping it waiting; and removing the
    // assistant revokes the token.
    $relayCheck = static function (bool $ok, string $label) use (&$failures, &$assistantChecks): void {
        ++$assistantChecks;
        if (!$ok) {
            $failures[] = 'local assistant: ' . $label;
        }
    };
    $idLocal = 0;
    try {
        $idRelay = SomcpRelay::createPairing();
        $relay = SomcpRelay::get($idRelay);
        $code = (string) ($relay['code'] ?? '');
        $relayCheck((bool) preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code), 'the pairing code is not shown as XXXX-XXXX');
        $token = SomcpRelay::pair(strtolower(str_replace('-', '', $code)), '0.1.0', 'linux/amd64');
        $relayCheck($token !== '', 'a valid code typed in lower case without its dash did not pair');
        $relayCheck(SomcpRelay::pair($code, '0.1.0', 'linux/amd64') === '', 'a pairing code worked twice');
        $relayCheck((SomcpRelay::authenticate($token)['id_relay'] ?? 0) === $idRelay, 'the token does not lead back to its relay');
        $relayCheck(SomcpRelay::authenticate('wrong') === null, 'an unknown token was accepted');

        SomcpRelay::hello($idRelay, ['software' => 'Ollama', 'models' => [
            ['id' => 'qwen2.5:7b', 'tools' => true],
            ['id' => 'gemma2:2b', 'tools' => false],
            ['id' => 'untested'],
        ]]);
        $relay = SomcpRelay::get($idRelay);
        $relayCheck($relay !== null && SomcpRelay::toolModels($relay) === ['qwen2.5:7b'], 'models without a passed tool test are offered');
        $local = ['model' => 'gemma2:2b', 'role' => '', 'active' => true, 'token_cap' => 0];
        $relayCheck(SomcpAssistant::saveLocal(0, $idRelay, $local) === 0, 'a model that failed the tool test was saved');
        $idLocal = SomcpAssistant::saveLocal(0, $idRelay, ['model' => 'qwen2.5:7b'] + $local);
        $saved = SomcpAssistant::edited($idLocal);
        $relayCheck($saved !== null && $saved['local'] && $saved['label'] === 'Ollama' && $saved['online'], 'the local assistant is not saved online under the name of its AI');

        // Nobody chatting: the relays may rest; a queued request wakes them.
        SomcpRelay::purge();
        $relayCheck(!SomcpRelay::awake(), 'the relays stay awake with nobody chatting');
        $key = SomcpRelay::enqueue($idRelay, ['model' => 'qwen2.5:7b', 'messages' => [['role' => 'user', 'content' => 'Hello']]]);
        $relayCheck(SomcpRelay::awake(), 'a request from the chat did not wake the relays');
        $job = SomcpRelay::claim($idRelay);
        $relayCheck(($job['id'] ?? '') === $key && ($job['request']['model'] ?? '') === 'qwen2.5:7b', 'the relay did not get the queued request');
        $relayCheck(SomcpRelay::claim($idRelay) === null, 'one request was handed out twice');
        $relayCheck(SomcpRelay::complete($idRelay, $key, 200, '{"choices":[{"message":{"role":"assistant","content":"Hi"}}]}', ''), 'the answer was refused');
        $answer = SomcpRelay::wait($key, 2.0);
        $relayCheck(($answer['status'] ?? 0) === 200 && strpos((string) ($answer['body'] ?? ''), '"Hi"') !== false, 'the chat did not read the answer back');

        // Quiet for two hours: the chat says so rather than wait for nobody.
        Db::getInstance()->update('somcp_relay', ['last_seen' => date('Y-m-d H:i:s', time() - 7200)], '`id_relay` = ' . $idRelay);
        $localThread = $chat->newThread($idLocal);
        $started = microtime(true);
        $view = $chat->send($localThread, 'Hello');
        $relayCheck($view['error'] !== '' && microtime(true) - $started < 10, 'an offline relay did not fail the chat at once');
        $relayCheck((int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'somcp_relay_job` WHERE `id_relay` = ' . $idRelay, false) === 0, 'a request was queued for an offline relay');
        $chat->deleteThread($localThread);

        SomcpAssistant::delete($idLocal);
        $idLocal = 0;
        $relayCheck(SomcpRelay::authenticate($token) === null, 'removing the local assistant did not revoke its relay');
    } catch (Throwable $e) {
        $failures[] = 'local assistant: ' . get_class($e) . ' - ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    if ($idLocal > 0) {
        SomcpAssistant::delete($idLocal);
    }
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
