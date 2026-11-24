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
 * somcp_update_localization_settings - the Save buttons of the Configuration,
 * Local units and Advanced cards of International > Localization >
 * Localization.
 *
 * Saved by their configuration keys for the shop of the token, the keys the
 * core's LocalizationConfiguration, LocalUnitsConfiguration and
 * AdvancedConfiguration write, with the choices their forms offer: enabled
 * languages, any country, the time zones of the timezone table. A new
 * default currency is set as the core sets it: every exchange rate reset and
 * read again from PrestaShop's feed, fetched before anything is written.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// feed is fetched and the rates compared as somcp_update_exchange_rates does.
require_once __DIR__ . '/SomcpToolListCurrencies.php';
require_once __DIR__ . '/SomcpToolUpdateExchangeRates.php';

class SomcpToolUpdateLocalizationSettings
{
    /**
     * What somcp_get_localization_settings answers beside the settings: given
     * back as read they are no change, given otherwise they are named as the
     * shop's, not as misspelt fields.
     */
    private const COMPUTED = ['default_language', 'default_country', 'default_currency', 'language'];

    /** @var array<int, string> The switches of the Configuration card. */
    private const SWITCHES = ['detect_language_from_browser', 'detect_country_from_browser'];

    /** @var array<int, string> The four texts of Local units. */
    private const UNITS = ['weight_unit', 'distance_unit', 'volume_unit', 'dimension_unit'];

    /**
     * @var int The units the help of the page names are 2 or 3 letters: a
     *          longer text is a sentence typed in the wrong place
     */
    private const UNIT_MAX = 10;

    /** @var array<string, string> The two identifiers of Advanced and what the help asks for. */
    private const IDENTIFIERS = [
        'language_identifier' => 'two letters, ISO 639-1, for example en',
        'country_identifier' => 'two letters, ISO 3166-1 alpha-2 in lowercase, for example us',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $boolean = static function (string $description): array {
            return ['type' => 'boolean', 'description' => $description];
        };
        $string = static function (string $description): array {
            return ['type' => 'string', 'description' => $description];
        };

        return new SomcpTool(
            'somcp_update_localization_settings',
            Somcp::t('Change the localization settings'),
            'Changes International > Localization > Localization, as its Save buttons: the default language and '
                . 'country, browser detection, the time zone, the weight, distance, volume and dimension units and '
                . 'the server identifiers. Only the fields given change; the answer of '
                . 'somcp_get_localization_settings can be given back. Units are labels: values already typed are '
                . 'not converted. A new default currency resets every exchange rate from PrestaShop\'s server (up '
                . 'to ' . SomcpToolUpdateExchangeRates::TIMEOUT . ' seconds) and converts no price. To enable a '
                . 'language or a country, somcp_update_language or somcp_update_country.',
            [
                'type' => 'object',
                'properties' => [
                    'default_id_lang' => [
                        'type' => 'integer',
                        'description' => 'Default language: an enabled language (ID), from somcp_list_languages.',
                    ],
                    'detect_language_from_browser' => $boolean('Set language from browser.'),
                    'default_id_country' => [
                        'type' => 'integer',
                        'description' => 'Default country (ID), from somcp_list_countries.',
                    ],
                    'detect_country_from_browser' => $boolean('Set default country from browser language.'),
                    'default_id_currency' => [
                        'type' => 'integer',
                        'description' => 'Default currency: an enabled currency (ID), from somcp_list_currencies.',
                    ],
                    'timezone' => $string('Time zone, a name of the list, for example Europe/Paris.'),
                    'weight_unit' => $string('Weight unit, for example kg.'),
                    'distance_unit' => $string('Distance unit, for example km.'),
                    'volume_unit' => $string('Volume unit, for example L.'),
                    'dimension_unit' => $string('Dimension unit, for example cm.'),
                    'language_identifier' => $string('Language identifier of the server, ISO 639-1, for example en.'),
                    'country_identifier' => $string('Country identifier of the server, ISO 3166-1 alpha-2, for example us.'),
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminLocalization')
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
        $language = SomcpTool::readLanguage([], $idShop);
        $idLang = $language !== null ? $language[0] : 0;
        $stored = SomcpToolGetLocalizationSettings::settings($idShop, $idLang);

        // What the read worked out, given back as it reads, is no change and
        // is taken out, so a read given back whole is not refused for it.
        $read = $stored + ['language' => $language !== null ? $language[1] : ''];
        $checked = $arguments;
        foreach (self::COMPUTED as $key) {
            if (!array_key_exists($key, $checked)) {
                continue;
            }
            $value = $key === 'language' && is_string($checked[$key]) ? Tools::strtolower($checked[$key]) : $checked[$key];
            if (json_encode($value) === json_encode($read[$key])) {
                unset($checked[$key]);
            }
        }

        // Every problem in one answer (#169), so the call is corrected in one go.
        $problems = SomcpTool::unknownFields($checked, array_keys(SomcpToolGetLocalizationSettings::KEYS), self::COMPUTED);
        $given = [];
        self::checkLanguage($arguments, $idShop, $given, $problems);
        self::checkCountry($arguments, $given, $problems);
        self::checkCurrency($arguments, (int) $stored['default_id_currency'], $idShop, $idLang, $given, $problems);
        foreach (self::SWITCHES as $field) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            if (is_bool($arguments[$field])) {
                $given[$field] = $arguments[$field];
            } else {
                $problems[] = $field . ': true or false';
            }
        }
        if (array_key_exists('timezone', $arguments) && $arguments['timezone'] !== null) {
            if (is_string($arguments['timezone']) && self::isTimezone($arguments['timezone'])) {
                $given['timezone'] = $arguments['timezone'];
            } else {
                $problems[] = 'timezone: a time zone name of the list, for example Europe/Paris';
            }
        }
        self::checkTexts($arguments, $given, $problems);

        if ($problems !== []) {
            return SomcpTool::refused('changed', $problems);
        }

        // The keys whose value differs, in the order of the cards of the page.
        $plan = [];
        foreach (SomcpToolGetLocalizationSettings::KEYS as $field => $key) {
            if (array_key_exists($field, $given) && $given[$field] !== $stored[$field]) {
                $plan[] = ['field' => $field, 'key' => $key, 'old' => $stored[$field], 'new' => $given[$field]];
            }
        }
        if ($plan === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The localization settings already hold every value given. Nothing was written.',
                'localization' => $stored,
            ]);
        }

        $currency = null;
        foreach ($plan as $change) {
            if ($change['field'] === 'default_id_currency') {
                $currency = self::prepareCurrency($idShop, (int) $change['old'], (int) $change['new'], $idEmployee);
                if (isset($currency['content'])) {
                    return $currency;
                }
            }
        }

        $saved = [];
        foreach ($plan as $change) {
            $value = is_bool($change['new']) ? (int) $change['new'] : $change['new'];
            if (!Configuration::updateValue($change['key'], $value, false, $idShopGroup, $idShop)) {
                return self::failed($saved, $change['field'] . ' could not be saved', $idEmployee);
            }
            $saved[] = $change['field'];
            if ($change['field'] === 'default_id_currency' && $currency !== null) {
                self::resetRates($currency['feed']);
            }
        }
        SomcpLogger::log('localization_settings_updated', SomcpLogger::OUTCOME_SUCCESS, implode(', ', $saved), '', $idEmployee);

        $result = [
            'changed' => true,
            'changes' => array_map(static function (array $change): array {
                return ['field' => $change['field'], 'old' => $change['old'], 'new' => $change['new']];
            }, $plan),
        ];
        $warnings = [];
        if ($currency !== null) {
            $after = SomcpToolUpdateExchangeRates::currencies($idShop);
            $result['rates'] = SomcpToolUpdateExchangeRates::rateChanges($currency['before'], $after);
            $iso = $currency['iso_code'];
            $listed = SomcpToolUpdateExchangeRates::listed($currency['feed']);
            $missing = [];
            foreach ($after as $row) {
                if ($row['id_currency'] !== (int) $currency['id_currency']
                    && !in_array(strtoupper((string) $row['iso_code']), $listed, true)) {
                    $missing[] = (string) $row['iso_code'];
                }
            }
            if ($missing !== []) {
                $result['not_in_feed'] = $missing;
            }
            $warnings[] = 'The default currency is now ' . $iso . '. Product prices were not converted: each keeps its '
                . 'figure, now read in ' . $iso . ', as the back office warns. Check them with somcp_get_product.';
            if ($missing !== []) {
                // CurrencyManager sets every rate to 1 before the feed is read,
                // and the feed cannot move a currency it does not list.
                $warnings[] = 'PrestaShop\'s exchange rates do not include ' . implode(', ', $missing) . ': their rate '
                    . 'is now 1; set it with somcp_update_currency.';
            }
        }
        foreach ($plan as $change) {
            if ($change['field'] === 'default_id_country' && !(new Country((int) $change['new']))->active) {
                $warnings[] = Country::getNameById($idLang, (int) $change['new']) . ' is the default country but is not '
                    . 'enabled: customers cannot pick it in an address; enable it with somcp_update_country.';
            }
            if (in_array($change['field'], self::UNITS, true)) {
                // The keys are labels: nothing in the core converts a stored value.
                $warnings[] = 'The ' . $change['field'] . ' changed from ' . $change['old'] . ' to ' . $change['new']
                    . ': weights, sizes and carrier ranges already typed are not converted, only their label changes.';
            }
        }
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'The settings changed for this shop only, as the back office saves them in a shop context.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        // Read back rather than echoed: what the assistant shows is what the
        // shop stored.
        $result['localization'] = SomcpToolGetLocalizationSettings::settings($idShop, $idLang);

        return SomcpTool::json($result);
    }

    /**
     * Default language: the form offers the enabled languages of the shop.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<string, mixed> $given
     * @param array<int, string> $problems
     *
     * @return void
     */
    private static function checkLanguage(array $arguments, int $idShop, array &$given, array &$problems): void
    {
        if (!array_key_exists('default_id_lang', $arguments) || $arguments['default_id_lang'] === null) {
            return;
        }
        $id = SomcpTool::id($arguments, 'default_id_lang');
        if ($id === 0) {
            $problems[] = SomcpTool::notAnIdentifier('default_id_lang');

            return;
        }
        foreach (Language::getLanguages(false, $idShop) as $row) {
            if ((int) $row['id_lang'] !== $id) {
                continue;
            }
            if ((int) $row['active'] !== 1) {
                $problems[] = 'default_id_lang: the language ' . $id . ' is disabled - the form offers enabled '
                    . 'languages only; enable it with somcp_update_language first';
            } else {
                $given['default_id_lang'] = $id;
            }

            return;
        }
        $problems[] = SomcpTool::notFound('default_id_lang', 'language', $id, 'somcp_list_languages');
    }

    /**
     * Default country: the form offers every country, enabled or not.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $given
     * @param array<int, string> $problems
     *
     * @return void
     */
    private static function checkCountry(array $arguments, array &$given, array &$problems): void
    {
        if (!array_key_exists('default_id_country', $arguments) || $arguments['default_id_country'] === null) {
            return;
        }
        $id = SomcpTool::id($arguments, 'default_id_country');
        if ($id === 0) {
            $problems[] = SomcpTool::notAnIdentifier('default_id_country');
        } elseif (!Validate::isLoadedObject(new Country($id))) {
            $problems[] = SomcpTool::notFound('default_id_country', 'country', $id, 'somcp_list_countries');
        } else {
            $given['default_id_country'] = $id;
        }
    }

    /**
     * Default currency: the form offers the enabled currencies of the shop.
     *
     * The value read always passes, as no change, so a read given back whole
     * is never refused for it.
     *
     * @param array<string, mixed> $arguments
     * @param int $stored
     * @param int $idShop
     * @param int $idLang
     * @param array<string, mixed> $given
     * @param array<int, string> $problems
     *
     * @return void
     */
    private static function checkCurrency(array $arguments, int $stored, int $idShop, int $idLang, array &$given, array &$problems): void
    {
        if (!array_key_exists('default_id_currency', $arguments) || $arguments['default_id_currency'] === null) {
            return;
        }
        $id = SomcpTool::id($arguments, 'default_id_currency');
        if ($id === 0) {
            $problems[] = SomcpTool::notAnIdentifier('default_id_currency');

            return;
        }
        if ($id === $stored) {
            $given['default_id_currency'] = $id;

            return;
        }
        $query = SomcpToolListCurrencies::query($idShop, $idLang);
        $query->where('c.`id_currency` = ' . (int) $id);
        $row = Db::getInstance()->getRow($query);
        if (!is_array($row)) {
            $problems[] = SomcpTool::notFound('default_id_currency', 'currency', $id, 'somcp_list_currencies');
        } elseif ((int) $row['active'] !== 1) {
            $problems[] = 'default_id_currency: the currency ' . $id . ' is disabled - the form offers enabled '
                . 'currencies only; enable it with somcp_update_currency first';
        } else {
            $given['default_id_currency'] = $id;
        }
    }

    /**
     * What a new default currency needs before anything is written: the feed, and the rates read before.
     *
     * The core sets every rate to 1, then reads them from the feed; when the
     * feed does not answer the back office leaves them at 1 without a word.
     * Fetched first, so that case changes nothing.
     *
     * @param int $idShop
     * @param int $old
     * @param int $new
     * @param int $idEmployee
     *
     * @return array<string, mixed> the refusal, with content, or feed, before, iso_code and id_currency
     */
    private static function prepareCurrency(int $idShop, int $old, int $new, int $idEmployee): array
    {
        // The rates live in currency_shop, one row per shop: the core resets
        // and reads them for the shops of the context.
        Somcp::actAsShop($idShop);
        $before = SomcpToolUpdateExchangeRates::currencies($idShop);
        $oldIso = isset($before[$old]) ? (string) $before[$old]['iso_code'] : (string) $old;
        $newIso = isset($before[$new]) ? (string) $before[$new]['iso_code'] : (string) $new;

        $feed = SomcpToolUpdateExchangeRates::feed();
        if ($feed === null) {
            SomcpLogger::log('localization_settings_update_failed', SomcpLogger::OUTCOME_FAILURE, 'feed unreadable', '', $idEmployee);

            return SomcpTool::text(
                'PrestaShop\'s exchange rate server did not answer within ' . SomcpToolUpdateExchangeRates::TIMEOUT
                    . ' seconds, or sent something unreadable. Nothing was changed: the default currency stays '
                    . $oldIso . ', since changing it needs new exchange rates. Try again later.',
                true
            );
        }
        if (!in_array(strtoupper($newIso), SomcpToolUpdateExchangeRates::listed($feed), true)) {
            SomcpLogger::log('localization_settings_update_failed', SomcpLogger::OUTCOME_FAILURE, 'currency not in feed', '', $idEmployee);

            return SomcpTool::text(
                'PrestaShop\'s exchange rates do not include ' . $newIso . ': the rates of the other currencies '
                    . 'cannot be worked out from it. Nothing was changed.',
                true
            );
        }

        return ['feed' => $feed, 'before' => $before, 'iso_code' => $newIso, 'id_currency' => $new];
    }

    /**
     * The core's own steps after a new default currency, called rather than copied.
     *
     * @param SimpleXMLElement $feed the feed already fetched, so the core sends no second request
     *
     * @return void
     */
    private static function resetRates(SimpleXMLElement $feed): void
    {
        Cache::store('Tools::simplexml_load_file' . _PS_CURRENCY_FEED_URL_, $feed);
        $manager = SomcpBackOffice::coreService('prestashop.adapter.manager.currency');
        if (!$manager instanceof PrestaShop\PrestaShop\Adapter\Currency\CurrencyManager) {
            // The MCP endpoint is a front controller, with no kernel container.
            $manager = new PrestaShop\PrestaShop\Adapter\Currency\CurrencyManager();
        }
        $manager->updateDefaultCurrency();
    }

    /**
     * The units and the two server identifiers, each a text the form saves as typed.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $given
     * @param array<int, string> $problems
     *
     * @return void
     */
    private static function checkTexts(array $arguments, array &$given, array &$problems): void
    {
        foreach (self::UNITS as $field) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            $value = is_string($arguments[$field]) ? trim($arguments[$field]) : '';
            if ($value === '') {
                $problems[] = $field . ': required, never empty';
            } elseif (!Validate::isGenericName($value)) {
                $problems[] = $field . ': no <>={} in a unit';
            } elseif (Tools::strlen($value) > self::UNIT_MAX) {
                $problems[] = $field . ': ' . self::UNIT_MAX . ' characters at most, a unit such as kg or cm';
            } else {
                $given[$field] = $value;
            }
        }
        foreach (self::IDENTIFIERS as $field => $rule) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            $value = is_string($arguments[$field]) ? Tools::strtolower(trim($arguments[$field])) : '';
            if (preg_match('/^[a-z]{2}$/', $value) === 1) {
                $given[$field] = $value;
            } else {
                $problems[] = $field . ': ' . $rule;
            }
        }
    }

    /**
     * A name of the timezone table, which the Time zone list of the page reads.
     *
     * @param string $name
     *
     * @return bool
     */
    private static function isTimezone(string $name): bool
    {
        return $name !== '' && (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . "timezone` WHERE BINARY `name` = '" . pSQL($name) . "'"
        );
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
        SomcpLogger::log('localization_settings_update_failed', SomcpLogger::OUTCOME_FAILURE, $failure, '', $idEmployee);

        return SomcpTool::text(
            'The localization settings could not be changed: ' . $failure
                . ($saved !== [] ? ', after ' . implode(', ', $saved) . ' had been saved' : '')
                . '. Read them with somcp_get_localization_settings before calling again.',
            true
        );
    }
}
