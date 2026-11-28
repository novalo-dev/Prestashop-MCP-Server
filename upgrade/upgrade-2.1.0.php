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

function upgrade_module_2_1_0($module)
{
    Somcp::bootClasses();

    // The settings now open on one card per page, and the pages are no longer
    // tabs under the MCP server item. A fresh install puts their menu entries
    // out of the menu; on a shop upgrading in place they are still under the
    // item, where PrestaShop draws them as tabs and sends the menu to the
    // first of them. installTabs() moves them, and leaves alone every entry
    // that is already right.
    if (!$module->installTabs()) {
        return false;
    }

    // PrestaShop 9 keeps a compiled copy of the menu until its cache is
    // rebuilt.
    if (method_exists('Tools', 'clearSf2Cache')) {
        Tools::clearSf2Cache();
    }

    return true;
}
