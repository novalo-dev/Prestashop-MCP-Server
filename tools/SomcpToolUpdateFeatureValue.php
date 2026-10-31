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
 * somcp_update_feature_value - one existing predefined feature value, changed
 * with everything somcp_create_feature_value can write.
 *
 * It extends the creation tool rather than copying it: a field accepted at
 * creation is accepted here under the same name and checked by the same code,
 * so the two tools cannot come to disagree on what a valid value or URL word
 * is, or on when two values of one feature clash, and the faceted search rows
 * are written by the same method.
 *
 * Only what is given changes. Each value is compared with what the shop holds
 * before anything is written, which gives the answer its old and new values
 * and lets a call that would change nothing write nothing at all: no hook
 * fires, and the answer says changed: false.
 *
 * The text and the feature go through FeatureValue::update(), as the core's
 * EditFeatureValueHandler does, so actionFeatureValueSave fires. The faceted
 * search fields come after, written the way the module's form writes them,
 * since the module reads them from the form's request only.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolCreateFeatureValue.php';

class SomcpToolUpdateFeatureValue extends SomcpToolCreateFeatureValue
{
    /** @var array<int, string> What this tool takes besides the fields of the creation. */
    private const OWN_ARGUMENTS = ['id_feature_value'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_feature_value',
            Somcp::t('Change a feature value'),
            'Changes one existing predefined value (Cotton, Linen...) of a feature of this shop. Everything '
                . 'somcp_create_feature_value can write can be changed here, under the same names and with the '
                . 'same rules - who fills each field, lengths, forbidden characters: value, the feature it '
                . 'belongs to (id_feature), and the faceted search fields url_name and meta_title when the '
                . 'faceted search module is installed and enabled. Only what is given changes: a field left out '
                . 'is kept, a text is replaced in the languages filled and kept in the others. When languages is '
                . 'left out every language of the shop is filled, so a text must then be given in each; to '
                . 'change one language, pass languages with it alone. '
                . 'Take the value from somcp_list_features, never guessed, and read it with '
                . 'somcp_get_feature_value to know what it holds. The value stays on the products that carry '
                . 'it: changing its text changes it on every one of them, so tell the merchant so, show what '
                . 'will change and call once they agree. Moving it to another feature is refused while a '
                . 'product carries it. A text the feature already holds in another of its values is refused. '
                . 'Every problem is reported at once and nothing is written until the whole call is valid. The '
                . 'answer gives each field changed with its old and new value. A call that would change nothing '
                . 'writes nothing and says changed: false. '
                . 'Do not use it to add a value (somcp_create_feature_value), to change the feature itself '
                . '(somcp_update_feature), or for a custom value typed on one product only, which is refused '
                . 'here and changed with somcp_update_product. One value per call. '
                . 'Not to be retried blindly: if the answer is lost, read the value again with '
                . 'somcp_get_feature_value and compare before calling again.',
            self::inputSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Values are managed under their feature, and changing one is the
            // edit right of the features page in Team, Permissions.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminFeatures')
        );
    }

    /**
     * The creation schema, made to name an existing value and to keep what is left out.
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

        $properties['id_feature']['description'] = 'Merchant. Moves the value to this feature, from '
            . 'somcp_list_features. Refused while a product carries the value.' . $kept;
        $properties['value']['description'] = 'Merchant. The value shown on the product page, 255 characters '
            . 'at most, never empty, without < > = { }. Changed on every product carrying it.'
            . ' One text per language being filled, keyed by ISO code: {"fr": "...", "en": "..."}.' . $kept;
        foreach (['url_name', 'meta_title'] as $field) {
            $properties[$field]['description'] .= $kept;
        }

        $schema['properties'] = [
            'id_feature_value' => [
                'type' => 'integer',
                'description' => 'The value to change, from somcp_list_features.',
            ],
        ] + $properties;
        $schema['required'] = ['id_feature_value'];

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

        // The same tests as somcp_get_feature_value, so a value this tool
        // refuses is never one that tool describes.
        $featureValue = SomcpToolGetFeatureValue::loadedValue(self::integer($arguments['id_feature_value'] ?? null) ?? 0, $idShop);
        if ($featureValue === null) {
            return SomcpTool::text('No feature value of this shop has this identifier. Nothing was changed. Find it with somcp_list_features.', true);
        }
        if (SomcpToolGetFeatureValue::isCustom($featureValue)) {
            return SomcpTool::text(
                'This is a custom value, typed on one product only: it belongs to that product. Nothing was '
                    . 'changed. Change it with somcp_update_product.',
                true
            );
        }

        $errors = [];
        $plan = self::planUpdate($arguments, $featureValue, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
        }

        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_feature_value' => (int) $featureValue->id,
                'reason' => 'The value already holds everything given. Nothing was written.',
            ]);
        }

        return self::asEmployee($idEmployee, static function () use ($featureValue, $plan, $idEmployee): array {
            return self::applyUpdate($featureValue, $plan, $idEmployee);
        });
    }

    /**
     * Turns the arguments into what will be written, or says everything that is wrong with them.
     *
     * Nothing is written here. A field equal to what the shop holds is left
     * out of the plan, so what remains is exactly what will change.
     *
     * @param array<string, mixed> $arguments
     * @param FeatureValue $featureValue loaded in every language
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function planUpdate(array $arguments, FeatureValue $featureValue, int $idShop, array &$errors): ?array
    {
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::VALUE_ARGUMENTS, true) && !in_array($key, self::OWN_ARGUMENTS, true)) {
                $errors[] = $key . (in_array($key, self::VALUE_COMPUTED, true)
                    ? ': worked out by the shop, so it is not written here'
                    : ': not a field of this tool');
            }
        }

        // The feature the value ends up in: the texts are compared with the
        // values of that one, the one the storefront filter will group them in.
        $idFeature = (int) $featureValue->id_feature;
        if (array_key_exists('id_feature', $arguments)) {
            $idFeature = self::integer($arguments['id_feature']) ?? 0;
            // A feature of another shop is answered like one that does not
            // exist, the test somcp_get_feature makes.
            if (SomcpToolGetFeature::existingFeature($idFeature, $idShop) === null) {
                $errors[] = 'id_feature: no feature of this shop has this identifier - find it with somcp_list_features';
                $idFeature = 0;
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
            'changes' => [],
            'values' => null,
            'id_feature' => null,
            'faceted_rows' => null,
        ];

        self::valueTexts($arguments, $languages, $idFeature, $plan, $errors, false, (int) $featureValue->id);
        // A value has no Indexable of its own: that switch belongs to the
        // feature. It is already reported as not a field of this tool, so it
        // is kept away from the shared check, which would report it twice.
        self::facetedFields(array_diff_key($arguments, ['indexable' => true]), $languages, $plan, $errors);

        if ($errors !== []) {
            return $plan;
        }

        self::compareValue($featureValue, $idFeature, $plan, $errors);

        // The same check the core runs before update(), on the whole object as
        // it will be written, as a last net for a rule none of the above knew.
        if ($errors === [] && ($plan['values'] !== null || $plan['id_feature'] !== null)) {
            $candidate = clone $featureValue;
            self::fill($candidate, array_filter([
                'value' => $plan['values'],
                'id_feature' => $plan['id_feature'],
            ], static function ($value): bool {
                return $value !== null;
            }));
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'value: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * Keeps the texts, feature and faceted search fields that differ from the shop, and records each one.
     *
     * @param FeatureValue $featureValue loaded in every language
     * @param int $idFeature the feature the value ends up in
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function compareValue(FeatureValue $featureValue, int $idFeature, array &$plan, array &$errors): void
    {
        $languages = $plan['languages'];
        $idFeatureValue = (int) $featureValue->id;

        // Loaded without a language, the value is one text per language; read
        // as one anyway, should a version hand back a single string.
        $current = is_array($featureValue->value)
            ? array_map('strval', $featureValue->value)
            : [(int) $languages['fallback'] => (string) $featureValue->value];
        $merged = $current;
        foreach ($plan['texts']['value'] ?? [] as $idLang => $value) {
            $old = $current[$idLang] ?? '';
            if ($old === $value) {
                continue;
            }
            $merged[$idLang] = $value;
            self::record($plan, 'value (' . $languages['filled'][$idLang] . ')', $old, $value);
        }
        if ($merged !== $current) {
            $plan['values'] = $merged;
        }

        $idCurrent = (int) $featureValue->id_feature;
        if ($idFeature !== $idCurrent) {
            self::checkMove($idFeatureValue, $idFeature, $merged, $plan, $errors);
            $plan['id_feature'] = $idFeature;
            self::record($plan, 'id_feature', $idCurrent, $idFeature);
        }

        $given = isset($plan['texts']['url_name']) || isset($plan['texts']['meta_title']);
        if (!$plan['faceted'] || !$given) {
            return;
        }

        $stored = self::storedValueFaceted($idFeatureValue, $languages['all']);
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
        if ($rows !== $stored) {
            $plan['faceted_rows'] = $rows;
        }
    }

    /**
     * Refuses a move to another feature that would leave the shop inconsistent.
     *
     * The products carrying a value name its feature in their own row of
     * feature_product, and FeatureValue::update() leaves those rows alone: the
     * products would show the value under the feature it left, and the
     * product form could no longer find it among that feature's values. Moved
     * only when no product carries it, the value cannot end up there.
     *
     * The texts given were compared with the new feature's values already; the
     * ones kept from the value are compared here, in every language.
     *
     * @param int $idFeatureValue
     * @param int $idFeature
     * @param array<int, string> $texts the value's texts as they will be, by language identifier
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function checkMove(int $idFeatureValue, int $idFeature, array $texts, array $plan, array &$errors): void
    {
        $products = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'feature_product` WHERE `id_feature_value` = ' . (int) $idFeatureValue
        );
        if ($products > 0) {
            $errors[] = 'id_feature: ' . $products . ' product(s) carry this value, and PrestaShop would keep showing it '
                . 'under its current feature on them - take it off those products first, or add the value to the '
                . 'other feature with somcp_create_feature_value';
        }

        $taken = self::predefinedTexts($idFeature, $idFeatureValue);
        foreach ($texts as $idLang => $text) {
            $text = Tools::strtolower(trim($text));
            if (isset($plan['texts']['value'][$idLang]) || $text === '' || !isset($taken[$idLang][$text])) {
                continue;
            }
            $errors[] = 'value (' . (string) Language::getIsoById((int) $idLang) . '): the feature it moves to already has '
                . 'this value, id_feature_value ' . $taken[$idLang][$text] . ' - give it another text in the same call';
        }
    }

    /**
     * The faceted search fields the value holds now, in every language of the shop.
     *
     * A value whose form was never saved with the module has no row: the form
     * shows empty fields, and so does this.
     *
     * @param int $idFeatureValue
     * @param array<int, int> $idLangs
     *
     * @return array{url_name: array<int, string>, meta_title: array<int, string>}
     */
    private static function storedValueFaceted(int $idFeatureValue, array $idLangs): array
    {
        $stored = ['url_name' => [], 'meta_title' => []];
        foreach ($idLangs as $idLang) {
            $stored['url_name'][(int) $idLang] = '';
            $stored['meta_title'][(int) $idLang] = '';
        }
        $rows = Db::getInstance()->executeS(
            'SELECT `id_lang`, `url_name`, `meta_title` FROM `' . _DB_PREFIX_ . 'layered_indexable_feature_value_lang_value`'
            . ' WHERE `id_feature_value` = ' . (int) $idFeatureValue
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
     * @param FeatureValue $featureValue
     * @param array<string, mixed> $plan
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function applyUpdate(FeatureValue $featureValue, array $plan, int $idEmployee): array
    {
        $idFeatureValue = (int) $featureValue->id;
        $idLeft = (int) $featureValue->id_feature;
        $written = $plan['values'] !== null || $plan['id_feature'] !== null;

        // update() fires actionFeatureValueSave, on which the faceted search
        // module deletes the value's rows and writes back only what its form's
        // request carries - nothing, here. The fields this call leaves alone
        // are read first and written back once the value is saved.
        $kept = null;
        if ($written && $plan['faceted'] && $plan['faceted_rows'] === null) {
            $kept = self::storedValueFaceted($idFeatureValue, $plan['languages']['all']);
        }

        if ($written) {
            if ($plan['values'] !== null) {
                self::fill($featureValue, ['value' => $plan['values']]);
            }
            if ($plan['id_feature'] !== null) {
                $featureValue->id_feature = (int) $plan['id_feature'];
                // PrestaShop 9 orders values within their feature, and update()
                // keeps the number the value had in the one it leaves: it is
                // put last in the new one, as add() places a new value.
                if (self::orderedValues()) {
                    $highest = self::versionMethod('getHighestPosition');
                    self::fill($featureValue, ['position' => (int) FeatureValue::$highest((int) $plan['id_feature']) + 1]);
                }
            }

            try {
                $done = $featureValue->update();
                $failure = $done ? '' : 'PrestaShop refused to save it';
            } catch (Throwable $e) {
                $done = false;
                $failure = $e->getMessage();
            }
            if (!$done) {
                SomcpLogger::log(
                    'feature_value_update_failed',
                    SomcpLogger::OUTCOME_FAILURE,
                    'feature value ' . $idFeatureValue . ': ' . Tools::substr($failure, 0, 500),
                    '',
                    $idEmployee
                );

                return SomcpTool::text(
                    'The feature value could not be changed: ' . $failure . '. The text may be changed in some '
                        . 'languages only, and the faceted search fields were not touched. Read the value again with '
                        . 'somcp_get_feature_value, tell the merchant what it holds now, and do not call again before.',
                    true
                );
            }

            // The positions left behind are closed up, as the core does when a
            // value is deleted, so the old feature keeps no gap.
            if ($plan['id_feature'] !== null && self::orderedValues()) {
                $clean = self::versionMethod('cleanPositions');
                $featureValue->$clean($idLeft);
            }

            // A value that never had faceted search fields has nothing to put back.
            if ($kept !== null && implode('', $kept['url_name']) . implode('', $kept['meta_title']) !== '') {
                $plan['faceted_rows'] = $kept;
            }
        }

        $result = [
            'changed' => true,
            'id_feature_value' => $idFeatureValue,
            'changes' => $plan['changes'],
        ];

        if ($plan['faceted_rows'] !== null) {
            $rows = $plan['faceted_rows'];
            if (!self::writeValueFaceted($idFeatureValue, $rows['url_name'], $rows['meta_title'], array_keys($rows['url_name']))) {
                SomcpLogger::log('feature_value_faceted_failed', SomcpLogger::OUTCOME_FAILURE, 'feature value ' . $idFeatureValue, '', $idEmployee);
                if (!$written) {
                    return SomcpTool::text(
                        'The faceted search fields could not be saved. Read the value again with '
                            . 'somcp_get_feature_value, tell the merchant what it holds now, and do not call again before.',
                        true
                    );
                }
                // The new text is kept: it went through on its own, and the
                // merchant is told what did not.
                $result['faceted_search'] = 'not saved: fill url_name and meta_title in the back office';
            }
        } elseif ($plan['faceted']) {
            // The module's filter blocks carry the value's text, and its own
            // form drops them on every save.
            self::dropFacetedCache();
        }

        SomcpLogger::log(
            'feature_value_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'feature value ' . $idFeatureValue . ', ' . count($plan['changes']) . ' change(s)',
            '',
            $idEmployee
        );

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $result['feature_value'] = SomcpToolGetFeatureValue::featureValue($idFeatureValue, (int) $plan['languages']['fallback']);

        return SomcpTool::json($result);
    }

    /**
     * Whether the running version gives feature values a position.
     *
     * PrestaShop 9 added it with getHighestPosition() and cleanPositions(),
     * which PrestaShop 8 does not have. They are called by name where this is
     * true, since PHPStan analyses the module against one version at a time
     * and would report the other one's methods as missing.
     *
     * @return bool
     */
    private static function orderedValues(): bool
    {
        return version_compare(_PS_VERSION_, '9.0.0', '>=');
    }

    /**
     * The name of a method only some versions have, typed as any string.
     *
     * Held in a local variable, a literal name is still resolved by PHPStan,
     * which then reports the method as missing on PrestaShop 8 although the
     * call is guarded by orderedValues(). Returned from here, it is not.
     *
     * @param string $name
     *
     * @return string
     */
    private static function versionMethod(string $name): string
    {
        return $name;
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
