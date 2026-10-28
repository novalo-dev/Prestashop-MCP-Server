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
 * somcp_update_feature - one existing feature, changed with everything
 * somcp_create_feature can write.
 *
 * It extends the creation tool rather than copying it: a field accepted at
 * creation is accepted here under the same name and checked by the same code,
 * so the two tools cannot come to disagree on what a valid name or URL word
 * is, and the faceted search rows are written by the same method.
 *
 * Only what is given changes. Each value is compared with what the shop holds
 * before anything is written, which gives the answer its old and new values
 * and lets a call that would change nothing write nothing at all: no hook
 * fires, and the answer says changed: false.
 *
 * The name goes through Feature::update() in the shop of the token, as the
 * core's EditFeatureHandler does, so the object hooks fire. The faceted search
 * fields come after, written the way the module's form writes them, since the
 * module reads them from the form's request only.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolCreateFeature.php';

class SomcpToolUpdateFeature extends SomcpToolCreateFeature
{
    /** @var array<int, string> What this tool takes besides the fields of the creation. */
    private const OWN_ARGUMENTS = ['id_feature'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_feature',
            Somcp::t('Change a feature'),
            'Changes one existing feature (Composition, Material...) of this shop. Everything '
                . 'somcp_create_feature can write can be changed here, under the same names and with the same '
                . 'rules - who fills each field, lengths, forbidden characters: name, and the faceted search '
                . 'fields url_name, meta_title and indexable when the faceted search module is installed and '
                . 'enabled. Only what is given changes: a value left out is kept, a text is replaced in the '
                . 'languages filled and kept in the others. When languages is left out every language of the '
                . 'shop is filled, so a text must then be given in each; to change one language, pass languages '
                . 'with it alone. '
                . 'Take the feature from somcp_list_features, never guessed, and read it with somcp_get_feature '
                . 'to know what it holds. Renaming a feature renames it on every product that carries it: tell '
                . 'the merchant so, show what will change and call once they agree. Every problem is reported '
                . 'at once and nothing is written until the whole call is valid. The answer gives each field '
                . 'changed with its old and new value. A call that would change nothing writes nothing and says '
                . 'changed: false. '
                . 'Do not use it to create a feature (somcp_create_feature) or to change one of its values '
                . '(somcp_update_feature_value). One feature per call. '
                . 'Not to be retried blindly: if the answer is lost, read the feature again with '
                . 'somcp_get_feature and compare before calling again.',
            self::inputSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Changing a feature is the edit right of the features page in
            // Team, Permissions; creating one is a different right.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminFeatures')
        );
    }

    /**
     * The creation schema, made to name an existing feature and to keep what is left out.
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

        $properties['name']['description'] = 'Merchant. The name shown on the product page, 128 characters at '
            . 'most, never empty, without < > = { }. Renamed on every product carrying the feature.'
            . ' One text per language being filled, keyed by ISO code: {"fr": "...", "en": "..."}.' . $kept;
        foreach (['url_name', 'meta_title'] as $field) {
            $properties[$field]['description'] .= $kept;
        }
        $properties['indexable']['description'] = 'Merchant. Faceted search: whether filtered pages on this '
            . 'feature are open to search engines.' . $kept;

        $schema['properties'] = [
            'id_feature' => ['type' => 'integer', 'description' => 'The feature to change, from somcp_list_features.'],
        ] + $properties;
        $schema['required'] = ['id_feature'];

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

        // The same test of the shop as somcp_get_feature, so a feature this
        // tool refuses is never one that tool describes.
        $feature = SomcpToolGetFeature::existingFeature(self::integer($arguments['id_feature'] ?? null) ?? 0, $idShop);
        if ($feature === null) {
            return SomcpTool::text('No feature of this shop has this identifier. Nothing was changed. Find it with somcp_list_features.', true);
        }

        $errors = [];
        $plan = self::planUpdate($arguments, $feature, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
        }

        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_feature' => (int) $feature->id,
                'reason' => 'The feature already holds every value given. Nothing was written.',
            ]);
        }

        return self::asEmployee($idEmployee, static function () use ($feature, $plan, $idShop, $idEmployee): array {
            return self::applyUpdate($feature, $plan, $idShop, $idEmployee);
        });
    }

    /**
     * Turns the arguments into what will be written, or says everything that is wrong with them.
     *
     * Nothing is written here. A value equal to what the shop holds is left
     * out of the plan, so what remains is exactly what will change.
     *
     * @param array<string, mixed> $arguments
     * @param Feature $feature
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function planUpdate(array $arguments, Feature $feature, int $idShop, array &$errors): ?array
    {
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::FEATURE_ARGUMENTS, true) && !in_array($key, self::OWN_ARGUMENTS, true)) {
                $errors[] = $key . (in_array($key, self::FEATURE_COMPUTED, true)
                    ? ': worked out by the shop, so it is not written here'
                    : ': not a field of this tool');
            }
        }

        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }

        $plan = [
            'languages' => $languages,
            'texts' => [],
            'faceted' => self::facetedSearchActive(),
            // null until given: unlike at creation, a missing indexable keeps
            // what the feature has rather than turning it off.
            'indexable' => null,
            'changes' => [],
            'names' => null,
            'faceted_rows' => null,
        ];

        self::featureNames($arguments, $languages, new Feature(), $plan, $errors, false);
        self::facetedFields($arguments, $languages, $plan, $errors);

        if ($errors !== []) {
            return $plan;
        }

        self::compareFeature($feature, $plan);

        // The same check the core runs before update(), on the whole object as
        // it will be written, as a last net for a rule none of the above knew.
        if ($plan['names'] !== null) {
            $candidate = clone $feature;
            self::fill($candidate, ['name' => $plan['names']]);
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'feature: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * Keeps the names and faceted search fields that differ from the shop, and records each one.
     *
     * @param Feature $feature loaded in every language
     * @param array<string, mixed> $plan
     *
     * @return void
     */
    private static function compareFeature(Feature $feature, array &$plan): void
    {
        $languages = $plan['languages'];

        if (isset($plan['texts']['name'])) {
            // Loaded without a language, the name is one text per language;
            // read as one anyway, should a version hand back a single string.
            $current = is_array($feature->name) ? $feature->name : [(int) $languages['fallback'] => (string) $feature->name];
            $merged = $current;
            foreach ($plan['texts']['name'] as $idLang => $name) {
                $old = (string) ($current[$idLang] ?? '');
                if ($old === $name) {
                    continue;
                }
                $merged[$idLang] = $name;
                self::record($plan, 'name (' . $languages['filled'][$idLang] . ')', $old, $name);
            }
            if ($merged !== $current) {
                $plan['names'] = $merged;
            }
        }

        $given = isset($plan['texts']['url_name']) || isset($plan['texts']['meta_title']) || $plan['indexable'] !== null;
        if (!$plan['faceted'] || !$given) {
            return;
        }

        $stored = self::storedFaceted((int) $feature->id, $languages['all']);
        $rows = $stored;
        foreach (['url_name', 'meta_title'] as $field) {
            foreach ($plan['texts'][$field] ?? [] as $idLang => $text) {
                $old = $stored[$field][$idLang] ?? '';
                if ($old === $text) {
                    continue;
                }
                $rows[$field][$idLang] = $text;
                self::record($plan, $field . ' (' . $languages['filled'][$idLang] . ')', $old, $text);
            }
        }
        if ($plan['indexable'] !== null && $plan['indexable'] !== $stored['indexable']) {
            $rows['indexable'] = $plan['indexable'];
            self::record($plan, 'indexable', $stored['indexable'], $plan['indexable']);
        }
        if ($rows !== $stored) {
            $plan['faceted_rows'] = $rows;
        }
    }

    /**
     * The faceted search fields the feature holds now, in every language of the shop.
     *
     * A feature whose form was never saved with the module has no row: the
     * form shows empty fields and an unticked box, and so does this.
     *
     * @param int $idFeature
     * @param array<int, int> $idLangs
     *
     * @return array{indexable: bool, url_name: array<int, string>, meta_title: array<int, string>}
     */
    private static function storedFaceted(int $idFeature, array $idLangs): array
    {
        $db = Db::getInstance();
        $stored = [
            'indexable' => (bool) $db->getValue(
                'SELECT `indexable` FROM `' . _DB_PREFIX_ . 'layered_indexable_feature` WHERE `id_feature` = ' . (int) $idFeature
            ),
            'url_name' => [],
            'meta_title' => [],
        ];
        foreach ($idLangs as $idLang) {
            $stored['url_name'][(int) $idLang] = '';
            $stored['meta_title'][(int) $idLang] = '';
        }
        $rows = $db->executeS(
            'SELECT `id_lang`, `url_name`, `meta_title` FROM `' . _DB_PREFIX_ . 'layered_indexable_feature_lang_value`'
            . ' WHERE `id_feature` = ' . (int) $idFeature
        );
        foreach (is_array($rows) ? $rows : [] as $row) {
            $idLang = (int) $row['id_lang'];
            if (isset($stored['url_name'][$idLang])) {
                $stored['url_name'][$idLang] = (string) $row['url_name'];
                $stored['meta_title'][$idLang] = (string) $row['meta_title'];
            }
        }

        return $stored;
    }

    /**
     * Writes the change, then the faceted search fields, and answers with what was stored.
     *
     * @param Feature $feature
     * @param array<string, mixed> $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function applyUpdate(Feature $feature, array $plan, int $idShop, int $idEmployee): array
    {
        $idFeature = (int) $feature->id;
        if ($plan['names'] !== null) {
            self::fill($feature, ['name' => $plan['names']]);
            // The shop of the token only: left to the context's list of shops,
            // update() can attach the feature to a shop it was never in.
            $feature->id_shop_list = [$idShop];

            try {
                $done = $feature->update();
                $failure = $done ? '' : 'PrestaShop refused to save it';
            } catch (Throwable $e) {
                $done = false;
                $failure = $e->getMessage();
            }
            if (!$done) {
                SomcpLogger::log(
                    'feature_update_failed',
                    SomcpLogger::OUTCOME_FAILURE,
                    'feature ' . $idFeature . ': ' . Tools::substr($failure, 0, 500),
                    '',
                    $idEmployee
                );

                return SomcpTool::text(
                    'The feature could not be changed: ' . $failure . '. The name may be changed in some languages '
                        . 'only, and the faceted search fields were not touched. Read the feature again with '
                        . 'somcp_get_feature, tell the merchant what it holds now, and do not call again before.',
                    true
                );
            }
        }

        $result = [
            'changed' => true,
            'id_feature' => $idFeature,
            'changes' => $plan['changes'],
        ];

        if ($plan['faceted_rows'] !== null) {
            $rows = $plan['faceted_rows'];
            if (!self::writeFaceted($idFeature, $rows['indexable'], $rows['url_name'], $rows['meta_title'], array_keys($rows['url_name']))) {
                SomcpLogger::log('feature_faceted_failed', SomcpLogger::OUTCOME_FAILURE, 'feature ' . $idFeature, '', $idEmployee);
                if ($plan['names'] === null) {
                    return SomcpTool::text(
                        'The faceted search fields could not be saved. Read the feature again with '
                            . 'somcp_get_feature, tell the merchant what it holds now, and do not call again before.',
                        true
                    );
                }
                // The new name is kept: it went through on its own, and the
                // merchant is told what did not.
                $result['faceted_search'] = 'not saved: fill url_name, meta_title and indexable in the back office';
            }
        } elseif ($plan['faceted']) {
            // The module's filter blocks carry the feature's name, and its own
            // form drops them on every save, a rename included.
            self::dropFacetedCache();
        }

        SomcpLogger::log(
            'feature_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'feature ' . $idFeature . ', ' . count($plan['changes']) . ' change(s)',
            '',
            $idEmployee
        );

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $result['feature'] = SomcpToolGetFeature::feature($idFeature, (int) $plan['languages']['fallback']);

        return SomcpTool::json($result);
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
