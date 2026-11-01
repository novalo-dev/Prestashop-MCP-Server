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
 * somcp_list_attributes - the attribute groups of this shop and their values,
 * identifier, name and what the back office lists show beside them.
 *
 * Kept short on purpose, like somcp_list_categories: it is the list an
 * assistant reads before a call that takes attribute values - a combination
 * created or changed - so that it never guesses an identifier. A combination
 * is a set of id_attribute, never of names: names are translated, and two
 * groups can hold the same value name (a Size M and a Paper type M).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListAttributes
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_attributes',
            Somcp::t('List attributes'),
            'Lists the attribute groups of this shop (Size, Colour...) and, under each, its values: '
                . 'identifier, name and position, plus the type and the number of values of each group '
                . 'and the colour of each value of a colour group. Call it before any call that takes attribute '
                . 'values - creating or changing the combinations of a product - and take the '
                . 'id_attribute from here: identifiers are specific to each shop, so never assume one. '
                . 'Two groups can hold a value of the same name: when the merchant names a value, '
                . 'check which group it belongs to, and ask when it is in more than one. Give '
                . 'id_attribute_group to read one group only. Everything else about one group or one '
                . 'value is read with somcp_get_attribute_group or somcp_get_attribute_value. '
                . 'Do not use it for the combinations of a product: '
                . 'somcp_get_product lists those.',
            [
                'type' => 'object',
                'properties' => [
                    'id_attribute_group' => [
                        'type' => 'integer',
                        'description' => 'Identifier of one attribute group, to list that group only. '
                            . 'Every group is listed otherwise.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of the language to read the names in, '
                            . 'for example fr. The shop default is used otherwise.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Attribute groups and their values are managed on their own back
            // office page, and that is the page a merchant would open to look
            // at them.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminAttributesGroups')
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
        $idLang = self::language($arguments);

        // A group asked for by identifier must be one of this shop: an
        // identifier that matches nothing is said, not answered with an empty
        // list the assistant would read as "this group has no values".
        $idGroup = null;
        if (isset($arguments['id_attribute_group'])) {
            $idGroup = is_scalar($arguments['id_attribute_group']) ? (int) $arguments['id_attribute_group'] : 0;
        }

        // Queries rather than AttributeGroup::getAttributesGroups() and
        // AttributeGroup::getAttributes(), which read the shop from the global
        // context: here it comes from the token and is passed explicitly.
        $query = new DbQuery();
        $query->select('ag.`id_attribute_group`, ag.`group_type`, ag.`position`, agl.`name`');
        $query->from('attribute_group', 'ag');
        $query->innerJoin(
            'attribute_group_shop',
            'ags',
            'ags.`id_attribute_group` = ag.`id_attribute_group` AND ags.`id_shop` = ' . $idShop
        );
        // A left join, so a group with no name in this language is still
        // listed rather than silently missing from the only list of identifiers.
        $query->leftJoin(
            'attribute_group_lang',
            'agl',
            'agl.`id_attribute_group` = ag.`id_attribute_group` AND agl.`id_lang` = ' . $idLang
        );
        if ($idGroup !== null) {
            $query->where('ag.`id_attribute_group` = ' . $idGroup);
        }
        // Same order as the back office and the storefront.
        $query->orderBy('ag.`position` ASC, ag.`id_attribute_group` ASC');

        $groups = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $groups[(int) $row['id_attribute_group']] = [
                'id_attribute_group' => (int) $row['id_attribute_group'],
                'name' => (string) ($row['name'] ?? ''),
                'group_type' => (string) $row['group_type'],
                'position' => (int) $row['position'],
                'values_count' => 0,
                'values' => [],
            ];
        }

        if ($idGroup !== null && $groups === []) {
            return SomcpTool::text(
                'No attribute group of this shop has this identifier. List them all with somcp_list_attributes, without id_attribute_group.',
                true
            );
        }

        if ($groups !== []) {
            $values = new DbQuery();
            $values->select('a.`id_attribute`, a.`id_attribute_group`, a.`color`, a.`position`, al.`name`');
            $values->from('attribute', 'a');
            $values->innerJoin(
                'attribute_shop',
                'ash',
                'ash.`id_attribute` = a.`id_attribute` AND ash.`id_shop` = ' . $idShop
            );
            $values->leftJoin('attribute_lang', 'al', 'al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . $idLang);
            $values->where('a.`id_attribute_group` IN (' . implode(',', array_map('intval', array_keys($groups))) . ')');
            $values->orderBy('a.`position` ASC, a.`id_attribute` ASC');

            foreach (Db::getInstance()->executeS($values) ?: [] as $row) {
                $id = (int) $row['id_attribute_group'];
                $groups[$id]['values'][] = [
                    'id_attribute' => (int) $row['id_attribute'],
                    'name' => (string) ($row['name'] ?? ''),
                    'position' => (int) $row['position'],
                    // Same rule as somcp_get_attribute_value: a colour is only
                    // drawn for a group of type color, so a colour left over
                    // from a group whose type changed is reported as null.
                    'color' => $groups[$id]['group_type'] === 'color' ? (string) $row['color'] : null,
                ];
                // Counted on the same rows as the values listed, which are the
                // ones somcp_get_attribute_group counts: attribute_shop in the
                // shop of the token.
                ++$groups[$id]['values_count'];
            }
        }

        return SomcpTool::json([
            'attribute_groups' => array_values($groups),
            'count' => count($groups),
        ]);
    }

    /**
     * The language to read the names in.
     *
     * @param array<string, mixed> $arguments
     *
     * @return int
     */
    private static function language(array $arguments): int
    {
        $iso = isset($arguments['language']) && is_scalar($arguments['language']) ? trim((string) $arguments['language']) : '';
        if ($iso !== '') {
            $idLang = (int) Language::getIdByIso($iso);
            if ($idLang > 0) {
                return $idLang;
            }
        }

        return (int) Somcp::legacyContext()->language->id;
    }
}
