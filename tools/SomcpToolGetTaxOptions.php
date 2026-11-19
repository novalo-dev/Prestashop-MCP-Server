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
 * somcp_get_tax_options - the Tax options form below the list of
 * International > Taxes > Taxes: Enable tax, Display tax in the shopping
 * cart, Based on, Use ecotax and Ecotax.
 *
 * These five settings decide whether the rates of the tax rules are charged
 * at all, on which address and with which ecotax. An assistant can create
 * taxes and tax rules, and without them it cannot tell whether those will
 * apply.
 *
 * Read by their configuration keys for the shop of the token, as the core's
 * TaxOptionsConfiguration reads them for the shop of the context.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetTaxOptions
{
    /**
     * The values of Based on, by the words the screen shows rather than the
     * address column the core stores.
     */
    private const BASED_ON = [
        'id_address_invoice' => 'invoice_address',
        'id_address_delivery' => 'delivery_address',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_tax_options',
            Somcp::t('Read the tax options'),
            'Reads the Tax options of International > Taxes > Taxes: Enable tax (taxes_enabled), Display tax '
                . 'in the shopping cart, Based on (invoice or delivery address), Use ecotax and the Ecotax tax '
                . 'rule with the rate it charges. Read it before creating taxes or tax rules: when taxes_enabled '
                . 'is false no rate is charged at all. For the taxes and their rates use somcp_list_taxes, for '
                . 'the rate a country charges somcp_get_tax_rule, for a product\'s ecotax somcp_get_product.',
            [
                'type' => 'object',
                'properties' => [
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
            // The form sits below the list of taxes, on the page whose view
            // right lets a merchant read it.
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
        $idShop = Somcp::currentShopId();
        $language = SomcpTool::readLanguage($arguments, $idShop);

        // Every problem in one answer (#169), so the call is corrected in one go.
        $problems = SomcpTool::unknownFields($arguments, ['language']);
        if ($language === null) {
            $problems[] = SomcpTool::languageProblem($idShop);
        }
        if ($problems !== [] || $language === null) {
            return SomcpTool::refused('read', $problems);
        }
        [$idLang, $iso] = $language;

        $result = self::options($idShop, $idLang);

        $warnings = [];
        if (!$result['taxes_enabled']) {
            // The core turns the cart line off with Enable tax, whatever the
            // stored Display tax setting holds.
            $warnings[] = 'Enable tax is off: no rate of any tax rule is charged, and the cart shows no tax line.';
        }
        if (!in_array($result['based_on'], self::BASED_ON, true)) {
            $warnings[] = 'Based on holds "' . $result['based_on'] . '", neither the invoice nor the delivery '
                . 'address: the shop falls back on its own default.';
        }
        if ($result['ecotax_id_tax_rules_group'] > 0 && $result['ecotax_tax_rule'] === null) {
            $warnings[] = 'The ecotax rule ' . $result['ecotax_id_tax_rules_group'] . ' no longer exists: choose '
                . 'another with somcp_update_tax_options.';
        }
        $result['language'] = $iso;
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }

    /**
     * The five tax options of a shop, under the names an update takes back.
     *
     * Public so the tool that changes them answers with them as this one
     * reads them, rather than with a second shape that could drift.
     *
     * @param int $idShop
     * @param int $idLang the language of the answer; a tax rule name is not
     *                    translated, so it changes nothing today
     *
     * @return array{taxes_enabled: bool, display_tax_in_cart: bool, based_on: string, use_ecotax: bool,
     *     ecotax_id_tax_rules_group: int, ecotax_tax_rule: string|null, ecotax_rate: float}
     */
    public static function options(int $idShop, int $idLang): array
    {
        // The group of the shop too, so a value set for the group of shops is
        // read as the back office of that shop shows it.
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $addressType = (string) Configuration::get('PS_TAX_ADDRESS_TYPE', null, $idShopGroup, $idShop);
        $idEcotaxRule = (int) Configuration::get('PS_ECOTAX_TAX_RULES_GROUP_ID', null, $idShopGroup, $idShop);
        $ecotaxRule = $idEcotaxRule > 0 ? SomcpToolGetTaxRule::existingRule($idEcotaxRule, $idShop) : null;

        return [
            'taxes_enabled' => (bool) Configuration::get('PS_TAX', null, $idShopGroup, $idShop),
            'display_tax_in_cart' => (bool) Configuration::get('PS_TAX_DISPLAY', null, $idShopGroup, $idShop),
            // A value that is neither (an old shop) is given as stored, never
            // guessed into one of the two.
            'based_on' => self::BASED_ON[$addressType] ?? $addressType,
            'use_ecotax' => (bool) Configuration::get('PS_USE_ECOTAX', null, $idShopGroup, $idShop),
            'ecotax_id_tax_rules_group' => $idEcotaxRule,
            'ecotax_tax_rule' => $ecotaxRule !== null ? (string) $ecotaxRule['name'] : null,
            // The same number the product tools charge on an ecotax.
            'ecotax_rate' => SomcpCatalog::ecotaxRate($idShop),
        ];
    }
}
