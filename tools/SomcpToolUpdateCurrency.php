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
 * Currencies changed, as its Edit page does for Exchange rate, Decimals,
 * Status, Currency name, Symbol, Customize symbol and format and Restore
 * default settings, or as the Enabled switch of the list does.
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
require_once __DIR__ . '/SomcpToolCreateCurrency.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolCreateTax.php';
require_once __DIR__ . '/SomcpToolIssuePartialRefund.php';

class SomcpToolUpdateCurrency
{
    /**
     * The fields of somcp_get_currency this tool does not write, taken only as the read gives them.
     */
    private const COMPUTED = ['language', 'iso_code', 'default', 'numeric_iso_code', 'alternative', 'pattern',
        'modified'];

    /**
     * The positions of the symbol Customize symbol and format offers, the
     * transformations of the core's PatternTransformer. Only these: a raw
     * pattern typed by an assistant would be printed in every price.
     */
    private const FORMATS = ['leftWithSpace', 'leftWithoutSpace', 'rightWithSpace', 'rightWithoutSpace'];

    /**
     * The format word that empties the pattern, so the CLDR one of the language applies.
     */
    private const DEFAULT_FORMAT = 'default';

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
            'Changes one currency of International > Localization > Currencies, as its Edit page: Exchange rate, '
                . 'Decimals, Status, Currency name, Symbol, the symbol position (format) or Restore default '
                . 'settings. Take id_currency from somcp_list_currencies; a row of somcp_get_currency can be given '
                . 'back whole, only what differs is written. The default currency keeps rate 1 and stays enabled. '
                . 'To delete one, somcp_delete_currency; the currency of a country, somcp_update_country.',
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
                    'name' => [
                        'type' => ['string', 'object'],
                        'description' => 'Merchant. Currency name, an object keyed by ISO code of language (a text '
                            . 'alone on a shop with one language). A language left out keeps its name; "" takes '
                            . 'the CLDR name back.',
                    ],
                    'symbol' => [
                        'type' => ['string', 'object'],
                        'description' => 'Merchant. Symbol, keyed as name. "" takes the CLDR symbol back.',
                    ],
                    'format' => [
                        'type' => ['string', 'object'],
                        'description' => 'Merchant. Customize symbol and format: leftWithSpace, '
                            . 'leftWithoutSpace, rightWithSpace or rightWithoutSpace, or default for the CLDR format; '
                            . 'one word for every language, or keyed as name.',
                    ],
                    'restore_defaults' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Restore default settings: true puts back the CLDR names, symbols '
                            . 'and format in every language. Alone, without name, symbol or format.',
                    ],
                    'languages' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'The ISO codes of the languages name, symbol and format change; every '
                            . 'active language when left out.',
                    ],
                    'language' => ['type' => 'string'] + $computed,
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
            array_merge(['id_currency', 'conversion_rate', 'precision', 'active', 'name', 'symbol', 'format',
                'restore_defaults', 'languages'], self::COMPUTED)
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

        // Loaded for the shop of the token, so the rate read and written is
        // the one of its currency_shop row only.
        $currency = $stored !== null ? new Currency($idCurrency, null, $idShop) : null;
        if ($currency !== null) {
            // The constructor turns the names, symbols and formats into the
            // string of one language; put back in every language, or update()
            // would write that one string in all of them. Put back as loaded:
            // an empty format stays null, as the back office stores it.
            $currency->setLocalizedNames($currency->getLocalizedNames());
            $currency->setLocalizedSymbols($currency->getLocalizedSymbols());
            $currency->setLocalizedPatterns($currency->getLocalizedPatterns());
        }
        $before = $currency !== null ? self::texts($currency) : null;
        // Typed for the analysis only: the class exists on 8 and 9, and the
        // service is looked up by its identifiers, never by a constructor.
        /** @var PrestaShop\PrestaShop\Core\Localization\CLDR\LocaleRepository|null $repository */
        $repository = null;
        // The fields given empty in a language, for the warning of an
        // alternative currency.
        $emptied = [];
        $textsGiven = self::planTexts($arguments, $currency, $idShop, $repository, $errors, $emptied);

        self::givenBack($arguments, $stored, $errors);
        if (!array_key_exists('conversion_rate', $arguments) && !array_key_exists('precision', $arguments)
            && !array_key_exists('active', $arguments) && !$textsGiven) {
            $errors[] = 'conversion_rate, precision, active, name, symbol, format or restore_defaults: give at least '
                . 'one field to change';
        }
        if ($errors !== [] || $stored === null || $before === null) {
            return SomcpTool::refused('changed', $errors);
        }

        if ($textsGiven && $repository !== null) {
            try {
                // The empty names and symbols take the CLDR ones again, and
                // modified is worked out, as the Edit page saves them.
                $currency->refreshLocalizedCurrencyData(Language::getLanguages(false), $repository);
                $check = $currency->validateFieldsLang(false, true);
                if ($check !== true) {
                    $errors[] = 'currency: ' . strip_tags(is_string($check) ? $check : 'a field PrestaShop refuses');
                }
            } catch (Throwable $e) {
                $errors[] = 'currency: ' . $e->getMessage();
            }
            if ($errors !== []) {
                return SomcpTool::refused('changed', $errors);
            }
            $after = self::texts($currency);
            foreach (['name', 'symbol', 'pattern'] as $field) {
                foreach ($after[$field] as $idLang => $value) {
                    $old = $before[$field][$idLang] ?? '';
                    if ($value === $old) {
                        continue;
                    }
                    $iso = (string) Language::getIsoById((int) $idLang);
                    self::record($changes, $field . ' (' . $iso . ')', $old !== '' ? $old : null, $value !== '' ? $value : null);
                }
            }
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

        $currency->id_shop_list = [$idShop];
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
                $warnings[] = $modules . ' payment modules accept ' . $iso . ' in Payment > Preferences (somcp_update_payment_restrictions); they keep '
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
        // CLDR has no text for a currency it does not know: the core puts
        // its ISO code in the place of an emptied one.
        foreach ($stored['alternative'] ? array_keys($emptied) : [] as $field) {
            $warnings[] = $iso . ' is an alternative currency: an empty ' . $field . ' takes its ISO code.';
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
     * Sets on the currency the names, symbols and formats given, or the defaults restored.
     *
     * @param array<string, mixed> $arguments
     * @param Currency|null $currency loaded in every language, null when not found
     * @param int $idShop
     * @param mixed $repository set to the CLDR repository when a text is given
     * @param array<int, string> $errors
     * @param array<string, bool> $emptied the fields given empty in a language, by name
     *
     * @param-out PrestaShop\PrestaShop\Core\Localization\CLDR\LocaleRepository|null $repository
     *
     * @return bool whether a name, symbol, format or restore_defaults was given
     */
    private static function planTexts(array $arguments, ?Currency $currency, int $idShop, &$repository, array &$errors, array &$emptied): bool
    {
        $restore = false;
        if (array_key_exists('restore_defaults', $arguments) && $arguments['restore_defaults'] !== null) {
            if (!is_bool($arguments['restore_defaults'])) {
                $errors[] = 'restore_defaults: true or false';
            } else {
                $restore = $arguments['restore_defaults'];
            }
        }
        $given = [];
        foreach (['name', 'symbol', 'format'] as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== null) {
                $given[] = $field;
            }
        }
        if ($restore && $given !== []) {
            $errors[] = 'restore_defaults: alone, or the texts given beside it would be lost';

            return true;
        }
        if (!$restore && $given === []) {
            return false;
        }
        // Typed for the analysis only, as somcp_create_currency takes it.
        /** @var PrestaShop\PrestaShop\Core\Localization\CLDR\LocaleRepository|null $found */
        $found = SomcpBackOffice::cldrLocaleRepository();
        $repository = $found;
        if ($found === null) {
            // Without CLDR an emptied text could not be filled again nor a
            // format built: nothing half-made is written.
            $errors[] = ($restore ? 'restore_defaults' : $given[0]) . ': the formats of the shop cannot be read here '
                . '- change it on the Edit page of the currency';

            return true;
        }
        if ($restore) {
            // Restore default settings empties every text, which
            // refreshLocalizedCurrencyData() then fills from CLDR.
            if ($currency !== null) {
                $empty = array_fill_keys(array_map('intval', Language::getIDs(false)), '');
                $currency->setLocalizedNames($empty);
                $currency->setLocalizedSymbols($empty);
                $currency->setLocalizedPatterns($empty);
            }

            return true;
        }

        // Keyed, defaulted and refused by the rule of every write tool (#149).
        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $errors);
        if ($languages === null || $currency === null) {
            return true;
        }
        $stored = self::texts($currency);
        $probe = new Currency();
        foreach (['name', 'symbol'] as $field) {
            if (!in_array($field, $given, true)) {
                continue;
            }
            // somcp_get_currency answers the text of one language: given back
            // as read, it is no change.
            if (SomcpToolCreateProduct::givenBackAsRead($arguments[$field], $stored[$field], $languages)) {
                continue;
            }
            $texts = SomcpToolCreateProduct::texts($arguments, $field, $field, $languages, $errors);
            if ($texts === null) {
                continue;
            }
            // A language left out keeps its stored text.
            $merged = $stored[$field];
            foreach ($texts as $idLang => $text) {
                $text = SomcpToolCreateCurrency::checkedText($probe, $field, $text, (int) $idLang,
                    $languages['filled'][$idLang], $errors);
                if ($text === null) {
                    continue;
                }
                if ($text === '') {
                    $emptied[$field] = true;
                }
                $merged[(int) $idLang] = $text;
            }
            if ($field === 'name') {
                $currency->setLocalizedNames($merged);
            } else {
                $currency->setLocalizedSymbols($merged);
            }
        }

        if (in_array('format', $given, true)) {
            $patterns = self::patterns($arguments['format'], $languages, $currency->getLocalizedPatterns(), $found, $errors);
            if ($patterns !== null) {
                $currency->setLocalizedPatterns($patterns);
            }
        }

        return true;
    }

    /**
     * The patterns of every language, those of the languages given built from a symbol position.
     *
     * @param mixed $format the argument as given
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<int|string, string|null> $stored the patterns as loaded, by language identifier; the others kept as they are
     * @param PrestaShop\PrestaShop\Core\Localization\CLDR\LocaleRepository $repository
     * @param array<int, string> $errors
     *
     * @return array<int|string, string|null>|null null when refused
     */
    private static function patterns($format, array $languages, array $stored, $repository, array &$errors): ?array
    {
        // One word is unambiguous whatever the number of languages: it is
        // the same position for each one filled.
        $words = is_string($format)
            ? array_fill_keys(array_keys($languages['filled']), $format)
            : SomcpToolCreateProduct::texts(['format' => $format], 'format', 'format', $languages, $errors);
        if ($words === null) {
            return null;
        }
        $refusal = 'format: ' . implode(', ', self::FORMATS) . ' or ' . self::DEFAULT_FORMAT;
        $transformer = new PrestaShop\PrestaShop\Core\Localization\Currency\PatternTransformer();
        $patterns = $stored;
        $failed = false;
        foreach ($words as $idLang => $word) {
            $word = trim($word);
            if ($word === self::DEFAULT_FORMAT) {
                // An empty pattern formats with the CLDR one of the language.
                $patterns[(int) $idLang] = '';
                continue;
            }
            if (!in_array($word, self::FORMATS, true)) {
                $failed = true;
                continue;
            }
            // Built as the core's handler builds it: the position applied to
            // the currency pattern of the language's own locale.
            $locale = SomcpToolCreateCurrency::cldrLocale($repository, (int) $idLang);
            $base = $locale !== null ? (string) $locale->getCurrencyPattern() : '';
            if ($base === '') {
                $errors[] = 'format (' . $languages['filled'][$idLang] . '): the formats of the shop cannot be read '
                    . 'here - change it on the Edit page of the currency';
                continue;
            }
            $patterns[(int) $idLang] = $transformer->transform($base, $word);
        }
        if ($failed) {
            $errors[] = $refusal;

            return null;
        }

        return $patterns;
    }

    /**
     * The names, symbols and patterns of a currency loaded in every language, as texts by language identifier.
     *
     * @param Currency $currency
     *
     * @return array{name: array<int, string>, symbol: array<int, string>, pattern: array<int, string>}
     */
    private static function texts(Currency $currency): array
    {
        return [
            'name' => self::byLanguage($currency->getLocalizedNames()),
            'symbol' => self::byLanguage($currency->getLocalizedSymbols()),
            'pattern' => self::byLanguage($currency->getLocalizedPatterns()),
        ];
    }

    /**
     * Localized values as texts by language identifier, a null pattern as "".
     *
     * @param mixed $values
     *
     * @return array<int, string>
     */
    private static function byLanguage($values): array
    {
        $out = [];
        foreach (is_array($values) ? $values : [] as $idLang => $value) {
            $out[(int) $idLang] = (string) $value;
        }

        return $out;
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
            $errors[] = $field . ($field === 'pattern'
                ? ': worked out by the shop - change it with format'
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
