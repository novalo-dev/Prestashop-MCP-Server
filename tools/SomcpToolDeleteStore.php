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
 * somcp_delete_store - the Delete action of a store of Shop Parameters >
 * Contact, tab Stores.
 *
 * AdminStoresController really deletes ($this->deleted is false): the
 * object removes the rows of the shop of the token, then the store once no
 * shop holds it. The core leaves its picture on disk; the tool removes it
 * then, as nothing would ever read or remove it again.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolCreateStore.php';

class SomcpToolDeleteStore
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_store',
            Somcp::t('Delete a store'),
            'Deletes a store of Shop Parameters > Contact > Stores, as its Delete action: one store per call, Delete '
                . 'selected being the same call repeated, its picture with it. Answers the store as it was. Cannot '
                . 'be undone: ask first. To hide a store without losing it use somcp_update_store with active '
                . 'false; for the id_store use somcp_list_stores.',
            [
                'type' => 'object',
                'properties' => [
                    'id_store' => [
                        'type' => 'integer',
                        'description' => 'The store to delete, from somcp_list_stores.',
                    ],
                ],
                'required' => ['id_store'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminStores')
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
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = SomcpTool::unknownFields($arguments, ['id_store']);

        $store = null;
        $idStore = SomcpTool::id($arguments, 'id_store');
        if (!array_key_exists('id_store', $arguments)) {
            $errors[] = 'id_store: required - find it with somcp_list_stores';
        } elseif ($idStore === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_store');
        } else {
            $loaded = new Store($idStore);
            // A store of another shop is answered as unknown, as the list of
            // this shop does not show it.
            if (Validate::isLoadedObject($loaded) && $loaded->isAssociatedToShop($idShop)) {
                $store = $loaded;
            } else {
                $errors[] = SomcpTool::notFound('id_store', 'store', $idStore, 'somcp_list_stores');
            }
        }

        if ($errors !== [] || $store === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        // Read before it goes: the answer is what it takes to create it again.
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $before = SomcpToolListStores::read($idStore, $idLang, $idShop);

        // The shop of the token only: the core removes the store itself once
        // no shop holds it any more.
        $store->id_shop_list = [$idShop];
        try {
            $done = (bool) $store->delete();
            $failure = $done ? '' : 'PrestaShop refused to delete it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('store_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'store ' . $idStore . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text(
                'The store could not be deleted: ' . $failure . '. Read it with somcp_list_stores before calling again.',
                true
            );
        }
        // The identifier only: the rest is an address.
        SomcpLogger::log('store_deleted', SomcpLogger::OUTCOME_SUCCESS, 'store ' . $idStore, '', $idEmployee);

        $result = [
            'deleted' => true,
            'id_store' => $idStore,
            'store' => $before,
        ];
        $warnings = [];
        $db = Db::getInstance();
        $others = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'store_shop` WHERE `id_store` = ' . (int) $idStore
        );
        if ($others > 0) {
            $warnings[] = 'The store was removed from this shop; ' . $others . ' other shops still show it.';
        } elseif (!(bool) $db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'store` WHERE `id_store` = ' . (int) $idStore)) {
            // The picture goes with the store, not before: another shop still
            // showing it still shows its picture.
            $removed = SomcpToolCreateManufacturer::removeLogoIn($idStore, _PS_STORE_IMG_DIR_, 'stores', 'store');
            if (!empty($removed['refused'])) {
                $warnings[] = 'The store is deleted, but ' . $removed['refused'] . '.';
            }
        }
        $left = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'store_shop` WHERE `id_shop` = ' . (int) $idShop
        );
        if ($left === 0) {
            $warnings[] = 'The shop has no store left: the storefront page of the stores is empty.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }
}
