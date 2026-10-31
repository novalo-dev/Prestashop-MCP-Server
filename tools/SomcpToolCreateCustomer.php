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
 * somcp_create_customer - one customer account, as Customers > Customers >
 * Add new customer creates it.
 *
 * The checks are those of the core's AddCustomerHandler on both versions: the
 * required fields the merchant set for customers, the rules of the running
 * version's Customer, a registered email used once per shop (guests may share
 * one), the default group among the groups given. B2B fields are only taken
 * when B2B mode is on, since the form only shows them then.
 *
 * No password goes through the conversation: a random one is hashed the way
 * the handler hashes the typed one, and the customer sets theirs with the
 * shop's password reset. Everything is checked before the account is written,
 * and every problem is reported at once.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The answer is the header block of somcp_get_customer, read by its own code.
require_once __DIR__ . '/SomcpToolGetCustomer.php';

class SomcpToolCreateCustomer
{
    /** @var array<int, string> Every argument this tool takes, under the names somcp_get_customer returns. */
    private const ARGUMENTS = [
        'firstname', 'lastname', 'email', 'is_guest', 'id_gender', 'birthday', 'active', 'partner_offers',
        'newsletter', 'group_ids', 'id_default_group', 'company', 'siret', 'ape', 'website',
        'allowed_outstanding_amount', 'max_payment_days', 'id_risk',
    ];

    /**
     * @var array<int, string> What somcp_get_customer returns and this tool refuses, because the shop works it
     *                         out or another tool writes it. Named, so the assistant learns why.
     */
    private const COMPUTED = [
        'id_customer', 'social_title', 'age', 'date_add', 'last_visit', 'customer_language', 'newsletter_date_add',
        'date_upd', 'private_note', 'currency', 'risk',
    ];

    /** @var array<string, string> The B2B fields, by argument name, with the Customer property each one fills. */
    private const B2B = [
        'company' => 'company',
        'siret' => 'siret',
        'ape' => 'ape',
        'website' => 'website',
        'allowed_outstanding_amount' => 'outstanding_allow_amount',
        'max_payment_days' => 'max_payment_days',
        'id_risk' => 'id_risk',
    ];

    /**
     * @var array<string, string> The plain texts, with what the running version's Customer accepts. Shared
     *                            with somcp_update_customer, so both tools refuse a value in the same words.
     */
    public const TEXTS = [
        'firstname' => 'required, letters, spaces and dots only, 255 characters at most',
        'lastname' => 'required, letters, spaces and dots only, 255 characters at most',
        'email' => 'required, a valid email address, 255 characters at most',
        'company' => '255 characters at most, without < > = { }',
        'siret' => '14 digits, as the SIRET number',
        'ape' => 'an APE code: 4 digits and a letter, for example 4711D',
        'website' => 'a valid URL',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_customer',
            Somcp::t('Create a customer'),
            'Creates one customer account in this shop, as Customers > Customers > Add new customer: first '
                . 'name, last name, email, guest account or not, social title, birthday, Enabled, partner '
                . 'offers, newsletter, group access and default customer group, plus the B2B fields (company, '
                . 'SIRET, APE, website, allowed outstanding amount, maximum number of payment days, risk '
                . 'rating) when B2B mode is on. Required: firstname, lastname and email. Every field takes the '
                . 'name somcp_get_customer returns, so an account read there can be given back. '
                . 'Every field is the merchant\'s: ask for it, never invent it. '
                . 'Read somcp_search_customers with the email first: a registered email is used by one account '
                . 'only, and is refused with the identifier of that account. A guest account has no password '
                . 'and may share its email with other guests. '
                . 'No password goes through the conversation: the account gets a random one, and the customer '
                . 'chooses theirs with "Forgot your password?" on the shop; tell the merchant so. No email is '
                . 'sent to the customer. '
                . 'Every problem is reported at once and nothing is written until the whole call is valid. '
                . 'Do not use it to change an account, which is somcp_update_customer, or to add an address. '
                . 'One account per call. '
                . 'Not to be retried: if the answer is lost, read somcp_search_customers with the email before '
                . 'anything else, because the account may already exist.',
            [
                'type' => 'object',
                'properties' => [
                    'firstname' => [
                        'type' => 'string',
                        'description' => 'First name. Required: letters, spaces and dots only.',
                    ],
                    'lastname' => [
                        'type' => 'string',
                        'description' => 'Last name. Required: letters, spaces and dots only.',
                    ],
                    'email' => [
                        'type' => 'string',
                        'description' => 'Email. Required. Refused when a registered account of this shop already '
                            . 'uses it.',
                    ],
                    'is_guest' => [
                        'type' => 'boolean',
                        'description' => 'Guest account: an account without a password, which may share its email '
                            . 'with other guests. false by default.',
                    ],
                    'id_gender' => [
                        'type' => 'integer',
                        'description' => 'Social title, by its identifier in this shop (an identifier the shop does '
                            . 'not have is refused with the list of its social titles). None by default.',
                    ],
                    'birthday' => [
                        'type' => 'string',
                        'description' => 'Birthday, YYYY-MM-DD, in the past.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Enabled: the customer can log in. true by default.',
                    ],
                    'partner_offers' => [
                        'type' => 'boolean',
                        'description' => 'Partner offers. false by default.',
                    ],
                    'newsletter' => [
                        'type' => 'boolean',
                        'description' => 'Newsletter subscription. false by default.',
                    ],
                    'group_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'Group access: the id_group of each group of the customer. Every group of '
                            . 'the shop by default, as the form preselects them.',
                    ],
                    'id_default_group' => [
                        'type' => 'integer',
                        'description' => 'Default customer group, which must be one of group_ids. The shop\'s '
                            . 'Customer group by default.',
                    ],
                    'company' => [
                        'type' => 'string',
                        'description' => 'B2B, only when B2B mode is on. Company.',
                    ],
                    'siret' => [
                        'type' => 'string',
                        'description' => 'B2B, only when B2B mode is on. SIRET number, 14 digits.',
                    ],
                    'ape' => [
                        'type' => 'string',
                        'description' => 'B2B, only when B2B mode is on. APE code, for example 4711D.',
                    ],
                    'website' => [
                        'type' => 'string',
                        'description' => 'B2B, only when B2B mode is on. Website address.',
                    ],
                    'allowed_outstanding_amount' => [
                        'type' => 'number',
                        'description' => 'B2B, only when B2B mode is on. Allowed outstanding amount, in the '
                            . 'shop\'s default currency.',
                    ],
                    'max_payment_days' => [
                        'type' => 'integer',
                        'description' => 'B2B, only when B2B mode is on. Maximum number of payment days.',
                    ],
                    'id_risk' => [
                        'type' => 'integer',
                        'description' => 'B2B, only when B2B mode is on. Risk rating: 1 None, 2 Low, 3 Medium, '
                            . '4 High on a standard shop.',
                    ],
                ],
                'required' => ['firstname', 'lastname', 'email'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_WRITE],
            [self::class, 'handle'],
            // Adding a customer is what the customers page asks for in Team,
            // Permissions; editing one is a different right.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminCustomers')
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

        $errors = [];
        $customer = self::plan($arguments, $shop, $errors);
        if ($errors !== []) {
            return SomcpTool::text(
                "Nothing was created. Correct these and call again:\n- " . implode("\n- ", $errors),
                true
            );
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($customer, $idShop, $idEmployee): array {
            return self::add($customer, $idShop, $idEmployee);
        });
    }

    /**
     * The account to write, filled from the arguments, and everything wrong with them.
     *
     * Nothing is written here, so a refused call leaves the shop as it was.
     *
     * @param array<string, mixed> $arguments
     * @param Shop $shop
     * @param array<int, string> $errors
     *
     * @return Customer
     */
    private static function plan(array $arguments, Shop $shop, array &$errors): Customer
    {
        $idShop = (int) $shop->id;
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::ARGUMENTS, true)) {
                $errors[] = $key . (in_array($key, self::COMPUTED, true)
                    ? ': worked out by the shop or written by another tool, so it is not taken here'
                    : ': not a field of this tool');
            }
        }

        $customer = new Customer();
        $customer->id_shop = $idShop;
        $customer->id_shop_group = (int) $shop->id_shop_group;
        // The form has no language field: the account takes the shop's.
        $customer->id_lang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $customer->active = true;
        $customer->is_guest = false;
        $customer->optin = false;
        $customer->newsletter = false;
        $customer->id_gender = 0;

        foreach (['firstname', 'lastname', 'email'] as $field) {
            $value = isset($arguments[$field]) && is_string($arguments[$field]) ? trim($arguments[$field]) : '';
            if ($value === '') {
                $errors[] = $field . ': ' . self::TEXTS[$field];
                continue;
            }
            if ($customer->validateField($field, $value) !== true) {
                $errors[] = $field . ': ' . self::TEXTS[$field];
                continue;
            }
            $customer->{$field} = $value;
        }

        // Booleans only: "false" cast to bool is true, and would turn on
        // what the merchant asked to keep off.
        foreach (['is_guest' => 'is_guest', 'active' => 'active', 'partner_offers' => 'optin', 'newsletter' => 'newsletter'] as $argument => $property) {
            if (!array_key_exists($argument, $arguments)) {
                continue;
            }
            if (!is_bool($arguments[$argument])) {
                $errors[] = $argument . ': true or false';
                continue;
            }
            $customer->{$property} = $arguments[$argument];
        }

        self::gender($arguments, $customer, $errors);
        self::birthday($arguments, $customer, $errors);
        self::groups($arguments, $customer, $idShop, $errors);
        self::b2b($arguments, $customer, $idShop, $errors);

        if ($customer->newsletter) {
            // What the list's newsletter switch writes along with it.
            $customer->newsletter_date_add = date('Y-m-d H:i:s');
            $customer->ip_registration_newsletter = (string) Tools::getRemoteAddr();
        }

        // A guest has no password of its own; a registered account needs one
        // nobody knows, so the customer chooses theirs through the reset link.
        $customer->passwd = (new PrestaShop\PrestaShop\Core\Crypto\Hashing())->hash(bin2hex(random_bytes(24)));

        if ($customer->email !== '' && !$customer->is_guest) {
            $taken = self::registeredWith((string) $customer->email, $idShop);
            if ($taken > 0) {
                $errors[] = 'email: already used by the registered account id_customer ' . $taken
                    . '; read it with somcp_get_customer, or change it with somcp_update_customer';
            }
        }

        if ($errors === []) {
            self::requiredFields($customer, $errors);
        }

        // The check the core handler runs before add(), as a last net for a
        // rule of the running version none of the above knew.
        if ($errors === [] && ($check = $customer->validateFields(false, true)) !== true) {
            $errors[] = 'customer: ' . strip_tags((string) $check);
        }

        return $customer;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param Customer $customer
     * @param array<int, string> $errors
     *
     * @return void
     */
    public static function gender(array $arguments, Customer $customer, array &$errors): void
    {
        if (!array_key_exists('id_gender', $arguments) || $arguments['id_gender'] === null) {
            return;
        }
        $idLang = (int) $customer->id_lang;
        $genders = [];
        foreach (Db::getInstance()->executeS('SELECT g.`id_gender`, gl.`name` FROM `' . _DB_PREFIX_ . 'gender` g'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'gender_lang` gl ON gl.`id_gender` = g.`id_gender` AND gl.`id_lang` = ' . $idLang
            . ' ORDER BY g.`id_gender`') ?: [] as $row) {
            $genders[(int) $row['id_gender']] = (string) $row['name'];
        }
        $id = $arguments['id_gender'];
        if (!is_int($id) || ($id !== 0 && !isset($genders[$id]))) {
            // No tool lists the social titles yet, so the list goes with the refusal.
            $known = [];
            foreach ($genders as $idGender => $name) {
                $known[] = $idGender . ' ' . $name;
            }
            $errors[] = 'id_gender: not a social title of this shop, which has ' . ($known === [] ? 'none' : implode(', ', $known));

            return;
        }
        $customer->id_gender = $id;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param Customer $customer
     * @param array<int, string> $errors
     *
     * @return void
     */
    public static function birthday(array $arguments, Customer $customer, array &$errors): void
    {
        if (!array_key_exists('birthday', $arguments) || $arguments['birthday'] === null || $arguments['birthday'] === '') {
            return;
        }
        $value = is_string($arguments['birthday']) ? trim($arguments['birthday']) : '';
        // The format somcp_get_customer gives, checked strictly: isBirthDate()
        // alone takes other separators the merchant did not mean.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !Validate::isBirthDate($value)) {
            $errors[] = 'birthday: a date in the past, YYYY-MM-DD';

            return;
        }
        $customer->birthday = $value;
    }

    /**
     * The groups and the default group, as the form's Group access and Default customer group.
     *
     * @param array<string, mixed> $arguments
     * @param Customer $customer
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function groups(array $arguments, Customer $customer, int $idShop, array &$errors): void
    {
        $shopGroups = [];
        foreach (Group::getGroups((int) $customer->id_lang, $idShop) ?: [] as $row) {
            $shopGroups[(int) $row['id_group']] = (string) $row['name'];
        }

        $groupIds = array_keys($shopGroups);
        if (array_key_exists('group_ids', $arguments)) {
            $given = $arguments['group_ids'];
            $groupIds = [];
            if (!is_array($given) || $given === []) {
                $errors[] = 'group_ids: a list of at least one id_group';
            } else {
                foreach ($given as $id) {
                    if (!is_int($id) || !isset($shopGroups[$id])) {
                        $errors[] = 'group_ids: ' . (is_scalar($id) ? (string) $id : 'this value') . ' is not a group of this shop';
                        continue;
                    }
                    $groupIds[] = $id;
                }
                $groupIds = array_values(array_unique($groupIds));
            }
        }

        $idDefault = (int) Configuration::get('PS_CUSTOMER_GROUP', null, null, $idShop);
        if (array_key_exists('id_default_group', $arguments)) {
            if (!is_int($arguments['id_default_group']) || !isset($shopGroups[$arguments['id_default_group']])) {
                $errors[] = 'id_default_group: not a group of this shop';

                return;
            }
            $idDefault = $arguments['id_default_group'];
        }
        // The rule of the core handler: a customer cannot default to a group
        // they have no access to.
        if ($groupIds !== [] && !in_array($idDefault, $groupIds, true)) {
            $errors[] = 'id_default_group: ' . $idDefault . ' must be one of group_ids';
        }

        $customer->id_default_group = $idDefault;
        $customer->groupBox = $groupIds;
    }

    /**
     * The B2B fields, refused when B2B mode is off, since the form hides them
     * then and the merchant could not see what was stored.
     *
     * @param array<string, mixed> $arguments
     * @param Customer $customer
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return void
     */
    public static function b2b(array $arguments, Customer $customer, int $idShop, array &$errors): void
    {
        $given = array_values(array_intersect(array_keys(self::B2B), array_keys($arguments)));
        if ($given === []) {
            return;
        }
        if (!Configuration::get('PS_B2B_ENABLE', null, null, $idShop)) {
            $errors[] = implode(', ', $given) . ': B2B fields, taken only when B2B mode is on '
                . '(Shop Parameters > Customer Settings > Enable B2B mode)';

            return;
        }

        foreach (['company', 'siret', 'ape', 'website'] as $field) {
            if (!array_key_exists($field, $arguments)) {
                continue;
            }
            $value = is_string($arguments[$field]) ? trim($arguments[$field]) : null;
            if ($value === null || ($value !== '' && $customer->validateField($field, $value) !== true)) {
                $errors[] = $field . ': ' . self::TEXTS[$field];
                continue;
            }
            $customer->{$field} = $value;
        }

        if (array_key_exists('allowed_outstanding_amount', $arguments)) {
            $amount = $arguments['allowed_outstanding_amount'];
            if (!(is_int($amount) || is_float($amount)) || $amount < 0) {
                $errors[] = 'allowed_outstanding_amount: a number, 0 or more';
            } else {
                $customer->outstanding_allow_amount = (float) $amount;
            }
        }
        if (array_key_exists('max_payment_days', $arguments)) {
            $days = $arguments['max_payment_days'];
            if (!is_int($days) || $days < 0) {
                $errors[] = 'max_payment_days: a whole number, 0 or more';
            } else {
                $customer->max_payment_days = $days;
            }
        }
        if (array_key_exists('id_risk', $arguments)) {
            $risk = $arguments['id_risk'];
            $known = is_int($risk) && $risk > 0
                && (int) Db::getInstance()->getValue('SELECT `id_risk` FROM `' . _DB_PREFIX_ . 'risk` WHERE `id_risk` = ' . (int) $risk) === $risk;
            if (!$known) {
                $errors[] = 'id_risk: not a risk rating of this shop (1 None, 2 Low, 3 Medium, 4 High on a standard shop)';
            } else {
                $customer->id_risk = $risk;
            }
        }
    }

    /**
     * The registered account of this shop that already uses this email, 0 when none.
     *
     * Guests are left out, as Customer::getByEmail() leaves them out for the
     * core handler: two guests, or a guest and an account, may share an email.
     * somcp_update_customer leaves out the account it changes, which may keep
     * its own email.
     *
     * @param string $email
     * @param int $idShop
     * @param int $exceptIdCustomer
     *
     * @return int
     */
    public static function registeredWith(string $email, int $idShop, int $exceptIdCustomer = 0): int
    {
        return (int) Db::getInstance()->getValue('SELECT `id_customer` FROM `' . _DB_PREFIX_ . 'customer`'
            . " WHERE `email` = '" . pSQL($email) . "' AND `is_guest` = 0 AND `deleted` = 0"
            . ' AND `id_shop` = ' . $idShop . ' AND `id_customer` != ' . $exceptIdCustomer . ' ORDER BY `id_customer`');
    }

    /**
     * The fields the merchant made required for customers (Set required fields
     * for this section).
     *
     * The core handler checks them through validateFieldsRequiredDatabase(),
     * which reads the posted form; here the account itself is read instead.
     *
     * @param Customer $customer
     * @param array<int, string> $errors
     *
     * @return void
     */
    public static function requiredFields(Customer $customer, array &$errors): void
    {
        // The names the merchant passes, where they differ from the property.
        $names = ['optin' => 'partner_offers', 'outstanding_allow_amount' => 'allowed_outstanding_amount'];
        foreach ($customer->getFieldsRequiredDatabase() ?: [] as $row) {
            $field = (string) ($row['field_name'] ?? '');
            if ($field === '' || !property_exists($customer, $field)) {
                continue;
            }
            $value = $customer->{$field};
            if ($value === null || $value === '' || $value === false || $value === 0 || $value === '0'
                || ($field === 'birthday' && strpos((string) $value, '0000-00-00') === 0)) {
                $errors[] = ($names[$field] ?? $field) . ': required by this shop (Customers > Customers > '
                    . 'Set required fields for this section)';
            }
        }
    }

    /**
     * Writes the account and its groups, and answers with what was stored.
     *
     * @param Customer $customer
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function add(Customer $customer, int $idShop, int $idEmployee): array
    {
        $groupIds = (array) $customer->groupBox;
        try {
            $done = (bool) $customer->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
            if ($done && $groupIds !== []) {
                // Written explicitly, as PrestaShop 9's handler does: 8 relies
                // on groupBox inside add(), which hooks may have changed.
                $customer->updateGroup($groupIds);
                // The core keeps the customer's groups in a static cache that
                // updateGroup() leaves stale: any later read in the same
                // process would still see the groups before the change.
                Customer::resetStaticCache();
            }
        } catch (Throwable $e) {
            $done = (int) $customer->id > 0;
            $failure = $e->getMessage();
        }

        if (!$done) {
            SomcpLogger::log(
                'customer_create_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr($failure, 0, 500),
                '',
                $idEmployee
            );

            return SomcpTool::text('The customer could not be created: ' . $failure . '. Nothing was kept.', true);
        }

        $idCustomer = (int) $customer->id;
        SomcpLogger::log('customer_created', SomcpLogger::OUTCOME_SUCCESS, 'customer ' . $idCustomer, '', $idEmployee);

        $stored = new Customer($idCustomer);
        $idLang = (int) $customer->id_lang;

        return SomcpTool::json([
            'created' => true,
            'id_customer' => $idCustomer,
            'password' => $customer->is_guest
                ? 'none: a guest account has no password'
                : 'none set: the customer chooses one with "Forgot your password?" on the shop',
            // Read back rather than echoed from the arguments: what the
            // assistant shows the merchant is what the shop stored.
            'customer' => SomcpToolGetCustomer::account($stored, $idLang, SomcpToolGetCustomer::currencyIso($idShop)),
        ]);
    }
}
