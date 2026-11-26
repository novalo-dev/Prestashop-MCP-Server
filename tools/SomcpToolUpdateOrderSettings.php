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
 * somcp_update_order_settings - the Save buttons of the General and Gift
 * options cards of Shop Parameters > Order Settings.
 *
 * Saved by their configuration keys for the shop of the token, as the core's
 * GeneralConfiguration and GiftOptionsConfiguration save them, with no other
 * step. Compared with, and answered with, the reading of
 * somcp_get_order_settings, so the read given back whole writes nothing.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// read before and after, the page of the terms as the CMS tools find it, and
// the tax of the gift wrapping as the tax rule tools find it.
require_once __DIR__ . '/SomcpToolGetOrderSettings.php';
require_once __DIR__ . '/SomcpToolUpdateCmsSeo.php';
require_once __DIR__ . '/SomcpToolGetTaxRule.php';

class SomcpToolUpdateOrderSettings
{
    /**
     * The switches, keyed to their configuration key, in the order of the two cards.
     */
    private const SWITCHES = [
        'final_summary' => 'PS_FINAL_SUMMARY_ENABLED',
        'guest_checkout' => 'PS_GUEST_CHECKOUT_ENABLED',
        'disable_reordering' => 'PS_DISALLOW_HISTORY_REORDERING',
        'recalculate_shipping' => 'PS_ORDER_RECALCULATE_SHIPPING',
        'delayed_shipping' => 'PS_SHIP_WHEN_AVAILABLE',
        'terms_of_service' => 'PS_CONDITIONS',
        'backorder_status' => 'PS_ENABLE_BACKORDER_STATUS',
        'gift_wrapping' => 'PS_GIFT_WRAPPING',
        'recycled_packaging' => 'PS_RECYCLABLE_PACK',
    ];

    /**
     * The two amounts, keyed to their configuration key.
     */
    private const AMOUNTS = [
        'minimum_purchase_tax_excl' => 'PS_PURCHASE_MINIMUM',
        'gift_wrapping_price_tax_excl' => 'PS_GIFT_WRAPPING_PRICE',
    ];

    /**
     * The page of the terms and the tax of the gift wrapping, keyed to their configuration key.
     */
    private const CHOICES = [
        'terms_id_cms' => 'PS_CONDITIONS_CMS_ID',
        'gift_wrapping_id_tax_rules_group' => 'PS_GIFT_WRAPPING_TAX_RULES_GROUP',
    ];

    /**
     * The fields saved, in the order of the two cards, so the changes are
     * named as the screen reads them.
     */
    private const ORDER = [
        'final_summary',
        'guest_checkout',
        'disable_reordering',
        'minimum_purchase_tax_excl',
        'recalculate_shipping',
        'delayed_shipping',
        'terms_of_service',
        'terms_id_cms',
        'backorder_status',
        'gift_wrapping',
        'gift_wrapping_price_tax_excl',
        'gift_wrapping_id_tax_rules_group',
        'recycled_packaging',
    ];

    /**
     * What somcp_get_order_settings answers beside the settings: given back
     * as read they are no change, given otherwise they are named as the
     * shop's, not as misspelt fields.
     */
    private const COMPUTED = ['currency', 'terms_page_title', 'gift_wrapping_tax_name', 'warning'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $switch = static function (string $description): array {
            return ['type' => 'boolean', 'description' => $description];
        };

        return new SomcpTool(
            'somcp_update_order_settings',
            Somcp::t('Change the order settings'),
            'Changes Shop Parameters > Order Settings, as the Save of its General and Gift options cards: final '
                . 'summary, guest checkout, reordering, minimum purchase total (0 turns it off), shipping '
                . 'recalculation, delayed shipping, terms of service and their page (an id_cms from '
                . 'somcp_list_cms_pages, 0 for none), backorder status (PrestaShop 9), gift wrapping with its price '
                . 'and tax, recycled packaging. Only the fields given change; the answer of '
                . 'somcp_get_order_settings can be given back.',
            [
                'type' => 'object',
                'properties' => [
                    'final_summary' => $switch('Enable final summary: the summary shown before the order is placed.'),
                    'guest_checkout' => $switch('Enable guest checkout: customers may order without an account.'),
                    'disable_reordering' => $switch('Disable reordering option in the order history of a customer.'),
                    'minimum_purchase_tax_excl' => [
                        'type' => 'number',
                        'description' => 'Minimum purchase total required in order to validate the order, tax '
                            . 'excluded, in the default currency, 0 or more. 0 turns it off.',
                    ],
                    'recalculate_shipping' => $switch('Recalculate shipping costs after editing the order.'),
                    'delayed_shipping' => $switch('Delayed shipping: customers may have the products in stock shipped first.'),
                    'terms_of_service' => $switch('Terms of service: customers accept them before they order.'),
                    'terms_id_cms' => [
                        'type' => 'integer',
                        'description' => 'Page for terms and conditions, by its id_cms from somcp_list_cms_pages. '
                            . '0 for None.',
                    ],
                    'backorder_status' => $switch('Set backorder status. PrestaShop 9 only.'),
                    'gift_wrapping' => $switch('Offer gift wrapping.'),
                    'gift_wrapping_price_tax_excl' => [
                        'type' => 'number',
                        'description' => 'Gift-wrapping price, tax excluded, in the default currency, 0 or more.',
                    ],
                    'gift_wrapping_id_tax_rules_group' => [
                        'type' => 'integer',
                        'description' => 'Gift-wrapping tax, by its id_tax_rules_group from somcp_list_tax_rules. '
                            . '0 for None.',
                    ],
                    'recycled_packaging' => $switch('Offer recycled packaging.'),
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminOrderPreferences')
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
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $idEmployee = (int) ($context['id_employee'] ?? 0);
        $nine = version_compare(_PS_VERSION_, '9.0.0', '>=');
        $before = SomcpToolGetOrderSettings::settings($idShop);

        // What the read worked out, given back as it reads, is no change and
        // is taken out, so a read given back whole is not refused for it.
        $checked = $arguments;
        foreach (self::COMPUTED as $key) {
            if (!array_key_exists($key, $checked)) {
                continue;
            }
            if ($key === 'warning' || json_encode($checked[$key]) === json_encode($before[$key])) {
                unset($checked[$key]);
            }
        }

        // Every problem in one answer (#169), so the call is corrected in one go.
        $problems = SomcpTool::unknownFields($checked, self::ORDER, self::COMPUTED);
        $currency = (string) $before['currency'];

        $given = [];
        foreach (array_keys(self::SWITCHES) as $field) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            if ($field === 'backorder_status' && !$nine) {
                // The setting exists from PrestaShop 9 only: the null of the
                // read is skipped above, a value is named rather than dropped.
                $problems[] = SomcpTool::notOnThisVersion('backorder_status', 'Set backorder status');
                continue;
            }
            if (!is_bool($arguments[$field])) {
                $problems[] = $field . ': true or false';
                continue;
            }
            $given[$field] = $arguments[$field];
        }

        foreach (array_keys(self::AMOUNTS) as $field) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            $value = $arguments[$field];
            // The check of the form type: a price.
            if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0
                && Validate::isPrice((string) $value)) {
                $given[$field] = (float) $value;
            } else {
                $problems[] = $field . ': an amount of 0 or more, tax excluded, in ' . $currency;
            }
        }

        $choices = [
            'terms_id_cms' => ['CMS page', 'somcp_list_cms_pages'],
            'gift_wrapping_id_tax_rules_group' => ['tax rule', 'somcp_list_tax_rules'],
        ];
        foreach ($choices as $field => [$thing, $tool]) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            $value = $arguments[$field];
            if ($value === 0 || $value === '0') {
                // None, the first choice of the select.
                $given[$field] = 0;
                continue;
            }
            $id = SomcpTool::id($arguments, $field);
            if ($id === 0) {
                $problems[] = SomcpTool::notAnIdentifier($field);
                continue;
            }
            // Found as the read finds them, so a page or a rule of another
            // shop is one that does not exist here.
            $exists = $field === 'terms_id_cms'
                ? SomcpToolUpdateCmsSeo::page($id, $idShop) !== null
                : SomcpToolGetTaxRule::existingRule($id, $idShop) !== null;
            if ($exists) {
                $given[$field] = $id;
            } else {
                $problems[] = SomcpTool::notFound($field, $thing, $id, $tool);
            }
        }

        if ($problems !== []) {
            return SomcpTool::refused('changed', $problems);
        }

        $keys = self::SWITCHES + self::AMOUNTS + self::CHOICES;
        $changes = [];
        foreach (self::ORDER as $field) {
            if (!array_key_exists($field, $given)) {
                continue;
            }
            // An amount read back from JSON may come as 2 or 2.0: compared as a number.
            $same = isset(self::AMOUNTS[$field])
                ? abs($given[$field] - (float) $before[$field]) < 0.0000005
                : $given[$field] === $before[$field];
            if (!$same) {
                $changes[] = ['field' => $field, 'old' => $before[$field], 'new' => $given[$field]];
            }
        }
        if ($changes === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The order settings already hold every value given. Nothing was written.',
                'settings' => $before,
            ]);
        }

        $saved = [];
        foreach ($changes as $change) {
            $field = $change['field'];
            // Stored as the core's resolvers cast them: 0 or 1, a float, an integer.
            $value = is_bool($given[$field]) ? (int) $given[$field] : $given[$field];
            if (!Configuration::updateValue($keys[$field], $value, false, $idShopGroup, $idShop)) {
                return self::failed($saved, $field . ' could not be saved', $idEmployee);
            }
            $saved[] = $field;
        }
        SomcpLogger::log('order_settings_updated', SomcpLogger::OUTCOME_SUCCESS, implode(', ', $saved), '', $idEmployee);

        // Read back rather than echoed: what the assistant shows is what the
        // shop stored.
        $after = SomcpToolGetOrderSettings::settings($idShop);
        $result = ['changed' => true, 'changes' => $changes];

        $warnings = [];
        if ($nine && in_array('gift_wrapping', $saved, true)) {
            // The help of the screen on PrestaShop 9: the e-mails show the
            // gift wrapping only once their templates are generated again.
            $warnings[] = 'Remember to regenerate email templates in Design > Email theme after enabling or '
                . 'disabling this feature.';
        }
        if ($after['terms_of_service'] && $after['terms_id_cms'] === 0) {
            $warnings[] = 'Terms of service are required but no page is chosen: customers are asked to accept a '
                . 'page that does not exist.';
        }
        if (in_array('guest_checkout', $saved, true) && $given['guest_checkout'] === false) {
            $warnings[] = 'Visitors must now create an account before they order.';
        }
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'The settings changed for this shop only, as the back office saves them in a shop context.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        $result['settings'] = $after;

        return SomcpTool::json($result);
    }

    /**
     * The answer to a save that stopped half way, naming what was saved so
     * the assistant reads the settings before trying again.
     *
     * @param array<int, string> $saved
     * @param string $failure
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function failed(array $saved, string $failure, int $idEmployee): array
    {
        SomcpLogger::log('order_settings_update_failed', SomcpLogger::OUTCOME_FAILURE, $failure, '', $idEmployee);

        return SomcpTool::text(
            'The order settings could not be changed: ' . $failure
                . ($saved !== [] ? ', after ' . implode(', ', $saved) . ' had been saved' : '')
                . '. Read them with somcp_get_order_settings before calling again.',
            true
        );
    }
}
