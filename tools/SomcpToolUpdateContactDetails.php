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
 * country and the state kept in step as the screen keeps them.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

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
                            'id_country' => [
                                'type' => ['integer', 'null'],
                                'description' => 'Country, an id_country of somcp_list_countries.',
                            ],
                            'country' => [
                                'type' => ['string', 'null'],
                                'description' => 'Read only: the name of id_country, accepted as read.',
                            ],
                            'id_state' => [
                                'type' => ['integer', 'null'],
                                'description' => 'State, an enabled id_state of the country from somcp_list_states; '
                                    . 'required when the country has states.',
                            ],
                            'state' => [
                                'type' => ['string', 'null'],
                                'description' => 'Read only: the name of id_state, accepted as read.',
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
            array_merge(array_keys(SomcpToolGetContactDetails::KEYS), ['country', 'state']),
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

        $idCountry = self::place($card, 'id_country', $stored, $problems);
        $country = null;
        if ($idCountry !== null) {
            $country = new Country($idCountry);
            if (!Validate::isLoadedObject($country)) {
                $problems[] = SomcpTool::notFound('contact_details.id_country', 'country', $idCountry, 'somcp_list_countries');
                $country = null;
            } else {
                $given['id_country'] = $idCountry;
            }
        } elseif ($stored['id_country'] !== null) {
            $country = new Country((int) $stored['id_country']);
        }

        $idState = self::place($card, 'id_state', $stored, $problems);
        if ($idState !== null && $country !== null) {
            // As beforeUpdateOptions(): an enabled state of the country given
            // or saved, or the screen refuses the whole card.
            $active = Db::getInstance()->getValue('SELECT `active` FROM `' . _DB_PREFIX_ . 'state` WHERE `id_country` = '
                . (int) $country->id . ' AND `id_state` = ' . (int) $idState);
            if ((int) $active !== 1) {
                $problems[] = 'contact_details.id_state: not a state of this country - find one with somcp_list_states';
            } else {
                $given['id_state'] = $idState;
            }
        } elseif ($idState !== null) {
            $problems[] = 'contact_details.id_state: a country first - find one with somcp_list_countries';
        }
        // The store form asks a state of a country that has some; asked only
        // when the call touches the place, so a change of the phone is never
        // held up by a card saved before.
        $touched = array_key_exists('id_country', $card) || array_key_exists('id_state', $card);
        $finalState = $given['id_state'] ?? ($idCountry === null ? $stored['id_state'] : null);
        if ($touched && $country !== null && (bool) $country->contains_states && $finalState === null
            && !self::hasError($problems, 'contact_details.id_state')) {
            $problems[] = 'contact_details.id_state: required, as this country has states - find one with somcp_list_states';
        }

        foreach (['country' => 'id_country', 'state' => 'id_state'] as $name => $id) {
            if (array_key_exists($name, $card) && $card[$name] !== $stored[$name]) {
                $problems[] = 'contact_details.' . $name . ': read only - change ' . $id;
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

        $result = [
            'changed' => true,
            'changes' => array_map(static function (array $change): array {
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
        $result['contact_details'] = SomcpToolGetContactDetails::details($idShop, $idLang);
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
     * The identifier given for the country or the state, or null when it is
     * left out or empty. The screen never writes a 0 (`if (!$this->errors &&
     * $value)`), so emptying one already saved is refused rather than
     * answered as done.
     *
     * @param array<string, mixed> $card
     * @param string $field id_country or id_state
     * @param array<string, mixed> $stored
     * @param array<int, string> $problems
     *
     * @return int|null
     */
    private static function place(array $card, string $field, array $stored, array &$problems): ?int
    {
        if (!array_key_exists($field, $card)) {
            return null;
        }
        $value = $card[$field];
        if ($value === null || $value === 0) {
            if ($stored[$field] !== null) {
                $problems[] = 'contact_details.' . $field . ': the back office cannot empty a saved '
                    . ($field === 'id_country' ? 'country' : 'state');
            }

            return null;
        }
        if (!is_int($value) || $value < 0) {
            $problems[] = SomcpTool::notAnIdentifier('contact_details.' . $field);

            return null;
        }

        return $value;
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
