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
 * Loads every class of a pruned vendor folder, the way the module archive
 * ships it. A class whose parent, interface or trait was pruned throws when
 * it is declared; one that nothing references would only fail in a shop, the
 * day something did. So all of them are declared here, once.
 *
 * Then every Neuron class the module's own code names must still be there.
 * Loading what is left proves nothing about what was taken away: the memory
 * shipped with src/RAG pruned, and only the merchant's chat found out.
 *
 * Usage: php dev/package/loads.php <module folder>
 */

$module = rtrim($argv[1] ?? '.', '/');
require $module . '/vendor/autoload.php';

$classmap = require $module . '/vendor/composer/autoload_classmap.php';
$failed = [];
$loaded = 0;

foreach ($classmap as $class => $file) {
    if (strpos($class, 'NeuronAI\\') !== 0 || !is_file($file)) {
        // A file listed in the class map but pruned: never requested by what
        // is left, which is what the loop below proves.
        continue;
    }
    try {
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
            $failed[] = $class . ': not declared by its file';
            continue;
        }
        ++$loaded;
    } catch (Throwable $e) {
        $failed[] = $class . ': ' . $e->getMessage();
    }
}

// Read as PHP tokens, so a name in a comment or a string does not count.
$named = [];
$code = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($module, FilesystemIterator::SKIP_DOTS));
foreach ($code as $file) {
    $path = substr($file->getPathname(), strlen($module) + 1);
    if ($file->getExtension() !== 'php' || preg_match('#^(vendor|dev)/#', $path)) {
        continue;
    }
    foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
        if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && strpos(ltrim($token[1], '\\'), 'NeuronAI\\') === 0) {
            $named[ltrim($token[1], '\\')][] = $path . ':' . $token[2];
        }
    }
}
foreach ($named as $class => $places) {
    try {
        $exists = class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class);
    } catch (Throwable $e) {
        $exists = false;
    }
    if (!$exists) {
        $failed[] = $class . ': used by ' . $places[0] . ' but missing from the archive (see dev/package/prune.txt)';
    }
}
echo count($named) . ' Neuron classes named by the module' . PHP_EOL;

foreach ($failed as $line) {
    echo '::error::' . $line . PHP_EOL;
}
echo $loaded . ' classes load, ' . count($failed) . ' failed' . PHP_EOL;
exit($failed === [] ? 0 : 1);
