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
 * somcp_update_manufacturer - one existing brand, changed with everything
 * somcp_create_manufacturer can write.
 *
 * It extends the creation tool rather than copying it: a field accepted at
 * creation is accepted here under the same name and checked by the same code,
 * so the two tools cannot come to disagree on what a valid name or meta title
 * is, and a logo is downloaded with the same guards and written where the
 * creation writes it.
 *
 * Only what is given changes. Each value is compared with what the shop holds
 * before anything is written, which gives the answer its old and new values
 * and lets a call that would change nothing write nothing at all: no hook
 * fires, and the answer says changed: false.
 *
 * Every check runs before the write, which is a single Manufacturer::update()
 * in the shop of the token, as the core's EditManufacturerHandler does, so the
 * object hooks fire. The logo comes after, as at creation: one that cannot be
 * fetched is reported and does not undo the change.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolCreateManufacturer.php';

class SomcpToolUpdateManufacturer extends SomcpToolCreateManufacturer
{
    /** @var array<int, string> What this tool takes besides the fields of the creation. */
    private const OWN_ARGUMENTS = ['id_manufacturer', 'remove_logo'];

    /** @var array<int, string> The translated texts of a brand, in the order the answer lists them. */
    private const TEXT_FIELDS = ['short_description', 'description', 'meta_title', 'meta_description', 'meta_keywords'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_manufacturer',
            Somcp::t('Change a brand'),
            'Changes one existing brand (manufacturer) of this shop. Everything somcp_create_manufacturer can '
                . 'write can be changed here, under the same names and with the same rules - who fills each '
                . 'field, lengths, forbidden characters. Only what is given changes: a value left out is kept, a '
                . 'text is replaced in the languages filled and kept in the others, a logo given replaces the '
                . 'current one. The logo is only removed through remove_logo. When languages is left out every '
                . 'language of the shop is filled, so a text must then be given in each; to change one language, '
                . 'pass languages with it alone. The name is one text, the same in every language. '
                . 'Take the brand from somcp_list_manufacturers, never guessed, and read it with '
                . 'somcp_get_manufacturer to know what it holds. Show the merchant what will change and call once '
                . 'they agree. Every problem is reported at once and nothing is written until the whole call is '
                . 'valid. The logo is downloaded by the shop from a public https address after the change; one '
                . 'that cannot be fetched is reported in logo and does not undo it. The answer gives each field '
                . 'changed with its old and new value. A call that would change nothing writes nothing and says '
                . 'changed: false. '
                . 'Do not use it to create a brand (somcp_create_manufacturer) or only to enable or disable one '
                . '(somcp_set_manufacturer_active). One brand per call. '
                . 'Not to be retried blindly: if the answer is lost, read the brand again with '
                . 'somcp_get_manufacturer and compare before calling again.',
            self::inputSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Changing a brand is the edit right of the brands page in Team,
            // Permissions; creating one is a different right.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminManufacturers')
        );
    }

    /**
     * The creation schema, made to name an existing brand and to keep what is left out.
     *
     * Derived rather than written again, so a field added to the creation is
     * offered here too, with the same description.
     *
     * @return array<string, mixed>
     */
    protected static function inputSchema(): array
    {
        $schema = parent::inputSchema();
        $properties = $schema['properties'];
        $kept = ' Left out: unchanged.';

        $properties['name']['description'] = 'Merchant. One text for every language: a brand name is not '
            . 'translated. 64 characters at most, never empty, without < > { } (nor ; = # before PrestaShop 9.1).' . $kept;
        $properties['active']['description'] = 'Merchant. Enabled on the storefront.' . $kept;
        $properties['logo']['description'] = 'Merchant. Public https address of a new logo, which replaces the '
            . 'current one. Downloaded by the shop: no redirect, 10 MB at most, JPEG, PNG, GIF or WebP.';
        $properties['remove_logo'] = [
            'type' => 'boolean',
            'description' => 'Merchant. true removes the logo. Not with logo, which already replaces it.',
        ];

        $schema['properties'] = [
            'id_manufacturer' => ['type' => 'integer', 'description' => 'The brand to change, from somcp_list_manufacturers.'],
        ] + $properties;
        $schema['required'] = ['id_manufacturer'];

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

        // The same test of the shop as somcp_get_manufacturer, so a brand this
        // tool refuses is never one that tool describes.
        $manufacturer = SomcpToolGetManufacturer::existingManufacturer(self::integer($arguments['id_manufacturer'] ?? null) ?? 0, $idShop);
        if ($manufacturer === null) {
            return SomcpTool::text('No brand of this shop has this identifier. Nothing was changed. Find it with somcp_list_manufacturers.', true);
        }

        $errors = [];
        $plan = self::planUpdate($arguments, $manufacturer, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
        }

        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_manufacturer' => (int) $manufacturer->id,
                'reason' => 'The brand already holds every value given. Nothing was written.',
            ]);
        }

        return self::asEmployee($idEmployee, static function () use ($manufacturer, $plan, $idShop, $idEmployee): array {
            return self::applyUpdate($manufacturer, $plan, $idShop, $idEmployee);
        });
    }

    /**
     * Turns the arguments into what will be written, or says everything that is wrong with them.
     *
     * Nothing is written here. A value equal to what the shop holds is left
     * out of the plan, so what remains is exactly what will change.
     *
     * @param array<string, mixed> $arguments
     * @param Manufacturer $manufacturer
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function planUpdate(array $arguments, Manufacturer $manufacturer, int $idShop, array &$errors): ?array
    {
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::MANUFACTURER_ARGUMENTS, true) && !in_array($key, self::OWN_ARGUMENTS, true)) {
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
            'fields' => [],
            'texts' => [],
            'changes' => [],
            'touched' => [],
            'manufacturer' => [],
            'logo' => self::logoUrl($arguments, $errors),
            'remove_logo' => false,
        ];

        $name = self::manufacturerName($arguments, $probe, $errors, false);
        if ($name !== null) {
            $plan['fields']['name'] = $name;
        }

        if (array_key_exists('active', $arguments)) {
            // A boolean only: "false" cast to bool is true, and would show a
            // brand the merchant asked to disable.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } else {
                $plan['fields']['active'] = $arguments['active'];
            }
        }

        self::richTexts($arguments, ['short_description', 'description'], $languages, $probe, $plan, $errors);
        self::metaWithin($arguments, self::MANUFACTURER_META_LIMITS, $languages, $probe, $plan, $errors);
        self::metaKeywords($arguments, $languages, $probe, $plan, $errors);

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

        self::compareManufacturer($manufacturer, $plan, $idShop);

        // The same check the core runs before update(), on the whole object as
        // it will be written, as a last net for a rule none of the above knew.
        if ($plan['manufacturer'] !== []) {
            $candidate = clone $manufacturer;
            self::fill($candidate, $plan['manufacturer']);
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'brand: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * Keeps the fields, texts and logo that differ from the shop, and records each one.
     *
     * @param Manufacturer $manufacturer
     * @param array<string, mixed> $plan
     * @param int $idShop
     *
     * @return void
     */
    private static function compareManufacturer(Manufacturer $manufacturer, array &$plan, int $idShop): void
    {
        $languages = $plan['languages'];
        // Read from the object's properties: meta_keywords exists on
        // PrestaShop 8 only, and a property access is unknown to static
        // analysis on 9.
        $properties = get_object_vars($manufacturer);

        foreach ($plan['fields'] as $field => $value) {
            $old = $properties[$field] ?? null;
            $same = is_bool($value) ? (bool) $old === $value : (string) $old === (string) $value;
            if ($same) {
                continue;
            }
            $plan['manufacturer'][$field] = $value;
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
                $plan['manufacturer'][$field] = $merged;
            }
        }

        if ($plan['logo'] === null && !$plan['remove_logo']) {
            return;
        }
        $before = SomcpToolGetManufacturer::manufacturer((int) $manufacturer->id, (int) $languages['fallback'], $idShop);
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
     * Writes the change, then the logo, and answers with what was stored.
     *
     * @param Manufacturer $manufacturer
     * @param array<string, mixed> $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function applyUpdate(Manufacturer $manufacturer, array $plan, int $idShop, int $idEmployee): array
    {
        $idManufacturer = (int) $manufacturer->id;
        if ($plan['manufacturer'] !== []) {
            self::fill($manufacturer, $plan['manufacturer']);
            // The shop of the token only: left to the context's list of shops,
            // update() can attach the brand to a shop it was never in.
            $manufacturer->id_shop_list = [$idShop];

            try {
                $done = $manufacturer->update();
                $failure = $done ? '' : 'PrestaShop refused to save it';
            } catch (Throwable $e) {
                $done = false;
                $failure = $e->getMessage();
            }
            if (!$done) {
                SomcpLogger::log(
                    'manufacturer_update_failed',
                    SomcpLogger::OUTCOME_FAILURE,
                    'brand ' . $idManufacturer . ': ' . Tools::substr($failure, 0, 500),
                    '',
                    $idEmployee
                );

                return SomcpTool::text(
                    'The brand could not be changed: ' . $failure . '. The write may be partly done and the logo '
                        . 'was not touched. Read the brand again with somcp_get_manufacturer, tell the merchant what '
                        . 'it holds now, and do not call again before.',
                    true
                );
            }
        }

        $logo = null;
        if ($plan['remove_logo']) {
            $logo = self::removeLogoIn($idManufacturer, _PS_MANU_IMG_DIR_, 'manufacturers', 'manufacturer');
        } elseif ($plan['logo'] !== null) {
            // Written over the current files, under the same names, rather
            // than after deleting them: if it cannot be fetched, the brand
            // keeps the logo it had.
            $logo = self::storeLogo($idManufacturer, (string) $plan['logo']);
        }

        SomcpLogger::log(
            'manufacturer_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'brand ' . $idManufacturer . ', ' . count($plan['changes']) . ' change(s)',
            '',
            $idEmployee
        );

        $warnings = self::updateWarnings($idManufacturer, $plan);

        return SomcpTool::json([
            'changed' => true,
            'id_manufacturer' => $idManufacturer,
            'changes' => $plan['changes'],
            'seo_warnings' => $warnings === [] ? new stdClass() : $warnings,
            'logo' => $logo,
            // Read back rather than echoed from the arguments: what the
            // assistant shows the merchant is what the shop stored.
            'manufacturer' => SomcpToolGetManufacturer::manufacturer($idManufacturer, (int) $plan['languages']['fallback'], $idShop),
        ]);
    }

    /**
     * The creation's SEO warnings for the languages whose texts changed.
     *
     * @param int $idManufacturer
     * @param array<string, mixed> $plan
     *
     * @return array<string, array<int, string>>
     */
    private static function updateWarnings(int $idManufacturer, array $plan): array
    {
        $touched = array_intersect_key($plan['languages']['filled'], $plan['touched']);
        if ($touched === []) {
            return [];
        }

        // On the texts as they are stored now rather than on the ones this
        // call gave, since a language may have been changed only in part.
        $properties = get_object_vars(new Manufacturer($idManufacturer));
        $texts = [];
        foreach (self::TEXT_FIELDS as $field) {
            $texts[$field] = is_array($properties[$field] ?? null) ? $properties[$field] : [];
        }

        return self::manufacturerWarnings(['languages' => ['filled' => $touched], 'texts' => $texts]);
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
