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
 * somcp_get_customer_group - one customer group of Shop Parameters >
 * Customer Settings > Groups, as its Edit form shows it.
 *
 * The fields the list gives are read by the list's own query, so a group
 * reads the same in both; the form adds the name in every language, the
 * Category discount rows and the Authorized modules, read the way
 * AdminGroupsController reads them on 8.0 and 9.0 alike.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolListCustomerGroups.php';

class SomcpToolGetCustomerGroup
{
    /** Public so the update of a group warns with the very same sentence. */
    public const GROUPS_DISABLED = 'Customer groups are disabled in Advanced Parameters > Performance: the '
        . 'discounts of this group do not apply.';

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_customer_group',
            Somcp::t('Get a customer group'),
            'Reads one customer group of Shop Parameters > Customer Settings > Groups, as its Edit form: the '
                . 'fields of somcp_list_customer_groups, plus the name in every language, the Category discount '
                . 'rows (category and percent) and the Authorized modules, by technical name. Read only. For the '
                . 'list of groups use somcp_list_customer_groups, for the customers of a group '
                . 'somcp_search_customers.',
            [
                'type' => 'object',
                'properties' => [
                    'id_group' => [
                        'type' => 'integer',
                        'description' => 'The group to read, from somcp_list_customer_groups.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, for '
                            . 'name and the category names; names holds every language. Refused when the shop '
                            . 'lacks it. The shop default when left out.',
                    ],
                ],
                'required' => ['id_group'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_READ],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminGroups')
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
        $shop = Somcp::currentShop();
        $idShop = (int) $shop->id;
        $idShopGroup = (int) $shop->id_shop_group;

        // Every mistake of the call in one refusal, so it is corrected in one go.
        $errors = SomcpTool::unknownFields($arguments, ['id_group', 'language']);
        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            $errors[] = SomcpTool::languageProblem($idShop);
        }
        $idGroup = SomcpTool::id($arguments, 'id_group');
        if (!array_key_exists('id_group', $arguments)) {
            $errors[] = 'id_group: required - find it with somcp_list_customer_groups';
        } elseif ($idGroup === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_group');
        } elseif (!self::inShop($idGroup, $idShop)) {
            // A group of another shop is unknown here, as this shop's list
            // does not show it.
            $errors[] = SomcpTool::notFound('id_group', 'customer group', $idGroup, 'somcp_list_customer_groups');
        }
        if ($errors !== [] || $language === null) {
            return SomcpTool::refused('read', $errors);
        }
        [$idLang, $iso] = $language;

        $listed = SomcpToolListCustomerGroups::groups($idShop, $idShopGroup, (int) $idLang, ['id_group' => $idGroup]);
        $group = ($listed[0] ?? []) + [
            'names' => self::names($idGroup, $idShop),
            'category_discounts' => self::categoryDiscounts($idGroup, (int) $idLang),
        ] + self::modules($idGroup, $idShop);

        $enabled = (bool) Configuration::get('PS_GROUP_FEATURE_ACTIVE', null, $idShopGroup, $idShop);
        $result = [
            'group' => $group,
            'customer_groups_enabled' => $enabled,
            'language' => $iso,
        ];
        if (!$enabled) {
            $result['warning'] = self::GROUPS_DISABLED;
        }

        return SomcpTool::json($result);
    }

    /**
     * Whether the shop of the token has the group (group_shop), as its list
     * shows only those.
     *
     * Public so the update of a group refuses the same groups.
     *
     * @param int $idGroup
     * @param int $idShop
     *
     * @return bool
     */
    public static function inShop(int $idGroup, int $idShop): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'group_shop` WHERE `id_group` = ' . $idGroup
                . ' AND `id_shop` = ' . $idShop
        );
    }

    /**
     * The Name field in every active language, keyed by ISO code.
     *
     * @param int $idGroup
     * @param int $idShop
     *
     * @return array<string, string>
     */
    private static function names(int $idGroup, int $idShop): array
    {
        $stored = [];
        foreach (Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'group_lang` WHERE `id_group` = ' . $idGroup
        ) ?: [] as $row) {
            $stored[(int) $row['id_lang']] = (string) $row['name'];
        }
        $names = [];
        foreach (SomcpTool::activeLanguages($idShop) as $idLanguage => $code) {
            $names[$code] = $stored[$idLanguage] ?? '';
        }

        return $names;
    }

    /**
     * The Category discount rows, read as the form reads them.
     *
     * Stored as a fraction (0.1), shown as a percent (10): answered as shown.
     * A row whose category is gone keeps a null name, as the form shows an
     * empty path rather than hiding the row.
     *
     * @param int $idGroup
     * @param int $idLang
     *
     * @return array<int, array{id_category: int, category: string|null, reduction: float}>
     */
    private static function categoryDiscounts(int $idGroup, int $idLang): array
    {
        $rows = [];
        foreach (GroupReduction::getGroupReductions($idGroup, $idLang) ?: [] as $row) {
            $rows[] = [
                'id_category' => (int) $row['id_category'],
                'category' => isset($row['category_name']) ? (string) $row['category_name'] : null,
                'reduction' => round((float) $row['reduction'] * 100, 2),
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            return $a['id_category'] <=> $b['id_category'];
        });

        return $rows;
    }

    /**
     * The Yes / No of Authorized modules for the shop of the token, by
     * technical name: the installed modules split in two lists.
     *
     * @param int $idGroup
     * @param int $idShop
     *
     * @return array{authorized_modules: array<int, string>, unauthorized_modules: array<int, string>}
     */
    private static function modules(int $idGroup, int $idShop): array
    {
        $authorized = [];
        foreach (Module::getAuthorizedModules($idGroup, [$idShop]) ?: [] as $row) {
            $authorized[(int) $row['id_module']] = true;
        }
        $yes = [];
        $no = [];
        foreach (Module::getModulesInstalled() ?: [] as $row) {
            $name = (string) $row['name'];
            if (isset($authorized[(int) $row['id_module']])) {
                $yes[] = $name;
            } else {
                $no[] = $name;
            }
        }
        sort($yes);
        sort($no);

        return ['authorized_modules' => $yes, 'unauthorized_modules' => $no];
    }
}
