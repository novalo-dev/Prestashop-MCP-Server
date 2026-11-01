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
 * somcp_delete_return_status - deletes one return status the merchant
 * created, as the delete action of the back office return status list
 * does.
 *
 * The row is really deleted, unlike an order status: OrderReturnState::
 * delete() removes the status and its names, on PrestaShop 8 and 9 alike.
 *
 * Neither back office offers Delete on the five statuses every shop
 * starts with - 8.0.1 skips the action on identifiers 1 to 5, 9.0.0
 * computes the same rows as unremovable - and neither handler checks it,
 * so the tool refuses 1 to 5 itself. A status a merchandise return still
 * stands on is refused too, with the number of returns: deleting it would
 * leave those returns with a step that no longer exists, which neither
 * version checks either.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolDeleteReturnStatus
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_return_status',
            Somcp::t('Delete a return status'),
            'Deletes one return status the merchant created: a step a merchandise return goes through, as '
                . 'the Delete action of the return status list of Shop Parameters > Order Settings > Statuses '
                . 'does. Irreversible. Before calling it, read the status with somcp_list_return_statuses and '
                . 'ask the merchant to confirm, naming the status. Take the status from '
                . 'somcp_list_return_statuses and use the identifier it returns; never guess one. The five '
                . 'statuses every shop starts with, identifiers 1 to 5, are refused, as both back offices '
                . 'refuse them, and so is a status a merchandise return still stands on, with the number of '
                . 'returns in the answer. Do not use it to change a return status '
                . '(somcp_update_return_status), and not for the statuses of an order: a return status is not '
                . 'an order status. One return status per call. Not to be retried on a lost answer: read '
                . 'somcp_list_return_statuses to see whether it is gone.',
            [
                'type' => 'object',
                'properties' => [
                    'id_order_return_state' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the return status, from somcp_list_return_statuses.',
                    ],
                ],
                'required' => ['id_order_return_state'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The right the back office checks on its delete action for a
            // return status, on the statuses page: edit alone does not allow it.
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
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        // A cast would read an array as 1 and delete a status nobody asked
        // for: only a whole number is an identifier.
        $value = $arguments['id_order_return_state'] ?? null;
        $idReturnState = is_int($value) || (is_string($value) && preg_match('/^\d{1,10}$/', trim($value)))
            ? (int) trim((string) $value)
            : 0;

        // Refused, not ignored: a second call for a status already deleted
        // must not read as a success.
        $status = self::existingStatus($idReturnState);
        if ($status === null) {
            return SomcpTool::text(
                'No return status has this identifier. Nothing was deleted. Find it with '
                    . 'somcp_list_return_statuses.',
                true
            );
        }

        // Read before deleting: afterwards there is nothing left to read it
        // from, and the merchant needs the name to know which status went.
        $name = self::statusName($status);

        // Neither back office offers Delete on the five statuses every shop
        // starts with, and neither handler checks it: the tool refuses them
        // itself, before any question of use.
        if ($idReturnState >= 1 && $idReturnState <= 5) {
            return SomcpTool::text(
                'Return status ' . $idReturnState . ' (' . $name . ') is one of the five statuses every shop '
                    . 'starts with, and neither back office offers Delete on those. Nothing was deleted.',
                true
            );
        }

        // A status a return still stands on is refused, with the number:
        // deleting it would leave those returns with a step that no longer
        // exists, which neither version checks.
        $returns = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_return` WHERE `state` = ' . $idReturnState
        );
        if ($returns > 0) {
            return SomcpTool::text(
                'Return status ' . $idReturnState . ' (' . $name . ') cannot be deleted: ' . $returns
                    . ' merchandise return(s) still stand on it, and deleting it would leave them with a step '
                    . 'that no longer exists. Nothing was deleted.',
                true
            );
        }

        try {
            $done = (bool) $status->delete();
        } catch (Throwable $e) {
            $done = false;
        }

        // Asked of the shop rather than inferred from delete(): the same
        // read as the list tool, so "deleted" means the merchant no longer
        // sees it.
        $gone = self::existingStatus($idReturnState) === null;

        if (!$done || !$gone) {
            SomcpLogger::log(
                'return_status_delete_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'return status ' . $idReturnState . ($gone ? ' removed, clean-up incomplete' : ' still in the shop'),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                $gone
                    ? 'The return status was deleted, but PrestaShop reported a problem while doing it. Tell '
                        . 'the merchant; there is nothing to call again.'
                    : 'The return status could not be deleted. Read it with somcp_list_return_statuses and '
                        . 'tell the merchant before anything else.',
                true
            );
        }

        SomcpLogger::log(
            'return_status_deleted',
            SomcpLogger::OUTCOME_SUCCESS,
            'return status ' . $idReturnState,
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'deleted' => true,
            'id_order_return_state' => $idReturnState,
            'name' => $name,
        ]);
    }

    /**
     * The status of this identifier, or null when the shop has none.
     *
     * No shop test, unlike the catalogue reads: return statuses are not
     * tied to a shop, the table has no shop column.
     *
     * @param int $idReturnState
     *
     * @return OrderReturnState|null
     */
    private static function existingStatus(int $idReturnState): ?OrderReturnState
    {
        if ($idReturnState < 1) {
            return null;
        }

        $status = new OrderReturnState($idReturnState);

        return (int) $status->id === $idReturnState ? $status : null;
    }

    /**
     * The name of the status in the shop's default language, the one the
     * answer speaks: a text from another language is taken when the default
     * one has none, rather than answering with an empty name.
     *
     * @param OrderReturnState $status loaded in every language
     *
     * @return string
     */
    private static function statusName(OrderReturnState $status): string
    {
        if (!is_array($status->name)) {
            return (string) $status->name;
        }

        $idLang = (int) Somcp::legacyContext()->language->id;
        if ((string) ($status->name[$idLang] ?? '') !== '') {
            return (string) $status->name[$idLang];
        }
        foreach ($status->name as $name) {
            if ((string) $name !== '') {
                return (string) $name;
            }
        }

        return '';
    }
}
