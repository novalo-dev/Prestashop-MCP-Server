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
 * somcp_send_test_email - the Test your email configuration card of
 * Advanced Parameters > E-mail.
 *
 * Sent through the core's EmailConfigurationTester, built here without the
 * Symfony container: the test then has the text, the subject, the escaping
 * and the failure sentence of the running PrestaShop version, which differ
 * between 8 and 9, instead of a copy of them that could drift. The page
 * sends what its form holds; this tool sends the saved settings, which are
 * what every e-mail of the shop uses.
 */

use PrestaShop\PrestaShop\Adapter\Configuration as CoreConfiguration;
use PrestaShop\PrestaShop\Adapter\Email\EmailConfigurationTester;

if (!defined('_PS_VERSION_')) {
    exit;
}

// The settings are read as somcp_get_email_settings reads them.
require_once __DIR__ . '/SomcpToolGetEmailSettings.php';

class SomcpToolSendTestEmail
{
    /** Codes of PS_MAIL_METHOD, the inverse of SomcpToolGetEmailSettings::METHODS. */
    private const METHOD_CODES = ['sendmail' => 1, 'smtp' => 2, 'never' => 3];

    /** The longest transport message answered, so a verbose server cannot flood the reply. */
    private const MESSAGE_LENGTH = 500;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_send_test_email',
            Somcp::t('Send a test e-mail'),
            'Sends the test e-mail of Advanced Parameters > E-mail with the settings the shop has saved, to '
                . 'send_email_to or, left out, to the shop\'s own address. Use it after somcp_update_email_settings '
                . 'to prove the shop can send before the first order e-mail is due; a failure answers the server\'s '
                . 'own message. Sends one e-mail per call. Refused when Never send emails is chosen. To change the '
                . 'settings use somcp_update_email_settings; for what was sent use somcp_list_email_logs.',
            [
                'type' => 'object',
                'properties' => [
                    'send_email_to' => [
                        'type' => 'string',
                        'description' => 'Send a test email to. Left out: the shop\'s e-mail address, as the page fills it.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::SYSTEM_WRITE],
            [self::class, 'handle'],
            // The page lets any right on it send the test; edit is asked, as
            // for somcp_update_email_settings, since the e-mail goes out in
            // the shop's name.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminEmails')
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
        $idShop = Somcp::currentShopId();
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $get = static function (string $key) use ($idShopGroup, $idShop): string {
            return (string) Configuration::get($key, null, $idShopGroup, $idShop);
        };

        $problems = SomcpTool::unknownFields($arguments, ['send_email_to']);

        $to = $get('PS_SHOP_EMAIL');
        if (array_key_exists('send_email_to', $arguments) && $arguments['send_email_to'] !== null) {
            $given = $arguments['send_email_to'];
            $to = is_string($given) ? trim($given) : '';
        }
        if ($to === '' || !Validate::isEmail($to)) {
            $problems[] = 'send_email_to: an e-mail address';
        }

        $settings = SomcpToolGetEmailSettings::settings($idShop);
        // The page would still send through sendmail and report a success
        // that the shop's own e-mails never get.
        if ($settings['mail_method'] === 'never') {
            $problems[] = 'mail_method: Never send emails is chosen, so the shop sends no e-mail - change it with '
                . 'somcp_update_email_settings first';
        }
        if ($problems !== []) {
            return SomcpTool::refused('sent', $problems);
        }

        // The two secrets settings() never answers are read for the core
        // only; an empty password makes the tester take the stored one.
        $dkimKey = $get('PS_MAIL_DKIM_KEY');
        $config = [
            'mail_method' => self::METHOD_CODES[$settings['mail_method']] ?? 1,
            'smtp_server' => (string) $settings['smtp_server'],
            'smtp_username' => (string) $settings['smtp_username'],
            'smtp_password' => '',
            'smtp_port' => $settings['smtp_port'] === null ? '' : (string) $settings['smtp_port'],
            'smtp_encryption' => (string) $settings['smtp_encryption'],
            'send_email_to' => $to,
            'dkim_enable' => (bool) $settings['dkim_enabled'],
            'dkim_key' => $dkimKey,
            'dkim_domain' => (string) $settings['dkim_domain'],
            'dkim_selector' => (string) $settings['dkim_selector'],
        ];

        try {
            $tester = new EmailConfigurationTester(new CoreConfiguration(), Somcp::legacyContext()->getTranslator());
            $errors = $tester->testConfiguration($config);
        } catch (Throwable $e) {
            $errors = ['The test e-mail could not be sent.', $e->getMessage()];
        }

        if ($errors !== []) {
            // The outcome only: the address and the transport message may
            // name a customer or the server's credentials.
            SomcpLogger::log('email_test_failed', SomcpLogger::OUTCOME_FAILURE, (string) $settings['mail_method'], '', $idEmployee);

            $lines = [(string) array_shift($errors)];
            $message = trim(implode(' ', array_map('strval', $errors)));
            if ($message !== '') {
                $lines[] = Tools::substr(self::withoutSecrets($message, [$get('PS_MAIL_PASSWD'), $dkimKey]), 0, self::MESSAGE_LENGTH);
            }
            $lines[] = 'Read the settings with somcp_get_email_settings.';

            return SomcpTool::text(implode("\n", $lines), true);
        }

        SomcpLogger::log('email_test_sent', SomcpLogger::OUTCOME_SUCCESS, (string) $settings['mail_method'], '', $idEmployee);

        return SomcpTool::json([
            'sent' => true,
            'send_email_to' => $to,
            'mail_method' => $settings['mail_method'],
            'warning' => 'Check the inbox of ' . $to . ', spam folder included: the shop handed the e-mail over, '
                . 'which does not prove it arrived.',
        ]);
    }

    /**
     * The transport's message with the stored secrets taken out.
     *
     * A mail library may quote what it was given when it fails; neither the
     * SMTP password nor the DKIM key may reach the answer.
     *
     * @param string $message
     * @param array<int, string> $secrets
     *
     * @return string
     */
    private static function withoutSecrets(string $message, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '<hidden>', $message);
            }
        }

        return $message;
    }
}
