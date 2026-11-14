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
 * somcp_delete_attribute_value - the Delete action of one value on Catalog >
 * Attributes & Features > Attributes.
 *
 * ProductAttribute::delete() deletes every combination that uses the value,
 * gives a product that lost its default combination the first one left as its
 * default, cleans the cart rule restrictions on the value and reorders the
 * other values of the group. Everything the answer reports is counted before
 * that call, through the function shared with somcp_get_attribute_value, so
 * the merchant is told what goes before confirming and the tools never
 * disagree on it. The catalogue price rule conditions on the value are left in
 * place by the core and named so the merchant can change or remove them.
 *
 * The core removes the value for good when it has no shop row left to keep or
 * is called for all shops; otherwise ObjectModel::delete() only takes it out
 * of the shops in id_shop_list. The tool never switches to the all-shops
 * context, so on multistore the value leaves the shop of the token and may
 * stay in others; still_in_shops says where it remains and the counts are then
 * empty.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// value reader, the shared count of what goes, and the employee context live
// in these classes.
require_once __DIR__ . '/SomcpToolGetAttributeValue.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';

class SomcpToolDeleteAttributeValue
{
    /** @var array<int, string> */
    private const FIELDS = ['id_attribute', 'language'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_attribute_value',
            Somcp::t('Delete an attribute value'),
            'Deletes one value of an attribute (M, Red...), as the Delete action of Catalog > Attributes & Features '
                . '> Attributes does. Irreversible: the combinations using it go with their stock and images, a '
                . 'product that loses its default gets a new one. Read it with somcp_get_attribute_value first, where '
                . 'combinations_count and products say what goes, and confirm by name. Price rules left on it are '
                . 'listed in catalog_price_rules. id_attribute from somcp_list_attributes. On multistore it leaves '
                . 'this shop only. One value per call. If the answer is lost, look with somcp_get_attribute_value.',
            [
                'type' => 'object',
                'properties' => [
                    'id_attribute' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the attribute value, from somcp_list_attributes or '
                            . 'somcp_get_attribute_value.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages for the name in the '
                            . 'answer, for example fr; a code the shop does not have is refused. The shop default '
                            . 'language when left out.',
                    ],
                ],
                'required' => ['id_attribute'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // The right the Delete action of the attributes page takes: edit
            // alone does not let the merchant do it by hand.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminAttributesGroups')
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

        $errors = SomcpTool::unknownFields($arguments, self::FIELDS);
        $idAttribute = SomcpTool::id($arguments, 'id_attribute');
        $value = null;
        if ($idAttribute === 0) {
            // A cast would read a list as 1 and delete a value nobody named.
            $errors[] = SomcpTool::notAnIdentifier('id_attribute');
        } else {
            // Refused, not ignored: a second call for a value already deleted
            // must not read as a success.
            $value = SomcpToolGetAttributeValue::existingValue($idAttribute, $idShop);
            if ($value === null) {
                $errors[] = SomcpTool::notFound('id_attribute', 'attribute value', $idAttribute, 'somcp_list_attributes');
            }
        }
        if ($errors !== [] || $value === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($value, $language, $idShop, $idEmployee): array {
            return self::delete($value, $language, $idShop, $idEmployee);
        });
    }

    /**
     * Counts what goes with the value, deletes it, and answers.
     *
     * @param ProductAttribute $value
     * @param array{0: int, 1: string} $language
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function delete(ProductAttribute $value, array $language, int $idShop, int $idEmployee): array
    {
        [$idLang, $iso] = $language;
        $idAttribute = (int) $value->id;
        $idGroup = (int) $value->id_attribute_group;
        $name = SomcpToolCreateCategory::textOf((new ProductAttribute($idAttribute, $idLang))->name, $idLang);

        // The same test as ProductAttribute::delete(): when it is false the
        // core only removes the shop row and the combinations stay.
        $full = !$value->hasMultishopEntries() || Shop::getContext() == Shop::CONTEXT_ALL;
        $counted = $full
            ? SomcpToolGetAttributeValue::whatGoes($idAttribute, $idShop, $idLang)
            : ['combinations' => [], 'default_changed' => [], 'cart_rules' => [], 'rules' => [], 'products' => []];

        $value->id_shop_list = [$idShop];
        try {
            $done = (bool) $value->delete();
        } catch (Throwable $e) {
            $done = false;
        }
        if (!$done || SomcpToolGetAttributeValue::existingValue($idAttribute, $idShop) !== null) {
            SomcpLogger::log('attribute_value_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'attribute value ' . $idAttribute, '', $idEmployee);

            return SomcpTool::text(
                'The attribute value could not be deleted, and may have lost part of its combinations. Read it '
                    . 'with somcp_get_attribute_value to see whether it is still there before anything else.',
                true
            );
        }

        $stillInShops = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'attribute_shop` WHERE `id_attribute` = ' . $idAttribute
        );

        SomcpLogger::log(
            'attribute_value_deleted',
            SomcpLogger::OUTCOME_SUCCESS,
            'attribute value ' . $idAttribute . ', ' . count($counted['combinations']) . ' combination(s) deleted'
                . ($stillInShops > 0 ? ', still in ' . $stillInShops . ' other shop(s)' : ''),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'deleted' => true,
            'id_attribute' => $idAttribute,
            'id_attribute_group' => $idGroup,
            'name' => $name,
            'language' => $iso,
            'combinations_deleted' => $counted['combinations'],
            'default_changed' => $counted['default_changed'],
            'cart_rules' => $counted['cart_rules'],
            'catalog_price_rules' => $counted['rules'],
            'still_in_shops' => $stillInShops,
        ]);
    }
}
