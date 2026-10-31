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
 * somcp_update_manufacturer_address - one existing brand address, changed with
 * everything somcp_create_manufacturer_address can write.
 *
 * It extends the creation tool rather than copying it: the values given are
 * laid over what the address holds and the whole is checked by the creation's
 * own code, so the two tools cannot come to accept different addresses. The
 * fields are checked together because their rules depend on one another: the
 * country decides whether a state or a DNI is required and which postal code
 * format applies, so a postal code alone is checked against the stored
 * country as much as a new country is checked against the stored postal code.
 *
 * Only the fields given are compared with what the shop holds, which gives the
 * answer its old and new values and lets a call that would change nothing
 * write nothing. The write follows the core's EditManufacturerAddressHandler,
 * the same on 8 and 9: the fields set on the stored Address, then
 * Address::update(), so every hook fires.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolCreateManufacturerAddress.php';

class SomcpToolUpdateManufacturerAddress extends SomcpToolCreateManufacturerAddress
{
    /** @var array<int, string> The text fields of a brand address, in the order the answer lists their changes. */
    private const TEXT_FIELDS = [
        'lastname', 'firstname', 'address1', 'address2', 'postcode', 'city', 'dni', 'phone', 'phone_mobile', 'other',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_manufacturer_address',
            Somcp::t('Change a brand address'),
            'Changes one existing postal address of a brand (manufacturer) of this shop. Everything '
                . 'somcp_create_manufacturer_address can write can be changed here, under the same names and with '
                . 'the same rules - lengths, forbidden characters, the country deciding whether a state, a DNI or a '
                . 'postal code format is needed. Only what is given changes: a value left out is kept. '
                . 'id_manufacturer moves the address to another brand. When the country changes, give the state '
                . 'again if the new country has states. Each field is one text, the same in every language. '
                . 'Take the address from somcp_list_manufacturer_addresses, never guessed, and read it with '
                . 'somcp_get_manufacturer_address to know what it holds. Customer and supplier addresses are '
                . 'refused: it changes brand addresses only. Show the merchant what will change and call once they '
                . 'agree. Every problem is reported at once and nothing is written until the whole call is valid. '
                . 'The answer gives each field changed with its old and new value. A call that would change nothing '
                . 'writes nothing and says changed: false. '
                . 'Do not use it to add an address (somcp_create_manufacturer_address) nor for the brand itself '
                . '(somcp_update_manufacturer). One address per call. '
                . 'Not to be retried blindly: if the answer is lost, read the address again with '
                . 'somcp_get_manufacturer_address and compare before calling again.',
            self::inputSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Brand addresses are managed under the brands page, and changing
            // one edits the brand: that is the right the back office checks.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminManufacturers')
        );
    }

    /**
     * The creation schema, made to name an existing address and to keep what is left out.
     *
     * Derived rather than written again, so a field added to the creation is
     * offered here too, with the same rules.
     *
     * @return array<string, mixed>
     */
    protected static function inputSchema(): array
    {
        $schema = parent::inputSchema();
        $properties = $schema['properties'];
        $kept = ' Left out: unchanged.';

        foreach ($properties as $field => $property) {
            if (!isset($property['description'])) {
                continue;
            }
            // Required at creation, kept here: the creation's "Required." would
            // tell the assistant to ask for what the address already holds.
            $properties[$field]['description'] = str_replace('Merchant. Required. ', 'Merchant. ', (string) $property['description']) . $kept;
        }
        $properties['id_manufacturer']['description'] = 'Merchant. Identifier of the brand the address moves to, '
            . 'from somcp_list_manufacturers.' . $kept;
        $properties['country']['description'] = 'Merchant. ISO code of the country, for example FR, among the '
            . 'countries of this shop. A new country takes the state given with it, none otherwise.' . $kept;

        $schema['properties'] = [
            'id_address' => [
                'type' => 'integer',
                'description' => 'The brand address to change, from somcp_list_manufacturer_addresses.',
            ],
        ] + $properties;
        $schema['required'] = ['id_address'];

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
        $idShop = (int) Somcp::currentShop()->id;
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        // The same test as somcp_get_manufacturer_address, so an address this
        // tool refuses is never one that tool describes: a customer's or a
        // supplier's address is never reachable under the brands permission.
        $address = SomcpToolGetManufacturerAddress::existingAddress(self::identifier($arguments['id_address'] ?? null), $idShop);
        if ($address === null) {
            return SomcpTool::text(
                'No brand address of this shop has this identifier. Nothing was changed. Find it with '
                    . 'somcp_list_manufacturer_addresses.',
                true
            );
        }
        unset($arguments['id_address']);

        $errors = [];
        $changes = [];
        $values = self::planUpdate($arguments, $address, $idShop, $errors, $changes);
        if ($errors !== []) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
        }

        if ($values === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_address' => (int) $address->id,
                'reason' => 'The address already holds every value given. Nothing was written.',
            ]);
        }

        return self::asEmployee($idEmployee, static function () use ($address, $values, $changes, $idShop, $idEmployee): array {
            return self::applyUpdate($address, $values, $changes, $idShop, $idEmployee);
        });
    }

    /**
     * A whole number, or 0: a cast would read an array as 1 and change an address nobody named.
     *
     * @param mixed $value
     *
     * @return int
     */
    private static function identifier($value): int
    {
        return is_int($value) || (is_string($value) && preg_match('/^\d{1,10}$/', trim($value)))
            ? (int) trim((string) $value)
            : 0;
    }

    /**
     * Works out the properties that will change, or says everything that is wrong with the call.
     *
     * Nothing is written here. The values given are laid over the stored
     * address and the whole goes through the creation's checks; then only the
     * fields the call named are compared with the shop, so a value the
     * merchant did not touch is never rewritten.
     *
     * @param array<string, mixed> $arguments without id_address
     * @param Address $address
     * @param int $idShop
     * @param array<int, string> $errors
     * @param array<int, array<string, mixed>> $changes
     *
     * @return array<string, mixed> the properties to set on the address, empty when nothing changes
     */
    private static function planUpdate(array $arguments, Address $address, int $idShop, array &$errors, array &$changes): array
    {
        $current = SomcpToolGetManufacturerAddress::address($address);

        $merged = [];
        foreach (self::ADDRESS_ARGUMENTS as $field) {
            if ($current[$field] !== null) {
                $merged[$field] = $current[$field];
            }
        }
        $countryChanges = array_key_exists('country', $arguments)
            && Tools::strtoupper(trim((string) self::string($arguments['country']))) !== Tools::strtoupper((string) $current['country']);
        if ($countryChanges && !array_key_exists('state', $arguments)) {
            // A stored state belongs to the stored country: carried over to a
            // new one, it would be refused as a state that country lacks.
            unset($merged['state']);
        }
        // Unknown and computed fields stay in, so the creation's checks name
        // them rather than this tool dropping them unseen.
        $merged = array_merge($merged, $arguments);

        $fields = self::planAddress($merged, $idShop, $errors);
        if ($errors !== []) {
            return [];
        }

        $values = [];
        foreach (self::TEXT_FIELDS as $field) {
            if (!array_key_exists($field, $arguments)) {
                continue;
            }
            $old = (string) $address->{$field};
            if ($old !== (string) $fields[$field]) {
                $values[$field] = (string) $fields[$field];
                $changes[] = ['field' => $field, 'old' => $old, 'new' => (string) $fields[$field]];
            }
        }

        if (array_key_exists('id_manufacturer', $arguments) && (int) $address->id_manufacturer !== (int) $fields['id_manufacturer']) {
            $values['id_manufacturer'] = (int) $fields['id_manufacturer'];
            $changes[] = ['field' => 'id_manufacturer', 'old' => (int) $address->id_manufacturer, 'new' => (int) $fields['id_manufacturer']];
        }

        // Shown as ISO codes, the way the brand address tools take and return
        // them. The state is compared whenever the country is given, since a
        // new country without states clears it.
        if (array_key_exists('country', $arguments) && (int) $address->id_country !== (int) $fields['id_country']) {
            $values['id_country'] = (int) $fields['id_country'];
            $changes[] = [
                'field' => 'country',
                'old' => $current['country'],
                'new' => (string) Country::getIsoById((int) $fields['id_country']),
            ];
        }
        if ((array_key_exists('state', $arguments) || array_key_exists('country', $arguments))
            && (int) $address->id_state !== (int) $fields['id_state']) {
            $values['id_state'] = (int) $fields['id_state'];
            $changes[] = ['field' => 'state', 'old' => $current['state'], 'new' => self::stateIso((int) $fields['id_state'])];
        }

        return $values;
    }

    /**
     * @param int $idState
     *
     * @return string|null
     */
    private static function stateIso(int $idState): ?string
    {
        if ($idState <= 0) {
            return null;
        }
        $state = new State($idState);

        return Validate::isLoadedObject($state) ? (string) $state->iso_code : null;
    }

    /**
     * Writes the change and answers with what was stored.
     *
     * @param Address $address
     * @param array<string, mixed> $values
     * @param array<int, array<string, mixed>> $changes
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function applyUpdate(Address $address, array $values, array $changes, int $idShop, int $idEmployee): array
    {
        $idAddress = (int) $address->id;
        self::fill($address, $values);
        try {
            // The checks the core's handler runs before update(), on the
            // object as it will be written.
            $failure = '';
            foreach ([$address->validateFields(false, true), $address->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $failure = strip_tags((string) $check);
                    break;
                }
            }
            if ($failure === '' && !$address->update()) {
                $failure = 'PrestaShop refused to save it';
            }
        } catch (Throwable $e) {
            $failure = $e->getMessage();
        }

        if ($failure !== '') {
            SomcpLogger::log(
                'manufacturer_address_update_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'address ' . $idAddress . ': ' . Tools::substr($failure, 0, 500),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The brand address could not be changed: ' . $failure . '. Read it again with '
                    . 'somcp_get_manufacturer_address, tell the merchant what it holds now, and do not call again before.',
                true
            );
        }

        SomcpLogger::log(
            'manufacturer_address_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'address ' . $idAddress . ', ' . count($changes) . ' change(s)',
            '',
            $idEmployee
        );

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $stored = SomcpToolGetManufacturerAddress::existingAddress($idAddress, $idShop);

        return SomcpTool::json([
            'changed' => true,
            'id_address' => $idAddress,
            'changes' => $changes,
            'address' => $stored === null ? null : SomcpToolGetManufacturerAddress::address($stored),
        ]);
    }
}
