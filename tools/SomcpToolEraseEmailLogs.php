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
 * somcp_erase_email_logs - the Erase all button of the list of sent e-mails
 * of Advanced Parameters > E-mail.
 *
 * Erased through Mail::eraseAllLogs(), the call of the core's
 * EmailLogEraser::eraseAll(), so no SQL of the module writes to the table.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The Log Emails switch is read as somcp_get_email_settings reads it.
require_once __DIR__ . '/SomcpToolGetEmailSettings.php';

class SomcpToolEraseEmailLogs
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_erase_email_logs',
            Somcp::t('Erase the list of sent e-mails'),
            'Erases every line of the list of sent e-mails of Advanced Parameters > E-mail, as its Erase all '
                . 'button. The log is one for the whole installation: on a multistore installation the lines of '
                . 'every shop go. The e-mails themselves stay sent. Cannot be undone; a second call answers 0. '
                . 'For one line use somcp_delete_email_log; to stop keeping new lines use '
                . 'somcp_update_email_settings with log_emails false.',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::SYSTEM_WRITE],
            [self::class, 'handle'],
            // Erase all asks for the delete right of the E-mail page, on 8
            // and 9 alike, as the delete of one line does.
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

        // A misspelt argument would otherwise be ignored, and the caller
        // would believe it had asked for something narrower than the whole
        // list.
        $problems = SomcpTool::unknownFields($arguments, []);
        if ($problems !== []) {
            return SomcpTool::refused('deleted', $problems);
        }

        // Counted first: the core truncates the table and answers no number.
        // The table has no shop column, so the count is the whole log.
        $count = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'mail`');

        if ($count > 0) {
            try {
                $done = (bool) Mail::eraseAllLogs();
                $failure = $done ? '' : 'PrestaShop refused to erase it';
            } catch (Throwable $e) {
                $done = false;
                $failure = $e->getMessage();
            }
            if (!$done) {
                SomcpLogger::log('email_logs_erase_failed', SomcpLogger::OUTCOME_FAILURE, $count . ' lines: '
                    . Tools::substr($failure, 0, 500), '', $idEmployee);

                return SomcpTool::text('The list of sent e-mails could not be erased.', true);
            }
            // The count only: the lines hold customers' addresses, which is
            // what the merchant erases, so the module's log never keeps them.
            SomcpLogger::log('email_logs_erased', SomcpLogger::OUTCOME_SUCCESS, $count . ' lines', '', $idEmployee);
        }

        $result = [
            'erased' => true,
            'deleted_count' => $count,
        ];

        $warnings = [];
        // The same count of shops as somcp_list_email_logs, so both tools
        // speak of the shared log in the same cases.
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'The lines of every shop were erased: PrestaShop keeps one log for all of them.';
        }
        $idShop = (int) Somcp::currentShop()->id;
        if (!(bool) SomcpToolGetEmailSettings::settings($idShop)['log_emails']) {
            $warnings[] = 'Log Emails is off: the list stays empty until it is turned on with '
                . 'somcp_update_email_settings (log_emails).';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }
}
