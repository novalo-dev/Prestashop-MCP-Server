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
 * somcp_create_contact - Add new contact of Shop Parameters > Contact.
 *
 * The MCP endpoint has no command bus, so the tool does on the Contact
 * object what the core's AddContactHandler does: the title of every language
 * being filled, the address, Save messages? and the description, checked
 * with the rules of the back office form (ContactType), then add() for the
 * shop of the token. The checks are public so the update of a contact applies
 * the very same ones.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The languages and texts of #149, shared with every create tool.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
// The answer is a contact as the list gives one.
require_once __DIR__ . '/SomcpToolListContacts.php';
require_once __DIR__ . '/SomcpToolGetEmailSettings.php';

class SomcpToolCreateContact extends SomcpToolCreateProduct
{
    /** @var array<int, string> The fields of a contact this tool and the update take. */
    public const CONTACT_FIELDS = ['languages', 'name', 'email', 'customer_service', 'description'];

    /** ContactType::MAX_TITLE_LENGTH, the size of the name and e-mail columns too. */
    public const MAX_LENGTH = 255;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_contact',
            Somcp::t('Create a contact'),
            'Creates a contact of Shop Parameters > Contact, as Add new contact: a title per language, the address '
                . 'that receives its messages, whether they are kept in Customer Service, a description. The contact '
                . 'form of the storefront offers it, and somcp_update_email_settings can make it the receiver of '
                . 'the messages (send_emails_to). Read somcp_list_contacts first: two contacts may share a title. One '
                . 'contact per call, not to be retried.',
            self::contactSchema(['name']),
            [SomcpScope::CUSTOMERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::ADD, 'AdminContacts')
        );
    }

    /**
     * The fields of a contact, under the names somcp_list_contacts answers.
     *
     * Public so the update describes them in the same words.
     *
     * @param array<int, string> $required
     *
     * @return array<string, mixed>
     */
    public static function contactSchema(array $required): array
    {
        $text = static function (string $description): array {
            return [
                'type' => 'object',
                'description' => $description . ' One text per language being filled, keyed by ISO code: {"fr": "...", "en": "..."}.',
                'additionalProperties' => ['type' => 'string'],
            ];
        };
        $schema = [
            'type' => 'object',
            'properties' => [
                'languages' => [
                    'type' => 'array',
                    'description' => 'ISO codes of the languages to fill, among the active languages of the shop. '
                        . 'All of them by default. Every text given must then be given in each of them.',
                    'items' => ['type' => 'string'],
                ],
                'name' => $text('Title, the name the contact form of the storefront shows, 255 characters at most, '
                    . 'without < > = { }.'),
                'email' => [
                    'type' => 'string',
                    'description' => 'Email address: the messages sent to this contact go there. Empty: none.',
                ],
                'customer_service' => [
                    'type' => 'boolean',
                    'description' => 'Save messages?: whether its messages are kept in Customer Service. false by '
                        . 'default, as in the form.',
                ],
                'description' => $text('Description shown on the contact form, without HTML tags.'),
            ],
            'additionalProperties' => false,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
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

        // id_contact and used_for_customer_messages come with a contact read
        // from the list, and are the shop's to set.
        $errors = SomcpTool::unknownFields($arguments, self::CONTACT_FIELDS, ['id_contact', 'used_for_customer_messages']);
        $plan = self::planContact($arguments, $idShop, $errors, true);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::refused('created', $errors);
        }

        $contact = new Contact();
        // A language not being filled takes the default one's text, as the
        // form fills it; a description left out is empty in every language.
        self::fill($contact, [
            'name' => self::complete($plan['name'] ?? [], $plan['languages']),
            'description' => isset($plan['description'])
                ? self::complete($plan['description'], $plan['languages'])
                : array_fill_keys($plan['languages']['all'], ''),
            'email' => $plan['email'] ?? '',
            'customer_service' => (bool) ($plan['customer_service'] ?? false),
        ]);
        // The shop of the token only, never the context's list, as
        // somcp_create_attribute_group does.
        $contact->id_shop_list = [$idShop];

        try {
            $done = (bool) $contact->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('contact_create_failed', SomcpLogger::OUTCOME_FAILURE, Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text('The contact could not be created: ' . $failure . '.', true);
        }
        $idContact = (int) $contact->id;
        SomcpLogger::log('contact_created', SomcpLogger::OUTCOME_SUCCESS, 'contact ' . $idContact, '', $idEmployee);

        $result = ['created' => true, 'id_contact' => $idContact, 'contact' => self::answer($idContact, $idShop)];
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $result['warning'] = 'The contact was created for this shop only; the back office can share it with '
                . 'others (Shop association).';
        }

        return SomcpTool::json($result);
    }

    /**
     * The fields given, checked with the rules of the back office form.
     *
     * Public so the update of a contact checks with the same rules. Every
     * problem is added to $errors, so a call is corrected in one go. The
     * texts are keyed by language identifier, for the languages being filled
     * only; a field left out is absent from the plan.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $errors
     * @param bool $nameRequired false when changing a contact, which has its title already
     *
     * @return array<string, mixed>|null
     */
    public static function planContact(array $arguments, int $idShop, array &$errors, bool $nameRequired): ?array
    {
        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }
        $plan = ['languages' => $languages];

        // Given, a title is never empty: the core requires it.
        $names = self::texts($arguments, 'name', 'name', $languages, $errors, $nameRequired || array_key_exists('name', $arguments));
        if ($names !== null) {
            foreach ($names as $idLang => $name) {
                $name = trim($name);
                $names[$idLang] = $name;
                $where = 'name (' . $languages['filled'][$idLang] . ')';
                // The pattern of ContactType, which the storefront form relies on.
                if (preg_match('/[<>={}]/u', $name)) {
                    $errors[] = $where . ': no <, >, =, { or }';
                } elseif (Tools::strlen($name) > self::MAX_LENGTH) {
                    $errors[] = $where . ': ' . self::MAX_LENGTH . ' characters at most';
                }
            }
            $plan['name'] = $names;
        }

        if (array_key_exists('email', $arguments)) {
            $email = is_string($arguments['email']) ? trim($arguments['email']) : null;
            // Empty is allowed, as the form leaves the field optional.
            if ($email === null || ($email !== '' && (!Validate::isEmail($email) || Tools::strlen($email) > self::MAX_LENGTH))) {
                $errors[] = 'email: an e-mail address';
            } else {
                $plan['email'] = $email;
            }
        }

        if (array_key_exists('customer_service', $arguments)) {
            if (!is_bool($arguments['customer_service'])) {
                $errors[] = 'customer_service: true or false';
            } else {
                $plan['customer_service'] = $arguments['customer_service'];
            }
        }

        $descriptions = self::texts($arguments, 'description', 'description', $languages, $errors);
        if ($descriptions !== null) {
            foreach ($descriptions as $idLang => $description) {
                // The NoTags rule of the form.
                if (strip_tags($description) !== $description) {
                    $errors[] = 'description (' . $languages['filled'][$idLang] . '): no HTML tag';
                }
            }
            $plan['description'] = $descriptions;
        }

        return $plan;
    }

    /**
     * A stored contact as the list gives it, plus its texts in every language.
     *
     * Public so the update answers in the same shape.
     *
     * @param int $idContact
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    public static function answer(int $idContact, int $idShop): array
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $read = new Contact($idContact, $idLang);
        $item = SomcpToolListContacts::item([
            'id_contact' => $idContact,
            'name' => $read->name,
            'email' => $read->email,
            'description' => $read->description,
            'customer_service' => $read->customer_service,
        ], SomcpToolGetEmailSettings::settings($idShop)['send_emails_to']);

        $every = new Contact($idContact);
        $names = [];
        $descriptions = [];
        foreach ((array) $every->name as $id => $text) {
            $iso = (string) Language::getIsoById((int) $id);
            if ($iso !== '') {
                $names[$iso] = (string) $text;
                $descriptions[$iso] = (string) (((array) $every->description)[$id] ?? '');
            }
        }
        $item['names'] = $names;
        $item['descriptions'] = $descriptions;

        return $item;
    }
}
