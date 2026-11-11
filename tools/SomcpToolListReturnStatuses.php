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
 * somcp_list_return_statuses - the steps a merchandise return goes through
 * in this shop.
 *
 * A return is not an order. It has its own statuses, its own table and its
 * own identifiers, and the two lists sit next to each other in the back
 * office, which is exactly why they get confused: an assistant asked where
 * a return stands and left without this tool reads the order statuses,
 * finds one with a similar name, and answers about the wrong object. The
 * day a tool moves a return to another step, a guessed identifier puts
 * that return in a state the merchant never chose.
 *
 * The five statuses a shop starts with cannot be deleted - the back office
 * hides Delete on identifiers 1 to 5 on both PrestaShop 8 and 9 - so each
 * status says whether it is removable, which is what tells the built-in
 * steps from the merchant's own.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in:
// the removable rule lives in the creation tool.
require_once __DIR__ . '/SomcpToolCreateReturnStatus.php';

class SomcpToolListReturnStatuses
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_return_statuses',
            Somcp::t('List return statuses'),
            'Lists the return statuses of this shop with their identifiers: the steps '
                . 'a merchandise return goes through, from waiting for confirmation to '
                . 'return completed or denied, with the colour each one is shown in and '
                . 'whether the merchant can remove it. Status identifiers are specific '
                . 'to each shop - a merchant renames them and adds their own - so never '
                . 'assume one. Call it before reasoning about the state of a return. '
                . 'Do not use it for the statuses of an order: somcp_list_order_statuses '
                . 'lists those, and a return status is not an order status.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, '
                            . 'for the names; a code the shop does not have is refused. The shop default '
                            . 'language when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_READ],
            [self::class, 'handle'],
            // Return statuses are shop settings, on the same back office page
            // as the order statuses.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminStatuses')
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
        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;

        $statuses = [];
        foreach (OrderReturnState::getOrderReturnStates($idLang) as $state) {
            $id = (int) $state['id_order_return_state'];
            $statuses[] = [
                'id_order_return_state' => $id,
                'name' => (string) $state['name'],
                'color' => (string) $state['color'],
                'removable' => SomcpToolCreateReturnStatus::removable($id),
            ];
        }

        return SomcpTool::json([
            'statuses' => $statuses,
            'count' => count($statuses),
            // The ISO code, which the write tools key their texts by, rather
            // than a language identifier no tool lists.
            'language' => $iso,
        ]);
    }
}
