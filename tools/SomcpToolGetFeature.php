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
 * somcp_get_feature - everything the back office feature form shows for one
 * feature.
 *
 * The reader and the test of the shop are public on purpose: the feature write
 * tools answer with "the feature as somcp_get_feature returns it", and calling
 * these is what keeps them naming every field alike and agreeing on which
 * features exist.
 *
 * It takes an identifier only. Finding a feature from a name is the job of
 * somcp_list_features.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// reader of translated texts lives in that class.
require_once __DIR__ . '/SomcpToolCreateCategory.php';

class SomcpToolGetFeature
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_feature',
            Somcp::t('Get a feature'),
            'Returns what the feature form of Catalog > Attributes & Features > Features shows for one feature '
                . '(Composition, Material...): name, position (0 for the first), the number of its predefined values, '
                . 'products_count (the products that show it) and the faceted search fields, null without the faceted '
                . 'search module. id_feature comes from '
                . 'somcp_list_features: never guess one. For the list and the names of the values use '
                . 'somcp_list_features; for one value somcp_get_feature_value.',
            [
                'type' => 'object',
                'properties' => [
                    'id_feature' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the feature, from somcp_list_features.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr; a code '
                            . 'the shop does not have is refused. The shop default language when left out.',
                    ],
                ],
                'required' => ['id_feature'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Features are managed on their own back office page, and that is
            // the page a merchant would open to look at one.
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

        // A cast would read an array as 1 and answer about a feature nobody
        // asked for: only a whole number is an identifier.
        $value = $arguments['id_feature'] ?? null;
        $idFeature = is_int($value) || (is_string($value) && preg_match('/^\d{1,10}$/', trim($value)))
            ? (int) trim((string) $value)
            : 0;

        if (self::existingFeature($idFeature, $idShop) === null) {
            return SomcpTool::text('No feature of this shop has this identifier. Find it with somcp_list_features.', true);
        }

        return SomcpTool::json(['language' => $iso] + self::feature($idFeature, $idLang, $idShop));
    }

    /**
     * The feature, when it exists in the shop of the token.
     *
     * @param int $idFeature
     * @param int $idShop
     *
     * @return Feature|null
     */
    public static function existingFeature(int $idFeature, int $idShop): ?Feature
    {
        if ($idFeature <= 0) {
            return null;
        }
        // A feature of another shop is answered like one that does not exist:
        // the Feature constructor takes no shop and would load it anyway.
        $inShop = (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'feature_shop` WHERE `id_feature` = ' . (int) $idFeature
            . ' AND `id_shop` = ' . (int) $idShop
        );
        if (!$inShop) {
            return null;
        }
        $feature = new Feature($idFeature);

        return Validate::isLoadedObject($feature) ? $feature : null;
    }

    /**
     * One feature, under the names the feature tools take and answer with.
     *
     * @param int $idFeature
     * @param int $idLang
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    public static function feature(int $idFeature, int $idLang, int $idShop): array
    {
        $feature = new Feature($idFeature, $idLang);

        $result = [
            'id_feature' => $idFeature,
            'name' => SomcpToolCreateCategory::textOf($feature->name, $idLang),
            'position' => (int) $feature->position,
            // Predefined values only, as somcp_list_features lists them: a
            // custom value is typed on one product and belongs to it alone.
            // custom is nullable, and the core reads NULL as predefined too.
            'values_count' => (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'feature_value`'
                . ' WHERE `id_feature` = ' . (int) $idFeature
                . ' AND (`custom` = 0 OR `custom` IS NULL)'
            ),
            // What a delete would take it off, so the merchant hears it before
            // confirming: somcp_delete_feature counts with the same function.
            'products_count' => self::productsCount($idFeature, $idShop),
            'url_name' => null,
            'meta_title' => null,
            'indexable' => null,
        ];

        // These three fields are added to the form by ps_facetedsearch and
        // live in its own tables, which only exist while it is installed. A disabled module runs
        // no hook, so the form drops them too: the same test as the write
        // tools, which refuse them then, keeps get and update in step.
        if (SomcpToolCreateFeature::facetedSearchActive()) {
            $texts = Db::getInstance()->getRow(
                'SELECT `url_name`, `meta_title` FROM `' . _DB_PREFIX_ . 'layered_indexable_feature_lang_value`'
                . ' WHERE `id_feature` = ' . (int) $idFeature . ' AND `id_lang` = ' . (int) $idLang
            );
            // No row is what the form shows as empty fields and an unticked
            // box: the module only writes one when the form is saved.
            $result['url_name'] = is_array($texts) ? (string) ($texts['url_name'] ?? '') : '';
            $result['meta_title'] = is_array($texts) ? (string) ($texts['meta_title'] ?? '') : '';
            $result['indexable'] = (bool) Db::getInstance()->getValue(
                'SELECT `indexable` FROM `' . _DB_PREFIX_ . 'layered_indexable_feature`'
                . ' WHERE `id_feature` = ' . (int) $idFeature
            );
        }

        return $result;
    }

    /**
     * The distinct products of the shop that show the feature, with a
     * predefined or a custom value.
     *
     * @param int $idFeature
     * @param int $idShop
     *
     * @return int
     */
    public static function productsCount(int $idFeature, int $idShop): int
    {
        // feature_product has no shop column: the product's shop row says
        // whether this shop sells it.
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT fp.`id_product`) FROM `' . _DB_PREFIX_ . 'feature_product` fp'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = fp.`id_product`'
            . ' AND ps.`id_shop` = ' . (int) $idShop
            . ' WHERE fp.`id_feature` = ' . (int) $idFeature
        );
    }
}
