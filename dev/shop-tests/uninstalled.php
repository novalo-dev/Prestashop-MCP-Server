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

/**
 * Checks that uninstalling the module left nothing behind.
 *
 * Run after run.php has called every tool and the module was uninstalled,
 * so the shop held everything the module creates while it is used. A merchant
 * who removes the module must get the shop back as it was: no table, no
 * setting, no menu entry, no access right, no hook it declared, no file
 * under var/. Each leftover is reported as an annotation on its own line.
 *
 * Usage: php dev/shop-tests/uninstalled.php <prestashop root>
 * Exit code 0 when nothing is left, 1 otherwise.
 */
$psRoot = realpath($argv[1] ?? '');
if ($psRoot === false || !is_file($psRoot . '/config/config.inc.php')) {
    fwrite(STDERR, "Usage: php dev/shop-tests/uninstalled.php <prestashop root>\n");
    exit(1);
}

require __DIR__ . '/boot.php';

$db = Db::getInstance();
$prefix = _DB_PREFIX_;
// LIKE patterns: the underscore of the prefix and of SOMCP_ must match
// itself, not any character.
$like = static function (string $text): string {
    return str_replace('_', '\\_', $text);
};

$checks = [
    'module still registered' => 'SELECT `name` FROM `' . $prefix . 'module` WHERE `name` = "somcp"',
    'tables' => 'SHOW TABLES LIKE "' . $like($prefix . 'somcp') . '%"',
    'settings' => 'SELECT `name` FROM `' . $prefix . 'configuration` WHERE `name` LIKE "' . $like('SOMCP_') . '%"',
    'menu entries' => 'SELECT `class_name` FROM `' . $prefix . 'tab` WHERE `module` = "somcp" OR `class_name` LIKE "AdminSomcp%"',
    'access rights' => 'SELECT `slug` FROM `' . $prefix . 'authorization_role`'
        . ' WHERE `slug` LIKE "' . $like('ROLE_MOD_TAB_ADMINSOMCP') . '%" OR `slug` LIKE "' . $like('ROLE_MOD_MODULE_SOMCP_') . '%"',
    'declared hooks' => 'SELECT `name` FROM `' . $prefix . 'hook` WHERE `name` IN ("actionSomcpRegisterTools", "actionSomcpRegisterPrompts")',
];

$left = 0;
foreach ($checks as $what => $sql) {
    $rows = $db->executeS($sql);
    if (!is_array($rows)) {
        echo '::error title=Uninstall check failed::' . $what . ': the query failed' . "\n";
        ++$left;
        continue;
    }
    foreach ($rows as $row) {
        echo '::error title=Left after uninstall::' . $what . ': ' . (string) reset($row) . "\n";
        ++$left;
    }
}

// Files the module writes under var/: the uploads and the memory.
$folder = rtrim(_PS_ROOT_DIR_, '/\\') . '/var/somcp';
if (file_exists($folder)) {
    echo '::error title=Left after uninstall::files: var/somcp' . "\n";
    ++$left;
}

if ($left > 0) {
    echo $left . " leftover(s) after uninstall\n";
    exit(1);
}
echo "Uninstall left nothing behind\n";
