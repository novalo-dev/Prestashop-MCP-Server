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
 * somcp_list_manufacturers - the brands of this shop, as the Brands list of the
 * back office shows them: identifier, name, Enabled, Addresses and Products.
 *
 * It is the list an assistant reads before a call that takes a brand - a
 * product created or changed - so that it never guesses an identifier, and it
 * carries the columns of the back office list so that a switched-off brand is
 * seen without reading each one. Everything else about one brand is the job of
 * somcp_get_manufacturer.
 *
 * It takes no language: a brand name is not translated in PrestaShop, it sits
 * in the manufacturer table itself, so a language would change nothing.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListManufacturers
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_manufacturers',
            Somcp::t('List brands'),
            'Lists the brands (manufacturers) of this shop, as the Brands list of the back office: '
                . 'identifier, name, whether it is enabled (active), its number of addresses and its '
                . 'number of products. Every brand matching the filters is returned, sorted by name. '
                . 'Call it before any call that takes a brand - creating or changing a product - and '
                . 'take the identifier from here: brand identifiers are specific to each shop, so '
                . 'never assume one. Brands that are switched off are listed too unless active is '
                . 'given, since a product can still point to them. Everything else about one brand, '
                . 'its logo included, is read with somcp_get_manufacturer. Do not use it to find the '
                . 'products of a brand: it lists brands only.',
            [
                'type' => 'object',
                'properties' => [
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true for enabled brands only, false for disabled ones only. Left out, all of them.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Part of the brand name, case-insensitive.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Brands are managed on their own back office page, and that is
            // the page a merchant would open to look at them.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminManufacturers')
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

        // A query rather than Manufacturer::getManufacturers(), which reads the
        // shop from the global context and needs a name in the language asked
        // for: here the shop comes from the token, and a brand with no
        // translated texts must still be listed. Same order as the core.
        $query = new DbQuery();
        // Deleted addresses are kept by PrestaShop for the orders that used
        // them and are left out of the Addresses column, so from here too.
        $query->select('m.`id_manufacturer`, m.`name`, m.`active`, (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'address` a'
            . ' WHERE a.`id_manufacturer` = m.`id_manufacturer` AND a.`deleted` = 0) AS addresses_count');
        $query->from('manufacturer', 'm');
        $query->innerJoin('manufacturer_shop', 'ms', 'ms.`id_manufacturer` = m.`id_manufacturer` AND ms.`id_shop` = ' . $idShop);
        // The same two filters as the list: Enabled (All / Yes / No) and Name.
        if (isset($arguments['active']) && is_bool($arguments['active'])) {
            $query->where('m.`active` = ' . (int) $arguments['active']);
        }
        $name = isset($arguments['name']) && is_scalar($arguments['name']) ? trim((string) $arguments['name']) : '';
        if ($name !== '') {
            $query->where("m.`name` LIKE '%" . pSQL($name) . "%'");
        }
        $query->orderBy('m.`name` ASC, m.`id_manufacturer` ASC');

        $manufacturers = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $idManufacturer = (int) $row['id_manufacturer'];
            $manufacturers[] = [
                'id_manufacturer' => $idManufacturer,
                'name' => (string) ($row['name'] ?? ''),
                'active' => (bool) $row['active'],
                'addresses_count' => (int) $row['addresses_count'],
                // Counted the way the page of one brand counts them.
                'products_count' => SomcpToolGetManufacturer::productsCount($idManufacturer, $idShop),
            ];
        }

        return SomcpTool::json([
            'manufacturers' => $manufacturers,
            'count' => count($manufacturers),
        ]);
    }
}
