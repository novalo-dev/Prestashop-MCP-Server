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
 * somcp_get_country - one country of International > Locations > Countries,
 * as its Edit page shows it.
 *
 * The list leaves out three fields of the form: the default currency, the
 * address format and the tax label. A change of a country starts from what
 * is stored, so this read gives every field, the keys of the list row first
 * so a country reads the same in both tools.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// country is found, and its common fields built, as the list builds them.
require_once __DIR__ . '/SomcpToolListCountries.php';

class SomcpToolGetCountry
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_country',
            Somcp::t('Read a country'),
            'Reads one country of International > Locations > Countries as its Edit page shows it: the fields of '
                . 'somcp_list_countries, plus the default currency, the address format (as stored and as the '
                . 'storefront applies it), Display tax label and the number of states. Take id_country from '
                . 'somcp_list_countries. For many countries use somcp_list_countries; for the states of a '
                . 'country, somcp_list_states.',
            [
                'type' => 'object',
                'properties' => [
                    'id_country' => [
                        'type' => 'integer',
                        'description' => 'Required. The country (ID), from somcp_list_countries.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, for the '
                            . 'name; a code the shop does not have is refused. The shop default language when left '
                            . 'out.',
                    ],
                ],
                'required' => ['id_country'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_READ],
            [self::class, 'handle'],
            // The page a merchant opens to look at a country, as the list.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminCountries')
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

        $errors = SomcpTool::unknownFields($arguments, ['id_country', 'language']);
        if ($language === null) {
            $errors[] = SomcpTool::languageProblem($idShop);
        }
        $row = null;
        $idCountry = SomcpTool::id($arguments, 'id_country');
        if ($idCountry === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_country');
        } elseif ($language !== null) {
            // Only a country country_shop ties to the shop of the token, as
            // the list filters them.
            $row = SomcpToolListCountries::country($idShop, $language[0], $idCountry);
            if ($row === null) {
                $errors[] = SomcpTool::notFound('id_country', 'country', $idCountry, 'somcp_list_countries');
            }
        }
        if ($errors !== [] || $row === null) {
            return SomcpTool::refused('read', $errors);
        }

        $country = new Country($idCountry);
        $idCurrency = (int) $country->id_currency;
        $currency = $idCurrency > 0 ? (string) Currency::getIsoCodeById($idCurrency) : '';

        // Read from the table on both versions: the 9.0.0 form shows the
        // field empty, its reading of the format left commented out.
        $stored = Db::getInstance()->getValue(
            'SELECT `format` FROM `' . _DB_PREFIX_ . 'address_format` WHERE `id_country` = ' . (int) $idCountry
        );

        $result = ['language' => $language[1]] + $row + [
            // 0 is Default store currency, as stored, so a write can give it back.
            'id_currency' => $idCurrency,
            'currency' => $currency !== '' ? $currency : null,
            'address_format' => is_string($stored) ? self::lines($stored) : null,
            // What the storefront applies: the default country's format when
            // the country has none, and the dni line when it needs one.
            'address_format_used' => self::lines((string) AddressFormat::getAddressCountryFormat($idCountry)),
            'display_tax_label' => (bool) $country->display_tax_label,
            // So the assistant knows whether somcp_list_states has anything
            // to show for this country.
            'states' => (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'state` WHERE `id_country` = ' . (int) $idCountry
            ),
        ];

        return SomcpTool::json($result);
    }

    /**
     * The lines of an address format, without the empty ones.
     *
     * @param string $format
     *
     * @return array<int, string>
     */
    private static function lines(string $format): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $format) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
