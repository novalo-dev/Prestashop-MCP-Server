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
 * somcp_update_state - one state of International > Locations > States, as
 * Edit then Save, or the Enabled switch and the 8.0.1 bulk action Assign to a
 * new zone for one state: Name, ISO code, Country, Zone and Status.
 *
 * Every field is checked by the functions somcp_create_state calls, so a
 * state is held to the same rules whether it is created or changed. The
 * answer has the shape of every update tool, with the state read back
 * through the row function of somcp_list_states.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// fields are checked as the creation checks them, and the carriers of a zone
// are read as the zone tools read them.
require_once __DIR__ . '/SomcpToolCreateState.php';
require_once __DIR__ . '/SomcpToolUpdateZone.php';

class SomcpToolUpdateState
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_state',
            Somcp::t('Change a state'),
            'Changes one state of International > Locations > States, as Edit then Save or the Enabled switch: '
                . 'Name, ISO code, Country, Zone, Status. Take id_state from somcp_list_states; a row of it can '
                . 'be given back whole, and only what differs is written. A state used by addresses or tax rule '
                . 'rows keeps its country. The zone is the merchant\'s choice. To create a state use '
                . 'somcp_create_state; for its tax, somcp_update_country_rule.',
            [
                'type' => 'object',
                'properties' => [
                    'id_state' => [
                        'type' => 'integer',
                        'description' => 'Required. The state to change (ID), from somcp_list_states.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Merchant. Name, as shown in addresses and on invoices, 80 characters at '
                            . 'most, without < > = { }. Left out, kept.',
                    ],
                    'iso_code' => [
                        'type' => 'string',
                        'description' => 'Merchant. ISO code: 1 to 4 letters or digits, optionally a dash and 1 to 4 '
                            . 'more, for example CA or US-CA; stored upper case. Left out, kept.',
                    ],
                    'country' => [
                        'type' => 'string',
                        'description' => 'Merchant. Country, its ISO code as somcp_list_countries gives it, for '
                            . 'example US; the country must use states. Left out, kept.',
                    ],
                    'id_zone' => [
                        'type' => 'integer',
                        'description' => 'Merchant. Zone, an id_zone from somcp_list_zones: the zone the carrier '
                            . 'prices read for the addresses of this state. Left out, kept.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Status: false stops offering the state in the address form. '
                            . 'Left out, kept.',
                    ],
                    'id_country' => [
                        'type' => 'integer',
                        'description' => 'Worked out by the shop: taken only as somcp_list_states gives it, so a '
                            . 'row of that list can be given back whole.',
                    ],
                    'country_name' => [
                        'type' => 'string',
                        'description' => 'Worked out by the shop: taken only as somcp_list_states gives it.',
                    ],
                    'zone' => [
                        'type' => 'string',
                        'description' => 'Worked out by the shop: taken only as somcp_list_states gives it.',
                    ],
                ],
                'required' => ['id_state'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Editing a state is what its edit form and the Enabled switch ask
            // for in Team, Permissions; adding one is a different right.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminStates')
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

        $errors = SomcpTool::unknownFields(
            $arguments,
            ['id_state', 'name', 'iso_code', 'country', 'id_zone', 'active', 'id_country', 'country_name', 'zone']
        );
        $stored = null;
        $idState = SomcpTool::id($arguments, 'id_state');
        if ($idState === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_state');
        } else {
            // Only a state of a country of the shop of the token, as the list
            // reads them: a state has no shop table of its own.
            $stored = SomcpToolListStates::state($idShop, $idState);
            if ($stored === null) {
                $errors[] = SomcpTool::notFound('id_state', 'state', $idState, 'somcp_list_states');
            }
        }

        $name = SomcpToolCreateState::name($arguments, false, $errors);
        $countryGiven = array_key_exists('country', $arguments) && $arguments['country'] !== null;
        $idCountry = SomcpToolCreateState::country($arguments, $idShop, false, $errors);
        // The ISO code is free or not within the country the state ends up
        // in: the new one when it is given and valid, its own otherwise.
        $isoCountry = $countryGiven ? $idCountry : ($stored !== null ? (int) $stored['id_country'] : 0);
        $isoCode = SomcpToolCreateState::isoCode($arguments, $isoCountry, false, $idState, $errors);
        $countryChanged = $stored !== null && $idCountry > 0 && $idCountry !== (int) $stored['id_country'];
        if ($countryChanged && !array_key_exists('iso_code', $arguments)) {
            // The code kept must also be free in the country it moves to.
            SomcpToolCreateState::isoCode(['iso_code' => $stored['iso_code']], $idCountry, true, $idState, $errors);
        }
        if ($countryChanged) {
            // The addresses keep their own id_country and the tax rule rows
            // their own pair: moved, the state would name another country
            // than its addresses and match none of its rows.
            $addresses = (new State($idState))->countUsed();
            $taxRows = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rule` WHERE `id_state` = ' . (int) $idState
            );
            if ($addresses > 0 || $taxRows > 0) {
                $errors[] = 'country: state ' . $idState . ' is used by ' . $addresses . ' addresses and ' . $taxRows
                    . ' tax rule rows - a state of another country would no longer match them';
            }
        }

        $idZone = 0;
        if (array_key_exists('id_zone', $arguments) && $arguments['id_zone'] !== null) {
            $idZone = SomcpTool::id($arguments, 'id_zone');
            if ($idZone === 0) {
                $errors[] = SomcpTool::notAnIdentifier('id_zone');
            } elseif (!SomcpToolListCountries::zoneOfShop($idZone, $idShop)) {
                $errors[] = SomcpTool::notFound('id_zone', 'zone', $idZone, 'somcp_list_zones');
                $idZone = 0;
            }
        }

        $active = null;
        if (array_key_exists('active', $arguments) && $arguments['active'] !== null) {
            // A boolean only: "false" cast to bool is true, and would keep
            // offering a state the merchant wanted off.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } else {
                $active = $arguments['active'];
            }
        }

        // Taken only as the list answers them, so a row given back whole is
        // not refused; any other value would claim a change this field cannot
        // make.
        foreach (['id_country', 'country_name', 'zone'] as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== null
                && ($stored === null || $arguments[$field] !== $stored[$field])) {
                $errors[] = $field . ': worked out by the shop - change country or id_zone';
            }
        }
        if ($errors !== [] || $stored === null) {
            return SomcpTool::refused('changed', $errors);
        }

        // Compared with what the list reads, so a row of somcp_list_states
        // given back whole writes nothing.
        $changes = [];
        if ($name !== null && $name !== $stored['name']) {
            self::record($changes, 'name', $stored['name'], $name);
        }
        if ($isoCode !== null && $isoCode !== $stored['iso_code']) {
            self::record($changes, 'iso_code', $stored['iso_code'], $isoCode);
        }
        if ($countryChanged) {
            self::record($changes, 'country', $stored['country'], (string) Country::getIsoById($idCountry));
        }
        if ($idZone > 0 && $idZone !== $stored['id_zone']) {
            self::record($changes, 'id_zone', $stored['id_zone'], $idZone);
        }
        if ($active !== null && $active !== $stored['active']) {
            self::record($changes, 'active', $stored['active'], $active);
        }
        if ($changes === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_state' => $idState,
                'reason' => 'The state already holds every value given. Nothing was written.',
                'state' => $stored,
            ]);
        }
        $fields = array_column($changes, 'field');

        // Only the fields that differ are set: tax_behavior, not on the form,
        // keeps what the shop stored.
        $state = new State($idState);
        if (in_array('name', $fields, true)) {
            $state->name = (string) $name;
        }
        if (in_array('iso_code', $fields, true)) {
            $state->iso_code = (string) $isoCode;
        }
        if ($countryChanged) {
            $state->id_country = $idCountry;
        }
        if (in_array('id_zone', $fields, true)) {
            $state->id_zone = $idZone;
        }
        if (in_array('active', $fields, true)) {
            $state->active = (bool) $active;
        }
        try {
            $done = $state->update();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('state_update_failed', SomcpLogger::OUTCOME_FAILURE, 'state ' . $idState . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text(
                'The state could not be changed: ' . $failure . '. Read it with somcp_list_states before calling '
                    . 'again.',
                true
            );
        }
        SomcpLogger::log('state_updated', SomcpLogger::OUTCOME_SUCCESS, 'state ' . $idState . ': '
            . implode(', ', $fields), '', $idEmployee);
        // The shape of every update tool: changed a boolean, changes what was
        // written with its old value.
        $result = ['changed' => true, 'id_state' => $idState, 'changes' => $changes];

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $row = SomcpToolListStates::state($idShop, $idState);

        $warnings = [];
        if (in_array('id_zone', $fields, true) && SomcpToolUpdateZone::carriers($idZone) === []) {
            $warnings[] = 'No carrier serves zone ' . $idZone . ' (' . ($row !== null ? $row['zone'] : '') . '): the '
                . 'addresses of this state are offered no carrier until one serves it - see somcp_update_carrier.';
        }
        if (in_array('active', $fields, true) && $active === false) {
            $warnings[] = 'The state is no longer offered in the address form; the ' . $state->countUsed()
                . ' addresses that use it keep it.';
        }
        // A state has no shop table: the change reaches every shop of its
        // country, said the way the creation says it.
        $otherShops = SomcpToolCreateTax::otherShops();
        if ($otherShops > 0) {
            $result['also_in_other_shops'] = $otherShops;
            $warnings[] = 'A state is not tied to a shop: this change reaches every shop of this installation ('
                . ($otherShops + 1) . ') that sells to its country.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        $result['state'] = $row;

        return SomcpTool::json($result);
    }

    /**
     * One entry of changes, as the other update tools record it.
     *
     * @param array<int, array<string, mixed>> $changes
     * @param string $field
     * @param mixed $old
     * @param mixed $new
     *
     * @return void
     */
    private static function record(array &$changes, string $field, $old, $new): void
    {
        $changes[] = ['field' => $field, 'old' => $old, 'new' => $new];
    }
}
