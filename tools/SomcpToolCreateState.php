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
 * somcp_create_state - one state of International > Locations > States, as
 * Add new state then Save: Name, ISO code, Country, Zone and Status.
 *
 * The tax rule rows and the addresses take a state by its ISO code within
 * its country, but only among the states the install left: a merchant who
 * sells to a region PrestaShop does not ship had to open the back office.
 * The answer is the state read back through the row function of
 * somcp_list_states, so the creation and the list give the same fields.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// answer is the state as somcp_list_states returns it.
require_once __DIR__ . '/SomcpToolListStates.php';

class SomcpToolCreateState
{
    /** @var int The size of the name column (State::$definition), used only when the column cannot be read. */
    public const NAME_MAX = 80;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_state',
            Somcp::t('Create a state'),
            'Creates one state of International > Locations > States, as Add new state then Save: Name, ISO code, '
                . 'Country, Zone, Status. Only for a country that uses states. Read somcp_list_states first: an '
                . 'ISO code another state of the country has is refused. The zone is the merchant\'s choice: read '
                . 'the zone of the country with somcp_list_countries and propose it, never pick one silently. For '
                . 'the tax of a state use somcp_add_country_rule.',
            [
                'type' => 'object',
                'properties' => [
                    'country' => [
                        'type' => 'string',
                        'description' => 'Merchant. Required. Country, its ISO code as somcp_list_countries gives '
                            . 'it, for example US; the country must use states.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Merchant. Required. Name, as shown in addresses and on invoices, 80 '
                            . 'characters at most, without < > = { }.',
                    ],
                    'iso_code' => [
                        'type' => 'string',
                        'description' => 'Merchant. Required. ISO code: 1 to 4 letters or digits, optionally a dash '
                            . 'and 1 to 4 more, for example CA or US-CA; stored upper case.',
                    ],
                    'id_zone' => [
                        'type' => 'integer',
                        'description' => 'Merchant. Required. Zone, an id_zone from somcp_list_zones: the zone the '
                            . 'carrier prices read for the addresses of this state.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Status: true by default, as in the form.',
                    ],
                ],
                'required' => ['country', 'name', 'iso_code', 'id_zone'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Adding a state is what the states page asks for in Team,
            // Permissions; editing one is a different right.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminStates')
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
        $idShop = (int) Somcp::currentShop()->id;
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        // What a row of somcp_list_states holds besides the fields written:
        // named, so an assistant giving a row back learns why it is not taken.
        $errors = SomcpTool::unknownFields(
            $arguments,
            ['country', 'name', 'iso_code', 'id_zone', 'active'],
            ['id_state', 'id_country', 'country_name', 'zone']
        );

        $idCountry = self::country($arguments, $idShop, true, $errors);
        $name = self::name($arguments, true, $errors);
        $isoCode = self::isoCode($arguments, $idCountry, true, 0, $errors);

        $idZone = 0;
        if (!array_key_exists('id_zone', $arguments) || $arguments['id_zone'] === null) {
            // No default from the country's zone, as the form has none: the
            // state's own zone is what the carrier prices read for its
            // addresses, a choice the merchant makes.
            $errors[] = 'id_zone: required';
        } else {
            $idZone = SomcpTool::id($arguments, 'id_zone');
            if ($idZone === 0) {
                $errors[] = SomcpTool::notAnIdentifier('id_zone');
            } elseif (!SomcpToolListCountries::zoneOfShop($idZone, $idShop)) {
                $errors[] = SomcpTool::notFound('id_zone', 'zone', $idZone, 'somcp_list_zones');
            }
        }

        $active = true;
        if (array_key_exists('active', $arguments)) {
            // A boolean only: "false" cast to bool is true, and would enable
            // a state the merchant wanted off.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } else {
                $active = $arguments['active'];
            }
        }
        if ($errors !== [] || $idCountry === 0 || $name === null || $isoCode === null || $idZone === 0) {
            return SomcpTool::refused('created', $errors);
        }

        $state = new State();
        $state->id_country = $idCountry;
        $state->id_zone = $idZone;
        $state->name = $name;
        $state->iso_code = $isoCode;
        $state->active = $active;

        try {
            $done = $state->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('state_create_failed', SomcpLogger::OUTCOME_FAILURE, Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text(
                'The state could not be created: ' . $failure . '. Read somcp_list_states before calling again.',
                true
            );
        }

        $idState = (int) $state->id;
        SomcpLogger::log('state_created', SomcpLogger::OUTCOME_SUCCESS, 'state ' . $idState, '', $idEmployee);

        $result = [
            'created' => true,
            'id_state' => $idState,
        ];
        // A state has no shop table: it shows in every shop of its country,
        // said the way the tax tools say it.
        $otherShops = SomcpToolCreateTax::otherShops();
        if ($otherShops > 0) {
            $result['also_in_other_shops'] = $otherShops;
            $result['warning'] = 'A state is not tied to a shop: this state now exists in every shop of this '
                . 'installation (' . ($otherShops + 1) . ') that sells to its country.';
        }
        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $result['state'] = SomcpToolListStates::state($idShop, $idState);

        return SomcpTool::json($result);
    }

    /**
     * The country given, a country of the shop that uses states, or 0 with the reason.
     *
     * Public so somcp_update_state checks a new country the same way.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param bool $required false when left out means kept, as on an update
     * @param array<int, string> $errors
     *
     * @return int
     */
    public static function country(array $arguments, int $idShop, bool $required, array &$errors): int
    {
        if (!array_key_exists('country', $arguments) || $arguments['country'] === null) {
            if ($required) {
                $errors[] = 'country: required';
            }

            return 0;
        }
        if ($arguments['country'] === '') {
            // Required when given: a state always belongs to a country.
            $errors[] = 'country: required';

            return 0;
        }
        if (!is_string($arguments['country'])) {
            $errors[] = 'country: an ISO code of 2 letters, for example US';

            return 0;
        }
        $code = Tools::strtoupper(trim($arguments['country']));
        // Read the way somcp_list_states reads it, validated first so a
        // malformed code never reaches the query.
        $found = Validate::isLanguageIsoCode($code) ? SomcpToolCreateSupplier::shopCountries($idShop, $code) : [];
        $idCountry = (int) array_key_first($found);
        if ($idCountry === 0) {
            $errors[] = SomcpTool::notFound('country', 'country', $code, 'somcp_list_countries', 'ISO code');

            return 0;
        }
        if (!(new Country($idCountry))->contains_states) {
            // The form lists only these countries: a state of another one
            // would never be offered in its address form. Named in the shop
            // default language, as somcp_list_states names a country.
            $label = Country::getNameById((int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop), $idCountry);
            $errors[] = 'country: ' . ($label ? $label : $code)
                . ' does not use states - only the countries with "contains states" take one';

            return 0;
        }

        return $idCountry;
    }

    /**
     * The name given, checked with the running version's rules, or null with the reason.
     *
     * Public so somcp_update_state checks a new name the same way.
     *
     * @param array<string, mixed> $arguments
     * @param bool $required false when left out means kept, as on an update
     * @param array<int, string> $errors
     *
     * @return string|null
     */
    public static function name(array $arguments, bool $required, array &$errors): ?string
    {
        if (!array_key_exists('name', $arguments) || $arguments['name'] === null) {
            if ($required) {
                $errors[] = 'name: required';
            }

            return null;
        }
        if (!is_string($arguments['name'])) {
            $errors[] = 'name: a text';

            return null;
        }
        $name = trim($arguments['name']);
        if ($name === '') {
            $errors[] = 'name: required';

            return null;
        }
        $probe = new State();
        $max = SomcpTool::columnSize($probe, 'name', self::NAME_MAX);
        if (Tools::strlen($name) > $max) {
            $errors[] = 'name: ' . $max . ' characters at most, ' . Tools::strlen($name) . ' given';

            return null;
        }
        // State::$name is checked with isGenericName.
        $check = $probe->validateField('name', $name);
        if ($check !== true) {
            $errors[] = 'name: a character PrestaShop refuses in a state name (< > = { }) - '
                . strip_tags(is_string($check) ? $check : 'invalid');

            return null;
        }

        return $name;
    }

    /**
     * The ISO code given, upper case and free within its country, or null with the reason.
     *
     * Public so somcp_update_state checks a new code the same way, the state
     * being changed left out of the test.
     *
     * @param array<string, mixed> $arguments
     * @param int $idCountry 0 when the country was refused, the code then checked for its form only
     * @param bool $required false when left out means kept, as on an update
     * @param int $exceptState the state being changed, which may keep its own code; 0 on a creation
     * @param array<int, string> $errors
     *
     * @return string|null
     */
    public static function isoCode(array $arguments, int $idCountry, bool $required, int $exceptState, array &$errors): ?string
    {
        if (!array_key_exists('iso_code', $arguments) || $arguments['iso_code'] === null) {
            if ($required) {
                $errors[] = 'iso_code: required';
            }

            return null;
        }
        if ($arguments['iso_code'] === '') {
            $errors[] = 'iso_code: required';

            return null;
        }
        if (!is_string($arguments['iso_code'])) {
            $errors[] = 'iso_code: the ISO code of a state, for example CA';

            return null;
        }
        $code = Tools::strtoupper(trim($arguments['iso_code']));
        if (!Validate::isStateIsoCode($code)) {
            $errors[] = 'iso_code: 1 to 4 letters or digits, optionally a dash and 1 to 4 more, for example CA or US-CA';

            return null;
        }
        if ($idCountry > 0) {
            // Within the country, as 8.0.1 checks it and as every tool takes
            // a state: the demo data itself gives the same code to states of
            // different countries, which the 9.0.0 test of every country
            // would refuse.
            $other = (int) State::getIdByIso($code, $idCountry);
            if ($other > 0 && $other !== $exceptState) {
                $errors[] = 'iso_code: state ' . $other . ' of ' . Country::getIsoById($idCountry)
                    . ' already has the ISO code ' . $code;

                return null;
            }
        }

        return $code;
    }
}
