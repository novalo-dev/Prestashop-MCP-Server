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
 * somcp_update_contact - Edit then Save of a contact of Shop Parameters >
 * Contact.
 *
 * As the core's EditContactHandler does, on the Contact object: only the
 * fields given change, each checked by SomcpToolCreateContact::planContact(),
 * the very rules of the create, so a value the create refuses is refused
 * here too. A contact item of somcp_list_contacts given back as it is
 * changes nothing.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolCreateContact.php';

class SomcpToolUpdateContact
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $schema = SomcpToolCreateContact::contactSchema(['id_contact']);
        $schema['properties'] = [
            'id_contact' => [
                'type' => 'integer',
                'description' => 'The contact to change, from somcp_list_contacts.',
            ],
        ] + $schema['properties'];

        return new SomcpTool(
            'somcp_update_contact',
            Somcp::t('Change a contact'),
            'Changes a contact of Shop Parameters > Contact, as Edit then Save: its title per language, its '
                . 'address, whether its messages are kept in Customer Service, its description. Only the fields '
                . 'given change, a language left out keeping its text; an item of somcp_list_contacts given back '
                . 'as it is changes nothing. To choose which contact receives the customers\' messages use '
                . 'somcp_update_email_settings (send_emails_to); to add one use somcp_create_contact.',
            $schema,
            [SomcpScope::CUSTOMERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminContacts')
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

        $errors = SomcpTool::unknownFields(
            $arguments,
            array_merge(['id_contact', 'used_for_customer_messages'], SomcpToolCreateContact::CONTACT_FIELDS)
        );

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

        // The list answers each text in one language: given back as read on a
        // shop with several, it is no change rather than an ambiguous text.
        $given = $arguments;
        $probe = [];
        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $probe);
        if ($contact !== null && $languages !== null) {
            foreach (['name', 'description'] as $field) {
                if (array_key_exists($field, $given)
                    && SomcpToolCreateProduct::givenBackAsRead($given[$field], self::texts($contact->{$field}), $languages)) {
                    unset($given[$field]);
                }
            }
        }
        $plan = SomcpToolCreateContact::planContact($given, $idShop, $errors, false);

        $receiver = SomcpToolGetEmailSettings::settings($idShop)['send_emails_to'];
        if (array_key_exists('used_for_customer_messages', $arguments)) {
            $current = $receiver !== null && $receiver === $idContact;
            if ($arguments['used_for_customer_messages'] !== $current) {
                $errors[] = 'used_for_customer_messages: not set by this tool - change send_emails_to with '
                    . 'somcp_update_email_settings';
            }
        }

        if ($errors !== [] || $plan === null || $contact === null) {
            return SomcpTool::refused('changed', $errors);
        }

        $changes = [];
        $set = [];
        foreach (['name', 'description'] as $field) {
            if (!isset($plan[$field])) {
                continue;
            }
            $texts = self::texts($contact->{$field});
            $merged = $texts;
            foreach ($plan[$field] as $idLang => $text) {
                $old = $texts[$idLang] ?? '';
                if ($text !== $old) {
                    $changes[] = ['field' => $field . ' (' . $plan['languages']['filled'][$idLang] . ')', 'old' => $old, 'new' => $text];
                    $merged[$idLang] = $text;
                }
            }
            if ($merged !== $texts) {
                $set[$field] = $merged;
            }
        }
        if (isset($plan['email']) && $plan['email'] !== (string) $contact->email) {
            $changes[] = ['field' => 'email', 'old' => (string) $contact->email, 'new' => $plan['email']];
            $set['email'] = $plan['email'];
        }
        if (isset($plan['customer_service']) && $plan['customer_service'] !== (bool) $contact->customer_service) {
            $changes[] = ['field' => 'customer_service', 'old' => (bool) $contact->customer_service, 'new' => $plan['customer_service']];
            $set['customer_service'] = $plan['customer_service'];
        }

        if ($changes === []) {
            return SomcpTool::json([
                'changed' => false,
                'changes' => [],
                'reason' => 'The contact already holds every value given. Nothing was written.',
                'contact' => SomcpToolCreateContact::answer($idContact, $idShop),
            ]);
        }

        foreach ($set as $field => $value) {
            $contact->{$field} = $value;
        }
        // The shop of the token, which the contact already belongs to, never
        // the context's list: its other shops stay as they are, as Shop
        // association is not taken by this tool.
        $contact->id_shop_list = [$idShop];

        try {
            $done = (bool) $contact->update();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('contact_update_failed', SomcpLogger::OUTCOME_FAILURE, 'contact ' . $idContact . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text('The contact could not be changed: ' . $failure . '.', true);
        }
        // The names of the fields only: the values may be an address.
        SomcpLogger::log('contact_updated', SomcpLogger::OUTCOME_SUCCESS, 'contact ' . $idContact . ': '
            . implode(', ', array_column($changes, 'field')), '', $idEmployee);

        return SomcpTool::json([
            'changed' => true,
            'changes' => $changes,
            'contact' => SomcpToolCreateContact::answer($idContact, $idShop),
        ]);
    }

    /**
     * A translated field of the contact as texts keyed by language identifier.
     *
     * @param mixed $value as the Contact object loaded in every language holds it
     *
     * @return array<int, string>
     */
    private static function texts($value): array
    {
        $texts = [];
        foreach (is_array($value) ? $value : [] as $idLang => $text) {
            $texts[(int) $idLang] = (string) $text;
        }

        return $texts;
    }
}
