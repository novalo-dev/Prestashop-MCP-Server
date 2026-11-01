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
 * Usage: php .github/scripts/check-tools.php [module root]
 * Exit code 0 when everything is in order, 1 otherwise.
 */

$root = rtrim($argv[1] ?? getcwd(), '/');
$errors = [];

// 1. The tools the module ships: the name each file in tools/ declares.
$shipped = [];
foreach (glob($root . '/tools/Somcp*.php') ?: [] as $file) {
    // The name is the first argument of the definition: the first string in
    // the file that is nothing but a tool name. Descriptions mention other
    // tools inside longer sentences, never as a literal of their own.
    if (preg_match("/^\\s*'(somcp_[a-z0-9_]+)',?\\s*$/m", (string) file_get_contents($file), $m)) {
        $shipped[$m[1]] = 'tools/' . basename($file);
    } else {
        $errors[] = 'tools/' . basename($file) . ': no tool name found';
    }
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
    if (!isset($shipped[$tool])) {
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
    "Tools OK: %d shipped, each in README.md, docs/TOOLS.md, SomcpScope, CHANGELOG.md and a prompt; %d more specified.\n",
    count($shipped),
    count($entries) - count($shipped)
);
exit(0);
