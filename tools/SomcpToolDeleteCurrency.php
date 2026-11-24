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
 * somcp_delete_currency - one currency of International > Localization >
 * Currencies deleted, as the Delete of its row does.
 *
 * A currency added by mistake, or one the merchant stops selling in, could
 * only be removed in the back office. PrestaShop keeps the row, flagged
 * deleted, for the orders placed in it; the refusals of the back office are
 * said before the call, and so is a country that still uses the currency,
 * which the back office does not look at.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// currency is found, and answered, as somcp_get_currency reads it.
require_once __DIR__ . '/SomcpToolGetCurrency.php';
require_once __DIR__ . '/SomcpToolCreateTax.php';

class SomcpToolDeleteCurrency
{
    /**
     * The countries named in a refusal, the rest counted.
     */
    private const COUNTRIES_NAMED = 10;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_currency',
            Somcp::t('Delete a currency'),
            'Deletes one currency of International > Localization > Currencies, as the Delete of its row. Never '
                . 'the default currency of a shop, nor one a country uses as its default currency: set the country '
                . 'back with somcp_update_country first. Orders placed in it keep it. The answer holds the currency '
                . 'as it was. To stop offering a currency for a while use somcp_update_currency with active false.',
            [
                'type' => 'object',
                'properties' => [
                    'id_currency' => [
                        'type' => 'integer',
                        'description' => 'Required. The currency to delete (ID), from somcp_list_currencies.',
                    ],
                ],
                'required' => ['id_currency'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Deleting a currency is what the Delete of the currencies list
            // asks for in Team, Permissions.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminCurrencies')
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
        $idEmployee = (int) ($context['id_employee'] ?? 0);
        // The answer is read in the shop default language, as a read that
        // names no language.
        $language = SomcpTool::readLanguage([], $idShop);

        $errors = SomcpTool::unknownFields($arguments, ['id_currency']);
        if ($language === null) {
            $errors[] = SomcpTool::languageProblem($idShop);
        }
        $stored = null;
        $idCurrency = SomcpTool::id($arguments, 'id_currency');
        if ($idCurrency === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_currency');
        } elseif ($language !== null) {
            // Only a currency currency_shop ties to the shop of the token and
            // not deleted, as the list filters them.
            $stored = SomcpToolGetCurrency::read($idShop, $language, $idCurrency);
            if ($stored === null) {
                $errors[] = SomcpTool::notFound('id_currency', 'currency', $idCurrency, 'somcp_list_currencies');
            }
        }
        if ($stored !== null) {
            $iso = (string) $stored['iso_code'];
            // The refusals of the back office: every price of a shop is in
            // its default currency, so a shop without it cannot sell.
            foreach (Shop::getShops(false) as $shop) {
                $id = (int) $shop['id_shop'];
                if ((int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $id) !== $idCurrency) {
                    continue;
                }
                $errors[] = $id === $idShop
                    ? 'id_currency: ' . $iso . ' is the default currency of this shop - PrestaShop never deletes it'
                    : 'id_currency: ' . $stored['name'] . ' is the default currency for shop ' . $shop['name']
                        . ', and therefore cannot be removed';
            }
            // The back office does not look at the countries: one would keep a
            // currency the shop no longer lists, which no tool could explain.
            $countries = array_map('strval', array_column(Db::getInstance()->executeS(
                'SELECT `iso_code` FROM `' . _DB_PREFIX_ . 'country` WHERE `id_currency` = ' . (int) $idCurrency
                . ' ORDER BY `iso_code` ASC'
            ) ?: [], 'iso_code'));
            if ($countries !== []) {
                $named = array_slice($countries, 0, self::COUNTRIES_NAMED);
                $errors[] = 'id_currency: ' . $iso . ' is the default currency of ' . count($countries)
                    . ' countries (' . implode(', ', $named) . (count($countries) > count($named) ? ', ...' : '')
                    . ') - set them to the default store currency first with somcp_update_country (id_currency 0)';
            }
            // One row in currency for every shop: deleting it here would
            // remove it from all of them, the rule of somcp_delete_zone.
            $otherShops = SomcpToolCreateTax::otherShops();
            if ($otherShops > 0) {
                $errors[] = 'id_currency: a currency is shared by every shop - deleting it here would remove it from '
                    . 'the ' . $otherShops . ' other shops of this installation too';
            }
        }
        if ($errors !== [] || $stored === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        // Counted before: Currency::delete() removes these rows with the currency.
        $modules = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'module_currency` WHERE `id_currency` = ' . (int) $idCurrency
        );
        $currency = new Currency($idCurrency);
        try {
            // Removes the payment restrictions, then flags the row deleted.
            $done = $currency->delete();
            $failure = $done ? '' : 'PrestaShop refused to delete it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('currency_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'currency ' . $idCurrency . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text(
                'Nothing was deleted. The shop refused to delete currency ' . $idCurrency . ' - read it again with '
                    . 'somcp_list_currencies',
                true
            );
        }
        SomcpLogger::log('currency_deleted', SomcpLogger::OUTCOME_SUCCESS, 'currency ' . $idCurrency . ' ('
            . $stored['iso_code'] . ')', '', $idEmployee);

        $iso = (string) $stored['iso_code'];
        $warnings = [];
        if ($modules > 0) {
            $warnings[] = $modules . ' payment modules no longer accept ' . $iso . ' in Payment > Preferences; adding '
                . 'the currency again does not bring that setting back.';
        }
        // The add page reuses the deleted row of the same ISO code.
        $warnings[] = 'Orders placed in ' . $iso . ' keep it; PrestaShop keeps the currency hidden for them, and '
            . 'adding ' . $iso . ' again brings the same currency back.';

        return SomcpTool::json([
            'deleted' => true,
            'id_currency' => $idCurrency,
            'warning' => implode(' ', $warnings),
            // The currency as it was, read before the deletion.
            'currency' => $stored,
        ]);
    }
}
