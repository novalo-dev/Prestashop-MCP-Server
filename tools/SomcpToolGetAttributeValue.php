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
 * somcp_get_attribute_value - everything the back office value form shows for
 * one attribute value.
 *
 * The reader and the test of the shop are public on purpose: the attribute
 * value write tools answer with "the value as somcp_get_attribute_value
 * returns it", and calling these is what keeps them naming every field alike
 * and agreeing on which values exist.
 *
 * It takes an identifier only. Finding a value from a name is the job of
 * somcp_list_attributes.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// reader of translated texts lives in that class.
require_once __DIR__ . '/SomcpToolCreateCategory.php';

class SomcpToolGetAttributeValue
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_attribute_value',
            Somcp::t('Get an attribute value'),
            'Returns everything the back office value form shows for one attribute value of this shop '
                . '(M under Size, Red under Colour...): its name, the group it belongs to with that group\'s '
                . 'name (the one the back office lists), its position in the group (0 for the first), and for '
                . 'a group of type color its colour (hex code) and the URL of its texture image, null when it '
                . 'has none; both are null for a value of a drop-down list or radio buttons group, where the '
                . 'product page does not show them. Also the faceted search fields of that form - url_name '
                . '(the word used in filtered URLs) and meta_title - which are null when the faceted search '
                . 'module is not installed. '
                . 'Identify the value by its identifier, from somcp_list_attributes: never guess one. '
                . 'Do not use it for the list of groups and values, which is somcp_list_attributes, or for '
                . 'the group itself, which is somcp_get_attribute_group.',
            [
                'type' => 'object',
                'properties' => [
                    'id_attribute' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the attribute value, from somcp_list_attributes.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of the language to read the texts in, for example fr. '
                            . 'The shop default is used otherwise.',
                    ],
                ],
                'required' => ['id_attribute'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Values are managed under their group, on the back office
            // attributes page, and that is the page a merchant would open.
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

        // A cast would read an array as 1 and answer about a value nobody
        // asked for: only a whole number is an identifier.
        $value = $arguments['id_attribute'] ?? null;
        $idAttribute = is_int($value) || (is_string($value) && preg_match('/^\d{1,10}$/', trim($value)))
            ? (int) trim((string) $value)
            : 0;

        if (self::existingValue($idAttribute, $idShop) === null) {
            return SomcpTool::text(
                'No attribute value of this shop has this identifier. Find it with somcp_list_attributes.',
                true
            );
        }

        return SomcpTool::json(self::attributeValue($idAttribute, $idLang));
    }

    /**
     * The attribute value, when it exists in the shop of the token.
     *
     * @param int $idAttribute
     * @param int $idShop
     *
     * @return ProductAttribute|null
     */
    public static function existingValue(int $idAttribute, int $idShop): ?ProductAttribute
    {
        if ($idAttribute <= 0) {
            return null;
        }
        // A value of another shop is answered like one that does not exist:
        // the ProductAttribute constructor would load it anyway, whatever shop
        // it belongs to. attribute_shop is also what somcp_list_attributes
        // reads, so both tools agree on which values the shop has.
        $inShop = (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'attribute_shop` WHERE `id_attribute` = ' . (int) $idAttribute
            . ' AND `id_shop` = ' . (int) $idShop
        );
        if (!$inShop) {
            return null;
        }
        $attribute = new ProductAttribute($idAttribute);

        return Validate::isLoadedObject($attribute) ? $attribute : null;
    }

    /**
     * One attribute value, under the names the attribute value tools take and
     * answer with.
     *
     * @param int $idAttribute
     * @param int $idLang
     *
     * @return array<string, mixed>
     */
    public static function attributeValue(int $idAttribute, int $idLang): array
    {
        $attribute = new ProductAttribute($idAttribute, $idLang);
        $idGroup = (int) $attribute->id_attribute_group;
        $group = new AttributeGroup($idGroup, $idLang);

        $color = null;
        $texture = null;
        // The colour and the texture are only drawn on the product page for a
        // group of type color. Elsewhere they may still be stored, left over
        // from a group whose type changed, but nobody sees them: null says so
        // rather than reporting a colour the shop does not show.
        if ((string) $group->group_type === 'color') {
            $color = (string) $attribute->color;
            // The texture is not a column: the back office stores the uploaded
            // file under the value's identifier, and only that file tells
            // whether there is one.
            if (is_file(_PS_COL_IMG_DIR_ . $idAttribute . '.jpg')) {
                $link = Somcp::legacyContext()->link ?? new Link();
                $texture = (string) $link->getMediaLink(_THEME_COL_DIR_ . $idAttribute . '.jpg');
            }
        }

        $result = [
            'id_attribute' => $idAttribute,
            'id_attribute_group' => $idGroup,
            'group_name' => SomcpToolCreateCategory::textOf($group->name, $idLang),
            'name' => SomcpToolCreateCategory::textOf($attribute->name, $idLang),
            'position' => (int) $attribute->position,
            'color' => $color,
            'texture' => $texture,
            'url_name' => null,
            'meta_title' => null,
        ];

        // These two fields are added to the form by ps_facetedsearch and live
        // in its own table, which only exists while it is installed.
        if (Module::isInstalled('ps_facetedsearch')) {
            $texts = Db::getInstance()->getRow(
                'SELECT `url_name`, `meta_title` FROM `' . _DB_PREFIX_ . 'layered_indexable_attribute_lang_value`'
                . ' WHERE `id_attribute` = ' . (int) $idAttribute . ' AND `id_lang` = ' . (int) $idLang
            );
            // No row is what the form shows as empty fields: the module only
            // writes one when the form is saved.
            $result['url_name'] = is_array($texts) ? (string) ($texts['url_name'] ?? '') : '';
            $result['meta_title'] = is_array($texts) ? (string) ($texts['meta_title'] ?? '') : '';
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
