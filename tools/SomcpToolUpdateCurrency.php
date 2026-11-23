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
 * somcp_update_currency - one currency of International > Localization >
 * Currencies changed, as its Edit page does for Exchange rate, Decimals and
 * Status, or as the Enabled switch of the list does.
 *
 * A shop that sells in a second currency has its rate typed once and never
 * updated, and a currency could only be switched on or off in the back
 * office. Only the fields given and different from the stored currency are
 * written, so a row of somcp_get_currency given back whole changes nothing.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// currency is compared with, and read back as, the answer of the read; the
// numbers are read as the other tools read them.
require_once __DIR__ . '/SomcpToolGetCurrency.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolCreateTax.php';
require_once __DIR__ . '/SomcpToolIssuePartialRefund.php';

class SomcpToolUpdateCurrency
{
    /**
     * The fields of somcp_get_currency this tool does not write, taken only as the read gives them.
     */
    private const COMPUTED = ['language', 'name', 'symbol', 'iso_code', 'default', 'numeric_iso_code', 'alternative',
        'pattern', 'modified'];

    /**
     * The fields of the read the names, symbols and formats part will write.
     */
    private const LATER = ['name', 'symbol', 'pattern'];

    /**
     * The largest rate currency_shop.conversion_rate holds, a decimal(13,6).
     */
    private const MAX_RATE = 9999999.999999;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $computed = ['description' => 'Worked out by the shop: taken only as somcp_get_currency gives it, so a row '
            . 'of that read can be given back whole.'];

        return new SomcpTool(
            'somcp_update_currency',
            Somcp::t('Change a currency'),
            'Changes one currency of International > Localization > Currencies, as its Edit page or the Enabled '
                . 'switch of the list: Exchange rate, Decimals and Status. Take id_currency from '
                . 'somcp_list_currencies; a row of somcp_get_currency can be given back whole, and only what '
                . 'differs is written. The default currency keeps rate 1 and stays enabled. For the names and '
                . 'symbols, a later tool; for the currency of a country, somcp_update_country.',
            [
                'type' => 'object',
                'properties' => [
                    'id_currency' => [
                        'type' => 'integer',
                        'description' => 'Required. The currency to change (ID), from somcp_list_currencies.',
                    ],
                    'conversion_rate' => [
                        'type' => 'number',
                        'description' => 'Merchant. Exchange rate of this shop: the value of 1 unit of the default '
                            . 'currency in this one, above 0, 6 decimals kept. Left out, kept.',
                    ],
                    'precision' => [
                        'type' => 'integer',
                        'description' => 'Merchant. Decimals: the number of decimals prices show, 0 or more. Left '
                            . 'out, kept.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Status: true offers the currency, false stops offering it. Left '
                            . 'out, kept.',
                    ],
                    'language' => ['type' => 'string'] + $computed,
                    'name' => ['type' => 'string'] + $computed,
                    'symbol' => ['type' => 'string'] + $computed,
                    'iso_code' => ['type' => 'string'] + $computed,
                    'default' => ['type' => 'boolean'] + $computed,
                    'numeric_iso_code' => ['type' => ['string', 'null']] + $computed,
                    'alternative' => ['type' => 'boolean'] + $computed,
                    'pattern' => ['type' => ['string', 'null']] + $computed,
                    'modified' => ['type' => 'boolean'] + $computed,
                ],
                'required' => ['id_currency'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Editing a currency is what its Edit page and the Enabled switch
            // ask for in Team, Permissions; adding one is a different right.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminCurrencies')
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

        $errors = SomcpTool::unknownFields(
            $arguments,
            array_merge(['id_currency', 'conversion_rate', 'precision', 'active'], self::COMPUTED)
        );
        // The read given back may have been made in any language of the shop:
        // it is compared in that language, so its name and symbol match.
        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            $errors[] = 'language: worked out by the shop - one of the shop\'s active languages, as the read gave it';
        }
        $stored = null;
        $idCurrency = SomcpTool::id($arguments, 'id_currency');
        if ($idCurrency === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_currency');
        } elseif ($language !== null) {
            $stored = SomcpToolGetCurrency::read($idShop, $language, $idCurrency);
            if ($stored === null) {
                $errors[] = SomcpTool::notFound('id_currency', 'currency', $idCurrency, 'somcp_list_currencies');
            }
        }

        $idDefault = SomcpToolListCurrencies::defaultCurrency($idShop);
        $isDefault = $stored !== null && $idCurrency === $idDefault;
        $changes = [];

        if (array_key_exists('conversion_rate', $arguments) && $arguments['conversion_rate'] !== null) {
            $rate = SomcpToolIssuePartialRefund::number($arguments['conversion_rate']);
            // Six decimals, what the column keeps: a rate compared before the
            // rounding would claim a change the shop then does not store.
            $rate = $rate !== null ? round($rate, 6) : null;
            if ($rate === null || $rate <= 0 || $rate > self::MAX_RATE) {
                $errors[] = 'conversion_rate: a number above 0, the value of 1 ' . self::isoCode($idDefault)
                    . ' in this currency';
            } elseif ($stored !== null && $rate !== round((float) $stored['conversion_rate'], 6)) {
                if ($isDefault) {
                    // Every rate is relative to the default currency and the
                    // shop converts it at 1 whatever is stored: a rate written
                    // there would show in the list and change nothing.
                    $errors[] = 'conversion_rate: ' . $stored['iso_code'] . ' is the default currency of this shop - '
                        . 'every rate is relative to it, its rate stays 1';
                } else {
                    self::record($changes, 'conversion_rate', (float) $stored['conversion_rate'], $rate);
                }
            }
        }

        if (array_key_exists('precision', $arguments) && $arguments['precision'] !== null) {
            $precision = SomcpToolCreateProduct::integer($arguments['precision']);
            if ($precision === null || $precision < 0 || $precision > 99) {
                $errors[] = 'precision: a whole number of 0 or more';
            } elseif ($stored !== null && $precision !== $stored['precision']) {
                self::record($changes, 'precision', $stored['precision'], $precision);
            }
        }

        if (array_key_exists('active', $arguments) && $arguments['active'] !== null) {
            // A boolean only: "false" cast to bool is true, and would keep
            // offering a currency the merchant wanted switched off.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } elseif ($stored !== null && $arguments['active'] !== $stored['active']) {
                if ($arguments['active'] === false) {
                    // The default currency of any shop, as the back office
                    // refuses it: a shop cannot sell in a currency it does not offer.
                    foreach (self::defaultOf($idCurrency) as $shopName) {
                        $errors[] = 'active: ' . $stored['name'] . ' is the default currency for shop ' . $shopName
                            . ', and therefore cannot be disabled';
                    }
                }
                self::record($changes, 'active', $stored['active'], $arguments['active']);
            }
        }

        self::givenBack($arguments, $stored, $errors);
        if (!array_key_exists('conversion_rate', $arguments) && !array_key_exists('precision', $arguments)
            && !array_key_exists('active', $arguments)) {
            $errors[] = 'conversion_rate, precision or active: give at least one field to change';
        }
        if ($errors !== [] || $stored === null) {
            return SomcpTool::refused('changed', $errors);
        }

        if ($changes === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_currency' => $idCurrency,
                'reason' => 'The currency already holds every value given. Nothing was written.',
                'currency' => $stored,
            ]);
        }
        $fields = array_column($changes, 'field');
        $new = array_column($changes, 'new', 'field');

        // Loaded for the shop of the token, so the rate read and written is
        // the one of its currency_shop row only.
        $currency = new Currency($idCurrency, null, $idShop);
        $currency->id_shop_list = [$idShop];
        // The constructor turns the names, symbols and formats into the
        // string of one language; put back in every language, or update()
        // would write that one string in all of them.
        $currency->setLocalizedNames($currency->getLocalizedNames());
        $currency->setLocalizedSymbols($currency->getLocalizedSymbols());
        $currency->setLocalizedPatterns($currency->getLocalizedPatterns());
        if (isset($new['conversion_rate'])) {
            $currency->conversion_rate = (float) $new['conversion_rate'];
        }
        if (isset($new['precision'])) {
            $currency->precision = (int) $new['precision'];
        }
        if (isset($new['active'])) {
            $currency->active = (bool) $new['active'];
        }
        try {
            // Null values kept as null, as the back office saves a currency:
            // an empty numeric ISO code or format stays empty, not "".
            $done = $currency->update(true);
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('currency_update_failed', SomcpLogger::OUTCOME_FAILURE, 'currency ' . $idCurrency . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text(
                'The currency could not be changed: ' . $failure . '. Read it with somcp_get_currency before calling '
                    . 'again.',
                true
            );
        }
        SomcpLogger::log('currency_updated', SomcpLogger::OUTCOME_SUCCESS, 'currency ' . $idCurrency . ': '
            . implode(', ', $fields), '', $idEmployee);
        // The shape of every update tool: changed a boolean, changes what was
        // written with its old value.
        $result = ['changed' => true, 'id_currency' => $idCurrency, 'changes' => $changes];

        $warnings = [];
        $iso = (string) $stored['iso_code'];
        if (isset($new['conversion_rate'])) {
            // orders.conversion_rate is stored with each order.
            $warnings[] = 'Prices in ' . $iso . ' are now converted at ' . $new['conversion_rate'] . '; orders '
                . 'already placed keep the rate they were placed at.';
        }
        if (isset($new['active']) && $new['active'] === false) {
            $modules = self::paymentModules($idCurrency, $idShop);
            if ($modules > 0) {
                $warnings[] = $modules . ' payment modules accept ' . $iso . ' in Payment > Preferences; they keep '
                    . 'that setting but the currency is no longer offered.';
            }
        }
        // Decimals and Status live in the currency row, shared by every shop;
        // the rate has a row per shop.
        $otherShops = SomcpToolCreateTax::otherShops();
        if ($otherShops > 0 && (isset($new['precision']) || isset($new['active']))) {
            $result['also_in_other_shops'] = $otherShops;
            $warnings[] = 'Decimals and Status are shared by every shop: this change reaches every shop of this '
                . 'installation (' . ($otherShops + 1) . ').';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $result['currency'] = SomcpToolGetCurrency::read($idShop, $language, $idCurrency);

        return SomcpTool::json($result);
    }

    /**
     * Refuses a field of somcp_get_currency this tool does not write, unless it is given back as read.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $stored the currency as somcp_get_currency reads it, in the language given
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function givenBack(array $arguments, ?array $stored, array &$errors): void
    {
        foreach (self::COMPUTED as $field) {
            // The language is checked with the read it decides.
            if ($field === 'language' || !array_key_exists($field, $arguments) || $stored === null) {
                continue;
            }
            if ($arguments[$field] === $stored[$field]) {
                continue;
            }
            $errors[] = $field . (in_array($field, self::LATER, true)
                ? ': changed by somcp_update_currency part 3, not written yet'
                : ': worked out by the shop');
        }
    }

    /**
     * The names of the shops whose default currency this is, the shop of the token included.
     *
     * @param int $idCurrency
     *
     * @return array<int, string>
     */
    private static function defaultOf(int $idCurrency): array
    {
        $names = [];
        foreach (Shop::getShops(false) as $shop) {
            if ((int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, (int) $shop['id_shop']) === $idCurrency) {
                $names[] = (string) $shop['name'];
            }
        }

        return $names;
    }

    /**
     * The number of payment modules that accept the currency in the shop (Payment > Preferences).
     *
     * @param int $idCurrency
     * @param int $idShop
     *
     * @return int
     */
    private static function paymentModules(int $idCurrency, int $idShop): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'module_currency` WHERE `id_currency` = ' . (int) $idCurrency
            . ' AND `id_shop` = ' . (int) $idShop
        );
    }

    /**
     * The ISO code of a currency, for the messages, or "default currency" when it has none.
     *
     * @param int $idCurrency
     *
     * @return string
     */
    private static function isoCode(int $idCurrency): string
    {
        $iso = $idCurrency > 0 ? (string) Currency::getIsoCodeById($idCurrency) : '';

        return $iso !== '' ? $iso : 'default currency';
    }

    /**
     * One entry of changes, as the other update tools record it.
     *
     * @param array<int, array<string, mixed>> $changes
     * @param string $field
     * @param mixed $old
     * @param mixed $new
     *
     * @return void
     */
    private static function record(array &$changes, string $field, $old, $new): void
    {
        $changes[] = ['field' => $field, 'old' => $old, 'new' => $new];
    }
}
