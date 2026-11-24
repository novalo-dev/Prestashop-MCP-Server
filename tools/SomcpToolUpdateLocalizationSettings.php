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
 * languages, any country, the time zones of the timezone table. The default
 * currency resets every exchange rate when it changes, which is a part of
 * its own: until it is written, only the value read passes.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

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
                . 'not converted. The default currency cannot be changed yet. To enable a language or a country, '
                . 'somcp_update_language or somcp_update_country.',
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
                        'description' => 'Default currency (ID): only the value read for now.',
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
        self::checkCurrency($arguments, (int) $stored['default_id_currency'], $given, $problems);
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

        $saved = [];
        foreach ($plan as $change) {
            $value = is_bool($change['new']) ? (int) $change['new'] : $change['new'];
            if (!Configuration::updateValue($change['key'], $value, false, $idShopGroup, $idShop)) {
                return self::failed($saved, $change['field'] . ' could not be saved', $idEmployee);
            }
            $saved[] = $change['field'];
        }
        SomcpLogger::log('localization_settings_updated', SomcpLogger::OUTCOME_SUCCESS, implode(', ', $saved), '', $idEmployee);

        $result = [
            'changed' => true,
            'changes' => array_map(static function (array $change): array {
                return ['field' => $change['field'], 'old' => $change['old'], 'new' => $change['new']];
            }, $plan),
        ];
        $warnings = [];
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
     * Default currency: changing it resets every exchange rate, which this
     * tool does not do yet, so only the value read passes, as no change.
     *
     * @param array<string, mixed> $arguments
     * @param int $stored
     * @param array<string, mixed> $given
     * @param array<int, string> $problems
     *
     * @return void
     */
    private static function checkCurrency(array $arguments, int $stored, array &$given, array &$problems): void
    {
        if (!array_key_exists('default_id_currency', $arguments) || $arguments['default_id_currency'] === null) {
            return;
        }
        $id = SomcpTool::id($arguments, 'default_id_currency');
        if ($id === 0) {
            $problems[] = SomcpTool::notAnIdentifier('default_id_currency');
        } elseif ($id !== $stored) {
            $problems[] = 'default_id_currency: changing the default currency is not available yet';
        } else {
            $given['default_id_currency'] = $id;
        }
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
