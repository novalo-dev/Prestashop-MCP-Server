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
        // Each assistant may have a monthly cap of its own, in tokens.
        $column = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($assistant) . '` LIKE "token_cap"');
        if (!is_array($column) || $column === []) {
            $db->execute('ALTER TABLE `' . bqSQL($assistant) . '` ADD `token_cap` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `role`');
        }
        // A local assistant answers through a somcp-relay; its own tables
        // come with installDatabase() below.
        $column = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($assistant) . '` LIKE "id_relay"');
        if (!is_array($column) || $column === []) {
            $db->execute('ALTER TABLE `' . bqSQL($assistant) . '` ADD `id_relay` INT(11) NOT NULL DEFAULT 0 AFTER `token_cap`');
        }
    }

    // The chat's + menu switches deep thinking, web search and the memory for
    // one conversation, and the conversation keeps them: an existing one
    // starts as it ran before, without thinking or search and with memory.
    $thread = _DB_PREFIX_ . 'somcp_assistant_thread';
    $threadTable = $db->executeS('SHOW TABLES LIKE "' . pSQL($thread) . '"');
    if (is_array($threadTable) && $threadTable !== []) {
        $switches = [
            'think' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `memory_last`',
            'web_search' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `think`',
            'memory' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `web_search`',
            // The shop tools a conversation loaded: the chat no longer sends
            // every tool's description on every call.
            'tools' => 'TEXT NULL AFTER `memory`',
        ];
        foreach ($switches as $name => $definition) {
            $column = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($thread) . '` LIKE "' . pSQL($name) . '"');
            if (!is_array($column) || $column === []) {
                $db->execute('ALTER TABLE `' . bqSQL($thread) . '` ADD `' . bqSQL($name) . '` ' . $definition);
            }
        }
    }

    // The monthly cap was an estimate in US dollars; it is now counted in the
    // tokens the providers report. A cap a merchant set is kept, converted at
    // the highest output price among their assistants, so it is never looser
    // than before - only a cap that cannot be converted, no model having a
    // known price, is left out. Each row keeps its level: all shops, a group
    // or a shop.
    $table = _DB_PREFIX_ . 'configuration';
    $old = $db->executeS('SELECT `id_shop_group`, `id_shop`, `value` FROM `' . bqSQL($table) . '`'
        . ' WHERE `name` = "' . pSQL(SomcpAssistant::BUDGET) . '"');
    $already = (int) $db->getValue('SELECT COUNT(*) FROM `' . bqSQL($table) . '` WHERE `name` = "' . pSQL(SomcpAssistant::TOKEN_CAP) . '"');
    if (is_array($old) && $old !== [] && $already === 0) {
        $models = is_array($present) && $present !== [] ? $db->executeS('SELECT `provider`, `model`, `resolved_model` FROM `' . bqSQL($assistant) . '`') : [];
        foreach ($old as $row) {
            $tokens = 0;
            foreach (is_array($models) ? $models : [] as $model) {
                $name = (string) $model['model'] !== '' ? (string) $model['model'] : (string) $model['resolved_model'];
                $converted = SomcpAssistant::tokensFor((float) $row['value'], (string) $model['provider'], $name);
                if ($converted > 0 && ($tokens === 0 || $converted < $tokens)) {
                    $tokens = $converted;
                }
            }
            if ($tokens > 0) {
                $db->insert('configuration', [
                    'name' => pSQL(SomcpAssistant::TOKEN_CAP),
                    'value' => (string) $tokens,
                    'id_shop_group' => $row['id_shop_group'] === null ? null : (int) $row['id_shop_group'],
                    'id_shop' => $row['id_shop'] === null ? null : (int) $row['id_shop'],
                    'date_add' => date('Y-m-d H:i:s'),
                    'date_upd' => date('Y-m-d H:i:s'),
                ], true);
            }
        }
    }
    $db->delete('configuration', '`name` = "' . pSQL(SomcpAssistant::BUDGET) . '"');

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
