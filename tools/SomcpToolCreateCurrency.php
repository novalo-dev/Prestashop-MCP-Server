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
 * somcp_create_currency - one currency added to International > Localization
 * > Currencies, as Add new currency does with Select a currency: one of the
 * official currencies PrestaShop knows (CLDR), its names and symbols in every
 * language filled by the shop.
 *
 * A shop that opens to a second market needs its currency before any
 * country, payment restriction or price can use it. The answer is the
 * currency read back through somcp_get_currency, so the creation and the
 * read give the same fields.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// answer is the read of the currency; the numbers and the translated texts
// are read as the other tools read them.
require_once __DIR__ . '/SomcpToolGetCurrency.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolIssuePartialRefund.php';

class SomcpToolCreateCurrency
{
    /**
     * The largest rate currency_shop.conversion_rate holds, a decimal(13,6).
     */
    private const MAX_RATE = 9999999.999999;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_currency',
            Somcp::t('Add a currency'),
            'Adds one currency to International > Localization > Currencies, as Add new currency: an official one '
                . '(Select a currency) or, with alternative, one PrestaShop does not know (Create an alternative '
                . 'currency). ISO code, Exchange rate, Decimals, Status, and optionally the names and symbols, '
                . 'otherwise filled from CLDR. Read somcp_list_currencies first. The rate is '
                . 'the merchant\'s - ask, never invent. For a currency the shop already has, '
                . 'somcp_update_currency; payment modules accepting it are set in Payment > Preferences (somcp_update_payment_restrictions).',
            [
                'type' => 'object',
                'properties' => [
                    'iso_code' => [
                        'type' => 'string',
                        'description' => 'Merchant. Required. Select a currency: the ISO 4217 code of an official '
                            . 'currency (e.g. CHF, GBP); with alternative, three letters no official currency has.',
                    ],
                    'alternative' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Create an alternative currency: true for a currency PrestaShop '
                            . 'does not know (a local or loyalty currency), named by the merchant. Left out, an '
                            . 'official one.',
                    ],
                    'conversion_rate' => [
                        'type' => 'number',
                        'description' => 'Merchant. Required. Exchange rate: the value of 1 unit of the default '
                            . 'currency in this one, above 0, 6 decimals kept.',
                    ],
                    'precision' => [
                        'type' => 'integer',
                        'description' => 'Merchant. Decimals: the number of decimals prices show, 0 or more. Left '
                            . 'out, the decimals of the currency in CLDR, 2 for an alternative one.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Status: true offers the currency. true by default, as the page.',
                    ],
                    'name' => [
                        'type' => ['string', 'object'],
                        'description' => 'Merchant. Currency name, an object keyed by ISO code of language (a text '
                            . 'alone on a shop with one language). Left out or empty, the CLDR name (the ISO code '
                            . 'for an alternative currency).',
                    ],
                    'symbol' => [
                        'type' => ['string', 'object'],
                        'description' => 'Merchant. Symbol, keyed as name. Left out or empty, the CLDR symbol.',
                    ],
                    'languages' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'The ISO codes of the languages name and symbol fill; every active '
                            . 'language when left out.',
                    ],
                ],
                'required' => ['iso_code', 'conversion_rate'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Adding a currency is what Add new currency asks for in Team,
            // Permissions; editing one is a different right.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminCurrencies')
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
            ['iso_code', 'alternative', 'conversion_rate', 'precision', 'active', 'name', 'symbol', 'languages'],
            ['id_currency']
        );
        $alternative = false;
        if (array_key_exists('alternative', $arguments) && $arguments['alternative'] !== null) {
            // A boolean only: "false" cast to bool is true, and would add a
            // currency without the official names, symbol and decimals.
            if (!is_bool($arguments['alternative'])) {
                $errors[] = 'alternative: true or false';
            } else {
                $alternative = $arguments['alternative'];
            }
        }
        // Typed for the analysis only: the class exists on 8 and 9, and the
        // service is looked up by its identifiers, never by a constructor.
        /** @var PrestaShop\PrestaShop\Core\Localization\CLDR\LocaleRepository|null $repository */
        $repository = SomcpBackOffice::cldrLocaleRepository();
        $cldr = $repository !== null
            ? self::cldrLocale($repository, (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop))
            : null;

        $iso = null;
        $reference = null;
        $existing = null;
        if (!isset($arguments['iso_code']) || !is_string($arguments['iso_code'])
            || !preg_match('/^[A-Za-z]{3}$/', trim($arguments['iso_code']))) {
            $errors[] = $alternative
                ? 'iso_code: required, three letters no official currency has'
                : 'iso_code: required, the three letters of an official currency (ISO 4217, for example USD or EUR)';
        } else {
            $iso = Tools::strtoupper(trim($arguments['iso_code']));
            if ($cldr !== null) {
                $reference = $cldr->getCurrency($iso);
                if ($alternative && $reference !== null) {
                    // As the alternative add refuses it: an official code
                    // takes the names, symbol and decimals CLDR gives it.
                    $errors[] = 'iso_code: ' . $iso . ' is an official currency - leave alternative out to add it';
                } elseif (!$alternative && $reference === null) {
                    $errors[] = 'iso_code: ' . $iso . ' is not an official currency PrestaShop knows (ISO 4217, for '
                        . 'example USD or EUR)';
                }
            }
            $existing = self::stored($iso);
            if ($existing !== null && !$existing['deleted']) {
                $errors[] = 'iso_code: ' . $iso . ' is already currency ' . $existing['id_currency'] . ' of this shop - '
                    . 'change it with somcp_update_currency';
            }
        }

        $rate = null;
        $given = $arguments['conversion_rate'] ?? null;
        $number = $given !== null ? SomcpToolIssuePartialRefund::number($given) : null;
        // Six decimals, what the column keeps.
        $number = $number !== null ? round($number, 6) : null;
        if ($number === null || $number <= 0 || $number > self::MAX_RATE) {
            $idDefault = SomcpToolListCurrencies::defaultCurrency($idShop);
            $defaultIso = $idDefault > 0 ? (string) Currency::getIsoCodeById($idDefault) : '';
            $errors[] = 'conversion_rate: a number above 0, the value of 1 '
                . ($defaultIso !== '' ? $defaultIso : 'default currency') . ' in this currency';
        } else {
            $rate = $number;
        }

        $precision = null;
        if (array_key_exists('precision', $arguments) && $arguments['precision'] !== null) {
            $precision = SomcpToolCreateProduct::integer($arguments['precision']);
            if ($precision === null || $precision < 0 || $precision > 99) {
                $errors[] = 'precision: a whole number of 0 or more';
                $precision = null;
            }
        }

        $active = true;
        if (array_key_exists('active', $arguments) && $arguments['active'] !== null) {
            // A boolean only: "false" cast to bool is true, and would offer a
            // currency the merchant wanted kept off.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } else {
                $active = $arguments['active'];
            }
        }

        [$names, $symbols] = self::texts($arguments, $idShop, $errors);

        if ($errors !== [] || $iso === null || $rate === null) {
            return SomcpTool::refused('created', $errors);
        }
        if ($repository === null || $cldr === null) {
            if ($alternative) {
                // An alternative currency needs no CLDR data, but without it
                // the code cannot be told apart from an official one.
                return SomcpTool::refused('created', ['iso_code: the currencies PrestaShop knows cannot be read here, '
                    . 'so ' . $iso . ' cannot be checked - add it on the Add new currency page']);
            }

            // Without CLDR neither the official list, the numeric code nor
            // the names can be known: nothing half-filled is written.
            return SomcpTool::text('Nothing was created. The currencies PrestaShop knows cannot be read here - add it '
                . 'on the Add new currency page', true);
        }

        $restored = $existing !== null;
        $created = self::create($iso, $rate, $active, $idShop, $repository, $alternative, $precision, $names, $symbols);
        if (is_string($created)) {
            SomcpLogger::log('currency_create_failed', SomcpLogger::OUTCOME_FAILURE, $iso . ': '
                . Tools::substr($created, 0, 500), '', $idEmployee);

            return SomcpTool::text('Nothing was created. The shop refused currency ' . $iso . ': ' . $created
                . ' - read somcp_list_currencies before calling again', true);
        }
        $idCurrency = $created;
        SomcpLogger::log('currency_created', SomcpLogger::OUTCOME_SUCCESS, 'currency ' . $idCurrency . ' (' . $iso . ')'
            . ($restored ? ' restored' : ''), '', $idEmployee);

        $warnings = [];
        if ($alternative) {
            // refreshLocalizedCurrencyData() puts the ISO code where a name or
            // a symbol was left empty: said, so the merchant can name it.
            foreach (['name' => $names, 'symbol' => $symbols] as $field => $texts) {
                $empty = [];
                foreach ($texts as $idLang => $text) {
                    if ($text === '') {
                        $empty[] = (string) Language::getIsoById((int) $idLang);
                    }
                }
                if ($empty !== []) {
                    $warnings[] = $field . ' left empty in ' . implode(', ', $empty) . ': the ISO code ' . $iso
                        . ' is shown instead.';
                }
            }
        }
        if ($restored) {
            $warnings[] = $iso . ' was deleted before: the same currency is back, without the payment modules it had '
                . 'in Payment > Preferences (somcp_update_payment_restrictions).';
        }
        if (self::paymentModules($idCurrency, $idShop) === 0) {
            $warnings[] = 'Payment > Preferences (somcp_update_payment_restrictions) decides which payment modules accept ' . $iso . '; no module accepts '
                . 'it yet.';
        }

        $result = ['created' => true, 'id_currency' => $idCurrency];
        if ($restored) {
            $result['restored'] = true;
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        $language = SomcpTool::readLanguage([], $idShop);
        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $result['currency'] = $language !== null ? SomcpToolGetCurrency::read($idShop, $language, $idCurrency) : null;

        return SomcpTool::json($result);
    }

    /**
     * One currency created as Add new currency does, its identifier returned, or the shop's refusal.
     *
     * Shared by somcp_create_currency and somcp_import_localization_pack,
     * which creates the currencies of a pack the shop lacks where the core's
     * command bus does not run: one creation, so both write the same row.
     * The arguments are already checked; the deleted row of the same code is
     * reused.
     *
     * @param string $iso uppercase ISO code
     * @param float $rate
     * @param bool $active
     * @param int $idShop
     * @param PrestaShop\PrestaShop\Core\Localization\CLDR\LocaleRepository $repository
     * @param bool $alternative
     * @param int|null $precision null for the decimals of CLDR, 2 for an alternative currency
     * @param array<int, string> $names by language identifier, empty ones filled from CLDR
     * @param array<int, string> $symbols by language identifier, empty ones filled from CLDR
     *
     * @return int|string
     */
    public static function create(
        string $iso,
        float $rate,
        bool $active,
        int $idShop,
        $repository,
        bool $alternative = false,
        ?int $precision = null,
        array $names = [],
        array $symbols = [],
    ) {
        $cldr = self::cldrLocale($repository, (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop));
        $reference = $cldr !== null ? $cldr->getCurrency($iso) : null;
        if (!$alternative && $reference === null) {
            return $iso . ' is not an official currency PrestaShop knows';
        }
        $existing = self::stored($iso);
        if ($existing !== null && !$existing['deleted']) {
            return $iso . ' is already currency ' . $existing['id_currency'] . ' of this shop';
        }
        foreach (Language::getIDs(false) as $idLang) {
            $names[(int) $idLang] = $names[(int) $idLang] ?? '';
            $symbols[(int) $idLang] = $symbols[(int) $idLang] ?? '';
        }
        $restored = $existing !== null;
        // The deleted row of the same code is reused, as the core's data
        // provider does: the orders placed in it stay tied to the one currency.
        $currency = $restored ? new Currency((int) $existing['id_currency']) : new Currency();
        $currency->iso_code = $iso;
        $currency->active = $active;
        $currency->deleted = false;
        $currency->unofficial = $alternative;
        $currency->conversion_rate = $rate;
        if ($reference !== null) {
            $currency->numeric_iso_code = (string) $reference->getNumericIsoCode();
            $currency->precision = $precision ?? (int) $reference->getDecimalDigits();
        } else {
            // No numeric code and the 2 decimals of the form: CLDR has neither
            // for a currency it does not know.
            $currency->numeric_iso_code = null;
            $currency->precision = $precision ?? 2;
        }
        // Tied to the shop of the token only, so currency_shop gets its row
        // and its rate, as the zone and state tools write their shop rows.
        $currency->id_shop_list = [$idShop];
        // Empty texts are filled from CLDR by refreshLocalizedCurrencyData(),
        // as the add page leaves them to it.
        $currency->setLocalizedNames($names);
        $currency->setLocalizedSymbols($symbols);
        // A reused row keeps the formats it had in every language, as the
        // core's add keeps the stored row when no format is given. A new
        // currency has none yet: its formats stay null, which the setter,
        // typed for an array, refuses.
        if ($restored) {
            $currency->setLocalizedPatterns($currency->getLocalizedPatterns());
        }

        try {
            $currency->refreshLocalizedCurrencyData(Language::getLanguages(false), $repository);
            $failure = '';
            foreach ([$currency->validateFields(false, true), $currency->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $failure = strip_tags(is_string($check) ? $check : 'a field PrestaShop refuses');
                    break;
                }
            }
            if ($failure === '') {
                // Null values kept as null, as the core saves a currency.
                $done = $currency->save(true, true);
                $failure = $done ? '' : 'PrestaShop refused to save it';
            } else {
                $done = false;
            }
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        return $done ? (int) $currency->id : $failure;
    }

    /**
     * The CLDR locale of a language, or English when it has none, or null when the repository cannot give it.
     *
     * Every CLDR locale holds the same currencies, as the core's handler
     * says when it picks the first language: the add takes the default
     * language. Public because somcp_update_currency builds the price format
     * of each language from the pattern of its own locale.
     *
     * @param PrestaShop\PrestaShop\Core\Localization\CLDR\LocaleRepository $repository
     * @param int $idLang
     *
     * @return PrestaShop\PrestaShop\Core\Localization\CLDR\LocaleInterface|null
     */
    public static function cldrLocale($repository, int $idLang)
    {
        $language = new Language($idLang);
        $code = Validate::isLoadedObject($language) ? (string) $language->locale : '';
        foreach (array_unique(array_filter([$code, 'en-US'])) as $locale) {
            try {
                $found = $repository->getLocale($locale);
                if (is_object($found)) {
                    return $found;
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * The currency row of an ISO code, deleted ones included, or null.
     *
     * @param string $iso
     *
     * @return array{id_currency: int, deleted: bool}|null
     */
    private static function stored(string $iso): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT `id_currency`, `deleted` FROM `' . _DB_PREFIX_ . 'currency`'
            . " WHERE `iso_code` = '" . pSQL($iso) . "' ORDER BY `deleted` ASC, `id_currency` ASC"
        );
        if (!is_array($row) || empty($row['id_currency'])) {
            return null;
        }

        return ['id_currency' => (int) $row['id_currency'], 'deleted' => (bool) $row['deleted']];
    }

    /**
     * The names and symbols given, by language identifier, every language present.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function texts(array $arguments, int $idShop, array &$errors): array
    {
        $names = [];
        $symbols = [];
        foreach (Language::getIDs(false) as $idLang) {
            $names[(int) $idLang] = '';
            $symbols[(int) $idLang] = '';
        }
        if (!isset($arguments['name']) && !isset($arguments['symbol']) && !array_key_exists('languages', $arguments)) {
            return [$names, $symbols];
        }
        // Keyed, defaulted and refused by the rule of every write tool (#149).
        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return [$names, $symbols];
        }
        $probe = new Currency();
        $out = ['name' => $names, 'symbol' => $symbols];
        foreach (array_keys($out) as $field) {
            $texts = SomcpToolCreateProduct::texts($arguments, $field, $field, $languages, $errors);
            if ($texts === null) {
                continue;
            }
            foreach ($texts as $idLang => $text) {
                $text = self::checkedText($probe, $field, $text, (int) $idLang, $languages['filled'][$idLang], $errors);
                if ($text !== null) {
                    $out[$field][(int) $idLang] = $text;
                }
            }
        }

        return [$out['name'], $out['symbol']];
    }

    /**
     * A name or symbol of one language, trimmed, or null with its error when the shop would refuse it.
     *
     * Public so the add and the edit refuse a text with the same words; an
     * empty text passes, as the shop fills it from CLDR.
     *
     * @param Currency $probe
     * @param string $field name or symbol
     * @param string $text
     * @param int $idLang
     * @param string $iso the ISO code of the language, for the message
     * @param array<int, string> $errors
     *
     * @return string|null
     */
    public static function checkedText(Currency $probe, string $field, string $text, int $idLang, string $iso, array &$errors): ?string
    {
        $text = trim($text);
        $where = $field . ' (' . $iso . ')';
        $max = SomcpTool::columnSize($probe, $field, 255);
        if (Tools::strlen($text) > $max) {
            $errors[] = $where . ': ' . $max . ' characters at most, ' . Tools::strlen($text) . ' given';

            return null;
        }
        if ($text !== '' && $probe->validateField($field, $text, $idLang) !== true) {
            $errors[] = $where . ': a character PrestaShop refuses in a currency ' . $field . ' (< > = { })';

            return null;
        }

        return $text;
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
}
