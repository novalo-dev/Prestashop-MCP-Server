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
 * The weight of the tool list, per tool and per back office page.
 *
 * Every tool loaded is sent to the model with its whole describe(), so its
 * size is paid on each step of a conversation. This prints that size (the
 * JSON of describe(), about 4 characters per token), the length of the tool
 * description and the property descriptions above their budget (600 and 200
 * characters, see Descriptions in docs/TOOLS.md), so a change to the texts
 * can quote the size before and after.
 *
 * It runs without a shop: the tools only need their own classes to define
 * themselves, and the title translation is replaced by its English source.
 *
 * Usage: php dev/tools/measure.php <module folder>
 */

const TOOL_BUDGET = 600;
const PROPERTY_BUDGET = 200;

$root = rtrim($argv[1] ?? '.', '/');
if (!is_dir($root . '/tools')) {
    fwrite(STDERR, "Usage: php dev/tools/measure.php <module folder>\n");
    exit(2);
}

define('_PS_VERSION_', '8.0.0');

// The module class needs PrestaShop; the tools only call its translator.
if (!class_exists('Somcp', false)) {
    class Somcp
    {
        /**
         * @param array<string, mixed> $parameters
         */
        public static function t(string $source, array $parameters = []): string
        {
            return $source;
        }
    }
}

spl_autoload_register(function (string $class) use ($root) {
    foreach (['classes', 'tools'] as $dir) {
        $file = $root . '/' . $dir . '/' . $class . '.php';
        if (is_file($file)) {
            require_once $file;

            return;
        }
    }
});

/**
 * Property descriptions above the budget, keyed by their path in the schema.
 *
 * @param array<string, mixed> $schema
 * @param array<string, int> $over
 */
function measureProperties(array $schema, string $path, array &$over): void
{
    if ($path !== '' && isset($schema['description']) && is_string($schema['description'])
        && strlen($schema['description']) > PROPERTY_BUDGET) {
        $over[$path] = strlen($schema['description']);
    }
    foreach ($schema['properties'] ?? [] as $name => $sub) {
        if (is_array($sub)) {
            measureProperties($sub, $path . '.' . $name, $over);
        }
    }
    if (isset($schema['items']) && is_array($schema['items'])) {
        measureProperties($schema['items'], $path . '[]', $over);
    }
    foreach (['oneOf', 'anyOf', 'allOf'] as $key) {
        foreach ($schema[$key] ?? [] as $i => $sub) {
            if (is_array($sub)) {
                measureProperties($sub, $path . '<' . $key . $i . '>', $over);
            }
        }
    }
}

$rows = [];
foreach (glob($root . '/tools/SomcpTool*.php') ?: [] as $file) {
    $class = basename($file, '.php');
    $tool = $class::define();
    $described = $tool->describe();
    $page = '-';
    foreach ($tool->getRequiredPermissions() as $permission) {
        $page = is_array($permission) ? (string) ($permission['controller'] ?? '-') : (string) $permission;
    }
    $over = [];
    measureProperties($described['inputSchema'] ?? [], '', $over);
    $rows[] = [
        'name' => $described['name'],
        'page' => $page,
        'json' => strlen((string) json_encode($described)),
        'description' => strlen($described['description']),
        'over' => $over,
    ];
}
usort($rows, function ($a, $b) {
    return [$a['page'], $a['name']] <=> [$b['page'], $b['name']];
});

$pages = [];
$total = 0;
$overTools = 0;
foreach ($rows as $row) {
    $total += $row['json'];
    $pages[$row['page']] = ($pages[$row['page']] ?? 0) + $row['json'];
    $flag = $row['description'] > TOOL_BUDGET ? ' over' : '';
    $overTools += $flag !== '' ? 1 : 0;
    printf("%-40s %-24s %7d %5d%s\n", $row['name'], $row['page'], $row['json'], $row['description'], $flag);
    foreach ($row['over'] as $path => $length) {
        printf("    property %s: %d\n", $path, $length);
    }
}
echo "\nPer page (characters, about 4 per token):\n";
foreach ($pages as $page => $size) {
    printf("%-24s %7d\n", $page, $size);
}
printf("\n%d tools, %d characters, %d descriptions above %d characters\n", count($rows), $total, $overTools, TOOL_BUDGET);
