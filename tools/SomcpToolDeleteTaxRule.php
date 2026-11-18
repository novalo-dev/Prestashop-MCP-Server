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
 * somcp_delete_tax_rule - one tax rule of International > Taxes > Tax Rules,
 * deleted with its rows per country, as the row action Delete of the list.
 *
 * Unlike the back office, it refuses a rule that products or carriers of the
 * shop still take: the core leaves them pointing at a rule that no longer
 * exists, and they are then sold without tax, on every invoice from then on,
 * without the merchant seeing it. A rule another shop also has is refused as
 * well, since TaxRulesGroup::delete() would take it from that shop too, beyond
 * the reach of the token.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in:
// the rule is found by the same test as somcp_get_tax_rule.
require_once __DIR__ . '/SomcpToolGetTaxRule.php';

class SomcpToolDeleteTaxRule
{
    /** @var array<int, string> Every argument this tool takes. */
    private const ARGUMENTS = ['id_tax_rules_group'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_tax_rule',
            Somcp::t('Delete a tax rule'),
            'Deletes one tax rule of International > Taxes > Tax Rules with its rows per country, as the '
                . 'Delete action of the list. Irreversible. Read it with somcp_get_tax_rule and confirm with the '
                . 'merchant by name first. id_tax_rules_group from somcp_list_tax_rules. Refused while products '
                . 'or carriers still take it, or another shop has it. To keep it for later, switch it off with '
                . 'somcp_update_tax_rule instead. One rule per call. Not to be retried: if the answer is lost, '
                . 'look with somcp_get_tax_rule.',
            [
                'type' => 'object',
                'properties' => [
                    'id_tax_rules_group' => [
                        'type' => 'integer',
                        'description' => 'The rule to delete, from somcp_list_tax_rules.',
                    ],
                ],
                'required' => ['id_tax_rules_group'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // The right the Delete action of the tax rules page takes: edit
            // alone does not let the merchant do it by hand.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminTaxRulesGroup')
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

        $errors = SomcpTool::unknownFields($arguments, self::ARGUMENTS);
        $idGroup = SomcpTool::id($arguments, 'id_tax_rules_group');
        $stored = null;
        if ($idGroup === 0) {
            // A cast would read a list as 1 and delete a rule nobody named.
            $errors[] = SomcpTool::notAnIdentifier('id_tax_rules_group');
        } else {
            // Refused, not ignored: a second call for a rule already deleted
            // must not read as a success.
            $stored = SomcpToolGetTaxRule::existingRule($idGroup, $idShop);
            if ($stored === null) {
                $errors[] = SomcpTool::notFound('id_tax_rules_group', 'tax rule', $idGroup, 'somcp_list_tax_rules');
            }
        }
        if ($stored !== null) {
            array_push($errors, ...self::stillUsed($idGroup, $idShop));
        }
        if ($errors !== [] || $stored === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        // Read before, since the rows go with the rule.
        $rows = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rule` WHERE `id_tax_rules_group` = ' . (int) $idGroup
        );

        $rule = new TaxRulesGroup($idGroup);
        $rule->id_shop_list = [$idShop];
        try {
            $done = (bool) $rule->delete();
        } catch (Throwable $e) {
            $done = false;
        }
        // Checked on the table rather than trusted from delete(): the rows of
        // tax_rule go first, so a failure after them leaves a rule half gone.
        $left = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rules_group` WHERE `id_tax_rules_group` = ' . (int) $idGroup
        );
        if (!$done || $left > 0) {
            SomcpLogger::log('tax_rule_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'tax rule ' . $idGroup, '', $idEmployee);

            return SomcpTool::text(
                'The tax rule could not be deleted, and may have lost part of its rows per country. Read it with '
                    . 'somcp_get_tax_rule to see what it still holds before anything else.',
                true
            );
        }

        SomcpLogger::log(
            'tax_rule_deleted',
            SomcpLogger::OUTCOME_SUCCESS,
            'tax rule ' . $idGroup . ', ' . $rows . ' country row(s)',
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'deleted' => true,
            'id_tax_rules_group' => $idGroup,
            'name' => (string) $stored['name'],
            'country_rules_deleted' => $rows,
        ]);
    }

    /**
     * Why the rule cannot go yet, one line per reason.
     *
     * @param int $idGroup
     * @param int $idShop
     *
     * @return array<int, string>
     */
    private static function stillUsed(int $idGroup, int $idShop): array
    {
        $problems = [];
        ['products' => $products, 'carriers' => $carriers, 'shops' => $shops] = self::users($idGroup, $idShop);

        if ($products > 0) {
            $problems[] = 'id_tax_rules_group: used by ' . $products . ' product' . ($products > 1 ? 's' : '')
                . ' - give them another tax rule with somcp_update_product first, or they would be sold without tax';
        }

        if ($carriers > 0) {
            $problems[] = 'id_tax_rules_group: used by ' . $carriers . ' carrier' . ($carriers > 1 ? 's' : '')
                . ' - give them another tax rule in Shipping > Carriers first';
        }

        if ($shops > 0) {
            $problems[] = 'id_tax_rules_group: also used by ' . $shops . ' other shop' . ($shops > 1 ? 's' : '')
                . ' of this installation - delete it from the back office in the context of every shop';
        }

        return $problems;
    }

    /**
     * How many products and carriers of the shop take the rule, and how
     * many other shops have it.
     *
     * Public so the tool that deletes the last row of a rule warns about the
     * same products and carriers this one refuses for.
     *
     * @param int $idGroup
     * @param int $idShop
     *
     * @return array{products: int, carriers: int, shops: int}
     */
    public static function users(int $idGroup, int $idShop): array
    {
        $db = Db::getInstance();

        return [
            'products' => (int) $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_shop`'
                . ' WHERE `id_tax_rules_group` = ' . (int) $idGroup . ' AND `id_shop` = ' . (int) $idShop
            ),
            // A deleted carrier keeps its row here, but is never offered again.
            'carriers' => (int) $db->getValue(
                'SELECT COUNT(DISTINCT ct.`id_carrier`) FROM `' . _DB_PREFIX_ . 'carrier_tax_rules_group_shop` ct'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'carrier` c ON c.`id_carrier` = ct.`id_carrier` AND c.`deleted` = 0'
                . ' WHERE ct.`id_tax_rules_group` = ' . (int) $idGroup . ' AND ct.`id_shop` = ' . (int) $idShop
            ),
            'shops' => (int) $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rules_group_shop`'
                . ' WHERE `id_tax_rules_group` = ' . (int) $idGroup . ' AND `id_shop` != ' . (int) $idShop
            ),
        ];
    }
}
