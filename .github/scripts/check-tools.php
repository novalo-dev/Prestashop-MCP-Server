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
 * CI check: every tool the module ships is documented everywhere a reader
 * looks for it, and nothing documents a tool that does not exist.
 *
 * A tool lives in five places besides its own file: the table in README.md,
 * its entry in docs/TOOLS.md, its scope in SomcpScope, a line in the
 * CHANGELOG, and at least one prompt that tells the assistant how to use it.
 * Each one is easy to forget in a pull request and none of them fails on its
 * own, so they are checked together here.
 *
 * A prompt also names the icon the built-in chat draws beside it. The chat
 * falls back to its default icon for a name it does not know, so a missing or
 * misspelt one would never fail anywhere else.
 *
 * Tools also point to each other: every tool a file names must exist, without
 * hedging the link, and every write must name a read that shows its outcome.
 * Their texts stay within the description budget, but for a listed few.
 *
 * Usage: php .github/scripts/check-tools.php [module root]
 * Exit code 0 when everything is in order, 1 otherwise.
 */

$root = rtrim($argv[1] ?? getcwd(), '/');
$errors = [];

// 1. The tools the module ships: the name each file in tools/ declares.
$shipped = [];
$sources = [];
foreach (glob($root . '/tools/Somcp*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);
    // The name is the first argument of the definition: the first string in
    // the file that is nothing but a tool name. Descriptions mention other
    // tools inside longer sentences, never as a literal of their own.
    if (preg_match("/^\\s*'(somcp_[a-z0-9_]+)',?\\s*$/m", $source, $m)) {
        $shipped[$m[1]] = 'tools/' . basename($file);
        $sources[$m[1]] = $source;
    } else {
        $errors[] = 'tools/' . basename($file) . ': no tool name found';
    }
}

// The chat's own tools, declared in SomcpAssistantChat rather than tools/:
// the README lists them in a table of their own, so they count as shipped
// there, and only there - they have no scope, prompt or MCP entry.
$chatOnly = [];
preg_match_all("/public const [A-Z_]+_TOOL = '(somcp_[a-z0-9_]+)'/", (string) @file_get_contents($root . '/classes/SomcpAssistantChat.php'), $m);
foreach ($m[1] as $tool) {
    $chatOnly[$tool] = true;
}

// 2. README.md: the table under "## Tools".
$readme = (string) @file_get_contents($root . '/README.md');
$listed = [];
if (preg_match('/^## Tools\s*$(.*?)^## /ms', $readme, $section)) {
    preg_match_all('/^\|\s*`(somcp_[a-z0-9_]+)`\s*\|/m', $section[1], $m);
    $listed = array_flip($m[1]);
} else {
    $errors[] = 'README.md: no "## Tools" section';
}

// 3. docs/TOOLS.md: each entry and its state.
$doc = (string) @file_get_contents($root . '/docs/TOOLS.md');
$entries = [];
preg_match_all('/^#### `(somcp_[a-z0-9_]+)`\s*\n\*\*State\*\*: ([a-z ]+?)(?: ·|\s*$).*?\n\*\*Nature\*\*:.*?\*\*Scope\*\*: `([a-z0-9.]+)`/m', $doc, $m, PREG_SET_ORDER);
foreach ($m as $entry) {
    $entries[$entry[1]] = ['state' => trim($entry[2]), 'scope' => $entry[3]];
}

// 4. The scopes the module declares.
$scopes = (string) @file_get_contents($root . '/classes/SomcpScope.php');

// 5. The CHANGELOG.
$changelog = (string) @file_get_contents($root . '/CHANGELOG.md');

// 6. The icons a prompt may name: the keys of PROMPT_ICONS in the chat script,
// read from there so the two can never drift apart.
$icons = [];
$script = (string) @file_get_contents($root . '/views/js/assistant.js');
if (preg_match('/var PROMPT_ICONS = \{(.*?)\n    \};/s', $script, $block)) {
    preg_match_all('/^\s{8}([a-z]+): \[/m', $block[1], $m);
    $icons = array_flip($m[1]);
}
if ($icons === []) {
    $errors[] = 'views/js/assistant.js: no PROMPT_ICONS set found';
}

// 7. The prompts: which tools each one hands to the assistant.
$inPrompts = [];
foreach (glob($root . '/prompts/*/prompt.json') ?: [] as $file) {
    $folder = basename(dirname($file));
    $where = 'prompts/' . $folder;
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        $errors[] = "$where/prompt.json: invalid JSON";
        continue;
    }
    $icon = $data['icon'] ?? null;
    if (!is_string($icon) || $icon === '') {
        $errors[] = "$where/prompt.json: no icon; name one of PROMPT_ICONS in views/js/assistant.js";
    } elseif ($icons !== [] && !isset($icons[$icon])) {
        $errors[] = "$where/prompt.json: icon \"$icon\" is not in PROMPT_ICONS of views/js/assistant.js";
    }
    $guide = (string) @file_get_contents(dirname($file) . '/prompt.md');
    foreach ((array) ($data['tools'] ?? []) as $tool) {
        if (!is_string($tool)) {
            continue;
        }
        $inPrompts[$tool][] = $folder;
        if (strncmp($tool, 'somcp_', 6) === 0 && !isset($shipped[$tool])) {
            $errors[] = "$where/prompt.json: names $tool, which the module does not ship";
        }
        // The guide is what the assistant reads; a tool in the list and not in
        // the text is one it was never told how to use.
        if (strpos($guide, $tool) === false) {
            $errors[] = "$where/prompt.md: never mentions $tool, listed in prompt.json";
        }
    }
    foreach ((array) ($data['scopes'] ?? []) as $scope) {
        if (is_string($scope) && strpos($scopes, "'" . $scope . "'") === false) {
            $errors[] = "$where/prompt.json: scope $scope is not declared in classes/SomcpScope.php";
        }
    }
}

// 8. The links between tools. A tool name in a description or an error is
// where the assistant goes next: a name the module does not ship sends it to
// a call that can only fail, so the pull request that writes a tool is the one
// that adds the links to it. A write must also name a read, the only way to
// check its outcome when its answer was lost, since it is not to be retried.
// The whole file is read, errors included, because the assistant reads those
// too; a family written as somcp_list_* is not a name. Since only shipped
// tools can be named, hedging a link with "when the shop offers it" is never
// true, and the assistant reads it as a tool that may be missing and skips it.
$writes = [];
foreach ($sources as $tool => $source) {
    if (preg_match('/SomcpTool::acts\(\s*SomcpPermission::(?:ADD|EDIT|DELETE)\b/', $source)) {
        $writes[$tool] = true;
    }
}
foreach ($sources as $tool => $source) {
    preg_match_all('/\bsomcp_[a-z0-9_]+(?![a-z0-9_*])/', $source, $m);
    $cited = array_unique($m[0]);
    if (stripos($source, 'when the shop offers') !== false) {
        $errors[] = "{$shipped[$tool]}: $tool hedges a link with \"when the shop offers\"; every tool it names exists, so name it plainly";
    }
    foreach ($cited as $name) {
        if (!isset($shipped[$name])) {
            $errors[] = "{$shipped[$tool]}: $tool names $name, which the module does not ship; leave the link to the pull request that writes it";
        }
    }
    if (isset($writes[$tool])) {
        $reads = array_filter($cited, function ($name) use ($tool, $shipped, $writes) {
            return $name !== $tool && isset($shipped[$name]) && !isset($writes[$name]);
        });
        if ($reads === []) {
            $errors[] = "{$shipped[$tool]}: $tool writes but names no read tool that shows the outcome";
        }
    }
}

// 9. The description budget (see Descriptions in docs/TOOLS.md): 600
// characters for a tool, 200 for a property. Every loaded tool is sent to the
// model on each step of a conversation, so a long text is paid again and
// again, and without a check every new tool could land above it. The texts
// written before the budget and not cut yet are listed below; the list only
// shrinks: a text cut within the budget must leave it, so it cannot grow back
// unnoticed. The sizes come from dev/tools/measure.php, the one measurement
// the pull requests quote.
$overBudgetAllowed = [
    // Tool descriptions, by tool name.
    'somcp_create_product',
    'somcp_update_product',
    // Property descriptions, by tool name and path in the schema.
    'somcp_get_cart_rule.language',
    'somcp_add_order_payment.payment_method',
    'somcp_create_product.virtual_file.file',
    'somcp_create_product.seo.redirect_type',
    'somcp_create_product.seo.tags',
    'somcp_create_product.images',
    'somcp_update_product.basics.product_type',
    'somcp_update_product.features',
    'somcp_update_product.pack.items',
    'somcp_update_product.virtual_file.file',
    'somcp_update_product.specific_prices.rules[].combination',
    'somcp_update_product.seo.link_rewrite',
    'somcp_update_product.seo.redirect_type',
    'somcp_update_product.seo.tags',
    'somcp_update_product.images',
    'somcp_update_product.images[].combinations',
    'somcp_update_product.combinations',
    'somcp_update_product.remove_virtual_file',
];
$measure = $root . '/dev/tools/measure.php';
if (!is_file($measure)) {
    $errors[] = 'dev/tools/measure.php: missing, the description budget cannot be checked';
} else {
    $output = [];
    $status = 1;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($measure) . ' ' . escapeshellarg($root) . ' --json 2>&1', $output, $status);
    $measured = $status === 0 ? json_decode(implode("\n", $output), true) : null;
    if (!is_array($measured) || !isset($measured['tools']) || !is_array($measured['tools'])) {
        $errors[] = 'dev/tools/measure.php: could not measure the tools: ' . implode(' ', array_slice($output, 0, 3));
    } else {
        $allowed = array_fill_keys($overBudgetAllowed, true);
        $stillOver = [];
        foreach ($measured['tools'] as $row) {
            $name = (string) $row['name'];
            $file = $shipped[$name] ?? 'tools/';
            if ((int) $row['description'] > (int) $measured['tool_budget']) {
                $stillOver[$name] = true;
                if (!isset($allowed[$name])) {
                    $errors[] = "$file: the description of $name holds {$row['description']} characters, above the budget of {$measured['tool_budget']}; see Descriptions in docs/TOOLS.md";
                }
            }
            foreach ((array) $row['over'] as $path => $length) {
                $stillOver[$name . $path] = true;
                if (!isset($allowed[$name . $path])) {
                    $errors[] = "$file: the description of property $path of $name holds $length characters, above the budget of {$measured['property_budget']}; see Descriptions in docs/TOOLS.md";
                }
            }
        }
        foreach ($overBudgetAllowed as $entry) {
            if (!isset($stillOver[$entry])) {
                $errors[] = ".github/scripts/check-tools.php: $entry is within the description budget now, remove it from \$overBudgetAllowed";
            }
        }
    }
}

foreach ($shipped as $tool => $file) {
    if (!isset($listed[$tool])) {
        $errors[] = "$file: $tool is missing from the Tools table of README.md";
    }
    if (!isset($entries[$tool])) {
        $errors[] = "$file: $tool has no entry in docs/TOOLS.md";
    } else {
        if (!in_array($entries[$tool]['state'], ['written', 'tested'], true)) {
            $errors[] = "$file: the entry of $tool in docs/TOOLS.md still says \"{$entries[$tool]['state']}\", set it to \"written\"";
        }
        $scope = $entries[$tool]['scope'];
        if (strpos($scopes, "'" . $scope . "'") === false) {
            $errors[] = "$file: scope $scope of $tool is not declared in classes/SomcpScope.php";
        }
    }
    if (strpos($changelog, $tool) === false) {
        $errors[] = "$file: $tool is not mentioned in CHANGELOG.md";
    }
    if (!isset($inPrompts[$tool])) {
        $errors[] = "$file: $tool is in no prompt; add it to the prompt of its section";
    }
}

foreach (array_keys($listed) as $tool) {
    if (!isset($shipped[$tool]) && !isset($chatOnly[$tool])) {
        $errors[] = "README.md: lists $tool, which the module does not ship";
    }
}
foreach ($entries as $tool => $entry) {
    if (in_array($entry['state'], ['written', 'tested'], true) && !isset($shipped[$tool])) {
        $errors[] = "docs/TOOLS.md: $tool says \"{$entry['state']}\" but no file in tools/ declares it";
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    fwrite(STDERR, sprintf("\n%d problem(s). See \"Writing a tool, from A to Z\" in CONTRIBUTING.md.\n", count($errors)));
    exit(1);
}

printf(
    "Tools OK: %d shipped, each in README.md, docs/TOOLS.md, SomcpScope, CHANGELOG.md and a prompt, linked to existing tools only; %d more specified.\n",
    count($shipped),
    count($entries) - count($shipped)
);
exit(0);
