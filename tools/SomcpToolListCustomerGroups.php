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
 * somcp_list_customer_groups - the customer groups of this shop, as the
 * Groups tab of Customer Settings shows them, with the groups the shop uses
 * for visitors, guests and registered customers.
 *
 * It is the source of the id_group other tools take: the groups of a
 * customer created or changed, the group access of a category, the group of
 * a specific price. Without it an assistant guesses 3 for "customers", which
 * holds on a fresh shop and silently points elsewhere on one the merchant
 * has reorganised.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListCustomerGroups
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_customer_groups',
            Somcp::t('List customer groups'),
            'Lists the customer groups of this shop, as the Groups tab of Customer Settings: '
                . 'id_group, name, discount, price display method, whether prices are shown, members, and '
                . 'the groups the shop uses by default for visitors, guests and registered customers. '
                . 'Read only. Group identifiers differ from shop to shop: take id_group from here for the '
                . 'customer and category tools and the specific prices of products. When '
                . 'customer_groups_enabled is false, prices ignore groups. For the customers of a group '
                . 'use somcp_search_customers; for the groups of one customer somcp_get_customer.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Part of the group name, case-insensitive.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of the language to read the names in, for example fr. '
                            . 'The shop default is used otherwise. A language the shop does not have is refused.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_READ],
            [self::class, 'handle'],
            // Groups have their own back office controller, a tab of Customer
            // Settings that is not in the side menu, so the right is asked on
            // that controller itself.
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

        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;

        // The three Default groups options, read for the token's shop: one
        // group can hold several of them.
        $defaults = [];
        foreach (['visitors' => 'PS_UNIDENTIFIED_GROUP', 'guests' => 'PS_GUEST_GROUP', 'customers' => 'PS_CUSTOMER_GROUP'] as $role => $key) {
            $defaults[$role] = (int) Configuration::get($key, null, $idShopGroup, $idShop);
        }

        // A query rather than Group::getGroups(), which takes the shop from
        // the global context: here it comes from the token. Sorted by ID, as
        // the list is.
        $query = new DbQuery();
        $query->select('g.`id_group`, gl.`name`, g.`reduction`, g.`price_display_method`, g.`show_prices`, g.`date_add`');
        $query->from('group', 'g');
        $query->innerJoin('group_shop', 'gs', 'gs.`id_group` = g.`id_group` AND gs.`id_shop` = ' . $idShop);
        $query->leftJoin('group_lang', 'gl', 'gl.`id_group` = g.`id_group` AND gl.`id_lang` = ' . (int) $idLang);
        $name = isset($arguments['name']) && is_scalar($arguments['name']) ? trim((string) $arguments['name']) : '';
        if ($name !== '') {
            $query->where("gl.`name` LIKE '%" . pSQL($name) . "%'");
        }
        $query->orderBy('g.`id_group` ASC');

        $members = self::membersCount($idShop);
        $groups = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $idGroup = (int) $row['id_group'];
            $defaultFor = [];
            foreach ($defaults as $role => $idDefault) {
                if ($idDefault === $idGroup) {
                    $defaultFor[] = $role;
                }
            }
            $groups[] = [
                'id_group' => $idGroup,
                'name' => (string) ($row['name'] ?? ''),
                'reduction' => (float) $row['reduction'],
                // Stored as PS_TAX_EXC (1) or PS_TAX_INC (0); named as the
                // edit form names the choice.
                'price_display_method' => (int) $row['price_display_method'] === 1 ? 'tax_excluded' : 'tax_included',
                'show_prices' => (bool) $row['show_prices'],
                'members_count' => $members[$idGroup] ?? 0,
                'date_add' => SomcpToolSearchCustomers::isoDate($row['date_add']),
                'default_for' => $defaultFor,
            ];
        }

        return SomcpTool::json([
            'groups' => $groups,
            'count' => count($groups),
            'customer_groups_enabled' => (bool) Configuration::get('PS_GROUP_FEATURE_ACTIVE', null, $idShopGroup, $idShop),
            'language' => $iso,
            'language_id' => $idLang,
        ]);
    }

    /**
     * The Members column, per group: counted as AdminGroupsController counts
     * it - deleted customers left out, only the customers the shop shares -
     * but with the sharing of the token's shop rather than the context's.
     *
     * @param int $idShop
     *
     * @return array<int, int>
     */
    private static function membersCount(int $idShop): array
    {
        $shops = array_map('intval', Shop::getSharedShops($idShop, Shop::SHARE_CUSTOMER) ?: [$idShop]);

        $query = new DbQuery();
        $query->select('cg.`id_group`, COUNT(cg.`id_customer`) AS `members`');
        $query->from('customer_group', 'cg');
        $query->innerJoin('customer', 'c', 'c.`id_customer` = cg.`id_customer`');
        $query->where('c.`deleted` != 1');
        $query->where('c.`id_shop` IN (' . implode(',', $shops) . ')');
        $query->groupBy('cg.`id_group`');

        $counts = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $counts[(int) $row['id_group']] = (int) $row['members'];
        }

        return $counts;
    }
}
