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
 * somcp_list_titles - the tab Titles of Shop Parameters > Customer Settings.
 *
 * The page is the legacy AdminGenders on 8.0 and a Symfony page on 9.0, over
 * the same table gender and the same object Gender: the list reads the table
 * through the query the customer tools already use, so the id_gender they
 * take and the names their refusals give are the ones listed here.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolCreateCustomer.php';

class SomcpToolListTitles
{
    /**
     * @var array<int, string> The gender of a title, by the type Gender stores.
     *
     * The screen calls the third one Neutral on 8.0 and Other on 9.0; the tool
     * says other on both, so an answer does not change with the version.
     */
    public const GENDERS = [0 => 'male', 1 => 'female', 2 => 'other'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_titles',
            Somcp::t('List the social titles'),
            'Lists the social titles of Shop Parameters > Customer Settings, tab Titles: the name in each '
                . 'language, the gender it stands for (male, female or other) and its image. The id_gender is '
                . 'the one somcp_create_customer and somcp_update_customer take. Read only. For the title of '
                . 'one customer use somcp_get_customer, for the customer settings somcp_get_customer_settings.',
            [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, '
                            . 'for name; names holds every language. A code the shop does not have is refused. '
                            . 'The shop default language when left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            // A title is part of a customer's account, so it is read with the
            // customers' scope, as somcp_search_customers reads it.
            [SomcpScope::CUSTOMERS_READ],
            [self::class, 'handle'],
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
        $idShop = Somcp::currentShopId();
        $language = SomcpTool::readLanguage($arguments, $idShop);

        // Every mistake of the call in one refusal, so it is corrected in one go.
        $problems = SomcpTool::unknownFields($arguments, ['language']);
        if ($language === null) {
            $problems[] = SomcpTool::languageProblem($idShop);
        }
        if ($problems !== [] || $language === null) {
            return SomcpTool::refused('read', $problems);
        }
        [$idLang, $iso] = $language;

        $titles = [];
        foreach (SomcpToolCreateCustomer::titles() as $idGender => $title) {
            $titles[] = self::item($idGender, $title, $idLang, $idShop);
        }

        return SomcpTool::json([
            'titles' => $titles,
            'count' => count($titles),
            'language' => $iso,
        ]);
    }

    /**
     * One title as the list answers it.
     *
     * Public so somcp_create_title answers the title it wrote in this very
     * shape, rather than a second one that could drift.
     *
     * @param int $idGender
     * @param array{type: int, names: array<int, string>} $title as SomcpToolCreateCustomer::titles() reads it
     * @param int $idLang the language of name
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    public static function item(int $idGender, array $title, int $idLang, int $idShop): array
    {
        $names = [];
        foreach (SomcpTool::activeLanguages($idShop) as $idLanguage => $code) {
            $names[$code] = $title['names'][$idLanguage] ?? '';
        }

        return [
            'id_gender' => $idGender,
            'name' => $title['names'][$idLang] ?? '',
            'names' => $names,
            'gender' => self::GENDERS[$title['type']] ?? 'other',
            // Null when the title has no image file, as the Image column
            // then shows none.
            'image' => SomcpDownload::offer('gender_image', $idGender, $idShop),
        ];
    }
}
