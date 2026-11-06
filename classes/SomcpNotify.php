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
 * SomcpNotify - the e-mail sent each time an employee connects an assistant.
 *
 * A connection approved by mistake - a merchant who clicked through a page
 * someone else sent them - only does harm for as long as nobody notices. The
 * e-mail goes to the employee who approved it and to the shop's address, and
 * the super administrators get a blind copy, so whoever can disconnect the
 * assistant hears of it the same minute.
 *
 * One e-mail in one language: the shop's default language when the module is
 * translated into it, English otherwise. The templates are PrestaShop mail
 * templates (mails/<iso>/somcp_new_assistant), listed with the shop's other
 * e-mails, so a merchant can reword them like any other; Mail::send() falls
 * back on the English one by itself for a language the module does not have.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpNotify
{
    /** @var string */
    public const TEMPLATE = 'somcp_new_assistant';

    /** @var array<int, string> the languages the module's e-mails exist in */
    private const LANGUAGES = ['en', 'fr', 'es', 'de'];

    /**
     * Sends the e-mail about a new connection. Never throws: a shop that cannot
     * send e-mail still connects its assistant.
     *
     * @param array<string, mixed> $client the client row
     * @param Employee $employee who approved the connection
     * @param int $idShop the shop the assistant was connected to
     * @param string $redirectUri where the assistant receives its answers
     *
     * @return bool whether PrestaShop accepted the e-mail
     */
    public static function newAssistant(array $client, Employee $employee, int $idShop, string $redirectUri): bool
    {
        try {
            $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
            $language = new Language($idLang);
            $locale = in_array((string) $language->iso_code, self::LANGUAGES, true) ? (string) $language->locale : 'en-US';

            $to = [];
            $names = [];
            foreach ([
                (string) $employee->email => trim($employee->firstname . ' ' . $employee->lastname),
                (string) Configuration::get('PS_SHOP_EMAIL', null, null, $idShop) => (string) Configuration::get('PS_SHOP_NAME', null, null, $idShop),
            ] as $email => $name) {
                if (Validate::isEmail($email) && !in_array(Tools::strtolower($email), array_map('strtolower', $to), true)) {
                    $to[] = $email;
                    $names[] = $name;
                }
            }
            if ($to === []) {
                return false;
            }

            $bcc = [];
            foreach (self::superAdministrators() as $email) {
                if (!in_array(Tools::strtolower($email), array_map('strtolower', $to), true)) {
                    $bcc[] = $email;
                }
            }

            $shop = new Shop($idShop);
            $sent = Mail::send(
                $idLang,
                self::TEMPLATE,
                Context::getContext()->getTranslator()->trans('A new assistant is connected to your shop', [], 'Modules.Somcp.Admin', $locale),
                [
                    // The name is the one the application gave itself: the
                    // characters that could open markup in the HTML version
                    // are dropped, since the same value fills the text one.
                    '{client_name}' => (string) preg_replace('/[<>&"\'`]/', '', SomcpOAuthClient::displayName($client)),
                    '{return_host}' => Tools::strtolower((string) parse_url($redirectUri, PHP_URL_HOST)),
                    '{employee_email}' => (string) $employee->email,
                    '{connected_shop}' => Validate::isLoadedObject($shop) ? (string) $shop->name : (string) Configuration::get('PS_SHOP_NAME'),
                    '{connected_at}' => date('Y-m-d H:i'),
                ],
                $to,
                $names,
                null,
                null,
                null,
                null,
                _PS_MODULE_DIR_ . 'somcp/mails/',
                false,
                $idShop,
                $bcc === [] ? null : $bcc
            );
        } catch (Throwable $e) {
            SomcpLogger::log('new_assistant_mail_failed', SomcpLogger::OUTCOME_FAILURE, Tools::substr($e->getMessage(), 0, 200));

            return false;
        }

        if (!$sent) {
            SomcpLogger::log('new_assistant_mail_failed', SomcpLogger::OUTCOME_FAILURE, 'the shop did not send the e-mail');
        }

        return (bool) $sent;
    }

    /**
     * Addresses of the active super administrators.
     *
     * @return array<int, string>
     */
    private static function superAdministrators(): array
    {
        $rows = Db::getInstance()->executeS('SELECT `email` FROM `' . _DB_PREFIX_ . 'employee`'
            . ' WHERE `active` = 1 AND `id_profile` = ' . (int) _PS_ADMIN_PROFILE_);

        $emails = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (Validate::isEmail((string) $row['email'])) {
                $emails[] = (string) $row['email'];
            }
        }

        return array_values(array_unique($emails));
    }
}
