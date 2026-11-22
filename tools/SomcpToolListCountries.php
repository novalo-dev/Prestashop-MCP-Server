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
 * somcp_list_countries - the countries of International > Locations >
 * Countries, with their zone and what an address there must hold.
 *
 * Every tool that takes a country takes it by its ISO code, and no tool
 * gave one: an assistant had to guess it, and could not tell which
 * countries the shop sells to. The postcode, state and tax identification
 * rules are returned because the address and tax rule tools refuse an
 * address that misses them, and the assistant should ask the merchant for
 * them before writing rather than after the refusal.
 *
 * Under the orders scope, as the carriers and the zones: countries are read
 * to set up delivery, by the same assistant and with the same consent.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListCountries
{
    /** @var int Countries returned when the caller asks for no particular number, the default of the list. */
    private const DEFAULT_LIMIT = 50;

    /** @var int Most a single call will return, whatever it asks for. */
    private const MAX_LIMIT = 300;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_countries',
            Somcp::t('List countries'),
            'Lists the countries of International > Locations > Countries: id_country, name, iso_code, call '
                . 'prefix, zone, Enabled, and what an address there must hold (states, postcode and its format, '
                . 'tax identification number). Take iso_code from here before giving a country to another tool: '
                . 'never guess one. total_count gives all the matches: read the next page with offset. For the '
                . 'zones themselves use somcp_list_zones.',
            [
                'type' => 'object',
                'properties' => [
                    'id_country' => [
                        'type' => 'integer',
                        'description' => 'Keep the country with this identifier (ID), as the filter of the '
                            . 'list: an identifier no country of the shop has gives an empty list.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Part of the country name (Country), case-insensitive, read in the '
                            . 'language of the answer. Left out, every country.',
                    ],
                    'iso_code' => [
                        'type' => 'string',
                        'description' => 'The ISO code of one country (ISO code), exact and case-insensitive, '
                            . 'for example fr.',
                    ],
                    'id_zone' => [
                        'type' => 'integer',
                        'description' => 'Keep the countries of this zone (Zone), an id_zone from '
                            . 'somcp_list_zones; a zone the shop does not have is refused.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true for enabled countries only, false for disabled ones only. Left '
                            . 'out, all of them.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, '
                            . 'for the names; a code the shop does not have is refused. The shop default '
                            . 'language when left out.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many countries to return, 50 by default and 300 at most.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'How many to skip, to read the next page.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_READ],
            [self::class, 'handle'],
            // Countries are managed on their own back office page, and that is
            // the page a merchant would open to look at them.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminCountries')
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

        // A misspelt filter, an Enabled that is not a boolean or a zone the
        // shop does not have would answer another list than the one asked
        // for without a word: refused, every problem at once so the call is
        // corrected in one go.
        $errors = SomcpTool::unknownFields(
            $arguments,
            ['id_country', 'name', 'iso_code', 'id_zone', 'active', 'language', 'limit', 'offset']
        );
        $idCountry = 0;
        if (array_key_exists('id_country', $arguments) && $arguments['id_country'] !== null) {
            $idCountry = SomcpTool::id($arguments, 'id_country');
            if ($idCountry === 0) {
                $errors[] = SomcpTool::notAnIdentifier('id_country');
            }
        }
        $idZone = 0;
        if (array_key_exists('id_zone', $arguments) && $arguments['id_zone'] !== null) {
            $idZone = SomcpTool::id($arguments, 'id_zone');
            if ($idZone === 0) {
                $errors[] = SomcpTool::notAnIdentifier('id_zone');
            } elseif (!self::zoneOfShop($idZone, $idShop)) {
                // Unlike an unknown country, which the screen answers with an
                // empty list, a zone is chosen from somcp_list_zones: one it
                // does not give is a mistake an empty list would hide.
                $errors[] = SomcpTool::notFound('id_zone', 'zone', $idZone, 'somcp_list_zones');
            }
        }
        if (array_key_exists('active', $arguments) && !is_bool($arguments['active'])) {
            $errors[] = 'active: true or false';
        }
        if ($language === null) {
            $errors[] = SomcpTool::languageProblem($idShop);
        }
        if ($errors !== [] || $language === null) {
            return SomcpTool::refused('read', $errors);
        }
        [$idLang, $iso] = $language;

        $filters = [
            'id_country' => $idCountry,
            'id_zone' => $idZone,
            'name' => isset($arguments['name']) && is_scalar($arguments['name']) ? trim((string) $arguments['name']) : '',
            'iso_code' => isset($arguments['iso_code']) && is_scalar($arguments['iso_code'])
                ? strtoupper(trim((string) $arguments['iso_code'])) : '',
            'active' => isset($arguments['active']) ? (int) $arguments['active'] : null,
        ];

        // Counted before the limit, so the assistant knows there is another
        // page instead of concluding from one that it has seen every country.
        $count = self::query($filters, $idShop, $idLang);
        $count->select('COUNT(*)');
        $total = (int) Db::getInstance()->getValue($count);

        $limit = isset($arguments['limit']) && is_numeric($arguments['limit']) ? (int) $arguments['limit'] : self::DEFAULT_LIMIT;
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = isset($arguments['offset']) && is_numeric($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;

        $page = self::query($filters, $idShop, $idLang);
        $page->select('c.`id_country`, cl.`name`, c.`iso_code`, c.`call_prefix`, c.`id_zone`, z.`name` AS `zone`');
        $page->select('c.`active`, c.`contains_states`, c.`need_zip_code`, c.`zip_code_format`, c.`need_identification_number`');
        $page->leftJoin('zone', 'z', 'z.`id_zone` = c.`id_zone`');
        // Name order, as the back office list, so a country sits where the
        // merchant sees it; the identifier settles two countries of one name.
        $page->orderBy('cl.`name` ASC, c.`id_country` ASC');
        $page->limit($limit, $offset);

        $countries = [];
        foreach (Db::getInstance()->executeS($page) ?: [] as $row) {
            $countries[] = [
                'id_country' => (int) $row['id_country'],
                'name' => (string) ($row['name'] ?? ''),
                'iso_code' => (string) $row['iso_code'],
                // 0 where the list shows -, a country with no prefix.
                'call_prefix' => (int) $row['call_prefix'],
                // The keys of somcp_list_zones and of the zones of
                // somcp_get_carrier, so a zone read here is the same there.
                'id_zone' => (int) $row['id_zone'],
                'zone' => (string) ($row['zone'] ?? ''),
                'active' => (bool) $row['active'],
                // What an address of the country must hold, which the address
                // and tax rule tools check: the assistant asks for it first.
                'contains_states' => (bool) $row['contains_states'],
                'need_zip_code' => (bool) $row['need_zip_code'],
                'zip_code_format' => (string) ($row['zip_code_format'] ?? ''),
                'need_identification_number' => (bool) $row['need_identification_number'],
            ];
        }

        return SomcpTool::json([
            'countries' => $countries,
            'returned' => count($countries),
            'total_count' => $total,
            'offset' => $offset,
            'language' => $iso,
        ]);
    }

    /**
     * The countries of the shop that match the filters, without what is selected.
     *
     * A query rather than Country::getCountries(), which has no name or ISO
     * filter and no paging. The list of a shop shows the countries
     * country_shop ties to it.
     *
     * @param array{id_country: int, id_zone: int, name: string, iso_code: string, active: int|null} $filters
     * @param int $idShop
     * @param int $idLang
     *
     * @return DbQuery
     */
    private static function query(array $filters, int $idShop, int $idLang): DbQuery
    {
        $query = new DbQuery();
        $query->from('country', 'c');
        $query->innerJoin('country_shop', 'cs', 'cs.`id_country` = c.`id_country` AND cs.`id_shop` = ' . (int) $idShop);
        // A left join, so a country with no name in this language is still
        // listed rather than silently missing from the only list of ISO codes.
        $query->leftJoin('country_lang', 'cl', 'cl.`id_country` = c.`id_country` AND cl.`id_lang` = ' . (int) $idLang);
        // The ID, Country, ISO code, Zone and Enabled filters of the list.
        if ($filters['id_country'] > 0) {
            $query->where('c.`id_country` = ' . (int) $filters['id_country']);
        }
        if ($filters['id_zone'] > 0) {
            $query->where('c.`id_zone` = ' . (int) $filters['id_zone']);
        }
        if ($filters['name'] !== '') {
            $query->where("cl.`name` LIKE '%" . pSQL($filters['name']) . "%'");
        }
        if ($filters['iso_code'] !== '') {
            $query->where("c.`iso_code` = '" . pSQL($filters['iso_code']) . "'");
        }
        if ($filters['active'] !== null) {
            $query->where('c.`active` = ' . (int) $filters['active']);
        }

        return $query;
    }

    /**
     * Whether the zone is one of the zones of the shop, as somcp_list_zones lists them.
     *
     * Public and shared with somcp_list_states, so a zone filter is refused
     * the same way wherever it is taken.
     *
     * @param int $idZone
     * @param int $idShop
     *
     * @return bool
     */
    public static function zoneOfShop(int $idZone, int $idShop): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'zone_shop` WHERE `id_zone` = ' . (int) $idZone
            . ' AND `id_shop` = ' . (int) $idShop
        );
    }
}
