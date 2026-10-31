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
 * somcp_create_feature - one feature, with every field of the back office
 * feature form, faceted search fields included.
 *
 * It extends the category creation tool for what a feature shares with the
 * other catalogue writes and must not come to disagree on: the languages being
 * filled, texts keyed by ISO code, a language left out taking the default
 * one's text, and the employee of the token carried on the write.
 *
 * Everything is checked before anything is written, and every problem is
 * reported at once. The feature is written through Feature::add(), in the shop
 * of the token, and deleted again if that fails after the row went in.
 *
 * The URL word, the title tag and Indexable are not PrestaShop's: the faceted
 * search module adds them to the form and saves them from the form's own
 * request only, so a feature added through Feature::add() would never get
 * them. The tool writes the rows that module writes, the way it writes them,
 * once the feature exists.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends, and it answers
// with the feature as somcp_get_feature returns it.
require_once __DIR__ . '/SomcpToolCreateCategory.php';
require_once __DIR__ . '/SomcpToolGetFeature.php';

class SomcpToolCreateFeature extends SomcpToolCreateCategory
{
    /** @var array<int, string> Every argument this tool takes. */
    protected const FEATURE_ARGUMENTS = ['languages', 'name', 'url_name', 'meta_title', 'indexable'];

    /**
     * @var array<int, string> What somcp_get_feature returns and this tool refuses, because the shop works it
     *                         out. Named, so the assistant learns why rather than meeting an "unknown field".
     */
    protected const FEATURE_COMPUTED = ['id_feature', 'position', 'values_count'];

    /** @var array<int, string> The fields the faceted search module adds to the form. */
    protected const FACETED_FIELDS = ['url_name', 'meta_title', 'indexable'];

    /** @var int The size of the name column (Feature::$definition). */
    protected const FEATURE_NAME_MAX = 128;

    /** @var int The size of url_name and meta_title in the faceted search module's tables. */
    protected const FACETED_TEXT_MAX = 128;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_feature',
            Somcp::t('Create a feature'),
            'Creates one feature (Composition, Material...) in this shop with every field of the back office '
                . 'feature form: name, and the faceted search fields of that form - url_name (the word used in '
                . 'filtered URLs), meta_title and indexable - when the faceted search module is installed and '
                . 'enabled. Only name is required. The feature is created without values: add them afterwards, '
                . 'one per call, with somcp_create_feature_value when the shop offers it. '
                . 'Read somcp_list_features first: the feature may already exist, and PrestaShop accepts two '
                . 'features of the same name. '
                . 'Who fills each field is said at the start of its description, and is a rule for you: '
                . '"Merchant" - ask the merchant and never invent the value; you may suggest one for them to '
                . 'confirm. "Assistant" - write it yourself in every language being filled, and show it to the '
                . 'merchant before the call. '
                . 'Texts are objects keyed by ISO code and must be given for every language in languages; a '
                . 'language of the shop left out takes the default language\'s text. '
                . 'Every problem is reported at once and nothing is written until the whole call is valid. '
                . 'Do not use it to add a value to a feature, which is somcp_create_feature_value, or to change a '
                . 'feature, which is somcp_update_feature. One feature per call: for several, call it once per '
                . 'feature and report each result. '
                . 'Not to be retried: if the answer is lost, read somcp_list_features and look for the name '
                . 'before anything else, because a second call creates a second feature.',
            self::inputSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Adding a feature is what the features page asks for in Team,
            // Permissions; editing one is a different right.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminFeatures')
        );
    }

    /**
     * The arguments, under the names somcp_get_feature returns.
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
                'name' => $text('Merchant. Required. The name shown on the product page, 128 characters at most, '
                    . 'without < > = { }.'),
                'url_name' => $text('Assistant. Faceted search: the word used in filtered URLs instead of the '
                    . 'name, lower case letters, digits and hyphens, for example "material". Empty for the name. '
                    . '128 characters at most.'),
                'meta_title' => $text('Assistant. Faceted search: the title tag of filtered pages instead of the '
                    . 'name. 128 characters at most, without < > = { }.'),
                'indexable' => [
                    'type' => 'boolean',
                    'description' => 'Merchant. Faceted search: whether filtered pages on this feature are open to '
                        . 'search engines. false by default, as in the form.',
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
        $plan = self::planFeature($arguments, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was created. Correct these and call again:\n- " . implode("\n- ", $errors),
                true
            );
        }

        return self::asEmployee($idEmployee, static function () use ($plan, $idShop, $idEmployee): array {
            return self::addFeature($plan, $idShop, $idEmployee);
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
    private static function planFeature(array $arguments, int $idShop, array &$errors): ?array
    {
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::FEATURE_ARGUMENTS, true)) {
                $errors[] = $key . (in_array($key, self::FEATURE_COMPUTED, true)
                    ? ': worked out by the shop, so it is not written here'
                    : ': not a field of this tool');
            }
        }

        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }

        $probe = new Feature();
        $plan = [
            'languages' => $languages,
            'texts' => [],
            'faceted' => self::facetedSearchActive(),
            'indexable' => false,
        ];

        self::featureNames($arguments, $languages, $probe, $plan, $errors, true);
        self::facetedFields($arguments, $languages, $plan, $errors);

        // The same check the core runs before add(), on the whole object, as a
        // last net for a rule of the running version none of the above knew.
        if ($errors === []) {
            $candidate = new Feature();
            self::fill($candidate, self::completeTexts(['name' => $plan['texts']['name']], $languages));
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'feature: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * Whether the faceted search module is there to use the fields it adds.
     *
     * Its tables exist as long as it is installed, but a disabled module does
     * not filter anything: fields written for it would be promises nothing
     * keeps, so they are refused rather than stored.
     *
     * @return bool
     */
    public static function facetedSearchActive(): bool
    {
        return Module::isInstalled('ps_facetedsearch') && Module::isEnabled('ps_facetedsearch');
    }

    /**
     * The names given in the call, checked with the running version's rules.
     *
     * @param array<string, mixed> $arguments
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param Feature $probe
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     * @param bool $required false when changing a feature, which already has a name
     *
     * @return void
     */
    protected static function featureNames(array $arguments, array $languages, Feature $probe, array &$plan, array &$errors, bool $required): void
    {
        // Given, a name is never empty, whether the feature is new or not.
        $names = self::texts($arguments, 'name', 'name', $languages, $errors, $required || array_key_exists('name', $arguments));
        foreach ($names ?? [] as $idLang => $name) {
            $name = trim($name);
            $names[$idLang] = $name;
            $where = 'name (' . $languages['filled'][$idLang] . ')';
            if (Tools::strlen($name) > self::FEATURE_NAME_MAX) {
                $errors[] = $where . ': ' . self::FEATURE_NAME_MAX . ' characters at most, ' . Tools::strlen($name) . ' given';
            } elseif ($name !== '' && ($check = $probe->validateField('name', $name, $idLang)) !== true) {
                // Feature::$name is checked with isGenericName, not the
                // catalogue rule of products and categories.
                $errors[] = $where . ': a character PrestaShop refuses in a feature name (< > = { }) - ' . self::reason($check);
            }
        }
        if ($names !== null) {
            $plan['texts']['name'] = $names;
        }
    }

    /**
     * The faceted search fields given in the call, refused when the module is not there.
     *
     * The URL word is checked the way the module's own form checks it - the
     * text must already be what Tools::str2url() makes of it - and stored the
     * way the module stores it.
     *
     * @param array<string, mixed> $arguments
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function facetedFields(array $arguments, array $languages, array &$plan, array &$errors): void
    {
        $given = array_values(array_intersect(self::FACETED_FIELDS, array_keys($arguments)));
        if ($given === []) {
            return;
        }
        if (!$plan['faceted']) {
            $errors[] = implode(', ', $given) . ': fields of the faceted search module (ps_facetedsearch), which is '
                . 'not installed and enabled in this shop; leave them out';

            return;
        }

        $urls = self::texts($arguments, 'url_name', 'url_name', $languages, $errors);
        foreach ($urls ?? [] as $idLang => $url) {
            $url = trim($url);
            $where = 'url_name (' . $languages['filled'][$idLang] . ')';
            if ($url === '') {
                $urls[$idLang] = '';
            } elseif (Tools::strlen($url) > self::FACETED_TEXT_MAX) {
                $errors[] = $where . ': ' . self::FACETED_TEXT_MAX . ' characters at most, ' . Tools::strlen($url) . ' given';
            } elseif (Tools::str2url($url) !== Tools::strtolower($url)) {
                // Refused rather than converted, as the back office form does:
                // the merchant sees the word that goes into the address.
                $errors[] = $where . ': not a URL word - lower case letters, digits and hyphens, for example "'
                    . Tools::str2url($url) . '"';
            } else {
                $urls[$idLang] = (string) Tools::str2url($url);
            }
        }
        if ($urls !== null) {
            $plan['texts']['url_name'] = $urls;
        }

        $titles = self::texts($arguments, 'meta_title', 'meta_title', $languages, $errors);
        foreach ($titles ?? [] as $idLang => $title) {
            $title = trim($title);
            $titles[$idLang] = $title;
            $where = 'meta_title (' . $languages['filled'][$idLang] . ')';
            if (Tools::strlen($title) > self::FACETED_TEXT_MAX) {
                $errors[] = $where . ': ' . self::FACETED_TEXT_MAX . ' characters at most, ' . Tools::strlen($title) . ' given';
            } elseif (!Validate::isGenericName($title)) {
                // The module strips tags from it when it stores it: refused
                // here, the merchant never ends up with a title they did not write.
                $errors[] = $where . ': a character PrestaShop refuses there (< > = { })';
            }
        }
        if ($titles !== null) {
            $plan['texts']['meta_title'] = $titles;
        }

        if (array_key_exists('indexable', $arguments)) {
            // A boolean only: "false" cast to bool is true, and would open
            // filtered pages to search engines against the merchant's word.
            if (!is_bool($arguments['indexable'])) {
                $errors[] = 'indexable: true or false';
            } else {
                $plan['indexable'] = $arguments['indexable'];
            }
        }
    }

    /**
     * Writes the feature, then its faceted search fields, and answers with what was stored.
     *
     * @param array<string, mixed> $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function addFeature(array $plan, int $idShop, int $idEmployee): array
    {
        $feature = new Feature();
        self::fill($feature, self::completeTexts(['name' => $plan['texts']['name']], $plan['languages']));
        // The shop of the token, never the context's list, so on a multistore
        // installation the feature goes to that shop only, as the core's
        // AddFeatureHandler does with the shops it is given.
        $feature->id_shop_list = [$idShop];

        try {
            $done = $feature->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        if (!$done) {
            $kept = false;
            if ((int) $feature->id > 0) {
                try {
                    $kept = !$feature->delete();
                } catch (Throwable $e) {
                    $kept = true;
                }
            }
            SomcpLogger::log(
                'feature_create_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr($failure, 0, 500) . ($kept ? ', feature ' . (int) $feature->id . ' could not be removed' : ''),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The feature could not be created: ' . $failure . '. '
                    . ($kept
                        ? 'It could not be removed either: feature ' . (int) $feature->id . ' exists in an '
                            . 'incomplete state. Tell the merchant to check it in the back office; do not call again.'
                        : 'Nothing was kept.'),
                true
            );
        }

        $idFeature = (int) $feature->id;
        $result = [
            'created' => true,
            'id_feature' => $idFeature,
        ];

        // The form saves these rows on every save once the module is there,
        // empty fields included, so a feature created here reads the same as
        // one created on screen.
        if ($plan['faceted'] && !self::storeFaceted($idFeature, $plan)) {
            // The feature is kept: it is usable without them, and removing it
            // would lose what did go through. The merchant is told instead.
            $result['faceted_search'] = 'not saved: fill url_name, meta_title and indexable in the back office';
            SomcpLogger::log('feature_faceted_failed', SomcpLogger::OUTCOME_FAILURE, 'feature ' . $idFeature, '', $idEmployee);
        }

        SomcpLogger::log('feature_created', SomcpLogger::OUTCOME_SUCCESS, 'feature ' . $idFeature, '', $idEmployee);

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $result['feature'] = SomcpToolGetFeature::feature($idFeature, (int) $plan['languages']['fallback']);

        return SomcpTool::json($result);
    }

    /**
     * The faceted search rows of a new feature, written as the module's form writes them.
     *
     * A language left out takes the default one's text, as for the name.
     *
     * @param int $idFeature
     * @param array<string, mixed> $plan
     *
     * @return bool
     */
    protected static function storeFaceted(int $idFeature, array $plan): bool
    {
        return self::writeFaceted(
            $idFeature,
            (bool) $plan['indexable'],
            self::complete($plan['texts']['url_name'] ?? [], $plan['languages']),
            self::complete($plan['texts']['meta_title'] ?? [], $plan['languages']),
            $plan['languages']['all']
        );
    }

    /**
     * Replaces the faceted search rows of one feature, then drops the module's filter cache.
     *
     * The module's own save method is private and reads the form; its
     * actionFeatureSave hook reads the request. Neither can be handed values,
     * so its rows are written here, through the same columns: one row for
     * Indexable, one per language of the shop for the URL word and title tag.
     * Shared by the creation and the change of a feature, so both store them
     * alike.
     *
     * @param int $idFeature
     * @param bool $indexable
     * @param array<int, string> $urls by language identifier
     * @param array<int, string> $titles by language identifier
     * @param array<int, int> $idLangs every language of the shop
     *
     * @return bool
     */
    protected static function writeFaceted(int $idFeature, bool $indexable, array $urls, array $titles, array $idLangs): bool
    {
        $db = Db::getInstance();

        try {
            // The module replaces the rows rather than updating them, and a
            // row left by a feature that once had this identifier would
            // otherwise make the insert fail.
            $done = $db->delete('layered_indexable_feature', '`id_feature` = ' . (int) $idFeature)
                && $db->delete('layered_indexable_feature_lang_value', '`id_feature` = ' . (int) $idFeature)
                && $db->insert('layered_indexable_feature', [
                    'id_feature' => (int) $idFeature,
                    'indexable' => (int) $indexable,
                ]);
            foreach ($idLangs as $idLang) {
                $done = $done && $db->insert('layered_indexable_feature_lang_value', [
                    'id_feature' => (int) $idFeature,
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

    /**
     * Drops the filter blocks the faceted search module built.
     *
     * It caches them with the feature names and URL words in them, and drops
     * them after each save of the feature form so the storefront picks up the
     * new ones.
     *
     * @return void
     */
    protected static function dropFacetedCache(): void
    {
        $module = Module::getInstanceByName('ps_facetedsearch');
        if ($module && method_exists($module, 'invalidateLayeredFilterBlockCache')) {
            try {
                $module->invalidateLayeredFilterBlockCache();
            } catch (Throwable $e) {
                // A stale cache block is rebuilt on its own; not worth failing for.
            }
        }
    }
}
