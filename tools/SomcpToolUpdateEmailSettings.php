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
 * somcp_update_email_settings - the Save of the Email card of Advanced
 * Parameters > E-mail.
 *
 * Saved by their configuration keys for the shop of the token, as the core's
 * EmailDataConfigurator::updateConfiguration() saves them, with no other
 * step. The SMTP password and the DKIM private key are never written: what an
 * assistant is given stays in its conversation, so the merchant types them on
 * the page. Compared with, and answered with, the reading of
 * somcp_get_email_settings, so the read given back whole writes nothing.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// read before and after, and its words for the stored codes.
require_once __DIR__ . '/SomcpToolGetEmailSettings.php';

class SomcpToolUpdateEmailSettings
{
    /**
     * The parameters, keyed to the configuration key each is saved under, in
     * the order of the card.
     */
    private const KEYS = [
        'send_emails_to' => 'PS_MAIL_EMAIL_MESSAGE',
        'mail_method' => 'PS_MAIL_METHOD',
        'smtp_domain' => 'PS_MAIL_DOMAIN',
        'smtp_server' => 'PS_MAIL_SERVER',
        'smtp_username' => 'PS_MAIL_USER',
        'smtp_encryption' => 'PS_MAIL_SMTP_ENCRYPTION',
        'smtp_port' => 'PS_MAIL_SMTP_PORT',
        'subject_prefix' => 'PS_MAIL_SUBJECT_PREFIX',
        'mail_type' => 'PS_MAIL_TYPE',
        'log_emails' => 'PS_LOG_EMAILS',
        'dkim_enabled' => 'PS_MAIL_DKIM_ENABLE',
        'dkim_domain' => 'PS_MAIL_DKIM_DOMAIN',
        'dkim_selector' => 'PS_MAIL_DKIM_SELECTOR',
    ];

    /**
     * What somcp_get_email_settings answers beside the settings: given back
     * as read they are no change, given otherwise they are named as the
     * shop's, not as misspelt fields.
     */
    private const COMPUTED = ['send_emails_to_choices', 'smtp_password_set', 'dkim_key_set', 'warning'];

    /** The free text fields, checked as the core's form leaves them: optional. */
    private const TEXTS = ['smtp_domain', 'smtp_server', 'smtp_username', 'dkim_domain', 'dkim_selector'];

    private const BOOLEANS = ['subject_prefix', 'log_emails', 'dkim_enabled'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $text = static function (string $label): array {
            return ['type' => 'string', 'description' => $label . ', as on the page. An empty string empties it.'];
        };

        return new SomcpTool(
            'somcp_update_email_settings',
            Somcp::t('Change the e-mail settings'),
            'Changes the Email card of Advanced Parameters > E-mail, as its Save: where customer messages go, how '
                . 'the shop sends e-mails (sendmail, SMTP or never), the SMTP server, username, encryption and port, '
                . 'the subject prefix, the format, the e-mail log and DKIM signing. Only the fields given change; '
                . 'the answer of somcp_get_email_settings can be given back. The SMTP password and the DKIM private '
                . 'key are typed by the merchant on that page and never go through this tool.',
            [
                'type' => 'object',
                'properties' => [
                    'send_emails_to' => [
                        'type' => 'integer',
                        'description' => 'Send emails to: the contact that receives customer messages, by its '
                            . 'id_contact from send_emails_to_choices of somcp_get_email_settings.',
                    ],
                    'mail_method' => [
                        'type' => 'string',
                        'enum' => array_values(SomcpToolGetEmailSettings::METHODS),
                        'description' => 'How the shop sends e-mails: sendmail (the PHP mail() function), smtp '
                            . '(the SMTP fields below) or never (no e-mail at all).',
                    ],
                    'smtp_domain' => $text('Email domain name'),
                    'smtp_server' => $text('SMTP server'),
                    'smtp_username' => $text('SMTP username'),
                    'smtp_encryption' => [
                        'type' => 'string',
                        'enum' => ['off', 'tls', 'ssl'],
                        'description' => 'Encryption: off or tls, and ssl on PrestaShop 8 only.',
                    ],
                    'smtp_port' => [
                        'type' => ['integer', 'null'],
                        'description' => 'Port, 1 to 65535. null empties it.',
                    ],
                    'subject_prefix' => [
                        'type' => ['boolean', 'null'],
                        'description' => 'Enable the store name as a prefix in the email\'s subject. PrestaShop 9 '
                            . 'only; null, as read on PrestaShop 8, is ignored.',
                    ],
                    'mail_type' => [
                        'type' => 'string',
                        'enum' => array_values(SomcpToolGetEmailSettings::TYPES),
                        'description' => 'The format of the e-mails sent: html, text or both.',
                    ],
                    'log_emails' => ['type' => 'boolean', 'description' => 'Log Emails.'],
                    'dkim_enabled' => ['type' => 'boolean', 'description' => 'DKIM signing.'],
                    'dkim_domain' => $text('DKIM domain'),
                    'dkim_selector' => $text('DKIM selector'),
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::SYSTEM_WRITE],
            [self::class, 'handle'],
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
        $idShop = Somcp::currentShopId();
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $idEmployee = (int) ($context['id_employee'] ?? 0);
        $before = SomcpToolGetEmailSettings::settings($idShop);
        $problems = [];

        // The two secrets are refused by any name that holds them, before
        // anything else is read, so a value given never reaches a save or a
        // log line.
        $checked = [];
        foreach ($arguments as $key => $value) {
            $key = (string) $key;
            $secret = preg_match('/passw|dkim_key|private_key/i', $key) === 1 && !in_array($key, self::COMPUTED, true);
            if ($secret) {
                $problems[] = $key . ': type it on the E-mail page of the back office; it never goes through a tool';
            } else {
                $checked[$key] = $value;
            }
        }

        // What the read worked out, given back as it reads, is no change and
        // is taken out, so a read given back whole is not refused for it.
        foreach (self::COMPUTED as $key) {
            if (array_key_exists($key, $checked)
                && ($key === 'warning' || json_encode($checked[$key]) === json_encode($before[$key]))) {
                unset($checked[$key]);
            }
        }

        // Every problem in one answer (#169), so the call is corrected in one go.
        $problems = array_merge($problems, SomcpTool::unknownFields($checked, array_keys(self::KEYS), self::COMPUTED));
        $given = self::given($checked, $before, $problems);

        if ($problems !== []) {
            return SomcpTool::refused('changed', $problems);
        }

        $changes = [];
        foreach ($given as $field => $value) {
            if ($value !== $before[$field]) {
                $changes[] = ['field' => $field, 'old' => $before[$field], 'new' => $value];
            }
        }
        if ($changes === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The e-mail settings already hold every value given. Nothing was written.',
                'settings' => $before,
            ]);
        }

        $saved = [];
        foreach ($changes as $change) {
            $field = $change['field'];
            if (!Configuration::updateValue(self::KEYS[$field], self::stored($field, $change['new']), false, $idShopGroup, $idShop)) {
                return self::failed($saved, $field . ' could not be saved', $idEmployee);
            }
            $saved[] = $field;
        }
        // The names of the fields only: a username or a server is the
        // merchant's account, and the shop's logs are kept for good.
        SomcpLogger::log('email_settings_updated', SomcpLogger::OUTCOME_SUCCESS, implode(', ', $saved), '', $idEmployee);

        // Read back rather than echoed: what the assistant shows is what the
        // shop stored.
        $after = SomcpToolGetEmailSettings::settings($idShop);
        $result = ['changed' => true, 'changes' => $changes];
        // The warnings of the read too, after this call's own: the read's
        // sentence for never is replaced by the one saying it just happened.
        $readWarnings = SomcpToolGetEmailSettings::warnings($after);
        $warnings = [];
        if (in_array('mail_method', $saved, true) && $after['mail_method'] === 'never') {
            $warnings[] = 'The shop now sends no e-mail at all: customers get no order confirmation.';
            $readWarnings = array_diff($readWarnings, [SomcpToolGetEmailSettings::NEVER_WARNING]);
        }
        if (in_array('mail_method', $saved, true) && $after['mail_method'] === 'smtp'
            && !$after['smtp_password_set'] && $after['smtp_username'] !== '') {
            $warnings[] = 'The SMTP password is not set: type it on the E-mail page of the back office.';
        }
        if (in_array('dkim_enabled', $saved, true) && $after['dkim_enabled'] && !$after['dkim_key_set']) {
            $warnings[] = 'DKIM is on but no private key is set: type it on the E-mail page of the back office.';
        }
        $warnings = array_merge($warnings, array_values($readWarnings));
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'The settings changed for this shop only, as the back office saves them in a shop context.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        $result['settings'] = $after;

        return SomcpTool::json($result);
    }

    /**
     * The values the call gives, under the names and words of the read, each
     * checked as the core's form checks it. A value equal to the one read is
     * taken as it is, so the read given back whole is never refused for a
     * setting stored before the screen offered it.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $before
     * @param array<int, string> $problems
     *
     * @return array<string, mixed>
     */
    private static function given(array $arguments, array $before, array &$problems): array
    {
        $given = [];
        foreach (array_keys(self::KEYS) as $field) {
            if (!array_key_exists($field, $arguments)) {
                continue;
            }
            $value = $arguments[$field];
            if ($value === $before[$field]) {
                $given[$field] = $value;
                continue;
            }
            if ($field === 'send_emails_to') {
                $id = is_int($value) ? $value : 0;
                if ($id <= 0) {
                    $problems[] = SomcpTool::notAnIdentifier($field);
                } elseif (!in_array($id, array_column($before['send_emails_to_choices'], 'id_contact'), true)) {
                    $problems[] = SomcpTool::notFound($field, 'contact', $id, 'somcp_get_email_settings');
                } else {
                    $given[$field] = $id;
                }
            } elseif ($field === 'mail_method' || $field === 'mail_type') {
                $words = array_values($field === 'mail_method' ? SomcpToolGetEmailSettings::METHODS : SomcpToolGetEmailSettings::TYPES);
                if (is_string($value) && in_array($value, $words, true)) {
                    $given[$field] = $value;
                } else {
                    $problems[] = SomcpTool::oneOf($field, $words);
                }
            } elseif ($field === 'smtp_encryption') {
                // ssl left the choices of the form in PrestaShop 9.
                $words = version_compare(_PS_VERSION_, '9.0.0', '>=') ? ['off', 'tls'] : ['off', 'tls', 'ssl'];
                if (is_string($value) && in_array($value, $words, true)) {
                    $given[$field] = $value;
                } else {
                    $problems[] = SomcpTool::oneOf($field, $words);
                }
            } elseif ($field === 'smtp_port') {
                if ($value === null || (is_int($value) && $value >= 1 && $value <= 65535)) {
                    $given[$field] = $value;
                } else {
                    $problems[] = 'smtp_port: a port number from 1 to 65535';
                }
            } elseif (in_array($field, self::BOOLEANS, true)) {
                if ($field === 'subject_prefix' && $value !== null && $before['subject_prefix'] === null) {
                    $problems[] = SomcpTool::notOnThisVersion($field, 'store name prefix in the subject of e-mails');
                } elseif ($value === null && $field === 'subject_prefix') {
                    continue;
                } elseif (is_bool($value)) {
                    $given[$field] = $value;
                } else {
                    $problems[] = $field . ': true or false';
                }
            } elseif (in_array($field, self::TEXTS, true)) {
                if (!is_string($value)) {
                    $problems[] = $field . ': a text';
                } elseif (!Validate::isCleanHtml($value)) {
                    $problems[] = $field . ': holds a script or an event attribute, which PrestaShop refuses';
                } else {
                    $given[$field] = $value;
                }
            }
        }

        return $given;
    }

    /**
     * A value under the name of the read turned back into what the core
     * stores: the codes of the method and the format, 0 or 1, '' for an
     * emptied port.
     *
     * @param string $field
     * @param mixed $value
     *
     * @return string|int
     */
    private static function stored(string $field, $value)
    {
        if ($field === 'mail_method') {
            return (int) array_search($value, SomcpToolGetEmailSettings::METHODS, true);
        }
        if ($field === 'mail_type') {
            return (int) array_search($value, SomcpToolGetEmailSettings::TYPES, true);
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value === null) {
            return '';
        }

        return is_int($value) ? $value : (string) $value;
    }

    /**
     * The answer to a save that stopped half way, naming what was saved so
     * the assistant reads the settings before trying again.
     *
     * @param array<int, string> $saved
     * @param string $failure
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function failed(array $saved, string $failure, int $idEmployee): array
    {
        SomcpLogger::log('email_settings_update_failed', SomcpLogger::OUTCOME_FAILURE, $failure, '', $idEmployee);

        return SomcpTool::text(
            'The e-mail settings could not be changed: ' . $failure
                . ($saved !== [] ? ', after ' . implode(', ', $saved) . ' had been saved' : '')
                . '. Read them with somcp_get_email_settings before calling again.',
            true
        );
    }
}
