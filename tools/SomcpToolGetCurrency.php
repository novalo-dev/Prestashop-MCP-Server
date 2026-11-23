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
 * somcp_get_currency - one currency of International > Localization >
 * Currencies, as its Edit page shows it.
 *
 * The list leaves out what the form holds beside it: Decimals, the format
 * of the language, whether the currency is an alternative one and whether
 * its names or symbols were changed from the CLDR ones. A change of a
 * currency starts from what is stored, so this read gives every field, the
 * keys of the list row first so a currency reads the same in both tools.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// currency is found, and its common fields built, as the list builds them.
require_once __DIR__ . '/SomcpToolListCurrencies.php';

class SomcpToolGetCurrency
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_currency',
            Somcp::t('Read a currency'),
            'Reads one currency of International > Localization > Currencies as its Edit page shows it: the '
                . 'fields of somcp_list_currencies, plus Decimals (precision), the numeric ISO code, whether it is '
                . 'an alternative currency, the price format of the language and whether its names or symbols '
                . 'were customized. Take id_currency from somcp_list_currencies. For many currencies use '
                . 'somcp_list_currencies; for the currency of a country, somcp_get_country.',
            [
                'type' => 'object',
                'properties' => [
                    'id_currency' => [
                        'type' => 'integer',
                        'description' => 'Required. The currency (ID), from somcp_list_currencies.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, for the '
                            . 'name, the symbol and the format; a code the shop does not have is refused. The shop '
                            . 'default language when left out.',
                    ],
                ],
                'required' => ['id_currency'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // The page a merchant opens to look at a currency, as the list.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminCurrencies')
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

        $errors = SomcpTool::unknownFields($arguments, ['id_currency', 'language']);
        if ($language === null) {
            $errors[] = SomcpTool::languageProblem($idShop);
        }
        $answer = null;
        $idCurrency = SomcpTool::id($arguments, 'id_currency');
        if ($idCurrency === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_currency');
        } elseif ($language !== null) {
            $answer = self::read($idShop, $language, $idCurrency);
            if ($answer === null) {
                $errors[] = SomcpTool::notFound('id_currency', 'currency', $idCurrency, 'somcp_list_currencies');
            }
        }
        if ($errors !== [] || $answer === null) {
            return SomcpTool::refused('read', $errors);
        }

        return SomcpTool::json($answer);
    }

    /**
     * One currency as this tool answers it, or null when the shop lists none with this identifier.
     *
     * Public so somcp_update_currency compares what it is given with this
     * very answer, and reads the currency back the same way after a change.
     *
     * @param int $idShop
     * @param array{0: int, 1: string} $language the language of the name, the symbol and the format, as SomcpTool::readLanguage() gives it
     * @param int $idCurrency
     *
     * @return array<string, mixed>|null
     */
    public static function read(int $idShop, array $language, int $idCurrency): ?array
    {
        // Only a currency currency_shop ties to the shop of the token and
        // not deleted, as the list filters them.
        $row = SomcpToolListCurrencies::currency($idShop, $language[0], $idCurrency);
        if ($row === null) {
            return null;
        }
        $numeric = (string) ($row['numeric_iso_code'] ?? '');
        $pattern = (string) ($row['pattern'] ?? '');

        return ['language' => $language[1]]
            + SomcpToolListCurrencies::row($row, SomcpToolListCurrencies::defaultCurrency($idShop))
            + [
                // Decimals of the form.
                'precision' => (int) $row['precision'],
                'numeric_iso_code' => $numeric !== '' ? $numeric : null,
                // Create an alternative currency, the words of the add page.
                'alternative' => (bool) $row['unofficial'],
                // Empty: PrestaShop formats with the CLDR pattern of the language.
                'pattern' => $pattern !== '' ? $pattern : null,
                // The names or symbols differ from the CLDR ones, which Restore
                // default settings brings back.
                'modified' => (bool) $row['modified'],
            ];
    }
}
