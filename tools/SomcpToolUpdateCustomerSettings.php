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
 * somcp_update_customer_settings - the Save button of the General card of
 * Shop Parameters > Customer Settings.
 *
 * Saved by their configuration keys for the shop of the token, as the core's
 * CustomerConfiguration saves them, and B2B mode turns the page of B2B
 * outstanding amounts on or off as CustomerPreferencesFormHandler does.
 * Compared with, and answered with, the reading of
 * somcp_get_customer_settings, so the read given back whole writes nothing.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// read before and after.
require_once __DIR__ . '/SomcpToolGetCustomerSettings.php';

class SomcpToolUpdateCustomerSettings
{
    /**
     * The fields saved, in the order of the card: configuration key and kind.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const FIELDS = [
        'redisplay_cart_at_login' => ['PS_CART_FOLLOWING', 'bool'],
        'send_email_after_registration' => ['PS_CUSTOMER_CREATION_EMAIL', 'bool'],
        'password_reset_delay_minutes' => ['PS_PASSWD_TIME_FRONT', 'int'],
        'b2b_mode' => ['PS_B2B_ENABLE', 'bool'],
        'ask_for_birth_date' => ['PS_CUSTOMER_BIRTHDATE', 'bool'],
        'partner_offers' => ['PS_CUSTOMER_OPTIN', 'bool'],
    ];

    /**
     * The page of B2B outstanding amounts, shown only while B2B mode is on.
     */
    private const B2B_TAB = 'AdminOutstanding';

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $switch = static function (string $description): array {
            return ['type' => 'boolean', 'description' => $description];
        };

        return new SomcpTool(
            'somcp_update_customer_settings',
            Somcp::t('Change the customer settings'),
            'Changes Shop Parameters > Customer Settings, as the Save of its General card: re-display of the cart '
                . 'at login, the e-mail after registration, the password reset delay, B2B mode, the birth date field '
                . 'and partner offers. Only the fields given change; the answer of somcp_get_customer_settings can '
                . 'be given back. B2B mode also shows or hides the page of B2B outstanding amounts. For one customer '
                . 'use somcp_update_customer. Show the merchant the change first.',
            [
                'type' => 'object',
                'properties' => [
                    'redisplay_cart_at_login' => $switch('Re-display cart at login: a customer who logs in finds the content of their last cart.'),
                    'send_email_after_registration' => $switch('Send an email with a summary of the account information after registration.'),
                    'password_reset_delay_minutes' => ['type' => 'integer', 'description' => 'Minimum time between two requests for a password reset, in minutes, 0 or more.'],
                    'b2b_mode' => $switch('Enable B2B mode: the company fields of an account and the page of B2B outstanding amounts.'),
                    'ask_for_birth_date' => $switch('Display the birth date field.'),
                    'partner_offers' => $switch('Display the optional check box for partner offers.'),
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminCustomerPreferences')
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

        // Every problem in one answer (#169), so the call is corrected in one go.
        $problems = SomcpTool::unknownFields($arguments, array_keys(self::FIELDS));
        $given = [];
        foreach (self::FIELDS as $field => [, $kind]) {
            if (!array_key_exists($field, $arguments)) {
                continue;
            }
            $value = $arguments[$field];
            if ($kind === 'bool') {
                // Booleans only: "false" is a non-empty string PHP reads as true.
                if (!is_bool($value)) {
                    $problems[] = $field . ': true or false';
                    continue;
                }
            } else {
                if (is_string($value) && ctype_digit($value)) {
                    $value = (int) $value;
                }
                if (!is_int($value) || $value < 0) {
                    $problems[] = $field . ': a number of minutes, 0 or more';
                    continue;
                }
            }
            $given[$field] = $value;
        }
        if ($problems !== []) {
            return SomcpTool::refused('changed', $problems);
        }

        $before = SomcpToolGetCustomerSettings::settings($idShop);
        $changes = [];
        foreach ($given as $field => $value) {
            if ($value !== $before[$field]) {
                $changes[] = ['field' => $field, 'old' => $before[$field], 'new' => $value];
            }
        }
        if ($changes === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The customer settings already hold every value given. Nothing was written.',
                'settings' => $before,
            ]);
        }

        $saved = [];
        foreach ($changes as $change) {
            $field = $change['field'];
            $stored = is_bool($change['new']) ? (int) $change['new'] : $change['new'];
            if (!Configuration::updateValue(self::FIELDS[$field][0], $stored, false, $idShopGroup, $idShop)) {
                return self::failed($saved, $field . ' could not be saved', $idEmployee);
            }
            $saved[] = $field;
        }
        if (in_array('b2b_mode', $saved, true) && !self::followB2b((bool) $given['b2b_mode'])) {
            return self::failed($saved, 'the page of B2B outstanding amounts could not be ' . ($given['b2b_mode'] ? 'shown' : 'hidden'), $idEmployee);
        }
        SomcpLogger::log('customer_settings_updated', SomcpLogger::OUTCOME_SUCCESS, implode(', ', $saved), '', $idEmployee);

        $result = ['changed' => true, 'changes' => $changes];
        $warnings = [];
        if (in_array('partner_offers', $saved, true) && $given['partner_offers'] === false && version_compare(_PS_VERSION_, '9.0.0', '>=')) {
            // The note PrestaShop 9 shows above the card.
            $warnings[] = 'Make sure partner offers are not set as required in the Customers section of the back '
                . 'office before disabling them. Otherwise, new customers won\'t be able to create an account and '
                . 'proceed to checkout.';
        }
        if (in_array('send_email_after_registration', $saved, true) && $given['send_email_after_registration'] === false) {
            $warnings[] = 'New customers no longer get an e-mail with their account details.';
        }
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'The settings changed for this shop only, as the back office saves them in a shop context.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        // Read back rather than echoed: what the assistant shows is what the
        // shop stored.
        $result['settings'] = SomcpToolGetCustomerSettings::settings($idShop);

        return SomcpTool::json($result);
    }

    /**
     * Shows or hides the page of B2B outstanding amounts with B2B mode, as
     * CustomerPreferencesFormHandler::handleB2bUpdate() does.
     *
     * Through the legacy Tab object, which behaves the same on PrestaShop 8
     * and 9 without the Symfony container the core's TabRepository needs.
     *
     * @param bool $active
     *
     * @return bool false when the page exists and could not be changed
     */
    private static function followB2b(bool $active): bool
    {
        $idTab = (int) Tab::getIdFromClassName(self::B2B_TAB);
        if ($idTab <= 0) {
            // A shop without the page has nothing to follow.
            return true;
        }
        $tab = new Tab($idTab);
        if ((bool) $tab->active === $active) {
            return true;
        }
        $tab->active = $active;

        return (bool) $tab->update();
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
        SomcpLogger::log('customer_settings_update_failed', SomcpLogger::OUTCOME_FAILURE, $failure, '', $idEmployee);

        return SomcpTool::text(
            'The customer settings could not be changed: ' . $failure
                . ($saved !== [] ? ', after ' . implode(', ', $saved) . ' had been saved' : '')
                . '. Read them with somcp_get_customer_settings before calling again.',
            true
        );
    }
}
