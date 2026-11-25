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
 * somcp_get_customer_settings - the General card of Shop Parameters >
 * Customer Settings.
 *
 * Whether a shop is B2B, asks for a birth date or offers partner offers
 * decides which fields an account has, so the assistant reads the merchant's
 * choice here before explaining a sign-up form. The keys are those the core's
 * CustomerConfiguration reads, for the shop of the token.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetCustomerSettings
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_customer_settings',
            Somcp::t('Read the customer settings'),
            'Reads Shop Parameters > Customer Settings, the General card: re-display of the cart at login, the '
                . 'e-mail after registration, the password reset delay, B2B mode, the birth date field and partner '
                . 'offers. Read only. This is where the merchant\'s '
                . 'choice of B2B mode is read. For the customer groups use somcp_list_customer_groups; for one '
                . 'customer, somcp_get_customer.',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_READ],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminCustomerPreferences')
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

        return SomcpTool::json(self::settings(Somcp::currentShopId()));
    }

    /**
     * The customer settings of a shop, under the names the update takes back.
     *
     * Public so a tool that changes them compares with, and answers with,
     * this very reading rather than a second shape that could drift.
     *
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    public static function settings(int $idShop): array
    {
        // The group of the shop too, so a value set for the group of shops is
        // read as the shop applies it.
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $get = static function (string $key) use ($idShopGroup, $idShop) {
            return Configuration::get($key, null, $idShopGroup, $idShop);
        };

        return [
            // A key with no row, as PS_CART_FOLLOWING and PS_B2B_ENABLE on a
            // fresh shop, reads as the core's adapter reads it: false and 0.
            'redisplay_cart_at_login' => (bool) $get('PS_CART_FOLLOWING'),
            'send_email_after_registration' => (bool) $get('PS_CUSTOMER_CREATION_EMAIL'),
            'password_reset_delay_minutes' => (int) $get('PS_PASSWD_TIME_FRONT'),
            'b2b_mode' => (bool) $get('PS_B2B_ENABLE'),
            'ask_for_birth_date' => (bool) $get('PS_CUSTOMER_BIRTHDATE'),
            'partner_offers' => (bool) $get('PS_CUSTOMER_OPTIN'),
        ];
    }
}
