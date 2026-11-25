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
 * somcp_get_email_settings - the Email card of Advanced Parameters > E-mail.
 *
 * Whether customers get their order confirmations depends on how the shop
 * sends its e-mails; this read lets the assistant check it before blaming a
 * customer's mailbox. The keys are those EmailDataConfigurator reads, for the
 * shop of the token. The SMTP password and the DKIM private key are secrets:
 * only whether each is set is answered.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetEmailSettings
{
    /** @var array<int, string> PS_MAIL_METHOD, as stored. */
    public const METHODS = [1 => 'sendmail', 2 => 'smtp', 3 => 'never'];

    /** @var array<int, string> PS_MAIL_TYPE, as stored. */
    public const TYPES = [1 => 'html', 2 => 'text', 3 => 'both'];

    /** The warning of a shop set to send nothing, which the update replaces with its own. */
    public const NEVER_WARNING = 'The shop sends no e-mail at all: customers get no order confirmation.';

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_email_settings',
            Somcp::t('Read the e-mail settings'),
            'Reads the Email card of Advanced Parameters > E-mail: where customer messages go (with the contacts '
                . 'to choose from), how the shop sends e-mails (sendmail, SMTP or never), the SMTP server, username, '
                . 'encryption and port, the subject prefix, the format, the e-mail log and DKIM signing. The SMTP '
                . 'password and the DKIM private key are never returned, only whether each is set. Read only. To '
                . 'change them use somcp_update_email_settings; for the e-mail of an order status use '
                . 'somcp_resend_order_status_email.',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::SYSTEM_READ],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminEmails')
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
        $problems = SomcpTool::unknownFields($arguments, []);
        if ($problems !== []) {
            return SomcpTool::refused('read', $problems);
        }

        $idShop = Somcp::currentShopId();
        $result = self::settings($idShop);
        $warnings = self::warnings($result);
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }

    /**
     * What the settings leave the customers without, as sentences.
     *
     * Public so the update answers what it stored with the same sentences
     * as the read.
     *
     * @param array<string, mixed> $settings as settings() reads them
     *
     * @return array<int, string>
     */
    public static function warnings(array $settings): array
    {
        if ($settings['mail_method'] === 'never') {
            return [self::NEVER_WARNING];
        }
        if ($settings['mail_method'] === 'smtp' && $settings['smtp_server'] === '') {
            return ['SMTP is chosen but no SMTP server is set: e-mails cannot leave the shop.'];
        }

        return [];
    }

    /**
     * The e-mail settings of a shop, under the names the update takes back.
     *
     * Public so a tool that changes them compares with, and answers with,
     * this very reading rather than a second shape that could drift. The two
     * secrets are answered as set or not, never as their value.
     *
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    public static function settings(int $idShop): array
    {
        // The group of the shop too, so a value set for the group of shops is
        // read as the shop applies it.
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $get = static function (string $key) use ($idShopGroup, $idShop) {
            return Configuration::get($key, null, $idShopGroup, $idShop);
        };

        $stored = trim((string) $get('PS_MAIL_EMAIL_MESSAGE'));
        $choices = [];
        $idLang = (int) $get('PS_LANG_DEFAULT');
        foreach (Contact::getContacts($idLang) ?: [] as $contact) {
            $choices[] = ['id_contact' => (int) $contact['id_contact'], 'name' => (string) $contact['name']];
        }
        $port = trim((string) $get('PS_MAIL_SMTP_PORT'));
        $encryption = (string) $get('PS_MAIL_SMTP_ENCRYPTION');

        return [
            // No row is stored until the page is saved: null, the list then
            // showing its first contact.
            'send_emails_to' => $stored !== '' ? (int) $stored : null,
            'send_emails_to_choices' => $choices,
            'mail_method' => self::METHODS[(int) $get('PS_MAIL_METHOD')] ?? self::METHODS[1],
            'smtp_domain' => (string) $get('PS_MAIL_DOMAIN'),
            'smtp_server' => (string) $get('PS_MAIL_SERVER'),
            'smtp_username' => (string) $get('PS_MAIL_USER'),
            'smtp_password_set' => (string) $get('PS_MAIL_PASSWD') !== '',
            'smtp_encryption' => in_array($encryption, ['off', 'tls', 'ssl'], true) ? $encryption : 'off',
            'smtp_port' => $port !== '' ? (int) $port : null,
            // The setting exists from PrestaShop 9 only.
            'subject_prefix' => version_compare(_PS_VERSION_, '9.0.0', '>=') ? (bool) $get('PS_MAIL_SUBJECT_PREFIX') : null,
            'mail_type' => self::TYPES[(int) $get('PS_MAIL_TYPE')] ?? self::TYPES[1],
            'log_emails' => (bool) $get('PS_LOG_EMAILS'),
            'dkim_enabled' => (bool) $get('PS_MAIL_DKIM_ENABLE'),
            'dkim_domain' => (string) $get('PS_MAIL_DKIM_DOMAIN'),
            'dkim_selector' => (string) $get('PS_MAIL_DKIM_SELECTOR'),
            'dkim_key_set' => (string) $get('PS_MAIL_DKIM_KEY') !== '',
        ];
    }
}
