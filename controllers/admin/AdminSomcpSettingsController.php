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

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'somcp/classes/SomcpSettingsController.php';

/*
 * The MCP server item under Shop Parameters, whose tabs are the settings
 * pages. It has no page of its own: anybody who lands on it is sent
 * to the first of them.
 */
class AdminSomcpSettingsController extends SomcpSettingsController
{
    public function init()
    {
        Somcp::bootClasses();
        SomcpHttp::redirect($this->context->link->getAdminLink(Somcp::settingsPages()['config'][0]));
    }
}
