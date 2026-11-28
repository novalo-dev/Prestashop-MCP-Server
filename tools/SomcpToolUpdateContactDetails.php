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
 * somcp_update_contact_details - the Save of the card Contact details of Shop
 * Parameters > Contact, tab Stores.
 *
 * It takes back the contact_details of somcp_get_contact_details and checks
 * each field as AdminController::processUpdateOptions() and
 * AdminStoresController::beforeUpdateOptions() do, then writes only the keys
 * whose value differs, for the shop of the token, with the names of the
 * country and the state kept in step as the screen keeps them. The country
 * and the state are taken by ISO code, as every address tool takes them.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolCreateSupplier.php';
require_once __DIR__ . '/SomcpToolGetContactDetails.php';
require_once __DIR__ . '/SomcpToolUpdateGeneralSettings.php';

class SomcpToolUpdateContactDetails
{
    /**
     * The text fields and the check of the screen for each, in the order of
     * the card. Only the name and the e-mail are required there.
     */
    private const TEXTS = [
        'shop_name' => 'isGenericName',
        'shop_email' => 'isEmail',
        'registration_number' => 'isGenericName',
        'address1' => 'isAddress',
        'address2' => 'isAddress',
        'postcode' => 'isGenericName',
        'city' => 'isGenericName',
        'phone' => 'isGenericName',
        'fax' => 'isGenericName',
    ];

    /**
     * What each check of TEXTS refuses, in the words the assistant can repeat.
     */
    private const RULES = [
        'isGenericName' => 'a text without < > = { }',
        'isEmail' => 'an e-mail address',
        'isAddress' => 'letters, digits and the signs of an address',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $text = static function (string $description): array {
            return ['type' => 'string', 'description' => $description];
        };

        return new SomcpTool(
            'somcp_update_contact_details',
            Somcp::t('Change the contact details of the shop'),
            'Changes the card Contact details of Shop Parameters > Contact > Stores, as its Save: the shop\'s '
                . 'own name, e-mail address, registration number, address, country, state, phone and fax, which '
                . 'the e-mails, the invoices and the page titles print. Only the fields given change; the '
                . 'contact_details of somcp_get_contact_details can be given back whole. The shop e-mail is the '
                . 'sender of the shop\'s e-mails. A saved country or state cannot be emptied, as on the screen.',
            [
                'type' => 'object',
                'properties' => [
                    'contact_details' => [
                        'type' => 'object',
                        'description' => 'The fields of somcp_get_contact_details, every one optional.',
                        'properties' => [
                            'shop_name' => $text('Store name, required: up to 255 characters, without < > = { }.'),
                            'shop_email' => $text('Shop email, required: the sender of the shop\'s e-mails.'),
                            'registration_number' => $text('Registration number, may be empty.'),
                            'address1' => $text('Shop address line 1, may be empty.'),
                            'address2' => $text('Shop address line 2, may be empty.'),
                            'postcode' => $text('Zip/Postal code, may be empty.'),
                            'city' => $text('City, may be empty.'),
                            'country' => [
                                'type' => ['string', 'null'],
                                'description' => 'Country, its ISO code of 2 letters (for example US), as '
                                    . 'somcp_list_countries gives it.',
                            ],
                            'state' => [
                                'type' => ['string', 'null'],
                                'description' => 'State, the ISO code of an enabled state of the country (for example '
                                    . 'FL), as somcp_list_states gives it; required when the country has states.',
                            ],
                            'id_country' => [
                                'type' => ['integer', 'null'],
                                'description' => 'Read only: the identifier of country, accepted as read.',
                            ],
                            'country_name' => [
                                'type' => ['string', 'null'],
                                'description' => 'Read only: the name of country, accepted as read.',
                            ],
                            'id_state' => [
                                'type' => ['integer', 'null'],
                                'description' => 'Read only: the identifier of state, accepted as read.',
                            ],
                            'state_name' => [
                                'type' => ['string', 'null'],
                                'description' => 'Read only: the name of state, accepted as read.',
                            ],
                            'phone' => $text('Phone, may be empty.'),
                            'fax' => $text('Fax, may be empty.'),
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'required' => ['contact_details'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_WRITE],
            [self::class, 'handle'],
            // The Save of the card is the edit right of the page of the stores.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminStores')
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
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $idEmployee = (int) ($context['id_employee'] ?? 0);
        $language = SomcpTool::readLanguage([], $idShop);
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;
        $stored = SomcpToolGetContactDetails::details($idShop, $idLang);

        // The rest of the read given back with the card is no change: its
        // language as read, and its remark on the country.
        $checked = $arguments;
        if (($checked['language'] ?? null) === $iso) {
            unset($checked['language']);
        }
        if (isset($checked['warning']) && is_string($checked['warning'])) {
            unset($checked['warning']);
        }
        // Every problem in one answer, so the call is corrected in one go.
        $problems = SomcpTool::unknownFields($checked, ['contact_details'], ['language', 'warning']);

        $card = $arguments['contact_details'] ?? null;
        if (!is_array($card) || ($card !== [] && array_is_list($card))) {
            $problems[] = 'contact_details: an object with the fields of somcp_get_contact_details';

            return SomcpTool::refused('changed', $problems);
        }
        $problems = array_merge($problems, SomcpTool::unknownFields(
            $card,
            array_keys($stored),
            [],
            'contact_details.'
        ));

        $given = [];
        foreach (self::TEXTS as $field => $rule) {
            if (!array_key_exists($field, $card)) {
                continue;
            }
            // A value given back as read is no change, even one the screen
            // would refuse now: the read given back whole is never refused.
            if ($card[$field] === $stored[$field]) {
                continue;
            }
            $problem = self::textProblem($field, $card[$field], $rule);
            if ($problem !== null) {
                $problems[] = 'contact_details.' . $field . ': ' . $problem;
                continue;
            }
            $given[$field] = $card[$field];
        }

        // The country and the state by ISO code, as every address tool takes
        // them, through the same lookups and refusal lines.
        $country = null;
        $countryChanged = false;
        if (self::emptied($card, 'country', $stored, $problems)) {
            $found = [];
            $country = SomcpToolCreateSupplier::supplierCountry(['country' => $card['country']], $idShop, $found);
            self::prefix($found, $problems);
            if ($country !== null && $country->iso_code !== $stored['country']) {
                $countryChanged = true;
                $given['id_country'] = (int) $country->id;
            }
        } elseif ($stored['id_country'] !== null) {
            $country = new Country((int) $stored['id_country']);
            $country = Validate::isLoadedObject($country) ? $country : null;
        }

        $stateGiven = self::emptied($card, 'state', $stored, $problems, $countryChanged);
        if ($stateGiven && $card['state'] === $stored['state'] && !$countryChanged) {
            $stateGiven = false;
        }
        if (($stateGiven || $countryChanged) && $country !== null) {
            $found = [];
            $idState = SomcpToolCreateSupplier::supplierState($stateGiven ? ['state' => $card['state']] : [], $country, $found);
            self::prefix($found, $problems);
            if ($idState > 0) {
                // As beforeUpdateOptions(): an enabled state of the country,
                // or the screen refuses the whole card.
                $active = Db::getInstance()->getValue('SELECT `active` FROM `' . _DB_PREFIX_ . 'state` WHERE `id_country` = '
                    . (int) $country->id . ' AND `id_state` = ' . (int) $idState);
                if ((int) $active !== 1) {
                    $problems[] = 'contact_details.state: not an enabled state of ' . $country->iso_code
                        . ' - find one with somcp_list_states';
                } else {
                    $given['id_state'] = $idState;
                }
            }
        } elseif ($stateGiven && !self::hasError($problems, 'contact_details.country')) {
            $problems[] = 'contact_details.state: a country first - find one with somcp_list_countries';
        }

        // What the read says beside the codes is given back as read, never
        // changed on its own.
        foreach (['id_country' => 'country', 'country_name' => 'country', 'id_state' => 'state', 'state_name' => 'state'] as $field => $through) {
            if (array_key_exists($field, $card) && $card[$field] !== $stored[$field]) {
                $problems[] = 'contact_details.' . $field . ': read only - change ' . $through;
            }
        }

        if ($problems !== []) {
            return SomcpTool::refused('changed', $problems);
        }

        $plan = [];
        foreach (SomcpToolGetContactDetails::KEYS as $field => $key) {
            if (array_key_exists($field, $given) && $given[$field] !== $stored[$field]) {
                $plan[] = ['field' => $field, 'key' => $key, 'old' => $stored[$field], 'new' => $given[$field]];
            }
        }
        if ($plan === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The contact details already hold every value given. Nothing was written.',
                'contact_details' => $stored,
                'language' => $iso,
            ]);
        }

        $saved = [];
        foreach ($plan as $change) {
            $values = [$change['key'] => (string) $change['new']];
            // The screen keeps the names beside the identifiers
            // (updateOptionPsShopCountryId(), updateOptionPsShopStateId()).
            if ($change['field'] === 'id_country') {
                $values['PS_SHOP_COUNTRY'] = (string) Country::getNameById($idLang, (int) $change['new']);
            } elseif ($change['field'] === 'id_state') {
                $values['PS_SHOP_STATE'] = (string) State::getNameById((int) $change['new']);
            }
            foreach ($values as $key => $value) {
                // html off, as the screen: no_escape only changes how it shows.
                if (!Configuration::updateValue($key, $value, false, $idShopGroup, $idShop)) {
                    return SomcpToolUpdateGeneralSettings::failed(
                        $saved,
                        $change['field'] . ' could not be saved',
                        $idEmployee,
                        'The contact details',
                        'somcp_get_contact_details',
                        'contact_details_update_failed'
                    );
                }
            }
            $saved[] = $change['field'];
        }
        // The fields only: the values are the shop's address and phone.
        SomcpLogger::log('contact_details_updated', SomcpLogger::OUTCOME_SUCCESS, implode(', ', $saved), '', $idEmployee);

        $after = SomcpToolGetContactDetails::details($idShop, $idLang);
        $result = [
            'changed' => true,
            'changes' => array_map(static function (array $change) use ($stored, $after): array {
                // The country and the state are said by the codes the call
                // gave, not by the identifiers written for them.
                $named = ['id_country' => 'country', 'id_state' => 'state'];
                if (isset($named[$change['field']])) {
                    $field = $named[$change['field']];

                    return ['field' => $field, 'old' => $stored[$field], 'new' => $after[$field]];
                }

                return ['field' => $change['field'], 'old' => $change['old'], 'new' => $change['new']];
            }, $plan),
        ];
        $warnings = [];
        if (in_array('shop_email', $saved, true)) {
            // Mail::send() takes PS_SHOP_EMAIL as the sender when none is given.
            $warnings[] = 'The e-mails of the shop are now sent from ' . $given['shop_email'] . '.';
        }
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'The settings changed for this shop only, as the back office saves them in a shop context.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        // Read back rather than echoed: what the assistant shows is what the
        // shop stored.
        $result['contact_details'] = $after;
        $result['language'] = $iso;

        return SomcpTool::json($result);
    }

    /**
     * What the screen would refuse in a text field, or null.
     *
     * @param string $field
     * @param mixed $value
     * @param string $rule a method of Validate
     *
     * @return string|null
     */
    private static function textProblem(string $field, $value, string $rule): ?string
    {
        if (!is_string($value)) {
            return 'a text';
        }
        $required = $field === 'shop_name' || $field === 'shop_email';
        if (trim($value) === '') {
            return $required ? 'required, it cannot be empty' : null;
        }
        if (!call_user_func(['Validate', $rule], $value)) {
            return $field === 'shop_name' ? 'a name without < > = { }' : self::RULES[$rule];
        }
        if ($field === 'shop_name' && Tools::strlen($value) > 255) {
            return 'at most 255 characters';
        }

        return null;
    }

    /**
     * Whether a code is given for the country or the state. The screen never
     * writes an empty one (`if (!$this->errors && $value)`), so emptying one
     * already saved is refused rather than answered as done.
     *
     * @param array<string, mixed> $card
     * @param string $field country or state
     * @param array<string, mixed> $stored
     * @param array<int, string> $problems
     * @param bool $countryChanged a state may be left empty for a new country without states
     *
     * @return bool
     */
    private static function emptied(array $card, string $field, array $stored, array &$problems, bool $countryChanged = false): bool
    {
        if (!array_key_exists($field, $card)) {
            return false;
        }
        if ($card[$field] === null || $card[$field] === '') {
            if ($stored[$field] !== null && !$countryChanged) {
                $problems[] = 'contact_details.' . $field . ': the back office cannot empty a saved ' . $field;
            }

            return false;
        }

        return true;
    }

    /**
     * The lines of the shared lookups, under the card they belong to.
     *
     * @param array<int, string> $found
     * @param array<int, string> $problems
     */
    private static function prefix(array $found, array &$problems): void
    {
        foreach ($found as $line) {
            $problems[] = 'contact_details.' . $line;
        }
    }

    /**
     * Whether a line about this path is already among the problems.
     *
     * @param array<int, string> $problems
     * @param string $path
     *
     * @return bool
     */
    private static function hasError(array $problems, string $path): bool
    {
        foreach ($problems as $problem) {
            if (strpos($problem, $path . ':') === 0) {
                return true;
            }
        }

        return false;
    }
}
