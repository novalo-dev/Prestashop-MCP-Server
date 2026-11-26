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
 * somcp_delete_title - the Delete action of a title of Shop Parameters >
 * Customer Settings, tab Titles.
 *
 * Both versions end in ObjectModel::delete() (AdminGendersController on 8.0,
 * DeleteTitleHandler on 9.0) and check nothing: a title held by customers
 * goes, and they then show none. The tool deletes the same way, says how
 * many customers held it, and also removes the image and its cached copies,
 * which both screens leave on disk where nothing would ever remove them.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The title as it was is answered as somcp_create_title answers one.
require_once __DIR__ . '/SomcpToolCreateTitle.php';

class SomcpToolDeleteTitle
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_title',
            Somcp::t('Delete a social title'),
            'Deletes a social title of Shop Parameters > Customer Settings, tab Titles, as its Delete action: one '
                . 'title per call, Delete selected being the same call repeated. Answers the title as it was, in '
                . 'every language, so it can be created again, and how many customers held it: their accounts '
                . 'then show none. Cannot be undone: ask first. To change a title use somcp_update_title; for the '
                . 'id_gender use somcp_list_titles.',
            [
                'type' => 'object',
                'properties' => [
                    'id_gender' => [
                        'type' => 'integer',
                        'description' => 'The title to delete, from somcp_list_titles.',
                    ],
                ],
                'required' => ['id_gender'],
                'additionalProperties' => false,
            ],
            // Written with the customers' scope, as the other title tools.
            [SomcpScope::CUSTOMERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminGenders')
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

        $errors = SomcpTool::unknownFields($arguments, ['id_gender']);

        $idGender = SomcpTool::id($arguments, 'id_gender');
        $known = false;
        if (!array_key_exists('id_gender', $arguments)) {
            $errors[] = 'id_gender: required - find it with somcp_list_titles';
        } elseif ($idGender === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_gender');
        } elseif (isset(SomcpToolCreateCustomer::titles()[$idGender])) {
            // A title is shared by every shop, so it is known on all of them.
            $known = true;
        } else {
            $errors[] = SomcpTool::notFound('id_gender', 'social title', $idGender, 'somcp_list_titles');
        }
        if ($errors !== [] || !$known) {
            return SomcpTool::refused('deleted', $errors);
        }

        // Read before it goes: the answer is what it takes to create it again.
        // The image is left out, its file being removed below.
        $before = SomcpToolCreateTitle::answer($idGender, $idShop);
        unset($before['image']);
        // Counted before, since afterwards nothing tells these customers apart.
        $holders = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_gender` = ' . (int) $idGender
                . ' AND `id_shop` = ' . (int) $idShop
        );

        $title = new Gender($idGender);
        try {
            $done = Validate::isLoadedObject($title) && (bool) $title->delete();
            $failure = $done ? '' : 'PrestaShop refused to delete it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('title_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'title ' . $idGender . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text(
                'The social title could not be deleted: ' . $failure . '. Read it with somcp_list_titles before '
                    . 'calling again.',
                true
            );
        }
        // After the row: a title that could not be deleted keeps its image.
        try {
            $title->deleteImage();
        } catch (Throwable $e) {
            // The title is gone; a file left behind is not worth a failure.
        }
        SomcpToolCreateTitle::dropCachedImages($idGender);
        SomcpLogger::log('title_deleted', SomcpLogger::OUTCOME_SUCCESS, 'title ' . $idGender, '', $idEmployee);

        $result = [
            'deleted' => true,
            'id_gender' => $idGender,
            'title' => $before,
        ];
        $warnings = [];
        if ($holders > 0) {
            $warnings[] = $holders . ' customers of this shop had this title: their accounts now show none.';
        }
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'A social title is shared by every shop: it is gone from all of them.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }
}
