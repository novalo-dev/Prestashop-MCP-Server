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
 * somcp_ping - what an assistant is connected to, and on whose behalf.
 *
 * It reads and changes nothing. Its job is to give an assistant, in one call,
 * the handful of facts it needs before doing anything else: which shop it
 * reached, which versions it must write for, which language and currency to
 * read values in, whose account it acts under - and, on a multistore
 * installation, which shops this connection cannot reach.
 *
 * On multistore a token belongs to exactly one shop. The employee behind it
 * may well have access to five shops in the back office; the token still opens
 * one. Saying so here, instead of leaving an assistant to work it out from
 * empty results, is the difference between a connection the merchant
 * understands and one they distrust.
 *
 * It is also the model every other tool follows: one file, one class, a
 * define() that describes the tool and a handler that does the work.
 *
 * The text below is written for the assistant reading it, not for the
 * merchant, which is why it does not go through the translation files: a tool
 * description is part of the protocol, and an assistant works from the English
 * one whatever language the back office is in.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolPing
{
    /**
     * Describes the tool to the registry.
     *
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_ping',
            Somcp::t('Test the connection'),
            'Tests that the connection to this PrestaShop shop works, and reports what it reached: '
                . 'which shop answered, the PrestaShop version, the languages, currency and time zone '
                . 'its values are expressed in, and the employee account the connection acts under. '
                . 'On a multistore installation it also names the shops this connection cannot reach. '
                . 'Reads only, changes nothing.',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::ACCESS],
            [self::class, 'handle'],
            // No back office permission required, deliberately: this tool reads
            // no shop data, and it is how an assistant with no rights anywhere
            // can still report which account it is connected as.
            SomcpTool::actsOnNothing()
        );
    }

    /**
     * Runs the tool.
     *
     * Seven lines, and every one of them changes what the assistant does next:
     * which shop it is talking to, which versions it must write for, which
     * language and currency to read values in, whose account it acts under and
     * what that account let it do. What the module version is, what time the
     * server thinks it is, which PHP runs underneath - none of that changes an
     * assistant's behaviour, and the back office already reports it to the
     * merchant, who is the one who can act on it.
     *
     * @param array<string, mixed> $arguments none, the tool takes no parameter
     * @param array<string, mixed> $context the token's employee, client and scopes
     *
     * @return array<string, mixed>
     */
    public static function handle(array $arguments, array $context): array
    {
        $shop = Somcp::currentShop();
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $lines = [
            'shop: ' . (string) $shop->name . ' (#' . (int) $shop->id . ') - ' . (string) $shop->domain,
            'prestashop: ' . _PS_VERSION_,
            'languages: ' . self::languages($shop),
            'currency: ' . self::defaultCurrency(),
            'time zone: ' . (string) Configuration::get('PS_TIMEZONE'),
            'connected as: ' . self::employee($idEmployee),
            'scopes: ' . (string) ($context['scopes'] ?? ''),
        ];

        if (Shop::isFeatureActive()) {
            $lines[] = 'multistore: ' . self::multistore($shop, $idEmployee);
        }

        return SomcpTool::text(implode("\n", $lines));
    }

    /**
     * Active languages of this shop, the default one marked.
     *
     * The assistant reads and writes product text in one of these, so it needs
     * the codes, not how many there are.
     *
     * @param Shop $shop
     *
     * @return string
     */
    private static function languages(Shop $shop): string
    {
        // The default of this shop, the one every tool reads in when no
        // language is given (#149), not the default of the whole installation.
        $idDefault = (int) Configuration::get('PS_LANG_DEFAULT', null, null, (int) $shop->id);
        $codes = [];

        foreach (Language::getLanguages(true, (int) $shop->id) as $language) {
            $code = (string) $language['iso_code'];
            $codes[] = (int) $language['id_lang'] === $idDefault ? $code . ' (default)' : $code;
        }

        return $codes === [] ? '-' : implode(', ', $codes);
    }

    /**
     * @return string
     */
    private static function defaultCurrency(): string
    {
        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));

        return Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '-';
    }

    /**
     * The account the assistant acts under, and the ceiling of what it may do.
     *
     * The name and the profile, not the email address: the assistant does
     * nothing with an email, and it is a third party.
     *
     * @param int $idEmployee
     *
     * @return string
     */
    private static function employee(int $idEmployee): string
    {
        $employee = new Employee($idEmployee);
        if (!Validate::isLoadedObject($employee)) {
            return '#' . $idEmployee . ' (this account no longer exists)';
        }

        $profile = Profile::getProfile((int) $employee->id_profile, (int) $employee->id_lang);
        $profileName = trim((string) ($profile['name'] ?? ''));

        return trim((string) $employee->firstname . ' ' . (string) $employee->lastname)
            . ' (#' . (int) $employee->id . ')'
            . ($profileName !== '' ? ', profile ' . $profileName : '');
    }

    /**
     * What this connection reaches, and what it does not.
     *
     * Kept because it is the one line that stops an assistant from assuming a
     * second shop is within reach: it holds one token, for one shop.
     *
     * @param Shop $shop
     * @param int $idEmployee
     *
     * @return string
     */
    private static function multistore(Shop $shop, int $idEmployee): string
    {
        $sentence = 'this connection reaches ' . (string) $shop->name . ' #' . (int) $shop->id . ' only.';
        $employee = new Employee($idEmployee);
        if (!Validate::isLoadedObject($employee)) {
            return $sentence;
        }

        $others = [];
        foreach (Shop::getShops(false) as $candidate) {
            $idCandidate = (int) $candidate['id_shop'];
            if ($idCandidate === (int) $shop->id || !$employee->hasAuthOnShop($idCandidate)) {
                continue;
            }
            $others[] = (string) $candidate['name'] . ' #' . $idCandidate;
        }

        if ($others === []) {
            return $sentence;
        }

        return $sentence . ' The account can also open '
            . implode(', ', $others) . ", on that shop's own address.";
    }
}
