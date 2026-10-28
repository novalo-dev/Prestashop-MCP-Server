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
 * somcp_create_manufacturer - one brand, with every field of the back office
 * brand form.
 *
 * It extends the category creation tool for what a brand shares with a
 * category and must not come to disagree on: the languages being filled, texts
 * keyed by ISO code, rich text cleaned the way the back office cleans it, meta
 * texts counted on the visible text, meta keywords on the versions that still
 * have them, and the guarded download of an image from a URL the merchant typed.
 *
 * Everything is checked before anything is written, and every problem is
 * reported at once. The brand is then written through Manufacturer::add(), in
 * the shop of the token, and deleted again if that fails after the row went in.
 *
 * The logo comes after, and one that cannot be fetched is reported without
 * undoing the brand. The core's ManufacturerImageUploader cannot be called for
 * it - it moves the file with move_uploaded_file(), which refuses anything that
 * did not arrive in a POST - so the files are written where it writes them,
 * under the same names, with the same thumbnails.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends, and it answers
// with the brand as somcp_get_manufacturer returns it.
require_once __DIR__ . '/SomcpToolCreateCategory.php';
require_once __DIR__ . '/SomcpToolGetManufacturer.php';

class SomcpToolCreateManufacturer extends SomcpToolCreateCategory
{
    /** @var array<int, string> Every argument this tool takes. */
    protected const MANUFACTURER_ARGUMENTS = [
        'languages', 'name', 'short_description', 'description', 'meta_title', 'meta_description', 'meta_keywords',
        'logo', 'active',
    ];

    /**
     * @var array<int, string> What somcp_get_manufacturer returns and this tool refuses, because the shop works it
     *                         out. Named, so the assistant learns why rather than meeting an "unknown field".
     */
    protected const MANUFACTURER_COMPUTED = ['id_manufacturer', 'products_count'];

    /** @var int The size of the name column (Manufacturer::$definition). */
    protected const MANUFACTURER_NAME_MAX = 64;

    /** @var array<string, int> PrestaShop's own sizes for a brand: nothing shorter is asked of the merchant. */
    protected const MANUFACTURER_META_LIMITS = ['meta_title' => 255, 'meta_description' => 512];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_manufacturer',
            Somcp::t('Create a brand'),
            'Creates one brand (manufacturer) in this shop with every field of the back office brand form: name, '
                . 'short description, description, meta title, meta description, meta keywords (PrestaShop 8), '
                . 'logo and Enabled. Only name is required: the brand is enabled unless told otherwise. '
                . 'Read somcp_list_manufacturers first: the brand may already exist, and PrestaShop accepts two '
                . 'brands of the same name. '
                . 'Who fills each field is said at the start of its description, and is a rule for you: '
                . '"Merchant" - ask the merchant and never invent the value; you may suggest one for them to '
                . 'confirm. "Assistant" - write it yourself in every language being filled, and show it to the '
                . 'merchant before the call. '
                . 'Texts other than the name are objects keyed by ISO code and must be given for every language '
                . 'in languages; a language of the shop left out takes the default language\'s text. The name is '
                . 'one text, the same in every language. Lengths count the visible text, spaces included, HTML '
                . 'tags not counted. '
                . 'Every problem is reported at once and nothing is written until the whole call is valid. '
                . 'The logo is downloaded by the shop from a public https address; one that cannot be fetched is '
                . 'reported in logo and does not stop the creation. '
                . 'Do not use it to change a brand, which is somcp_update_manufacturer, or to enable or disable '
                . 'one, which is somcp_set_manufacturer_active. One brand per call: for several, call it once per '
                . 'brand and report each result. '
                . 'Not to be retried: if the answer is lost, read somcp_list_manufacturers and look for the name '
                . 'before anything else, because a second call creates a second brand.',
            self::inputSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Adding a brand is what the brands page asks for in Team,
            // Permissions; editing one is a different right.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminManufacturers')
        );
    }

    /**
     * The arguments, under the names somcp_get_manufacturer returns.
     *
     * @return array<string, mixed>
     */
    protected static function inputSchema(): array
    {
        $text = static function (string $description): array {
            return [
                'type' => 'object',
                'description' => $description . ' One text per language being filled, keyed by ISO code: {"fr": "...", "en": "..."}.',
                'additionalProperties' => ['type' => 'string'],
            ];
        };

        return [
            'type' => 'object',
            'properties' => [
                'languages' => [
                    'type' => 'array',
                    'description' => 'ISO codes of the languages to fill, among the active languages of the shop. '
                        . 'All of them by default. Every text must then be given in each of them.',
                    'items' => ['type' => 'string'],
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Merchant. Required, one text for every language: a brand name is not '
                        . 'translated. 64 characters at most, without < > { } (nor ; = # before PrestaShop 9.1).',
                ],
                'short_description' => $text('Assistant. Shown at the top of the brand page, HTML allowed.'),
                'description' => $text('Assistant. The full presentation of the brand, HTML allowed.'),
                'meta_title' => $text('Assistant. 255 characters at most, without < > { } (nor = before PrestaShop 9.1).'),
                'meta_description' => $text('Assistant. 512 characters at most, without < > { } (nor = before PrestaShop 9.1).'),
                'meta_keywords' => $text('Assistant. PrestaShop 8 only, refused on 9, which dropped them. '
                    . '5 keywords at most, comma separated, without < > = { }.'),
                'logo' => [
                    'type' => 'string',
                    'description' => 'Merchant. Public https address of the logo. Downloaded by the shop: no '
                        . 'redirect, 10 MB at most, JPEG, PNG, GIF or WebP.',
                ],
                'active' => [
                    'type' => 'boolean',
                    'description' => 'Merchant. Enabled on the storefront; true by default.',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
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
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = [];
        $plan = self::planManufacturer($arguments, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was created. Correct these and call again:\n- " . implode("\n- ", $errors),
                true
            );
        }

        return self::asEmployee($idEmployee, static function () use ($plan, $idShop, $idEmployee): array {
            return self::addManufacturer($plan, $idShop, $idEmployee);
        });
    }

    /**
     * Turns the arguments into what will be written, or says everything that is wrong with them.
     *
     * Nothing is written here, so a refused call leaves the shop exactly as it was.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function planManufacturer(array $arguments, int $idShop, array &$errors): ?array
    {
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::MANUFACTURER_ARGUMENTS, true)) {
                $errors[] = $key . (in_array($key, self::MANUFACTURER_COMPUTED, true)
                    ? ': worked out by the shop, so it is not written here'
                    : ': not a field of this tool');
            }
        }

        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }

        $probe = new Manufacturer();
        $plan = [
            'languages' => $languages,
            'fields' => [
                'name' => self::manufacturerName($arguments, $probe, $errors, true),
                'active' => true,
            ],
            'texts' => [],
            'logo' => self::logoUrl($arguments, $errors),
        ];

        if (array_key_exists('active', $arguments)) {
            // A boolean only: "false" cast to bool is true, and would show a
            // brand the merchant asked to keep disabled.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } else {
                $plan['fields']['active'] = $arguments['active'];
            }
        }

        self::richTexts($arguments, ['short_description', 'description'], $languages, $probe, $plan, $errors);
        self::metaWithin($arguments, self::MANUFACTURER_META_LIMITS, $languages, $probe, $plan, $errors);
        self::metaKeywords($arguments, $languages, $probe, $plan, $errors);

        // The same check the core runs before add(), on the whole object, as a
        // last net for a rule of the running version none of the above knew.
        if ($errors === []) {
            $candidate = new Manufacturer();
            self::fill($candidate, $plan['fields'] + self::completeTexts($plan['texts'], $languages));
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'brand: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * The name given in the call, checked with the running version's rules.
     *
     * One string: Manufacturer::$name has no language, so a name per language
     * would be a choice the shop cannot store. Supplier::$name has none either,
     * and follows the same rules, so the supplier tools call this too.
     *
     * @param array<string, mixed> $arguments
     * @param ObjectModel $probe the brand or supplier whose rules the name is checked against
     * @param array<int, string> $errors
     * @param bool $required false when changing a brand, which already has a name
     * @param string $noun what the name is the name of, in the messages
     *
     * @return string|null null when not given
     */
    protected static function manufacturerName(array $arguments, ObjectModel $probe, array &$errors, bool $required, string $noun = 'brand'): ?string
    {
        if (!array_key_exists('name', $arguments)) {
            if ($required) {
                $errors[] = 'name: required, the ' . $noun . ' name';
            }

            return null;
        }
        $name = is_string($arguments['name']) ? trim($arguments['name']) : '';
        if ($name === '') {
            $errors[] = 'name: a text, the same in every language';
        } elseif (Tools::strlen($name) > self::MANUFACTURER_NAME_MAX) {
            $errors[] = 'name: ' . self::MANUFACTURER_NAME_MAX . ' characters at most, ' . Tools::strlen($name) . ' given';
        } elseif (!Validate::isCatalogName($name)) {
            // The rule is the running version's: PrestaShop 9.1 dropped ; = # from it.
            $errors[] = 'name: a character PrestaShop refuses in a name (< > { }, and ; = # before PrestaShop 9.1)';
        } elseif (($check = $probe->validateField('name', $name)) !== true) {
            $errors[] = 'name: ' . self::reason($check);
        }

        return $name;
    }

    /**
     * The logo address, checked for shape only: it is fetched once the brand exists.
     *
     * @param array<string, mixed> $arguments
     * @param array<int, string> $errors
     *
     * @return string|null null when not given
     */
    protected static function logoUrl(array $arguments, array &$errors): ?string
    {
        if (!array_key_exists('logo', $arguments)) {
            return null;
        }
        $url = self::string($arguments['logo']);
        if ($url === null || trim($url) === '') {
            $errors[] = 'logo: a public https address';

            return null;
        }

        return trim($url);
    }

    /**
     * Writes the brand, then its logo, and answers with what was stored.
     *
     * @param array<string, mixed> $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function addManufacturer(array $plan, int $idShop, int $idEmployee): array
    {
        $manufacturer = new Manufacturer();
        self::fill($manufacturer, $plan['fields'] + self::completeTexts($plan['texts'], $plan['languages']));
        // The shop of the token, never the context's list, so on a multistore
        // installation the brand goes to that shop only, as the back office
        // does when one shop is selected.
        $manufacturer->id_shop_list = [$idShop];

        try {
            $done = $manufacturer->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        if (!$done) {
            $kept = false;
            if ((int) $manufacturer->id > 0) {
                try {
                    $kept = !$manufacturer->delete();
                } catch (Throwable $e) {
                    $kept = true;
                }
            }
            SomcpLogger::log(
                'manufacturer_create_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr($failure, 0, 500) . ($kept ? ', brand ' . (int) $manufacturer->id . ' could not be removed' : ''),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The brand could not be created: ' . $failure . '. '
                    . ($kept
                        ? 'It could not be removed either: brand ' . (int) $manufacturer->id . ' exists in an '
                            . 'incomplete state. Tell the merchant to check it in the back office; do not call again.'
                        : 'Nothing was kept.'),
                true
            );
        }

        $idManufacturer = (int) $manufacturer->id;
        $logo = $plan['logo'] === null ? null : self::storeLogo($idManufacturer, (string) $plan['logo']);

        SomcpLogger::log(
            'manufacturer_created',
            SomcpLogger::OUTCOME_SUCCESS,
            'brand ' . $idManufacturer . (!empty($logo['stored']) ? ' with its logo' : ''),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'created' => true,
            'id_manufacturer' => $idManufacturer,
            'seo_warnings' => self::manufacturerWarnings($plan) ?: new stdClass(),
            'logo' => $logo,
            // Read back rather than echoed from the arguments: what the
            // assistant shows the merchant is what the shop stored.
            'manufacturer' => SomcpToolGetManufacturer::manufacturer($idManufacturer, (int) $plan['languages']['fallback'], $idShop),
        ]);
    }

    /**
     * The logo downloaded, checked and written where the core's uploader writes it.
     *
     * @param int $idManufacturer
     * @param string $url
     *
     * @return array<string, mixed> the address, with stored: true or the reason it was refused
     */
    protected static function storeLogo(int $idManufacturer, string $url): array
    {
        return self::storeLogoIn($idManufacturer, $url, _PS_MANU_IMG_DIR_, 'manufacturers', 'manufacturer_mini_');
    }

    /**
     * A logo downloaded, checked and written the way the core's brand and
     * supplier uploaders write theirs.
     *
     * Those two uploaders differ only by folder, image types and cached copy,
     * so one download path serves both: a second copy would be a second place
     * to forget one of its checks.
     *
     * @param int $id the brand or supplier
     * @param string $url
     * @param string $directory where the uploader writes, _PS_MANU_IMG_DIR_ or _PS_SUPP_IMG_DIR_
     * @param string $for the image types to make: manufacturers or suppliers
     * @param string $miniPrefix the name of the copy the back office list keeps in the image cache
     *
     * @return array<string, mixed> the address, with stored: true or the reason it was refused
     */
    protected static function storeLogoIn(int $id, string $url, string $directory, string $for, string $miniPrefix): array
    {
        $file = tempnam(_PS_TMP_IMG_DIR_, 'somcp');
        if ($file === false) {
            return ['url' => $url, 'refused' => 'the shop has no room to receive it'];
        }

        try {
            $refusal = self::download($url, $file);
            if ($refusal === '') {
                $refusal = self::checkImage($file);
            }
            if ($refusal === '') {
                // ManufacturerImageUploader and SupplierImageUploader: the
                // original as <id>.jpg, then one file per image type.
                $target = $directory . $id . '.jpg';
                $stored = ImageManager::resize($file, $target);
                if ($stored) {
                    self::thumbnails($target, $directory . $id, $for);
                    // The back office list keeps a small copy of the logo in
                    // the image cache; the uploader drops it so the list shows
                    // the new one.
                    @unlink(_PS_TMP_IMG_DIR_ . $miniPrefix . $id . '.jpg');
                }
                $refusal = $stored ? '' : 'the shop could not read it as an image';
            }
        } catch (Throwable $e) {
            $refusal = 'the shop could not save it';
        } finally {
            @unlink($file);
        }

        return ['url' => $url] + ($refusal === '' ? ['stored' => true] : ['refused' => $refusal]);
    }

    /**
     * What the merchant should know about the SEO of each language filled.
     *
     * @param array<string, mixed> $plan
     * @param string $noun whose name the storefront falls back on: brand or supplier
     *
     * @return array<string, array<int, string>>
     */
    protected static function manufacturerWarnings(array $plan, string $noun = 'brand'): array
    {
        $warnings = [];
        foreach ($plan['languages']['filled'] as $idLang => $iso) {
            $list = [];
            if ((string) ($plan['texts']['meta_title'][$idLang] ?? '') === '') {
                $list[] = 'no meta_title: the storefront uses the ' . $noun . ' name';
            }
            if ((string) ($plan['texts']['meta_description'][$idLang] ?? '') === '') {
                $list[] = 'no meta_description: search engines pick a passage of the page';
            }
            if ($list !== []) {
                $warnings[$iso] = $list;
            }
        }

        return $warnings;
    }
}
