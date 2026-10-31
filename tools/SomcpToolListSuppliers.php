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
 * somcp_list_suppliers - the suppliers of this shop, as the Suppliers list of
 * the back office: identifier, name, enabled, number of products.
 *
 * Kept to the columns of that list, like somcp_list_manufacturers: it is the
 * list an assistant reads before a call that takes a supplier - a product
 * created or changed - so that it never guesses an identifier. Everything else
 * about one supplier, its logo included, is the job of somcp_get_supplier.
 *
 * It takes no language: a supplier name is not translated in PrestaShop, it
 * sits in the supplier table itself, so a language would change nothing.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListSuppliers
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_suppliers',
            Somcp::t('List suppliers'),
            'Lists the suppliers of this shop, as the Suppliers list of the back office: identifier, '
                . 'name, whether it is enabled (active) and its number of products. Every supplier '
                . 'matching the filters is returned, sorted by name. '
                . 'Call it before any call that takes a supplier - creating or changing a product - and '
                . 'take the identifier from here: supplier identifiers are specific to each shop, so '
                . 'never assume one. Suppliers that are switched off are listed too unless active is '
                . 'given, since a product can still point to them. Everything else about one supplier, '
                . 'its logo included, is read with somcp_get_supplier. Do not use it to find the '
                . 'products of a supplier: it lists suppliers only.',
            [
                'type' => 'object',
                'properties' => [
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true for enabled suppliers only, false for disabled ones only. Left out, all of them.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Part of the supplier name, case-insensitive.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Suppliers are managed on their own back office page, and that is
            // the page a merchant would open to look at them.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminSuppliers')
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

        // A query rather than Supplier::getSuppliers(), which reads the shop
        // from the global context and leaves inactive suppliers out unless
        // asked: here the shop comes from the token, and a supplier switched
        // off must still be listed unless the filter says otherwise. Same
        // order as the core.
        $query = new DbQuery();
        $query->select('s.`id_supplier`, s.`name`, s.`active`');
        $query->from('supplier', 's');
        $query->innerJoin('supplier_shop', 'ss', 'ss.`id_supplier` = s.`id_supplier` AND ss.`id_shop` = ' . $idShop);
        // The same two filters as the list: Enabled (All / Yes / No) and Name.
        if (isset($arguments['active']) && is_bool($arguments['active'])) {
            $query->where('s.`active` = ' . (int) $arguments['active']);
        }
        $name = isset($arguments['name']) && is_scalar($arguments['name']) ? trim((string) $arguments['name']) : '';
        if ($name !== '') {
            $query->where("s.`name` LIKE '%" . pSQL($name) . "%'");
        }
        $query->orderBy('s.`name` ASC, s.`id_supplier` ASC');

        $suppliers = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $idSupplier = (int) $row['id_supplier'];
            $suppliers[] = [
                'id_supplier' => $idSupplier,
                'name' => (string) ($row['name'] ?? ''),
                'active' => (bool) $row['active'],
                // Counted the way the page of one supplier counts them.
                'products_count' => SomcpToolGetSupplier::productsCount($idSupplier, $idShop),
            ];
        }

        return SomcpTool::json([
            'suppliers' => $suppliers,
            'count' => count($suppliers),
        ]);
    }
}
