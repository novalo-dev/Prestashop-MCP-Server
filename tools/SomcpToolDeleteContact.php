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
 * somcp_delete_contact - the Delete action of a contact of Shop Parameters >
 * Contact.
 *
 * As the core's ContactDeleter does, through Contact::delete(). One check the
 * page does not make: the contact chosen by Send emails to is kept, since an
 * assistant deleting it would leave the customers' messages without a
 * receiver and nothing on screen would say so.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolCreateContact.php';

class SomcpToolDeleteContact
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_contact',
            Somcp::t('Delete a contact'),
            'Deletes a contact of Shop Parameters > Contact, as its Delete action: one contact per call, Delete '
                . 'selected being the same call repeated. Answers the contact as it was, in every language, so it '
                . 'can be created again. Refused for the contact that receives the customers\' messages (Send emails '
                . 'to). Cannot be undone: ask first. To change a contact use somcp_update_contact; for the '
                . 'id_contact use somcp_list_contacts.',
            [
                'type' => 'object',
                'properties' => [
                    'id_contact' => [
                        'type' => 'integer',
                        'description' => 'The contact to delete, from somcp_list_contacts.',
                    ],
                ],
                'required' => ['id_contact'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminContacts')
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
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = SomcpTool::unknownFields($arguments, ['id_contact']);

        $contact = null;
        $idContact = SomcpTool::id($arguments, 'id_contact');
        if (!array_key_exists('id_contact', $arguments)) {
            $errors[] = 'id_contact: required - find it with somcp_list_contacts';
        } elseif ($idContact === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_contact');
        } else {
            $loaded = new Contact($idContact);
            // A contact of another shop is answered as unknown, as the back
            // office of this shop does not list it.
            if (Validate::isLoadedObject($loaded) && $loaded->isAssociatedToShop($idShop)) {
                $contact = $loaded;
            } else {
                $errors[] = SomcpTool::notFound('id_contact', 'contact', $idContact, 'somcp_list_contacts');
            }
        }

        // Send emails to is read as somcp_list_contacts reads it, so the
        // contact this tool keeps is the one the list marks as the receiver.
        if ($contact !== null && SomcpToolGetEmailSettings::settings($idShop)['send_emails_to'] === $idContact) {
            $errors[] = 'id_contact: this contact receives the customers\' messages (Send emails to) - choose '
                . 'another with somcp_update_email_settings first';
        }

        if ($errors !== [] || $contact === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        // Read before it goes: the answer is what it takes to create it again.
        $before = SomcpToolCreateContact::answer($idContact, $idShop);

        // The shop of the token only, as somcp_update_contact writes it: the
        // core removes the contact itself once no shop holds it any more.
        $contact->id_shop_list = [$idShop];
        try {
            $done = (bool) $contact->delete();
            $failure = $done ? '' : 'PrestaShop refused to delete it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('contact_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'contact ' . $idContact . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text(
                'The contact could not be deleted: ' . $failure . '. Read it with somcp_list_contacts before '
                    . 'calling again.',
                true
            );
        }
        // The identifier only: the contact's address may be a person's.
        SomcpLogger::log('contact_deleted', SomcpLogger::OUTCOME_SUCCESS, 'contact ' . $idContact, '', $idEmployee);

        $result = [
            'deleted' => true,
            'id_contact' => $idContact,
            'contact' => $before,
        ];
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        if ((Contact::getContacts($idLang) ?: []) === []) {
            $result['warning'] = SomcpToolListContacts::NO_CONTACT;
        }

        return SomcpTool::json($result);
    }
}
