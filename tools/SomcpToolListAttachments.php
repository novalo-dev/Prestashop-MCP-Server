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
 * somcp_list_attachments - the files of the shop (Catalogue > Files) that a
 * product can carry, with the columns of the back office list.
 *
 * It is the list an assistant reads before a call that attaches files to a
 * product, so that it never guesses an identifier. The file itself never
 * travels through the conversation: each row carries the storefront link
 * PrestaShop already gives customers on a product page, which the merchant
 * opens in a browser.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListAttachments
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_attachments',
            Somcp::t('List files'),
            'Lists the files of this shop (Catalogue > Files: manuals, data sheets) that a product '
                . 'can carry, with the columns of the back office list: identifier, name, description, '
                . 'file name, size in bytes, number of products carrying it, MIME type and a download '
                . 'link, filtered by part of the name or of the file name. Call '
                . 'it before any call that takes file identifiers - creating or changing a product - and '
                . 'take id_attachment from here: identifiers are specific to each shop, so never assume '
                . 'one. The file itself is never sent: download_url is the public storefront link, which '
                . 'works without being signed in, so anyone holding it can download the file - give it '
                . 'to the merchant only, never paste it anywhere public. Do not use it for the files of '
                . 'one product: somcp_get_product lists those. It cannot add a file.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Part of the file name shown in the back office (Name), '
                            . 'case-insensitive, read in the language of the answer.',
                    ],
                    'file_name' => [
                        'type' => 'string',
                        'description' => 'Part of the name the file was uploaded under (File), '
                            . 'case-insensitive.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr; '
                            . 'a code the shop does not have is refused. The shop default language when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_READ],
            [self::class, 'handle'],
            // Files are managed on their own back office page, and that is the
            // page a merchant would open to look at them.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminAttachments')
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

        $query = new DbQuery();
        $query->select('a.`id_attachment`, al.`name`, al.`description`, a.`file_name`, a.`file_size`, a.`mime`');
        $query->from('attachment', 'a');
        // A left join, unlike the back office list, so a file with no name in
        // this language is still listed rather than silently missing from the
        // only list of identifiers.
        $query->leftJoin(
            'attachment_lang',
            'al',
            'al.`id_attachment` = a.`id_attachment` AND al.`id_lang` = ' . $idLang
        );
        // The filters of the back office list. Name is the translated one,
        // so it is matched in the language the answer is read in.
        $name = isset($arguments['name']) && is_scalar($arguments['name']) ? trim((string) $arguments['name']) : '';
        if ($name !== '') {
            $query->where("al.`name` LIKE '%" . pSQL($name) . "%'");
        }
        $fileName = isset($arguments['file_name']) && is_scalar($arguments['file_name']) ? trim((string) $arguments['file_name']) : '';
        if ($fileName !== '') {
            $query->where("a.`file_name` LIKE '%" . pSQL($fileName) . "%'");
        }
        // Same order as the back office list.
        $query->orderBy('a.`id_attachment` ASC');
        $rows = Db::getInstance()->executeS($query) ?: [];

        // Counted apart rather than joined: DbQuery prefixes every joined
        // table, so it cannot join a subquery. Only products of this shop are
        // counted - files have no shop of their own in PrestaShop, but the
        // products carrying them do, and a count reaching into another shop
        // would cross the boundary the token sets.
        $counts = [];
        if ($rows !== []) {
            $products = new DbQuery();
            $products->select('pa.`id_attachment`, COUNT(DISTINCT pa.`id_product`) AS `products_count`');
            $products->from('product_attachment', 'pa');
            $products->innerJoin(
                'product_shop',
                'ps',
                'ps.`id_product` = pa.`id_product` AND ps.`id_shop` = ' . $idShop
            );
            $products->groupBy('pa.`id_attachment`');
            foreach (Db::getInstance()->executeS($products) ?: [] as $row) {
                $counts[(int) $row['id_attachment']] = (int) $row['products_count'];
            }
        }

        $link = Somcp::legacyContext()->link ?? new Link();

        $attachments = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_attachment'];
            $attachments[] = [
                'id_attachment' => $id,
                'name' => (string) ($row['name'] ?? ''),
                // What tells two files of the same kind apart - two manuals,
                // two data sheets - and no other tool returns it.
                'description' => (string) ($row['description'] ?? ''),
                'file_name' => (string) $row['file_name'],
                'file_size' => (int) $row['file_size'],
                'products_count' => $counts[$id] ?? 0,
                'mime' => (string) $row['mime'],
                // The storefront's own download link, served by the core
                // attachment controller: the file stays in the shop.
                'download_url' => (string) $link->getPageLink(
                    'attachment',
                    true,
                    $idLang,
                    ['id_attachment' => $id],
                    false,
                    $idShop
                ),
            ];
        }

        return SomcpTool::json([
            'attachments' => $attachments,
            'count' => count($attachments),
            'language' => $iso,
        ]);
    }
}
