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

/*
 * The protected resource metadata document.
 *
 * Unlike the authorization server document, this one does not need to sit at
 * the root of the domain: since the 2026-07-28 revision a client follows the
 * resource_metadata parameter of the WWW-Authenticate challenge, which the MCP
 * endpoint sends on every unauthenticated call and which points here. Keeping
 * it inside the module means one fragile address instead of two.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpPrmModuleFrontController extends ModuleFrontController
{
    /** @var Somcp the module instance, narrowed from the generic Module the parent declares */
    public $module;

    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ssl = true;

    /** @var bool */
    public $display_header = false;

    /** @var bool */
    public $display_footer = false;

    /**
     * The MCP endpoints answer even while the shop is closed.
     *
     * Maintenance mode hides the storefront from visitors. These addresses are
     * not the storefront: they are the merchant's own, guarded by an OAuth
     * token, and a shop being prepared is exactly when an assistant gets
     * connected. Left alone, PrestaShop answers 503 here before the controller
     * ever runs - and it would do so unevenly, since PS_MAINTENANCE_ALLOW_ADMINS
     * lets the merchant's browser through the consent screen while every
     * machine call still fails, leaving a connection broken halfway with
     * nothing to explain it. The setting that closes this server is the one in
     * the module, which says so plainly.
     */
    protected function displayMaintenancePage()
    {
    }

    /**
     * Hands a browser over to the shop's "page not found" before anything else.
     *
     * This address is for programs; a person who opens it, or a link built to
     * see what the shop runs, gets the same page as for any dead link. It has
     * to happen before the parent init (see SomcpHttp::notFoundPage()).
     */
    public function init()
    {
        Somcp::bootClasses();

        if (Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET' && SomcpHttp::isBrowser()) {
            SomcpHttp::notFoundPage();
        }

        parent::init();
    }

    /**
     * Emits the document and stops the script.
     */
    public function initContent()
    {
        Somcp::bootClasses();

        if (!SomcpConfig::isOn(SomcpConfig::ENABLED)) {
            SomcpHttp::noContent(404);
        }

        SomcpHttp::json(SomcpMetadata::protectedResource(), 200);
    }
}
