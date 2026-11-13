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
 * The address an assistant sends a file to, by itself.
 *
 * The deposit page of the back office needs a person: signed in, choosing a
 * file, pressing a button. An assistant that can make an HTTP request already
 * holds the file and needs none of that, so somcp_upload_file hands it this
 * address as well. It is public by necessity - the request comes from
 * wherever the assistant runs - and everything rests on the link, as for the
 * chat's file links: it names the deposit by the hash of its reference,
 * carries the deposit's expiry and is signed with a key only the shop holds.
 *
 * It takes one thing: a multipart POST of the files the deposit was opened
 * for, judged by SomcpUpload::accept() exactly as the page judges them. A
 * link that fails any check gets the same empty 404 as an address that does
 * not exist, so nothing can be learnt by trying. Nothing is ever read back
 * from here: the file reaches a tool only through its upload_ref, redeemed
 * for the account that opened the deposit.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpDepositModuleFrontController extends ModuleFrontController
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
     * Served even while the shop is closed: the assistant sending is not a
     * visitor, and a shop in maintenance is often the one being filled.
     */
    protected function displayMaintenancePage()
    {
    }

    /**
     * Keeps the file and answers in JSON, or answers an empty 404.
     */
    public function initContent()
    {
        Somcp::bootClasses();

        if (Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            SomcpHttp::noContent(404);
        }

        $invitation = SomcpUpload::directInvitation(
            (string) Tools::getValue('u'),
            (int) Tools::getValue('e'),
            (string) Tools::getValue('s')
        );
        if ($invitation === null || (int) $invitation['id_shop'] !== Somcp::currentShopId()) {
            SomcpHttp::noContent(404);
        }

        // PHP throws away a body over post_max_size before any script runs,
        // and an empty $_FILES would otherwise read as "no file was sent".
        if (SomcpUpload::postWasDropped()) {
            SomcpHttp::json(['received' => false, 'refused' => SomcpUpload::refusal('too_large', $invitation)], 413);
        }

        $file = $_FILES[SomcpUpload::DIRECT_FIELD] ?? null;
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            // One file per request: several under one field arrive as lists,
            // which accept() does not read.
            SomcpHttp::json(['received' => false, 'refused' => SomcpUpload::refusal('no_file', $invitation)], 400);
        }

        $problem = SomcpUpload::accept($invitation, $file);
        if ($problem !== '') {
            SomcpHttp::json(
                ['received' => false, 'refused' => SomcpUpload::refusal($problem, $invitation)],
                $problem === 'too_large' ? 413 : ($problem === 'no_store' ? 500 : 400)
            );
        }

        SomcpHttp::json(['received' => true, 'file' => Tools::substr((string) ($file['name'] ?? ''), 0, 255)]);
    }
}
