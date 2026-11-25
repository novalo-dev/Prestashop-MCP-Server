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
 * somcp_get_order_settings - the General and Gift options cards of Shop
 * Parameters > Order Settings.
 *
 * What a customer may do at the checkout (order as a guest, below which total,
 * with which terms) is set here; this read lets the assistant check it before
 * explaining a refused order. The keys are those the core's
 * GeneralConfiguration and GiftOptionsConfiguration read, for the shop of the
 * token.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// page of the terms is found as the CMS tools find it, and the tax of the
// gift wrapping as the tax rule tools find it.
require_once __DIR__ . '/SomcpToolUpdateCmsSeo.php';
require_once __DIR__ . '/SomcpToolGetTaxRule.php';

class SomcpToolGetOrderSettings
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_order_settings',
            Somcp::t('Read the order settings'),
            'Reads Shop Parameters > Order Settings, the General and Gift options cards: final summary, guest '
                . 'checkout, reordering, minimum purchase total, shipping recalculation, delayed shipping, terms of '
                . 'service and their page, backorder status (PrestaShop 9), gift wrapping with its price and tax, '
                . 'and recycled packaging. Read only. For the order statuses use somcp_list_order_statuses; for '
                . 'shipping costs, somcp_get_shipping_preferences.',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_READ],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminOrderPreferences')
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
        $problems = SomcpTool::unknownFields($arguments, []);
        if ($problems !== []) {
            return SomcpTool::refused('read', $problems);
        }

        $result = self::settings(Somcp::currentShopId());
        $warnings = [];
        if ($result['terms_id_cms'] > 0 && $result['terms_page_title'] === null) {
            // The update tool is not shipped yet: the screen is named instead.
            $warnings[] = 'The page for terms and conditions no longer exists: choose another in Shop Parameters > '
                . 'Order Settings.';
        }
        if ($result['terms_of_service'] && $result['terms_id_cms'] === 0) {
            $warnings[] = 'Terms of service are required but no page is chosen: customers are asked to accept a '
                . 'page that does not exist.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }

    /**
     * The order settings of a shop, under the names the update takes back.
     *
     * Public so a tool that changes them compares with, and answers with,
     * this very reading rather than a second shape that could drift.
     * PS_ALLOW_MULTISHIPPING, which PrestaShop 8 reads too, is left out as the
     * form leaves it out.
     *
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    public static function settings(int $idShop): array
    {
        // The group of the shop too, so a value set for the group of shops is
        // read as the checkout of that shop applies it.
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $get = static function (string $key) use ($idShopGroup, $idShop) {
            return Configuration::get($key, null, $idShopGroup, $idShop);
        };
        $idLang = (int) $get('PS_LANG_DEFAULT');

        $idCms = (int) $get('PS_CONDITIONS_CMS_ID');
        $title = null;
        if ($idCms > 0) {
            // Found as the CMS tools find a page, so a page of another shop is
            // one that no longer exists here.
            $cms = SomcpToolUpdateCmsSeo::page($idCms, $idShop);
            if ($cms !== null) {
                $titles = $cms->meta_title;
                $title = (string) (is_array($titles) ? ($titles[$idLang] ?? reset($titles)) : $titles);
            }
        }

        $idTax = (int) $get('PS_GIFT_WRAPPING_TAX_RULES_GROUP');
        $tax = $idTax > 0 ? SomcpToolGetTaxRule::existingRule($idTax, $idShop) : null;

        return [
            // A key with no row, as on a fresh shop, reads as the core's
            // adapters default it: false and 0.
            'final_summary' => (bool) $get('PS_FINAL_SUMMARY_ENABLED'),
            'guest_checkout' => (bool) $get('PS_GUEST_CHECKOUT_ENABLED'),
            'disable_reordering' => (bool) $get('PS_DISALLOW_HISTORY_REORDERING'),
            'minimum_purchase_tax_excl' => (float) $get('PS_PURCHASE_MINIMUM'),
            'currency' => (string) Currency::getIsoCodeById((int) $get('PS_CURRENCY_DEFAULT')),
            'recalculate_shipping' => (bool) $get('PS_ORDER_RECALCULATE_SHIPPING'),
            'delayed_shipping' => (bool) $get('PS_SHIP_WHEN_AVAILABLE'),
            'terms_of_service' => (bool) $get('PS_CONDITIONS'),
            'terms_id_cms' => $idCms,
            'terms_page_title' => $title,
            // The setting exists from PrestaShop 9 only.
            'backorder_status' => version_compare(_PS_VERSION_, '9.0.0', '>=') ? (bool) $get('PS_ENABLE_BACKORDER_STATUS') : null,
            'gift_wrapping' => (bool) $get('PS_GIFT_WRAPPING'),
            'gift_wrapping_price_tax_excl' => (float) $get('PS_GIFT_WRAPPING_PRICE'),
            'gift_wrapping_id_tax_rules_group' => $idTax,
            'gift_wrapping_tax_name' => $tax !== null ? (string) $tax['name'] : null,
            'recycled_packaging' => (bool) $get('PS_RECYCLABLE_PACK'),
        ];
    }
}
