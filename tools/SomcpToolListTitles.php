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
 * somcp_list_titles - the social titles of Shop Parameters > Customer Settings,
 * tab Titles.
 *
 * A handful of rows, most shops two, so the tool takes no filter: it lists
 * them all, each with its name in every language of the token's shop rather
 * than one language of them, the gender it stands for, and the picture the
 * shop keeps for it. It closes a gap of the mesh: the customer tools take an
 * id_gender, and until now the only way to learn the identifiers was the
 * refusal of SomcpToolCreateCustomer::genderProblem().
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListTitles
{
    /**
     * The type column of the core, as the back office words the Gender
     * column: what the titles of the standard installation stand for.
     *
     * @var array<int, string>
     */
    private const GENDERS = [0 => 'male', 1 => 'female', 2 => 'other'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_titles',
            Somcp::t('List social titles'),
            'Lists the social titles of Shop Parameters > Customer Settings > Titles: their name in each of '
                . 'the shop\'s languages, the gender each stands for and the picture the shop keeps for it '
                . '(a download_ref for somcp_download_file, null when there is none). The source of the '
                . 'id_gender that somcp_create_customer and somcp_update_customer take and somcp_search_customers '
                . 'filters by. Read only. The titles belong to the whole installation: every shop of it lists '
                . 'the same ones.',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_READ],
            [self::class, 'handle'],
            // The Titles tab of Customer Settings, where a merchant reads the
            // list by hand.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminGenders')
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
        // A misspelt argument would otherwise be ignored, and the assistant
        // would take the answer for the one it asked for.
        $errors = SomcpTool::unknownFields($arguments, []);
        if ($errors !== []) {
            return SomcpTool::refused('read', $errors);
        }

        $idShop = Somcp::currentShopId();

        // Every title first, whatever languages the shop has for it: the
        // page lists a title with no name in any of them as a row with an
        // empty cell, and so does the tool.
        $titles = [];
        foreach (Db::getInstance()->executeS('SELECT g.`id_gender`, g.`type` FROM `' . _DB_PREFIX_ . 'gender` g'
            . ' ORDER BY g.`id_gender`') ?: [] as $row) {
            $idGender = (int) $row['id_gender'];
            $titles[$idGender] = [
                'id_gender' => $idGender,
                'names' => [],
                'gender' => self::GENDERS[(int) $row['type']] ?? 'other',
                'image' => SomcpDownload::offer('gender_image', $idGender, $idShop),
            ];
        }

        // The names come in every language of the shop, not one language of
        // them: a merchant asking about a title in another language needs no
        // second call, and no language of the answer has to be the default.
        $query = new DbQuery();
        $query->select('gl.`id_gender`, l.`iso_code`, gl.`name`');
        $query->from('gender_lang', 'gl');
        $query->innerJoin('lang', 'l', 'l.`id_lang` = gl.`id_lang` AND l.`active` = 1');
        $query->innerJoin('lang_shop', 'ls', 'ls.`id_lang` = l.`id_lang` AND ls.`id_shop` = ' . (int) $idShop);
        $query->orderBy('gl.`id_gender` ASC, l.`id_lang` ASC');
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $idGender = (int) $row['id_gender'];
            if (!isset($titles[$idGender])) {
                continue;
            }
            $titles[$idGender]['names'][] = [
                'language' => (string) $row['iso_code'],
                'name' => (string) ($row['name'] ?? ''),
            ];
        }

        return SomcpTool::json([
            'titles' => array_values($titles),
            'count' => count($titles),
        ]);
    }
}
