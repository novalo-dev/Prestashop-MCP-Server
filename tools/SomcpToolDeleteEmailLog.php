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
 * somcp_delete_email_log - the delete button of one line of the list of sent
 * e-mails of Advanced Parameters > E-mail.
 *
 * Deleted as the core's EmailLogEraser::erase() does, through the Mail
 * object, so no SQL of the module touches the table.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The line is found, and answered, as somcp_list_email_logs reads it.
require_once __DIR__ . '/SomcpToolListEmailLogs.php';

class SomcpToolDeleteEmailLog
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_email_log',
            Somcp::t('Delete a sent e-mail from the list'),
            'Deletes one line of the list of sent e-mails of Advanced Parameters > E-mail, as its delete '
                . 'button: for example the line of a customer who asked to be forgotten. One line per call; '
                . 'the e-mail itself was already sent and stays sent. The answer holds the line as it was. To '
                . 'stop keeping new lines use somcp_update_email_settings with log_emails false.',
            [
                'type' => 'object',
                'properties' => [
                    'id_mail' => [
                        'type' => 'integer',
                        'description' => 'Required. The line to delete (ID), from somcp_list_email_logs.',
                    ],
                ],
                'required' => ['id_mail'],
                'additionalProperties' => false,
            ],
            [SomcpScope::SYSTEM_WRITE],
            [self::class, 'handle'],
            // The delete of the list asks for the delete right of the E-mail
            // page, on 8 and 9 alike.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminEmails')
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public static function handle(array $arguments, array $context): array
    {
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = SomcpTool::unknownFields($arguments, ['id_mail']);
        $stored = null;
        $idMail = SomcpTool::id($arguments, 'id_mail');
        if ($idMail <= 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_mail');
        } else {
            // The log has no shop column: a line is the same for every shop,
            // as the list shows it.
            $stored = SomcpToolListEmailLogs::email($idMail);
            if ($stored === null) {
                $errors[] = SomcpTool::notFound('id_mail', 'logged e-mail', $idMail, 'somcp_list_email_logs');
            }
        }
        if ($errors !== [] || $stored === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        try {
            $done = (new Mail($idMail))->delete();
            // False when the line went between the read and the call.
            $failure = $done ? '' : 'PrestaShop refused to delete it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        // The id_mail and the template only: the recipient and the subject
        // are what the merchant deletes, so the module's log never keeps them.
        $what = 'e-mail log ' . $idMail . ' (' . $stored['template'] . ')';
        if (!$done) {
            SomcpLogger::log('email_log_delete_failed', SomcpLogger::OUTCOME_FAILURE, $what . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text(
                'The logged e-mail could not be deleted: ' . $failure . '. Read it with somcp_list_email_logs '
                    . 'before calling again.',
                true
            );
        }
        SomcpLogger::log('email_log_deleted', SomcpLogger::OUTCOME_SUCCESS, $what, '', $idEmployee);

        return SomcpTool::json([
            'deleted' => true,
            'id_mail' => $idMail,
            // The line as it was, read before the deletion, in the shape of
            // one item of emails of somcp_list_email_logs.
            'email' => $stored,
        ]);
    }
}
