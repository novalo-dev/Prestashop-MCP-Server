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
 * The deposit screen.
 *
 * A tool cannot be handed a file: the protocol carries JSON, and a file put in
 * an argument is bytes the model has to write out one token at a time. So the
 * tool asks instead, the assistant opens this page, and the merchant chooses
 * the file here. Nothing about it passes through the assistant.
 *
 * It lives in the back office so that PrestaShop decides who the visitor is,
 * from the employee's own session. The specification is explicit that a
 * server MUST verify the identity of whoever opens the address before accepting
 * anything - the attack being that one person triggers the call and has someone
 * else deposit. Here the employee signed in is compared with the one the
 * invitation was opened for, and nobody else gets the form.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSomcpUploadController extends ModuleAdminController
{
    /**
     * @var Somcp the module instance, narrowed from the generic Module the
     *            parent declares so static analysis can resolve its public API
     */
    public $module;

    /** @var string What happened to the invitation on this request: done, cancelled or nothing. */
    private $outcome = '';

    /** @var string Why a deposit was refused, one of the reasons SomcpUpload returns. */
    private $refusal = '';

    /** @var int How many of the chosen files were refused. */
    private $refusedCount = 0;

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
     * carries its own rules by reflecting on THIS method, not on viewAccess. Without it, viewAccess below is never
     * consulted and every profile but the super administrator gets a 403.
     *
     * @return string
     */
    public function getTabSlug()
    {
        return parent::getTabSlug();
    }

    /**
     * Any signed-in employee may deposit against their own invitation.
     *
     * The tab is hidden and has no permissions of its own, so the usual check
     * would refuse everyone but the super administrator. What can be done here
     * is bounded by the invitation, which names one employee and was opened by
     * a tool that already checked that employee's back office rights.
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
     * Reads the merchant's answer before anything is rendered.
     */
    public function postProcess()
    {
        Somcp::bootClasses();

        // Checked before the submit marker, because there is no submit marker
        // to find: PHP threw the whole body away for exceeding post_max_size,
        // and $_POST is empty. Without this the page redraws as if the
        // merchant had done nothing at all.
        if (SomcpUpload::postWasDropped()) {
            SomcpLogger::log('upload_refused', SomcpLogger::OUTCOME_FAILURE, 'post_max_size exceeded');
            $this->refusal = 'too_much';

            return parent::postProcess();
        }

        if (!Tools::isSubmit('submitSomcpUpload')) {
            return parent::postProcess();
        }

        // The administration token PrestaShop puts in the address has already
        // been checked by the core before this runs, and it is what guards the
        // form: it is tied to this controller, this employee and this
        // installation, which is exactly the protection a deposit form needs.
        $invitation = $this->invitation();
        if ($invitation === null) {
            return parent::postProcess();
        }

        $idEmployee = (int) $this->context->employee->id;

        $decision = (string) Tools::getValue('decision');

        // Taking one file back off the list, which the cross beside each row
        // asks for. It is tested before the catch-all below, and used to be
        // tested after: every decision that was not "deposit" fell into the
        // cancellation, so pressing the cross threw away the whole invitation
        // instead of the one file. Static analysis is what caught it - the
        // second test could never be true - and the page never complained.
        if ($decision === 'remove') {
            SomcpUpload::remove($invitation, (int) Tools::getValue('file'));

            return parent::postProcess();
        }

        if ($decision !== 'deposit') {
            SomcpUpload::drop($invitation);
            SomcpLogger::log('upload_cancelled', SomcpLogger::OUTCOME_INFO, '', '', $idEmployee);
            $this->outcome = 'cancelled';

            return parent::postProcess();
        }

        // One button, one request: the merchant gathers their files in the
        // page and sends the lot. There was a second button that only said
        // "I am done", and choosing files then pressing it sent nothing at
        // all - the files were in the request and this method returned before
        // reading them. Two ways to finish, one of them silently wrong.
        $accepted = 0;
        foreach (self::chosenFiles() as $file) {
            $refusal = SomcpUpload::accept($invitation, $file);
            if ($refusal === '') {
                ++$accepted;

                continue;
            }

            SomcpLogger::log('upload_refused', SomcpLogger::OUTCOME_FAILURE, $refusal, '', $idEmployee);

            // The first reason is the one shown. Several files refused for
            // several reasons would make a paragraph nobody reads, and the
            // journal already has each one.
            if ($this->refusal === '') {
                $this->refusal = $refusal;
            }
            ++$this->refusedCount;
        }

        // Finished only when nothing was turned away. A refusal keeps the page
        // open with the accepted files already in place, so the merchant can
        // replace the one that failed instead of starting over.
        if ($accepted > 0 && $this->refusedCount === 0) {
            $this->outcome = 'done';
        }

        return parent::postProcess();
    }

    /**
     * Renders the screen.
     */
    public function initContent()
    {
        Somcp::bootClasses();

        $this->context->smarty->assign($this->templateVariables());
        $this->content = $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'somcp/views/templates/admin/somcp/upload.tpl'
        );

        parent::initContent();
    }

    /**
     * Everything the template needs.
     *
     * @return array<string, mixed>
     */
    private function templateVariables(): array
    {
        if ($this->outcome === 'done') {
            return [
                'somcp_state' => 'done',
                'somcp_title' => $this->module->l10n('File received'),
                'somcp_message' => $this->module->l10n(
                    'The file has been received. Go back to your assistant and let it carry on.'),
            ];
        }

        if ($this->outcome === 'cancelled') {
            return [
                'somcp_state' => 'done',
                'somcp_title' => $this->module->l10n('Nothing was sent'),
                'somcp_message' => $this->module->l10n(
                    'No file was sent. Go back to your assistant and tell it you would rather not.'),
            ];
        }

        $invitation = $this->invitation();
        if ($invitation === null) {
            return [
                'somcp_state' => 'expired',
                'somcp_title' => $this->module->l10n('Nothing to send'),
                'somcp_message' => $this->module->l10n(
                    'This request has expired, has already been answered, or was not meant for your account. Ask your assistant again.'),
            ];
        }

        $extensions = array_filter(explode(' ', (string) $invitation['extensions']));
        $deposited = SomcpUpload::deposited((int) $invitation['id_upload']);
        $maxFiles = (int) $invitation['max_files'];

        $files = [];
        foreach ($deposited as $file) {
            $files[] = [
                'id' => (int) $file['id_upload_file'],
                'name' => (string) $file['file_name'],
                'size' => self::readableSize((int) $file['file_size']),
            ];
        }

        return [
            'somcp_state' => 'ask',
            'somcp_title' => $maxFiles > 1
                ? $this->module->l10n('Send files to your assistant')
                : $this->module->l10n('Send a file to your assistant'),
            'somcp_purpose' => (string) $invitation['purpose'],
            'somcp_asked_by' => $this->askedBy((string) $invitation['module']),
            'somcp_employee' => (string) $this->context->employee->email,
            'somcp_shop' => self::shopName((int) $invitation['id_shop']),
            'somcp_accept' => '.' . implode(',.', $extensions),
            'somcp_extensions' => implode(', ', $extensions),
            'somcp_max_size' => self::readableSize((int) $invitation['max_bytes']),
            'somcp_max_files' => $maxFiles,
            'somcp_files' => $files,
            'somcp_room_left' => count($files) < $maxFiles,
            'somcp_room_for' => max(0, $maxFiles - count($files)),
            // The raw numbers, for the checks the page makes before sending.
            'somcp_max_bytes' => (int) $invitation['max_bytes'],
            'somcp_max_total' => SomcpUpload::postCeiling(),
            'somcp_extension_list' => implode(',', $extensions),
            'somcp_reference' => (string) Tools::getValue('ref'),
            'somcp_error' => $this->refusalNotice(),
            'somcp_form_action' => $this->context->link->getAdminLink(
                SomcpBackOffice::UPLOAD_CONTROLLER,
                true,
                [],
                ['ref' => (string) Tools::getValue('ref')]
            ),
        ];
    }

    /**
     * The invitation this page is serving, for the employee signed in.
     *
     * @return array<string, mixed>|null
     */
    private function invitation(): ?array
    {
        $employee = $this->context->employee;
        if (!$employee || !$employee->id) {
            return null;
        }

        $invitation = SomcpUpload::find((string) Tools::getValue('ref'), (int) $employee->id);
        if ($invitation === null) {
            return null;
        }

        // The lookup deliberately ignores the shop in context, so that an
        // administrator working on one store still finds an invitation opened
        // for another. The shop the invitation belongs to is checked here
        // instead: on a multistore installation an employee must be attached
        // to that store, and back office permissions alone do not say so.
        if (!SomcpPermission::employeeIsUsable((int) $employee->id, (int) $invitation['id_shop'])) {
            SomcpLogger::log(
                'upload_refused_shop',
                SomcpLogger::OUTCOME_FAILURE,
                'employee is not attached to shop ' . (int) $invitation['id_shop'],
                '',
                (int) $employee->id
            );

            return null;
        }

        return $invitation;
    }

    /**
     * Why the last deposit was refused, in words the merchant can act on.
     *
     * @return string
     */
    private function refusalMessage(): string
    {
        switch ($this->refusal) {
            case 'no_file':
                return $this->module->l10n('No file arrived. Choose one and send it again.');
            case 'too_large':
                return $this->module->l10n('That file is too large.');
            case 'too_many':
                return $this->module->l10n('You have already sent as many files as were asked for.');
            case 'too_much':
                return $this->module->l10n(
                    'Everything together was too large to send at once. Send fewer files at a time.');
            case 'wrong_type':
                return $this->module->l10n('That file is not one of the accepted kinds.');
            case 'no_store':
                return $this->module->l10n(
                    'The file could not be stored. Check that the shop can write inside its modules folder.');
            default:
                return '';
        }
    }

    /**
     * The refusal, with a count when more than one file was turned away.
     *
     * @return string
     */
    private function refusalNotice(): string
    {
        $message = $this->refusalMessage();
        if ($message === '' || $this->refusedCount < 2) {
            return $message;
        }

        return $this->module->l10n('%count% files were not accepted.', ['%count%' => (string) $this->refusedCount])
            . ' ' . $message;
    }

    /**
     * Which module asked for the file, named as the merchant knows it.
     *
     * @param string $folder
     *
     * @return string
     */
    private function askedBy(string $folder): string
    {
        if ($folder === '') {
            return (string) $this->module->displayName;
        }

        $module = Module::getInstanceByName($folder);

        return $module instanceof Module ? (string) $module->displayName : $folder;
    }

    /**
     * The files the merchant chose, as one flat list.
     *
     * The field is sent as somcp_file[], so PHP hands back an array per key
     * rather than an array per file - names in one array, sizes in another.
     * This turns it back into what the rest of the code expects, and tolerates
     * the single-file shape in case a browser sends it that way.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function chosenFiles(): array
    {
        $field = $_FILES['somcp_file'] ?? null;
        if (!is_array($field) || !isset($field['name'])) {
            return [];
        }

        if (!is_array($field['name'])) {
            return [$field];
        }

        $files = [];
        foreach (array_keys($field['name']) as $index) {
            $files[] = [
                'name' => $field['name'][$index] ?? '',
                'type' => $field['type'][$index] ?? '',
                'tmp_name' => $field['tmp_name'][$index] ?? '',
                'error' => $field['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $field['size'][$index] ?? 0,
            ];
        }

        return $files;
    }

    /**
     * A byte count as the merchant reads it.
     *
     * @param int $bytes
     *
     * @return string
     */
    private static function readableSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return (string) round($bytes / 1048576, 1) . ' MB';
        }

        return (string) max(1, (int) round($bytes / 1024)) . ' kB';
    }

    /**
     * Name of a shop, for the deposit screen.
     *
     * @param int $idShop
     *
     * @return string
     */
    private static function shopName(int $idShop): string
    {
        $shop = new Shop($idShop);

        return Validate::isLoadedObject($shop)
            ? (string) $shop->name
            : (string) Configuration::get('PS_SHOP_NAME');
    }
}
