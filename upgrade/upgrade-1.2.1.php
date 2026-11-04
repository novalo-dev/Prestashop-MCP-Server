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

function upgrade_module_1_2_1($module)
{
    Somcp::bootClasses();
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'configuration';

    // The built-in assistant now has its own list of tools and prompts
    // switched off. Until this version the one list covered it too, so it
    // starts as a copy of that list at every level it is set - all shops, a
    // group, a shop - and nothing the merchant switched off comes back on in
    // the chat. A row the module may already hold for the new key is dropped
    // first: the copy is the only value it can have coming from 1.2.0.
    $pairs = [
        SomcpConfig::TOOLS_OFF => SomcpConfig::TOOLS_OFF_CHAT,
        SomcpConfig::PROMPTS_OFF => SomcpConfig::PROMPTS_OFF_CHAT,
    ];
    foreach ($pairs as $from => $to) {
        $rows = $db->executeS('SELECT `id_shop_group`, `id_shop`, `value` FROM `' . bqSQL($table) . '`'
            . ' WHERE `name` = "' . pSQL($from) . '"');
        $db->delete('configuration', '`name` = "' . pSQL($to) . '"');
        foreach (is_array($rows) ? $rows : [] as $row) {
            $db->insert('configuration', [
                'name' => pSQL($to),
                'value' => pSQL((string) $row['value']),
                'id_shop_group' => $row['id_shop_group'] === null ? null : (int) $row['id_shop_group'],
                'id_shop' => $row['id_shop'] === null ? null : (int) $row['id_shop'],
                'date_add' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s'),
            ], true);
        }
    }

    return true;
}
