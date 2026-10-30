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
 * somcp_get_manufacturer - everything the back office brand form shows for one
 * brand.
 *
 * The reader and the test of the shop are public on purpose: the brand write
 * tools answer with "the brand as somcp_get_manufacturer returns it", and
 * calling these is what keeps them naming every field alike and agreeing on
 * which brands exist.
 *
 * It takes an identifier only. Finding a brand from a name is the job of
 * somcp_list_manufacturers.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// reader of translated texts lives in that class.
require_once __DIR__ . '/SomcpToolCreateCategory.php';

class SomcpToolGetManufacturer
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_manufacturer',
            Somcp::t('Get a brand'),
            'Returns everything the back office brand (manufacturer) form shows for one brand of this shop: '
                . 'name, Enabled (active), short description, description, meta title, meta description, meta '
                . 'keywords (PrestaShop 8, null on 9), the logo address (null when the brand has none) and the '
                . 'number of products of this shop attached to it. '
                . 'Identify the brand by its identifier, from somcp_list_manufacturers: never guess one. '
                . 'Do not use it for the list of brands, which is somcp_list_manufacturers, or for the products of '
                . 'a brand: somcp_search_products has no brand filter. A brand\'s postal addresses are not part of '
                . 'the brand form and are not returned.',
            [
                'type' => 'object',
                'properties' => [
                    'id_manufacturer' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the brand, from somcp_list_manufacturers.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of the language to read the texts in, for example fr. '
                            . 'The shop default is used otherwise.',
                    ],
                ],
                'required' => ['id_manufacturer'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Brands are managed on their own back office page, and that is
            // the page a merchant would open to look at one.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminManufacturers')
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

        // A cast would read an array as 1 and answer about a brand nobody
        // asked for: only a whole number is an identifier.
        $value = $arguments['id_manufacturer'] ?? null;
        $idManufacturer = is_int($value) || (is_string($value) && preg_match('/^\d{1,10}$/', trim($value)))
            ? (int) trim((string) $value)
            : 0;

        if (self::existingManufacturer($idManufacturer, $idShop) === null) {
            return SomcpTool::text('No brand of this shop has this identifier. Find it with somcp_list_manufacturers.', true);
        }

        return SomcpTool::json(self::manufacturer($idManufacturer, $idLang, $idShop));
    }

    /**
     * The brand, when it exists in the shop of the token.
     *
     * @param int $idManufacturer
     * @param int $idShop
     *
     * @return Manufacturer|null
     */
    public static function existingManufacturer(int $idManufacturer, int $idShop): ?Manufacturer
    {
        if ($idManufacturer <= 0) {
            return null;
        }
        // A brand of another shop is answered like one that does not exist:
        // the Manufacturer constructor takes no shop and would load it anyway.
        $inShop = (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'manufacturer_shop` WHERE `id_manufacturer` = ' . (int) $idManufacturer
            . ' AND `id_shop` = ' . (int) $idShop
        );
        if (!$inShop) {
            return null;
        }
        $manufacturer = new Manufacturer($idManufacturer);

        return Validate::isLoadedObject($manufacturer) ? $manufacturer : null;
    }

    /**
     * One brand, under the names the brand tools take and answer with.
     *
     * @param int $idManufacturer
     * @param int $idLang
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    public static function manufacturer(int $idManufacturer, int $idLang, int $idShop): array
    {
        $manufacturer = new Manufacturer($idManufacturer, $idLang);
        $link = Somcp::legacyContext()->link ?? new Link();

        // Not Link::getManufacturerImageLink(), which falls back on the
        // "no picture" image of the language: a brand without a logo must say
        // so, not hand over a placeholder as if it were its logo.
        $logo = null;
        if (is_file(_PS_MANU_IMG_DIR_ . $idManufacturer . '.jpg')) {
            $logo = (string) $link->getMediaLink(_THEME_MANU_DIR_ . $idManufacturer . '.jpg');
        }

        // meta_keywords is gone from PrestaShop 9: read from the object's
        // properties, since a property access is an unknown property to static
        // analysis against that version even behind the definition check.
        $properties = get_object_vars($manufacturer);

        return [
            'id_manufacturer' => $idManufacturer,
            'name' => (string) $manufacturer->name,
            'active' => (bool) $manufacturer->active,
            'short_description' => SomcpToolCreateCategory::textOf($manufacturer->short_description, $idLang),
            'description' => SomcpToolCreateCategory::textOf($manufacturer->description, $idLang),
            'meta_title' => SomcpToolCreateCategory::textOf($manufacturer->meta_title, $idLang),
            'meta_description' => SomcpToolCreateCategory::textOf($manufacturer->meta_description, $idLang),
            'meta_keywords' => isset(Manufacturer::$definition['fields']['meta_keywords']) ? SomcpToolCreateCategory::textOf($properties['meta_keywords'] ?? '', $idLang) : null,
            'logo' => $logo,
            'products_count' => self::productsCount($idManufacturer, $idShop),
        ];
    }

    /**
     * The number of products of a brand in a shop, the Products column of the
     * Brands list. Shared with somcp_list_manufacturers, so the list and the
     * page of one brand never count differently.
     *
     * @param int $idManufacturer
     * @param int $idShop
     *
     * @return int
     */
    public static function productsCount(int $idManufacturer, int $idShop): int
    {
        // The brand is on the product itself, not per shop: only the
        // products of this shop are counted, as everywhere else.
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT p.`id_product`) FROM `' . _DB_PREFIX_ . 'product` p'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . (int) $idShop
            . ' WHERE p.`id_manufacturer` = ' . (int) $idManufacturer
        );
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
