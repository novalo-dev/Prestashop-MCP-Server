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
 * somcp_get_feature_value - everything the back office value form shows for
 * one predefined feature value.
 *
 * The reader and the tests of the shop and of a custom value are public on
 * purpose: the value write tools answer with "the value as
 * somcp_get_feature_value returns it", and calling these is what keeps them
 * naming every field alike and agreeing on which values exist.
 *
 * It takes an identifier only. Finding a value from its text is the job of
 * somcp_list_features.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// reader of translated texts and the test of the feature's shop live there.
require_once __DIR__ . '/SomcpToolCreateCategory.php';
require_once __DIR__ . '/SomcpToolGetFeature.php';

class SomcpToolGetFeatureValue
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_feature_value',
            Somcp::t('Get a feature value'),
            'Returns what the value form of Catalog > Attributes & Features > Features shows for one predefined '
                . 'value (Cotton under Composition...): its text, its feature with that feature\'s name, the faceted '
                . 'search fields (null without the faceted search module) and, from PrestaShop 9 on, its position (0 '
                . 'for the first), and products_count, the products that have it. id_feature_value comes from '
                . 'somcp_list_features: never guess one. A value typed on '
                . 'one product only is refused: read it with somcp_get_product. For the feature itself use '
                . 'somcp_get_feature.',
            [
                'type' => 'object',
                'properties' => [
                    'id_feature_value' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the value, from somcp_list_features.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr; a code '
                            . 'the shop does not have is refused. The shop default language when left out.',
                    ],
                ],
                'required' => ['id_feature_value'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Values are managed under their feature, on the back office
            // features page, and that is the page a merchant would open.
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
        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }
        [$idLang, $iso] = $language;

        // A cast would read an array as 1 and answer about a value nobody
        // asked for: only a whole number is an identifier.
        $value = $arguments['id_feature_value'] ?? null;
        $idFeatureValue = is_int($value) || (is_string($value) && preg_match('/^\d{1,10}$/', trim($value)))
            ? (int) trim((string) $value)
            : 0;

        $featureValue = self::loadedValue($idFeatureValue, $idShop);
        if ($featureValue === null) {
            return SomcpTool::text('No feature value of this shop has this identifier. Find it with somcp_list_features.', true);
        }
        if (self::isCustom($featureValue)) {
            return SomcpTool::text(
                'This is a custom value, typed on one product only: it belongs to that product. Read it with somcp_get_product.',
                true
            );
        }

        return SomcpTool::json(['language' => $iso] + self::featureValue($idFeatureValue, $idLang)
            + ['products_count' => self::productsCount($idFeatureValue, $idShop)]);
    }

    /**
     * The distinct products of the shop that have this value.
     *
     * Shared with somcp_delete_feature_value, so the count the merchant hears
     * before confirming a delete is the one the delete reports.
     *
     * @param int $idFeatureValue
     * @param int $idShop
     *
     * @return int
     */
    public static function productsCount(int $idFeatureValue, int $idShop): int
    {
        // feature_product has no shop column: the product's shop row says
        // whether this shop sells it.
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT fp.`id_product`) FROM `' . _DB_PREFIX_ . 'feature_product` fp'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = fp.`id_product`'
            . ' AND ps.`id_shop` = ' . (int) $idShop
            . ' WHERE fp.`id_feature_value` = ' . (int) $idFeatureValue
        );
    }

    /**
     * The predefined value, when it exists under a feature of the shop of the
     * token. Custom values are answered like values that do not exist.
     *
     * @param int $idFeatureValue
     * @param int $idShop
     *
     * @return FeatureValue|null
     */
    public static function existingValue(int $idFeatureValue, int $idShop): ?FeatureValue
    {
        $featureValue = self::loadedValue($idFeatureValue, $idShop);

        return $featureValue !== null && !self::isCustom($featureValue) ? $featureValue : null;
    }

    /**
     * One predefined value, under the names the value tools take and answer
     * with.
     *
     * @param int $idFeatureValue
     * @param int $idLang
     *
     * @return array<string, mixed>
     */
    public static function featureValue(int $idFeatureValue, int $idLang): array
    {
        $featureValue = new FeatureValue($idFeatureValue, $idLang);
        $idFeature = (int) $featureValue->id_feature;
        $feature = new Feature($idFeature, $idLang);

        $result = [
            'id_feature_value' => $idFeatureValue,
            'id_feature' => $idFeature,
            'feature_name' => SomcpToolCreateCategory::textOf($feature->name, $idLang),
            'value' => SomcpToolCreateCategory::textOf($featureValue->value, $idLang),
            'url_name' => null,
            'meta_title' => null,
        ];

        // Only PrestaShop 9 gives values a position, and its FeatureValue has
        // a property PrestaShop 8 lacks: read from the table, under the name
        // somcp_list_features uses, and left out where the column is missing.
        if (SomcpCatalog::featureValuesHavePosition()) {
            $result['position'] = (int) Db::getInstance()->getValue(
                'SELECT `position` FROM `' . _DB_PREFIX_ . 'feature_value`'
                . ' WHERE `id_feature_value` = ' . (int) $idFeatureValue
            );
        }

        // These two fields are added to the form by ps_facetedsearch and live
        // in its own table, which only exists while it is installed. A disabled module runs
        // no hook, so the form drops them too: the same test as the write
        // tools, which refuse them then, keeps get and update in step.
        if (SomcpToolCreateFeature::facetedSearchActive()) {
            $texts = Db::getInstance()->getRow(
                'SELECT `url_name`, `meta_title` FROM `' . _DB_PREFIX_ . 'layered_indexable_feature_value_lang_value`'
                . ' WHERE `id_feature_value` = ' . (int) $idFeatureValue . ' AND `id_lang` = ' . (int) $idLang
            );
            // No row is what the form shows as empty fields: the module only
            // writes one when the form is saved.
            $result['url_name'] = is_array($texts) ? (string) ($texts['url_name'] ?? '') : '';
            $result['meta_title'] = is_array($texts) ? (string) ($texts['meta_title'] ?? '') : '';
        }

        return $result;
    }

    /**
     * The value, custom or not, when its feature is one of the shop of the
     * token.
     *
     * @param int $idFeatureValue
     * @param int $idShop
     *
     * @return FeatureValue|null
     */
    public static function loadedValue(int $idFeatureValue, int $idShop): ?FeatureValue
    {
        if ($idFeatureValue <= 0) {
            return null;
        }
        $featureValue = new FeatureValue($idFeatureValue);
        if (!Validate::isLoadedObject($featureValue)) {
            return null;
        }
        // feature_value has no shop table: a value belongs to the shops of its
        // feature, so a value of another shop's feature is not one of this shop.
        if (SomcpToolGetFeature::existingFeature((int) $featureValue->id_feature, $idShop) === null) {
            return null;
        }

        return $featureValue;
    }

    /**
     * custom is nullable, and the core reads NULL as a predefined value.
     *
     * @param FeatureValue $featureValue
     *
     * @return bool
     */
    public static function isCustom(FeatureValue $featureValue): bool
    {
        return (bool) $featureValue->custom;
    }
}
