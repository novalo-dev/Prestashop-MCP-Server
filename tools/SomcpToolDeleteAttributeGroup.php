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
 * somcp_delete_attribute_group - the Delete action of Catalog > Attributes &
 * Features > Attributes.
 *
 * AttributeGroup::delete() takes the values of the group with it, strips them
 * from every combination and deletes the combinations left without any value.
 * Everything the answer reports is counted before that call: afterwards the
 * values and the links between them and the combinations are gone. The
 * combinations are counted from the group's own values, because the core's
 * cleanDeadCombinations() also removes combinations of the shop that had no
 * value before the call, which this delete did not cause.
 *
 * The core only removes the values when the group has no shop rows to keep
 * (hasMultishopEntries(), true for any row once multistore is on) or when
 * called for all shops; otherwise ObjectModel::delete() only takes the group
 * out of the shops in id_shop_list. The tool never switches to the all-shops
 * context, so on multistore the group leaves the shop of the token and keeps
 * its values; the counts are then 0 and still_in_shops says where it remains.
 *
 * Which groups exist in the shop of the token, their names, and what a
 * delete takes with them come from somcp_get_attribute_group, which shows the
 * same counts before the call, so the attribute group tools never disagree.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// shared checks and steps live in these classes.
require_once __DIR__ . '/SomcpToolGetAttributeGroup.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';

class SomcpToolDeleteAttributeGroup
{
    /** @var array<int, string> */
    private const FIELDS = ['id_attribute_group', 'language'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_attribute_group',
            Somcp::t('Delete an attribute group'),
            'Deletes one attribute group (Size, Color...) with all its values, as Catalog > Attributes & Features '
                . '> Attributes does. Irreversible: combinations built only on it go with their stock, the others '
                . 'lose one value. Read it with somcp_get_attribute_group, show the merchant its products, '
                . 'combinations_count and combinations_only_on_it, confirm by name. Price rules left on its values are listed in catalog_price_rules. id_attribute_group from '
                . 'somcp_list_attributes. On multistore it leaves this shop only. One group per call. Not to be '
                . 'retried: if the answer is lost, look with somcp_get_attribute_group.',
            [
                'type' => 'object',
                'properties' => [
                    'id_attribute_group' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the attribute group, from somcp_list_attributes or '
                            . 'somcp_get_attribute_group.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages for the name in the '
                            . 'answer, for example fr; a code the shop does not have is refused. The shop default '
                            . 'language when left out.',
                    ],
                ],
                'required' => ['id_attribute_group'],
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
        $idGroup = SomcpTool::id($arguments, 'id_attribute_group');
        $group = null;
        if ($idGroup === 0) {
            // A cast would read a list as 1 and delete a group nobody named.
            $errors[] = SomcpTool::notAnIdentifier('id_attribute_group');
        } else {
            // Refused, not ignored: a second call for a group already deleted
            // must not read as a success.
            $group = SomcpToolGetAttributeGroup::existingGroup($idGroup, $idShop);
            if ($group === null) {
                $errors[] = SomcpTool::notFound('id_attribute_group', 'attribute group', $idGroup, 'somcp_list_attributes');
            }
        }
        // Read only when given, so an unknown language is listed with the
        // call's other mistakes rather than answered on its own.
        $askedLanguage = array_key_exists('language', $arguments) && $arguments['language'] !== null && $arguments['language'] !== '';
        $language = SomcpTool::readLanguage($askedLanguage ? $arguments : [], $idShop);
        if ($askedLanguage && $language === null) {
            $errors[] = SomcpTool::languageProblem($idShop);
        }
        if ($errors !== [] || $group === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        // Left only for a shop whose default language cannot be read.
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($group, $language, $idShop, $idEmployee): array {
            return self::delete($group, $language, $idShop, $idEmployee);
        });
    }

    /**
     * Counts what goes with the group, deletes it, and answers.
     *
     * @param AttributeGroup $group
     * @param array{0: int, 1: string} $language
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function delete(AttributeGroup $group, array $language, int $idShop, int $idEmployee): array
    {
        [$idLang, $iso] = $language;
        $idGroup = (int) $group->id;
        $described = SomcpToolGetAttributeGroup::group($idGroup, $idLang, $idShop);

        // The same test as AttributeGroup::delete(): when it is false the core
        // only removes the shop row and the values stay.
        $full = !$group->hasMultishopEntries() || Shop::getContext() == Shop::CONTEXT_ALL;
        $counted = $full
            ? SomcpToolGetAttributeGroup::whatGoes($idGroup, $idShop, $idLang)
            : ['deleted' => 0, 'changed' => 0, 'products' => [], 'rules' => []];

        $group->id_shop_list = [$idShop];
        try {
            $done = (bool) $group->delete();
        } catch (Throwable $e) {
            $done = false;
        }
        if (!$done || SomcpToolGetAttributeGroup::existingGroup($idGroup, $idShop) !== null) {
            SomcpLogger::log('attribute_group_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'attribute group ' . $idGroup, '', $idEmployee);

            return SomcpTool::text(
                'The attribute group could not be deleted, and may have lost part of its values or combinations. '
                    . 'Read it with somcp_get_attribute_group to see what it still holds before anything else.',
                true
            );
        }

        $stillInShops = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'attribute_group_shop` WHERE `id_attribute_group` = ' . $idGroup
        );

        SomcpLogger::log(
            'attribute_group_deleted',
            SomcpLogger::OUTCOME_SUCCESS,
            'attribute group ' . $idGroup . ', ' . $counted['deleted'] . ' combination(s) deleted, '
                . $counted['changed'] . ' changed' . ($stillInShops > 0 ? ', still in ' . $stillInShops . ' other shop(s)' : ''),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'deleted' => true,
            'id_attribute_group' => $idGroup,
            'name' => (string) $described['name'],
            'language' => $iso,
            'values_deleted' => $full ? (int) $described['values_count'] : 0,
            'combinations_deleted' => $counted['deleted'],
            'combinations_changed' => $counted['changed'],
            'products' => $counted['products'],
            'catalog_price_rules' => $counted['rules'],
            'still_in_shops' => $stillInShops,
        ]);
    }
}
