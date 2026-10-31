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
 * somcp_update_customer - one existing customer account, changed as
 * Customers > Customers > Edit does, plus the private note of the customer
 * page and the Newsletter / Partner offers switches of the list.
 *
 * Every rule is the one somcp_create_customer applies, called from there
 * rather than copied, so a value one tool takes the other takes too. Only
 * the fields given are touched; everything is checked before the account is
 * written, every problem is reported at once, and a call that would change
 * nothing writes nothing.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The rules of the creation, and the header block of the detail for the answer.
require_once __DIR__ . '/SomcpToolGetCustomer.php';
require_once __DIR__ . '/SomcpToolCreateCustomer.php';

class SomcpToolUpdateCustomer
{
    /** @var array<int, string> Every argument this tool takes, under the names somcp_get_customer returns. */
    private const ARGUMENTS = [
        'id_customer', 'firstname', 'lastname', 'email', 'id_gender', 'birthday', 'active', 'partner_offers',
        'newsletter', 'group_ids', 'id_default_group', 'private_note', 'company', 'siret', 'ape', 'website',
        'allowed_outstanding_amount', 'max_payment_days', 'id_risk',
    ];

    /**
     * @var array<int, string> What somcp_get_customer returns and this tool refuses, because the shop works it
     *                         out or another part of the back office writes it. Named, so the assistant learns why.
     */
    private const COMPUTED = [
        'social_title', 'age', 'date_add', 'last_visit', 'customer_language', 'newsletter_date_add', 'date_upd',
        'is_guest', 'currency', 'risk',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $kept = ' Left out: unchanged.';

        return new SomcpTool(
            'somcp_update_customer',
            Somcp::t('Change a customer'),
            'Changes one existing customer account of this shop, as Customers > Customers > Edit: first name, '
                . 'last name, email, social title, birthday, Enabled, partner offers, newsletter, group access and '
                . 'default customer group, plus the private note of the customer page and the B2B fields (company, '
                . 'SIRET, APE, website, allowed outstanding amount, maximum number of payment days, risk rating) when '
                . 'B2B mode is on. Only id_customer is required, from somcp_search_customers; every other field is '
                . 'left as it is when absent. Every field takes the name somcp_get_customer returns, so a value read '
                . 'there can be given back. Every value is the merchant\'s: ask for it, never invent it. '
                . 'The password is never written: the customer sets theirs with "Forgot your password?" on the '
                . 'shop. A guest account cannot be turned into a registered one here. '
                . 'Every problem is reported at once and nothing is written until the whole call is valid. The answer '
                . 'gives each field changed with its old and new value; a call that would change nothing writes '
                . 'nothing and says changed: false. '
                . 'Do not use it to create an account, which is somcp_create_customer, or to change an address. '
                . 'One customer per call. Not to be retried: if the answer is lost, read somcp_get_customer before '
                . 'anything else, to see what the account holds now.',
            [
                'type' => 'object',
                'properties' => [
                    'id_customer' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the customer, from somcp_search_customers.',
                    ],
                    'firstname' => [
                        'type' => 'string',
                        'description' => 'First name: letters, spaces and dots only.' . $kept,
                    ],
                    'lastname' => [
                        'type' => 'string',
                        'description' => 'Last name: letters, spaces and dots only.' . $kept,
                    ],
                    'email' => [
                        'type' => 'string',
                        'description' => 'Email. Refused when another registered account of this shop already uses '
                            . 'it.' . $kept,
                    ],
                    'id_gender' => [
                        'type' => 'integer',
                        'description' => 'Social title, by its identifier in this shop; 0 clears it (an identifier '
                            . 'the shop does not have is refused with the list of its social titles).' . $kept,
                    ],
                    'birthday' => [
                        'type' => ['string', 'null'],
                        'description' => 'Birthday, YYYY-MM-DD, in the past; null clears it.' . $kept,
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Enabled: the customer can log in.' . $kept,
                    ],
                    'partner_offers' => [
                        'type' => 'boolean',
                        'description' => 'Partner offers.' . $kept,
                    ],
                    'newsletter' => [
                        'type' => 'boolean',
                        'description' => 'Newsletter subscription.' . $kept,
                    ],
                    'group_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Group access: the full list of the customer\'s id_group after the change, '
                            . 'not the groups to add.' . $kept,
                    ],
                    'id_default_group' => [
                        'type' => 'integer',
                        'description' => 'Default customer group, which must be one of the customer\'s groups after '
                            . 'the change.' . $kept,
                    ],
                    'private_note' => [
                        'type' => 'string',
                        'description' => 'The private note of the customer page, only seen in the back office; an '
                            . 'empty string clears it.' . $kept,
                    ],
                    'company' => [
                        'type' => 'string',
                        'description' => 'B2B, only when B2B mode is on. Company.' . $kept,
                    ],
                    'siret' => [
                        'type' => 'string',
                        'description' => 'B2B, only when B2B mode is on. SIRET number, 14 digits.' . $kept,
                    ],
                    'ape' => [
                        'type' => 'string',
                        'description' => 'B2B, only when B2B mode is on. APE code, for example 4711D.' . $kept,
                    ],
                    'website' => [
                        'type' => 'string',
                        'description' => 'B2B, only when B2B mode is on. Website address.' . $kept,
                    ],
                    'allowed_outstanding_amount' => [
                        'type' => 'number',
                        'description' => 'B2B, only when B2B mode is on. Allowed outstanding amount, in the '
                            . 'shop\'s default currency.' . $kept,
                    ],
                    'max_payment_days' => [
                        'type' => 'integer',
                        'description' => 'B2B, only when B2B mode is on. Maximum number of payment days.' . $kept,
                    ],
                    'id_risk' => [
                        'type' => 'integer',
                        'description' => 'B2B, only when B2B mode is on. Risk rating: 1 None, 2 Low, 3 Medium, '
                            . '4 High on a standard shop.' . $kept,
                    ],
                ],
                'required' => ['id_customer'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_WRITE],
            [self::class, 'handle'],
            // Editing a customer is what the customers page asks for in Team,
            // Permissions; adding one is a different right.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminCustomers')
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
        $shop = Somcp::currentShop();
        $idShop = (int) $shop->id;
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        // The same test as somcp_get_customer: a customer of another shop, or
        // deleted with their data kept, is unknown here as it is there.
        // A numeric string is taken too, as somcp_get_customer takes it.
        $given = $arguments['id_customer'] ?? null;
        $id = is_int($given) || (is_string($given) && ctype_digit($given)) ? (int) $given : 0;
        $customer = new Customer($id);
        if ($id <= 0 || !Validate::isLoadedObject($customer) || (int) $customer->id_shop !== $idShop || (int) $customer->deleted === 1) {
            return SomcpTool::text('No customer ' . $id . ' in this shop. Find one with somcp_search_customers.', true);
        }

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $currencyIso = SomcpToolGetCustomer::currencyIso($idShop);
        $before = SomcpToolGetCustomer::account($customer, $idLang, $currencyIso);
        $groupsBefore = array_map('intval', $customer->getGroups());
        sort($groupsBefore);

        $errors = [];
        $groupIds = self::plan($arguments, $customer, $groupsBefore, $idShop, $errors);
        if ($errors !== []) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
        }

        $changes = self::changes($arguments, $before, $customer, $groupsBefore, $groupIds);
        if ($changes === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_customer' => (int) $customer->id,
                'reason' => 'The customer already holds every value given. Nothing was written.',
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($customer, $groupIds, $changes, $idLang, $currencyIso, $idEmployee): array {
            return self::apply($customer, $groupIds, $changes, $idLang, $currencyIso, $idEmployee);
        });
    }

    /**
     * Fills the loaded account with the arguments, and lists everything wrong with them.
     *
     * Nothing is written here, so a refused call leaves the shop as it was.
     *
     * @param array<string, mixed> $arguments
     * @param Customer $customer
     * @param array<int, int> $groupsBefore
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<int, int>|null the groups to write, null when group_ids was not given
     */
    private static function plan(array $arguments, Customer $customer, array $groupsBefore, int $idShop, array &$errors): ?array
    {
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::ARGUMENTS, true)) {
                $errors[] = $key . (in_array($key, self::COMPUTED, true)
                    ? ': worked out by the shop or changed elsewhere in the back office, so it is not taken here'
                    : ': not a field of this tool');
            }
        }

        $emailGiven = false;
        foreach (['firstname', 'lastname', 'email'] as $field) {
            if (!array_key_exists($field, $arguments)) {
                continue;
            }
            $value = is_string($arguments[$field]) ? trim($arguments[$field]) : '';
            if ($value === '' || $customer->validateField($field, $value) !== true) {
                $errors[] = $field . ': ' . SomcpToolCreateCustomer::TEXTS[$field];
                continue;
            }
            $customer->{$field} = $value;
            $emailGiven = $emailGiven || $field === 'email';
        }

        if ($emailGiven) {
            $taken = SomcpToolCreateCustomer::registeredWith((string) $customer->email, $idShop, (int) $customer->id);
            if ($taken > 0) {
                $errors[] = 'email: already used by the registered account id_customer ' . $taken
                    . '; read it with somcp_get_customer';
            }
        }

        $newsletterBefore = (bool) $customer->newsletter;
        // Booleans only: "false" cast to bool is true, and would turn on
        // what the merchant asked to keep off.
        foreach (['active' => 'active', 'partner_offers' => 'optin', 'newsletter' => 'newsletter'] as $argument => $property) {
            if (!array_key_exists($argument, $arguments)) {
                continue;
            }
            if (!is_bool($arguments[$argument])) {
                $errors[] = $argument . ': true or false';
                continue;
            }
            $customer->{$property} = $arguments[$argument];
        }
        if ((bool) $customer->newsletter !== $newsletterBefore) {
            // What the list's newsletter switch writes along with it: the date
            // and address of a subscription, nothing left of one cancelled.
            // The zero date rather than null: the property is a string, and
            // somcp_get_customer reads both as no date.
            $customer->newsletter_date_add = $customer->newsletter ? date('Y-m-d H:i:s') : '0000-00-00 00:00:00';
            $customer->ip_registration_newsletter = $customer->newsletter ? (string) Tools::getRemoteAddr() : '';
        }

        SomcpToolCreateCustomer::gender($arguments, $customer, $errors);
        if (array_key_exists('birthday', $arguments) && $arguments['birthday'] === null) {
            // The empty date the core's Birthday value object writes.
            $customer->birthday = '0000-00-00';
        } else {
            SomcpToolCreateCustomer::birthday($arguments, $customer, $errors);
        }
        $groupIds = self::groups($arguments, $customer, $groupsBefore, $idShop, $errors);
        SomcpToolCreateCustomer::b2b($arguments, $customer, $idShop, $errors);

        if (array_key_exists('private_note', $arguments)) {
            $note = $arguments['private_note'];
            // The note is shown as HTML on the customer page: the same clean
            // HTML rule as the other back office texts, and the column's size.
            if (!is_string($note) || ($note !== '' && (!Validate::isCleanHtml($note) || $customer->validateField('note', $note) !== true))) {
                $errors[] = 'private_note: a text, without HTML tags or scripts';
            } else {
                $customer->note = $note;
            }
        }

        if ($errors === []) {
            SomcpToolCreateCustomer::requiredFields($customer, $errors);
        }

        // The check update() runs, as a last net for a rule of the running
        // version none of the above knew.
        if ($errors === [] && ($check = $customer->validateFields(false, true)) !== true) {
            $errors[] = 'customer: ' . strip_tags((string) $check);
        }

        return $groupIds;
    }

    /**
     * The groups after the change, and the default group, as the form's Group
     * access and Default customer group.
     *
     * @param array<string, mixed> $arguments
     * @param Customer $customer
     * @param array<int, int> $groupsBefore
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<int, int>|null
     */
    private static function groups(array $arguments, Customer $customer, array $groupsBefore, int $idShop, array &$errors): ?array
    {
        if (!array_key_exists('group_ids', $arguments) && !array_key_exists('id_default_group', $arguments)) {
            return null;
        }
        $shopGroups = [];
        foreach (Group::getGroups((int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop), $idShop) ?: [] as $row) {
            $shopGroups[(int) $row['id_group']] = true;
        }

        $groupIds = null;
        if (array_key_exists('group_ids', $arguments)) {
            $given = $arguments['group_ids'];
            if (!is_array($given) || $given === []) {
                $errors[] = 'group_ids: a list of at least one id_group';

                return null;
            }
            $groupIds = [];
            foreach ($given as $id) {
                if (!is_int($id) || !isset($shopGroups[$id])) {
                    $errors[] = 'group_ids: ' . (is_scalar($id) ? (string) $id : 'this value') . ' is not a group of this shop';
                    continue;
                }
                $groupIds[] = $id;
            }
            $groupIds = array_values(array_unique($groupIds));
            sort($groupIds);
        }

        $idDefault = (int) $customer->id_default_group;
        if (array_key_exists('id_default_group', $arguments)) {
            if (!is_int($arguments['id_default_group']) || !isset($shopGroups[$arguments['id_default_group']])) {
                $errors[] = 'id_default_group: not a group of this shop';

                return $groupIds;
            }
            $idDefault = $arguments['id_default_group'];
        }
        // The rule of the core handler: a customer cannot default to a group
        // they have no access to, whichever of the two the call changes.
        $after = $groupIds ?? $groupsBefore;
        if ($after !== [] && !in_array($idDefault, $after, true)) {
            $errors[] = 'id_default_group: ' . $idDefault . ' must be one of the customer\'s groups after the change ('
                . implode(', ', $after) . ')';
        }
        $customer->id_default_group = $idDefault;

        return $groupIds;
    }

    /**
     * Each field the call changes, with its old and new value, under the names
     * of somcp_get_customer.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $before
     * @param Customer $customer
     * @param array<int, int> $groupsBefore
     * @param array<int, int>|null $groupIds
     *
     * @return array<int, array<string, mixed>>
     */
    private static function changes(array $arguments, array $before, Customer $customer, array $groupsBefore, ?array $groupIds): array
    {
        // The account as it would be read after the write, compared with the
        // same function, so old and new always share one shape.
        $after = SomcpToolGetCustomer::account(
            $customer,
            (int) Configuration::get('PS_LANG_DEFAULT', null, null, (int) $customer->id_shop),
            (string) $before['currency']
        );

        $changes = [];
        foreach (self::ARGUMENTS as $field) {
            if ($field === 'id_customer' || $field === 'group_ids' || !array_key_exists($field, $arguments)) {
                continue;
            }
            if ($before[$field] !== $after[$field]) {
                $changes[] = ['field' => $field, 'old' => $before[$field], 'new' => $after[$field]];
            }
        }
        if ($groupIds !== null && $groupIds !== $groupsBefore) {
            $changes[] = ['field' => 'group_ids', 'old' => $groupsBefore, 'new' => $groupIds];
        }

        return $changes;
    }

    /**
     * Writes the account and its groups, and answers with what was stored.
     *
     * @param Customer $customer
     * @param array<int, int>|null $groupIds
     * @param array<int, array<string, mixed>> $changes
     * @param int $idLang
     * @param string $currencyIso
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Customer $customer, ?array $groupIds, array $changes, int $idLang, string $currencyIso, int $idEmployee): array
    {
        $idCustomer = (int) $customer->id;
        $failure = '';
        try {
            if (!$customer->update()) {
                $failure = 'PrestaShop refused to save it';
            } elseif ($groupIds !== null) {
                // The full list replaces the current one, as the form's Group
                // access does.
                $customer->updateGroup($groupIds);
            }
        } catch (Throwable $e) {
            $failure = $e->getMessage();
        }

        if ($failure !== '') {
            SomcpLogger::log(
                'customer_update_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'customer ' . $idCustomer . ': ' . Tools::substr($failure, 0, 500),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The customer could not be changed: ' . $failure . '. Read the customer again with '
                    . 'somcp_get_customer, tell the merchant what it holds now, and do not call again before.',
                true
            );
        }

        SomcpLogger::log(
            'customer_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'customer ' . $idCustomer . ', ' . count($changes) . ' change(s)',
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'changed' => true,
            'id_customer' => $idCustomer,
            'changes' => $changes,
            // Read back rather than echoed from the arguments: what the
            // assistant shows the merchant is what the shop stored.
            'customer' => SomcpToolGetCustomer::account(new Customer($idCustomer), $idLang, $currencyIso),
        ]);
    }
}
