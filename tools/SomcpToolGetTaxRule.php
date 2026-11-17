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
 * somcp_get_tax_rule - one tax rule as its edit page shows it, with the tax it
 * applies per country, state and zip code range.
 *
 * somcp_list_tax_rules gives the rate in the shop's default country only: a
 * shop selling abroad charges another rate to a customer delivered there, and
 * that rate lives in the rows of the rule. Under the catalogue scope, as the
 * list: it is read to price a product, by the same assistant and with the
 * same consent.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetTaxRule
{
    /** @var array<int, string> the choices of Behavior, by the value stored in tax_rule.behavior */
    private const BEHAVIORS = [
        0 => 'this_tax_only',
        1 => 'combine',
        2 => 'one_after_another',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_tax_rule',
            Somcp::t('Get a tax rule'),
            'Reads one tax rule of International > Taxes > Tax Rules as its edit page shows it: Name, Enable '
                . 'and every row, the tax it applies per Country, State and Zip/Postal code with its Behavior, '
                . 'plus the rate in the shop default country. Use it to tell what a customer delivered abroad '
                . 'pays. To list the rules or find an id_tax_rules_group use somcp_list_tax_rules; for the '
                . 'tax of one product use somcp_get_product.',
            [
                'type' => 'object',
                'properties' => [
                    'id_tax_rules_group' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the tax rule, from somcp_list_tax_rules (active: false '
                            . 'lists disabled ones too).',
                    ],
                    'country' => [
                        'type' => 'string',
                        'description' => 'ISO code of a country, for example DE: only the rows of that country. '
                            . 'Left out, every row.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, for the '
                            . 'country, state and tax names; a code the shop does not have is refused. The shop '
                            . 'default language when left out.',
                    ],
                ],
                'required' => ['id_tax_rules_group'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Tax rules are managed on their own back office page, and that is
            // the page a merchant would open to look at one.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminTaxRulesGroup')
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

        // A cast would read an array as 1 and answer about a rule nobody
        // asked for: only a whole number is an identifier.
        $idGroup = SomcpTool::id($arguments, 'id_tax_rules_group');
        $errors = SomcpTool::unknownFields($arguments, ['id_tax_rules_group', 'country', 'language']);
        // Listed with the call's other mistakes, so it is corrected in one go.
        if ($language === null) {
            $errors[] = SomcpTool::languageProblem($idShop);
        }
        $group = null;
        if ($idGroup === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_tax_rules_group');
        } else {
            $group = self::existingRule($idGroup, $idShop);
            if ($group === null) {
                $errors[] = SomcpTool::notFound('id_tax_rules_group', 'tax rule', $idGroup, 'somcp_list_tax_rules');
            }
        }
        // A country the shop does not know is refused rather than read as
        // every row: the assistant would answer for countries nobody asked about.
        $idCountry = 0;
        if (array_key_exists('country', $arguments) && $arguments['country'] !== null && $arguments['country'] !== '') {
            $code = is_string($arguments['country']) ? Tools::strtoupper(trim($arguments['country'])) : '';
            $idCountry = Validate::isLanguageIsoCode($code) ? (int) Country::getByIso($code) : 0;
            if ($idCountry === 0) {
                $errors[] = 'country: not a country of this shop - give its ISO code, for example DE';
            }
        }
        if ($errors !== [] || $group === null || $language === null) {
            return SomcpTool::refused('read', $errors);
        }
        [$idLang, $iso] = $language;

        return SomcpTool::json(self::answer($idGroup, $group, $idLang, $iso, $idShop, $idCountry));
    }

    /**
     * One tax rule as this tool answers it, or null when the shop of the
     * token has no such rule.
     *
     * Public so the tools that write a rule answer with it read back here:
     * the write and the read then give the same fields under the same names.
     *
     * @param int $idGroup
     * @param int $idLang
     * @param string $iso
     * @param int $idShop
     *
     * @return array<string, mixed>|null
     */
    public static function rule(int $idGroup, int $idLang, string $iso, int $idShop): ?array
    {
        $group = self::existingRule($idGroup, $idShop);

        return $group === null ? null : self::answer($idGroup, $group, $idLang, $iso, $idShop, 0);
    }

    /**
     * @param int $idGroup
     * @param array<string, mixed> $group the stored row, from existingRule()
     * @param int $idLang
     * @param string $iso
     * @param int $idShop
     * @param int $idCountry 0 for every country
     *
     * @return array<string, mixed>
     */
    private static function answer(int $idGroup, array $group, int $idLang, string $iso, int $idShop, int $idCountry): array
    {
        [$rates, $defaultCountry] = SomcpToolListTaxRules::defaultCountryRates($idShop);

        return [
            'id_tax_rules_group' => $idGroup,
            'name' => (string) $group['name'],
            'active' => (bool) $group['active'],
            'date_add' => SomcpTool::isoDate($group['date_add']),
            'date_upd' => SomcpTool::isoDate($group['date_upd']),
            'country_rules' => self::rows($idGroup, $idLang, $idCountry),
            // Read by the same function as somcp_list_tax_rules, so the two
            // tools never give two rates for the same rule.
            'rate_in_default_country' => (float) ($rates[$idGroup] ?? 0),
            'default_country' => $defaultCountry,
            // Said apart: with taxes switched off the shop charges none,
            // whatever the rows below say.
            'taxes_enabled' => (bool) Configuration::get('PS_TAX', null, null, $idShop),
            'language' => $iso,
        ];
    }

    /**
     * The tax rule as stored, when it belongs to the shop of the token and
     * was not deleted.
     *
     * @param int $idGroup
     * @param int $idShop
     *
     * @return array<string, mixed>|null
     */
    private static function existingRule(int $idGroup, int $idShop): ?array
    {
        // A query rather than new TaxRulesGroup(), which loads a rule of any
        // shop. A rule deleted from the back office stays in the table,
        // flagged, for the orders that used it: answered as unknown, as
        // somcp_list_tax_rules leaves it out.
        $row = Db::getInstance()->getRow(
            'SELECT g.`name`, g.`active`, g.`date_add`, g.`date_upd`'
            . ' FROM `' . _DB_PREFIX_ . 'tax_rules_group` g'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'tax_rules_group_shop` gs'
            . ' ON gs.`id_tax_rules_group` = g.`id_tax_rules_group` AND gs.`id_shop` = ' . (int) $idShop
            . ' WHERE g.`id_tax_rules_group` = ' . (int) $idGroup . ' AND g.`deleted` = 0'
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * The rows of the rule, in the order of the list of its edit page.
     *
     * @param int $idGroup
     * @param int $idLang
     * @param int $idCountry 0 for every country
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rows(int $idGroup, int $idLang, int $idCountry): array
    {
        // Left joins: a row may point at a tax deleted since, or at no state,
        // and the edit page still lists it.
        $query = new DbQuery();
        $query->select('tr.`id_tax_rule`, tr.`zipcode_from`, tr.`zipcode_to`, tr.`behavior`, tr.`id_tax`,'
            . ' tr.`description`, c.`iso_code` AS country, cl.`name` AS country_name, tr.`id_state`,'
            . ' s.`iso_code` AS state, s.`name` AS state_name, t.`rate`, tl.`name` AS tax_name');
        $query->from('tax_rule', 'tr');
        $query->leftJoin('country', 'c', 'c.`id_country` = tr.`id_country`');
        $query->leftJoin('country_lang', 'cl', 'cl.`id_country` = tr.`id_country` AND cl.`id_lang` = ' . (int) $idLang);
        $query->leftJoin('state', 's', 's.`id_state` = tr.`id_state`');
        $query->leftJoin('tax', 't', 't.`id_tax` = tr.`id_tax`');
        $query->leftJoin('tax_lang', 'tl', 'tl.`id_tax` = tr.`id_tax` AND tl.`id_lang` = ' . (int) $idLang);
        $query->where('tr.`id_tax_rules_group` = ' . (int) $idGroup);
        if ($idCountry > 0) {
            $query->where('tr.`id_country` = ' . (int) $idCountry);
        }
        $query->orderBy('tr.`id_tax_rule` ASC');

        $rows = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $idTax = (int) $row['id_tax'];
            $hasState = (int) $row['id_state'] > 0;
            $rows[] = [
                'id_tax_rule' => (int) $row['id_tax_rule'],
                'country' => (string) $row['country'],
                'country_name' => (string) $row['country_name'],
                // No state is the All of the State column.
                'state' => $hasState ? (string) $row['state'] : null,
                'state_name' => $hasState ? (string) $row['state_name'] : null,
                // A row with no range stores 0, which the list shows as --.
                'zipcode_from' => self::zipcode($row['zipcode_from']),
                'zipcode_to' => self::zipcode($row['zipcode_to']),
                'behavior' => self::BEHAVIORS[(int) $row['behavior']] ?? 'this_tax_only',
                'id_tax' => $idTax,
                // No Tax is stored as 0: it has no name, and charges nothing.
                'tax_name' => $idTax > 0 ? (string) $row['tax_name'] : null,
                'rate' => $idTax > 0 ? (float) $row['rate'] : 0.0,
                'description' => (string) $row['description'],
            ];
        }

        return $rows;
    }

    /**
     * A zip code bound, or null when the row has none.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private static function zipcode($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '0' ? null : $value;
    }
}
