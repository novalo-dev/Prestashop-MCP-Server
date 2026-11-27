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
 * somcp_get_contact_details - the card Contact details of Shop Parameters >
 * Contact, tab Stores.
 *
 * The shop's own name, e-mail, registration number, address and phone, which
 * the e-mails, the invoices and the page titles print. The fields carry the
 * names of the screen, so the change that follows takes the same object back.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolGetGeneralSettings.php';

class SomcpToolGetContactDetails
{
    /**
     * @var array<string, string> The fields of the card, in the order of the screen, and their setting
     */
    public const KEYS = [
        'shop_name' => 'PS_SHOP_NAME',
        'shop_email' => 'PS_SHOP_EMAIL',
        'registration_number' => 'PS_SHOP_DETAILS',
        'address1' => 'PS_SHOP_ADDR1',
        'address2' => 'PS_SHOP_ADDR2',
        'postcode' => 'PS_SHOP_CODE',
        'city' => 'PS_SHOP_CITY',
        'id_country' => 'PS_SHOP_COUNTRY_ID',
        'id_state' => 'PS_SHOP_STATE_ID',
        'phone' => 'PS_SHOP_PHONE',
        'fax' => 'PS_SHOP_FAX',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_contact_details',
            Somcp::t('Get the contact details of the shop'),
            'Reads the card Contact details of Shop Parameters > Contact > Stores: the shop\'s own name, e-mail '
                . 'address, registration number, address, country, state, phone and fax, which the e-mails, the '
                . 'invoices and the page titles print. Read only. For the stores shown on the storefront use '
                . 'somcp_list_stores, for the addresses of the contact form somcp_list_contacts.',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_READ],
            [self::class, 'handle'],
            // The card sits on the page of the stores, whose view right lets a
            // merchant read it.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminStores')
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
        $problems = SomcpTool::unknownFields($arguments, []);
        if ($problems !== []) {
            return SomcpTool::refused('read', $problems);
        }

        $idShop = Somcp::currentShopId();
        // The names of the country and the state in the shop's default
        // language, said beside them.
        $language = SomcpTool::readLanguage([], $idShop);
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;

        $result = ['contact_details' => self::details($idShop, $idLang), 'language' => $iso];

        if ($result['contact_details']['id_country'] === null) {
            // The screen preselects the country of the context when none is
            // saved; the answer says so rather than giving it as the shop's.
            $country = Context::getContext()->country;
            $name = Validate::isLoadedObject($country) ? (string) Country::getNameById($idLang, (int) $country->id) : '';
            if ($name !== '') {
                $result['warning'] = 'No country is saved: the screen preselects ' . $name
                    . ', which the shop does not use until it is saved.';
            }
        }

        return SomcpTool::json($result);
    }

    /**
     * The card as the tool answers it.
     *
     * Public so the change of these details answers them in this very shape,
     * which the assistant can give back to it as it is.
     *
     * @param int $idShop
     * @param int $idLang the language of the country and state names
     *
     * @return array<string, mixed>
     */
    public static function details(int $idShop, int $idLang): array
    {
        $details = [];
        foreach (self::KEYS as $field => $key) {
            $value = SomcpToolGetGeneralSettings::stored($key, $idShop);
            if ($field === 'id_country' || $field === 'id_state') {
                // 0 is the "Choose" line of the select: no identifier saved.
                $details[$field] = (int) $value > 0 ? (int) $value : null;
                continue;
            }
            $details[$field] = $value;
        }

        // The names come from the identifiers, never from PS_SHOP_COUNTRY and
        // PS_SHOP_STATE, which only follow them when the screen saves.
        $country = $details['id_country'] !== null ? Country::getNameById($idLang, $details['id_country']) : false;
        $state = $details['id_state'] !== null ? State::getNameById($details['id_state']) : false;

        $ordered = [];
        foreach ($details as $field => $value) {
            $ordered[$field] = $value;
            if ($field === 'id_country') {
                $ordered['country'] = is_string($country) && $country !== '' ? $country : null;
            } elseif ($field === 'id_state') {
                $ordered['state'] = is_string($state) && $state !== '' ? $state : null;
            }
        }

        return $ordered;
    }
}
