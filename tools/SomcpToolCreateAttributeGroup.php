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
 * somcp_create_attribute_group - one attribute group, with every field of the
 * back office attribute form, faceted search fields included.
 *
 * It extends the feature creation tool, which already checks the faceted
 * search fields the same form carries the same way, on top of what every
 * catalogue write shares: the languages being filled, texts keyed by ISO
 * code, a language left out taking the default one's text, and the employee
 * of the token carried on the write.
 *
 * Everything is checked before anything is written, and every problem is
 * reported at once. The group is written through AttributeGroup::add(), in
 * the shop of the token, and deleted again if that fails after the row went in.
 *
 * The URL word, the title tag and Indexable are not PrestaShop's: the faceted
 * search module adds them to the form and its actionAttributeGroupSave hook
 * reads them from the form's own request only, so a group added through
 * AttributeGroup::add() would never get them. The tool writes the rows that
 * module writes, the way it writes them, once the group exists.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends, and it answers
// with the group as somcp_get_attribute_group returns it.
require_once __DIR__ . '/SomcpToolCreateFeature.php';
require_once __DIR__ . '/SomcpToolGetAttributeGroup.php';

class SomcpToolCreateAttributeGroup extends SomcpToolCreateFeature
{
    /** @var array<int, string> Every argument this tool takes. */
    protected const GROUP_ARGUMENTS = ['languages', 'name', 'public_name', 'group_type', 'url_name', 'meta_title', 'indexable'];

    /**
     * @var array<int, string> What somcp_get_attribute_group returns and this tool refuses, because the shop
     *                         works it out. Named, so the assistant learns why rather than meeting an "unknown field".
     */
    protected const GROUP_COMPUTED = ['id_attribute_group', 'position', 'values_count'];

    /** @var array<int, string> The types the back office form offers, in its order. */
    protected const GROUP_TYPES = ['select', 'radio', 'color'];

    /** @var array<string, int> The size of each text column (AttributeGroup::$definition). */
    protected const GROUP_TEXT_MAX = ['name' => 128, 'public_name' => 64];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_attribute_group',
            Somcp::t('Create an attribute group'),
            'Creates one attribute group (Size, Colour...) in this shop with every field of the back office '
                . 'attribute form: name (the one the back office lists), public_name (the one customers see on '
                . 'the product page), group_type (how customers pick a value: select for a drop-down list, radio '
                . 'for radio buttons, color for colour swatches), and the faceted search fields of that form - '
                . 'url_name (the word used in filtered URLs), meta_title and indexable - when the faceted search '
                . 'module is installed and enabled. name, public_name and group_type are required. The group is '
                . 'created without values and placed last: add the values afterwards, one per call, with '
                . 'somcp_create_attribute_value when the shop offers it. '
                . 'Read somcp_list_attributes first: the group may already exist, and PrestaShop accepts two '
                . 'groups of the same name. '
                . 'Who fills each field is said at the start of its description, and is a rule for you: '
                . '"Merchant" - ask the merchant and never invent the value; you may suggest one for them to '
                . 'confirm. "Assistant" - write it yourself in every language being filled, and show it to the '
                . 'merchant before the call. '
                . 'Texts are objects keyed by ISO code and must be given for every language in languages; a '
                . 'language of the shop left out takes the default language\'s text. '
                . 'Every problem is reported at once and nothing is written until the whole call is valid. '
                . 'Do not use it to add a value to a group, which is somcp_create_attribute_value, or to change a '
                . 'group, which is somcp_update_attribute_group. One group per call: for several, call it once '
                . 'per group and report each result. '
                . 'Not to be retried: if the answer is lost, read somcp_list_attributes and look for the name '
                . 'before anything else, because a second call creates a second group.',
            self::groupSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Adding a group is what the attributes page asks for in Team,
            // Permissions; editing one is a different right.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminAttributesGroups')
        );
    }

    /**
     * The arguments, under the names somcp_get_attribute_group returns.
     *
     * @return array<string, mixed>
     */
    protected static function groupSchema(): array
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
                'name' => $text('Merchant. Required. The name the back office lists the group under, 128 '
                    . 'characters at most, without < > = { }.'),
                'public_name' => $text('Merchant. Required. The name customers see on the product page, 64 '
                    . 'characters at most, without < > = { }. Often the same as name.'),
                'group_type' => [
                    'type' => 'string',
                    'enum' => self::GROUP_TYPES,
                    'description' => 'Merchant. Required. How customers pick a value on the product page: select '
                        . '(a drop-down list), radio (radio buttons) or color (colour swatches, each value then '
                        . 'carrying a colour or a texture).',
                ],
                'url_name' => $text('Assistant. Faceted search: the word used in filtered URLs instead of the '
                    . 'name, lower case letters, digits and hyphens, for example "size". Empty for the name. '
                    . '128 characters at most.'),
                'meta_title' => $text('Assistant. Faceted search: the title tag of filtered pages instead of the '
                    . 'name. 128 characters at most, without < > = { }.'),
                'indexable' => [
                    'type' => 'boolean',
                    'description' => 'Merchant. Faceted search: whether filtered pages on this group are open to '
                        . 'search engines. false by default, as in the form.',
                ],
            ],
            'required' => ['name', 'public_name', 'group_type'],
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
        $plan = self::planGroup($arguments, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was created. Correct these and call again:\n- " . implode("\n- ", $errors),
                true
            );
        }

        return self::asEmployee($idEmployee, static function () use ($plan, $idShop, $idEmployee): array {
            return self::addGroup($plan, $idShop, $idEmployee);
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
    private static function planGroup(array $arguments, int $idShop, array &$errors): ?array
    {
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::GROUP_ARGUMENTS, true)) {
                $errors[] = $key . (in_array($key, self::GROUP_COMPUTED, true)
                    ? ': worked out by the shop, so it is not written here'
                    : ': not a field of this tool');
            }
        }

        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }

        $probe = new AttributeGroup();
        $plan = [
            'languages' => $languages,
            'texts' => [],
            'group_type' => '',
            'faceted' => self::facetedSearchActive(),
            'indexable' => false,
        ];

        foreach (array_keys(self::GROUP_TEXT_MAX) as $field) {
            self::groupTexts($arguments, $field, $languages, $probe, $plan, $errors, true);
        }
        self::groupType($arguments, $plan, $errors, true);
        self::facetedFields($arguments, $languages, $plan, $errors);

        // The same check the core runs before add(), on the whole object, as a
        // last net for a rule of the running version none of the above knew.
        if ($errors === []) {
            $candidate = new AttributeGroup();
            self::fill($candidate, ['group_type' => $plan['group_type']] + self::completeTexts([
                'name' => $plan['texts']['name'],
                'public_name' => $plan['texts']['public_name'],
            ], $languages));
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'attribute group: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * One of the two names of a group, checked with the running version's rules.
     *
     * @param array<string, mixed> $arguments
     * @param string $field name or public_name
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param AttributeGroup $probe
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     * @param bool $required false when changing a group, which already has its names
     *
     * @return void
     */
    protected static function groupTexts(array $arguments, string $field, array $languages, AttributeGroup $probe, array &$plan, array &$errors, bool $required): void
    {
        // Given, a name is never empty, whether the group is new or not: the
        // core requires both in every language.
        $texts = self::texts($arguments, $field, $field, $languages, $errors, $required || array_key_exists($field, $arguments));
        $max = self::GROUP_TEXT_MAX[$field];
        foreach ($texts ?? [] as $idLang => $text) {
            $text = trim($text);
            $texts[$idLang] = $text;
            $where = $field . ' (' . $languages['filled'][$idLang] . ')';
            if (Tools::strlen($text) > $max) {
                $errors[] = $where . ': ' . $max . ' characters at most, ' . Tools::strlen($text) . ' given';
            } elseif ($text !== '' && ($check = $probe->validateField($field, $text, $idLang)) !== true) {
                // Both names are checked with isGenericName, not the catalogue
                // rule of products and categories.
                $errors[] = $where . ': a character PrestaShop refuses in an attribute group name (< > = { }) - ' . self::reason($check);
            }
        }
        if ($texts !== null) {
            $plan['texts'][$field] = $texts;
        }
    }

    /**
     * The type of the group, among the ones the back office form offers.
     *
     * The column takes any text, but the product page only knows how to draw
     * these three: anything else would leave customers without a way to pick.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     * @param bool $required
     *
     * @return void
     */
    protected static function groupType(array $arguments, array &$plan, array &$errors, bool $required): void
    {
        if (!array_key_exists('group_type', $arguments)) {
            if ($required) {
                $errors[] = 'group_type: required, one of ' . implode(', ', self::GROUP_TYPES);
            }

            return;
        }
        $type = is_string($arguments['group_type']) ? Tools::strtolower(trim($arguments['group_type'])) : '';
        if (!in_array($type, self::GROUP_TYPES, true)) {
            $errors[] = 'group_type: one of ' . implode(', ', self::GROUP_TYPES);

            return;
        }
        $plan['group_type'] = $type;
    }

    /**
     * Writes the group, then its faceted search fields, and answers with what was stored.
     *
     * @param array<string, mixed> $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function addGroup(array $plan, int $idShop, int $idEmployee): array
    {
        $group = new AttributeGroup();
        self::fill($group, ['group_type' => $plan['group_type']] + self::completeTexts([
            'name' => $plan['texts']['name'],
            'public_name' => $plan['texts']['public_name'],
        ], $plan['languages']));
        // The shop of the token, never the context's list, so on a multistore
        // installation the group goes to that shop only, as the core's
        // AddAttributeGroupHandler does with the shops it is given. Position
        // and the colour flag are left to add(), which works them out.
        $group->id_shop_list = [$idShop];

        try {
            $done = $group->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        if (!$done) {
            $kept = false;
            if ((int) $group->id > 0) {
                try {
                    $kept = !$group->delete();
                } catch (Throwable $e) {
                    $kept = true;
                }
            }
            SomcpLogger::log(
                'attribute_group_create_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr($failure, 0, 500) . ($kept ? ', attribute group ' . (int) $group->id . ' could not be removed' : ''),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The attribute group could not be created: ' . $failure . '. '
                    . ($kept
                        ? 'It could not be removed either: attribute group ' . (int) $group->id . ' exists in an '
                            . 'incomplete state. Tell the merchant to check it in the back office; do not call again.'
                        : 'Nothing was kept.'),
                true
            );
        }

        $idGroup = (int) $group->id;
        $result = [
            'created' => true,
            'id_attribute_group' => $idGroup,
        ];

        // The form saves the Indexable row on every save once the module is
        // there, so a group created here reads the same as one created on screen.
        if ($plan['faceted'] && !self::storeGroupFaceted($idGroup, $plan)) {
            // The group is kept: it is usable without them, and removing it
            // would lose what did go through. The merchant is told instead.
            $result['faceted_search'] = 'not saved: fill url_name, meta_title and indexable in the back office';
            SomcpLogger::log('attribute_group_faceted_failed', SomcpLogger::OUTCOME_FAILURE, 'attribute group ' . $idGroup, '', $idEmployee);
        }

        SomcpLogger::log('attribute_group_created', SomcpLogger::OUTCOME_SUCCESS, 'attribute group ' . $idGroup, '', $idEmployee);

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $result['attribute_group'] = SomcpToolGetAttributeGroup::group($idGroup, (int) $plan['languages']['fallback'], $idShop);

        return SomcpTool::json($result);
    }

    /**
     * The faceted search rows of a new group, written as the module's form writes them.
     *
     * A language left out takes the default one's text, as for the names.
     *
     * @param int $idGroup
     * @param array<string, mixed> $plan
     *
     * @return bool
     */
    protected static function storeGroupFaceted(int $idGroup, array $plan): bool
    {
        return self::writeGroupFaceted(
            $idGroup,
            (bool) $plan['indexable'],
            self::complete($plan['texts']['url_name'] ?? [], $plan['languages']),
            self::complete($plan['texts']['meta_title'] ?? [], $plan['languages']),
            $plan['languages']['all']
        );
    }

    /**
     * Replaces the faceted search rows of one group, then drops the module's filter cache.
     *
     * The module's own save method is private and its actionAttributeGroupSave
     * hook reads the request, so its rows are written here, through the same
     * columns: one row for Indexable, and one per language whose URL word or
     * title tag is filled - the module writes no row for a language where both
     * are empty, and reads a missing row as empty fields. Shared by the
     * creation and the change of a group, so both store them alike.
     *
     * @param int $idGroup
     * @param bool $indexable
     * @param array<int, string> $urls by language identifier
     * @param array<int, string> $titles by language identifier
     * @param array<int, int> $idLangs every language of the shop
     *
     * @return bool
     */
    protected static function writeGroupFaceted(int $idGroup, bool $indexable, array $urls, array $titles, array $idLangs): bool
    {
        $db = Db::getInstance();

        try {
            // The module replaces the rows rather than updating them; its own
            // hook may also have written some during add(), and a row left by
            // a group that once had this identifier would make the insert fail.
            $done = $db->delete('layered_indexable_attribute_group', '`id_attribute_group` = ' . (int) $idGroup)
                && $db->delete('layered_indexable_attribute_group_lang_value', '`id_attribute_group` = ' . (int) $idGroup)
                && $db->insert('layered_indexable_attribute_group', [
                    'id_attribute_group' => (int) $idGroup,
                    'indexable' => (int) $indexable,
                ]);
            foreach ($idLangs as $idLang) {
                $url = (string) ($urls[$idLang] ?? '');
                $title = (string) ($titles[$idLang] ?? '');
                if ($url === '' && $title === '') {
                    continue;
                }
                $done = $done && $db->insert('layered_indexable_attribute_group_lang_value', [
                    'id_attribute_group' => (int) $idGroup,
                    'id_lang' => (int) $idLang,
                    'url_name' => pSQL($url),
                    'meta_title' => pSQL($title),
                ]);
            }
        } catch (Throwable $e) {
            $done = false;
        }

        self::dropFacetedCache();

        return $done;
    }
}
