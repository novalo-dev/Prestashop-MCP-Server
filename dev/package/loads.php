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

foreach ($failed as $line) {
    echo '::error::' . $line . PHP_EOL;
}
echo $loaded . ' classes load, ' . count($failed) . ' failed' . PHP_EOL;
exit($failed === [] ? 0 : 1);
