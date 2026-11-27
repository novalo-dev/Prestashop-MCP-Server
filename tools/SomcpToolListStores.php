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
 * somcp_list_stores - the Stores tab of Shop Parameters > Contact.
 *
 * Each store comes with every field of its Edit form, hours and picture
 * included, so the item can be given back whole to the update that follows
 * this read. The hours are the seven lists the shop stores, never the joined
 * text of the form, so a day with two openings stays two openings.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolListCountries.php';

class SomcpToolListStores
{
    /** @var int Stores returned when the caller asks for no particular number. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Most a single call will return. */
    private const MAX_LIMIT = 100;

    /** @var array<int, string> */
    private const FIELDS = ['language', 'name', 'city', 'postcode', 'id_country', 'active', 'sort', 'limit', 'offset'];

    /**
     * @var array<string, string> The sortable columns of the back office list, each way,
     *                            in the <column>_<way> shape of somcp_list_cart_rules
     */
    private const SORTS = [
        'id_asc' => 's.`id_store` ASC',
        'id_desc' => 's.`id_store` DESC',
        'name_asc' => 'sl.`name` ASC',
        'name_desc' => 'sl.`name` DESC',
        'city_asc' => 's.`city` ASC',
        'city_desc' => 's.`city` DESC',
    ];

    /** @var array<int, string> The days of store_lang.hours, in the order the form shows them. */
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_stores',
            Somcp::t('List the stores'),
            'Lists the stores of Shop Parameters > Contact > Stores, the ones the storefront page of the stores '
                . 'shows, each with every field of its Edit form: name, address, postcode, city, country, state, '
                . 'latitude and longitude, phone, fax, e-mail address, note, whether it is shown, the opening hours '
                . 'of each day and the picture (a download_ref for somcp_download_file). Read only. For the contact '
                . 'addresses of the contact form use somcp_list_contacts.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, '
                            . 'for the translated texts; a code the shop does not have is refused. The shop default '
                            . 'language when left out.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Part of the store name, without case, as the Name filter of the list.',
                    ],
                    'city' => [
                        'type' => 'string',
                        'description' => 'Part of the city, without case, as the City filter of the list.',
                    ],
                    'postcode' => [
                        'type' => 'string',
                        'description' => 'Part of the Zip/Postal code, without case, as the filter of the list.',
                    ],
                    'id_country' => [
                        'type' => 'integer',
                        'description' => 'Only the stores of this country, an id_country from somcp_list_countries.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'True for the stores shown on the storefront (Enabled), false for the hidden ones.',
                    ],
                    'sort' => [
                        'type' => 'string',
                        'enum' => array_keys(self::SORTS),
                        'description' => 'Order of the results: the sortable columns of the back office list (ID, '
                            . 'Name, City), each way. By ID (id_asc) by default, as the screen.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many stores to return, 20 by default and 100 at most.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'How many to skip, to read the next page.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_READ],
            [self::class, 'handle'],
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
        $idShop = Somcp::currentShopId();
        $language = SomcpTool::readLanguage($arguments, $idShop);

        // Every mistake of the call in one refusal, so it is corrected in one go.
        $problems = SomcpTool::unknownFields($arguments, self::FIELDS);
        if ($language === null) {
            $problems[] = SomcpTool::languageProblem($idShop);
        }

        foreach (['name', 'city', 'postcode'] as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== null && !is_string($arguments[$field])) {
                $problems[] = $field . ': a text';
            }
        }
        if (array_key_exists('active', $arguments) && $arguments['active'] !== null && !is_bool($arguments['active'])) {
            $problems[] = 'active: true or false';
        }

        $idCountry = 0;
        if (array_key_exists('id_country', $arguments) && $arguments['id_country'] !== null) {
            $idCountry = SomcpTool::id($arguments, 'id_country');
            if ($idCountry === 0) {
                $problems[] = SomcpTool::notAnIdentifier('id_country');
            } elseif ($language !== null && SomcpToolListCountries::country($idShop, $language[0], $idCountry) === null) {
                // Only a country of the shop of the token, as somcp_list_countries lists them.
                $problems[] = SomcpTool::notFound('id_country', 'country', $idCountry, 'somcp_list_countries');
            }
        }

        // An order outside the list is named rather than dropped for the
        // default one, which would answer a different question.
        $sort = 'id_asc';
        if (array_key_exists('sort', $arguments) && $arguments['sort'] !== null) {
            if (!is_string($arguments['sort']) || !isset(self::SORTS[$arguments['sort']])) {
                $problems[] = SomcpTool::oneOf('sort', array_keys(self::SORTS));
            } else {
                $sort = $arguments['sort'];
            }
        }

        $limit = self::DEFAULT_LIMIT;
        if (array_key_exists('limit', $arguments) && $arguments['limit'] !== null) {
            if (!is_int($arguments['limit']) || $arguments['limit'] < 1 || $arguments['limit'] > self::MAX_LIMIT) {
                $problems[] = 'limit: a whole number from 1 to ' . self::MAX_LIMIT;
            } else {
                // Cast although checked: the value goes into the LIMIT clause.
                $limit = (int) $arguments['limit'];
            }
        }
        $offset = 0;
        if (array_key_exists('offset', $arguments) && $arguments['offset'] !== null) {
            if (!is_int($arguments['offset']) || $arguments['offset'] < 0) {
                $problems[] = 'offset: a whole number, 0 at least';
            } else {
                $offset = (int) $arguments['offset'];
            }
        }

        if ($problems !== [] || $language === null) {
            return SomcpTool::refused('read', $problems);
        }
        [$idLang, $iso] = $language;

        $conditions = ['ss.`id_shop` = ' . (int) $idShop];
        foreach (['name' => 'sl.`name`', 'city' => 's.`city`', 'postcode' => 's.`postcode`'] as $field => $column) {
            if (isset($arguments[$field]) && is_string($arguments[$field]) && trim($arguments[$field]) !== '') {
                $conditions[] = $column . " LIKE '%" . pSQL(trim($arguments[$field])) . "%'";
            }
        }
        if ($idCountry > 0) {
            $conditions[] = 's.`id_country` = ' . (int) $idCountry;
        }
        if (isset($arguments['active']) && is_bool($arguments['active'])) {
            $conditions[] = 's.`active` = ' . ($arguments['active'] ? 1 : 0);
        }
        $where = implode(' AND ', $conditions);

        // Only the stores tied to the shop of the token, as the list shows them.
        $from = '`' . _DB_PREFIX_ . 'store` s
             INNER JOIN `' . _DB_PREFIX_ . 'store_shop` ss ON ss.`id_store` = s.`id_store`
             LEFT JOIN `' . _DB_PREFIX_ . 'store_lang` sl
                    ON sl.`id_store` = s.`id_store` AND sl.`id_lang` = ' . (int) $idLang;

        $db = Db::getInstance();
        $total = (int) $db->getValue('SELECT COUNT(DISTINCT s.`id_store`) FROM ' . $from . ' WHERE ' . $where);
        $rows = $db->executeS(
            'SELECT s.*, sl.`name`, sl.`address1`, sl.`address2`, sl.`hours`, sl.`note`
             FROM ' . $from . '
             WHERE ' . $where . '
             ORDER BY ' . self::SORTS[$sort] . ($sort === 'id_asc' || $sort === 'id_desc' ? '' : ', s.`id_store` ASC') . '
             LIMIT ' . $offset . ', ' . $limit
        ) ?: [];

        $stores = [];
        foreach ($rows as $row) {
            $stores[] = self::item($row, $idLang, $idShop);
        }

        return SomcpTool::json([
            'stores' => $stores,
            'returned' => count($stores),
            'total_count' => $total,
            'offset' => $offset,
            'language' => $iso,
        ]);
    }

    /**
     * One store as the list answers it.
     *
     * Public so the tools that create or change a store answer it in this
     * very shape, which the assistant can give back to them as it is.
     *
     * @param array<string, mixed> $row a row of store with the store_lang columns of one language
     * @param int $idLang the language of the texts and of the country and state names
     * @param int $idShop the shop the picture is offered for
     *
     * @return array<string, mixed>
     */
    public static function item(array $row, int $idLang, int $idShop): array
    {
        $idStore = (int) $row['id_store'];
        $idCountry = (int) $row['id_country'];
        $idState = (int) $row['id_state'];
        $state = $idState > 0 ? State::getNameById($idState) : false;

        return [
            'id_store' => $idStore,
            'name' => (string) ($row['name'] ?? ''),
            'address1' => (string) ($row['address1'] ?? ''),
            'address2' => (string) ($row['address2'] ?? ''),
            'postcode' => (string) $row['postcode'],
            'city' => (string) $row['city'],
            'id_country' => $idCountry,
            'country' => $idCountry > 0 ? (string) Country::getNameById($idLang, $idCountry) : '',
            'id_state' => $idState > 0 ? $idState : null,
            'state' => is_string($state) ? $state : null,
            'latitude' => $row['latitude'] !== null && $row['latitude'] !== '' ? (float) $row['latitude'] : null,
            'longitude' => $row['longitude'] !== null && $row['longitude'] !== '' ? (float) $row['longitude'] : null,
            'phone' => (string) $row['phone'],
            'fax' => (string) $row['fax'],
            'email' => (string) $row['email'],
            'note' => (string) ($row['note'] ?? ''),
            'active' => (bool) $row['active'],
            'hours' => self::hours($row['hours'] ?? null),
            'image' => SomcpDownload::offer('store_image', (string) $idStore, $idShop),
            'date_add' => SomcpTool::isoDate($row['date_add']),
            'date_upd' => SomcpTool::isoDate($row['date_upd']),
        ];
    }

    /**
     * The hours of store_lang as the seven days by name, or null.
     *
     * The shop keeps a JSON list of seven lists of strings; anything else in
     * the row is said as null rather than guessed, so the update that follows
     * never writes back a week the read made up.
     *
     * @param mixed $stored
     *
     * @return array<string, array<int, string>>|null
     */
    public static function hours($stored): ?array
    {
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        $decoded = json_decode($stored, true);
        if (!is_array($decoded) || count($decoded) !== count(self::DAYS) || !array_is_list($decoded)) {
            return null;
        }
        $hours = [];
        foreach (self::DAYS as $index => $day) {
            $value = $decoded[$index];
            if (!is_array($value)) {
                return null;
            }
            $hours[$day] = [];
            foreach ($value as $text) {
                if (!is_string($text)) {
                    return null;
                }
                $hours[$day][] = $text;
            }
        }

        return $hours;
    }
}
