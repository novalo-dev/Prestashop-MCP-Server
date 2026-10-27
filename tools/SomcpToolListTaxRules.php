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
 * somcp_list_tax_rules - the tax rules a product can take in this shop, with
 * the rate each one applies in the shop's default country.
 *
 * A product is priced tax excluded and carries a tax rule identifier. Those
 * identifiers are created by the localization pack of each shop, so the same
 * "20 %" is 1 in one shop and 9 in the next: an assistant creating a product
 * without this list either guesses, or leaves the rule out and lets the back
 * office pick. The rate is returned next to the name because the name is
 * free text the merchant may have written in any way.
 *
 * Under the catalogue scope rather than a configuration one: it is read to
 * price a product, by the same assistant and with the same consent.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListTaxRules
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_tax_rules',
            Somcp::t('List tax rules'),
            'Lists the tax rules of this shop with their identifier, name, whether they are '
                . 'enabled, and the rate each one applies in the shop default country (given as '
                . 'its ISO code), which is the rate the back office product page shows. Call it '
                . 'before creating or changing a product, and take id_tax_rules_group from here: '
                . 'identifiers are specific to each shop, so never assume one, and 0 means no tax. '
                . 'A rate depends on the country: a customer delivered elsewhere may pay another one. '
                . 'When taxes_enabled is false the shop applies no tax at all, whatever the rule. '
                . 'Do not use it for the tax of one product: somcp_get_product gives its rule and rate.',
            [
                'type' => 'object',
                'properties' => [
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true (the default) for enabled rules only, false to include '
                            . 'disabled ones too.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Tax rules are managed on their own back office page, and that is
            // the page a merchant would open to look at them.
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

        $onlyActive = true;
        if (isset($arguments['active']) && is_bool($arguments['active'])) {
            $onlyActive = $arguments['active'];
        }

        // A query rather than TaxRulesGroup::getTaxRulesGroups(), which reads
        // the shop from the global context: here it comes from the token and
        // is passed explicitly.
        $query = new DbQuery();
        $query->select('g.`id_tax_rules_group`, g.`name`, g.`active`');
        $query->from('tax_rules_group', 'g');
        $query->innerJoin(
            'tax_rules_group_shop',
            'gs',
            'gs.`id_tax_rules_group` = g.`id_tax_rules_group` AND gs.`id_shop` = ' . $idShop
        );
        // A rule deleted from the back office stays in the table, flagged, so
        // the orders that used it keep their history; it can no longer be
        // given to a product.
        $query->where('g.`deleted` = 0');
        if ($onlyActive) {
            $query->where('g.`active` = 1');
        }
        // Same order as the core list and the product page.
        $query->orderBy('g.`name` ASC, g.`id_tax_rules_group` ASC');

        $idCountry = (int) Configuration::get('PS_COUNTRY_DEFAULT', null, null, $idShop);
        // The same call somcp_get_product and somcp_search_products make, so
        // the rate read here is the one those tools apply to a price.
        $rates = TaxRulesGroup::getAssociatedTaxRatesByIdCountry($idCountry);
        $iso = (string) Country::getIsoById($idCountry);

        $rules = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $id = (int) $row['id_tax_rules_group'];
            $rules[] = [
                'id_tax_rules_group' => $id,
                'name' => (string) $row['name'],
                'active' => (bool) $row['active'],
                // A rule with nothing for this country applies no tax there,
                // which is what the core computes too.
                'rate' => (float) ($rates[$id] ?? 0),
                'country' => $iso,
            ];
        }

        return SomcpTool::json([
            'rules' => $rules,
            'count' => count($rules),
            // Said apart: with taxes switched off the shop charges none, and
            // somcp_get_product then gives a rate of 0 whatever the rule.
            'taxes_enabled' => (bool) Configuration::get('PS_TAX', null, null, $idShop),
        ]);
    }
}
