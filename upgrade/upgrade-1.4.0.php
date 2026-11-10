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

function upgrade_module_1_4_0($module)
{
    Somcp::bootClasses();

    // Private documents are now handed over through a signed download page,
    // which needs a hidden tab of its own: without one the controller has no
    // security token and PrestaShop refuses the page. A fresh install creates
    // it; a shop upgrading in place gets it here. installTabs() leaves every
    // tab that already exists alone.
    if (!$module->installTabs()) {
        return false;
    }

    // On PrestaShop 9 the same page has a route and a service of its own.
    // Symfony keeps a compiled copy of both until its cache is rebuilt, and
    // until then the new link would answer "not found".
    if (method_exists('Tools', 'clearSf2Cache')) {
        Tools::clearSf2Cache();
    }

    return true;
}
