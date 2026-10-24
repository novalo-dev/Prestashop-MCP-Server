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
 * somcp_list_features - the features of this shop and their predefined
 * values, identifier and name.
 *
 * Kept that short on purpose, like somcp_list_attributes: it is the list an
 * assistant reads before a call that takes feature values - a product created
 * or changed - so that it never guesses an identifier. Only predefined values
 * are listed: a custom value is typed on one product, belongs to it alone and
 * is read with that product.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListFeatures
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_features',
            Somcp::t('List features'),
            'Lists the features of this shop (Composition, Material...) and, under each, its '
                . 'predefined values: identifier and name, nothing else. Call it before any call that '
                . 'takes feature values - creating or changing a product - and take the id_feature and '
                . 'id_feature_value from here: identifiers are specific to each shop, so never assume '
                . 'one. Custom values, typed on one product, are not listed: they belong to that product. '
                . 'Give id_feature to read one feature only. Everything else about one feature or one '
                . 'value is read with somcp_get_feature or somcp_get_feature_value when the shop offers '
                . 'them. Do not use it for the features of a product: somcp_get_product lists those.',
            [
                'type' => 'object',
                'properties' => [
                    'id_feature' => [
                        'type' => 'integer',
                        'description' => 'Identifier of one feature, to list that feature only. '
                            . 'Every feature is listed otherwise.',
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
            // Features and their values are managed on their own back office
            // page, and that is the page a merchant would open to look at them.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminFeatures')
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

        // A feature asked for by identifier must be one of this shop: an
        // identifier that matches nothing is said, not answered with an empty
        // list the assistant would read as "this feature has no values".
        $idFeature = null;
        if (isset($arguments['id_feature'])) {
            $idFeature = is_scalar($arguments['id_feature']) ? (int) $arguments['id_feature'] : 0;
        }

        // Queries rather than Feature::getFeatures() and
        // FeatureValue::getFeatureValuesWithLang(), which read the shop from
        // the global context: here it comes from the token and is passed
        // explicitly.
        $query = new DbQuery();
        $query->select('f.`id_feature`, fl.`name`');
        $query->from('feature', 'f');
        $query->innerJoin(
            'feature_shop',
            'fs',
            'fs.`id_feature` = f.`id_feature` AND fs.`id_shop` = ' . $idShop
        );
        // A left join, so a feature with no name in this language is still
        // listed rather than silently missing from the only list of identifiers.
        $query->leftJoin(
            'feature_lang',
            'fl',
            'fl.`id_feature` = f.`id_feature` AND fl.`id_lang` = ' . $idLang
        );
        if ($idFeature !== null) {
            $query->where('f.`id_feature` = ' . $idFeature);
        }
        // Same order as the back office and the storefront.
        $query->orderBy('f.`position` ASC, f.`id_feature` ASC');

        $features = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $features[(int) $row['id_feature']] = [
                'id_feature' => (int) $row['id_feature'],
                'name' => (string) ($row['name'] ?? ''),
                'values' => [],
            ];
        }

        if ($idFeature !== null && $features === []) {
            return SomcpTool::text(
                'No feature of this shop has this identifier. List them all with somcp_list_features, without id_feature.',
                true
            );
        }

        if ($features !== []) {
            // feature_value has no shop table: a value belongs to the shops
            // of its feature, which the query above already filtered.
            $values = new DbQuery();
            $values->select('v.`id_feature_value`, v.`id_feature`, vl.`value`');
            $values->from('feature_value', 'v');
            $values->leftJoin(
                'feature_value_lang',
                'vl',
                'vl.`id_feature_value` = v.`id_feature_value` AND vl.`id_lang` = ' . $idLang
            );
            $values->where('v.`id_feature` IN (' . implode(',', array_map('intval', array_keys($features))) . ')');
            // custom is nullable: the core treats NULL as predefined too.
            $values->where('(v.`custom` = 0 OR v.`custom` IS NULL)');
            // By identifier: feature_value only has a position column from
            // PrestaShop 9 on, and this has to run on 8.0 as well.
            $values->orderBy('v.`id_feature_value` ASC');

            foreach (Db::getInstance()->executeS($values) ?: [] as $row) {
                $features[(int) $row['id_feature']]['values'][] = [
                    'id_feature_value' => (int) $row['id_feature_value'],
                    'value' => (string) ($row['value'] ?? ''),
                ];
            }
        }

        return SomcpTool::json([
            'features' => array_values($features),
            'count' => count($features),
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
