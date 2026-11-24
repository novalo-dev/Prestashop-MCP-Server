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
 * somcp_import_localization_pack - the Import button of the Import a
 * localization pack card of International > Localization > Localization.
 *
 * A shop built from A to Z gets the states, taxes, tax rules, units and price
 * display of its country in one call, by the core's own importer reading the
 * shop's own file (Download pack data off), rather than state by state and
 * tax by tax. Currencies and languages need PrestaShop's servers: part 2.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// states are answered as the list answers them, the units read as the
// localization read gives them, and the shops counted as the tax tools count.
require_once __DIR__ . '/SomcpToolListStates.php';
require_once __DIR__ . '/SomcpToolGetLocalizationSettings.php';
require_once __DIR__ . '/SomcpToolCreateTax.php';

class SomcpToolImportLocalizationPack
{
    /** @var array<int, string> The contents of the card this tool imports, in the order of the card. */
    public const CONTENTS = ['states', 'taxes', 'units', 'groups'];

    /** @var array<int, string> The contents of the card that wait for part 2: they call PrestaShop's servers. */
    private const LATER = ['currencies', 'languages'];

    /**
     * The unit keys _installUnits() overwrites, under the names
     * somcp_get_localization_settings gives them; the base distance unit is
     * on no screen and keeps a name of its own.
     *
     * @var array<string, string>
     */
    private const UNITS = [
        'weight_unit' => 'PS_WEIGHT_UNIT',
        'volume_unit' => 'PS_VOLUME_UNIT',
        'dimension_unit' => 'PS_DIMENSION_UNIT',
        'distance_unit' => 'PS_DISTANCE_UNIT',
        'base_distance_unit' => 'PS_BASE_DISTANCE_UNIT',
    ];

    /** @var array<int, string> The groups updateDefaultGroupDisplayMethod() sets, by their configuration key. */
    private const GROUPS = ['PS_CUSTOMER_GROUP', 'PS_GUEST_GROUP', 'PS_UNIDENTIFIED_GROUP'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_import_localization_pack',
            Somcp::t('Import a localization pack'),
            'Imports a country\'s localization pack from the shop\'s own file, as Import a localization pack in '
                . 'International > Localization > Localization: states, taxes and tax rules, units, and the price '
                . 'display of the default groups. Creates what is missing and never changes an existing state, tax '
                . 'or tax rule; units are overwritten. Not undone by a second call, which creates nothing. For one '
                . 'state or tax, somcp_create_state or somcp_create_tax.',
            [
                'type' => 'object',
                'properties' => [
                    'country' => [
                        'type' => 'string',
                        'description' => 'ISO code of the country whose pack is imported, two letters, for example fr.',
                    ],
                    'content' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'enum' => self::CONTENTS],
                        'description' => 'What to import: states, taxes, units, groups (price display of the default groups).',
                    ],
                ],
                'required' => ['country', 'content'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminLocalization')
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

        // Every problem in one answer (#169), before anything is imported.
        $problems = SomcpTool::unknownFields($arguments, ['country', 'content']);
        $iso = self::checkCountry($arguments, $problems);
        $content = self::checkContent($arguments, $problems);
        $idCountry = 0;
        if ($iso !== null) {
            $idCountry = (int) Country::getByIso($iso);
            if ($idCountry === 0) {
                $problems[] = SomcpTool::notFound('country', 'country', $iso, 'somcp_list_countries', 'ISO code');
            }
        }
        if ($problems !== [] || $iso === null || $content === []) {
            return SomcpTool::refused('changed', $problems);
        }
        $file = self::packFile($iso);
        $xml = @simplexml_load_file($file);
        if (!$xml instanceof SimpleXMLElement) {
            return SomcpTool::text('Cannot load the localization pack of ' . $iso . '. Nothing was changed.', true);
        }

        // The core imports in the shop of the context.
        Somcp::actAsShop($idShop);
        $before = self::snapshot($idShop, $idCountry);
        $errors = self::import($iso, $content, $xml);
        $after = self::snapshot($idShop, $idCountry);

        $result = self::difference($before, $after, $idShop);
        $kept = self::kept($xml, $content, $result['created']);

        if ($errors !== []) {
            SomcpLogger::log('localization_pack_import_failed', SomcpLogger::OUTCOME_FAILURE, $iso . ' ' . implode(',', $content), '', $idEmployee);
            $done = self::summary($result);

            return SomcpTool::text(
                'The localization pack of ' . $iso . ' could not be imported: ' . implode(' ', $errors)
                    . ($done !== '' ? ' Before that, ' . $done . '.' : ' Nothing was created.')
                    . ' Read the states and taxes with somcp_list_states and somcp_list_taxes before calling again.',
                true
            );
        }

        $changed = $result['created'] !== [] || $result['changes'] !== [] || $result['country_turned_on'] !== [];
        SomcpLogger::log(
            'localization_pack_imported',
            SomcpLogger::OUTCOME_SUCCESS,
            $iso . ' ' . implode(',', $content) . ': ' . self::counts($result['created']),
            '',
            $idEmployee
        );

        $answer = ['changed' => $changed, 'country' => $after['country']];
        foreach ($result['country_turned_on'] as $field) {
            $answer['country'][$field] = true;
        }
        if (!$changed) {
            $answer['reason'] = 'The shop already holds everything this pack brings for ' . implode(', ', $content)
                . '. Nothing was created.';
        }
        $answer['created'] = (object) $result['created'];
        $answer['changes'] = $result['changes'];
        $answer['kept'] = (object) $kept;

        $warnings = [];
        if (($kept['taxes'] ?? 0) > 0 || ($kept['tax_rules'] ?? 0) > 0) {
            $warnings[] = 'Taxes and tax rules that already had their name were kept as they are, even when the pack '
                . 'has another rate: check them with somcp_list_taxes.';
        }
        if (in_array('enabled', $result['country_turned_on'], true)) {
            $warnings[] = $after['country']['name'] . ' was not enabled: the import enabled it, as the back office does.';
        }
        foreach ($result['changes'] as $change) {
            if (isset(self::UNITS[$change['field']])) {
                // The keys are labels: nothing in the core converts a stored value.
                $warnings[] = 'The ' . $change['field'] . ' changed from ' . $change['old'] . ' to ' . $change['new']
                    . ': weights, sizes and carrier ranges already typed are not converted, only their label changes.';
            }
        }
        if (SomcpToolCreateTax::otherShops() > 0) {
            $warnings[] = 'The pack was imported for the whole installation: states, taxes and units are shared by every shop.';
        }
        if ($warnings !== []) {
            $answer['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($answer);
    }

    /**
     * The country of the pack, lowercase, or null with its problem.
     *
     * The name is checked before any path is built from it, so only a file
     * named by two letters of the localization folder can ever be read.
     *
     * @param array<string, mixed> $arguments
     * @param array<int, string> $problems
     *
     * @return string|null
     */
    private static function checkCountry(array $arguments, array &$problems): ?string
    {
        $value = $arguments['country'] ?? null;
        $iso = is_string($value) ? Tools::strtolower(trim($value)) : '';
        if ($iso !== '' && preg_match('/^[a-z]{2}$/', $iso) === 1 && Validate::isLanguageIsoCode($iso)
            && is_file(self::packFile($iso))) {
            return $iso;
        }
        $problems[] = 'country: no localization pack for ' . (is_scalar($value) ? (string) $value : 'this value')
            . ' in this shop - the packs are named by the ISO code of their country, for example fr';

        return null;
    }

    /**
     * The contents asked for, each once, in the order of the card.
     *
     * Never empty when accepted: an empty selection makes the core import
     * everything and change the default language.
     *
     * @param array<string, mixed> $arguments
     * @param array<int, string> $problems
     *
     * @return array<int, string>
     */
    private static function checkContent(array $arguments, array &$problems): array
    {
        $value = $arguments['content'] ?? null;
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value) || $value === []) {
            $problems[] = 'content: at least one of ' . implode(', ', self::CONTENTS);

            return [];
        }
        $asked = [];
        $wrong = false;
        foreach ($value as $item) {
            $item = is_string($item) ? Tools::strtolower(trim($item)) : $item;
            if (in_array($item, self::LATER, true)) {
                $problems[] = 'content: currencies and languages are not available yet';
                $wrong = true;
            } elseif (!is_string($item) || !in_array($item, self::CONTENTS, true)) {
                $problems[] = 'content: ' . (is_scalar($item) ? (string) $item : json_encode($item)) . ' is not one of '
                    . implode(', ', self::CONTENTS);
                $wrong = true;
            } else {
                $asked[$item] = true;
            }
        }
        if ($wrong) {
            return [];
        }

        return array_values(array_filter(self::CONTENTS, static function (string $content) use ($asked): bool {
            return isset($asked[$content]);
        }));
    }

    /**
     * @param string $iso two lowercase letters, already checked
     *
     * @return string
     */
    private static function packFile(string $iso): string
    {
        return _PS_ROOT_DIR_ . '/localization/' . $iso . '.xml';
    }

    /**
     * The core's import, its errors returned.
     *
     * The importer service of the back office when a container offers it;
     * the MCP endpoint is a front controller without the kernel container,
     * so there the same steps the importer takes with Download pack data off:
     * the local file, a new LocalizationPack, loadLocalisationPack().
     *
     * @param string $iso
     * @param array<int, string> $content
     * @param SimpleXMLElement $xml the shop's own file, already loaded
     *
     * @return array<int, string>
     */
    private static function import(string $iso, array $content, SimpleXMLElement $xml): array
    {
        try {
            $importer = SomcpBackOffice::coreService('prestashop.core.localization.pack.import.importer');
            if ($importer instanceof PrestaShop\PrestaShop\Core\Localization\Pack\Import\LocalizationPackImporterInterface) {
                $errors = $importer->import(new PrestaShop\PrestaShop\Core\Localization\Pack\Import\LocalizationPackImportConfig($iso, $content, false));
            } else {
                $pack = new LocalizationPack();
                $pack->loadLocalisationPack($xml, $content, false, $iso);
                $errors = $pack->getErrors();
            }
        } catch (Throwable $e) {
            $errors = [$e->getMessage()];
        }

        return array_values(array_map('strval', is_array($errors) ? $errors : []));
    }

    /**
     * What an import can create or change, read before and after it.
     *
     * @param int $idShop
     * @param int $idCountry
     *
     * @return array<string, mixed>
     */
    private static function snapshot(int $idShop, int $idCountry): array
    {
        $db = Db::getInstance();
        $ids = static function (string $table, string $column) use ($db): array {
            return array_map('intval', array_column($db->executeS('SELECT `' . bqSQL($column) . '` FROM `' . _DB_PREFIX_ . bqSQL($table) . '`') ?: [], $column));
        };
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $settings = SomcpToolGetLocalizationSettings::settings($idShop, 0);
        $units = [];
        foreach (self::UNITS as $field => $key) {
            $units[$field] = isset($settings[$field]) ? (string) $settings[$field] : (string) Configuration::get($key, null, $idShopGroup, $idShop);
        }
        $groups = [];
        foreach (self::GROUPS as $key) {
            $idGroup = (int) Configuration::get($key, null, $idShopGroup, $idShop);
            if ($idGroup > 0 && !isset($groups[$idGroup])) {
                $groups[$idGroup] = (int) $db->getValue('SELECT `price_display_method` FROM `' . _DB_PREFIX_ . 'group` WHERE `id_group` = ' . (int) $idGroup);
            }
        }
        $country = new Country($idCountry);
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, $idShopGroup, $idShop);

        return [
            'states' => $ids('state', 'id_state'),
            'zones' => $ids('zone', 'id_zone'),
            'taxes' => $ids('tax', 'id_tax'),
            'tax_rules' => $ids('tax_rules_group', 'id_tax_rules_group'),
            'units' => $units,
            'price_display_method' => (int) Configuration::get('PRICE_DISPLAY_METHOD', null, $idShopGroup, $idShop),
            'groups' => $groups,
            'country' => [
                'id_country' => $idCountry,
                'iso_code' => (string) $country->iso_code,
                'name' => (string) Country::getNameById($idLang, $idCountry),
            ],
            'active' => (bool) $country->active,
            'contains_states' => (bool) $country->contains_states,
        ];
    }

    /**
     * What appeared or moved between two snapshots, under the names the list tools give.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param int $idShop
     *
     * @return array{created: array<string, array<int, array<string, mixed>>>, changes: array<int, array<string, mixed>>, country_turned_on: array<int, string>}
     */
    private static function difference(array $before, array $after, int $idShop): array
    {
        $db = Db::getInstance();
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $created = [];

        $states = [];
        foreach (array_diff($after['states'], $before['states']) as $idState) {
            $state = SomcpToolListStates::state($idShop, (int) $idState);
            if ($state !== null) {
                $states[] = $state;
            }
        }
        if ($states !== []) {
            $created['states'] = $states;
        }
        $zones = [];
        foreach (array_diff($after['zones'], $before['zones']) as $idZone) {
            $zones[] = ['id_zone' => (int) $idZone, 'name' => (string) $db->getValue('SELECT `name` FROM `' . _DB_PREFIX_ . 'zone` WHERE `id_zone` = ' . (int) $idZone)];
        }
        if ($zones !== []) {
            $created['zones'] = $zones;
        }
        $taxes = [];
        foreach (array_diff($after['taxes'], $before['taxes']) as $idTax) {
            $tax = new Tax((int) $idTax, $idLang);
            $taxes[] = ['id_tax' => (int) $idTax, 'name' => is_array($tax->name) ? (string) reset($tax->name) : (string) $tax->name, 'rate' => (float) $tax->rate];
        }
        if ($taxes !== []) {
            $created['taxes'] = $taxes;
        }
        $rules = [];
        foreach (array_diff($after['tax_rules'], $before['tax_rules']) as $idGroup) {
            $rules[] = ['id_tax_rules_group' => (int) $idGroup, 'name' => (string) $db->getValue('SELECT `name` FROM `' . _DB_PREFIX_ . 'tax_rules_group` WHERE `id_tax_rules_group` = ' . (int) $idGroup)];
        }
        if ($rules !== []) {
            $created['tax_rules'] = $rules;
        }

        $changes = [];
        foreach ($after['units'] as $field => $value) {
            if ($value !== $before['units'][$field]) {
                $changes[] = ['field' => $field, 'old' => $before['units'][$field], 'new' => $value];
            }
        }
        $display = static function (int $method): string {
            return $method === 1 ? 'tax_excluded' : 'tax_included';
        };
        if ($after['price_display_method'] !== $before['price_display_method']) {
            $changes[] = ['field' => 'price_display_method', 'old' => $display($before['price_display_method']), 'new' => $display($after['price_display_method'])];
        }
        foreach ($after['groups'] as $idGroup => $method) {
            if (isset($before['groups'][$idGroup]) && $method !== $before['groups'][$idGroup]) {
                $changes[] = ['field' => 'price_display_method', 'id_group' => (int) $idGroup, 'old' => $display($before['groups'][$idGroup]), 'new' => $display($method)];
            }
        }

        $turned = [];
        // Named as the answer names them: enabled for the country's active.
        foreach (['active' => 'enabled', 'contains_states' => 'contains_states'] as $key => $name) {
            if ($after[$key] && !$before[$key]) {
                $turned[] = $name;
            }
        }

        return ['created' => $created, 'changes' => $changes, 'country_turned_on' => $turned];
    }

    /**
     * How many states, taxes and tax rules of the pack already existed.
     *
     * @param SimpleXMLElement $xml
     * @param array<int, string> $content
     * @param array<string, array<int, array<string, mixed>>> $created
     *
     * @return array<string, int>
     */
    private static function kept(SimpleXMLElement $xml, array $content, array $created): array
    {
        $kept = [];
        if (in_array('states', $content, true) && isset($xml->states->state)) {
            $kept['states'] = max(0, count($xml->states->state) - count($created['states'] ?? []));
        }
        if (in_array('taxes', $content, true)) {
            $kept['taxes'] = max(0, (isset($xml->taxes->tax) ? count($xml->taxes->tax) : 0) - count($created['taxes'] ?? []));
            $kept['tax_rules'] = max(0, (isset($xml->taxes->taxRulesGroup) ? count($xml->taxes->taxRulesGroup) : 0) - count($created['tax_rules'] ?? []));
        }

        return $kept;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $created
     *
     * @return string
     */
    private static function counts(array $created): string
    {
        $parts = [];
        foreach ($created as $kind => $rows) {
            $parts[] = count($rows) . ' ' . $kind;
        }

        return $parts !== [] ? implode(', ', $parts) : 'nothing created';
    }

    /**
     * What a failed import still did, for the error.
     *
     * @param array{created: array<string, array<int, array<string, mixed>>>, changes: array<int, array<string, mixed>>, country_turned_on: array<int, string>} $result
     *
     * @return string
     */
    private static function summary(array $result): string
    {
        $parts = [];
        if ($result['created'] !== []) {
            $parts[] = self::counts($result['created']) . ' had been created';
        }
        foreach ($result['changes'] as $change) {
            $parts[] = $change['field'] . ' had changed from ' . $change['old'] . ' to ' . $change['new'];
        }

        return implode(', ', $parts);
    }
}
