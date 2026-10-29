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
 * somcp_set_supplier_active - the Enabled toggle of the back office supplier
 * list.
 *
 * Kept apart from somcp_update_supplier on purpose. "Disable this supplier"
 * has to touch Enabled and nothing else, and a tool that can rewrite a whole
 * supplier, its address included, is one mistaken argument away from doing
 * more. This one cannot.
 *
 * The core's own sequence, read from ToggleSupplierStatusHandler on 8.0.0 and
 * 9.0.0: Supplier::toggleStatus(), which flips the active field and saves the
 * supplier narrowed to it, so the object hooks fire. The same narrowed save is
 * used here, but the value asked for is written rather than flipped, so a call
 * that crossed another change never undoes it.
 *
 * Which suppliers exist in the shop of the token is decided by
 * somcp_get_supplier's own test, so the supplier tools never disagree on what
 * may be touched.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// test of the shop lives in that class.
require_once __DIR__ . '/SomcpToolGetSupplier.php';

class SomcpToolSetSupplierActive
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_set_supplier_active',
            Somcp::t('Enable or disable a supplier'),
            'Enables or disables one supplier of this shop, exactly like the Enabled toggle of the back office '
                . 'supplier list, and changes nothing else. Disabling a supplier hides its page on the '
                . 'storefront; it stays attached to its products, with their supplier references and purchase '
                . 'prices, so tell the merchant. It is reversible: it is not a deletion. Find the supplier with '
                . 'somcp_list_suppliers first and use the identifier it returns; never guess one. When the '
                . 'supplier already has the state asked for, nothing is written and the answer says '
                . 'changed: false. On a multistore installation, PrestaShop keeps Enabled once for every shop '
                . 'sharing the supplier, so the change shows in each of them. Do not use it to change anything '
                . 'else about a supplier (somcp_update_supplier). One supplier per call: for several, call it '
                . 'once per supplier and report each result. Not to be retried on a lost answer: read the '
                . 'supplier with somcp_get_supplier to see whether the change went through.',
            [
                'type' => 'object',
                'properties' => [
                    'id_supplier' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the supplier, taken from somcp_list_suppliers.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true to enable the supplier, false to disable it.',
                    ],
                ],
                'required' => ['id_supplier', 'active'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // The toggle sits on the suppliers page, and it takes the edit
            // right there: disabling a supplier is a change, not a deletion.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminSuppliers')
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

        // A boolean only: a string such as "false" cast to bool is true, and
        // would enable a supplier the merchant asked to disable.
        if (!array_key_exists('active', $arguments) || !is_bool($arguments['active'])) {
            return SomcpTool::text('Nothing was changed: active must be true or false.', true);
        }
        $active = $arguments['active'];

        $supplier = SomcpToolGetSupplier::existingSupplier(self::identifier($arguments['id_supplier'] ?? null), $idShop);
        if ($supplier === null) {
            return SomcpTool::text(
                'No supplier of this shop has this identifier. Nothing was changed. Find it with somcp_list_suppliers.',
                true
            );
        }

        if ((bool) $supplier->active === $active) {
            return SomcpTool::json([
                'changed' => false,
                'id_supplier' => (int) $supplier->id,
                'active' => $active,
                'reason' => $active
                    ? 'The supplier is already enabled. Nothing was written.'
                    : 'The supplier is already disabled. Nothing was written.',
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($supplier, $active, $idShop, $idEmployee): array {
            return self::apply($supplier, $active, $idShop, $idEmployee);
        });
    }

    /**
     * Writes Enabled, and only that.
     *
     * @param Supplier $supplier
     * @param bool $active
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Supplier $supplier, bool $active, int $idShop, int $idEmployee): array
    {
        $supplier->active = $active;
        // The single field the toggle stands for, as toggleStatus() does, so
        // the supplier's texts as loaded are not written back over a change
        // made since.
        $supplier->setFieldsToUpdate(['active' => true]);
        // The shop of the token only: left to the context's list of shops,
        // update() can attach the supplier to a shop it was never in.
        $supplier->id_shop_list = [$idShop];

        try {
            $done = $supplier->update();
        } catch (Throwable $e) {
            $done = false;
        }

        if (!$done) {
            SomcpLogger::log(
                'supplier_status_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'supplier ' . (int) $supplier->id . ' to ' . ($active ? 'enabled' : 'disabled'),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'Enabled could not be changed. Read the supplier with somcp_get_supplier to see what it holds '
                    . 'before calling again.',
                true
            );
        }

        SomcpLogger::log(
            'supplier_status_changed',
            SomcpLogger::OUTCOME_SUCCESS,
            'supplier ' . (int) $supplier->id . ' to ' . ($active ? 'enabled' : 'disabled'),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'changed' => true,
            'id_supplier' => (int) $supplier->id,
            'active' => $active,
        ]);
    }

    /**
     * A supplier identifier as a client may send it: an integer, or digits in a string.
     *
     * A cast would read an array as 1 and act on a supplier nobody named.
     *
     * @param mixed $value
     *
     * @return int
     */
    private static function identifier($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d{1,10}$/', trim($value))) {
            return (int) trim($value);
        }

        return 0;
    }
}
