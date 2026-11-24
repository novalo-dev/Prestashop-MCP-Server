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
 * somcp_get_localization_settings - the settings of International >
 * Localization > Localization: the Configuration card (default language,
 * country and currency, browser detection, time zone), Local units and
 * Advanced.
 *
 * No other tool says which language, country or currency a shop starts from,
 * nor the units every product weight and carrier range is read in. Read by
 * their configuration keys for the shop of the token, as the tax options are.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetLocalizationSettings
{
    /**
     * @var array<string, string> Each setting, under the name an update takes
     *                            back, and its configuration key, in the order
     *                            of the cards of the page
     */
    public const KEYS = [
        'default_id_lang' => 'PS_LANG_DEFAULT',
        'detect_language_from_browser' => 'PS_DETECT_LANG',
        'default_id_country' => 'PS_COUNTRY_DEFAULT',
        'detect_country_from_browser' => 'PS_DETECT_COUNTRY',
        'default_id_currency' => 'PS_CURRENCY_DEFAULT',
        'timezone' => 'PS_TIMEZONE',
        'weight_unit' => 'PS_WEIGHT_UNIT',
        'distance_unit' => 'PS_DISTANCE_UNIT',
        'volume_unit' => 'PS_VOLUME_UNIT',
        'dimension_unit' => 'PS_DIMENSION_UNIT',
        'language_identifier' => 'PS_LOCALE_LANGUAGE',
        'country_identifier' => 'PS_LOCALE_COUNTRY',
    ];

    /** @var array<int, string> The switches of the Configuration card. */
    private const BOOLEANS = ['detect_language_from_browser', 'detect_country_from_browser'];

    /** @var array<int, string> The identifiers, given as the somcp_list_* tools give them. */
    private const IDENTIFIERS = ['default_id_lang', 'default_id_country', 'default_id_currency'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_localization_settings',
            Somcp::t('Read the localization settings'),
            'Reads International > Localization > Localization: the default language, country and currency '
                . '(their ID with ISO code and name), browser detection, the time zone, the weight, distance, '
                . 'volume and dimension units every product and carrier range is read in, and the server '
                . 'language and country identifiers. For the lists of languages, countries or currencies use '
                . 'somcp_list_languages, somcp_list_countries, somcp_list_currencies. To change them, '
                . 'somcp_update_localization_settings.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, for the '
                            . 'names of the default country and currency; a code the shop does not have is refused. '
                            . 'The shop default language when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // The page a merchant opens to look at these settings.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminLocalization')
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

        $errors = SomcpTool::unknownFields($arguments, ['language']);
        if ($language === null) {
            $errors[] = SomcpTool::languageProblem($idShop);
        }
        if ($errors !== [] || $language === null) {
            return SomcpTool::refused('read', $errors);
        }

        return SomcpTool::json([
            'language' => $language[1],
            'localization' => self::settings($idShop, $language[0]),
        ]);
    }

    /**
     * The localization settings of a shop, under the names an update takes back.
     *
     * Public so the tool that changes them compares with, and answers with,
     * this very reading rather than a second shape that could drift.
     *
     * @param int $idShop
     * @param int $idLang the language of the names of the country and the currency
     *
     * @return array<string, mixed>
     */
    public static function settings(int $idShop, int $idLang): array
    {
        // The group of the shop too, so a value set for the group of shops is
        // read as the storefront of that shop applies it.
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);

        $settings = [];
        foreach (self::KEYS as $name => $key) {
            $value = Configuration::get($key, null, $idShopGroup, $idShop);
            if (in_array($name, self::BOOLEANS, true)) {
                $settings[$name] = (bool) $value;
            } elseif (in_array($name, self::IDENTIFIERS, true)) {
                $settings[$name] = (int) $value;
            } else {
                $settings[$name] = (string) $value;
            }
        }

        // The names beside the identifiers, so the answer reads without a
        // second call; computed, an update recognises them given back.
        $settings = self::insertAfter($settings, 'default_id_lang', 'default_language', self::language($settings['default_id_lang']));
        $settings = self::insertAfter($settings, 'default_id_country', 'default_country', self::country($settings['default_id_country'], $idLang));

        return self::insertAfter($settings, 'default_id_currency', 'default_currency', self::currency($settings['default_id_currency'], $idLang));
    }

    /**
     * @param array<string, mixed> $settings
     * @param string $after
     * @param string $name
     * @param mixed $value
     *
     * @return array<string, mixed>
     */
    private static function insertAfter(array $settings, string $after, string $name, $value): array
    {
        $result = [];
        foreach ($settings as $key => $item) {
            $result[$key] = $item;
            if ($key === $after) {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * @param int $idLang
     *
     * @return array{iso_code: string, name: string}|null null when the setting names no language the shop has
     */
    private static function language(int $idLang): ?array
    {
        $language = new Language($idLang);
        if (!Validate::isLoadedObject($language)) {
            return null;
        }

        return ['iso_code' => (string) $language->iso_code, 'name' => (string) $language->name];
    }

    /**
     * @param int $idCountry
     * @param int $idLang
     *
     * @return array{iso_code: string, name: string}|null
     */
    private static function country(int $idCountry, int $idLang): ?array
    {
        $country = new Country($idCountry);
        if (!Validate::isLoadedObject($country)) {
            return null;
        }

        return ['iso_code' => (string) $country->iso_code, 'name' => (string) Country::getNameById($idLang, $idCountry)];
    }

    /**
     * @param int $idCurrency
     * @param int $idLang
     *
     * @return array{iso_code: string, name: string}|null
     */
    private static function currency(int $idCurrency, int $idLang): ?array
    {
        $currency = new Currency($idCurrency, $idLang);
        if (!Validate::isLoadedObject($currency)) {
            return null;
        }
        $name = is_array($currency->name) ? (string) ($currency->name[$idLang] ?? '') : (string) $currency->name;

        return ['iso_code' => (string) $currency->iso_code, 'name' => $name];
    }
}
