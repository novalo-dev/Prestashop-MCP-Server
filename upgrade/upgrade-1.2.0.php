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

    // The columns 1.2.0 adds - the level of an assistant, its own monthly
    // cap and its relay; the + menu switches and the loaded tools of a
    // conversation - and the relay tables come from install.sql, which
    // installDatabase() applies to whatever the shop already has. That runs
    // first, so the steps below find every column whatever version the shop
    // comes from. The defaults are what existing rows need: group 0 keeps
    // each assistant the shop's own, and a conversation goes on as before,
    // without thinking or search and with its memory.
    if (!$module->installDatabase()) {
        return false;
    }
    $assistant = _DB_PREFIX_ . 'somcp_assistant';
    $present = $db->executeS('SHOW TABLES LIKE "' . pSQL($assistant) . '"');

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

    // The Economy, Balanced and Powerful levels are gone: an assistant now
    // runs the model the merchant chose as its default. One that already
    // resolved its level keeps that model, so nothing changes on the shop;
    // the provider is not asked here, and one that never ran keeps resolving
    // its level until it is saved again.
    if (is_array($present = $db->executeS('SHOW TABLES LIKE "' . pSQL($assistant) . '"')) && $present !== []) {
        $db->execute('UPDATE `' . bqSQL($assistant) . '` SET `level` = "' . pSQL(SomcpAssistant::LEVEL_CUSTOM) . '", `model` = `resolved_model`'
            . ' WHERE `level` IN ("economy", "balanced", "power") AND `model` = "" AND `resolved_model` <> ""');
    }

    return true;
}
