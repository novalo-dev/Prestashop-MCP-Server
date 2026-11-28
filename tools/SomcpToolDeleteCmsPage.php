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
 * somcp_delete_cms_page - Delete on a row of Design > Pages.
 *
 * The back office (DeleteCmsPageHandler on 9, the same list on 8) loads the
 * page and calls CMS::delete(): the ObjectModel deletion of cms, cms_lang and
 * cms_shop, then cleanPositions() of its category, so the pages after it move
 * up one place. Nothing in the core refuses it; the page of the terms of the
 * checkout is refused here because the checkout would then ask customers to
 * accept a page that no longer exists.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in:
// the page is found and answered as the get and the update find it.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolUpdateCmsSeo.php';
require_once __DIR__ . '/SomcpToolGetCmsPage.php';

class SomcpToolDeleteCmsPage
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_cms_page',
            Somcp::t('Delete a CMS page'),
            'Deletes a page of Design > Pages, as Delete, by the id_cms of somcp_list_cms_pages, one page per '
                . 'call: its texts go with it and the pages after it in its category move up one place. Answers '
                . 'the page it was. The page customers accept at checkout is refused. Changes the shop and cannot '
                . 'be undone: ask first, naming the page and its Title. To hide a page instead, use '
                . 'somcp_update_cms_page (active false).',
            [
                'type' => 'object',
                'properties' => [
                    'id_cms' => [
                        'type' => 'integer',
                        'description' => 'The CMS page to delete, from somcp_list_cms_pages.',
                    ],
                ],
                'required' => ['id_cms'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CONTENT_WRITE],
            [self::class, 'handle'],
            // Delete on a row of Design > Pages takes the delete right on AdminCmsContent.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminCmsContent')
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

        // Every mistake of the call in one refusal, so it is corrected in one go.
        $errors = SomcpTool::unknownFields($arguments, ['id_cms']);

        $cms = null;
        $idCms = SomcpTool::id($arguments, 'id_cms');
        if (!array_key_exists('id_cms', $arguments) || $arguments['id_cms'] === null) {
            $errors[] = 'id_cms: required - find it with somcp_list_cms_pages';
        } elseif ($idCms === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_cms');
        } else {
            $cms = SomcpToolUpdateCmsSeo::page($idCms, $idShop);
            if ($cms === null) {
                $errors[] = SomcpTool::notFound('id_cms', 'CMS page', $idCms, 'somcp_list_cms_pages');
            } elseif (self::isTermsPage($idCms, $idShop)) {
                $errors[] = 'id_cms: the page customers accept at checkout - choose another one first with '
                    . 'somcp_update_order_settings (terms_id_cms)';
            }
        }

        if ($errors !== [] || $cms === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        // Read before the deletion: afterwards there is nothing left to answer.
        $language = SomcpTool::readLanguage([], $idShop);
        $page = $language === null ? null : SomcpToolGetCmsPage::cmsPage($cms, $language[0], $language[1], $idShop);

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($cms, $idCms, $idShop, $idEmployee, $page): array {
            return self::apply($cms, $idCms, $idShop, $idEmployee, $page);
        });
    }

    /**
     * Deletes the page and answers with what it was.
     *
     * @param CMS $cms
     * @param int $idCms
     * @param int $idShop
     * @param int $idEmployee
     * @param array<string, mixed>|null $page the page as somcp_get_cms_page read it
     *
     * @return array<string, mixed>
     */
    private static function apply(CMS $cms, int $idCms, int $idShop, int $idEmployee, ?array $page): array
    {
        // The shop of the token only, as every tool of the pages writes: a
        // page shared with other shops stays in theirs.
        $cms->id_shop_list = [$idShop];

        try {
            $done = (bool) $cms->delete();
            $failure = $done ? '' : 'PrestaShop refused to delete it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('cms_page_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'cms ' . $idCms . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text('The page could not be deleted: ' . $failure . '.', true);
        }

        SomcpLogger::log('cms_page_deleted', SomcpLogger::OUTCOME_SUCCESS, 'cms ' . $idCms, '', $idEmployee);

        $warnings = [];
        $blocks = self::linkBlocks($idCms);
        if ($blocks !== []) {
            $warnings[] = 'The footer link block(s) ' . implode(', ', $blocks) . ' still list this page: change them '
                . 'in Design > Link List.';
        }
        if (self::otherShops($idCms) > 0) {
            $warnings[] = 'The page stays in the other shops it belongs to.';
        }
        $warnings[] = 'Links to its address now lead to a page not found.';

        return SomcpTool::json([
            'deleted' => true,
            'id_cms' => $idCms,
            'page' => $page,
            'warning' => implode(' ', $warnings),
        ]);
    }

    /**
     * Whether the checkout asks customers to accept this page.
     *
     * Only while Terms and conditions is on: with it off, the page named by
     * PS_CONDITIONS_CMS_ID is shown nowhere and can go.
     *
     * @param int $idCms
     * @param int $idShop
     *
     * @return bool
     */
    private static function isTermsPage(int $idCms, int $idShop): bool
    {
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);

        return (bool) Configuration::get('PS_CONDITIONS', null, $idShopGroup, $idShop)
            && (int) Configuration::get('PS_CONDITIONS_CMS_ID', null, $idShopGroup, $idShop) === $idCms;
    }

    /**
     * The link blocks of ps_linklist that still name the page.
     *
     * The module keeps the pages of a block in a JSON list and does not hook
     * the deletion of a page, so the block goes on naming it.
     *
     * @param int $idCms
     *
     * @return array<int, int>
     */
    private static function linkBlocks(int $idCms): array
    {
        if (!Module::isInstalled('ps_linklist')) {
            return [];
        }
        try {
            $rows = Db::getInstance()->executeS(
                'SELECT `id_link_block`, `content` FROM `' . _DB_PREFIX_ . 'link_block` ORDER BY `id_link_block` ASC'
            );
        } catch (Throwable $e) {
            // A module installed without its table names no page.
            return [];
        }
        $blocks = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $content = json_decode((string) $row['content'], true);
            $pages = is_array($content) && is_array($content['cms'] ?? null) ? $content['cms'] : [];
            // Stored as strings by the module: compared as numbers.
            if (in_array($idCms, array_map('intval', $pages), true)) {
                $blocks[] = (int) $row['id_link_block'];
            }
        }

        return $blocks;
    }

    /**
     * How many shops still hold the page after its deletion from this one.
     *
     * @param int $idCms
     *
     * @return int
     */
    private static function otherShops(int $idCms): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cms_shop` WHERE `id_cms` = ' . (int) $idCms
        );
    }
}
