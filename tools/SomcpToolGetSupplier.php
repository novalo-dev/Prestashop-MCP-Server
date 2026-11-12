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

/*
 * somcp_get_supplier - everything the back office supplier form shows for one
 * supplier.
 *
 * The reader and the test of the shop are public on purpose, like those of
 * somcp_get_manufacturer: the supplier write tools answer with "the supplier
 * as somcp_get_supplier returns it", and calling these is what keeps them
 * naming every field alike and agreeing on which suppliers exist.
 *
 * It takes an identifier only. Finding a supplier from a name is the job of
 * somcp_list_suppliers.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// reader of translated texts lives in that class.
require_once __DIR__ . '/SomcpToolCreateCategory.php';

class SomcpToolGetSupplier
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_supplier',
            Somcp::t('Get a supplier'),
            'Returns what the supplier form of Catalog > Brands & Suppliers > Suppliers shows for one '
                . 'supplier: name, Enabled, description, the contact details of its address with country and '
                . 'state as ISO codes, the meta texts (keywords null on PrestaShop 9), the logo address (null '
                . 'without one) and the number of products of this shop it supplies. id_supplier comes from '
                . 'somcp_list_suppliers: never guess one. For the list use somcp_list_suppliers; '
                . 'somcp_search_products has no supplier filter.',
            [
                'type' => 'object',
                'properties' => [
                    'id_supplier' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the supplier, from somcp_list_suppliers.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr; a code '
                            . 'the shop does not have is refused. The shop default language when left out.',
                    ],
                ],
                'required' => ['id_supplier'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Suppliers are managed on their own back office page, and that is
            // the page a merchant would open to look at one.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminSuppliers')
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

        // A cast would read an array as 1 and answer about a supplier nobody
        // asked for: only a whole number is an identifier.
        $idSupplier = SomcpTool::id($arguments, 'id_supplier');
        $errors = SomcpTool::unknownFields($arguments, ['id_supplier', 'language']);
        if ($idSupplier === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_supplier');
        } elseif (self::existingSupplier($idSupplier, $idShop) === null) {
            $errors[] = SomcpTool::notFound('id_supplier', 'supplier', $idSupplier, 'somcp_list_suppliers');
        }
        if ($errors !== []) {
            return SomcpTool::refused('read', $errors);
        }

        return SomcpTool::json(['language' => $iso] + self::supplier($idSupplier, $idLang, $idShop));
    }

    /**
     * The supplier, when it exists in the shop of the token.
     *
     * @param int $idSupplier
     * @param int $idShop
     *
     * @return Supplier|null
     */
    public static function existingSupplier(int $idSupplier, int $idShop): ?Supplier
    {
        if ($idSupplier <= 0) {
            return null;
        }
        // A supplier of another shop is answered like one that does not
        // exist: the Supplier constructor takes no shop and would load it
        // anyway.
        $inShop = (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'supplier_shop` WHERE `id_supplier` = ' . (int) $idSupplier
            . ' AND `id_shop` = ' . (int) $idShop
        );
        if (!$inShop) {
            return null;
        }
        $supplier = new Supplier($idSupplier);

        return Validate::isLoadedObject($supplier) ? $supplier : null;
    }

    /**
     * One supplier, under the names the supplier tools take and answer with.
     *
     * @param int $idSupplier
     * @param int $idLang
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    public static function supplier(int $idSupplier, int $idLang, int $idShop): array
    {
        $supplier = new Supplier($idSupplier, $idLang);
        $link = Somcp::legacyContext()->link ?? new Link();

        // Not Link::getSupplierImageLink(), which falls back on the "no
        // picture" image of the language: a supplier without a logo must say
        // so, not hand over a placeholder as if it were its logo.
        $logo = null;
        if (is_file(_PS_SUPP_IMG_DIR_ . $idSupplier . '.jpg')) {
            $logo = (string) $link->getMediaLink(_THEME_SUP_DIR_ . $idSupplier . '.jpg');
        }

        // meta_keywords is gone from PrestaShop 9: read from the object's
        // properties, since a property access is an unknown property to static
        // analysis against that version even behind the definition check.
        $properties = get_object_vars($supplier);

        $result = [
            'id_supplier' => $idSupplier,
            'name' => (string) $supplier->name,
            'active' => (bool) $supplier->active,
            'description' => SomcpToolCreateCategory::textOf($supplier->description, $idLang),
        ];

        return $result + self::contact($idSupplier) + [
            'meta_title' => SomcpToolCreateCategory::textOf($supplier->meta_title, $idLang),
            'meta_description' => SomcpToolCreateCategory::textOf($supplier->meta_description, $idLang),
            'meta_keywords' => isset(Supplier::$definition['fields']['meta_keywords']) ? SomcpToolCreateCategory::textOf($properties['meta_keywords'] ?? '', $idLang) : null,
            'logo' => $logo,
            'products_count' => self::productsCount($idSupplier, $idShop),
        ];
    }

    /**
     * The number of products of this shop linked to a supplier, shared with
     * somcp_list_suppliers so that the list and the page of one supplier
     * always give the same figure.
     *
     * @param int $idSupplier
     * @param int $idShop
     *
     * @return int
     */
    public static function productsCount(int $idSupplier, int $idShop): int
    {
        // Counted on product_supplier, as the back office supplier list
        // does: a product can have several suppliers, and the one it names by
        // default is only one of them. Only the products of this shop are
        // counted, as everywhere else.
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT psu.`id_product`) FROM `' . _DB_PREFIX_ . 'product_supplier` psu'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = psu.`id_product` AND ps.`id_shop` = ' . (int) $idShop
            . ' WHERE psu.`id_supplier` = ' . (int) $idSupplier
        );
    }

    /**
     * The contact fields of the supplier form, which the core keeps on an
     * address row of the supplier rather than on the supplier itself.
     *
     * @param int $idSupplier
     *
     * @return array<string, mixed>
     */
    private static function contact(int $idSupplier): array
    {
        // The core's own lookup, the one the supplier form reads: it skips a
        // deleted address and one that also belongs to a customer or a brand.
        $idAddress = (int) Address::getAddressIdBySupplierId($idSupplier);
        $address = $idAddress > 0 ? new Address($idAddress) : null;
        if ($address === null || !Validate::isLoadedObject($address)) {
            // A supplier created without contact details has no address row:
            // its form shows empty fields, so the answer does too.
            $address = null;
        }

        $country = null;
        $state = null;
        if ($address !== null && (int) $address->id_country > 0) {
            $country = (string) Country::getIsoById((int) $address->id_country) ?: null;
        }
        if ($address !== null && (int) $address->id_state > 0) {
            $stateObject = new State((int) $address->id_state);
            $state = Validate::isLoadedObject($stateObject) ? (string) $stateObject->iso_code : null;
        }

        return [
            'phone' => $address !== null ? (string) $address->phone : '',
            'phone_mobile' => $address !== null ? (string) $address->phone_mobile : '',
            'address1' => $address !== null ? (string) $address->address1 : '',
            'address2' => $address !== null ? (string) $address->address2 : '',
            'postcode' => $address !== null ? (string) $address->postcode : '',
            'city' => $address !== null ? (string) $address->city : '',
            'country' => $country,
            'state' => $state,
            'dni' => $address !== null ? (string) $address->dni : '',
        ];
    }
}
