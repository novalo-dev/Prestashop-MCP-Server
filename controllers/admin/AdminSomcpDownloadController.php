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
 * The download screen, the outgoing counterpart of the deposit page.
 *
 * A tool that produces a private file never returns the bytes: it hands out a
 * signed link to this page, which the merchant opens in their own back office.
 * It lives in the back office so that PrestaShop decides who the visitor is,
 * from the employee's own session - the same guarantee the deposit page relies
 * on. A leaked link is useless without that session: the signature is bound to
 * the employee it was made for, so it only verifies for the one signed in.
 *
 * Nothing is sent before every check passes: the link is sound and unexpired,
 * the signed-in employee is the one it was made for and on the shop it belongs
 * to (both enforced by the signature), the employee holds the back office
 * rights of the tool that reads that kind of file (SomcpDownload::mayHave()),
 * and the file still exists on that shop. A document of an order is then
 * built on demand from the core PDF class, so no customer data is written to
 * disk; any other file is read where the shop keeps it.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSomcpDownloadController extends ModuleAdminController
{
    /**
     * @var Somcp the module instance, narrowed from the generic Module the
     *            parent declares so static analysis can resolve its public API
     */
    public $module;

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
     * Declared because PrestaShop 9 works out whether a legacy controller
     * carries its own rules by reflecting on THIS method. Without it the
     * viewAccess below is never consulted and every profile but the super
     * administrator gets a 403 before the controller can check the view right
     * that actually guards the document.
     *
     * @return string
     */
    public function getTabSlug()
    {
        return parent::getTabSlug();
    }

    /**
     * Any signed-in employee may reach the page; the document itself is guarded.
     *
     * The tab is hidden and has no permissions of its own, so the usual check
     * would refuse everyone but the super administrator. What may be downloaded
     * is bounded by the signed link and, below, by the rights of the tool that
     * reads that kind of file - not by a permission on this hidden tab.
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
     * Validates the link and streams the document, or shows a short refusal.
     */
    public function initContent()
    {
        Somcp::bootClasses();

        $refusal = $this->serve();
        if ($refusal === '') {
            return;
        }

        // A refusal is a short back office page, never a PDF and never a stack
        // trace, inside the ordinary back office chrome the employee is already
        // signed in to. The markup lives in a template, as the Addons rules ask.
        $this->context->smarty->assign('somcp_refusal', $refusal);
        $this->content = $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'somcp/views/templates/admin/somcp/download.tpl'
        );
        parent::initContent();
    }

    /**
     * Streams the document when every check passes, otherwise the refusal text.
     *
     * @return string empty once the PDF has been sent (the script has stopped by then)
     */
    private function serve(): string
    {
        $employee = $this->context->employee;
        if (!$employee || !$employee->id) {
            return $this->refuse('download_refused', 'no employee', $this->module->l10n('This link was made for another employee.'));
        }

        $idEmployee = (int) $employee->id;
        $idShop = (int) $this->context->shop->id;

        $query = [
            'kind' => (string) Tools::getValue('kind'),
            'id' => (string) Tools::getValue('id'),
            'e' => (int) Tools::getValue('e'),
            's' => (string) Tools::getValue('s'),
        ];

        $sound = SomcpDownload::check($query, $idEmployee, $idShop);

        if ($sound === 'expired') {
            return $this->refuse('download_refused', 'expired', $this->module->l10n('This link has expired. Ask the assistant for the document again.'), $idEmployee);
        }
        if ($sound !== '') {
            return $this->refuse('download_refused', 'signature', $this->module->l10n('This link was made for another employee.'), $idEmployee);
        }

        if (!SomcpDownload::mayHave($query['kind'], null, $idEmployee)) {
            return $this->refuse('download_refused', 'no permission', $this->module->l10n('You do not have permission to view this.'), $idEmployee);
        }

        $file = SomcpDownload::file($query['kind'], $query['id'], $idShop);
        if (!is_array($file)) {
            return $this->refuse('download_refused', 'gone', $this->module->l10n('This document no longer exists.'), $idEmployee);
        }

        $this->send($file);

        return '';
    }

    /**
     * Sends a file, as the core sends a PDF, and stops the script.
     *
     * The name is reduced to plain characters, as controllers/front/file.php
     * does: it is the shop's own name for the file, but an attachment's name
     * was typed by whoever uploaded it.
     *
     * @param array{bytes:string, name:string, mime:string} $file
     */
    private function send(array $file): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Shown in the page only for the types a browser cannot run: an
        // attachment may be an HTML or SVG file, which opened inline here would
        // run inside the back office. Anything else is saved, never shown.
        $shown = in_array($file['mime'], ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
        $name = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']);
        header('Content-Type: ' . ($shown ? $file['mime'] : 'application/octet-stream'));
        header('Content-Length: ' . strlen($file['bytes']));
        header('Content-Disposition: ' . ($shown ? 'inline' : 'attachment') . '; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        // Not on a PDF: Chrome's viewer refuses to open one under a sandbox.
        if ($file['mime'] !== 'application/pdf') {
            header("Content-Security-Policy: default-src 'none'; sandbox");
        }
        header('Cache-Control: private, no-store');
        echo $file['bytes'];
        exit;
    }

    /**
     * Records a refusal in the journal and returns its merchant-facing text.
     *
     * @param string $event
     * @param string $reason the internal reason, for the journal only
     * @param string $message the text shown to the merchant
     * @param int $idEmployee
     *
     * @return string the message
     */
    private function refuse(string $event, string $reason, string $message, int $idEmployee = 0): string
    {
        SomcpLogger::log($event, SomcpLogger::OUTCOME_FAILURE, $reason, '', $idEmployee);

        return $message;
    }
}
