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
 * somcp_delete_order_status - deletes one order status the merchant created,
 * as the Delete action of the back office order status list does.
 *
 * The deletion is soft on PrestaShop 8 and 9 alike: the row stays and
 * `deleted` is set to 1, so the orders that had it keep their history and
 * their current status. OrderState::getOrderStates() leaves a deleted status
 * out, so it disappears from the list and from the choices of
 * somcp_set_order_status. The icon file is kept.
 *
 * Only a status the merchant created can go. The 14 of PrestaShop and the
 * ones payment modules add are `unremovable`, and neither back office offers
 * Delete on them - in somcp_list_order_statuses these are the rows with
 * built_in: true. Neither handler leaves the check to the list, so the tool
 * refuses them itself, before any question of use.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in:
// the list-cache reset lives in the creation tool, on the same page.
require_once __DIR__ . '/SomcpToolCreateOrderStatus.php';

class SomcpToolDeleteOrderStatus
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_order_status',
            Somcp::t('Delete an order status'),
            'Deletes one order status the merchant created, as the Delete action of the order status list of '
                . 'Shop Parameters > Order Settings > Statuses. The orders that have it keep it; it leaves the '
                . 'list and the choices of somcp_set_order_status. status_id from somcp_list_order_statuses, '
                . 'never guessed, and have the merchant confirm it by name. The statuses of PrestaShop and of '
                . 'modules (built_in) are refused. Not for return statuses; use somcp_delete_return_status. One '
                . 'status per call. Do not retry on a lost answer: read somcp_list_order_statuses to see whether '
                . 'it is gone.',
            [
                'type' => 'object',
                'properties' => [
                    'status_id' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the order status, from somcp_list_order_statuses.',
                    ],
                ],
                'required' => ['status_id'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The right the back office checks on its delete action for an
            // order status, on the statuses page: edit alone does not allow it.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminStatuses')
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

        // Refused, not ignored: a second call for a status already deleted
        // must not read as a success. A deleted status is no longer listed,
        // so it is answered like one that was never there.
        $status = self::locate(SomcpTool::id($arguments, 'status_id'));
        if ($status === null) {
            return SomcpTool::text(
                'No order status of this shop has this identifier. Nothing was deleted. Find it with '
                    . 'somcp_list_order_statuses.',
                true
            );
        }

        $idOrderState = (int) $status->id;
        // Read before deleting, in the shop default language: the merchant
        // needs the name to know which status went.
        $name = self::defaultName($status);

        // The 14 of PrestaShop and the ones modules add are unremovable, and
        // neither back office offers Delete on them; this is what the list
        // reports as built_in. Refused here before any question of use.
        if ((bool) $status->unremovable) {
            return SomcpTool::text(
                'Order status ' . $idOrderState . ' (' . $name . ') is a built-in status of PrestaShop or of a '
                    . 'module, and neither back office offers Delete on those. Nothing was deleted.',
                true
            );
        }

        // How many orders of the shop still have it as their current status:
        // they keep it, and the merchant should know before it leaves the list.
        $ordersWithIt = self::ordersWithStatus($idOrderState, $idShop);

        // Soft, as both versions do it (9.0.0 sets deleted then update()): the
        // row stays so the orders that had it keep their history, and the icon
        // file is left in place.
        $status->deleted = true;
        try {
            $done = (bool) $status->update();
        } catch (Throwable $e) {
            $done = false;
        }

        // Asked of the shop rather than inferred from update(): the same read
        // as the list tool, so "deleted" means the merchant no longer sees it.
        $gone = self::locate($idOrderState) === null;

        if (!$done || !$gone) {
            SomcpLogger::log(
                'order_status_delete_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'order status ' . $idOrderState . ($gone ? ' removed, clean-up incomplete' : ' still in the shop'),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                $gone
                    ? 'The order status was deleted, but PrestaShop reported a problem while doing it. Tell the '
                        . 'merchant; there is nothing to call again.'
                    : 'The order status could not be deleted. Read it with somcp_list_order_statuses and tell '
                        . 'the merchant before anything else.',
                true
            );
        }

        // A list read later in the same process must not still show it.
        SomcpToolCreateOrderStatus::forgetOrderStatusLists(array_keys(SomcpTool::activeLanguages($idShop)));

        SomcpLogger::log(
            'order_status_deleted',
            SomcpLogger::OUTCOME_SUCCESS,
            'order status ' . $idOrderState . ($ordersWithIt > 0 ? ', kept on ' . $ordersWithIt . ' order(s)' : ''),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'deleted' => true,
            'status_id' => $idOrderState,
            'name' => $name,
            'orders_with_it' => $ordersWithIt,
        ]);
    }

    /**
     * The order status as a live, not-yet-deleted row, every language loaded,
     * or null when the identifier names none: an unknown one, or one already
     * soft-deleted that the back office no longer lists. Shared with
     * somcp_update_order_status, which refuses the same statuses.
     *
     * @param int $idOrderState
     *
     * @return OrderState|null
     */
    public static function locate(int $idOrderState): ?OrderState
    {
        if ($idOrderState <= 0) {
            return null;
        }

        $status = new OrderState($idOrderState);
        if (!Validate::isLoadedObject($status) || (bool) $status->deleted) {
            return null;
        }

        return $status;
    }

    /**
     * The name of the status in the shop default language, the one the answer
     * speaks; a text from another language is taken when the default one has
     * none, rather than answering with an empty name.
     *
     * @param OrderState $status loaded in every language
     *
     * @return string
     */
    private static function defaultName(OrderState $status): string
    {
        if (!is_array($status->name)) {
            return trim((string) $status->name);
        }

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        if ((string) ($status->name[$idLang] ?? '') !== '') {
            return trim((string) $status->name[$idLang]);
        }
        foreach ($status->name as $name) {
            if ((string) $name !== '') {
                return trim((string) $name);
            }
        }

        return '';
    }

    /**
     * How many orders of this shop have the status as their current one.
     *
     * @param int $idOrderState
     * @param int $idShop
     *
     * @return int
     */
    private static function ordersWithStatus(int $idOrderState, int $idShop): int
    {
        $query = new DbQuery();
        $query->select('COUNT(*)');
        $query->from('orders');
        $query->where('`current_state` = ' . $idOrderState . ' AND `id_shop` = ' . $idShop);

        return (int) Db::getInstance()->getValue($query);
    }
}
