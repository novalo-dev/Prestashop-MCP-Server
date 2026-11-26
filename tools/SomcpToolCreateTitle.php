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
 * somcp_create_title - Add new title of Shop Parameters > Customer Settings,
 * tab Titles.
 *
 * The MCP endpoint has no command bus, so the tool does on the Gender object
 * what AdminGendersController (8.0) and AddTitleHandler (9.0) do: the name of
 * every language being filled and the gender, then add(), then the image
 * written where both versions keep it. The checks are public so
 * the update of a title can apply the very same ones.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The languages and texts of #149, shared with every create tool.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
// The answer is a title as the list gives one.
require_once __DIR__ . '/SomcpToolListTitles.php';
// The picture is fetched through the path every image of the module takes.
require_once __DIR__ . '/SomcpToolUpdateLanguage.php';

class SomcpToolCreateTitle extends SomcpToolCreateProduct
{
    /** @var array<int, string> The fields of a title this tool and the update take. */
    public const TITLE_FIELDS = ['languages', 'name', 'gender', 'image', 'image_width', 'image_height'];

    /** The size of gender_lang.name in Gender::$definition. */
    public const MAX_LENGTH = 20;

    /** Image width and Image height as both forms show them before anything is typed. */
    public const DEFAULT_IMAGE_SIZE = 16;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_title',
            Somcp::t('Create a social title'),
            'Creates a social title of Shop Parameters > Customer Settings, tab Titles, as Add new title: its name '
                . 'per language, the gender it stands for (male, female or other) and an optional image. The '
                . 'id_gender it answers is the one somcp_create_customer and somcp_update_customer take. Read '
                . 'somcp_list_titles first: two titles may share a name. One title per call, not to be retried. For '
                . 'the title of one customer use somcp_update_customer.',
            self::titleSchema(['name', 'gender']),
            // A title is part of a customer's account, so it is written with
            // the customers' scope, as the list reads it.
            [SomcpScope::CUSTOMERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::ADD, 'AdminGenders'),
            files: ['image' => SomcpUpload::images()]
        );
    }

    /**
     * The fields of a title, under the names somcp_list_titles answers.
     *
     * Public so the update describes them in the same words.
     *
     * @param array<int, string> $required
     *
     * @return array<string, mixed>
     */
    public static function titleSchema(array $required): array
    {
        $size = static function (string $what): array {
            return [
                'type' => 'integer',
                'description' => 'Image ' . $what . ' in pixels, 0 or more, only with image. '
                    . self::DEFAULT_IMAGE_SIZE . ' when left out, as the form shows. The image is resized only when '
                    . 'width and height are both above 0, otherwise kept at its own size.',
            ];
        };
        $schema = [
            'type' => 'object',
            'properties' => [
                'languages' => [
                    'type' => 'array',
                    'description' => 'ISO codes of the languages to fill, among the active languages of the shop. '
                        . 'All of them by default. Every text given must then be given in each of them.',
                    'items' => ['type' => 'string'],
                ],
                'name' => [
                    'type' => 'object',
                    'description' => 'Social title, for example Dr., ' . self::MAX_LENGTH . ' characters at most, '
                        . 'without < > ; = # { }. One text per language being filled, keyed by ISO code: '
                        . '{"fr": "...", "en": "..."}.',
                    'additionalProperties' => ['type' => 'string'],
                ],
                'gender' => [
                    'type' => 'string',
                    'enum' => SomcpToolListTitles::GENDERS,
                    'description' => 'Gender the title stands for: male, female or other (Neutral on 8.0).',
                ],
                'image' => SomcpUpload::schema('Image shown beside the title.', 'the image'),
                'image_width' => $size('width'),
                'image_height' => $size('height'),
            ],
            'additionalProperties' => false,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
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

        // id_gender is not taken: an item of the list given back is not a
        // creation, and a new title gets an identifier of its own.
        $errors = SomcpTool::unknownFields($arguments, self::TITLE_FIELDS);
        $plan = self::planTitle($arguments, $idShop, $errors, true);
        if ($errors !== [] || $plan === null || !isset($plan['name'], $plan['type'])) {
            return SomcpTool::refused('created', $errors);
        }

        $file = '';
        try {
            if (isset($plan['image'])) {
                // Fetched before anything is written: a picture the shop
                // refuses must not leave a title behind.
                $refusal = SomcpToolUpdateLanguage::fetch($plan['image'], $file);
                if ($refusal !== '') {
                    return SomcpTool::refused('created', ['image: ' . $refusal]);
                }
            }

            return self::writeTitle($plan, $file, $idShop, $idEmployee);
        } finally {
            if ($file !== '') {
                @unlink($file);
            }
        }
    }

    /**
     * Adds the title, then writes its image, and answers.
     *
     * @param array<string, mixed> $plan from planTitle()
     * @param string $file the image in hand, '' without one
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function writeTitle(array $plan, string $file, int $idShop, int $idEmployee): array
    {
        $title = new Gender();
        // A language not being filled takes the default one's name, as the
        // form fills it.
        self::fill($title, ['name' => self::complete($plan['name'], $plan['languages']), 'type' => $plan['type']]);

        $added = false;
        $done = false;
        $failure = '';
        try {
            $added = (bool) $title->add();
            if (!$added) {
                $failure = 'PrestaShop refused to save it';
            } elseif ($file !== '') {
                // After add(): the image is named after the new identifier.
                $refusal = self::storeImage((int) $title->id, $file, $plan['image_width'], $plan['image_height']);
                $failure = $refusal !== '' ? 'the image ' . $refusal : '';
            }
            $done = $added && $failure === '';
        } catch (Throwable $e) {
            $failure = $e->getMessage();
        } finally {
            if ($added && !$done) {
                // A failed call leaves nothing: the title goes with the image
                // that could not be stored.
                try {
                    $title->deleteImage();
                    $title->delete();
                } catch (Throwable $e) {
                    // The failure below is what the assistant reports.
                }
            }
        }
        if (!$done) {
            SomcpLogger::log('title_create_failed', SomcpLogger::OUTCOME_FAILURE, Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text('The social title could not be created: ' . $failure . '.', true);
        }

        $idGender = (int) $title->id;
        SomcpLogger::log('title_created', SomcpLogger::OUTCOME_SUCCESS, 'title ' . $idGender, '', $idEmployee);

        $result = ['created' => true] + self::answer($idGender, $idShop);
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $result['warning'] = 'A social title is shared by every shop: it is offered on all of them.';
        }

        return SomcpTool::json($result);
    }

    /**
     * The fields given, checked with the rules of the back office form.
     *
     * Public so the update of a title checks with the same rules. Every problem
     * is added to $errors, so a call is corrected in one go. The names are
     * keyed by language identifier, for the languages being filled only; a
     * field left out is absent from the plan, except image_width and
     * image_height, which hold the size to resize to (null to keep the
     * picture's own) whenever an image is given.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $errors
     * @param bool $required false when changing a title, which has its name and gender already
     *
     * @return array<string, mixed>|null
     */
    public static function planTitle(array $arguments, int $idShop, array &$errors, bool $required): ?array
    {
        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }
        $plan = ['languages' => $languages];

        // Given, a name is never empty: the core requires it.
        $names = self::texts($arguments, 'name', 'name', $languages, $errors, $required || array_key_exists('name', $arguments));
        if ($names !== null) {
            foreach ($names as $idLang => $name) {
                $name = trim($name);
                $names[$idLang] = $name;
                $where = 'name (' . $languages['filled'][$idLang] . ')';
                // An empty name is named by texts() already.
                if ($name === '') {
                    continue;
                }
                if (preg_match('/[<>;=#{}]/u', $name)) {
                    // isCatalogName, the rule of 9.0, on both versions: a
                    // title made on 8.0 stays valid after an upgrade.
                    $errors[] = $where . ': no <, >, ;, =, #, { or }';
                } elseif (Tools::strlen($name) > self::MAX_LENGTH) {
                    $errors[] = $where . ': ' . self::MAX_LENGTH . ' characters at most';
                }
            }
            $plan['name'] = $names;
        }

        $genders = SomcpToolListTitles::GENDERS;
        if (array_key_exists('gender', $arguments) && $arguments['gender'] !== null) {
            $type = is_string($arguments['gender']) ? array_search($arguments['gender'], SomcpToolListTitles::GENDERS, true) : false;
            if ($type === false) {
                $errors[] = SomcpTool::oneOf('gender', $genders);
            } else {
                $plan['type'] = (int) $type;
            }
        } elseif ($required) {
            $errors[] = 'gender: required, ' . implode(', ', $genders);
        }

        $image = array_key_exists('image', $arguments) && $arguments['image'] !== null
            ? SomcpUpload::argument($arguments, 'image', $errors, 'image')
            : null;
        if ($image !== null) {
            $plan['image'] = $image;
        }
        $sizes = [];
        foreach (['image_width', 'image_height'] as $field) {
            // Set before the checks so a refused size still leaves one to
            // read below: the plan is built whole and the errors decide.
            $sizes[$field] = self::DEFAULT_IMAGE_SIZE;
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            $value = $arguments[$field];
            if (is_string($value) && ctype_digit($value)) {
                $value = (int) $value;
            }
            if (!array_key_exists('image', $arguments) || $arguments['image'] === null) {
                $errors[] = $field . ': only with image';
            } elseif (!is_int($value) || $value < 0) {
                $errors[] = $field . ': a whole number of pixels, 0 or more';
            } else {
                $sizes[$field] = $value;
            }
        }
        // Resized only when both are above 0, as 8.0 decides and as the help
        // of both screens says; otherwise the picture keeps its own size.
        $resized = $sizes['image_width'] > 0 && $sizes['image_height'] > 0;
        $plan['image_width'] = $resized ? $sizes['image_width'] : null;
        $plan['image_height'] = $resized ? $sizes['image_height'] : null;

        return $plan;
    }

    /**
     * Writes an image in hand where both versions of the core keep it.
     *
     * One JPEG at img/genders/<id_gender>.jpg, shared by every shop, as
     * SomcpToolUpdateLanguage::storeFlag() writes a flag. Forced to JPEG: the
     * storefront and SomcpDownload::stored() ask for .jpg whatever the file
     * sent. Both cached thumbnails are dropped, the one of 8.0 per shop and
     * the one of 9.0, so the lists show the new picture.
     *
     * @param int $idGender
     * @param string $file from SomcpToolUpdateLanguage::fetch()
     * @param int|null $width null to keep the picture's own size
     * @param int|null $height
     *
     * @return string the reason it was refused, '' once stored
     */
    public static function storeImage(int $idGender, string $file, ?int $width, ?int $height): string
    {
        try {
            (new Gender($idGender))->deleteImage();
            if (!ImageManager::resize($file, _PS_GENDERS_DIR_ . $idGender . '.jpg', $width, $height, 'jpg', true)) {
                return 'could not be read as a picture by the shop';
            }
            $cached = [_PS_TMP_IMG_DIR_ . 'genders_mini_' . $idGender . '.jpg'];
            foreach (Shop::getShops(false, null, true) as $idShop) {
                $cached[] = _PS_TMP_IMG_DIR_ . 'gender_mini_' . $idGender . '_' . (int) $idShop . '.jpg';
            }
            foreach ($cached as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            return '';
        } catch (Throwable $e) {
            return 'could not be saved by the shop';
        }
    }

    /**
     * A stored title as the list gives it.
     *
     * Public so the update answers in the same shape.
     *
     * @param int $idGender
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    public static function answer(int $idGender, int $idShop): array
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $titles = SomcpToolCreateCustomer::titles();

        return SomcpToolListTitles::item($idGender, $titles[$idGender] ?? ['type' => 2, 'names' => []], $idLang, $idShop);
    }
}
