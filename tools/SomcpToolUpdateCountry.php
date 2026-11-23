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
 * somcp_update_country - one country of International > Locations >
 * Countries, as Edit then Save, or the Enabled switch and the 8.0.1 bulk
 * action Assign to a new zone for one country: Country, ISO code, Call
 * prefix, Default currency, Zone, Active, Contains states, Do you need a tax
 * identification number? and Display tax label.
 *
 * Two edges of the back office the tool settles the same way on both
 * versions. A new zone takes with it the states of the country that were in
 * its old zone, as 8.0.1 does and 9.0.0 does not: the addresses carry the
 * zone of their state, and left behind they would be offered none of the
 * carriers of the new zone. And enabling a country writes no payment
 * restriction, which the 8.0.1 switch adds for every module without asking:
 * that choice belongs to Payment > Preferences, and a warning says when no
 * module is offered to the country.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// country is read back as somcp_get_country reads it, the texts are taken by
// the rule of every write tool, and the carriers of a zone are read as the
// zone tools read them.
require_once __DIR__ . '/SomcpToolGetCountry.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolCreateTax.php';
require_once __DIR__ . '/SomcpToolUpdateZone.php';

class SomcpToolUpdateCountry
{
    /** @var array<int, string> The Yes/No fields of the form, under the names somcp_get_country returns. */
    private const SWITCHES = ['active', 'contains_states', 'need_identification_number', 'display_tax_label'];

    /** @var array<int, string> Fields of somcp_get_country this tool does not write: taken only as read. */
    private const COMPUTED = ['language', 'zone', 'currency', 'states', 'address_format_used'];

    /** @var array<int, string> Fields of the form left to the third part of the edit of a country. */
    private const LATER = ['need_zip_code', 'zip_code_format', 'address_format'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $flag = static function (string $label, string $role): array {
            return ['type' => 'boolean', 'description' => 'Merchant. ' . $label . ': ' . $role . ' Left out, kept.'];
        };
        $computed = ['description' => 'Worked out by the shop: taken only as somcp_get_country gives it, so a row of '
            . 'that read can be given back whole.'];

        return new SomcpTool(
            'somcp_update_country',
            Somcp::t('Change a country'),
            'Changes one country of International > Locations > Countries, as Edit then Save or the Enabled '
                . 'switch: name, ISO code, call prefix, default currency, zone, active, contains states, tax '
                . 'identification number, tax label. Take id_country from somcp_list_countries; a row of '
                . 'somcp_get_country can be given back whole, and only what differs is written. A new zone takes '
                . 'along the states that were in the old one. For one state use somcp_update_state, for its tax '
                . 'somcp_add_country_rule.',
            [
                'type' => 'object',
                'properties' => [
                    'id_country' => [
                        'type' => 'integer',
                        'description' => 'Required. The country to change (ID), from somcp_list_countries.',
                    ],
                    'languages' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'ISO codes of the languages the name is given in, for example ["fr"]; '
                            . 'every active language of the shop when left out.',
                    ],
                    'name' => [
                        'type' => ['object', 'string'],
                        'description' => 'Merchant. Country: one text per language keyed by ISO code, for example '
                            . '{"en": "France"}, 64 characters at most, without < > = { }; a stored name given back '
                            . 'is no change. Left out, kept.',
                    ],
                    'iso_code' => [
                        'type' => 'string',
                        'description' => 'Merchant. ISO code: 2 or 3 letters, for example FR, stored upper case, '
                            . 'that no other country has. Left out, kept.',
                    ],
                    'call_prefix' => [
                        'type' => 'integer',
                        'description' => 'Merchant. Call prefix: the international dialling code without +, for '
                            . 'example 33; 0 for none. Left out, kept.',
                    ],
                    'id_currency' => [
                        'type' => 'integer',
                        'description' => 'Merchant. Default currency: 0 for Default store currency, or an '
                            . 'id_currency from somcp_list_currencies. Left out, kept.',
                    ],
                    'id_zone' => [
                        'type' => 'integer',
                        'description' => 'Merchant. Zone, an id_zone from somcp_list_zones: the zone the carrier '
                            . 'prices read for the addresses of this country. Left out, kept.',
                    ],
                    'active' => $flag('Active', 'false stops offering the country to customers.'),
                    'contains_states' => $flag('Contains states', 'whether the address form asks for a state.'),
                    'need_identification_number' => $flag(
                        'Do you need a tax identification number?',
                        'whether a new address must give one.'
                    ),
                    'display_tax_label' => $flag(
                        'Display tax label (e.g. "Tax incl.")',
                        'whether its customers see the tax label beside prices.'
                    ),
                    'language' => ['type' => 'string'] + $computed,
                    'zone' => ['type' => 'string'] + $computed,
                    'currency' => ['type' => ['string', 'null']] + $computed,
                    'states' => ['type' => 'integer'] + $computed,
                    'address_format_used' => ['type' => 'array', 'items' => ['type' => 'string']] + $computed,
                    'need_zip_code' => ['type' => 'boolean', 'description' => 'Not changed by this tool: taken only '
                        . 'as somcp_get_country gives it.'],
                    'zip_code_format' => ['type' => 'string', 'description' => 'Not changed by this tool: taken only '
                        . 'as somcp_get_country gives it.'],
                    'address_format' => ['type' => ['array', 'null'], 'items' => ['type' => 'string'], 'description' => 'Not '
                        . 'changed by this tool: taken only as somcp_get_country gives it.'],
                ],
                'required' => ['id_country'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Editing a country is what its edit form and the Enabled switch
            // ask for in Team, Permissions; adding one is a different right.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminCountries')
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
        // The answer is read in the shop default language, as a read that
        // names no language.
        $answerLanguage = SomcpTool::readLanguage([], $idShop);

        $errors = $answerLanguage === null ? [SomcpTool::languageProblem($idShop)] : [];
        array_push($errors, ...SomcpTool::unknownFields($arguments, array_merge(
            ['id_country', 'languages', 'name', 'iso_code', 'call_prefix', 'id_currency', 'id_zone'],
            self::SWITCHES,
            self::COMPUTED,
            self::LATER
        )));
        $stored = null;
        $country = null;
        $idCountry = SomcpTool::id($arguments, 'id_country');
        if ($idCountry === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_country');
        } elseif ($answerLanguage !== null) {
            // Only a country country_shop ties to the shop of the token, as
            // the list and the read filter them.
            $stored = SomcpToolGetCountry::country($idShop, $answerLanguage, $idCountry);
            if ($stored === null) {
                $errors[] = SomcpTool::notFound('id_country', 'country', $idCountry, 'somcp_list_countries');
            } else {
                // Loaded in every language, so a name given in one keeps the others.
                $country = new Country($idCountry);
            }
        }

        $set = [];
        $changes = [];
        self::planName($arguments, $country, $idShop, $set, $changes, $errors);
        self::planFields($arguments, $country, $idShop, $idCountry, $set, $changes, $errors);
        self::givenBack($arguments, $stored, $idShop, $errors);

        if ($errors === [] && $country !== null && $set !== []) {
            // The same check the core runs before update(), on the whole object
            // as it will be written, as a last net for a rule none of the above knew.
            $candidate = clone $country;
            foreach ($set as $field => $value) {
                $candidate->{$field} = $value;
            }
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'country: ' . strip_tags((string) $check);
                }
            }
        }
        if ($errors !== [] || $stored === null) {
            return SomcpTool::refused('changed', $errors);
        }
        if ($changes === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_country' => $idCountry,
                'reason' => 'The country already holds every value given. Nothing was written.',
                'country' => $stored,
            ]);
        }

        $oldZone = (int) $country->id_zone;
        foreach ($set as $field => $value) {
            $country->{$field} = $value;
        }
        try {
            $done = $country->update();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        $fields = array_column($changes, 'field');
        if (!$done) {
            SomcpLogger::log('country_update_failed', SomcpLogger::OUTCOME_FAILURE, 'country ' . $idCountry . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text(
                'The country could not be changed: ' . $failure . '. Read it with somcp_get_country before calling '
                    . 'again.',
                true
            );
        }
        $moved = isset($set['id_zone']) ? self::moveStates($idCountry, $oldZone, (int) $set['id_zone']) : null;
        SomcpLogger::log('country_updated', SomcpLogger::OUTCOME_SUCCESS, 'country ' . $idCountry . ': '
            . implode(', ', $fields) . ($moved !== null ? ' (' . count($moved) . ' states moved)' : ''), '', $idEmployee);

        // The shape of every update tool: changed a boolean, changes what was
        // written with its old value.
        $result = ['changed' => true, 'id_country' => $idCountry, 'changes' => $changes];
        if ($moved !== null) {
            $result['states_moved'] = $moved;
        }

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $row = SomcpToolGetCountry::country($idShop, $answerLanguage, $idCountry);
        $name = $row !== null ? (string) $row['name'] : (string) $idCountry;

        $warnings = self::warnings($set, $country, $idShop, $idCountry, $name, $row);
        // A country row is shared by every shop: the change reaches them all,
        // said the way the tax and zone tools say it.
        $otherShops = SomcpToolCreateTax::otherShops();
        if ($otherShops > 0) {
            $result['also_in_other_shops'] = $otherShops;
            $warnings[] = 'A country is shared by every shop: this change reaches every shop of this installation ('
                . ($otherShops + 1) . ') that sells to it.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        $result['country'] = $row;

        return SomcpTool::json($result);
    }

    /**
     * The names to write, per language, by the rule of every write tool (#149).
     *
     * @param array<string, mixed> $arguments
     * @param Country|null $country loaded in every language
     * @param int $idShop
     * @param array<string, mixed> $set
     * @param array<int, array<string, mixed>> $changes
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function planName(array $arguments, ?Country $country, int $idShop, array &$set, array &$changes, array &$errors): void
    {
        if (!array_key_exists('name', $arguments) && !array_key_exists('languages', $arguments)) {
            return;
        }
        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $errors);
        if ($languages === null || !array_key_exists('name', $arguments)) {
            return;
        }
        $stored = $country !== null ? self::names($country->name) : [];
        // somcp_get_country answers the name as one text: given back as read,
        // it is no change.
        if (SomcpToolCreateProduct::givenBackAsRead($arguments['name'], $stored, $languages)) {
            return;
        }
        $names = SomcpToolCreateProduct::texts($arguments, 'name', 'name', $languages, $errors, true);
        if ($names === null) {
            return;
        }
        $probe = new Country();
        $max = SomcpTool::columnSize($probe, 'name', 64);
        $merged = $stored;
        foreach ($languages['all'] as $idLang) {
            $merged[(int) $idLang] = $merged[(int) $idLang] ?? '';
        }
        $changed = false;
        foreach ($names as $idLang => $name) {
            $name = trim($name);
            $where = 'name (' . $languages['filled'][$idLang] . ')';
            if (Tools::strlen($name) > $max) {
                $errors[] = $where . ': ' . $max . ' characters at most, ' . Tools::strlen($name) . ' given';
                continue;
            }
            if ($probe->validateField('name', $name, $idLang) !== true) {
                $errors[] = $where . ': a character PrestaShop refuses in a country name (< > = { })';
                continue;
            }
            $old = $stored[$idLang] ?? '';
            if ($old === $name) {
                continue;
            }
            $merged[$idLang] = $name;
            $changed = true;
            $changes[] = ['field' => $where, 'old' => $old, 'new' => $name];
        }
        if ($changed) {
            $set['name'] = $merged;
        }
    }

    /**
     * The name of a country loaded without a language, as one text per language.
     *
     * Loaded that way the core gives an array, yet PrestaShop 8.0 declares the
     * field as a plain string, so the value goes through an untyped parameter:
     * read against the declaration, the array case would look impossible.
     *
     * @param mixed $value
     *
     * @return array<int, string>
     */
    private static function names($value): array
    {
        $names = [];
        foreach (is_array($value) ? $value : [] as $idLang => $text) {
            $names[(int) $idLang] = (string) $text;
        }

        return $names;
    }

    /**
     * The other fields of the form, each checked, kept only when it differs from the shop.
     *
     * @param array<string, mixed> $arguments
     * @param Country|null $country
     * @param int $idShop
     * @param int $idCountry
     * @param array<string, mixed> $set
     * @param array<int, array<string, mixed>> $changes
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function planFields(array $arguments, ?Country $country, int $idShop, int $idCountry, array &$set, array &$changes, array &$errors): void
    {
        $given = static function (string $field) use ($arguments): bool {
            return array_key_exists($field, $arguments) && $arguments[$field] !== null;
        };
        $keep = static function (string $field, $old, $new) use ($country, &$set, &$changes): void {
            if ($country !== null && $old !== $new) {
                $set[$field] = $new;
                $changes[] = ['field' => $field, 'old' => $old, 'new' => $new];
            }
        };

        if ($given('iso_code')) {
            $iso = is_string($arguments['iso_code']) ? strtoupper(trim($arguments['iso_code'])) : '';
            if (!Validate::isLanguageIsoCode($iso)) {
                $errors[] = 'iso_code: 2 or 3 letters, for example FR';
            } else {
                // Refused by 8.0.1 and accepted by 9.0.0: two countries of one
                // code would leave every lookup by ISO code to chance.
                $other = (int) Country::getByIso($iso);
                if ($other > 0 && $other !== $idCountry) {
                    $errors[] = 'iso_code: country ' . $other . ' (' . Country::getNameById(
                        (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop),
                        $other
                    ) . ') already has the ISO code ' . $iso;
                } else {
                    $keep('iso_code', $country !== null ? (string) $country->iso_code : '', $iso);
                }
            }
        }

        if ($given('call_prefix')) {
            $prefix = SomcpToolCreateProduct::integer($arguments['call_prefix']);
            if ($prefix === null || $prefix < 0) {
                $errors[] = 'call_prefix: a whole number, 0 for none (the list shows -)';
            } else {
                $keep('call_prefix', $country !== null ? (int) $country->call_prefix : 0, $prefix);
            }
        }

        if ($given('id_currency')) {
            $idCurrency = SomcpToolCreateProduct::integer($arguments['id_currency']);
            if ($idCurrency === null || $idCurrency < 0) {
                $errors[] = 'id_currency: 0 for Default store currency, or an id_currency from somcp_list_currencies';
            } elseif ($idCurrency > 0 && !self::currencyOfShop($idCurrency, $idShop)) {
                $errors[] = SomcpTool::notFound('id_currency', 'currency', $idCurrency, 'somcp_list_currencies');
            } else {
                $keep('id_currency', $country !== null ? (int) $country->id_currency : 0, $idCurrency);
            }
        }

        if ($given('id_zone')) {
            $idZone = SomcpTool::id($arguments, 'id_zone');
            if ($idZone === 0) {
                $errors[] = SomcpTool::notAnIdentifier('id_zone');
            } elseif (!SomcpToolListCountries::zoneOfShop($idZone, $idShop)) {
                $errors[] = SomcpTool::notFound('id_zone', 'zone', $idZone, 'somcp_list_zones');
            } else {
                $keep('id_zone', $country !== null ? (int) $country->id_zone : 0, $idZone);
            }
        }

        foreach (self::SWITCHES as $field) {
            if (!$given($field)) {
                continue;
            }
            // A boolean only: "false" cast to bool is true, and would open a
            // country the merchant wanted closed.
            if (!is_bool($arguments[$field])) {
                $errors[] = $field . ': true or false';
                continue;
            }
            $keep($field, $country !== null ? (bool) $country->{$field} : false, $arguments[$field]);
        }
    }

    /**
     * Refuses a field of somcp_get_country this tool does not write, unless it is given back as read.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $stored the country as somcp_get_country reads it
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function givenBack(array $arguments, ?array $stored, int $idShop, array &$errors): void
    {
        $from = [
            'language' => 'the language of the read',
            'zone' => 'id_zone',
            'currency' => 'id_currency',
            'states' => 'the states with somcp_create_state and somcp_delete_state',
            'address_format_used' => 'the address format',
        ];
        foreach (self::COMPUTED as $field) {
            if (!array_key_exists($field, $arguments)) {
                continue;
            }
            // The read may have been made in any language of the shop: the
            // name of that language is what it gave back, not a change.
            $same = $field === 'language'
                ? SomcpTool::readLanguage(['language' => $arguments['language']], $idShop) !== null
                : $stored !== null && $arguments[$field] === $stored[$field];
            if (!$same) {
                $errors[] = $field . ': worked out by the shop - change ' . $from[$field];
            }
        }
        foreach (self::LATER as $field) {
            if (array_key_exists($field, $arguments) && ($stored === null || $arguments[$field] !== $stored[$field])) {
                $errors[] = $field . ': changed by somcp_update_country part 3 (#361), not written yet';
            }
        }
    }

    /**
     * Whether the currency is one somcp_list_currencies lists for the shop.
     *
     * @param int $idCurrency
     * @param int $idShop
     *
     * @return bool
     */
    private static function currencyOfShop(int $idCurrency, int $idShop): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'currency_shop` WHERE `id_currency` = ' . (int) $idCurrency
            . ' AND `id_shop` = ' . (int) $idShop
        );
    }

    /**
     * Moves to the new zone the states of the country that were in its old one.
     *
     * As 8.0.1 does on Save, on both versions: an address carries the zone of
     * its state, so the states left behind would keep the addresses of the
     * country out of the carriers of its new zone. A state the merchant put
     * in another zone keeps it. Through State, so each change is saved as
     * the states page saves it.
     *
     * @param int $idCountry
     * @param int $oldZone
     * @param int $newZone
     *
     * @return array<int, int> the states moved
     */
    private static function moveStates(int $idCountry, int $oldZone, int $newZone): array
    {
        $moved = [];
        foreach (Db::getInstance()->executeS(
            'SELECT `id_state` FROM `' . _DB_PREFIX_ . 'state` WHERE `id_country` = ' . (int) $idCountry
            . ' AND `id_zone` = ' . (int) $oldZone . ' ORDER BY `id_state` ASC'
        ) ?: [] as $line) {
            $state = new State((int) $line['id_state']);
            $state->id_zone = $newZone;
            if ($state->update()) {
                $moved[] = (int) $line['id_state'];
            }
        }

        return $moved;
    }

    /**
     * What the change leaves the merchant to do elsewhere.
     *
     * @param array<string, mixed> $set what was written
     * @param Country $country as written
     * @param int $idShop
     * @param int $idCountry
     * @param string $name
     * @param array<string, mixed>|null $row the country read back
     *
     * @return array<int, string>
     */
    private static function warnings(array $set, Country $country, int $idShop, int $idCountry, string $name, ?array $row): array
    {
        $warnings = [];
        if (($set['active'] ?? null) === true) {
            $payment = (bool) Db::getInstance()->getValue(
                'SELECT 1 FROM `' . _DB_PREFIX_ . 'module_country` WHERE `id_country` = ' . (int) $idCountry
                . ' AND `id_shop` = ' . (int) $idShop
            );
            if (!$payment) {
                $warnings[] = 'No payment module is offered to ' . $name . ' in this shop: its customers cannot pay '
                    . 'until one is allowed for it in Payment > Preferences.';
            }
            $idZone = (int) $country->id_zone;
            if (SomcpToolUpdateZone::carriers($idZone) === []) {
                $warnings[] = 'No carrier serves zone ' . $idZone . ' (' . ($row !== null ? $row['zone'] : '')
                    . '): customers of ' . $name . ' are offered no carrier until one serves it - see '
                    . 'somcp_update_carrier.';
            }
        }
        $states = $row !== null ? (int) $row['states'] : 0;
        if (($set['contains_states'] ?? null) === false && $states > 0) {
            $warnings[] = 'The ' . $states . ' states of ' . $name . ' are no longer offered in its address form; '
                . 'they are kept.';
        } elseif (($set['contains_states'] ?? null) === true && $states === 0) {
            $warnings[] = $name . ' has no state yet: add them with somcp_create_state.';
        }
        if (($set['need_identification_number'] ?? null) === true) {
            $warnings[] = 'New addresses in ' . $name . ' must now give an identification number; the existing '
                . 'ones are not checked.';
        }

        return $warnings;
    }
}
