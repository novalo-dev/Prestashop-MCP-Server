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
 * The MCP server item under Shop Parameters. It opens on one card per
 * settings page, and shows the page a card asks for.
 */
class AdminSomcpSettingsController extends SomcpSettingsController
{
    /**
     * @var string Somcp::HOME_PANE, written out: the class of the module is
     *             not loaded yet when PrestaShop reads this file
     */
    protected $somcpPane = 'home';

    public function init()
    {
        // The page asked for by a card; anything else opens on the cards.
        $pane = (string) Tools::getValue('somcp_pane', '');
        if (isset(Somcp::settingsPages()[$pane])) {
            $this->somcpPane = $pane;
        }

        parent::init();
    }
}
