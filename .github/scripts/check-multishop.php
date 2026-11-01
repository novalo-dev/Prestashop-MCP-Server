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
 * CI check: the module is multistore to the letter.
 *
 * Every table the module creates holds rows of one shop, so each one carries
 * an `id_shop` column - unless it is the child of a table that already does,
 * reached only through its parent's id and never on its own. Those children
 * are listed below with the parent that scopes them; anything else without
 * `id_shop` is a row no shop owns, which is the bug this check exists to stop.
 *
 * It also refuses a global Configuration call on one of the built-in
 * assistant's merchant settings: those follow PrestaShop's levels - all shops,
 * a group, a shop - written in the back office context and read for a shop,
 * never through the global calls that skip the levels. Module state and minted
 * secrets are the same on every shop and are left alone - only the merchant
 * settings are checked, so the rule is exact rather than a guess about every
 * global value.
 *
 * Usage: php .github/scripts/check-multishop.php [module root]
 * Exit code 0 when everything is in order, 1 otherwise.
 */

$root = rtrim($argv[1] ?? getcwd(), '/');
$errors = [];

// Children reached only through a parent that carries id_shop: the parent's
// row decides the shop, so the child would only repeat it.
$children = [
    'somcp_upload_file' => 'somcp_upload',
    'somcp_assistant_message' => 'somcp_assistant_thread',
    'somcp_assistant_run' => 'somcp_assistant_thread',
];

// The built-in assistant's merchant settings. Each is something the merchant
// sets on the page for a level and reads for a shop, so a global call on any
// of them is the regression this check guards against. Module state and minted
// secrets are global by nature and are not listed, so they are not flagged.
$merchantSettings = [
    'BUDGET',
    'TOKEN_CAP',
    'INSTRUCTIONS',
    'PROFILE',
];

$sql = (string) @file_get_contents($root . '/sql/install.sql');
if ($sql === '') {
    fwrite(STDERR, "sql/install.sql not found or empty\n");
    exit(1);
}

// 1. Every created table carries id_shop, or is a listed child of one that does.
if (preg_match_all('/CREATE TABLE IF NOT EXISTS `PREFIX_([a-z_]+)` \((.*?)\n\) ENGINE/s', $sql, $tables, PREG_SET_ORDER)) {
    foreach ($tables as $table) {
        [$whole, $name, $body] = $table;
        if (strpos($body, '`id_shop`') !== false) {
            continue;
        }
        if (isset($children[$name])) {
            // The parent must itself be a real, shop-scoped table.
            if (!preg_match('/CREATE TABLE IF NOT EXISTS `PREFIX_' . preg_quote($children[$name], '/') . '` \((.*?)\n\) ENGINE/s', $sql, $parent)
                || strpos($parent[1], '`id_shop`') === false) {
                $errors[] = $name . ': declared a child of ' . $children[$name] . ', but that table has no id_shop';
            }
            continue;
        }
        $errors[] = $name . ': no id_shop column, and not a listed child of a shop-scoped table';
    }
} else {
    $errors[] = 'sql/install.sql: no CREATE TABLE statements found';
}

// 2. None of the assistant's merchant settings is read or written globally.
foreach (array_merge(glob($root . '/classes/*.php') ?: [], glob($root . '/*.php') ?: []) as $file) {
    $code = (string) file_get_contents($file);
    if (preg_match_all('/(?:getGlobalValue|updateGlobalValue)\(\s*(?:self::|SomcpAssistant::)?([A-Z_]+)/', $code, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            if (in_array($hit[1], $merchantSettings, true)) {
                $errors[] = basename($file) . ': ' . $hit[1] . ' is a merchant setting read or written with a global Configuration call; it follows the shop levels (write in the context, read with an id_shop)';
            }
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Multistore check failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

$count = isset($tables) ? count($tables) : 0;
echo 'Multistore OK: ' . $count . ' tables, each shop-scoped or a listed child; no merchant setting stored globally.' . "\n";
exit(0);
