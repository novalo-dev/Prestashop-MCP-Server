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
 * somcp_list_states - the states of International > Locations > States,
 * with their ISO code, their country and their zone.
 *
 * The tax rule, supplier and brand address tools take a state by its ISO
 * code within its country, yet no tool gave those codes: an assistant adding
 * a tax row for a few US states, or a supplier address in Canada, had to
 * guess them.
 *
 * Under the orders scope, as the countries and the zones: states are read
 * to set up where the shop delivers and taxes, by the same assistant and
 * with the same consent.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListStates
{
    /** @var int States returned when the caller asks for no particular number, the default of the list. */
    private const DEFAULT_LIMIT = 50;

    /** @var int Most a single call will return, whatever it asks for. */
    private const MAX_LIMIT = 300;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_states',
            Somcp::t('List states'),
            'Lists the states of International > Locations > States: id_state, name, iso_code, country, zone, '
                . 'Enabled. The iso_code, within its country, is what state and states of every other tool take: '
                . 'take it from here, never guess one. Filter by country with its ISO code, as the other tools '
                . 'take it. total_count gives all the matches: read the next page with offset. For the countries '
                . 'use somcp_list_countries, for the zones somcp_list_zones.',
            [
                'type' => 'object',
                'properties' => [
                    'id_state' => [
                        'type' => 'integer',
                        'description' => 'Keep the state with this identifier (ID), as the filter of the list: '
                            . 'an identifier no state of the shop has gives an empty list.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Part of the state name (Name), case-insensitive. Left out, every state.',
                    ],
                    'iso_code' => [
                        'type' => 'string',
                        'description' => 'The ISO code of the state (ISO code), exact and case-insensitive, for '
                            . 'example CA; states of other countries may share it.',
                    ],
                    'country' => [
                        'type' => 'string',
                        'description' => 'Keep the states of this country (Country), its ISO code as '
                            . 'somcp_list_countries gives it, for example US; a country the shop does not have '
                            . 'is refused.',
                    ],
                    'id_zone' => [
                        'type' => 'integer',
                        'description' => 'Keep the states of this zone (Zone), an id_zone from '
                            . 'somcp_list_zones; a zone the shop does not have is refused.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true for enabled states only, false for disabled ones only. Left out, '
                            . 'all of them.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many states to return, 50 by default and 300 at most.',
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
            // States are managed on their own back office page, and that is
            // the page a merchant would open to look at them.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminStates')
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

        // A misspelt filter, an Enabled that is not a boolean, or a country or
        // zone the shop does not have would answer another list than the one
        // asked for without a word: refused, every problem at once so the
        // call is corrected in one go.
        $errors = SomcpTool::unknownFields(
            $arguments,
            ['id_state', 'name', 'iso_code', 'country', 'id_zone', 'active', 'limit', 'offset']
        );
        $idState = 0;
        if (array_key_exists('id_state', $arguments) && $arguments['id_state'] !== null) {
            $idState = SomcpTool::id($arguments, 'id_state');
            if ($idState === 0) {
                $errors[] = SomcpTool::notAnIdentifier('id_state');
            }
        }
        $idCountry = 0;
        if (array_key_exists('country', $arguments) && $arguments['country'] !== null && $arguments['country'] !== '') {
            if (!is_scalar($arguments['country'])) {
                // A list or an object is a mistake in the call, not a code
                // the shop lacks: told as such, the way an identifier of the
                // wrong kind is kept apart from one no record has.
                $errors[] = 'country: an ISO code of 2 letters, for example US';
            } else {
                $code = Tools::strtoupper(trim((string) $arguments['country']));
                // The ISO code the country rule and supplier tools take, through
                // the join they read the shop's countries with; validated first
                // so a malformed code never reaches the query.
                $found = Validate::isLanguageIsoCode($code) ? SomcpToolCreateSupplier::shopCountries($idShop, $code) : [];
                $idCountry = (int) array_key_first($found);
                if ($idCountry === 0) {
                    $errors[] = SomcpTool::notFound('country', 'country', $code, 'somcp_list_countries', 'ISO code');
                }
            }
        }
        // A state code of the wrong kind was dropped from the filters, which
        // answered every state as if that one had been asked for.
        if (array_key_exists('iso_code', $arguments) && $arguments['iso_code'] !== null && !is_scalar($arguments['iso_code'])) {
            $errors[] = 'iso_code: the ISO code of a state, for example CA';
        }
        $idZone = 0;
        if (array_key_exists('id_zone', $arguments) && $arguments['id_zone'] !== null) {
            $idZone = SomcpTool::id($arguments, 'id_zone');
            if ($idZone === 0) {
                $errors[] = SomcpTool::notAnIdentifier('id_zone');
            } elseif (!SomcpToolListCountries::zoneOfShop($idZone, $idShop)) {
                // A zone is chosen from somcp_list_zones: one it does not give
                // is a mistake an empty list would hide.
                $errors[] = SomcpTool::notFound('id_zone', 'zone', $idZone, 'somcp_list_zones');
            }
        }
        if (array_key_exists('active', $arguments) && !is_bool($arguments['active'])) {
            $errors[] = 'active: true or false';
        }
        if ($errors !== []) {
            return SomcpTool::refused('read', $errors);
        }

        $filters = [
            'id_state' => $idState,
            'id_country' => $idCountry,
            'id_zone' => $idZone,
            'name' => isset($arguments['name']) && is_scalar($arguments['name']) ? trim((string) $arguments['name']) : '',
            'iso_code' => isset($arguments['iso_code']) && is_scalar($arguments['iso_code'])
                ? strtoupper(trim((string) $arguments['iso_code'])) : '',
            'active' => isset($arguments['active']) ? (int) $arguments['active'] : null,
        ];

        // Counted before the limit, so the assistant knows there is another
        // page instead of concluding from one that it has seen every state.
        $count = self::query($filters, $idShop);
        $count->select('COUNT(*)');
        $total = (int) Db::getInstance()->getValue($count);

        $limit = isset($arguments['limit']) && is_numeric($arguments['limit']) ? (int) $arguments['limit'] : self::DEFAULT_LIMIT;
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = isset($arguments['offset']) && is_numeric($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;

        $page = self::select(self::query($filters, $idShop), $idShop);
        // Identifier order, as the back office list, so a state sits where
        // the merchant sees it.
        $page->orderBy('s.`id_state` ASC');
        $page->limit($limit, $offset);

        $states = [];
        foreach (Db::getInstance()->executeS($page) ?: [] as $row) {
            $states[] = self::row($row);
        }

        return SomcpTool::json([
            'states' => $states,
            'returned' => count($states),
            'total_count' => $total,
            'offset' => $offset,
        ]);
    }

    /**
     * One state of the shop as the list returns it, or null when the shop has none with this identifier.
     *
     * Public so somcp_create_state answers the state it wrote with the same
     * fields as the list.
     *
     * @param int $idShop
     * @param int $idState
     *
     * @return array<string, mixed>|null
     */
    public static function state(int $idShop, int $idState): ?array
    {
        $filters = ['id_state' => $idState, 'id_country' => 0, 'id_zone' => 0, 'name' => '', 'iso_code' => '', 'active' => null];
        $row = Db::getInstance()->getRow(self::select(self::query($filters, $idShop), $idShop));

        return is_array($row) && $row !== [] ? self::row($row) : null;
    }

    /**
     * A row of the list, built from what select() reads.
     *
     * Public so every tool that answers a state gives the same fields.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function row(array $row): array
    {
        return [
            'id_state' => (int) $row['id_state'],
            'name' => (string) $row['name'],
            'iso_code' => (string) $row['iso_code'],
            'id_country' => (int) $row['id_country'],
            'country' => (string) $row['country'],
            'country_name' => (string) ($row['country_name'] ?? ''),
            // The state's own zone, not always the zone of its country: the
            // key of somcp_list_zones and of the zones of somcp_get_carrier.
            'id_zone' => (int) $row['id_zone'],
            'zone' => (string) ($row['zone'] ?? ''),
            'active' => (bool) $row['active'],
        ];
    }

    /**
     * The columns row() reads, added to a query of query().
     *
     * @param DbQuery $query
     * @param int $idShop
     *
     * @return DbQuery
     */
    private static function select(DbQuery $query, int $idShop): DbQuery
    {
        // A state name is not translated; the country is named in the shop
        // default language, as somcp_get_tax_rule names it.
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $query->select('s.`id_state`, s.`name`, s.`iso_code`, s.`id_country`, c.`iso_code` AS `country`');
        $query->select('cl.`name` AS `country_name`, s.`id_zone`, z.`name` AS `zone`, s.`active`');
        $query->leftJoin('country_lang', 'cl', 'cl.`id_country` = s.`id_country` AND cl.`id_lang` = ' . (int) $idLang);
        $query->leftJoin('zone', 'z', 'z.`id_zone` = s.`id_zone`');

        return $query;
    }

    /**
     * The states of the shop that match the filters, without what is selected.
     *
     * A query rather than State::getStates(), which has no filter and no
     * paging. States belong to no shop (there is no state_shop table): those
     * of the shop are the states of the countries country_shop ties to it,
     * as somcp_list_countries reads them.
     *
     * @param array{id_state: int, id_country: int, id_zone: int, name: string, iso_code: string, active: int|null} $filters
     * @param int $idShop
     *
     * @return DbQuery
     */
    private static function query(array $filters, int $idShop): DbQuery
    {
        $query = new DbQuery();
        $query->from('state', 's');
        $query->innerJoin('country', 'c', 'c.`id_country` = s.`id_country`');
        $query->innerJoin('country_shop', 'cs', 'cs.`id_country` = s.`id_country` AND cs.`id_shop` = ' . (int) $idShop);
        // The ID, Name, ISO code, Zone, Country and Enabled filters of the list.
        if ($filters['id_state'] > 0) {
            $query->where('s.`id_state` = ' . (int) $filters['id_state']);
        }
        if ($filters['id_country'] > 0) {
            $query->where('s.`id_country` = ' . (int) $filters['id_country']);
        }
        if ($filters['id_zone'] > 0) {
            $query->where('s.`id_zone` = ' . (int) $filters['id_zone']);
        }
        if ($filters['name'] !== '') {
            $query->where("s.`name` LIKE '%" . pSQL($filters['name']) . "%'");
        }
        if ($filters['iso_code'] !== '') {
            $query->where("s.`iso_code` = '" . pSQL($filters['iso_code']) . "'");
        }
        if ($filters['active'] !== null) {
            $query->where('s.`active` = ' . (int) $filters['active']);
        }

        return $query;
    }
}
