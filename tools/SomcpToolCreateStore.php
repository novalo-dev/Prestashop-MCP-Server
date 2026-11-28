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
 * somcp_create_store - Add new store of Shop Parameters > Contact, tab Stores.
 *
 * The page is the legacy AdminStoresController on 8 and 9: its postProcess()
 * checks the state, the coordinates and the postcode, then the object's
 * definition checks the rest. The tool runs those checks on a Store object
 * and adds it for the shop of the token. The checks are public so the
 * update of a store applies the very same ones.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The languages and texts of #149, and the country and state by ISO code.
require_once __DIR__ . '/SomcpToolCreateSupplier.php';
// The answer is a store as the list gives one.
require_once __DIR__ . '/SomcpToolListStores.php';

class SomcpToolCreateStore extends SomcpToolCreateSupplier
{
    /** @var array<int, string> The fields of a store this tool and the update take. */
    public const STORE_FIELDS = [
        'languages', 'name', 'address1', 'address2', 'note', 'hours', 'postcode', 'city', 'country', 'state',
        'latitude', 'longitude', 'phone', 'fax', 'email', 'active', 'image',
    ];

    /** @var array<string, string> The plain texts, and what the definition refuses in each. */
    private const PLAIN = [
        'city' => 'required, 64 characters at most, without ! < > ; ? = + @ # " ° { } _ $ %',
        'phone' => 'digits, spaces and + - . ( ) / only, 16 characters at most',
        'fax' => 'digits, spaces and + - . ( ) / only, 16 characters at most',
        'email' => 'an e-mail address, 255 characters at most',
    ];

    /** @var array<string, string> The translated texts, and what the definition refuses in each. */
    private const TRANSLATED = [
        'name' => '255 characters at most, without < > = { }',
        'address1' => '255 characters at most, without ! < > ? = + @ { } _ $ %',
        'address2' => '255 characters at most, without ! < > ? = + @ { } _ $ %',
        'note' => 'HTML without script',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_store',
            Somcp::t('Create a store'),
            'Creates a store of Shop Parameters > Contact > Stores, as Add new store: name, address, country and '
                . 'state by ISO code, latitude and longitude, phone, e-mail, note, opening hours and whether it is '
                . 'shown on the storefront page of the stores, and its picture. One store per call, not to be retried: read '
                . 'somcp_list_stores first. For the address of the shop itself use somcp_update_contact_details.',
            self::storeSchema(['name', 'address1', 'city', 'country', 'latitude', 'longitude']),
            [SomcpScope::CUSTOMERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::ADD, 'AdminStores'),
            files: ['image' => SomcpUpload::images()]
        );
    }

    /**
     * The fields of a store, under the names somcp_list_stores answers.
     *
     * Public so the update describes them in the same words.
     *
     * @param array<int, string> $required
     *
     * @return array<string, mixed>
     */
    public static function storeSchema(array $required): array
    {
        $text = static function (string $description): array {
            return [
                'type' => 'object',
                'description' => $description . ' One text per language being filled, keyed by ISO code: {"en": "..."}.',
                'additionalProperties' => ['type' => 'string'],
            ];
        };
        $plain = static function (string $description): array {
            return ['type' => 'string', 'description' => $description];
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
                'name' => $text('Name, 255 characters at most.'),
                'address1' => $text('Address, 255 characters at most.'),
                'address2' => $text('Address (2), may be empty.'),
                'note' => $text('Note, may be empty.'),
                'hours' => [
                    'type' => 'object',
                    'description' => 'Opening hours as somcp_list_stores answers them: monday to sunday, each a list '
                        . 'of texts; a day left out is closed. For every language filled, or one week per ISO code.',
                ],
                'postcode' => $plain('Zip/Postal code, in the format of the country, 12 characters at most.'),
                'city' => $plain('City, 64 characters at most.'),
                'country' => $plain('Country, its ISO code of 2 letters (for example US), as somcp_list_countries gives it.'),
                'state' => $plain('State, its ISO code (for example FL), as somcp_list_states gives it; required when '
                    . 'the country has states, refused when it has none.'),
                'latitude' => ['type' => 'number', 'description' => 'Latitude, required and not 0, kept to 8 decimals.'],
                'longitude' => ['type' => 'number', 'description' => 'Longitude, required and not 0, kept to 8 decimals.'],
                'phone' => $plain('Phone, may be empty.'),
                'fax' => $plain('Fax, may be empty.'),
                'email' => $plain('Email address, may be empty.'),
                'active' => [
                    'type' => 'boolean',
                    'description' => 'Active: whether the storefront page of the stores shows it. true by default, '
                        . 'as the form.',
                ],
                'image' => SomcpUpload::schema('Picture: the storefront picture of the store.', 'the picture'),
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

        // An item of the list given back is not a creation: its id_store is
        // named as unknown rather than ignored.
        $errors = SomcpTool::unknownFields($arguments, self::STORE_FIELDS);
        $plan = self::planStore($arguments, $idShop, $errors, true);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::refused('created', $errors);
        }

        $store = new Store();
        $languages = $plan['languages'];
        $values = array_diff_key($plan, ['languages' => true, 'texts' => true, 'hours' => true, 'image' => true]);
        foreach (['address2', 'note'] as $field) {
            // Left out, it is empty in every language, as the form leaves it.
            $plan['texts'][$field] = $plan['texts'][$field] ?? [];
        }
        $hours = $plan['hours'] ?? [];
        $encoded = [];
        foreach ($languages['all'] as $idLang) {
            $week = $hours[$idLang] ?? ($hours[$languages['fallback']] ?? self::week([]));
            $encoded[$idLang] = (string) json_encode($week);
        }
        self::fill($store, $values + self::completeTexts($plan['texts'], $languages) + ['hours' => $encoded]);
        // The shop of the token only, never the context's list, as
        // somcp_create_contact does.
        $store->id_shop_list = [$idShop];

        try {
            $done = (bool) $store->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('store_create_failed', SomcpLogger::OUTCOME_FAILURE, Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text('The store could not be created: ' . $failure . '.', true);
        }
        $idStore = (int) $store->id;

        // The picture needs the id_store for its name, so it comes after the
        // row; one the shop refuses takes the store away again, so a failed
        // call leaves nothing to clean up.
        if (isset($plan['image'])) {
            $image = self::storePicture($idStore, $plan['image']);
            if (!empty($image['refused'])) {
                $store->delete();
                SomcpLogger::log('store_create_failed', SomcpLogger::OUTCOME_FAILURE, 'the picture: ' . $image['refused'], '', $idEmployee);

                return SomcpTool::text('The store could not be created: the picture ' . $image['refused'] . '.', true);
            }
        }
        // The identifier only: the rest is an address.
        SomcpLogger::log('store_created', SomcpLogger::OUTCOME_SUCCESS, 'store ' . $idStore
            . (isset($plan['image']) ? ' with its picture' : ''), '', $idEmployee);

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $result = [
            'created' => true,
            // Read back rather than echoed: what the assistant shows is what
            // the shop stored.
            'store' => SomcpToolListStores::read($idStore, $idLang, $idShop),
            'language' => (string) Language::getIsoById($idLang),
        ];
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $result['warning'] = 'The store was created for this shop only.';
        }

        return SomcpTool::json($result);
    }

    /**
     * The fields given, checked as the Save of the store form checks them.
     *
     * Public so the update of a store checks with the same rules. Every problem
     * is added to $errors, so a call is corrected in one go. A field left out
     * is absent from the plan; the texts sit under texts, keyed by language
     * identifier, the hours under hours, one week per language filled.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $errors
     * @param bool $required false when changing a store, which has its fields already
     *
     * @return array<string, mixed>|null
     */
    public static function planStore(array $arguments, int $idShop, array &$errors, bool $required): ?array
    {
        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }
        $plan = ['languages' => $languages, 'texts' => []];
        // The definition of the running version holds the rules and sizes,
        // which differ for the note between 8 and 9.
        $probe = new Store();

        foreach (self::TRANSLATED as $field => $reason) {
            $needed = $required && ($field === 'name' || $field === 'address1');
            $texts = self::texts($arguments, $field, $field, $languages, $errors, $needed);
            if ($texts === null) {
                continue;
            }
            foreach ($texts as $idLang => $value) {
                $value = trim($value);
                $texts[$idLang] = $value;
                $where = $field . ' (' . $languages['filled'][$idLang] . ')';
                if ($value === '' && ($field === 'name' || $field === 'address1')) {
                    $errors[] = $where . ': required, it cannot be empty';
                } elseif ($value !== '' && ($check = $probe->validateField($field, $value, $idLang)) !== true) {
                    $errors[] = $where . ': ' . $reason . ' - ' . self::reason($check);
                }
            }
            $plan['texts'][$field] = $texts;
        }

        foreach (self::PLAIN as $field => $reason) {
            if (!array_key_exists($field, $arguments)) {
                if ($required && $field === 'city') {
                    $errors[] = 'city: required, the city of the store';
                }
                continue;
            }
            $value = self::string($arguments[$field]);
            if ($value === null) {
                $errors[] = $field . ': a text';
                continue;
            }
            $value = trim($value);
            if ($field === 'city' && $value === '') {
                $errors[] = 'city: required, it cannot be empty';
                continue;
            }
            if ($value !== '' && ($check = $probe->validateField($field, $value)) !== true) {
                $errors[] = $field . ': ' . $reason . ' - ' . self::reason($check);
                continue;
            }
            $plan[$field] = $value;
        }

        // The country and the state by ISO code, through the lookups and the
        // refusal lines of every address tool.
        $country = null;
        if ($required || array_key_exists('country', $arguments)) {
            $country = self::supplierCountry($arguments, $idShop, $errors);
            if ($country !== null) {
                $plan['id_country'] = (int) $country->id;
                $plan['id_state'] = self::supplierState($arguments, $country, $errors);
            }
        }

        if ($required || array_key_exists('postcode', $arguments)) {
            $postcode = self::string($arguments['postcode'] ?? '');
            if ($postcode === null) {
                $errors[] = 'postcode: a text';
            } else {
                $postcode = trim($postcode);
                $problem = $country !== null ? self::postcodeProblem($country, $postcode) : null;
                if ($problem !== null) {
                    $errors[] = 'postcode: ' . $problem;
                } elseif (Tools::strlen($postcode) > 12) {
                    $errors[] = 'postcode: 12 characters at most';
                } else {
                    $plan['postcode'] = $postcode;
                }
            }
        }

        foreach (['latitude', 'longitude'] as $field) {
            if (!array_key_exists($field, $arguments)) {
                if ($required) {
                    $errors[] = $field . ': required, not 0';
                }
                continue;
            }
            $value = $arguments[$field];
            if (!is_int($value) && !is_float($value)) {
                $errors[] = $field . ': a number, for example 25.765005';
                continue;
            }
            // The screen saves 8 decimals (number_format(..., 8)) and refuses 0.
            $value = round((float) $value, 8);
            if ($value === 0.0) {
                $errors[] = $field . ': required, not 0';
            } elseif (!Validate::isCoordinate(number_format($value, 8, '.', '')) || abs($value) > ($field === 'latitude' ? 90 : 180)) {
                $errors[] = $field . ': a coordinate, ' . ($field === 'latitude' ? '-90 to 90' : '-180 to 180');
            } else {
                // As the text the screen posts: the definition checks
                // isCoordinate() on it, which refuses a float without decimals.
                $plan[$field] = number_format($value, 8, '.', '');
            }
        }

        if (array_key_exists('active', $arguments)) {
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } else {
                $plan['active'] = $arguments['active'];
            }
        } elseif ($required) {
            // Store::$active is true on a new store, as the switch of the form.
            $plan['active'] = true;
        }

        if (array_key_exists('hours', $arguments)) {
            $hours = self::hours($arguments['hours'], $languages, $errors);
            if ($hours !== null) {
                $plan['hours'] = $hours;
            }
        }

        // The Picture field of the form replaces a picture and has no delete
        // link: null is refused rather than read as a removal the screen
        // cannot do.
        if (array_key_exists('image', $arguments)) {
            if ($arguments['image'] === null && !$required) {
                $errors[] = 'image: the back office cannot remove a store\'s picture - give a new one or leave the field out';
            } else {
                $image = SomcpUpload::argument($arguments, 'image', $errors, 'image');
                if ($image !== null) {
                    $plan['image'] = $image;
                }
            }
        }

        return $plan;
    }

    /**
     * The picture written as the store form's Save writes it.
     *
     * AdminController::postImage() keeps img/st/<id_store>.jpg and one
     * thumbnail per image type of the stores, and the form shows a copy
     * cached in img/tmp, dropped so it shows the new one: the path of the
     * brands and suppliers, which differs only by folder, types and cache.
     *
     * @param int $idStore
     * @param array{url: string, upload_ref: string} $source
     *
     * @return array<string, mixed> the url or upload_ref, with stored: true or the reason it was refused
     */
    public static function storePicture(int $idStore, array $source): array
    {
        return self::storeLogoIn($idStore, $source, _PS_STORE_IMG_DIR_, 'stores', 'store_');
    }

    /**
     * What the screen says of a postcode for this country, or null.
     *
     * The three rules of AdminStoresController::postProcess(), in its order.
     *
     * @param Country $country
     * @param string $postcode
     *
     * @return string|null
     */
    public static function postcodeProblem(Country $country, string $postcode): ?string
    {
        if ($country->zip_code_format && !$country->checkZipCode($postcode)) {
            return 'not in the format of ' . $country->iso_code . ' ('
                . str_replace(['N', 'L', 'C'], ['0', 'A', $country->iso_code], (string) $country->zip_code_format) . ')';
        }
        if ($postcode === '' && $country->need_zip_code) {
            return 'required by ' . $country->iso_code;
        }
        if ($postcode !== '' && !Validate::isPostCode($postcode)) {
            return 'letters, digits, spaces and - only';
        }

        return null;
    }

    /**
     * The hours given, one week per language filled, or null with the reasons.
     *
     * One week for every language, or one per language keyed by ISO code:
     * a key that is a day tells the first shape.
     *
     * @param mixed $value
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<int, string> $errors
     *
     * @return array<int, array<int, array<int, string>>>|null
     */
    private static function hours($value, array $languages, array &$errors): ?array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = 'hours: an object with the days monday to sunday, as somcp_list_stores answers it';

            return null;
        }
        $byDay = $value === [] || array_intersect(array_keys($value), SomcpToolListStores::DAYS) !== [];
        $weeks = [];
        if ($byDay) {
            $week = self::readWeek($value, 'hours', $errors);
            foreach (array_keys($languages['filled']) as $idLang) {
                $weeks[$idLang] = $week;
            }
        } else {
            $byIso = array_flip($languages['filled']);
            foreach ($value as $iso => $days) {
                $idLang = $byIso[Tools::strtolower((string) $iso)] ?? null;
                if ($idLang === null) {
                    $errors[] = SomcpTool::oneOf('hours', SomcpToolListStores::DAYS)
                        . ', or a language being filled (' . implode(', ', $languages['filled']) . ')';
                    continue;
                }
                if (!is_array($days)) {
                    $errors[] = 'hours.' . $iso . ': an object with the days monday to sunday';
                    continue;
                }
                $weeks[$idLang] = self::readWeek($days, 'hours.' . $iso, $errors);
            }
        }

        return $weeks;
    }

    /**
     * One week, the seven lists in the order of the form.
     *
     * @param array<mixed> $days
     * @param string $path
     * @param array<int, string> $errors
     *
     * @return array<int, array<int, string>>
     */
    private static function readWeek(array $days, string $path, array &$errors): array
    {
        $read = [];
        foreach ($days as $day => $texts) {
            if (!in_array($day, SomcpToolListStores::DAYS, true)) {
                $errors[] = SomcpTool::oneOf($path, SomcpToolListStores::DAYS);
                continue;
            }
            if (!is_array($texts) || !array_is_list($texts)) {
                $errors[] = $path . '.' . $day . ': a list of texts';
                continue;
            }
            $read[$day] = [];
            foreach ($texts as $text) {
                if (!is_string($text)) {
                    $errors[] = $path . '.' . $day . ': a list of texts';
                    continue 2;
                }
                $read[$day][] = trim($text);
            }
        }

        return self::week($read);
    }

    /**
     * The seven lists store_lang.hours keeps, a day left out closed.
     *
     * @param array<string, array<int, string>> $days
     *
     * @return array<int, array<int, string>>
     */
    private static function week(array $days): array
    {
        $week = [];
        foreach (SomcpToolListStores::DAYS as $day) {
            $week[] = $days[$day] ?? [];
        }

        return $week;
    }
}
