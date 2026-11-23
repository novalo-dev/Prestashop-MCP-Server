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
 * somcp_list_currencies - the currencies of International > Localization >
 * Currencies, with their exchange rate and the shop default.
 *
 * Every tool that takes a currency takes its ISO code, yet the codes were
 * only given in the refusal of somcp_set_order_currency, without the names,
 * the rates, the disabled currencies or the default. A disabled currency is
 * listed too, so the assistant can tell the merchant why the other tools
 * refuse it.
 *
 * Under the catalogue scope, as the taxes and the languages: currencies are
 * read to write prices and price rules, by the same assistant and with the
 * same consent.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListCurrencies
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_currencies',
            Somcp::t('List currencies'),
            'Lists the currencies of International > Localization > Currencies, by identifier as that list: '
                . 'id_currency, name, symbol, iso_code, exchange rate of the shop, Enabled, and the shop default. '
                . 'The iso_code is what the currency of every other tool takes, and only an enabled currency is '
                . 'accepted there; rates are against the default currency.',
            [
                'type' => 'object',
                'properties' => [
                    'id_currency' => [
                        'type' => 'integer',
                        'description' => 'Keep the currency with this identifier (ID), as the filter of the '
                            . 'list: an identifier no currency of the shop has gives an empty list.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Part of the currency name (Currency), case-insensitive, read in the '
                            . 'language asked. Left out, every currency.',
                    ],
                    'iso_code' => [
                        'type' => 'string',
                        'description' => 'The ISO code of one currency (ISO code), exact and case-insensitive, '
                            . 'for example EUR.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true for enabled currencies only, false for disabled ones only. Left '
                            . 'out, all of them.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, for the '
                            . 'names and symbols; a code the shop does not have is refused. The shop default '
                            . 'language when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Currencies are managed on their own back office page, and that
            // is the page a merchant would open to look at them.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminCurrencies')
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

        // A misspelt filter, or an Enabled that is not a boolean, would answer
        // another list than the one asked for without a word: refused, with
        // a wrong language among them so the call is corrected in one go.
        $errors = SomcpTool::unknownFields($arguments, ['id_currency', 'name', 'iso_code', 'active', 'language']);
        $idCurrency = 0;
        if (array_key_exists('id_currency', $arguments) && $arguments['id_currency'] !== null) {
            $idCurrency = SomcpTool::id($arguments, 'id_currency');
            if ($idCurrency === 0) {
                $errors[] = SomcpTool::notAnIdentifier('id_currency');
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

        $query = self::query($idShop, $idLang);
        // The ID, Currency, ISO code and Enabled (All / Yes / No) filters of the list.
        if ($idCurrency > 0) {
            $query->where('c.`id_currency` = ' . (int) $idCurrency);
        }
        $name = isset($arguments['name']) && is_scalar($arguments['name']) ? trim((string) $arguments['name']) : '';
        if ($name !== '') {
            $query->where("cl.`name` LIKE '%" . pSQL($name) . "%'");
        }
        $code = isset($arguments['iso_code']) && is_scalar($arguments['iso_code']) ? strtoupper(trim((string) $arguments['iso_code'])) : '';
        if ($code !== '') {
            $query->where("c.`iso_code` = '" . pSQL($code) . "'");
        }
        if (isset($arguments['active'])) {
            $query->where('c.`active` = ' . (int) $arguments['active']);
        }
        // Identifier order, as the back office list, so a currency sits where
        // the merchant sees it.
        $query->orderBy('c.`id_currency` ASC');

        $default = self::defaultCurrency($idShop);
        $currencies = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $currencies[] = self::row($row, $default);
        }

        return SomcpTool::json([
            'currencies' => $currencies,
            'count' => count($currencies),
            'language' => $iso,
        ]);
    }

    /**
     * One currency of the list, every column of its tables, or null when the shop lists none with this identifier.
     *
     * Public so somcp_get_currency finds a currency exactly where this list
     * shows it, and reads its other fields from the same row.
     *
     * @param int $idShop
     * @param int $idLang the language of the name, the symbol and the format
     * @param int $idCurrency
     *
     * @return array<string, mixed>|null
     */
    public static function currency(int $idShop, int $idLang, int $idCurrency): ?array
    {
        $query = self::query($idShop, $idLang);
        $query->select('c.`precision`, c.`numeric_iso_code`, c.`unofficial`, c.`modified`, cl.`pattern`');
        $query->where('c.`id_currency` = ' . (int) $idCurrency);
        $row = Db::getInstance()->getRow($query);

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * A currency as the list answers it.
     *
     * Public so somcp_get_currency gives the same keys, built the same way,
     * before the fields of the edit page.
     *
     * @param array<string, mixed> $row a row of query()
     * @param int $default the identifier of the shop default currency
     *
     * @return array<string, mixed>
     */
    public static function row(array $row, int $default): array
    {
        return [
            'id_currency' => (int) $row['id_currency'],
            'name' => (string) ($row['name'] ?? ''),
            'symbol' => (string) ($row['symbol'] ?? ''),
            'iso_code' => (string) $row['iso_code'],
            'conversion_rate' => (float) $row['conversion_rate'],
            'active' => (bool) $row['active'],
            'default' => (int) $row['id_currency'] === $default,
        ];
    }

    /**
     * The currency every rate of the shop is relative to.
     *
     * @param int $idShop
     *
     * @return int
     */
    public static function defaultCurrency(int $idShop): int
    {
        return (int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop);
    }

    /**
     * The currencies of the shop, as the back office list shows them, before its filters.
     *
     * A query rather than Currency::getCurrencies(), which has no name or ISO
     * code filter. The list of a shop shows the currencies currency_shop ties
     * to it, with the rate that shop converts at.
     *
     * @param int $idShop
     * @param int $idLang
     *
     * @return DbQuery
     */
    private static function query(int $idShop, int $idLang): DbQuery
    {
        $query = new DbQuery();
        $query->select('c.`id_currency`, cl.`name`, cl.`symbol`, c.`iso_code`, cs.`conversion_rate`, c.`active`');
        $query->from('currency', 'c');
        $query->innerJoin(
            'currency_shop',
            'cs',
            'cs.`id_currency` = c.`id_currency` AND cs.`id_shop` = ' . (int) $idShop
        );
        // A left join, so a currency with no name in this language is still
        // listed rather than silently missing from the only list of codes.
        $query->leftJoin(
            'currency_lang',
            'cl',
            'cl.`id_currency` = c.`id_currency` AND cl.`id_lang` = ' . (int) $idLang
        );
        // A deleted currency stays in the table, flagged, for the orders that
        // used it; the back office list leaves it out, and so does this.
        $query->where('c.`deleted` = 0');

        return $query;
    }
}
