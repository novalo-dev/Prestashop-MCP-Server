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
 * somcp_update_attribute_group - one existing attribute group, changed with
 * everything somcp_create_attribute_group can write.
 *
 * It extends the creation tool rather than copying it: a field accepted at
 * creation is accepted here under the same name and checked by the same code,
 * so the two tools cannot come to disagree on what a valid name, type or URL
 * word is, and the faceted search rows are written by the same method.
 *
 * Only what is given changes. Each value is compared with what the shop holds
 * before anything is written, which gives the answer its old and new values
 * and lets a call that would change nothing write nothing at all: no hook
 * fires, and the answer says changed: false.
 *
 * The names and the type go through AttributeGroup::update() in the shop of
 * the token, as the core's EditAttributeGroupHandler does, so the object hooks
 * fire and update() keeps the colour flag in step with the type. The faceted
 * search fields come after, written the way the module's form writes them,
 * since the module reads them from the form's request only.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolCreateAttributeGroup.php';

class SomcpToolUpdateAttributeGroup extends SomcpToolCreateAttributeGroup
{
    /** @var array<int, string> What this tool takes besides the fields of the creation. */
    private const OWN_ARGUMENTS = ['id_attribute_group'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_attribute_group',
            Somcp::t('Change an attribute group'),
            'Changes one existing attribute group (Size, Colour...) of this shop. Everything '
                . 'somcp_create_attribute_group can write can be changed here, under the same names and with the '
                . 'same rules - who fills each field, lengths, forbidden characters: name (the one the back office '
                . 'lists), public_name (the one customers see on the product page), group_type (select, radio or '
                . 'color), and the faceted search fields url_name, meta_title and indexable when the faceted '
                . 'search module is installed and enabled. Only what is given changes: a value left out is kept, a '
                . 'text is replaced in the languages filled and kept in the others. When languages is left out '
                . 'every language of the shop is filled, so a text must then be given in each; to change one '
                . 'language, pass languages with it alone. '
                . 'Take the group from somcp_list_attributes, never guessed, and read it with '
                . 'somcp_get_attribute_group to know what it holds. A new public name or type shows on every '
                . 'product page offering the group: tell the merchant so, show what will change and call once '
                . 'they agree. Changing group_type away from color keeps the colours and textures of the values '
                . 'but no longer shows them. Every problem is reported at once and nothing is written until the '
                . 'whole call is valid. The answer gives each field changed with its old and new value. A call '
                . 'that would change nothing writes nothing and says changed: false. '
                . 'Do not use it to create a group (somcp_create_attribute_group) or to change one of its values '
                . '(somcp_update_attribute_value when the shop offers it). One group per call. '
                . 'Not to be retried blindly: if the answer is lost, read the group again with '
                . 'somcp_get_attribute_group and compare before calling again.',
            self::groupSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Changing a group is the edit right of the attributes page in
            // Team, Permissions; creating one is a different right.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminAttributesGroups')
        );
    }

    /**
     * The creation schema, made to name an existing group and to keep what is left out.
     *
     * Derived rather than written again, so a field added to the creation is
     * offered here too, with the same description.
     *
     * @return array<string, mixed>
     */
    protected static function groupSchema(): array
    {
        $schema = parent::groupSchema();
        $properties = $schema['properties'];
        $kept = ' Left out: unchanged.';
        $perLanguage = ' One text per language being filled, keyed by ISO code: {"fr": "...", "en": "..."}.';

        $properties['name']['description'] = 'Merchant. The name the back office lists the group under, 128 '
            . 'characters at most, never empty, without < > = { }.' . $perLanguage . $kept;
        $properties['public_name']['description'] = 'Merchant. The name customers see on the product page, 64 '
            . 'characters at most, never empty, without < > = { }.' . $perLanguage . $kept;
        $properties['group_type']['description'] = 'Merchant. How customers pick a value on the product page: '
            . 'select (a drop-down list), radio (radio buttons) or color (colour swatches). Away from color, '
            . 'the colours and textures of the values are kept but no longer shown.' . $kept;
        foreach (['url_name', 'meta_title'] as $field) {
            $properties[$field]['description'] .= $kept;
        }
        $properties['indexable']['description'] = 'Merchant. Faceted search: whether filtered pages on this '
            . 'group are open to search engines.' . $kept;

        $schema['properties'] = [
            'id_attribute_group' => [
                'type' => 'integer',
                'description' => 'The attribute group to change, from somcp_list_attributes.',
            ],
        ] + $properties;
        $schema['required'] = ['id_attribute_group'];

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

        // The same test of the shop as somcp_get_attribute_group, so a group
        // this tool refuses is never one that tool describes.
        $group = SomcpToolGetAttributeGroup::existingGroup(self::integer($arguments['id_attribute_group'] ?? null) ?? 0, $idShop);
        if ($group === null) {
            return SomcpTool::text(
                'No attribute group of this shop has this identifier. Nothing was changed. Find it with somcp_list_attributes.',
                true
            );
        }

        $errors = [];
        $plan = self::planUpdate($arguments, $group, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
        }

        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_attribute_group' => (int) $group->id,
                'reason' => 'The attribute group already holds every value given. Nothing was written.',
            ]);
        }

        return self::asEmployee($idEmployee, static function () use ($group, $plan, $idShop, $idEmployee): array {
            return self::applyUpdate($group, $plan, $idShop, $idEmployee);
        });
    }

    /**
     * Turns the arguments into what will be written, or says everything that is wrong with them.
     *
     * Nothing is written here. A value equal to what the shop holds is left
     * out of the plan, so what remains is exactly what will change.
     *
     * @param array<string, mixed> $arguments
     * @param AttributeGroup $group
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function planUpdate(array $arguments, AttributeGroup $group, int $idShop, array &$errors): ?array
    {
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::GROUP_ARGUMENTS, true) && !in_array($key, self::OWN_ARGUMENTS, true)) {
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
            // Empty until given: the group keeps its type otherwise.
            'group_type' => '',
            'faceted' => self::facetedSearchActive(),
            // null until given: unlike at creation, a missing indexable keeps
            // what the group has rather than turning it off.
            'indexable' => null,
            'changes' => [],
            'object' => [],
            'faceted_rows' => null,
            'colours_unused' => false,
        ];

        foreach (array_keys(self::GROUP_TEXT_MAX) as $field) {
            self::groupTexts($arguments, $field, $languages, $probe, $plan, $errors, false);
        }
        self::groupType($arguments, $plan, $errors, false);
        self::facetedFields($arguments, $languages, $plan, $errors);

        if ($errors !== []) {
            return $plan;
        }

        self::compareGroup($group, $plan);

        // The same check the core runs before update(), on the whole object as
        // it will be written, as a last net for a rule none of the above knew.
        if ($plan['object'] !== []) {
            $candidate = clone $group;
            self::fill($candidate, $plan['object']);
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'attribute group: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * Keeps the names, type and faceted search fields that differ from the shop, and records each one.
     *
     * @param AttributeGroup $group loaded in every language
     * @param array<string, mixed> $plan
     *
     * @return void
     */
    private static function compareGroup(AttributeGroup $group, array &$plan): void
    {
        $languages = $plan['languages'];

        foreach (array_keys(self::GROUP_TEXT_MAX) as $field) {
            if (!isset($plan['texts'][$field])) {
                continue;
            }
            // Loaded without a language, a name is one text per language; read
            // as one anyway, should a version hand back a single string. Read
            // by name, since the core types it as either depending on the version.
            $stored = $group->{$field};
            $current = is_array($stored) ? $stored : [(int) $languages['fallback'] => (string) $stored];
            $merged = $current;
            foreach ($plan['texts'][$field] as $idLang => $text) {
                $old = (string) ($current[$idLang] ?? '');
                if ($old === $text) {
                    continue;
                }
                $merged[$idLang] = $text;
                self::record($plan, $field . ' (' . $languages['filled'][$idLang] . ')', $old, $text);
            }
            if ($merged !== $current) {
                $plan['object'][$field] = $merged;
            }
        }

        $oldType = (string) $group->group_type;
        if ($plan['group_type'] !== '' && $plan['group_type'] !== $oldType) {
            $plan['object']['group_type'] = $plan['group_type'];
            self::record($plan, 'group_type', $oldType, $plan['group_type']);
            // The values keep their colour and texture, as in the back office;
            // the product page simply stops drawing them as swatches.
            $plan['colours_unused'] = $oldType === 'color';
        }

        $given = isset($plan['texts']['url_name']) || isset($plan['texts']['meta_title']) || $plan['indexable'] !== null;
        if (!$plan['faceted'] || !$given) {
            return;
        }

        $stored = self::storedGroupFaceted((int) $group->id, $languages['all']);
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
     * The faceted search fields the group holds now, in every language of the shop.
     *
     * A group whose form was never saved with the module has no row: the form
     * shows empty fields and an unticked box, and so does this.
     *
     * @param int $idGroup
     * @param array<int, int> $idLangs
     *
     * @return array{indexable: bool, url_name: array<int, string>, meta_title: array<int, string>}
     */
    private static function storedGroupFaceted(int $idGroup, array $idLangs): array
    {
        $db = Db::getInstance();
        $stored = [
            'indexable' => (bool) $db->getValue(
                'SELECT `indexable` FROM `' . _DB_PREFIX_ . 'layered_indexable_attribute_group`'
                . ' WHERE `id_attribute_group` = ' . (int) $idGroup
            ),
            'url_name' => [],
            'meta_title' => [],
        ];
        foreach ($idLangs as $idLang) {
            $stored['url_name'][(int) $idLang] = '';
            $stored['meta_title'][(int) $idLang] = '';
        }
        $rows = $db->executeS(
            'SELECT `id_lang`, `url_name`, `meta_title` FROM `' . _DB_PREFIX_ . 'layered_indexable_attribute_group_lang_value`'
            . ' WHERE `id_attribute_group` = ' . (int) $idGroup
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
     * @param AttributeGroup $group
     * @param array<string, mixed> $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function applyUpdate(AttributeGroup $group, array $plan, int $idShop, int $idEmployee): array
    {
        $idGroup = (int) $group->id;
        if ($plan['object'] !== []) {
            self::fill($group, $plan['object']);
            // The shop of the token only: left to the context's list of shops,
            // update() can attach the group to a shop it was never in.
            $group->id_shop_list = [$idShop];

            try {
                $done = $group->update();
                $failure = $done ? '' : 'PrestaShop refused to save it';
            } catch (Throwable $e) {
                $done = false;
                $failure = $e->getMessage();
            }
            if (!$done) {
                SomcpLogger::log(
                    'attribute_group_update_failed',
                    SomcpLogger::OUTCOME_FAILURE,
                    'attribute group ' . $idGroup . ': ' . Tools::substr($failure, 0, 500),
                    '',
                    $idEmployee
                );

                return SomcpTool::text(
                    'The attribute group could not be changed: ' . $failure . '. The names may be changed in some '
                        . 'languages only, and the faceted search fields were not touched. Read the group again '
                        . 'with somcp_get_attribute_group, tell the merchant what it holds now, and do not call '
                        . 'again before.',
                    true
                );
            }
        }

        $result = [
            'changed' => true,
            'id_attribute_group' => $idGroup,
            'changes' => $plan['changes'],
        ];
        if ($plan['colours_unused']) {
            $result['note'] = 'The group is no longer of type color: its values keep their colours and textures, '
                . 'but the product page no longer shows them. They come back if the type is set to color again.';
        }

        if ($plan['faceted_rows'] !== null) {
            $rows = $plan['faceted_rows'];
            if (!self::writeGroupFaceted($idGroup, $rows['indexable'], $rows['url_name'], $rows['meta_title'], array_keys($rows['url_name']))) {
                SomcpLogger::log('attribute_group_faceted_failed', SomcpLogger::OUTCOME_FAILURE, 'attribute group ' . $idGroup, '', $idEmployee);
                if ($plan['object'] === []) {
                    return SomcpTool::text(
                        'The faceted search fields could not be saved. Read the group again with '
                            . 'somcp_get_attribute_group, tell the merchant what it holds now, and do not call again before.',
                        true
                    );
                }
                // The names and type are kept: they went through on their
                // own, and the merchant is told what did not.
                $result['faceted_search'] = 'not saved: fill url_name, meta_title and indexable in the back office';
            }
        } elseif ($plan['faceted']) {
            // The module's filter blocks carry the group's name, and its own
            // form drops them on every save, a rename included.
            self::dropFacetedCache();
        }

        SomcpLogger::log(
            'attribute_group_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'attribute group ' . $idGroup . ', ' . count($plan['changes']) . ' change(s)',
            '',
            $idEmployee
        );

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $result['attribute_group'] = SomcpToolGetAttributeGroup::group($idGroup, (int) $plan['languages']['fallback'], $idShop);

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
