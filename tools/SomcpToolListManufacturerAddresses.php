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
 * somcp_list_manufacturer_addresses - the postal addresses of the brands, as
 * the Brand addresses list of the back office shows them.
 *
 * Kept to the columns of that list: it is what an assistant reads to find the
 * address of a brand before reading or changing it, so it stays plain.
 * Everything else about one address is the job of
 * somcp_get_manufacturer_address.
 *
 * A brand address has no shop of its own in PrestaShop: the address table
 * carries no id_shop. It belongs to the shop of its brand, so only the
 * addresses of brands of the token's shop are listed.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// test of the shop lives in that class, so the brand tools never disagree on
// which brands belong to the shop.
require_once __DIR__ . '/SomcpToolGetManufacturer.php';

class SomcpToolListManufacturerAddresses
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_manufacturer_addresses',
            Somcp::t('List brand addresses'),
            'Lists the brand addresses of this shop, like the Brand addresses list of Catalog > Brands & '
                . 'Suppliers > Brands: identifier, brand, names, postal code, city and country. The filters follow the '
                . 'columns of that list. Take an address identifier from here rather than guess one, and read one '
                . 'address in full with somcp_get_manufacturer_address. For the brands themselves use '
                . 'somcp_list_manufacturers; customer and supplier addresses are not listed.',
            [
                'type' => 'object',
                'properties' => [
                    'id_manufacturer' => [
                        'type' => 'integer',
                        'description' => 'Identifier of one brand, from somcp_list_manufacturers, to list '
                            . 'its addresses only. The addresses of every brand are listed otherwise.',
                    ],
                    'lastname' => [
                        'type' => 'string',
                        'description' => 'Part of the last name (Last name), case-insensitive.',
                    ],
                    'firstname' => [
                        'type' => 'string',
                        'description' => 'Part of the first name (First name), case-insensitive.',
                    ],
                    'postcode' => [
                        'type' => 'string',
                        'description' => 'Part of the postal code (Zip/Postal code).',
                    ],
                    'city' => [
                        'type' => 'string',
                        'description' => 'Part of the city (City), case-insensitive.',
                    ],
                    'country' => [
                        'type' => 'string',
                        'description' => 'ISO code of the country (Country), for example FR: the code the '
                            . 'answer gives in country.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, '
                            . 'for country_name, which is translated; a code the shop does not have is refused. '
                            . 'The shop default language when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Brand addresses are managed under the brands page of the back
            // office, and that page's right is the one it checks for them.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminManufacturers')
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

        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        $idLang = $language[0];

        // Every problem of the call in one answer, a misspelt filter included:
        // ignored, it would widen the list without a word.
        $errors = SomcpTool::unknownFields(
            $arguments,
            ['id_manufacturer', 'lastname', 'firstname', 'postcode', 'city', 'country', 'language']
        );

        // The Country filter of the list picks one country: an unknown code
        // is said, rather than answered with an empty list the assistant would
        // read as "no brand address in this country".
        $idCountry = 0;
        $countryIso = isset($arguments['country']) && is_scalar($arguments['country']) ? trim((string) $arguments['country']) : '';
        if ($countryIso !== '') {
            // Country::getByIso() stops the request on a malformed code
            // rather than answering false, so the code is checked first.
            $idCountry = Validate::isLanguageIsoCode($countryIso) ? (int) Country::getByIso($countryIso) : 0;
            if ($idCountry <= 0) {
                $errors[] = 'country: no country has the ISO code ' . $countryIso . ' - give a code such as FR';
            }
        }

        // A brand asked for must be one of this shop: an identifier that
        // matches nothing is said, not answered with an empty list the
        // assistant would read as "this brand has no address".
        $idManufacturer = null;
        if (array_key_exists('id_manufacturer', $arguments) && $arguments['id_manufacturer'] !== null) {
            $idManufacturer = SomcpTool::id($arguments, 'id_manufacturer');
            if ($idManufacturer === 0) {
                $errors[] = SomcpTool::notAnIdentifier('id_manufacturer');
            } elseif (SomcpToolGetManufacturer::existingManufacturer($idManufacturer, $idShop) === null) {
                $errors[] = SomcpTool::notFound('id_manufacturer', 'brand', $idManufacturer, 'somcp_list_manufacturers');
            }
        }
        if ($errors !== []) {
            return SomcpTool::refused('read', $errors);
        }

        // A query rather than Address objects: the list only needs the columns
        // the back office list shows, and the brand must be in the shop of the
        // token, which an Address knows nothing about. Deleted addresses are
        // kept by PrestaShop for the orders that used them, and are hidden
        // from the back office list, so from here too.
        $query = new DbQuery();
        $query->select('a.`id_address`, a.`id_manufacturer`, m.`name` AS manufacturer_name, a.`lastname`, a.`firstname`, a.`postcode`, a.`city`, c.`iso_code`, cl.`name` AS country_name');
        $query->from('address', 'a');
        $query->innerJoin('manufacturer', 'm', 'm.`id_manufacturer` = a.`id_manufacturer`');
        $query->innerJoin('manufacturer_shop', 'ms', 'ms.`id_manufacturer` = a.`id_manufacturer` AND ms.`id_shop` = ' . $idShop);
        // A left join, so an address whose country was removed is still listed.
        $query->leftJoin('country', 'c', 'c.`id_country` = a.`id_country`');
        // The Country column shows the name, not the code: given in the
        // language asked for, so the assistant does not translate codes.
        $query->leftJoin('country_lang', 'cl', 'cl.`id_country` = a.`id_country` AND cl.`id_lang` = ' . (int) $idLang);
        $query->where('a.`id_manufacturer` > 0');
        $query->where('a.`deleted` = 0');
        if ($idManufacturer !== null) {
            $query->where('a.`id_manufacturer` = ' . (int) $idManufacturer);
        }
        if ($idCountry > 0) {
            $query->where('a.`id_country` = ' . (int) $idCountry);
        }
        // The text filters of the list, which match a part of the column.
        foreach (['lastname', 'firstname', 'postcode', 'city'] as $column) {
            $part = isset($arguments[$column]) && is_scalar($arguments[$column]) ? trim((string) $arguments[$column]) : '';
            if ($part !== '') {
                $query->where('a.`' . bqSQL($column) . "` LIKE '%" . pSQL($part) . "%'");
            }
        }
        $query->orderBy('m.`name` ASC, a.`id_address` ASC');

        $addresses = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $addresses[] = [
                'id_address' => (int) $row['id_address'],
                'id_manufacturer' => (int) $row['id_manufacturer'],
                'manufacturer_name' => (string) ($row['manufacturer_name'] ?? ''),
                'lastname' => (string) ($row['lastname'] ?? ''),
                'firstname' => (string) ($row['firstname'] ?? ''),
                'postcode' => (string) ($row['postcode'] ?? ''),
                'city' => (string) ($row['city'] ?? ''),
                'country' => isset($row['iso_code']) && $row['iso_code'] !== '' ? (string) $row['iso_code'] : null,
                'country_name' => isset($row['country_name']) && $row['country_name'] !== '' ? (string) $row['country_name'] : null,
            ];
        }

        return SomcpTool::json([
            'addresses' => $addresses,
            'count' => count($addresses),
            'language' => $language[1],
        ]);
    }
}
