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
 * SomcpCatalog - how the catalogue tools read a stored value the same way.
 *
 * Several tools return the same product values. Each rule lives here once, so
 * a product read with one tool never shows a different value with another.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpCatalog
{
    /**
     * A stored low stock level, or null when the merchant set none.
     *
     * The column is nullable and null means no alert; reading it as 0 would
     * tell the assistant an alert is set at zero.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    public static function threshold($value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * Whether the merchant ticked the email alert at the low stock level.
     *
     * Read the same way for a product (product_shop) and a combination
     * (product_attribute_shop), so somcp_get_product and somcp_get_stock never
     * disagree on it.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public static function lowStockAlert($value): bool
    {
        return (bool) (int) $value;
    }

    /**
     * The rate of the ecotax, in percent, in the shop's default country.
     *
     * The ecotax has its own tax rule, set in the shop's tax settings, and the
     * back office applies it with the rate of the default country, as for the
     * product price. 0 when the shop has taxes switched off or the rule has
     * nothing for that country.
     *
     * @param int $idShop
     *
     * @return float
     */
    public static function ecotaxRate(int $idShop): float
    {
        if (!Configuration::get('PS_TAX', null, null, $idShop)) {
            return 0.0;
        }
        $rates = TaxRulesGroup::getAssociatedTaxRatesByIdCountry(
            (int) Configuration::get('PS_COUNTRY_DEFAULT', null, null, $idShop)
        );

        return (float) ($rates[(int) Configuration::get('PS_ECOTAX_TAX_RULES_GROUP_ID', null, null, $idShop)] ?? 0);
    }
}
