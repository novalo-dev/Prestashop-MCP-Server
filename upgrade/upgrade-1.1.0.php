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
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.1.0 brings the assistant built into the back office.
 *
 * A shop upgrading in place gets what a fresh install of 1.1.0 would have
 * done: the assistant tables, the hidden page the chat talks to, and the
 * header hook its button is printed by. Every call skips what already
 * exists, so running this twice changes nothing. The hook that loads the
 * chat's script was already registered by 1.0.0.
 *
 * @param Somcp $module
 *
 * @return bool
 */
function upgrade_module_1_1_0($module)
{
    Somcp::bootClasses();

    // The header hook the chat button needs is new; one that cannot be
    // registered leaves the chat reachable from the module only, which is no
    // reason to fail the upgrade.
    $module->registerHook('displayBackOfficeTop');

    // installDatabase() only creates missing tables, so a shop that already
    // has the assistant table from a pre-release build keeps it without the
    // key_ok column. Add it when it is missing, so the refused-key state has
    // somewhere to live.
    $table = _DB_PREFIX_ . 'somcp_assistant';
    $exists = Db::getInstance()->executeS('SHOW TABLES LIKE "' . pSQL($table) . '"');
    if (is_array($exists) && $exists !== []) {
        $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . bqSQL($table) . '` LIKE "key_ok"');
        if (!is_array($columns) || $columns === []) {
            Db::getInstance()->execute('ALTER TABLE `' . bqSQL($table) . '` ADD `key_ok` TINYINT(1) DEFAULT NULL AFTER `role`');
        }
    }

    return $module->installDatabase() && $module->installTabs();
}
