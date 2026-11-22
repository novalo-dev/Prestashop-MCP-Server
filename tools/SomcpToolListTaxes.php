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
 * somcp_list_taxes - the taxes of International > Taxes > Taxes, with the
 * rate each one charges.
 *
 * A tax rule applies a tax per country, and that tax is named by an
 * id_tax no other tool gives: an assistant setting up the taxes of a new
 * shop would have to guess it. The rate is returned next to the name
 * because the name is free text the merchant may have written in any way.
 *
 * Under the catalogue scope, as the tax rules: it is read to price a
 * product, by the same assistant and with the same consent.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListTaxes
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_taxes',
            Somcp::t('List taxes'),
            'Lists the taxes of International > Taxes > Taxes, by identifier as that list: id_tax, name, rate '
                . 'and Enabled. Take id_tax from here before setting what a tax rule applies: never guess one. '
                . 'When taxes_enabled is false the shop charges no tax at all. A product takes a tax rule, never '
                . 'a tax: for those use somcp_list_tax_rules, and for what one rule applies per country '
                . 'somcp_get_tax_rule.',
            [
                'type' => 'object',
                'properties' => [
                    'id_tax' => [
                        'type' => 'integer',
                        'description' => 'Keep the tax with this identifier (ID), as the filter of the list: '
                            . 'an identifier no tax has gives an empty list.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Part of the tax name (Name), case-insensitive, read in the language '
                            . 'of the answer. Left out, every tax.',
                    ],
                    'rate' => [
                        'type' => 'number',
                        'description' => 'Keep the taxes whose Rate is exactly this number, for example 20 or '
                            . '5.5, compared at the three decimals the shop stores.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true for enabled taxes only, false for disabled ones only. Left out, '
                            . 'all of them.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr; '
                            . 'a code the shop does not have is refused. The shop default language when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Taxes are managed on their own back office page, and that is the
            // page a merchant would open to look at them.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminTaxes')
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
        $errors = SomcpTool::unknownFields($arguments, ['id_tax', 'name', 'rate', 'active', 'language']);
        $idTax = 0;
        if (array_key_exists('id_tax', $arguments) && $arguments['id_tax'] !== null) {
            $idTax = SomcpTool::id($arguments, 'id_tax');
            if ($idTax === 0) {
                $errors[] = SomcpTool::notAnIdentifier('id_tax');
            }
        }
        $rate = null;
        if (array_key_exists('rate', $arguments) && $arguments['rate'] !== null) {
            if ((is_int($arguments['rate']) || is_float($arguments['rate'])) && is_finite((float) $arguments['rate'])) {
                $rate = round((float) $arguments['rate'], 3);
            } else {
                $errors[] = 'rate: a number, for example 20 or 5.5';
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

        // A query rather than Tax::getTaxes(), which takes the language and
        // nothing else. Taxes belong to no shop in PrestaShop (there is no
        // tax_shop table), so the list is the same for every shop.
        $query = new DbQuery();
        $query->select('t.`id_tax`, tl.`name`, t.`rate`, t.`active`');
        $query->from('tax', 't');
        // A left join, so a tax with no name in this language is still listed
        // rather than silently missing from the only list of identifiers.
        $query->leftJoin('tax_lang', 'tl', 'tl.`id_tax` = t.`id_tax` AND tl.`id_lang` = ' . (int) $idLang);
        // A tax deleted from the list stays in the table, flagged, when an
        // order used it; the back office list leaves it out, and so does this.
        $query->where('t.`deleted` = 0');
        // The ID, Rate, Enabled (All / Yes / No) and Name filters of the list.
        if ($idTax > 0) {
            $query->where('t.`id_tax` = ' . (int) $idTax);
        }
        // An equality where the list matches the rate as text: there, 6 also
        // finds 6.250 and 6.850, and an assistant given a rate means that one.
        // Rounded to the three decimals of the column, so 5.5 finds 5.500.
        if ($rate !== null) {
            $query->where('t.`rate` = ' . sprintf('%.3F', $rate));
        }
        if (isset($arguments['active'])) {
            $query->where('t.`active` = ' . (int) $arguments['active']);
        }
        $name = isset($arguments['name']) && is_scalar($arguments['name']) ? trim((string) $arguments['name']) : '';
        if ($name !== '') {
            $query->where("tl.`name` LIKE '%" . pSQL($name) . "%'");
        }
        // Identifier order, as the back office list, so a tax sits where the
        // merchant sees it.
        $query->orderBy('t.`id_tax` ASC');

        $taxes = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $taxes[] = self::row($row);
        }

        return SomcpTool::json([
            'taxes' => $taxes,
            'count' => count($taxes),
            // Said apart, as somcp_list_tax_rules does: with taxes switched
            // off the shop charges none of these rates.
            'taxes_enabled' => (bool) Configuration::get('PS_TAX', null, null, $idShop),
            'language' => $iso,
        ]);
    }

    /**
     * One tax read back as a row of this list, or null when none has the identifier.
     *
     * The row the tax write tools answer with, so a created, changed or
     * deleted tax reads with the same fields under the same names as the list.
     * A tax flagged deleted (kept for past orders) is not read, as the list
     * leaves it out.
     *
     * @param int $idTax
     * @param int $idLang the language the name is read in
     *
     * @return array{id_tax: int, name: string, rate: float, active: bool}|null
     */
    public static function tax(int $idTax, int $idLang): ?array
    {
        $query = new DbQuery();
        $query->select('t.`id_tax`, tl.`name`, t.`rate`, t.`active`');
        $query->from('tax', 't');
        $query->leftJoin('tax_lang', 'tl', 'tl.`id_tax` = t.`id_tax` AND tl.`id_lang` = ' . (int) $idLang);
        $query->where('t.`id_tax` = ' . (int) $idTax);
        $query->where('t.`deleted` = 0');
        $row = Db::getInstance()->getRow($query);

        return is_array($row) && $row !== [] ? self::row($row) : null;
    }

    /**
     * One database row mapped to the fields of this list.
     *
     * @param array<string, mixed> $row
     *
     * @return array{id_tax: int, name: string, rate: float, active: bool}
     */
    private static function row(array $row): array
    {
        return [
            'id_tax' => (int) $row['id_tax'],
            'name' => (string) ($row['name'] ?? ''),
            'rate' => (float) $row['rate'],
            'active' => (bool) $row['active'],
        ];
    }
}
