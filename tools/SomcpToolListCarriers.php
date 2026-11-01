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
 * somcp_list_carriers - the carriers of this shop, as Shipping > Carriers
 * lists them, with the tracking URL of each.
 *
 * Carrier identifiers are specific to each shop and, unlike most others, move:
 * PrestaShop gives a carrier a new id_carrier every time it is edited and keeps
 * the old row, flagged deleted, so the orders placed with it keep their
 * history. id_reference is what stays. The product tools take an id_carrier
 * from here and turn it into its reference themselves, which is why both are
 * returned and the description says which one to hand over.
 *
 * In Configuration, under the orders scope: an order uses a carrier without
 * containing one, like a status, and the prompt that changes a status and its
 * tracking number is the one that receives this tool.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListCarriers
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_carriers',
            Somcp::t('List carriers'),
            'Lists the carriers of this shop as Shipping > Carriers shows them, enabled or not, '
                . 'in the order of that list: identifier, name, delay, whether it is enabled and '
                . 'free, its position, logo, tracking URL (with @ where the tracking number goes) '
                . 'and the module that computes its costs when a module created it. '
                . 'Take id_carrier from here for shipping.carriers of somcp_create_product and '
                . 'somcp_update_product: identifiers are specific to each shop, so never assume one. '
                . 'PrestaShop gives a carrier a new id_carrier each time it is edited; id_reference '
                . 'stays the same, and is what an order placed before the edit can be matched on. '
                . 'Do not use it for the carrier and tracking number of one order (somcp_get_order), '
                . 'nor to change a tracking number (somcp_set_order_tracking).',
            [
                'type' => 'object',
                'properties' => [
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'The Status filter: true for enabled carriers only, false for '
                            . 'disabled ones only. Left out, every carrier, since a disabled one can '
                            . 'still be tied to a product.',
                    ],
                    'is_free' => [
                        'type' => 'boolean',
                        'description' => 'The Free shipping filter: true for free carriers only, false for '
                            . 'paying ones only. Left out, both.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Part of the carrier name (Name), case-insensitive. Left out, every carrier.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, '
                            . 'for the delay, which is translated; a code the shop does not have is refused. '
                            . 'The shop default language when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_READ],
            [self::class, 'handle'],
            // Carriers are managed on their own back office page, and that is
            // the page a merchant would open to look at them.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminCarriers')
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

        // A query rather than Carrier::getCarriers(), which reads the shop
        // from the global context and, asked for every carrier, still drops
        // the disabled ones: here the shop comes from the token, and a
        // disabled carrier is listed as the back office lists it.
        $query = new DbQuery();
        $query->select(
            'c.`id_carrier`, c.`id_reference`, c.`name`, c.`active`, c.`is_free`, c.`position`, '
                . 'c.`url`, c.`is_module`, c.`external_module_name`, cl.`delay`'
        );
        $query->from('carrier', 'c');
        $query->innerJoin(
            'carrier_shop',
            'cs',
            'cs.`id_carrier` = c.`id_carrier` AND cs.`id_shop` = ' . $idShop
        );
        // The delay is translated per shop as well as per language.
        $query->leftJoin(
            'carrier_lang',
            'cl',
            'cl.`id_carrier` = c.`id_carrier` AND cl.`id_lang` = ' . (int) $idLang . ' AND cl.`id_shop` = ' . $idShop
        );
        // An edited carrier leaves its previous row behind, flagged, for the
        // orders placed with it; the back office list does not show those.
        $query->where('c.`deleted` = 0');
        if (isset($arguments['active']) && is_bool($arguments['active'])) {
            $query->where('c.`active` = ' . ($arguments['active'] ? 1 : 0));
        }
        if (isset($arguments['is_free']) && is_bool($arguments['is_free'])) {
            $query->where('c.`is_free` = ' . ($arguments['is_free'] ? 1 : 0));
        }
        // The order of the back office list, so a carrier sits where the
        // merchant sees it.
        $query->orderBy('c.`position` ASC, c.`id_carrier` ASC');

        // Matched on the name as shown rather than in the query: the carrier
        // the installer creates is stored as 0 and shown under the shop name.
        $name = isset($arguments['name']) && is_scalar($arguments['name'])
            ? Tools::strtolower(trim((string) $arguments['name']))
            : '';

        $carriers = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $id = (int) $row['id_carrier'];
            $shown = SomcpTool::carrierName((string) $row['name']);
            if ($name !== '' && strpos(Tools::strtolower($shown), $name) === false) {
                continue;
            }
            $carriers[] = [
                'id_carrier' => $id,
                'id_reference' => (int) $row['id_reference'],
                'name' => $shown,
                'delay' => (string) $row['delay'],
                'active' => (bool) $row['active'],
                'is_free' => (bool) $row['is_free'],
                // From 0, as the other tools give a position; the list shows
                // it from 1.
                'position' => (int) $row['position'],
                'logo' => self::logoUrl($id),
                'tracking_url' => (string) $row['url'],
                // Such a carrier's costs are computed by its module, not by
                // the ranges of the carrier form.
                'external_module' => (bool) $row['is_module'] && (string) $row['external_module_name'] !== ''
                    ? (string) $row['external_module_name']
                    : null,
            ];
        }

        return SomcpTool::json([
            'carriers' => $carriers,
            'count' => count($carriers),
            'language' => $iso,
        ]);
    }

    /**
     * Public URL of the carrier logo.
     *
     * The logo is not a column: the back office stores the uploaded file under
     * the carrier identifier, and only that file tells whether there is one.
     *
     * @param int $idCarrier
     *
     * @return string|null null when the carrier has no logo
     */
    private static function logoUrl(int $idCarrier): ?string
    {
        if (!is_file(_PS_SHIP_IMG_DIR_ . $idCarrier . '.jpg')) {
            return null;
        }
        $link = Somcp::legacyContext()->link ?? new Link();

        return (string) $link->getMediaLink(_THEME_SHIP_DIR_ . $idCarrier . '.jpg');
    }
}
