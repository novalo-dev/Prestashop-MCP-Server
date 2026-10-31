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
 * somcp_create_supplier - one supplier, with every field of the back office
 * supplier form.
 *
 * It extends the brand creation tool for what a supplier shares with a brand
 * and must not come to disagree on: a name that is one text in every language,
 * the languages being filled, texts keyed by ISO code, rich text cleaned the
 * way the back office cleans it, meta texts counted on the visible text, meta
 * keywords on the versions that still have them, and the guarded download of a
 * logo from a URL the merchant typed.
 *
 * What a brand does not have is the contact block: PrestaShop keeps it on an
 * address row of the supplier, as the core's AddSupplierHandler writes it, with
 * the placeholder alias and names that handler sets. Those fields are checked
 * with the rules of the running version's Address and of the country chosen
 * (states, postal code format, DNI).
 *
 * Everything is checked before anything is written, and every problem is
 * reported at once. The supplier is written through Supplier::add(), in the
 * shop of the token, then its address through Address::add(); if either fails
 * after a row went in, what was written is deleted again. The logo comes after,
 * and one that cannot be fetched is reported without undoing the supplier.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends, and it answers
// with the supplier as somcp_get_supplier returns it.
require_once __DIR__ . '/SomcpToolCreateManufacturer.php';
require_once __DIR__ . '/SomcpToolGetSupplier.php';

class SomcpToolCreateSupplier extends SomcpToolCreateManufacturer
{
    /** @var array<int, string> Every argument this tool takes. */
    protected const SUPPLIER_ARGUMENTS = [
        'languages', 'name', 'description', 'phone', 'phone_mobile', 'address1', 'address2', 'postcode', 'city',
        'country', 'state', 'dni', 'meta_title', 'meta_description', 'meta_keywords', 'logo', 'active',
    ];

    /**
     * @var array<int, string> What somcp_get_supplier returns and this tool refuses, because the shop works it
     *                         out. Named, so the assistant learns why rather than meeting an "unknown field".
     */
    protected const SUPPLIER_COMPUTED = ['id_supplier', 'products_count'];

    /** @var array<int, string> The contact fields kept on the supplier's address row, as plain texts. */
    protected const SUPPLIER_CONTACT_TEXTS = ['phone', 'phone_mobile', 'address1', 'address2', 'postcode', 'city', 'dni'];

    /** @var array<string, int> PrestaShop's own sizes for a supplier: nothing shorter is asked of the merchant. */
    protected const SUPPLIER_META_LIMITS = ['meta_title' => 255, 'meta_description' => 512];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_supplier',
            Somcp::t('Create a supplier'),
            'Creates one supplier in this shop with every field of the back office supplier form: name, '
                . 'description, phone, mobile phone, address, address line 2, postal code, city, country, state, '
                . 'DNI (national identification number), meta title, meta description, meta keywords (PrestaShop 8), '
                . 'logo and Enabled. Required: name, address1, city and country, plus state when the country has '
                . 'states and dni when the country asks for one. The supplier is enabled unless told otherwise. '
                . 'Read somcp_list_suppliers first: the supplier may already exist, and PrestaShop accepts two '
                . 'suppliers of the same name. '
                . 'Who fills each field is said at the start of its description, and is a rule for you: '
                . '"Merchant" - ask the merchant and never invent the value; you may suggest one for them to '
                . 'confirm. "Assistant" - write it yourself in every language being filled, and show it to the '
                . 'merchant before the call. '
                . 'The description and the meta texts are objects keyed by ISO code and must be given for every '
                . 'language in languages; a language of the shop left out takes the default language\'s text. The '
                . 'name and the contact details are one text each, the same in every language. Lengths count the '
                . 'visible text, spaces included, HTML tags not counted. '
                . 'Every problem is reported at once and nothing is written until the whole call is valid. '
                . 'The logo is downloaded by the shop from a public https address; one that cannot be fetched is '
                . 'reported in logo and does not stop the creation. '
                . 'Do not use it to change a supplier, which is somcp_update_supplier, or to enable or disable '
                . 'one, which is somcp_set_supplier_active. One supplier per call: for several, call it once per '
                . 'supplier and report each result. '
                . 'Not to be retried: if the answer is lost, read somcp_list_suppliers and look for the name '
                . 'before anything else, because a second call creates a second supplier.',
            self::inputSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Adding a supplier is what the suppliers page asks for in Team,
            // Permissions; editing one is a different right.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminSuppliers')
        );
    }

    /**
     * The arguments, under the names somcp_get_supplier returns.
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
        $plain = static function (string $description): array {
            return ['type' => 'string', 'description' => $description];
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
                'name' => $plain('Merchant. Required, one text for every language: a supplier name is not '
                    . 'translated. 64 characters at most, without < > { } (nor ; = # before PrestaShop 9.1).'),
                'description' => $text('Assistant. The presentation of the supplier, HTML allowed.'),
                'phone' => $plain('Merchant. Phone number: digits, spaces and + - . ( ) / only, 32 characters at most.'),
                'phone_mobile' => $plain('Merchant. Mobile phone number, same rules as phone.'),
                'address1' => $plain('Merchant. Required. Street and number, 128 characters at most.'),
                'address2' => $plain('Merchant. Address line 2, 128 characters at most.'),
                'postcode' => $plain('Merchant. Postal code, 12 characters at most, in the format of the country '
                    . 'when it has one.'),
                'city' => $plain('Merchant. Required. 64 characters at most.'),
                'country' => $plain('Merchant. Required. ISO code of the country, for example FR, among the '
                    . 'countries of this shop.'),
                'state' => $plain('Merchant. ISO code of the state, for example CA: required when the country has '
                    . 'states, refused when it has none.'),
                'dni' => $plain('Merchant. National identification number, 16 characters at most: required when '
                    . 'the country asks for one.'),
                'meta_title' => $text('Assistant. 255 characters at most, without < > { } (nor = before PrestaShop 9.1).'),
                'meta_description' => $text('Assistant. 512 characters at most, without < > { } (nor = before PrestaShop 9.1).'),
                'meta_keywords' => $text('Assistant. PrestaShop 8 only, refused on 9, which dropped them. '
                    . '5 keywords at most, comma separated, without < > = { }.'),
                'logo' => $plain('Merchant. Public https address of the logo. Downloaded by the shop: no '
                    . 'redirect, 10 MB at most, JPEG, PNG, GIF or WebP.'),
                'active' => [
                    'type' => 'boolean',
                    'description' => 'Merchant. Enabled on the storefront; true by default.',
                ],
            ],
            'required' => ['name', 'address1', 'city', 'country'],
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
        $plan = self::planSupplier($arguments, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was created. Correct these and call again:\n- " . implode("\n- ", $errors),
                true
            );
        }

        return self::asEmployee($idEmployee, static function () use ($plan, $idShop, $idEmployee): array {
            return self::addSupplier($plan, $idShop, $idEmployee);
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
    private static function planSupplier(array $arguments, int $idShop, array &$errors): ?array
    {
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::SUPPLIER_ARGUMENTS, true)) {
                $errors[] = $key . (in_array($key, self::SUPPLIER_COMPUTED, true)
                    ? ': worked out by the shop, so it is not written here'
                    : ': not a field of this tool');
            }
        }

        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }

        $probe = new Supplier();
        $plan = [
            'languages' => $languages,
            'fields' => [
                'name' => self::manufacturerName($arguments, $probe, $errors, true, 'supplier'),
                'active' => true,
            ],
            'texts' => [],
            'address' => self::supplierAddress($arguments, $idShop, $errors),
            'logo' => self::logoUrl($arguments, $errors),
        ];

        if (array_key_exists('active', $arguments)) {
            // A boolean only: "false" cast to bool is true, and would show a
            // supplier the merchant asked to keep disabled.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } else {
                $plan['fields']['active'] = $arguments['active'];
            }
        }

        self::richTexts($arguments, ['description'], $languages, $probe, $plan, $errors);
        self::metaWithin($arguments, self::SUPPLIER_META_LIMITS, $languages, $probe, $plan, $errors);
        self::metaKeywords($arguments, $languages, $probe, $plan, $errors);

        // The same checks the core's AddSupplierHandler runs before add(), on
        // both whole objects, as a last net for a rule of the running version
        // none of the above knew.
        if ($errors === []) {
            $candidate = new Supplier();
            self::fill($candidate, $plan['fields'] + self::completeTexts($plan['texts'], $languages));
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'supplier: ' . strip_tags((string) $check);
                }
            }
            $address = new Address();
            self::fill($address, $plan['address']);
            if (($check = $address->validateFields(false, true)) !== true) {
                $errors[] = 'contact details: ' . strip_tags((string) $check);
            }
        }

        return $plan;
    }

    /**
     * The contact fields, as the properties of the supplier's address row.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>
     */
    protected static function supplierAddress(array $arguments, int $idShop, array &$errors): array
    {
        // The placeholders the core's AddSupplierHandler writes: an address
        // row needs an alias and a name, which a supplier form does not ask.
        $address = [
            'alias' => 'supplier',
            'firstname' => 'supplier',
            'lastname' => 'supplier',
            'id_country' => 0,
            'id_state' => 0,
        ];
        foreach (self::SUPPLIER_CONTACT_TEXTS as $field) {
            $address[$field] = '';
            if (!array_key_exists($field, $arguments)) {
                continue;
            }
            $value = self::string($arguments[$field]);
            if ($value === null) {
                $errors[] = $field . ': a text, the same in every language';
                continue;
            }
            $address[$field] = trim($value);
        }

        $country = self::supplierCountry($arguments, $idShop, $errors);
        if ($country !== null) {
            $address['id_country'] = (int) $country->id;
            $address['id_state'] = self::supplierState($arguments, $country, $errors);
        }

        // Checked against the core's Address, whose definition holds the rules
        // and sizes of the running version; the DNI check also asks the
        // country, so the country is set on the probe first.
        $probe = new Address();
        $probe->id_country = (int) $address['id_country'];
        $reasons = [
            'phone' => 'digits, spaces and + - . ( ) / only, 32 characters at most',
            'phone_mobile' => 'digits, spaces and + - . ( ) / only, 32 characters at most',
            'address1' => 'required, 128 characters at most, without ! < > ? = + @ { } _ $ %',
            'address2' => '128 characters at most, without ! < > ? = + @ { } _ $ %',
            'postcode' => '12 characters at most, letters, digits, spaces and - only',
            'city' => 'required, 64 characters at most, without ! < > ; ? = + @ # " ° { } _ $ %',
            'dni' => $country !== null && $country->need_identification_number
                ? 'required by ' . $country->iso_code . ', 16 characters at most, letters, digits, . and - only'
                : '16 characters at most, letters, digits, . and - only',
        ];
        foreach (self::SUPPLIER_CONTACT_TEXTS as $field) {
            if (($check = $probe->validateField($field, $address[$field])) !== true) {
                $errors[] = $field . ': ' . $reasons[$field] . ' - ' . self::reason($check);
            }
        }

        // PrestaShop's own rule for the country: the format the checkout asks
        // of a customer, which the supplier form does not enforce but which
        // the shop shows and prints with the address.
        if ($country !== null && $address['postcode'] !== '' && !$country->checkZipCode((string) $address['postcode'])) {
            $errors[] = 'postcode: not in the format of ' . $country->iso_code . ' ('
                . str_replace(['N', 'L', 'C'], ['0', 'A', $country->iso_code], (string) $country->zip_code_format) . ')';
        }

        return $address;
    }

    /**
     * The country given, among the countries of this shop.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return Country|null
     */
    protected static function supplierCountry(array $arguments, int $idShop, array &$errors): ?Country
    {
        if (!array_key_exists('country', $arguments)) {
            $errors[] = 'country: required, the ISO code of the country';

            return null;
        }
        $iso = self::string($arguments['country']);
        $iso = $iso === null ? '' : Tools::strtoupper(trim($iso));
        // Validated before the query: Country::getByIso() would stop the
        // request on a malformed code, and the shop's own countries are read
        // through country_shop, which that method does not look at.
        $idCountry = Validate::isLanguageIsoCode($iso) ? (int) Db::getInstance()->getValue(
            'SELECT c.`id_country` FROM `' . _DB_PREFIX_ . 'country` c'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'country_shop` cs ON cs.`id_country` = c.`id_country` AND cs.`id_shop` = ' . (int) $idShop
            . ' WHERE c.`iso_code` = \'' . pSQL($iso) . '\''
        ) : 0;
        $country = $idCountry > 0 ? new Country($idCountry) : null;
        if ($country === null || !Validate::isLoadedObject($country)) {
            $errors[] = 'country: no country of this shop has the ISO code "' . $iso . '"';

            return null;
        }

        return $country;
    }

    /**
     * The state given, which must be one of the country's when it has states.
     *
     * @param array<string, mixed> $arguments
     * @param Country $country
     * @param array<int, string> $errors
     *
     * @return int 0 when the country has no states
     */
    protected static function supplierState(array $arguments, Country $country, array &$errors): int
    {
        $hasStates = (bool) $country->contains_states;
        if (!array_key_exists('state', $arguments) || $arguments['state'] === '' || $arguments['state'] === null) {
            if ($hasStates) {
                $errors[] = 'state: required, ' . $country->iso_code . ' has states - give the ISO code of one';
            }

            return 0;
        }
        if (!$hasStates) {
            // Stored, it would print a state the country does not have.
            $errors[] = 'state: ' . $country->iso_code . ' has no states, leave the field out';

            return 0;
        }
        $iso = self::string($arguments['state']);
        $iso = $iso === null ? '' : Tools::strtoupper(trim($iso));
        $idState = Validate::isStateIsoCode($iso) ? (int) State::getIdByIso($iso, (int) $country->id) : 0;
        if ($idState <= 0) {
            $errors[] = 'state: ' . $country->iso_code . ' has no state with the ISO code "' . $iso . '"';
        }

        return $idState;
    }

    /**
     * Writes the supplier, its address, then its logo, and answers with what was stored.
     *
     * @param array<string, mixed> $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function addSupplier(array $plan, int $idShop, int $idEmployee): array
    {
        $supplier = new Supplier();
        self::fill($supplier, $plan['fields'] + self::completeTexts($plan['texts'], $plan['languages']));
        // The shop of the token, never the context's list, so on a multistore
        // installation the supplier goes to that shop only, as the back office
        // does when one shop is selected.
        $supplier->id_shop_list = [$idShop];

        $address = new Address();
        try {
            $done = $supplier->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
            if ($done) {
                // Linked from the start, rather than added first and linked
                // after as the core's handler does: a failure in between would
                // otherwise leave an address that belongs to nobody.
                self::fill($address, $plan['address'] + ['id_supplier' => (int) $supplier->id]);
                $done = $address->add();
                $failure = $done ? '' : 'PrestaShop refused to save its contact details';
            }
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        if (!$done) {
            $kept = false;
            try {
                if ((int) $address->id > 0) {
                    $kept = !$address->delete();
                }
                if ((int) $supplier->id > 0) {
                    $kept = !$supplier->delete() || $kept;
                }
            } catch (Throwable $e) {
                $kept = true;
            }
            SomcpLogger::log(
                'supplier_create_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr($failure, 0, 500) . ($kept ? ', supplier ' . (int) $supplier->id . ' could not be removed' : ''),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The supplier could not be created: ' . $failure . '. '
                    . ($kept
                        ? 'It could not be removed either: supplier ' . (int) $supplier->id . ' exists in an '
                            . 'incomplete state. Tell the merchant to check it in the back office; do not call again.'
                        : 'Nothing was kept.'),
                true
            );
        }

        $idSupplier = (int) $supplier->id;
        $logo = $plan['logo'] === null
            ? null
            : self::storeLogoIn($idSupplier, (string) $plan['logo'], _PS_SUPP_IMG_DIR_, 'suppliers', 'supplier_mini_');

        SomcpLogger::log(
            'supplier_created',
            SomcpLogger::OUTCOME_SUCCESS,
            'supplier ' . $idSupplier . (!empty($logo['stored']) ? ' with its logo' : ''),
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'created' => true,
            'id_supplier' => $idSupplier,
            'seo_warnings' => self::manufacturerWarnings($plan, 'supplier') ?: new stdClass(),
            'logo' => $logo,
            // Read back rather than echoed from the arguments: what the
            // assistant shows the merchant is what the shop stored.
            'supplier' => SomcpToolGetSupplier::supplier($idSupplier, (int) $plan['languages']['fallback'], $idShop),
        ]);
    }
}
