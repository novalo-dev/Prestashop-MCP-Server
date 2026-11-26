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
 * somcp_list_contacts - the Contacts tab of Shop Parameters > Contact.
 *
 * The e-mail settings name a contact by its ID and title only; this read
 * gives each in full, so the assistant can check where the customers'
 * messages go before it changes Send emails to. The IDs and titles are those
 * of send_emails_to_choices, so a contact read here is handed back as it is.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListContacts
{
    /** Said by the list and by somcp_delete_contact when the shop is left without one. */
    public const NO_CONTACT = 'The shop has no contact: the contact form of the storefront cannot send a message.';

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_contacts',
            Somcp::t('List the contacts'),
            'Lists the contacts of Shop Parameters > Contact: the addresses the contact form of the storefront '
                . 'offers, with their title, e-mail address, description, whether their messages are kept in '
                . 'Customer Service, and which one receives the customers\' messages (Send emails to). The '
                . 'id_contact is the one somcp_update_email_settings takes as send_emails_to. Read only. For the '
                . 'e-mail settings themselves use somcp_get_email_settings.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, '
                            . 'for the texts, which are translated; a code the shop does not have is refused. '
                            . 'The shop default language when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_READ],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminContacts')
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
        $language = SomcpTool::readLanguage($arguments, $idShop);

        // Every mistake of the call in one refusal, so it is corrected in one go.
        $problems = SomcpTool::unknownFields($arguments, ['language']);
        if ($language === null) {
            $problems[] = SomcpTool::languageProblem($idShop);
        }
        if ($problems !== [] || $language === null) {
            return SomcpTool::refused('read', $problems);
        }
        [$idLang, $iso] = $language;

        // Send emails to is read exactly as the e-mail settings read it, so
        // the two tools never disagree on which contact receives the messages.
        $receiver = SomcpToolGetEmailSettings::settings($idShop)['send_emails_to'];

        $contacts = [];
        foreach (Contact::getContacts($idLang) ?: [] as $contact) {
            $contacts[] = self::item($contact, $receiver);
        }

        $result = [
            'contacts' => $contacts,
            'count' => count($contacts),
            'language' => $iso,
        ];
        if ($contacts === []) {
            $result['warning'] = self::NO_CONTACT;
        }

        return SomcpTool::json($result);
    }

    /**
     * One contact as the list answers it.
     *
     * Public so the tools that create or change a contact answer it in this
     * very shape, which the assistant can give back to them as it is.
     *
     * @param array<string, mixed> $contact a row of Contact::getContacts(), or the same keys
     * @param int|null $receiver the id_contact of Send emails to, as SomcpToolGetEmailSettings reads it
     *
     * @return array<string, mixed>
     */
    public static function item(array $contact, ?int $receiver): array
    {
        $idContact = (int) $contact['id_contact'];

        return [
            'id_contact' => $idContact,
            'name' => (string) $contact['name'],
            'email' => (string) $contact['email'],
            'description' => (string) $contact['description'],
            'customer_service' => (bool) $contact['customer_service'],
            'used_for_customer_messages' => $receiver !== null && $receiver === $idContact,
        ];
    }
}
