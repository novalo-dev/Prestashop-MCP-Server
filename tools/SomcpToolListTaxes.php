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
                    'name' => [
                        'type' => 'string',
                        'description' => 'Part of the tax name (Name), case-insensitive, read in the language '
                            . 'of the answer. Left out, every tax.',
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
        $errors = SomcpTool::unknownFields($arguments, ['name', 'active', 'language']);
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
        // The Enabled (All / Yes / No) and Name filters of the list.
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
            $taxes[] = [
                'id_tax' => (int) $row['id_tax'],
                'name' => (string) ($row['name'] ?? ''),
                'rate' => (float) $row['rate'],
                'active' => (bool) $row['active'],
            ];
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
}
