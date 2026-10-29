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
 * somcp_update_supplier - one existing supplier, changed with everything
 * somcp_create_supplier can write.
 *
 * It extends the creation tool rather than copying it: a field accepted at
 * creation is accepted here under the same name and checked by the same code,
 * the contact block included, so the two tools cannot come to disagree on what
 * a valid phone number, postal code or state is.
 *
 * Only what is given changes. The contact details are checked as a whole, the
 * values given laid over what the supplier holds, because their rules depend
 * on one another: a state is required or refused by the country, a DNI asked
 * for by it, a postal code checked against its format. Each value is then
 * compared with what the shop holds, which gives the answer its old and new
 * values and lets a call that would change nothing write nothing at all.
 *
 * The write follows the core's EditSupplierHandler: Supplier::update() in the
 * shop of the token, then the update of its address row, so the object hooks
 * fire. A supplier created without contact details has no address row; one is
 * added for it, as the creation adds it. The logo comes after, and one that
 * cannot be fetched is reported and does not undo the change.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolCreateSupplier.php';

class SomcpToolUpdateSupplier extends SomcpToolCreateSupplier
{
    /** @var array<int, string> What this tool takes besides the fields of the creation. */
    private const OWN_ARGUMENTS = ['id_supplier', 'remove_logo'];

    /** @var array<int, string> The translated texts of a supplier, in the order the answer lists them. */
    private const TEXT_FIELDS = ['description', 'meta_title', 'meta_description', 'meta_keywords'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_supplier',
            Somcp::t('Change a supplier'),
            'Changes one existing supplier of this shop. Everything somcp_create_supplier can write can be '
                . 'changed here, under the same names and with the same rules - who fills each field, lengths, '
                . 'forbidden characters, the country deciding whether a state, a DNI or a postal code format is '
                . 'needed. Only what is given changes: a value left out is kept, a text is replaced in the '
                . 'languages filled and kept in the others, a logo given replaces the current one. The logo is '
                . 'only removed through remove_logo. When the country changes, give the state again if the new '
                . 'country has states. When languages is left out every language of the shop is filled, so a text '
                . 'must then be given in each; to change one language, pass languages with it alone. The name and '
                . 'the contact details are one text each, the same in every language. '
                . 'Take the supplier from somcp_list_suppliers, never guessed, and read it with somcp_get_supplier '
                . 'to know what it holds. Show the merchant what will change and call once they agree. Every '
                . 'problem is reported at once and nothing is written until the whole call is valid. The logo is '
                . 'downloaded by the shop from a public https address after the change; one that cannot be '
                . 'fetched is reported in logo and does not undo it. The answer gives each field changed with its '
                . 'old and new value. A call that would change nothing writes nothing and says changed: false. '
                . 'Do not use it to create a supplier (somcp_create_supplier) or only to enable or disable one '
                . '(somcp_set_supplier_active). One supplier per call. '
                . 'Not to be retried blindly: if the answer is lost, read the supplier again with '
                . 'somcp_get_supplier and compare before calling again.',
            self::inputSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Changing a supplier is the edit right of the suppliers page in
            // Team, Permissions; creating one is a different right.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminSuppliers')
        );
    }

    /**
     * The creation schema, made to name an existing supplier and to keep what is left out.
     *
     * Derived rather than written again, so a field added to the creation is
     * offered here too, with the same rules.
     *
     * @return array<string, mixed>
     */
    protected static function inputSchema(): array
    {
        $schema = parent::inputSchema();
        $properties = $schema['properties'];
        $kept = ' Left out: unchanged.';

        foreach ($properties as $field => $property) {
            if ($field === 'languages' || !isset($property['description'])) {
                continue;
            }
            // Required at creation, kept here: the creation's "Required." would
            // tell the assistant to ask for what the supplier already holds.
            $properties[$field]['description'] = str_replace('Merchant. Required. ', 'Merchant. ', (string) $property['description']) . $kept;
        }
        $properties['name']['description'] = 'Merchant. One text for every language: a supplier name is not '
            . 'translated. 64 characters at most, never empty, without < > { } (nor ; = # before PrestaShop 9.1).' . $kept;
        $properties['active']['description'] = 'Merchant. Enabled on the storefront.' . $kept;
        $properties['country']['description'] = 'Merchant. ISO code of the country, for example FR, among the '
            . 'countries of this shop. A new country takes the state given with it, none otherwise.' . $kept;
        $properties['logo']['description'] = 'Merchant. Public https address of a new logo, which replaces the '
            . 'current one. Downloaded by the shop: no redirect, 10 MB at most, JPEG, PNG, GIF or WebP.';
        $properties['remove_logo'] = [
            'type' => 'boolean',
            'description' => 'Merchant. true removes the logo. Not with logo, which already replaces it.',
        ];

        $schema['properties'] = [
            'id_supplier' => ['type' => 'integer', 'description' => 'The supplier to change, from somcp_list_suppliers.'],
        ] + $properties;
        $schema['required'] = ['id_supplier'];

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
        $idShop = (int) Somcp::currentShop()->id;
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        // The same test of the shop as somcp_get_supplier, so a supplier this
        // tool refuses is never one that tool describes.
        $supplier = SomcpToolGetSupplier::existingSupplier(self::integer($arguments['id_supplier'] ?? null) ?? 0, $idShop);
        if ($supplier === null) {
            return SomcpTool::text('No supplier of this shop has this identifier. Nothing was changed. Find it with somcp_list_suppliers.', true);
        }

        $errors = [];
        $plan = self::planUpdate($arguments, $supplier, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
        }

        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_supplier' => (int) $supplier->id,
                'reason' => 'The supplier already holds every value given. Nothing was written.',
            ]);
        }

        return self::asEmployee($idEmployee, static function () use ($supplier, $plan, $idShop, $idEmployee): array {
            return self::applyUpdate($supplier, $plan, $idShop, $idEmployee);
        });
    }

    /**
     * Turns the arguments into what will be written, or says everything that is wrong with them.
     *
     * Nothing is written here. A value equal to what the shop holds is left
     * out of the plan, so what remains is exactly what will change.
     *
     * @param array<string, mixed> $arguments
     * @param Supplier $supplier
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function planUpdate(array $arguments, Supplier $supplier, int $idShop, array &$errors): ?array
    {
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::SUPPLIER_ARGUMENTS, true) && !in_array($key, self::OWN_ARGUMENTS, true)) {
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
            'fields' => [],
            'texts' => [],
            'changes' => [],
            'touched' => [],
            'supplier' => [],
            'address' => [],
            'id_address' => (int) Address::getAddressIdBySupplierId((int) $supplier->id),
            'logo' => self::logoUrl($arguments, $errors),
            'remove_logo' => false,
        ];

        $name = self::manufacturerName($arguments, $probe, $errors, false, 'supplier');
        if ($name !== null) {
            $plan['fields']['name'] = $name;
        }

        if (array_key_exists('active', $arguments)) {
            // A boolean only: "false" cast to bool is true, and would show a
            // supplier the merchant asked to disable.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } else {
                $plan['fields']['active'] = $arguments['active'];
            }
        }

        self::richTexts($arguments, ['description'], $languages, $probe, $plan, $errors);
        self::metaWithin($arguments, self::SUPPLIER_META_LIMITS, $languages, $probe, $plan, $errors);
        self::metaKeywords($arguments, $languages, $probe, $plan, $errors);

        $address = self::mergedAddress($arguments, $supplier, $idShop, $errors);

        if (array_key_exists('remove_logo', $arguments)) {
            if (!is_bool($arguments['remove_logo'])) {
                $errors[] = 'remove_logo: true or false';
            } elseif ($arguments['remove_logo'] && array_key_exists('logo', $arguments)) {
                $errors[] = 'remove_logo: not with logo, which already replaces the current one';
            } else {
                $plan['remove_logo'] = $arguments['remove_logo'];
            }
        }

        if ($errors !== []) {
            return $plan;
        }

        self::compareSupplier($supplier, $plan, $idShop);
        if ($address !== null) {
            self::compareAddress($address, $plan);
        }

        // The same checks the core's EditSupplierHandler runs before update(),
        // on both whole objects as they will be written, as a last net for a
        // rule none of the above knew.
        if ($plan['supplier'] !== []) {
            $candidate = clone $supplier;
            self::fill($candidate, $plan['supplier']);
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'supplier: ' . strip_tags((string) $check);
                }
            }
        }
        if ($plan['address'] !== []) {
            $candidate = $plan['id_address'] > 0 ? new Address($plan['id_address']) : new Address();
            self::fill($candidate, $plan['address']);
            if (($check = $candidate->validateFields(false, true)) !== true) {
                $errors[] = 'contact details: ' . strip_tags((string) $check);
            }
        }

        return $plan;
    }

    /**
     * The contact details as they will be, or null when none was given.
     *
     * The values given are laid over what the supplier holds and the whole is
     * checked by the creation's own code: a phone number alone is checked
     * against the stored country as much as a new country is checked against
     * the stored postal code.
     *
     * @param array<string, mixed> $arguments
     * @param Supplier $supplier
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null the properties of the address row
     */
    private static function mergedAddress(array $arguments, Supplier $supplier, int $idShop, array &$errors): ?array
    {
        $contactFields = array_merge(self::SUPPLIER_CONTACT_TEXTS, ['country', 'state']);
        if (array_intersect($contactFields, array_keys($arguments)) === []) {
            return null;
        }

        $current = SomcpToolGetSupplier::supplier((int) $supplier->id, (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop), $idShop);
        $merged = [];
        foreach (self::SUPPLIER_CONTACT_TEXTS as $field) {
            $merged[$field] = array_key_exists($field, $arguments) ? $arguments[$field] : (string) $current[$field];
        }
        if (array_key_exists('country', $arguments)) {
            $merged['country'] = $arguments['country'];
        } elseif ($current['country'] !== null) {
            $merged['country'] = $current['country'];
        }
        if (array_key_exists('state', $arguments)) {
            $merged['state'] = $arguments['state'];
        } elseif ($current['state'] !== null && !self::countryChanges($arguments, $current)) {
            // A stored state belongs to the stored country: carried over to a
            // new one, it would be refused as a state that country lacks.
            $merged['state'] = $current['state'];
        }

        return self::supplierAddress($merged, $idShop, $errors);
    }

    /**
     * Whether the call names a country other than the stored one.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $current the supplier as somcp_get_supplier returns it
     *
     * @return bool
     */
    private static function countryChanges(array $arguments, array $current): bool
    {
        if (!array_key_exists('country', $arguments)) {
            return false;
        }
        $iso = self::string($arguments['country']);

        return Tools::strtoupper(trim((string) $iso)) !== Tools::strtoupper((string) $current['country']);
    }

    /**
     * Keeps the fields, texts and logo of the supplier that differ from the shop, and records each one.
     *
     * @param Supplier $supplier
     * @param array<string, mixed> $plan
     * @param int $idShop
     *
     * @return void
     */
    private static function compareSupplier(Supplier $supplier, array &$plan, int $idShop): void
    {
        $languages = $plan['languages'];
        // Read from the object's properties: meta_keywords exists on
        // PrestaShop 8 only, and a property access is unknown to static
        // analysis on 9.
        $properties = get_object_vars($supplier);

        foreach ($plan['fields'] as $field => $value) {
            $old = $properties[$field] ?? null;
            $same = is_bool($value) ? (bool) $old === $value : (string) $old === (string) $value;
            if ($same) {
                continue;
            }
            $plan['supplier'][$field] = $value;
            self::record($plan, $field, is_bool($value) ? (bool) $old : ($old === null ? null : (string) $old), $value);
        }

        foreach ($plan['texts'] as $field => $texts) {
            $current = is_array($properties[$field] ?? null) ? $properties[$field] : [];
            $merged = $current;
            foreach ($texts as $idLang => $text) {
                $old = (string) ($current[$idLang] ?? '');
                if ($old === $text) {
                    continue;
                }
                $merged[$idLang] = $text;
                self::record($plan, $field . ' (' . $languages['filled'][$idLang] . ')', $old, $text);
                $plan['touched'][$idLang] = true;
            }
            if ($merged !== $current) {
                $plan['supplier'][$field] = $merged;
            }
        }

        if ($plan['logo'] === null && !$plan['remove_logo']) {
            return;
        }
        $before = SomcpToolGetSupplier::supplier((int) $supplier->id, (int) $languages['fallback'], $idShop);
        // An address cannot be compared with a stored file, so a logo given
        // always counts as a change; a removal counts only when there is one.
        if ($plan['logo'] !== null) {
            self::record($plan, 'logo', $before['logo'], $plan['logo']);
        } elseif ($before['logo'] === null) {
            $plan['remove_logo'] = false;
        } else {
            self::record($plan, 'logo', $before['logo'], null);
        }
    }

    /**
     * Keeps the contact details that differ from the supplier's address row, and records each one.
     *
     * @param array<string, mixed> $address the properties built by supplierAddress()
     * @param array<string, mixed> $plan
     *
     * @return void
     */
    private static function compareAddress(array $address, array &$plan): void
    {
        $stored = $plan['id_address'] > 0 ? new Address($plan['id_address']) : null;
        $properties = $stored !== null && Validate::isLoadedObject($stored) ? get_object_vars($stored) : [];

        foreach (self::SUPPLIER_CONTACT_TEXTS as $field) {
            $old = (string) ($properties[$field] ?? '');
            if ($old !== (string) $address[$field]) {
                $plan['address'][$field] = $address[$field];
                self::record($plan, $field, $old, $address[$field]);
            }
        }

        // Shown as ISO codes, the way the supplier tools take and return them.
        $oldCountry = (int) ($properties['id_country'] ?? 0);
        if ($oldCountry !== (int) $address['id_country']) {
            $plan['address']['id_country'] = (int) $address['id_country'];
            self::record($plan, 'country', $oldCountry > 0 ? (string) Country::getIsoById($oldCountry) : null, (string) Country::getIsoById((int) $address['id_country']));
        }
        $oldState = (int) ($properties['id_state'] ?? 0);
        if ($oldState !== (int) $address['id_state']) {
            $plan['address']['id_state'] = (int) $address['id_state'];
            self::record($plan, 'state', self::stateIso($oldState), self::stateIso((int) $address['id_state']));
        }

        if ($plan['address'] !== [] && $properties === []) {
            // No address row yet: it is added whole, with the placeholders the
            // creation writes, rather than only with what differs from nothing.
            $plan['address'] = $address;
            $plan['id_address'] = 0;
        }
    }

    /**
     * @param int $idState
     *
     * @return string|null
     */
    private static function stateIso(int $idState): ?string
    {
        if ($idState <= 0) {
            return null;
        }
        $state = new State($idState);

        return Validate::isLoadedObject($state) ? (string) $state->iso_code : null;
    }

    /**
     * Writes the change, then the logo, and answers with what was stored.
     *
     * @param Supplier $supplier
     * @param array<string, mixed> $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function applyUpdate(Supplier $supplier, array $plan, int $idShop, int $idEmployee): array
    {
        $idSupplier = (int) $supplier->id;
        $failure = '';
        try {
            if ($plan['supplier'] !== []) {
                self::fill($supplier, $plan['supplier']);
                // The shop of the token only: left to the context's list of
                // shops, update() can attach the supplier to a shop it was
                // never in.
                $supplier->id_shop_list = [$idShop];
                if (!$supplier->update()) {
                    $failure = 'PrestaShop refused to save it';
                }
            }
            if ($failure === '' && $plan['address'] !== []) {
                if ($plan['id_address'] > 0) {
                    $address = new Address($plan['id_address']);
                    self::fill($address, $plan['address']);
                    $done = $address->update();
                } else {
                    // Linked from the start, as the creation does, so a failure
                    // cannot leave an address that belongs to nobody.
                    $address = new Address();
                    self::fill($address, $plan['address'] + ['id_supplier' => $idSupplier]);
                    $done = $address->add();
                }
                if (!$done) {
                    $failure = 'PrestaShop refused to save its contact details';
                }
            }
        } catch (Throwable $e) {
            $failure = $e->getMessage();
        }

        if ($failure !== '') {
            SomcpLogger::log(
                'supplier_update_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'supplier ' . $idSupplier . ': ' . Tools::substr($failure, 0, 500),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The supplier could not be changed: ' . $failure . '. The write may be partly done and the logo '
                    . 'was not touched. Read the supplier again with somcp_get_supplier, tell the merchant what it '
                    . 'holds now, and do not call again before.',
                true
            );
        }

        $logo = null;
        if ($plan['remove_logo']) {
            $logo = self::removeLogoIn($idSupplier, _PS_SUPP_IMG_DIR_, 'suppliers', 'supplier');
        } elseif ($plan['logo'] !== null) {
            // Written over the current files, under the same names, rather
            // than after deleting them: if it cannot be fetched, the supplier
            // keeps the logo it had.
            $logo = self::storeLogoIn($idSupplier, (string) $plan['logo'], _PS_SUPP_IMG_DIR_, 'suppliers', 'supplier_mini_');
        }

        SomcpLogger::log(
            'supplier_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'supplier ' . $idSupplier . ', ' . count($plan['changes']) . ' change(s)',
            '',
            $idEmployee
        );

        $warnings = self::updateWarnings($idSupplier, $plan);

        return SomcpTool::json([
            'changed' => true,
            'id_supplier' => $idSupplier,
            'changes' => $plan['changes'],
            'seo_warnings' => $warnings === [] ? new stdClass() : $warnings,
            'logo' => $logo,
            // Read back rather than echoed from the arguments: what the
            // assistant shows the merchant is what the shop stored.
            'supplier' => SomcpToolGetSupplier::supplier($idSupplier, (int) $plan['languages']['fallback'], $idShop),
        ]);
    }

    /**
     * The creation's SEO warnings for the languages whose texts changed.
     *
     * @param int $idSupplier
     * @param array<string, mixed> $plan
     *
     * @return array<string, array<int, string>>
     */
    private static function updateWarnings(int $idSupplier, array $plan): array
    {
        $touched = array_intersect_key($plan['languages']['filled'], $plan['touched']);
        if ($touched === []) {
            return [];
        }

        // On the texts as they are stored now rather than on the ones this
        // call gave, since a language may have been changed only in part.
        $properties = get_object_vars(new Supplier($idSupplier));
        $texts = [];
        foreach (self::TEXT_FIELDS as $field) {
            $texts[$field] = is_array($properties[$field] ?? null) ? $properties[$field] : [];
        }

        return self::manufacturerWarnings(['languages' => ['filled' => $touched], 'texts' => $texts], 'supplier');
    }

    /**
     * @param array<string, mixed> $plan
     * @param string $field
     * @param mixed $old
     * @param mixed $new
     *
     * @return void
     */
    private static function record(array &$plan, string $field, $old, $new): void
    {
        $plan['changes'][] = ['field' => $field, 'old' => $old, 'new' => $new];
    }
}
