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
 * somcp_delete_meta_page - the Delete action of a page of Shop Parameters >
 * Traffic & SEO > SEO & URLs.
 *
 * The back office (MetaEraser on 8 and 9) calls Meta::delete() on each page:
 * the rows of meta and meta_lang go for every shop, since a page has no shop
 * of its own, and .htaccess is written again so the rewriting rules follow.
 * No other table holds an id_meta: the storefront finds a page by its name,
 * and a page without its row still answers at index.php?controller=<page>.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// page loader, the reading of its texts and the employee switch are theirs.
require_once __DIR__ . '/SomcpToolUpdateMetaPage.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';

class SomcpToolDeleteMetaPage
{
    /** The home page, served at the root of the shop. */
    private const HOME = 'index';

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_meta_page',
            Somcp::t('Delete a shop page from SEO & URLs'),
            'Deletes a page of Shop Parameters > Traffic & SEO > SEO & URLs, as its Delete action: one page per '
                . 'call, its Page title, Meta description and Rewritten URL in every language with it. Answers '
                . 'the texts it had, to create it again. Cannot be undone: ask first, naming the page and its '
                . 'Rewritten URL. Take id_meta from somcp_list_meta_pages; to empty a text use '
                . 'somcp_update_meta_page.',
            [
                'type' => 'object',
                'properties' => [
                    'id_meta' => [
                        'type' => 'integer',
                        'description' => 'The page to delete, from somcp_list_meta_pages.',
                    ],
                ],
                'required' => ['id_meta'],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_WRITE],
            [self::class, 'handle'],
            // The Delete action of the list of Traffic & SEO, whose controller
            // is AdminMeta, takes the delete right there.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminMeta')
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
        $idShop = Somcp::currentShopId();
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = SomcpTool::unknownFields($arguments, ['id_meta']);

        $meta = null;
        $idMeta = SomcpTool::id($arguments, 'id_meta');
        if (!array_key_exists('id_meta', $arguments)) {
            $errors[] = 'id_meta: required - find it with somcp_list_meta_pages';
        } elseif ($idMeta === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_meta');
        } else {
            $loaded = SomcpToolUpdateMetaPage::page($idMeta, $idShop);
            if ($loaded === null) {
                $errors[] = SomcpTool::notFound('id_meta', 'page', $idMeta, 'somcp_list_meta_pages');
            } elseif (!(bool) $loaded->configurable) {
                // The list does not show these pages, so the screen cannot
                // delete them: the product, category and CMS templates.
                $errors[] = SomcpToolUpdateMetaPage::notConfigurable($loaded);
            } else {
                $meta = $loaded;
            }
        }

        if ($errors !== [] || $meta === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($meta, $idShop, $idEmployee): array {
            return self::delete($meta, $idShop, $idEmployee);
        });
    }

    /**
     * @param Meta $meta
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function delete(Meta $meta, int $idShop, int $idEmployee): array
    {
        $idMeta = (int) $meta->id;
        $page = (string) $meta->page;
        // Read before it goes: the answer is what it takes to create it
        // again, in every language of the shop.
        $before = SomcpToolUpdateMetaPage::describe($meta, SomcpTool::activeLanguages($idShop));

        try {
            $done = (bool) $meta->delete();
            $failure = $done ? '' : 'PrestaShop refused to delete it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        // Meta::delete() answers false as well when the rows are gone but
        // .htaccess could not be written again: the page is read again to
        // tell the two apart.
        $gone = !(bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'meta` WHERE `id_meta` = ' . $idMeta
        );
        if (!$done && !$gone) {
            SomcpLogger::log('meta_page_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'meta ' . $idMeta . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text('The page could not be deleted: ' . $failure . '.', true);
        }
        // The identifier and the controller name only: the texts are the
        // merchant's.
        SomcpLogger::log('meta_page_deleted', SomcpLogger::OUTCOME_SUCCESS, 'meta ' . $idMeta . ' (' . $page . ')', '', $idEmployee);

        $result = [
            'deleted' => true,
            'id_meta' => $idMeta,
            'page' => $page,
            'texts' => $before['texts'],
        ];
        $warnings = [];
        if (!$done) {
            $warnings[] = 'The page was deleted, but the .htaccess file could not be written again: save Set up URLs '
                . 'once in Traffic & SEO.';
        }
        if ($page === self::HOME) {
            $warnings[] = 'The home page no longer has a page title nor a meta description of its own.';
        } elseif (strpos($page, 'module-') !== 0) {
            $warnings[] = $page . ' no longer has a Rewritten URL: it answers at index.php?controller=' . $page
                . ', and links to its old address lead nowhere.';
        }
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'The page is deleted for every shop: its texts were kept by shop, the page itself is shared.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }
}
