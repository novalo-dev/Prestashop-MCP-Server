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

function upgrade_module_1_2_0($module)
{
    Somcp::bootClasses();
    $db = Db::getInstance();

    // The assistants of the built-in chat now belong to a level - all shops,
    // a group or a shop - as every other setting does. Existing rows keep
    // their shop and get group 0: each one stays the shop's own assistant,
    // so nothing changes for a merchant until they choose to inherit.
    $assistant = _DB_PREFIX_ . 'somcp_assistant';
    $present = $db->executeS('SHOW TABLES LIKE "' . pSQL($assistant) . '"');
    if (is_array($present) && $present !== []) {
        $column = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($assistant) . '` LIKE "id_shop_group"');
        if (!is_array($column) || $column === []) {
            $db->execute('ALTER TABLE `' . bqSQL($assistant) . '` ADD `id_shop_group` INT(11) NOT NULL DEFAULT 0 AFTER `id_shop`');
        }
    }

    // One assistant set for all shops is now used by several of them, and
    // each shop keeps its own spending: the month of an assistant is counted
    // per shop. The old key would have added every shop's cost to one row.
    $usage = _DB_PREFIX_ . 'somcp_assistant_usage';
    $present = $db->executeS('SHOW TABLES LIKE "' . pSQL($usage) . '"');
    if (is_array($present) && $present !== []) {
        $key = $db->executeS('SHOW INDEX FROM `' . bqSQL($usage) . '` WHERE `Key_name` = "PRIMARY"');
        $columns = is_array($key) ? array_column($key, 'Column_name') : [];
        if (!in_array('id_shop', $columns, true)) {
            $db->execute('ALTER TABLE `' . bqSQL($usage) . '` DROP PRIMARY KEY, ADD PRIMARY KEY (`id_assistant`, `id_shop`, `month`)');
        }
    }

    return $module->installDatabase();
}
