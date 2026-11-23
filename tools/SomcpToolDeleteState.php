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
 * somcp_delete_state - one state of International > Locations > States, as
 * the Delete of its row.
 *
 * PrestaShop keeps a state an address uses, and this tool also keeps one a
 * tax rule row uses: the back office deletes it and leaves the row behind,
 * pointing to no state, which applies to no address and which
 * somcp_get_tax_rule could only list with an empty state.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// state is found, and answered, as somcp_list_states reads it.
require_once __DIR__ . '/SomcpToolListStates.php';

class SomcpToolDeleteState
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_state',
            Somcp::t('Delete a state'),
            'Deletes one state of International > Locations > States, as the Delete of its row. Only a state no '
                . 'address and no tax rule row uses: delete its rows with somcp_delete_country_rule first. The '
                . 'answer holds the state as it was, to recreate it with somcp_create_state. To stop offering a '
                . 'state for a while use somcp_update_state with active false.',
            [
                'type' => 'object',
                'properties' => [
                    'id_state' => [
                        'type' => 'integer',
                        'description' => 'Required. The state to delete (ID), from somcp_list_states.',
                    ],
                ],
                'required' => ['id_state'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Deleting a state is what the Delete of the states list asks for
            // in Team, Permissions.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminStates')
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

        $errors = SomcpTool::unknownFields($arguments, ['id_state']);
        $stored = null;
        $idState = SomcpTool::id($arguments, 'id_state');
        if ($idState === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_state');
        } else {
            // Only a state of a country of the shop of the token, as the list
            // reads them: a state has no shop table of its own.
            $stored = SomcpToolListStates::state($idShop, $idState);
            if ($stored === null) {
                $errors[] = SomcpTool::notFound('id_state', 'state', $idState, 'somcp_list_states');
            }
        }
        if ($stored !== null) {
            // Every address row counts, deleted ones and those of suppliers,
            // brands and warehouses included: State::delete() refuses on the
            // same count, so it is said before rather than failed after.
            $addresses = (new State($idState))->countUsed();
            if ($addresses > 0) {
                $errors[] = 'id_state: state ' . $idState . ' is used by ' . $addresses
                    . ' addresses - PrestaShop keeps a state an address uses';
            }
            $groups = Db::getInstance()->executeS(
                'SELECT `id_tax_rules_group`, COUNT(*) AS `n` FROM `' . _DB_PREFIX_ . 'tax_rule`'
                . ' WHERE `id_state` = ' . (int) $idState . ' GROUP BY `id_tax_rules_group` ORDER BY `id_tax_rules_group` ASC'
            ) ?: [];
            if ($groups !== []) {
                $errors[] = 'id_state: state ' . $idState . ' is used by ' . array_sum(array_map('intval', array_column($groups, 'n')))
                    . ' tax rule rows (tax rules ' . implode(', ', array_map('intval', array_column($groups, 'id_tax_rules_group')))
                    . ') - delete them first with somcp_delete_country_rule';
            }
            // A state has no shop table: deleting it here would remove it
            // from every shop, the rule of somcp_delete_zone.
            $otherShops = SomcpToolCreateTax::otherShops();
            if ($otherShops > 0) {
                $errors[] = 'id_state: a state is not tied to a shop - deleting it here would remove it from the '
                    . $otherShops . ' other shops of this installation too';
            }
        }
        if ($errors !== [] || $stored === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        $state = new State($idState);
        try {
            $done = $state->delete();
            // False when an address took the state between the check and the call.
            $failure = $done ? '' : 'PrestaShop refused to delete it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('state_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'state ' . $idState . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text(
                'The state could not be deleted: ' . $failure . '. Read it with somcp_list_states before calling '
                    . 'again.',
                true
            );
        }
        SomcpLogger::log('state_deleted', SomcpLogger::OUTCOME_SUCCESS, 'state ' . $idState . ' (' . $stored['name']
            . ', ' . $stored['iso_code'] . ')', '', $idEmployee);

        return SomcpTool::json([
            'deleted' => true,
            'id_state' => $idState,
            // The state as it was, read before the deletion, so the assistant
            // can recreate it with somcp_create_state.
            'state' => $stored,
        ]);
    }
}
