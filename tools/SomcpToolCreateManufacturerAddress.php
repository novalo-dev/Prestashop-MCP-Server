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
 * somcp_create_manufacturer_address - one postal address added to a brand,
 * with every field of the back office brand address form.
 *
 * It extends the supplier creation tool because a supplier's contact block is
 * the same Address row: the street lines, postal code, city, phones and DNI
 * are checked by the same code against the running version's Address, and the
 * country and state are found among the shop's countries the same way, so the
 * two tools can never come to accept different addresses. What a brand address
 * adds is a contact name, the "other" field and the brand it belongs to.
 *
 * The row is written as the core's AddManufacturerAddressHandler writes it,
 * the same on 8 and 9: the fields of the form, the brand, and the alias
 * "manufacturer", through Address::add() so every hook fires. Everything is
 * checked before anything is written, and every problem is reported at once.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends, and it answers
// with the address as somcp_get_manufacturer_address returns it.
require_once __DIR__ . '/SomcpToolCreateSupplier.php';
require_once __DIR__ . '/SomcpToolGetManufacturerAddress.php';

class SomcpToolCreateManufacturerAddress extends SomcpToolCreateSupplier
{
    /** @var array<int, string> Every argument this tool takes. */
    protected const ADDRESS_ARGUMENTS = [
        'id_manufacturer', 'lastname', 'firstname', 'address1', 'address2', 'postcode', 'city', 'country', 'state',
        'dni', 'phone', 'phone_mobile', 'other',
    ];

    /**
     * @var array<int, string> What somcp_get_manufacturer_address returns and this tool refuses, because the shop
     *                         works it out. Named, so the assistant learns why rather than meeting an "unknown field".
     */
    protected const ADDRESS_COMPUTED = ['id_address', 'manufacturer_name'];

    /** @var int The length the back office form allows for "other", on 8 and 9 alike, although 9 stores more. */
    protected const OTHER_MAX_LENGTH = 300;

    /** @var string What Validate::isName() and the size of Address hold a contact name to, told the same in the schema and the errors. */
    protected const NAME_RULE = 'required, 255 characters at most, without digits nor ! < > , ; ? = + ( ) / @ # " ° * { } _ $ % :';

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_manufacturer_address',
            Somcp::t('Add a brand address'),
            'Adds one postal address to a brand (manufacturer) of this shop, with every field of the back office '
                . 'brand address form: brand, last name, first name, address, address line 2, postal code, city, '
                . 'country, state, DNI (national identification number), phone, mobile phone and other '
                . 'information. Required: id_manufacturer, lastname, firstname, address1, city and country, plus '
                . 'state when the country has states and dni when the country asks for one. '
                . 'Take the brand from somcp_list_manufacturers, never guessed, and read '
                . 'somcp_list_manufacturer_addresses for that brand first: the address may already be there, and '
                . 'PrestaShop accepts the same address twice. '
                . 'Every field is filled by the merchant: ask for each value and never invent a name, a phone '
                . 'number or an address; you may suggest one for them to confirm. Each field is one text, the same '
                . 'in every language. '
                . 'Every problem is reported at once and nothing is written until the whole call is valid. '
                . 'Do not use it to change an address, which is somcp_update_manufacturer_address, nor for the '
                . 'brand itself (somcp_create_manufacturer, somcp_update_manufacturer). One address per call: for '
                . 'several, call it once per address and report each result. '
                . 'Not to be retried: if the answer is lost, read somcp_list_manufacturer_addresses for the brand '
                . 'before anything else, because a second call adds a second address.',
            self::inputSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Brand addresses are managed under the brands page, and adding
            // one edits the brand: that is the right the back office checks.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminManufacturers')
        );
    }

    /**
     * The arguments, under the names somcp_get_manufacturer_address returns.
     *
     * @return array<string, mixed>
     */
    protected static function inputSchema(): array
    {
        $plain = static function (string $description): array {
            return ['type' => 'string', 'description' => $description];
        };

        return [
            'type' => 'object',
            'properties' => [
                'id_manufacturer' => [
                    'type' => 'integer',
                    'description' => 'Merchant. Required. Identifier of the brand, from somcp_list_manufacturers.',
                ],
                'lastname' => $plain('Merchant. Last name of the contact: ' . self::NAME_RULE . '.'),
                'firstname' => $plain('Merchant. First name of the contact: ' . self::NAME_RULE . '.'),
                'address1' => $plain('Merchant. Required. Street and number, 128 characters at most.'),
                'address2' => $plain('Merchant. Address line 2, 128 characters at most.'),
                'postcode' => $plain('Merchant. Postal code, 12 characters at most, in the format of the country '
                    . 'when it has one.'),
                'city' => $plain('Merchant. Required. 64 characters at most.'),
                'country' => $plain('Merchant. Required. ISO code of the country, for example FR, among the '
                    . 'countries of this shop.'),
                'state' => $plain('Merchant. ISO code of the state, for example CA: required when the country has '
                    . 'states, refused when it has none.'),
                'dni' => $plain('Merchant. National identification number, 16 characters at most: required when '
                    . 'the country asks for one.'),
                'phone' => $plain('Merchant. Phone number: digits, spaces and + - . ( ) / only, 32 characters at most.'),
                'phone_mobile' => $plain('Merchant. Mobile phone number, same rules as phone.'),
                'other' => $plain('Merchant. Other information, 300 characters at most, without < > { }.'),
            ],
            'required' => ['id_manufacturer', 'lastname', 'firstname', 'address1', 'city', 'country'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public static function handle(array $arguments, array $context): array
    {
        $idShop = (int) Somcp::currentShop()->id;
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = [];
        $fields = self::planAddress($arguments, $idShop, $errors);
        if ($errors !== []) {
            return SomcpTool::text(
                "Nothing was created. Correct these and call again:\n- " . implode("\n- ", $errors),
                true
            );
        }

        return self::asEmployee($idEmployee, static function () use ($fields, $idShop, $idEmployee): array {
            return self::addAddress($fields, $idShop, $idEmployee);
        });
    }

    /**
     * Turns the arguments into the properties of the address row, or says everything that is wrong with them.
     *
     * Nothing is written here, so a refused call leaves the shop exactly as it was.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>
     */
    private static function planAddress(array $arguments, int $idShop, array &$errors): array
    {
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::ADDRESS_ARGUMENTS, true)) {
                $errors[] = $key . (in_array($key, self::ADDRESS_COMPUTED, true)
                    ? ': worked out by the shop, so it is not written here'
                    : ': not a field of this tool');
            }
        }

        // A cast would read an array as 1 and attach the address to a brand
        // nobody named: only a whole number is an identifier.
        $value = $arguments['id_manufacturer'] ?? null;
        $idManufacturer = is_int($value) || (is_string($value) && preg_match('/^\d{1,10}$/', trim($value)))
            ? (int) trim((string) $value)
            : 0;
        if (!array_key_exists('id_manufacturer', $arguments)) {
            $errors[] = 'id_manufacturer: required, the identifier of the brand from somcp_list_manufacturers';
        } elseif (SomcpToolGetManufacturer::existingManufacturer($idManufacturer, $idShop) === null) {
            // A brand of another shop is refused like one that does not exist,
            // by the same test every brand tool uses.
            $errors[] = 'id_manufacturer: no brand of this shop has this identifier, find it with somcp_list_manufacturers';
        }

        // The street lines, postal code, city, phones, DNI, country and state,
        // checked exactly as a supplier's, since they are the same row.
        $fields = self::supplierAddress($arguments, $idShop, $errors);
        $fields['id_manufacturer'] = $idManufacturer;
        // The alias the core's AddManufacturerAddressHandler sets: the form
        // does not ask for one and the row cannot be saved without it.
        $fields['alias'] = 'manufacturer';

        $probe = new Address();
        $rules = ['lastname' => self::NAME_RULE, 'firstname' => self::NAME_RULE, 'other' => 'without < > { }'];
        foreach ($rules as $field => $rule) {
            $fields[$field] = '';
            if (array_key_exists($field, $arguments)) {
                $text = self::string($arguments[$field]);
                if ($text === null) {
                    $errors[] = $field . ': a text, the same in every language';
                    continue;
                }
                $fields[$field] = trim($text);
            }
            if ($field === 'other' && Tools::strlen((string) $fields['other']) > self::OTHER_MAX_LENGTH) {
                // PrestaShop 9 stores a longer text than its own form lets
                // through, so the form's limit is the one held on both
                // versions, and told the same on both.
                $errors[] = 'other: ' . self::OTHER_MAX_LENGTH . ' characters at most';
            } elseif (($check = $probe->validateField($field, $fields[$field])) !== true) {
                $errors[] = $field . ': ' . $rule . ' - ' . self::reason($check);
            }
        }

        // The checks the core's handler runs before add(), on the whole
        // object, as a last net for a rule of the running version none of the
        // above knew.
        if ($errors === []) {
            $address = new Address();
            self::fill($address, $fields);
            foreach ([$address->validateFields(false, true), $address->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'address: ' . strip_tags((string) $check);
                }
            }
        }

        return $fields;
    }

    /**
     * Writes the address and answers with what was stored.
     *
     * @param array<string, mixed> $fields
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function addAddress(array $fields, int $idShop, int $idEmployee): array
    {
        $address = new Address();
        self::fill($address, $fields);
        try {
            $done = $address->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        if (!$done) {
            SomcpLogger::log(
                'manufacturer_address_create_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr($failure, 0, 500),
                '',
                $idEmployee
            );

            return SomcpTool::text('The brand address could not be created: ' . $failure . '. Nothing was kept.', true);
        }

        $idAddress = (int) $address->id;
        SomcpLogger::log(
            'manufacturer_address_created',
            SomcpLogger::OUTCOME_SUCCESS,
            'address ' . $idAddress . ' of brand ' . (int) $fields['id_manufacturer'],
            '',
            $idEmployee
        );

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $stored = SomcpToolGetManufacturerAddress::existingAddress($idAddress, $idShop);

        return SomcpTool::json([
            'created' => true,
            'id_address' => $idAddress,
            'address' => $stored === null ? null : SomcpToolGetManufacturerAddress::address($stored),
        ]);
    }
}
