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
 * somcp_get_attribute_group - everything the back office attribute form shows
 * for one attribute group.
 *
 * The reader and the test of the shop are public on purpose: the attribute
 * group write tools answer with "the group as somcp_get_attribute_group
 * returns it", and calling these is what keeps them naming every field alike
 * and agreeing on which groups exist.
 *
 * It takes an identifier only. Finding a group from a name is the job of
 * somcp_list_attributes.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// reader of translated texts lives in that class.
require_once __DIR__ . '/SomcpToolCreateCategory.php';

class SomcpToolGetAttributeGroup
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_attribute_group',
            Somcp::t('Get an attribute group'),
            'Returns everything the back office attribute form shows for one attribute group of this '
                . 'shop (Size, Colour...): name (the one the back office lists), public_name (the one '
                . 'customers see on the product page), group_type (select, radio or color: how customers '
                . 'pick a value), position (0 for the first, the order of the back office list and of the '
                . 'product page), the number of values it holds, and the faceted search fields of that '
                . 'form - url_name (the word used in filtered URLs), meta_title and indexable - which are '
                . 'null when the faceted search module is not installed. '
                . 'Identify the group by its identifier, from somcp_list_attributes: never guess one. '
                . 'Do not use it for the list of groups or the names of their values, which is '
                . 'somcp_list_attributes, or for one value: somcp_get_attribute_value.',
            [
                'type' => 'object',
                'properties' => [
                    'id_attribute_group' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the attribute group, from somcp_list_attributes.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of the language to read the texts in, for example fr. '
                            . 'The shop default is used otherwise.',
                    ],
                ],
                'required' => ['id_attribute_group'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Attribute groups are managed on their own back office page, and
            // that is the page a merchant would open to look at one.
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

        // A cast would read an array as 1 and answer about a group nobody
        // asked for: only a whole number is an identifier.
        $value = $arguments['id_attribute_group'] ?? null;
        $idGroup = is_int($value) || (is_string($value) && preg_match('/^\d{1,10}$/', trim($value)))
            ? (int) trim((string) $value)
            : 0;

        if (self::existingGroup($idGroup, $idShop) === null) {
            return SomcpTool::text(
                'No attribute group of this shop has this identifier. Find it with somcp_list_attributes.',
                true
            );
        }

        return SomcpTool::json(self::group($idGroup, $idLang, $idShop));
    }

    /**
     * The attribute group, when it exists in the shop of the token.
     *
     * @param int $idGroup
     * @param int $idShop
     *
     * @return AttributeGroup|null
     */
    public static function existingGroup(int $idGroup, int $idShop): ?AttributeGroup
    {
        if ($idGroup <= 0) {
            return null;
        }
        // A group of another shop is answered like one that does not exist:
        // the AttributeGroup constructor would load it anyway, whatever shop
        // it belongs to.
        $inShop = (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'attribute_group_shop` WHERE `id_attribute_group` = ' . (int) $idGroup
            . ' AND `id_shop` = ' . (int) $idShop
        );
        if (!$inShop) {
            return null;
        }
        $group = new AttributeGroup($idGroup);

        return Validate::isLoadedObject($group) ? $group : null;
    }

    /**
     * One attribute group, under the names the attribute group tools take and
     * answer with.
     *
     * @param int $idGroup
     * @param int $idLang
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    public static function group(int $idGroup, int $idLang, int $idShop): array
    {
        $group = new AttributeGroup($idGroup, $idLang);

        $result = [
            'id_attribute_group' => $idGroup,
            'name' => SomcpToolCreateCategory::textOf($group->name, $idLang),
            'public_name' => SomcpToolCreateCategory::textOf($group->public_name, $idLang),
            'group_type' => (string) $group->group_type,
            'position' => (int) $group->position,
            // The values of this shop only, the same set somcp_list_attributes
            // lists under the group.
            'values_count' => (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'attribute` a'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'attribute_shop` ash ON ash.`id_attribute` = a.`id_attribute`'
                . ' AND ash.`id_shop` = ' . (int) $idShop
                . ' WHERE a.`id_attribute_group` = ' . (int) $idGroup
            ),
            'url_name' => null,
            'meta_title' => null,
            'indexable' => null,
        ];

        // These three fields are added to the form by ps_facetedsearch and
        // live in its own tables, which only exist while it is installed.
        if (Module::isInstalled('ps_facetedsearch')) {
            $texts = Db::getInstance()->getRow(
                'SELECT `url_name`, `meta_title` FROM `' . _DB_PREFIX_ . 'layered_indexable_attribute_group_lang_value`'
                . ' WHERE `id_attribute_group` = ' . (int) $idGroup . ' AND `id_lang` = ' . (int) $idLang
            );
            // No row is what the form shows as empty fields and an unticked
            // box: the module only writes one when the form is saved.
            $result['url_name'] = is_array($texts) ? (string) ($texts['url_name'] ?? '') : '';
            $result['meta_title'] = is_array($texts) ? (string) ($texts['meta_title'] ?? '') : '';
            $result['indexable'] = (bool) Db::getInstance()->getValue(
                'SELECT `indexable` FROM `' . _DB_PREFIX_ . 'layered_indexable_attribute_group`'
                . ' WHERE `id_attribute_group` = ' . (int) $idGroup
            );
        }

        return $result;
    }

    /**
     * The language to read the texts in.
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
