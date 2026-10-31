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
 * somcp_set_manufacturer_active - the Enabled toggle of the back office brand
 * list.
 *
 * Kept apart from somcp_update_manufacturer on purpose. "Disable this brand"
 * has to touch Enabled and nothing else, and a tool that can rewrite a whole
 * brand is one mistaken argument away from doing more. This one cannot.
 *
 * The core's own sequence, read from ToggleManufacturerStatusHandler on 8.0.0
 * and 9.0.0: the active field set, then the brand saved, which fires the
 * object hooks. The save is narrowed here to that one field, so a text another
 * process changed since the brand was loaded is not written back over. The
 * value asked for is written rather than flipped, so a call that crossed
 * another change never undoes it.
 *
 * Which brands exist in the shop of the token is decided by
 * somcp_get_manufacturer's own test, so the brand tools never disagree on what
 * may be touched.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// test of the shop lives in that class.
require_once __DIR__ . '/SomcpToolGetManufacturer.php';

class SomcpToolSetManufacturerActive
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_set_manufacturer_active',
            Somcp::t('Enable or disable a brand'),
            'Enables or disables one brand (manufacturer) of this shop, exactly like the Enabled toggle of the '
                . 'back office brand list, and changes nothing else. Disabling a brand hides its page on the '
                . 'storefront; its products stay on sale and keep their brand, so tell the merchant. It is '
                . 'reversible: it is not a deletion. Find the brand with somcp_list_manufacturers first and use '
                . 'the identifier it returns; never guess one. When the brand already has the state asked for, '
                . 'nothing is written and the answer says changed: false. On a multistore installation, '
                . 'PrestaShop keeps Enabled once for every shop sharing the brand, so the change shows in each '
                . 'of them. Do not use it to change anything else about a brand (somcp_update_manufacturer). One '
                . 'brand per call: for several, call it once per brand and report each result. Not to be retried '
                . 'on a lost answer: read the brand with somcp_get_manufacturer to see whether the change went '
                . 'through.',
            [
                'type' => 'object',
                'properties' => [
                    'id_manufacturer' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the brand, taken from somcp_list_manufacturers.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true to enable the brand, false to disable it.',
                    ],
                ],
                'required' => ['id_manufacturer', 'active'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // The toggle sits on the brands page, and it takes the edit right
            // there: disabling a brand is a change, not a deletion.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminManufacturers')
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
        // would enable a brand the merchant asked to disable.
        if (!array_key_exists('active', $arguments) || !is_bool($arguments['active'])) {
            return SomcpTool::text('Nothing was changed: active must be true or false.', true);
        }
        $active = $arguments['active'];

        $manufacturer = SomcpToolGetManufacturer::existingManufacturer(self::identifier($arguments['id_manufacturer'] ?? null), $idShop);
        if ($manufacturer === null) {
            return SomcpTool::text(
                'No brand of this shop has this identifier. Nothing was changed. Find it with somcp_list_manufacturers.',
                true
            );
        }

        if ((bool) $manufacturer->active === $active) {
            return SomcpTool::json([
                'changed' => false,
                'id_manufacturer' => (int) $manufacturer->id,
                'active' => $active,
                'reason' => $active
                    ? 'The brand is already enabled. Nothing was written.'
                    : 'The brand is already disabled. Nothing was written.',
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($manufacturer, $active, $idShop, $idEmployee): array {
            return self::apply($manufacturer, $active, $idShop, $idEmployee);
        });
    }

    /**
     * Writes Enabled, and only that.
     *
     * @param Manufacturer $manufacturer
     * @param bool $active
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(Manufacturer $manufacturer, bool $active, int $idShop, int $idEmployee): array
    {
        $manufacturer->active = $active;
        // The single field the toggle stands for, so the brand's texts as
        // loaded are not written back over a change made since.
        $manufacturer->setFieldsToUpdate(['active' => true]);
        // The shop of the token only: left to the context's list of shops,
        // update() can attach the brand to a shop it was never in.
        $manufacturer->id_shop_list = [$idShop];

        try {
            $done = $manufacturer->update();
        } catch (Throwable $e) {
            $done = false;
        }

        if (!$done) {
            SomcpLogger::log(
                'manufacturer_status_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'brand ' . (int) $manufacturer->id . ' to ' . ($active ? 'enabled' : 'disabled'),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'Enabled could not be changed. Read the brand with somcp_get_manufacturer to see what it holds '
                    . 'before calling again.',
                true
            );
        }

        SomcpLogger::log(
            'manufacturer_status_changed',
            SomcpLogger::OUTCOME_SUCCESS,
            'brand ' . (int) $manufacturer->id . ' to ' . ($active ? 'enabled' : 'disabled'),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'changed' => true,
            'id_manufacturer' => (int) $manufacturer->id,
            'active' => $active,
        ]);
    }

    /**
     * A brand identifier as a client may send it: an integer, or digits in a string.
     *
     * A cast would read an array as 1 and act on a brand nobody named.
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
