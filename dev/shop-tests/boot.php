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
 * Loads PrestaShop from the command line, the way every shop test needs it.
 *
 * Included at the top level of a script, never from inside a function:
 * config.inc.php sets globals the core reads back later, and a function
 * would turn them into locals. The script sets $psRoot and its own usage
 * message before including this file.
 *
 * The back-office side of PrestaShop: a command line has no host to pick a
 * shop from, and the admin side falls back on the default shop, which is the
 * one the tools answer for here. The source tree names its folder admin-dev.
 */

/* @var string $psRoot */
// The official Docker images give the folder a name of their own, so the
// first admin* folder holding the back office is taken after the usual two.
// PrestaShop 9's admin-api is the API's entry point, not the back office.
$adminDirs = array_merge([$psRoot . '/admin-dev', $psRoot . '/admin'], glob($psRoot . '/admin*', GLOB_ONLYDIR) ?: []);
foreach ($adminDirs as $adminDir) {
    if (basename($adminDir) !== 'admin-api' && is_file($adminDir . '/index.php')) {
        define('_PS_ADMIN_DIR_', $adminDir);
        break;
    }
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require $psRoot . '/config/config.inc.php';
