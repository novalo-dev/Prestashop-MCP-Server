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

/**
 * One page of the module settings.
 *
 * The settings are one template split into panes; each pane is a page of its
 * own, under the module's entry in the Modules menu, so that PrestaShop draws
 * them as tabs under the page title the way it does for Contacts and Stores,
 * with the module's buttons beside the title. A page only names its pane: the
 * module does the rest, exactly as its Configure page always did.
 */
abstract class SomcpSettingsController extends ModuleAdminController
{
    /**
     * @var Somcp the module instance, narrowed from the generic Module the
     *            parent declares so static analysis can resolve its public API
     */
    public $module;

    /** @var string The pane of the settings template this page shows. */
    protected $somcpPane = 'config';

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        $module = Module::getInstanceByName('somcp');
        if ($module instanceof Somcp) {
            $this->module = $module;
        }
    }

    /**
     * The permission slug of this controller, unchanged from the parent.
     *
     * Declared for the same reason as on the consent screen: PrestaShop 9 works
     * out whether a legacy controller carries its own rules by reflecting on
     * THIS method, not on viewAccess. Without it, viewAccess below is never
     * consulted.
     *
     * @return string
     */
    public function getTabSlug()
    {
        return parent::getTabSlug();
    }

    /**
     * Any signed-in employee reaches the page, as they reached the Configure
     * page before.
     *
     * The module itself decides what they see: the settings for the super
     * administrator, and for anybody else the notice that tells them how to
     * connect an assistant - checked before any form is read.
     *
     * @param bool $disable
     *
     * @return bool
     */
    public function viewAccess($disable = false)
    {
        return true;
    }

    /**
     * The module name as the page title, and its two buttons beside it.
     */
    public function initPageHeaderToolbar()
    {
        Somcp::bootClasses();

        $this->page_header_toolbar_title = $this->module->displayName;

        // A link to the sponsor page that opens the thank-you window instead
        // when the browser can show it: the window is drawn by the page, the
        // button by PrestaShop, and this onclick is the only thing PrestaShop
        // lets a button carry on both 8 and 9.
        $this->page_header_toolbar_btn['somcp_sponsor'] = [
            'href' => Somcp::SPONSOR_URL,
            'desc' => $this->module->l10n('Support the project'),
            'class' => 'btn-primary',
            // Named explicitly: both header templates build the button id
            // from it, and the stylesheet finds the button by that id. Left
            // out, the legacy template took the class instead.
            'imgclass' => 'somcp_sponsor',
            'target' => true,
            'js' => "var d=document.querySelector('[data-somcp-sponsor]');if(d&&d.showModal){d.showModal();return false;}",
        ];

        parent::initPageHeaderToolbar();

        // PrestaShop's Help button points at its own documentation for this
        // page, which has none: it leads to the module's instead.
        $this->context->smarty->assign('help_link', SomcpUpdate::REPOSITORY . '#readme');
    }

    /**
     * The pane, drawn by the module. Forms are handled there too, and
     * redirected back here, before anything is written to the page.
     */
    public function initContent()
    {
        $this->content = $this->module->renderSettings($this->somcpPane);

        parent::initContent();
    }
}
