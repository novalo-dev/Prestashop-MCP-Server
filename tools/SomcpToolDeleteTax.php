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
 * somcp_delete_tax - one tax of International > Taxes > Taxes, deleted as the
 * Delete of its row. Irreversible.
 *
 * Unlike the back office, it refuses a tax that tax rule rows still charge:
 * the core deletes those rows from every rule without a word, so the products
 * and carriers of those countries are then sold without that tax, and a
 * deleted row cannot be read back. Refused, the merchant moves or deletes the
 * rows one by one with the country row tools, each of which says what it
 * changes. A tax an order line carries is kept hidden for that order rather
 * than removed, as the core does.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// rows are counted by somcp_update_tax and the tax read by somcp_list_taxes.
require_once __DIR__ . '/SomcpToolUpdateTax.php';
require_once __DIR__ . '/SomcpToolListTaxes.php';

class SomcpToolDeleteTax
{
    /** @var array<int, string> Every argument this tool takes. */
    private const ARGUMENTS = ['id_tax'];

    /**
     * @var array<int, string> what somcp_list_taxes returns and the shop works out, named so a row of the
     *                         list given whole is understood rather than meeting an "unknown field"
     */
    private const COMPUTED = ['name', 'rate', 'active'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_tax',
            Somcp::t('Delete a tax'),
            'Deletes one tax of International > Taxes > Taxes, as the Delete of its row. Irreversible: confirm '
                . 'with the merchant by name first. id_tax from somcp_list_taxes. Refused while tax rule rows '
                . 'still charge it: give those countries another tax with somcp_update_country_rule, or delete '
                . 'the rows with somcp_delete_country_rule first. To turn a tax off instead of deleting it, use '
                . 'somcp_update_tax with active false. One tax per call. Not to be retried: if the answer is '
                . 'lost, look with somcp_list_taxes.',
            [
                'type' => 'object',
                'properties' => [
                    'id_tax' => [
                        'type' => 'integer',
                        'description' => 'The tax to delete, from somcp_list_taxes.',
                    ],
                ],
                'required' => ['id_tax'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // The right the Delete action of the taxes page takes: edit alone
            // does not let the merchant do it by hand.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminTaxes')
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

        $errors = SomcpTool::unknownFields($arguments, self::ARGUMENTS, self::COMPUTED);
        $idTax = SomcpTool::id($arguments, 'id_tax');
        $tax = null;
        if ($idTax === 0) {
            // A cast would read a list as 1 and delete a tax nobody named.
            $errors[] = SomcpTool::notAnIdentifier('id_tax');
        } else {
            $tax = new Tax($idTax);
            if (!Validate::isLoadedObject($tax) || (int) $tax->deleted === 1) {
                $errors[] = SomcpTool::notFound('id_tax', 'tax', $idTax, 'somcp_list_taxes');
                $tax = null;
            }
        }
        if ($tax !== null) {
            $charging = SomcpToolUpdateTax::charging($idTax);
            if ($charging['rows'] > 0) {
                $errors[] = 'id_tax: charged by ' . $charging['rows'] . ' row(s) of the tax rules '
                    . implode(', ', $charging['rules']) . ' - give them another tax with somcp_update_country_rule, '
                    . 'or delete them with somcp_delete_country_rule first, or those countries would be charged nothing';
            }
        }
        if ($errors !== [] || $tax === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        return self::deleteTax($tax, $idTax, $idEmployee);
    }

    /**
     * Deletes the tax and answers with what was removed.
     *
     * @param Tax $tax
     * @param int $idTax
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function deleteTax(Tax $tax, int $idTax, int $idEmployee): array
    {
        // Read before, since the row goes with the delete, and in the shop
        // default language, as the list reads it.
        $language = SomcpTool::readLanguage([], (int) Somcp::currentShop()->id);
        $before = SomcpToolListTaxes::tax($idTax, $language !== null ? (int) $language[0] : 0);
        $usedBefore = (bool) $tax->isUsed();

        try {
            $done = (bool) $tax->delete();
        } catch (Throwable $e) {
            $done = false;
        }

        // Checked on the table rather than trusted from delete(): a tax an
        // order carries is only flagged deleted = 1, the rest is really gone.
        $row = Db::getInstance()->getRow(
            'SELECT `deleted` FROM `' . _DB_PREFIX_ . 'tax` WHERE `id_tax` = ' . (int) $idTax
        );
        $gone = !is_array($row) || $row === [];
        $kept = is_array($row) && $row !== [] && (int) $row['deleted'] === 1;
        if (!$done || (!$gone && !$kept)) {
            SomcpLogger::log('tax_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'tax ' . $idTax, '', $idEmployee);

            return SomcpTool::text(
                'The tax could not be deleted. Read somcp_list_taxes to see whether it is still there before '
                    . 'anything else.',
                true
            );
        }

        SomcpLogger::log('tax_deleted', SomcpLogger::OUTCOME_SUCCESS, 'tax ' . $idTax, '', $idEmployee);

        $result = [
            'deleted' => true,
            'id_tax' => $idTax,
            'tax' => $before,
            'kept_for_orders' => $kept,
        ];
        $warnings = [];
        if ($kept) {
            $warnings[] = 'This tax is used by orders already placed: PrestaShop keeps it hidden for them, '
                . 'and it is gone from the list.';
        }
        // Said as the tax rule tools say it, so a merchant with several shops
        // knows the tax is gone from the others too.
        $otherShops = SomcpToolCreateTax::otherShops();
        if ($otherShops > 0) {
            $result['also_in_other_shops'] = $otherShops;
            $warnings[] = 'A tax is not tied to a shop: it is gone from every shop of this installation ('
                . ($otherShops + 1) . ').';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }
}
