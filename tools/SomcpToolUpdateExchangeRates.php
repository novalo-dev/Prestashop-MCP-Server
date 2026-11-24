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
 * somcp_update_exchange_rates - the Update button of Update exchange rates,
 * in the Exchange rate block of International > Localization > Currencies.
 *
 * Without it an assistant could only type a rate with somcp_update_currency,
 * from a figure it does not have. The rates come from PrestaShop's own feed,
 * written by PrestaShop's own Currency::refreshCurrencies(), for the shop of
 * the token only.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// currencies are read as that list reads them, and the shops counted as the
// other tools count them.
require_once __DIR__ . '/SomcpToolListCurrencies.php';
require_once __DIR__ . '/SomcpToolCreateTax.php';

class SomcpToolUpdateExchangeRates
{
    /**
     * Seconds the feed is waited for, the timeout SomcpUpload gives a download:
     * the 5 seconds of the core cut a slow answer of PrestaShop's server.
     */
    public const TIMEOUT = 20;

    /**
     * Seconds of PHP the call asks for, above the wait for the feed.
     */
    private const REQUEST_SECONDS = 40;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_exchange_rates',
            Somcp::t('Update the exchange rates'),
            'Updates the exchange rates of every currency of the shop from PrestaShop\'s exchange rate server, as '
                . 'the Update button of Update exchange rates in International > Localization > Currencies. Takes no '
                . 'argument; waits up to ' . self::TIMEOUT . ' seconds for PrestaShop\'s server. Answers the rates '
                . 'that moved, old and new. Do not retry a success: read the rates with somcp_list_currencies. To '
                . 'set one rate by hand, somcp_update_currency.',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
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
        $problems = SomcpTool::unknownFields($arguments, []);
        if ($problems !== []) {
            return SomcpTool::refused('changed', $problems);
        }
        $idShop = Somcp::currentShopId();
        $idEmployee = (int) ($context['id_employee'] ?? 0);
        // The rates live in currency_shop, one row per shop: the core loads
        // and saves the currencies in the shop of the context.
        Somcp::actAsShop($idShop);

        $before = self::currencies($idShop);
        $default = SomcpToolListCurrencies::defaultCurrency($idShop);
        $others = array_filter($before, static function (array $currency) use ($default): bool {
            return $currency['id_currency'] !== $default;
        });
        if ($others === []) {
            $iso = isset($before[$default]) ? (string) $before[$default]['iso_code'] : '';

            return SomcpTool::json([
                'changed' => false,
                'reason' => ($iso !== '' ? $iso . ' is' : 'The default currency is') . ' the only currency of this '
                    . 'shop: its rate is always 1. Nothing was written.',
                'currencies' => array_values($before),
            ]);
        }

        $feed = self::feed();
        if ($feed === null) {
            SomcpLogger::log('exchange_rates_update_failed', SomcpLogger::OUTCOME_FAILURE, 'feed unreadable', '', $idEmployee);

            return SomcpTool::text(
                'PrestaShop\'s exchange rate server did not answer within ' . self::TIMEOUT . ' seconds, or sent '
                    . 'something unreadable. Nothing was changed. Try again later, or set a rate with '
                    . 'somcp_update_currency.',
                true
            );
        }
        // Handed to the core through the cache Tools::simplexml_load_file()
        // reads first, so Currency::refreshCurrencies() sends no second request.
        Cache::store('Tools::simplexml_load_file' . _PS_CURRENCY_FEED_URL_, $feed);

        try {
            $error = (string) Currency::refreshCurrencies();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        if ($error !== '') {
            SomcpLogger::log('exchange_rates_update_failed', SomcpLogger::OUTCOME_FAILURE, Tools::substr($error, 0, 500), '', $idEmployee);

            return SomcpTool::text('The exchange rates could not be updated: ' . $error . '. Nothing was changed.', true);
        }

        // Read again and compared row by row: the core saves only the rates
        // that differ and says nothing of the others.
        $after = self::currencies($idShop);
        $changes = self::rateChanges($before, $after);
        SomcpLogger::log('exchange_rates_updated', SomcpLogger::OUTCOME_SUCCESS, count($changes) . ' rates changed', '', $idEmployee);

        // The core leaves a currency the feed does not list as it was,
        // without a word: named, so the merchant sets it by hand.
        $listed = self::listed($feed);
        $missing = [];
        foreach ($others as $currency) {
            if (!in_array(strtoupper((string) $currency['iso_code']), $listed, true)) {
                $missing[] = (string) $currency['iso_code'];
            }
        }

        $result = ['changed' => $changes !== []];
        if ($changes !== []) {
            $result['changes'] = $changes;
        } else {
            $result['reason'] = 'The exchange rates of PrestaShop give the rates the shop already holds. Nothing was written.';
        }
        $warnings = [];
        if ($missing !== []) {
            $result['not_in_feed'] = $missing;
            $warnings[] = 'PrestaShop\'s exchange rates do not include ' . implode(', ', $missing) . ': their rate was '
                . 'left unchanged; set it with somcp_update_currency.';
        }
        if ($changes !== []) {
            // orders.conversion_rate is stored with each order.
            $warnings[] = 'Prices shown in these currencies change now; orders already placed keep their rate.';
            if (SomcpToolCreateTax::otherShops() > 0) {
                $warnings[] = 'The exchange rates changed for this shop only; the other shops keep theirs.';
            }
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        $result['currencies'] = array_values($after);

        return SomcpTool::json($result);
    }

    /**
     * PrestaShop's exchange rate feed, or null when it does not answer in time or cannot be read.
     *
     * Fetched here with a longer wait than the core's. Public because
     * somcp_update_localization_settings needs the same feed before it
     * changes the default currency, and must fetch it the same way.
     *
     * @return SimpleXMLElement|null
     */
    public static function feed(): ?SimpleXMLElement
    {
        // PHP may stop the request before the feed answers.
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0 && $limit < self::REQUEST_SECONDS && function_exists('set_time_limit')) {
            @set_time_limit(self::REQUEST_SECONDS);
        }
        $content = Tools::file_get_contents(_PS_CURRENCY_FEED_URL_, false, null, self::TIMEOUT);
        $feed = is_string($content) && $content !== '' ? @simplexml_load_string($content) : false;

        return $feed instanceof SimpleXMLElement ? $feed : null;
    }

    /**
     * The ISO codes the feed gives a rate for, its source currency included, in capitals.
     *
     * @param SimpleXMLElement $feed
     *
     * @return array<int, string>
     */
    public static function listed(SimpleXMLElement $feed): array
    {
        $listed = [strtoupper((string) $feed->source['iso_code'])];
        if (isset($feed->list->currency)) {
            foreach ($feed->list->currency as $entry) {
                $listed[] = strtoupper((string) $entry['iso_code']);
            }
        }

        return $listed;
    }

    /**
     * The rates that moved between two reads of currencies(), in the shape of changes.
     *
     * @param array<int, array<string, mixed>> $before
     * @param array<int, array<string, mixed>> $after
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rateChanges(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $id => $currency) {
            $old = isset($before[$id]) ? (float) $before[$id]['conversion_rate'] : null;
            if ($old !== null && abs($old - (float) $currency['conversion_rate']) > 0.0000001) {
                $changes[] = [
                    'id_currency' => $id,
                    'iso_code' => (string) $currency['iso_code'],
                    'field' => 'conversion_rate',
                    'old' => $old,
                    'new' => (float) $currency['conversion_rate'],
                ];
            }
        }

        return $changes;
    }

    /**
     * The currencies of the shop as somcp_list_currencies answers them, keyed by identifier.
     *
     * Public so somcp_update_localization_settings compares the rates of the
     * same rows when a new default currency resets them.
     *
     * @param int $idShop
     *
     * @return array<int, array<string, mixed>>
     */
    public static function currencies(int $idShop): array
    {
        $language = SomcpTool::readLanguage([], $idShop);
        $idLang = $language !== null ? $language[0] : (int) Configuration::get('PS_LANG_DEFAULT');
        $query = SomcpToolListCurrencies::query($idShop, $idLang);
        $query->orderBy('c.`id_currency` ASC');
        $default = SomcpToolListCurrencies::defaultCurrency($idShop);
        $currencies = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $currency = SomcpToolListCurrencies::row($row, $default);
            $currencies[(int) $currency['id_currency']] = $currency;
        }

        return $currencies;
    }
}
