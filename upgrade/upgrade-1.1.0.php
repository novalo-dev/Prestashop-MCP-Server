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
 * done: the assistant tables, the hidden page the chat talks to, the
 * settings pages under Shop Parameters > MCP server, and the header hook
 * its button is printed by. Every call skips what already exists or puts it
 * back where it belongs, so running this twice changes nothing. The hook
 * that loads the chat's script was already registered by 1.0.0.
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

    // installDatabase() only creates missing tables. A shop that built the
    // assistant tables from a pre-release copy has them without id_shop, so the
    // per-shop scoping is added here. Each column defaults to shop 1, which is
    // the only shop on a single-store install.
    $db = Db::getInstance();
    foreach (['somcp_assistant', 'somcp_assistant_usage'] as $name) {
        $table = _DB_PREFIX_ . $name;
        $present = $db->executeS('SHOW TABLES LIKE "' . pSQL($table) . '"');
        if (!is_array($present) || $present === []) {
            continue;
        }
        $column = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($table) . '` LIKE "id_shop"');
        if (!is_array($column) || $column === []) {
            $db->execute('ALTER TABLE `' . bqSQL($table) . '` ADD `id_shop` INT(11) NOT NULL DEFAULT 1, ADD KEY `idx_shop` (`id_shop`)');
        }
    }

    // The long-term memory marks how far each conversation was written, and a
    // conversation marks when a move is running on it.
    $thread = _DB_PREFIX_ . 'somcp_assistant_thread';
    $present = $db->executeS('SHOW TABLES LIKE "' . pSQL($thread) . '"');
    if (is_array($present) && $present !== []) {
        $column = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($thread) . '` LIKE "memory_last"');
        if (!is_array($column) || $column === []) {
            $db->execute('ALTER TABLE `' . bqSQL($thread) . '` ADD `memory_last` VARCHAR(64) NOT NULL DEFAULT \'\'');
        }
        // A pre-release copy counted the messages written instead, a mark that
        // a summary of the conversation threw off. A conversation it had
        // written to is marked written up to its latest message, so the memory
        // does not embed - and pay for - it a second time; then the old column
        // goes, and the table matches a fresh install.
        $column = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($thread) . '` LIKE "memory_count"');
        if (is_array($column) && $column !== []) {
            $messages = _DB_PREFIX_ . 'somcp_assistant_message';
            $db->execute(
                'UPDATE `' . bqSQL($thread) . '` t SET t.`memory_last` = IFNULL(('
                . 'SELECT m.`message_id` FROM `' . bqSQL($messages) . '` m'
                . ' WHERE m.`thread_id` = t.`thread_id` ORDER BY m.`id` DESC LIMIT 1), \'\')'
                . ' WHERE t.`memory_count` > 0'
            );
            $db->execute('ALTER TABLE `' . bqSQL($thread) . '` DROP `memory_count`');
        }
        // One move at a time per conversation, now that the chat carries on
        // across page reloads and tabs.
        $column = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($thread) . '` LIKE "busy_until"');
        if (!is_array($column) || $column === []) {
            $db->execute('ALTER TABLE `' . bqSQL($thread) . '` ADD `busy_until` DATETIME NULL DEFAULT NULL');
        }
        // The message being answered, so a page reloaded meanwhile still
        // shows it.
        $column = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($thread) . '` LIKE "pending"');
        if (!is_array($column) || $column === []) {
            $db->execute('ALTER TABLE `' . bqSQL($thread) . '` ADD `pending` TEXT NULL');
        }
    }

    return $module->installDatabase() && $module->installTabs();
}
