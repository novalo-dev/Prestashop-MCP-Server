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
 * somcp_update_title - Edit then Save of a social title of Shop Parameters >
 * Customer Settings, tab Titles.
 *
 * As EditTitleHandler (9.0) and AdminGendersController (8.0) do, on the
 * Gender object: only the fields given change, each checked by
 * SomcpToolCreateTitle::planTitle(), the very rules of the create, and the
 * image written by SomcpToolCreateTitle::storeImage(). An item of
 * somcp_list_titles given back as it is changes nothing.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolCreateTitle.php';

class SomcpToolUpdateTitle
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $schema = SomcpToolCreateTitle::titleSchema(['id_gender']);
        $schema['properties'] = [
            'id_gender' => [
                'type' => 'integer',
                'description' => 'The social title to change, from somcp_list_titles.',
            ],
        ] + $schema['properties'];
        // Declared so a client that checks arguments against the schema
        // accepts an item of the list given back whole.
        $schema['properties']['names'] = [
            'type' => 'object',
            'description' => 'The name in every language, as somcp_list_titles answers it: taken as name.',
            'additionalProperties' => ['type' => 'string'],
        ];
        $schema['properties']['image']['description'] = 'Image shown beside the title: a new picture, which '
            . 'replaces the current one. The download_ref somcp_list_titles answered is no change. Cannot be '
            . 'removed, only replaced.';

        return new SomcpTool(
            'somcp_update_title',
            Somcp::t('Change a social title'),
            'Changes a social title of Shop Parameters > Customer Settings, tab Titles, as Edit then Save: its '
                . 'name per language, the gender it stands for, its image. Only the fields given change, a '
                . 'language left out keeping its name; an item of somcp_list_titles given back as it is changes '
                . 'nothing. An image can be replaced, not removed. To add a title use somcp_create_title, for the '
                . 'title of one customer somcp_update_customer.',
            $schema,
            // The scope the list and the creation of a title use.
            [SomcpScope::CUSTOMERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminGenders'),
            files: ['image' => SomcpUpload::images()]
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

        $errors = SomcpTool::unknownFields(
            $arguments,
            array_merge(['id_gender', 'names'], SomcpToolCreateTitle::TITLE_FIELDS)
        );

        $stored = null;
        $idGender = SomcpTool::id($arguments, 'id_gender');
        if (!array_key_exists('id_gender', $arguments)) {
            $errors[] = 'id_gender: required - find it with somcp_list_titles';
        } elseif ($idGender === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_gender');
        } else {
            // A title is shared by every shop, so it is known on all of them.
            $stored = SomcpToolCreateCustomer::titles()[$idGender] ?? null;
            if ($stored === null) {
                $errors[] = SomcpTool::notFound('id_gender', 'social title', $idGender, 'somcp_list_titles');
            }
        }

        $given = self::givenBack($arguments, $idGender, $stored, $idShop, $errors);
        $plan = SomcpToolCreateTitle::planTitle($given, $idShop, $errors, false);
        if ($errors !== [] || $plan === null || $stored === null) {
            return SomcpTool::refused('changed', $errors);
        }

        $changes = [];
        $names = $stored['names'];
        foreach ($plan['name'] ?? [] as $idLang => $name) {
            $old = $stored['names'][$idLang] ?? '';
            if ($name !== $old) {
                $changes[] = ['field' => 'name (' . $plan['languages']['filled'][$idLang] . ')', 'old' => $old, 'new' => $name];
                $names[$idLang] = $name;
            }
        }
        $genderChanged = isset($plan['type']) && $plan['type'] !== $stored['type'];
        if ($genderChanged) {
            $changes[] = [
                'field' => 'gender',
                'old' => SomcpToolListTitles::GENDERS[$stored['type']] ?? 'other',
                'new' => SomcpToolListTitles::GENDERS[$plan['type']],
            ];
        }
        if (isset($plan['image'])) {
            $changes[] = ['field' => 'image', 'old' => null, 'new' => SomcpUpload::origin($plan['image'])];
        }

        if ($changes === []) {
            return SomcpTool::json([
                'changed' => false,
                'changes' => [],
                'reason' => 'The social title already holds every value given. Nothing was written.',
                'title' => SomcpToolCreateTitle::answer($idGender, $idShop),
            ]);
        }

        $file = '';
        try {
            if (isset($plan['image'])) {
                // Fetched before anything is written: a picture the shop
                // cannot receive must not leave a half-changed title.
                $refusal = SomcpToolUpdateLanguage::fetch($plan['image'], $file);
                if ($refusal !== '') {
                    return SomcpTool::refused('changed', ['image: ' . $refusal]);
                }
            }

            return self::write($idGender, $names, $genderChanged ? $plan['type'] : null, $file, $plan, $changes, $idShop, $idEmployee);
        } finally {
            if ($file !== '') {
                @unlink($file);
            }
        }
    }

    /**
     * The arguments with what an item of the list gives back unchanged left out.
     *
     * @param array<string, mixed> $arguments
     * @param int $idGender
     * @param array{type: int, names: array<int, string>}|null $stored
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>
     */
    private static function givenBack(array $arguments, int $idGender, ?array $stored, int $idShop, array &$errors): array
    {
        $given = $arguments;
        unset($given['id_gender'], $given['names']);

        if (array_key_exists('image', $arguments)) {
            if ($arguments['image'] === null) {
                // Neither screen removes a picture: another one replaces it.
                $errors[] = 'image: a social title\'s image cannot be removed, only replaced';
                unset($given['image']);
            } elseif ($stored !== null && SomcpUpload::same($arguments['image'], 'gender_image', (string) $idGender)) {
                unset($given['image']);
            }
        }
        if ($stored === null) {
            return $given;
        }

        $probe = [];
        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $probe);
        if ($languages === null) {
            return $given;
        }
        $given = SomcpToolCreateProduct::nameGivenBack($arguments, $given, $stored['names'], $languages, $idShop, 'somcp_list_titles', $errors);

        return $given;
    }

    /**
     * Saves the name and gender, then the image, and answers.
     *
     * @param int $idGender
     * @param array<int, string> $names every language, the changed ones merged in
     * @param int|null $type null to keep the gender
     * @param string $file the image in hand, '' without one
     * @param array<string, mixed> $plan from planTitle()
     * @param array<int, array<string, mixed>> $changes
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function write(int $idGender, array $names, ?int $type, string $file, array $plan, array $changes, int $idShop, int $idEmployee): array
    {
        $fields = array_column($changes, 'field');
        $textChanged = array_filter($fields, static function (string $field): bool {
            return $field !== 'image';
        }) !== [];

        $failure = '';
        if ($textChanged) {
            try {
                $title = new Gender($idGender);
                $title->name = $names;
                if ($type !== null) {
                    $title->type = $type;
                }
                $failure = $title->update() ? '' : 'PrestaShop refused to save it';
            } catch (Throwable $e) {
                $failure = $e->getMessage();
            }
        }
        if ($failure === '' && $file !== '') {
            $refusal = SomcpToolCreateTitle::storeImage($idGender, $file, $plan['image_width'], $plan['image_height']);
            if ($refusal !== '') {
                $failure = $textChanged
                    ? 'the name and gender were saved, the image was not: ' . $refusal
                    : 'the image ' . $refusal;
            }
        }
        if ($failure !== '') {
            SomcpLogger::log('title_update_failed', SomcpLogger::OUTCOME_FAILURE, 'title ' . $idGender . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text('The social title could not be changed: ' . $failure
                . '. Read it with somcp_list_titles before calling again.', true);
        }
        SomcpLogger::log('title_updated', SomcpLogger::OUTCOME_SUCCESS, 'title ' . $idGender . ': '
            . implode(', ', $fields), '', $idEmployee);

        $result = [
            'changed' => true,
            'changes' => $changes,
            'title' => SomcpToolCreateTitle::answer($idGender, $idShop),
        ];
        $warnings = [];
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'A social title is shared by every shop: it is offered on all of them.';
        }
        if ($type !== null) {
            $holders = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer`'
                . ' WHERE `id_gender` = ' . $idGender . ' AND `id_shop` = ' . $idShop);
            if ($holders > 0) {
                $warnings[] = $holders . ' customers of this shop have this title.';
            }
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }
}
