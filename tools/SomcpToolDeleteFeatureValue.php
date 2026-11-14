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
 * somcp_delete_feature_value - the Delete action on one value of a feature,
 * in Catalog > Attributes & Features > Features > View.
 *
 * FeatureValue::delete() removes the value from every product, deletes its
 * texts and fires actionFeatureValueDelete; on PrestaShop 9 it also closes
 * the gap in the positions of the other values. What the answer reports is
 * read before that call: afterwards the value and its product links are gone.
 *
 * feature_value has no shop table: a value belongs to every shop of its
 * feature, and the core removes it from all of them. A value of a feature
 * that another shop also holds is therefore refused, on the same test as
 * somcp_delete_feature, so a delete from one shop never empties the others.
 *
 * Which values exist in the shop of the token, and their names, come from
 * somcp_get_feature_value, so the value tools never disagree.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// shared checks and steps live in these classes.
require_once __DIR__ . '/SomcpToolGetFeatureValue.php';
require_once __DIR__ . '/SomcpToolDeleteFeature.php';
require_once __DIR__ . '/SomcpToolCreateCatalogPriceRule.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';

class SomcpToolDeleteFeatureValue
{
    /** @var array<int, string> */
    private const FIELDS = ['id_feature_value', 'language'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_feature_value',
            Somcp::t('Delete a feature value'),
            'Deletes one predefined value of a feature (Cotton under Composition...), as Catalog > Attributes & '
                . 'Features > Features does. Irreversible: every product that has it loses it. Read products_count '
                . 'with somcp_get_feature_value, tell the merchant, confirm by name. Price rules left on it are listed '
                . 'in catalog_price_rules. id_feature_value from somcp_list_features. A custom value: '
                . 'somcp_update_product; the whole feature: somcp_delete_feature. A value of a shared feature is '
                . 'refused. One value per call. Not to be retried: if the answer is lost, look with '
                . 'somcp_get_feature_value.',
            [
                'type' => 'object',
                'properties' => [
                    'id_feature_value' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the value, from values of somcp_list_features.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages for the names in the '
                            . 'answer, for example fr; a code the shop does not have is refused. The shop default '
                            . 'language when left out.',
                    ],
                ],
                'required' => ['id_feature_value'],
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
        $idFeatureValue = SomcpTool::id($arguments, 'id_feature_value');
        $value = null;
        if ($idFeatureValue === 0) {
            // A cast would read a list as 1 and delete a value nobody named.
            $errors[] = SomcpTool::notAnIdentifier('id_feature_value');
        } else {
            // Custom values are told apart from missing ones: they exist, but
            // belong to one product, where they are changed.
            $loaded = SomcpToolGetFeatureValue::loadedValue($idFeatureValue, $idShop);
            if ($loaded === null) {
                // Refused, not ignored: a second call for a value already
                // deleted must not read as a success.
                $errors[] = SomcpTool::notFound('id_feature_value', 'value of a feature', $idFeatureValue, 'somcp_list_features');
            } elseif (SomcpToolGetFeatureValue::isCustom($loaded)) {
                $errors[] = 'id_feature_value: a custom value, typed on one product only - it belongs to that '
                    . 'product: read it with somcp_get_product and change it with somcp_update_product';
            } elseif (SomcpToolDeleteFeature::otherShops((int) $loaded->id_feature, $idShop) > 0) {
                $errors[] = 'id_feature_value: the feature of this value is shared with other shops - deleting it '
                    . 'here would remove it from their products too';
            } else {
                $value = $loaded;
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
     * @param FeatureValue $value
     * @param array{0: int, 1: string} $language
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function delete(FeatureValue $value, array $language, int $idShop, int $idEmployee): array
    {
        [$idLang, $iso] = $language;
        $idFeatureValue = (int) $value->id;
        $described = SomcpToolGetFeatureValue::featureValue($idFeatureValue, $idLang);
        $products = SomcpToolGetFeatureValue::productsCount($idFeatureValue, $idShop);
        // The value of a feature condition is the id_feature_value, and the
        // core leaves these conditions in place.
        $rules = SomcpToolCreateCatalogPriceRule::rulesOn('feature', [$idFeatureValue], $idShop);

        try {
            $done = (bool) $value->delete();
        } catch (Throwable $e) {
            $done = false;
        }
        if (!$done || SomcpToolGetFeatureValue::loadedValue($idFeatureValue, $idShop) !== null) {
            SomcpLogger::log('feature_value_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'feature value ' . $idFeatureValue, '', $idEmployee);

            return SomcpTool::text(
                'The feature value could not be deleted, and may have lost part of its products. Read it with '
                    . 'somcp_get_feature_value to see whether it is still there before anything else.',
                true
            );
        }

        SomcpLogger::log(
            'feature_value_deleted',
            SomcpLogger::OUTCOME_SUCCESS,
            'feature value ' . $idFeatureValue . ', ' . $products . ' product(s)',
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'deleted' => true,
            'id_feature_value' => $idFeatureValue,
            'id_feature' => (int) $described['id_feature'],
            'feature_name' => (string) $described['feature_name'],
            'value' => (string) $described['value'],
            'language' => $iso,
            'products_count' => $products,
            'catalog_price_rules' => $rules,
        ]);
    }
}
