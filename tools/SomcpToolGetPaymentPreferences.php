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
 * somcp_get_payment_preferences - the four cards of Payment > Preferences:
 * the currencies, customer groups, countries and carriers each payment
 * module is offered to.
 *
 * A shop sells only where a payment module is offered; the tools that add a
 * country or a currency warn about this page, and this read lets the
 * assistant check it. Each table is read for the shop of the token, as the
 * core's PaymentModuleListProvider and the restriction finders read them.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: a
// carrier is stored by its reference and named as the shipping preferences
// name the default carrier.
require_once __DIR__ . '/SomcpToolGetShippingPreferences.php';

class SomcpToolGetPaymentPreferences
{
    /** @var array<int, string> The two rows of Currency restrictions that are not a currency, for a module in radio mode. */
    public const CURRENCY_CHOICES = [-1 => 'Customer currency', -2 => 'Shop default currency'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_payment_preferences',
            Somcp::t('Read the payment preferences'),
            'Reads Payment > Preferences: for each payment module, the currencies, customer groups, countries and '
                . 'carriers it is offered to, with the identifiers of somcp_list_currencies, '
                . 'somcp_list_customer_groups, somcp_list_countries and somcp_list_carriers. Read it before saying a '
                . 'shop can take payments in a country or currency. Read only. To change these lists '
                . 'of a module use somcp_update_payment_restrictions; to install, enable or configure a '
                . 'payment module use somcp_get_module or somcp_set_module_active; for the payment of an order, '
                . 'somcp_get_order.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Technical name of one payment module, as somcp_list_modules gives it, for '
                            . 'example ps_checkpayment. Every payment module when left out.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, for the '
                            . 'names of the groups and carriers. The shop default language when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_READ],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminPaymentPreferences')
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
        $language = SomcpTool::readLanguage($arguments, $idShop);

        // Every problem in one answer (#169), so the call is corrected in one go.
        $problems = SomcpTool::unknownFields($arguments, ['name', 'language']);
        if ($language === null) {
            $problems[] = SomcpTool::languageProblem($idShop);
        }
        $name = null;
        if (array_key_exists('name', $arguments) && $arguments['name'] !== null) {
            $given = is_string($arguments['name']) ? trim($arguments['name']) : '';
            $known = false;
            foreach (self::modules($idShop) as $module) {
                if (Tools::strtolower($module['name']) === Tools::strtolower($given)) {
                    $name = $module['name'];
                    $known = true;
                    break;
                }
            }
            if (!$known) {
                $problems[] = SomcpTool::notFound(
                    'name',
                    'payment module',
                    is_scalar($arguments['name']) ? (string) $arguments['name'] : '',
                    'somcp_list_modules',
                    'technical name'
                );
            }
        }
        if ($problems !== [] || $language === null) {
            return SomcpTool::refused('read', $problems);
        }

        $modules = self::preferences($idShop, $language[0], $name);
        $result = ['payment_modules' => $modules, 'language' => $language[1]];
        $warnings = [];
        if ($modules === []) {
            // The sentence of the page.
            $warnings[] = 'No payment module installed';
        }
        foreach ($modules as $module) {
            $empty = [];
            foreach (['currencies' => 'currency', 'groups' => 'group', 'countries' => 'country', 'carriers' => 'carrier'] as $list => $word) {
                if ($module[$list] === []) {
                    $empty[] = $word;
                }
            }
            if ($empty !== []) {
                // The checkout offers a module only to a cart that matches a
                // row of each table: one empty list hides it everywhere.
                $warnings[] = self::offeredToNone((string) $module['display_name'], 'is', $empty);
            }
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }

    /**
     * The installed payment modules, in the order of the page (display name).
     *
     * A payment module is an installed module whose instance is a
     * PaymentModule, enabled or not, as PaymentModuleListProvider lists the
     * columns of the page. Public so the update checks a name against the
     * same list.
     *
     * @param int $idShop
     *
     * @return array<int, array{id_module: int, name: string, display_name: string, active: bool, currencies_mode: string, limited_countries: array<int, string>|null}>
     */
    public static function modules(int $idShop): array
    {
        $modules = [];
        foreach (SomcpModules::states($idShop) as $state) {
            $row = Db::getInstance()->getRow(
                'SELECT `name` FROM `' . _DB_PREFIX_ . 'module` WHERE `id_module` = ' . (int) $state['id_module']
            );
            $name = is_array($row) ? (string) $row['name'] : '';
            if ($name === '') {
                continue;
            }
            try {
                $instance = Module::getInstanceByName($name);
            } catch (Throwable $e) {
                // A module whose code no longer loads cannot be a column of
                // the page either.
                continue;
            }
            if (!$instance instanceof PaymentModule) {
                continue;
            }
            // Cast: a module may set a single code where the core declares a list.
            $limited = array_values(array_map(static function ($iso): string {
                return Tools::strtolower((string) $iso);
            }, (array) $instance->limited_countries));
            $modules[] = [
                'id_module' => (int) $state['id_module'],
                'name' => $name,
                'display_name' => (string) $instance->displayName,
                // A row in module_shop is the module enabled in this shop.
                'active' => $state['enable_device'] !== null,
                'currencies_mode' => $instance->currencies_mode === 'radio' ? 'radio' : 'checkbox',
                'limited_countries' => $limited !== [] ? $limited : null,
            ];
        }
        usort($modules, static function (array $a, array $b): int {
            return strnatcasecmp($a['display_name'], $b['display_name']) ?: strcmp($a['name'], $b['name']);
        });

        return $modules;
    }

    /**
     * The warning for a payment module with one empty list or more, one
     * English sentence for one to four lists: "no currency or group",
     * "no currency, group, country or carrier".
     *
     * Public so the update warns with this very sentence rather than a
     * second one that could drift.
     *
     * @param string $displayName
     * @param string $verb "is", or "is now" after a change
     * @param array<int, string> $empty the words of the empty lists, in the order of the page
     *
     * @return string
     */
    public static function offeredToNone(string $displayName, string $verb, array $empty): string
    {
        $last = (string) array_pop($empty);
        $words = $empty !== [] ? implode(', ', $empty) . ' or ' . $last : $last;

        return $displayName . ' ' . $verb . ' offered to no ' . $words . ': customers cannot pay with it.';
    }

    /**
     * The restrictions of the payment modules of a shop, under the names the update takes back.
     *
     * Public so a tool that changes them compares with, and answers with,
     * this very reading rather than a second shape that could drift.
     *
     * @param int $idShop
     * @param int $idLang the language of the names of the groups and carriers
     * @param string|null $name one module, by its technical name; every one when null
     *
     * @return array<int, array<string, mixed>>
     */
    public static function preferences(int $idShop, int $idLang, ?string $name = null): array
    {
        $db = Db::getInstance();
        $out = [];
        foreach (self::modules($idShop) as $module) {
            if ($name !== null && $module['name'] !== $name) {
                continue;
            }
            $where = ' WHERE r.`id_module` = ' . (int) $module['id_module'] . ' AND r.`id_shop` = ' . (int) $idShop;

            $currencies = [];
            $rows = $db->executeS(
                'SELECT r.`id_currency`, c.`iso_code` FROM `' . _DB_PREFIX_ . 'module_currency` r'
                . ' LEFT JOIN `' . _DB_PREFIX_ . 'currency` c ON c.`id_currency` = r.`id_currency` AND c.`deleted` = 0' . $where
                . ' ORDER BY r.`id_currency` ASC'
            ) ?: [];
            foreach ($rows as $row) {
                $id = (int) $row['id_currency'];
                if (isset(self::CURRENCY_CHOICES[$id])) {
                    $currencies[] = ['id_currency' => $id, 'iso_code' => null, 'name' => self::CURRENCY_CHOICES[$id]];
                } elseif ($row['iso_code'] !== null) {
                    $currencies[] = ['id_currency' => $id, 'iso_code' => (string) $row['iso_code']];
                }
            }

            $groups = [];
            $rows = $db->executeS(
                'SELECT r.`id_group`, gl.`name` FROM `' . _DB_PREFIX_ . 'module_group` r'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'group` g ON g.`id_group` = r.`id_group`'
                . ' LEFT JOIN `' . _DB_PREFIX_ . 'group_lang` gl ON gl.`id_group` = r.`id_group` AND gl.`id_lang` = '
                . (int) $idLang . $where . ' ORDER BY r.`id_group` ASC'
            ) ?: [];
            foreach ($rows as $row) {
                $groups[] = ['id_group' => (int) $row['id_group'], 'name' => (string) $row['name']];
            }

            $countries = [];
            $rows = $db->executeS(
                'SELECT r.`id_country`, c.`iso_code` FROM `' . _DB_PREFIX_ . 'module_country` r'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'country` c ON c.`id_country` = r.`id_country`' . $where
                . ' ORDER BY r.`id_country` ASC'
            ) ?: [];
            foreach ($rows as $row) {
                $countries[] = ['id_country' => (int) $row['id_country'], 'iso_code' => (string) $row['iso_code']];
            }

            // The page stores the carrier's reference, which an edited carrier
            // keeps under a new id_carrier: the identifier given is the one
            // somcp_list_carriers lists today, and a deleted one is left out.
            $carriers = [];
            $rows = $db->executeS(
                'SELECT r.`id_reference` FROM `' . _DB_PREFIX_ . 'module_carrier` r' . $where
                . ' ORDER BY r.`id_reference` ASC'
            ) ?: [];
            foreach ($rows as $row) {
                $carrier = SomcpToolGetShippingPreferences::carrierOfReference((int) $row['id_reference'], $idShop, $idLang);
                if ($carrier !== null) {
                    $carriers[] = ['id_carrier' => (int) $carrier['id_carrier'], 'name' => (string) $carrier['name']];
                }
            }

            $out[] = [
                'name' => $module['name'],
                'display_name' => $module['display_name'],
                'active' => $module['active'],
                'currencies_mode' => $module['currencies_mode'],
                'currencies' => $currencies,
                'groups' => $groups,
                'countries' => $countries,
                'carriers' => $carriers,
                'limited_countries' => $module['limited_countries'],
            ];
        }

        return $out;
    }
}
