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
 * A file attached in the built-in assistant, for the provider that reads it.
 *
 * When the shop is reachable from the internet, the chat hands Claude, OpenAI
 * or Mistral a link to an attached image or PDF rather than its bytes, and
 * their servers fetch it here. So this address is public by necessity, and
 * everything rests on the link: it names the file by the hash of its
 * reference, carries an expiry, and is signed with a key only the shop holds.
 * A link that fails any of the three gets the same empty 404 as a file that
 * does not exist, so nothing can be learnt by trying.
 *
 * Only deposits made by the chat can be served, and only until they expire.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpFileModuleFrontController extends ModuleFrontController
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
     * Served even while the shop is closed: the provider asking is not a
     * visitor, and a merchant preparing a shop in maintenance is exactly the
     * one chatting with the assistant about its catalogue.
     */
    protected function displayMaintenancePage()
    {
    }

    /**
     * Sends the file and stops the script.
     */
    public function initContent()
    {
        Somcp::bootClasses();

        $hash = (string) Tools::getValue('f');
        $expires = (int) Tools::getValue('e');
        $signature = (string) Tools::getValue('s');

        $file = null;
        if ($expires > time() && preg_match('/^[a-f0-9]{64}$/', $signature)
            && hash_equals(SomcpAssistantChat::signature($hash, $expires), $signature)) {
            $file = SomcpUpload::linked($hash);
        }
        if ($file === null) {
            SomcpHttp::noContent(404);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // The type stored at deposit, checked then against the bytes; never
        // sniffed again by the browser, and never run as a page.
        header('Content-Type: ' . $file['mimeType']);
        header('Content-Length: ' . (string) filesize($file['path']));
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']) . '"');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: private, max-age=3600');
        header('X-Robots-Tag: noindex, nofollow');
        readfile($file['path']);
        exit;
    }
}
