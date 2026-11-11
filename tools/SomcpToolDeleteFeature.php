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
 * somcp_delete_feature - the Delete action of Catalog > Attributes & Features
 * > Features.
 *
 * Feature::delete() takes the values of the feature and their texts with it
 * and removes it from every product, then reorders the positions and fires
 * actionFeatureDelete. Everything the answer reports is counted before that
 * call: afterwards the values and the links to the products are gone.
 *
 * Unlike the attribute classes, Feature::delete() has no multistore guard:
 * the values and the product links go for every shop, whatever id_shop_list
 * says. A feature that another shop also holds is therefore refused, so a
 * delete from one shop never empties it in the others; the tool never
 * switches to the all-shops context itself.
 *
 * Which features exist in the shop of the token, and their names, come from
 * somcp_get_feature, so the feature tools never disagree.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// shared checks and steps live in these classes.
require_once __DIR__ . '/SomcpToolGetFeature.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';

class SomcpToolDeleteFeature
{
    /** @var array<int, string> */
    private const FIELDS = ['id_feature', 'language'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_feature',
            Somcp::t('Delete a feature'),
            'Deletes one feature (Composition, Material...) with all its values, as Catalog > Attributes & Features '
                . '> Features does. Irreversible: every product that shows it loses it. Read products_count with '
                . 'somcp_get_feature, tell the merchant, confirm by name. Price rules left on its values are listed '
                . 'in catalog_price_rules. id_feature from somcp_list_features. A feature shared with other shops is '
                . 'refused. One feature per call. Not to be retried: if the answer is lost, look with '
                . 'somcp_get_feature.',
            [
                'type' => 'object',
                'properties' => [
                    'id_feature' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the feature, from somcp_list_features or somcp_get_feature.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages for the name in the '
                            . 'answer, for example fr; a code the shop does not have is refused. The shop default '
                            . 'language when left out.',
                    ],
                ],
                'required' => ['id_feature'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // The right the Delete action of the features page takes: edit
            // alone does not let the merchant do it by hand.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminFeatures')
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
        $idFeature = SomcpTool::id($arguments, 'id_feature');
        $feature = null;
        if ($idFeature === 0) {
            // A cast would read a list as 1 and delete a feature nobody named.
            $errors[] = SomcpTool::notAnIdentifier('id_feature');
        } else {
            // Refused, not ignored: a second call for a feature already
            // deleted must not read as a success.
            $feature = SomcpToolGetFeature::existingFeature($idFeature, $idShop);
            if ($feature === null) {
                $errors[] = SomcpTool::notFound('id_feature', 'feature', $idFeature, 'somcp_list_features');
            } else {
                $others = self::otherShops($idFeature, $idShop);
                if ($others > 0) {
                    $errors[] = 'id_feature: shared with ' . $others . ' other shop(s); deleting it here would remove '
                        . 'its values from all of them - delete it from the back office in the all-shops context';
                }
            }
        }
        if ($errors !== [] || $feature === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        $language = SomcpTool::readLanguage($arguments, $idShop);
        if ($language === null) {
            return SomcpTool::languageRefused($idShop);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($feature, $language, $idShop, $idEmployee): array {
            return self::delete($feature, $language, $idShop, $idEmployee);
        });
    }

    /**
     * Counts what goes with the feature, deletes it, and answers.
     *
     * @param Feature $feature
     * @param array{0: int, 1: string} $language
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function delete(Feature $feature, array $language, int $idShop, int $idEmployee): array
    {
        [$idLang, $iso] = $language;
        $idFeature = (int) $feature->id;
        $described = SomcpToolGetFeature::feature($idFeature, $idLang, $idShop);
        $db = Db::getInstance();

        // Custom values included: the core deletes them with the predefined
        // ones, so they are part of what the merchant loses.
        $valuesDeleted = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'feature_value` WHERE `id_feature` = ' . (int) $idFeature
        );
        $products = (int) $described['products_count'];
        $rules = self::priceRules($idFeature, $idShop);

        $feature->id_shop_list = [$idShop];
        try {
            $done = (bool) $feature->delete();
        } catch (Throwable $e) {
            $done = false;
        }
        if (!$done || SomcpToolGetFeature::existingFeature($idFeature, $idShop) !== null) {
            SomcpLogger::log('feature_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'feature ' . $idFeature, '', $idEmployee);

            return SomcpTool::text(
                'The feature could not be deleted, and may have lost part of its values or products. Read it with '
                    . 'somcp_get_feature to see what it still holds before anything else.',
                true
            );
        }

        SomcpLogger::log(
            'feature_deleted',
            SomcpLogger::OUTCOME_SUCCESS,
            'feature ' . $idFeature . ', ' . $valuesDeleted . ' value(s), ' . $products . ' product(s)',
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'deleted' => true,
            'id_feature' => $idFeature,
            'name' => (string) $described['name'],
            'language' => $iso,
            'values_deleted' => $valuesDeleted,
            'products' => $products,
            'catalog_price_rules' => $rules,
        ]);
    }

    /**
     * The number of other shops that hold the feature.
     *
     * Public so the value delete refuses a value of a shared feature on the
     * same test: the core removes a value from every shop of its feature.
     *
     * @param int $idFeature
     * @param int $idShop
     *
     * @return int
     */
    public static function otherShops(int $idFeature, int $idShop): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'feature_shop` WHERE `id_feature` = ' . (int) $idFeature
            . ' AND `id_shop` <> ' . (int) $idShop
        );
    }

    /**
     * The catalogue price rules of this shop with a condition on one of the
     * feature's values, which the core leaves behind.
     *
     * @param int $idFeature
     * @param int $idShop
     *
     * @return array<int, int>
     */
    private static function priceRules(int $idFeature, int $idShop): array
    {
        // The value of a feature condition is the id_feature_value, as
        // somcp_create_catalog_price_rule writes it.
        $rows = Db::getInstance()->executeS(
            'SELECT DISTINCT g.`id_specific_price_rule` FROM `' . _DB_PREFIX_ . 'specific_price_rule_condition` c'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'specific_price_rule_condition_group` g'
            . ' ON g.`id_specific_price_rule_condition_group` = c.`id_specific_price_rule_condition_group`'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'specific_price_rule` r ON r.`id_specific_price_rule` = g.`id_specific_price_rule`'
            . ' AND r.`id_shop` = ' . (int) $idShop
            . ' INNER JOIN `' . _DB_PREFIX_ . 'feature_value` v ON v.`id_feature_value` = CAST(c.`value` AS UNSIGNED)'
            . ' AND v.`id_feature` = ' . (int) $idFeature
            . ' WHERE c.`type` = \'feature\''
            . ' ORDER BY g.`id_specific_price_rule`'
        ) ?: [];

        return array_map(static function (array $row): int {
            return (int) $row['id_specific_price_rule'];
        }, $rows);
    }
}
