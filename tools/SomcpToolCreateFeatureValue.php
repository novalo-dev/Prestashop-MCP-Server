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
 * somcp_create_feature_value - one predefined value of a feature, with every
 * field of the back office value form, faceted search fields included.
 *
 * It extends the feature creation tool for what a value shares with its
 * feature and must not come to disagree on: the languages being filled, texts
 * keyed by ISO code, the faceted search URL word and title tag checked and
 * stored the way that module's form does it, and the employee of the token
 * carried on the write.
 *
 * Everything is checked before anything is written, and every problem is
 * reported at once. The value is written through FeatureValue::add(), so
 * actionFeatureValueSave fires, and deleted again if that fails after the row
 * went in.
 *
 * The URL word and the title tag are not PrestaShop's: the faceted search
 * module saves them from its form's own request only, and its
 * actionFeatureValueSave hook, finding nothing in this request, leaves the
 * value without them. The tool writes the rows that module writes once the
 * value exists.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends, and it answers
// with the value as somcp_get_feature_value returns it.
require_once __DIR__ . '/SomcpToolCreateFeature.php';
require_once __DIR__ . '/SomcpToolGetFeature.php';
require_once __DIR__ . '/SomcpToolGetFeatureValue.php';

class SomcpToolCreateFeatureValue extends SomcpToolCreateFeature
{
    /** @var array<int, string> Every argument this tool takes. */
    protected const VALUE_ARGUMENTS = ['id_feature', 'languages', 'value', 'url_name', 'meta_title'];

    /**
     * @var array<int, string> What somcp_get_feature_value returns and this tool refuses, because the shop
     *                         works it out. Named, so the assistant learns why rather than meeting an "unknown field".
     */
    protected const VALUE_COMPUTED = ['id_feature_value', 'feature_name', 'position', 'custom'];

    /** @var int The size of the value column (FeatureValue::$definition). */
    protected const VALUE_MAX = 255;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_feature_value',
            Somcp::t('Create a feature value'),
            'Adds one predefined value (Cotton, Linen...) to a feature of this shop, with every field of the '
                . 'back office value form: value, and the faceted search fields of that form - url_name (the '
                . 'word used in filtered URLs) and meta_title - when the faceted search module is installed and '
                . 'enabled. id_feature and value are required; take id_feature from somcp_list_features, never '
                . 'guess it. Read somcp_list_features first: the value may already exist, and a value whose text '
                . 'the feature already holds is refused. '
                . 'Who fills each field is said at the start of its description, and is a rule for you: '
                . '"Merchant" - ask the merchant and never invent the value; you may suggest one for them to '
                . 'confirm. "Assistant" - write it yourself in every language being filled, and show it to the '
                . 'merchant before the call. '
                . 'Texts are objects keyed by ISO code and must be given for every language in languages; a '
                . 'language of the shop left out takes the default language\'s text. '
                . 'Every problem is reported at once and nothing is written until the whole call is valid. '
                . 'Do not use it to create a feature, which is somcp_create_feature, to change a value, which is '
                . 'somcp_update_feature_value, or to give one product its own value, which is '
                . 'somcp_update_product with a custom value in features. One value per call: '
                . 'for several, call it once per value and report each result. '
                . 'Not to be retried: if the answer is lost, read somcp_list_features and look for the value '
                . 'before anything else.',
            self::inputSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Values are added under their feature, on the back office
            // features page, with the right to add there.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminFeatures')
        );
    }

    /**
     * The arguments, under the names somcp_get_feature_value returns.
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
                'id_feature' => [
                    'type' => 'integer',
                    'description' => 'Merchant. Required. Identifier of the feature the value belongs to, from '
                        . 'somcp_list_features.',
                ],
                'languages' => [
                    'type' => 'array',
                    'description' => 'ISO codes of the languages to fill, among the active languages of the shop. '
                        . 'All of them by default. Every text must then be given in each of them.',
                    'items' => ['type' => 'string'],
                ],
                'value' => $text('Merchant. Required. The value shown on the product page, 255 characters at '
                    . 'most, without < > = { }.'),
                'url_name' => $text('Assistant. Faceted search: the word used in filtered URLs instead of the '
                    . 'value, lower case letters, digits and hyphens, for example "linen". Empty for the value. '
                    . '128 characters at most.'),
                'meta_title' => $text('Assistant. Faceted search: the title tag of filtered pages instead of the '
                    . 'value. 128 characters at most, without < > = { }.'),
            ],
            'required' => ['id_feature', 'value'],
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
        $plan = self::planValue($arguments, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was created. Correct these and call again:\n- " . implode("\n- ", $errors),
                true
            );
        }

        return self::asEmployee($idEmployee, static function () use ($plan, $idEmployee): array {
            return self::addValue($plan, $idEmployee);
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
    private static function planValue(array $arguments, int $idShop, array &$errors): ?array
    {
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::VALUE_ARGUMENTS, true)) {
                $errors[] = $key . (in_array($key, self::VALUE_COMPUTED, true)
                    ? ': worked out by the shop, so it is not written here'
                    : ': not a field of this tool');
            }
        }

        $idFeature = 0;
        if (!array_key_exists('id_feature', $arguments)) {
            $errors[] = 'id_feature: required, from somcp_list_features';
        } else {
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
            'id_feature' => $idFeature,
            'languages' => $languages,
            'texts' => [],
            'faceted' => self::facetedSearchActive(),
            'indexable' => false,
        ];

        self::valueTexts($arguments, $languages, $idFeature, $plan, $errors);
        // A value has no Indexable of its own: that switch belongs to the
        // feature. It is already reported as not a field of this tool, so it
        // is kept away from the shared check, which would report it twice.
        self::facetedFields(array_diff_key($arguments, ['indexable' => true]), $languages, $plan, $errors);

        // The same check the core runs before add(), on the whole object, as a
        // last net for a rule of the running version none of the above knew.
        if ($errors === []) {
            $candidate = self::newValue($plan);
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'value: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * The texts of the value, checked with the running version's rules, and
     * refused when the feature already holds them.
     *
     * @param array<string, mixed> $arguments
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idFeature 0 when the feature was refused, which leaves nothing to compare with
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     * @param bool $required false when changing a value, which already has a text
     * @param int $idOwn the value being changed, which never clashes with itself; 0 for a new one
     *
     * @return void
     */
    protected static function valueTexts(array $arguments, array $languages, int $idFeature, array &$plan, array &$errors, bool $required = true, int $idOwn = 0): void
    {
        $probe = new FeatureValue();
        // Given, a value is never empty, whether it is new or not.
        $values = self::texts($arguments, 'value', 'value', $languages, $errors, $required || array_key_exists('value', $arguments));
        if ($values === null) {
            return;
        }

        $taken = $idFeature > 0 ? self::predefinedTexts($idFeature, $idOwn) : [];
        foreach ($values as $idLang => $value) {
            $value = trim($value);
            $values[$idLang] = $value;
            $where = 'value (' . $languages['filled'][$idLang] . ')';
            if ($value === '') {
                // Already reported by texts() as missing.
                continue;
            }
            if (Tools::strlen($value) > self::VALUE_MAX) {
                $errors[] = $where . ': ' . self::VALUE_MAX . ' characters at most, ' . Tools::strlen($value) . ' given';
            } elseif (($check = $probe->validateField('value', $value, $idLang)) !== true) {
                $errors[] = $where . ': a character PrestaShop refuses in a feature value (< > = { }) - ' . self::reason($check);
            } elseif (isset($taken[$idLang][Tools::strtolower($value)])) {
                // Compared without case: the storefront filter would show
                // both under one label, and the merchant could not tell them apart.
                $errors[] = $where . ': the feature already has this value, id_feature_value '
                    . $taken[$idLang][Tools::strtolower($value)]
                    . ($idOwn > 0 ? ' - two values of one feature cannot share a text' : ' - use it rather than adding it again');
            }
        }
        $plan['texts']['value'] = $values;
    }

    /**
     * The texts of the predefined values a feature already holds, per language.
     *
     * Custom values are left out: each belongs to one product, and the merchant
     * never picks them from the list.
     *
     * @param int $idFeature
     * @param int $idOwn a value left out of the comparison, the one being changed; 0 for none
     *
     * @return array<int, array<string, int>> lower-cased text => id_feature_value, by language identifier
     */
    protected static function predefinedTexts(int $idFeature, int $idOwn = 0): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT fvl.`id_feature_value`, fvl.`id_lang`, fvl.`value`'
            . ' FROM `' . _DB_PREFIX_ . 'feature_value` fv'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl ON fvl.`id_feature_value` = fv.`id_feature_value`'
            . ' WHERE fv.`id_feature` = ' . (int) $idFeature
            // custom is nullable, and the core reads NULL as a predefined value.
            . ' AND (fv.`custom` = 0 OR fv.`custom` IS NULL)'
            . ' AND fv.`id_feature_value` != ' . (int) $idOwn
        );

        $taken = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $text = Tools::strtolower(trim((string) $row['value']));
            $taken[(int) $row['id_lang']][$text] = $taken[(int) $row['id_lang']][$text] ?? (int) $row['id_feature_value'];
        }

        return $taken;
    }

    /**
     * The value as it will be added, every language of the shop filled.
     *
     * @param array<string, mixed> $plan
     *
     * @return FeatureValue
     */
    private static function newValue(array $plan): FeatureValue
    {
        $featureValue = new FeatureValue();
        $featureValue->id_feature = (int) $plan['id_feature'];
        // Set rather than left to the column default: a predefined value is
        // what the back office form creates, and what the list offers.
        $featureValue->custom = false;
        self::fill($featureValue, self::completeTexts(['value' => $plan['texts']['value']], $plan['languages']));

        return $featureValue;
    }

    /**
     * Writes the value, then its faceted search fields, and answers with what was stored.
     *
     * @param array<string, mixed> $plan
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function addValue(array $plan, int $idEmployee): array
    {
        $featureValue = self::newValue($plan);

        try {
            $done = $featureValue->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        if (!$done) {
            $kept = false;
            if ((int) $featureValue->id > 0) {
                try {
                    $kept = !$featureValue->delete();
                } catch (Throwable $e) {
                    $kept = true;
                }
            }
            SomcpLogger::log(
                'feature_value_create_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr($failure, 0, 500) . ($kept ? ', feature value ' . (int) $featureValue->id . ' could not be removed' : ''),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The feature value could not be created: ' . $failure . '. '
                    . ($kept
                        ? 'It could not be removed either: feature value ' . (int) $featureValue->id . ' exists in an '
                            . 'incomplete state. Tell the merchant to check it in the back office; do not call again.'
                        : 'Nothing was kept.'),
                true
            );
        }

        $idFeatureValue = (int) $featureValue->id;
        $result = [
            'created' => true,
            'id_feature_value' => $idFeatureValue,
        ];

        // Written on every creation once the module is there, empty fields
        // included, so a value created here reads the same as one saved on screen.
        if ($plan['faceted'] && !self::storeValueFaceted($idFeatureValue, $plan)) {
            // The value is kept: it is usable without them, and removing it
            // would lose what did go through. The merchant is told instead.
            $result['faceted_search'] = 'not saved: fill url_name and meta_title in the back office';
            SomcpLogger::log('feature_value_faceted_failed', SomcpLogger::OUTCOME_FAILURE, 'feature value ' . $idFeatureValue, '', $idEmployee);
        }

        SomcpLogger::log('feature_value_created', SomcpLogger::OUTCOME_SUCCESS, 'feature value ' . $idFeatureValue, '', $idEmployee);

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $result['feature_value'] = SomcpToolGetFeatureValue::featureValue($idFeatureValue, (int) $plan['languages']['fallback']);

        return SomcpTool::json($result);
    }

    /**
     * The faceted search rows of a new value, written as the module's form writes them.
     *
     * A language left out takes the default one's text, as for the value.
     *
     * @param int $idFeatureValue
     * @param array<string, mixed> $plan
     *
     * @return bool
     */
    protected static function storeValueFaceted(int $idFeatureValue, array $plan): bool
    {
        return self::writeValueFaceted(
            $idFeatureValue,
            self::complete($plan['texts']['url_name'] ?? [], $plan['languages']),
            self::complete($plan['texts']['meta_title'] ?? [], $plan['languages']),
            $plan['languages']['all']
        );
    }

    /**
     * Replaces the faceted search rows of one value, then drops the module's filter cache.
     *
     * The module's own save reads its form or the request, neither of which
     * can be handed values, so its rows are written here, through the same
     * columns: one per language of the shop for the URL word and title tag.
     *
     * @param int $idFeatureValue
     * @param array<int, string> $urls by language identifier
     * @param array<int, string> $titles by language identifier
     * @param array<int, int> $idLangs every language of the shop
     *
     * @return bool
     */
    protected static function writeValueFaceted(int $idFeatureValue, array $urls, array $titles, array $idLangs): bool
    {
        $db = Db::getInstance();

        try {
            // The module replaces the rows rather than updating them, and a
            // row left by a value that once had this identifier would
            // otherwise make the insert fail.
            $done = $db->delete('layered_indexable_feature_value_lang_value', '`id_feature_value` = ' . (int) $idFeatureValue);
            foreach ($idLangs as $idLang) {
                $done = $done && $db->insert('layered_indexable_feature_value_lang_value', [
                    'id_feature_value' => (int) $idFeatureValue,
                    'id_lang' => (int) $idLang,
                    'url_name' => pSQL((string) ($urls[$idLang] ?? '')),
                    'meta_title' => pSQL((string) ($titles[$idLang] ?? '')),
                ]);
            }
        } catch (Throwable $e) {
            $done = false;
        }

        self::dropFacetedCache();

        return $done;
    }
}
