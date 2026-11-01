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
 * SomcpCatalog - how the catalogue tools read or write a stored value the same way.
 *
 * Several tools return the same product values, or move items of the catalogue
 * the same way. Each rule lives here once, so a value read with one tool never
 * shows differently with another, and two tools never write it differently.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpCatalog
{
    /**
     * A stored low stock level, or null when the merchant set none.
     *
     * The column is nullable and null means no alert; reading it as 0 would
     * tell the assistant an alert is set at zero.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    public static function threshold($value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * Whether the merchant ticked the email alert at the low stock level.
     *
     * Read the same way for a product (product_shop) and a combination
     * (product_attribute_shop), so somcp_get_product and somcp_get_stock never
     * disagree on it.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public static function lowStockAlert($value): bool
    {
        return (bool) (int) $value;
    }

    /**
     * The rate of the ecotax, in percent, in the shop's default country.
     *
     * The ecotax has its own tax rule, set in the shop's tax settings, and the
     * back office applies it with the rate of the default country, as for the
     * product price. 0 when the shop has taxes switched off or the rule has
     * nothing for that country.
     *
     * @param int $idShop
     *
     * @return float
     */
    public static function ecotaxRate(int $idShop): float
    {
        if (!Configuration::get('PS_TAX', null, null, $idShop)) {
            return 0.0;
        }
        $rates = TaxRulesGroup::getAssociatedTaxRatesByIdCountry(
            (int) Configuration::get('PS_COUNTRY_DEFAULT', null, null, $idShop)
        );

        return (float) ($rates[(int) Configuration::get('PS_ECOTAX_TAX_RULES_GROUP_ID', null, null, $idShop)] ?? 0);
    }

    /**
     * Whether the running version gives feature values a position.
     *
     * PrestaShop 9 added feature_value.position, with the back office order
     * and the Rearrange button that go with it; PrestaShop 8 has neither. The
     * tools that list, read or move feature values all ask here, so they never
     * disagree on which versions have it.
     *
     * @return bool
     */
    public static function featureValuesHavePosition(): bool
    {
        return version_compare(_PS_VERSION_, '9.0.0', '>=');
    }

    /**
     * Moves an object to a new place in its list, as the drag and drop of the back office does.
     *
     * The core's updatePosition() shifts the others, as the list does. Some
     * versions of it look up the object being moved, or its parent, from the
     * request the back office list sends rather than from the object, so the
     * caller names those request values and they are set for the call only:
     * the request of this module never carries them, and without them the core
     * would move another row or refuse. Every tool that reorders a catalogue
     * list is meant to come through here, so they agree on how a place is
     * written.
     *
     * @param ObjectModel $object loaded, with an updatePosition($direction, $position) method
     * @param int $current where it stands now
     * @param int $position where it goes
     * @param array<string, int> $request request values the core reads during the move
     *
     * @return string empty on success, the reason otherwise
     */
    public static function movePosition(ObjectModel $object, int $current, int $position, array $request = []): string
    {
        if ($current === $position) {
            return '';
        }
        if (!method_exists($object, 'updatePosition')) {
            return 'PrestaShop gives this object no position';
        }

        $saved = [];
        foreach ($request as $key => $value) {
            $saved[$key] = [
                'get' => array_key_exists($key, $_GET) ? $_GET[$key] : null,
                'post' => array_key_exists($key, $_POST) ? $_POST[$key] : null,
            ];
            // Both, since Tools::getValue() reads POST before GET.
            $_GET[$key] = (string) (int) $value;
            $_POST[$key] = (string) (int) $value;
        }

        try {
            $failure = $object->updatePosition($position > $current, $position) ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $failure = $e->getMessage();
        } finally {
            foreach ($saved as $key => $was) {
                if ($was['get'] === null) {
                    unset($_GET[$key]);
                } else {
                    $_GET[$key] = $was['get'];
                }
                if ($was['post'] === null) {
                    unset($_POST[$key]);
                } else {
                    $_POST[$key] = $was['post'];
                }
            }
        }

        // The object is kept for the rest of the request under its id, and a
        // read-back must show the new place rather than the one loaded before.
        Cache::clean('objectmodel_' . get_class($object) . '_' . (int) $object->id . '_*');

        return $failure;
    }
}
