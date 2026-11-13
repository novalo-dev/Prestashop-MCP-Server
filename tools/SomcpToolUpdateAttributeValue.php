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
 * somcp_update_attribute_value - one existing attribute value, changed with
 * everything somcp_create_attribute_value can write.
 *
 * It extends the creation tool rather than copying it: a field accepted at
 * creation is accepted here under the same name and checked by the same code,
 * so the two tools cannot come to disagree on what a valid name, colour, URL
 * word or texture is, or on when two values of one group clash, and
 * the texture and the faceted search rows are written by the same methods.
 *
 * Only what is given changes. Each value is compared with what the shop holds
 * before anything is written, which gives the answer its old and new values
 * and lets a call that would change nothing write nothing at all: no hook
 * fires, and the answer says changed: false.
 *
 * The names, the colour and the group go through ProductAttribute::update()
 * in the shop of the token, as the core's EditAttributeHandler does, so
 * actionAttributeSave fires. The faceted search module answers that hook by
 * deleting the value's rows and writing back what its form's request carries
 * - nothing, here - so the rows are written again afterwards. A new position
 * comes last, through ProductAttribute::updatePosition(), where the drag and
 * drop of the values list ends, so the other values shift as they do there.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolCreateAttributeValue.php';

class SomcpToolUpdateAttributeValue extends SomcpToolCreateAttributeValue
{
    /** @var array<int, string> What this tool takes besides the fields of the creation. */
    private const OWN_ARGUMENTS = ['id_attribute', 'remove_texture', 'position'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_attribute_value',
            Somcp::t('Change an attribute value'),
            'Changes one attribute value, as the value form of Catalog > Attributes & Features > Attributes, under '
                . 'the names and rules of somcp_create_attribute_value. id_attribute comes from somcp_list_attributes: '
                . 'never guess one; read it first with somcp_get_attribute_value. Only what is given changes. A new name '
                . 'or colour shows on every combination using it: tell the merchant, show what will change and call '
                . 'once they agree. Moving it to another group is refused while a combination uses it. One value per '
                . 'call. Not to be retried: if the answer is lost, read somcp_get_attribute_value before anything else.',
            self::valueSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Values are managed under their group, and changing one is the
            // edit right of the attributes page in Team, Permissions.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminAttributesGroups'),
            files: ['texture' => SomcpUpload::images()]
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
    protected static function valueSchema(): array
    {
        $schema = parent::valueSchema();
        $properties = $schema['properties'];
        $kept = ' Left out: unchanged.';

        $properties['id_attribute_group']['description'] = 'Merchant. Moves the value to this attribute group, '
            . 'from somcp_list_attributes; it is placed last there, or at position when given. Refused while a '
            . 'combination uses the value.' . $kept;
        $properties['name']['description'] = 'Merchant. The value on the product page, changed on every '
            . 'combination using it.'
            . ' One text per language being filled, keyed by ISO code: {"fr": "...", "en": "..."}.' . $kept;
        foreach (['url_name', 'meta_title', 'color'] as $field) {
            $properties[$field]['description'] .= $kept;
        }
        // Rewritten rather than added to: the shared image text leaves no
        // room under the description budget for more than this.
        $properties['texture']['description'] = str_replace(
            'Merchant. Color groups only: the swatch image.',
            'Merchant. Color groups only: replaces the swatch image.',
            (string) $properties['texture']['description']
        );

        $schema['properties'] = [
            'id_attribute' => [
                'type' => 'integer',
                'description' => 'The attribute value to change, from somcp_list_attributes.',
            ],
        ] + $properties + [
            'remove_texture' => [
                'type' => 'boolean',
                'description' => 'Merchant. Groups of type color only: true removes the texture, so the colour '
                    . 'shows again. Not with texture in the same call.',
            ],
            'position' => [
                'type' => 'integer',
                'description' => 'Merchant. The place in its group, or in id_attribute_group, from 0 as '
                    . 'somcp_get_attribute_value gives it; the others shift.' . $kept,
            ],
        ];
        $schema['required'] = ['id_attribute'];

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

        // The same test of the shop as somcp_get_attribute_value, so a value
        // this tool refuses is never one that tool describes.
        $idAttribute = self::integer($arguments['id_attribute'] ?? null) ?? 0;
        $attribute = SomcpToolGetAttributeValue::existingValue($idAttribute, $idShop);
        if ($attribute === null) {
            return SomcpTool::refused('changed', [$idAttribute > 0
                ? SomcpTool::notFound('id_attribute', 'attribute value', $idAttribute, 'somcp_list_attributes')
                : SomcpTool::notAnIdentifier('id_attribute')]);
        }

        $errors = [];
        $plan = self::planUpdate($arguments, $attribute, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::refused('changed', $errors);
        }

        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_attribute' => (int) $attribute->id,
                'reason' => 'The attribute value already holds everything given. Nothing was written.',
            ]);
        }

        return self::asEmployee($idEmployee, static function () use ($attribute, $plan, $idShop, $idEmployee): array {
            return self::applyUpdate($attribute, $plan, $idShop, $idEmployee);
        });
    }

    /**
     * Turns the arguments into what will be written, or says everything that is wrong with them.
     *
     * Nothing is written here. A field equal to what the shop holds is left
     * out of the plan, so what remains is exactly what will change.
     *
     * @param array<string, mixed> $arguments
     * @param ProductAttribute $attribute loaded in every language
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function planUpdate(array $arguments, ProductAttribute $attribute, int $idShop, array &$errors): ?array
    {
        array_push($errors, ...SomcpTool::unknownFields($arguments, array_merge(self::VALUE_ARGUMENTS, self::OWN_ARGUMENTS), self::VALUE_COMPUTED));

        // The group the value ends up in: the names are compared with its
        // values, and its type decides whether a colour or texture is shown.
        $idCurrent = (int) $attribute->id_attribute_group;
        $idGroup = $idCurrent;
        $group = new AttributeGroup($idCurrent);
        if (array_key_exists('id_attribute_group', $arguments)) {
            // A group of another shop is answered like one that does not
            // exist, the test somcp_get_attribute_group makes.
            $idGiven = self::integer($arguments['id_attribute_group']) ?? 0;
            $group = SomcpToolGetAttributeGroup::existingGroup($idGiven, $idShop);
            if ($group === null) {
                $errors[] = $idGiven > 0
                    ? SomcpTool::notFound('id_attribute_group', 'attribute group', $idGiven, 'somcp_list_attributes')
                    : SomcpTool::notAnIdentifier('id_attribute_group');
            }
            $idGroup = $group === null ? 0 : (int) $group->id;
        }
        $colorGroup = $group === null ? null : (string) $group->group_type === 'color';

        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }

        $plan = [
            'languages' => $languages,
            'texts' => [],
            // Empty until given: the value keeps its colour and texture otherwise.
            'color' => '',
            'texture' => null,
            'remove_texture' => false,
            'faceted' => self::facetedSearchActive(),
            'changes' => [],
            'names' => null,
            'new_color' => null,
            'id_attribute_group' => null,
            'faceted_rows' => null,
            'colours_unused' => false,
            // null until given: the value keeps its place otherwise.
            'position' => null,
        ];

        self::valueNames($arguments, $languages, $idGroup, $idShop, $plan, $errors, false, (int) $attribute->id);
        // The texture as somcp_get_attribute_value returned it - the address
        // of the stored file, or null when there is none - is not a new one:
        // it is left out, so nothing is fetched again or listed in changes.
        // Any other address, or an upload_ref, is a real texture.
        $given = $arguments;
        if (array_key_exists('texture', $given)) {
            if (SomcpUpload::same($given['texture'], SomcpToolGetAttributeValue::textureUrl((int) $attribute->id))) {
                unset($given['texture']);
            } elseif ($given['texture'] === null && $colorGroup === true) {
                // Read as "no texture", null would leave the stored one in
                // place without a word: removing it is remove_texture's job.
                $errors[] = 'texture: null is not a texture, and this value has one; remove_texture: true removes it';
                unset($given['texture']);
            }
        }
        self::colorFields($given, $colorGroup, $plan, $errors);
        self::removeTexture($arguments, $colorGroup, $plan, $errors);
        // A value has no Indexable of its own: that switch belongs to the
        // group. It is already reported as not a field of this tool, so it is
        // kept away from the shared check, which would report it twice.
        self::facetedFields(array_diff_key($arguments, ['indexable' => true]), $languages, $plan, $errors);
        if (array_key_exists('position', $arguments) && $idGroup > 0) {
            self::planPosition($arguments['position'], $idGroup, $idGroup !== $idCurrent, $plan, $errors);
        }

        if ($errors !== []) {
            return $plan;
        }

        self::compareValue($attribute, $idGroup, $group === null ? '' : (string) $group->group_type, $idShop, $plan, $errors);

        if ($plan['position'] !== null) {
            // A value moved to another group always changes list, so its
            // place there is recorded even when the number is the same.
            $oldPosition = (int) $attribute->position;
            if ($idGroup === $idCurrent && $oldPosition === $plan['position']) {
                $plan['position'] = null;
            } else {
                self::record($plan, 'position', $oldPosition, $plan['position']);
            }
        }

        // The same check the core runs before update(), on the whole object as
        // it will be written, as a last net for a rule none of the above knew.
        if ($errors === [] && ($plan['names'] !== null || $plan['new_color'] !== null || $plan['id_attribute_group'] !== null)) {
            $candidate = clone $attribute;
            self::fill($candidate, array_filter([
                'name' => $plan['names'],
                'color' => $plan['new_color'],
                'id_attribute_group' => $plan['id_attribute_group'],
            ], static function ($value): bool {
                return $value !== null;
            }));
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'attribute value: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * The new place of the value, checked against how many values its group will hold.
     *
     * The order of the values is one per group for the whole installation,
     * the one the back office list shows, so every value of the group counts,
     * whatever its shop. A value moving in is one more there.
     *
     * @param mixed $value
     * @param int $idGroup the group the value ends up in
     * @param bool $moving whether it comes from another group
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function planPosition($value, int $idGroup, bool $moving, array &$plan, array &$errors): void
    {
        $last = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'attribute` WHERE `id_attribute_group` = ' . (int) $idGroup
        ) - ($moving ? 0 : 1);
        $position = self::integer($value);
        if ($position === null || $position < 0 || $position > $last) {
            $errors[] = 'position: a whole number from 0 to ' . $last . ', the place among the values of the group';

            return;
        }
        $plan['position'] = $position;
    }

    /**
     * The removal of the texture, accepted for a group of type color only, as the texture itself.
     *
     * @param array<string, mixed> $arguments
     * @param bool|null $colorGroup null when the group is unknown, which leaves nothing to check against
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function removeTexture(array $arguments, ?bool $colorGroup, array &$plan, array &$errors): void
    {
        if (!array_key_exists('remove_texture', $arguments)) {
            return;
        }
        // A boolean only: "false" cast to bool is true, and would delete a
        // texture the merchant meant to keep.
        if (!is_bool($arguments['remove_texture'])) {
            $errors[] = 'remove_texture: true or false';

            return;
        }
        if (!$arguments['remove_texture']) {
            return;
        }
        if (array_key_exists('texture', $arguments)) {
            $errors[] = 'remove_texture: not with texture in the same call - a new texture already replaces the current one';

            return;
        }
        if ($colorGroup === false) {
            $errors[] = 'remove_texture: only a group of type color shows a texture, and this one is a drop-down list '
                . 'or radio buttons; leave it out';

            return;
        }
        $plan['remove_texture'] = true;
    }

    /**
     * A translated field of an object loaded without a language, as one text per language.
     *
     * Loaded that way the core gives an array, yet PrestaShop 8.0 declares
     * ProductAttribute::$name as a plain string, so the value goes through an
     * untyped parameter: read against the declaration, the array case would
     * look impossible. A version that hands back a single string still works.
     *
     * @param mixed $value
     * @param int $idFallback
     *
     * @return array<int, string>
     */
    private static function perLanguage($value, int $idFallback): array
    {
        if (is_array($value)) {
            return array_map('strval', $value);
        }

        return [$idFallback => is_scalar($value) ? (string) $value : ''];
    }

    /**
     * Keeps the names, group, colour, texture and faceted search fields that differ from the shop, and records each one.
     *
     * @param ProductAttribute $attribute loaded in every language
     * @param int $idGroup the group the value ends up in
     * @param string $groupType the type of that group
     * @param int $idShop
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function compareValue(ProductAttribute $attribute, int $idGroup, string $groupType, int $idShop, array &$plan, array &$errors): void
    {
        $languages = $plan['languages'];
        $idAttribute = (int) $attribute->id;

        $current = self::perLanguage($attribute->name, (int) $languages['fallback']);
        $merged = $current;
        foreach ($plan['texts']['name'] ?? [] as $idLang => $name) {
            $old = $current[$idLang] ?? '';
            if ($old === $name) {
                continue;
            }
            $merged[$idLang] = $name;
            self::record($plan, 'name (' . $languages['filled'][$idLang] . ')', $old, $name);
        }
        if ($merged !== $current) {
            $plan['names'] = $merged;
        }

        $idCurrent = (int) $attribute->id_attribute_group;
        if ($idGroup !== $idCurrent) {
            self::checkMove($idAttribute, $idGroup, $idShop, $merged, $plan, $errors);
            $plan['id_attribute_group'] = $idGroup;
            self::record($plan, 'id_attribute_group', $idCurrent, $idGroup);
            // The colour and texture stay stored, as when a group changes
            // type: only a group of type color draws them.
            $plan['colours_unused'] = $groupType !== 'color'
                && ((string) $attribute->color !== '' || SomcpToolGetAttributeValue::textureUrl($idAttribute) !== null)
                && (string) (new AttributeGroup($idCurrent))->group_type === 'color';
        }

        // Compared without case: #e84c3d and #E84C3D are the same swatch.
        $oldColor = (string) $attribute->color;
        if ($plan['color'] !== '' && strcasecmp($oldColor, (string) $plan['color']) !== 0) {
            $plan['new_color'] = (string) $plan['color'];
            self::record($plan, 'color', $oldColor, $plan['color']);
        }

        // A texture given is always a change: the one given back as read was
        // left out by planUpdate(), and what another address serves today
        // cannot be compared with the stored file without fetching it.
        $oldTexture = SomcpToolGetAttributeValue::textureUrl($idAttribute);
        if ($plan['texture'] !== null) {
            self::record($plan, 'texture', $oldTexture, SomcpUpload::origin($plan['texture']));
        } elseif ($plan['remove_texture'] && $oldTexture !== null) {
            self::record($plan, 'texture', $oldTexture, null);
        } else {
            $plan['remove_texture'] = false;
        }

        $given = isset($plan['texts']['url_name']) || isset($plan['texts']['meta_title']);
        if (!$plan['faceted'] || !$given) {
            return;
        }

        $stored = self::storedValueFaceted($idAttribute, $languages['all']);
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
     * Refuses a move to another group that would leave the shop inconsistent.
     *
     * A combination is made of one value per group: moved under it, a value
     * could give a combination two values of one group, or none of a group its
     * siblings all have, and the faceted search index keeps the group the
     * value was indexed under. Moved only when no combination uses it, the
     * value cannot end up there.
     *
     * The names given were compared with the new group's values already; the
     * ones kept from the value are compared here, in every language.
     *
     * @param int $idAttribute
     * @param int $idGroup
     * @param int $idShop
     * @param array<int, string> $names the value's names as they will be, by language identifier
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function checkMove(int $idAttribute, int $idGroup, int $idShop, array $names, array $plan, array &$errors): void
    {
        $combinations = (int) Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT `id_product_attribute`) FROM `' . _DB_PREFIX_ . 'product_attribute_combination`'
            . ' WHERE `id_attribute` = ' . (int) $idAttribute
        );
        if ($combinations > 0) {
            $errors[] = 'id_attribute_group: ' . $combinations . ' combination(s) use this value, and they would end up '
                . 'with a value of a group their product was not built on - delete those combinations first, or add '
                . 'the value to the other group with somcp_create_attribute_value';
        }

        $taken = self::groupNames($idGroup, $idShop, $idAttribute);
        foreach ($names as $idLang => $name) {
            $name = Tools::strtolower(trim($name));
            if (isset($plan['texts']['name'][$idLang]) || $name === '' || !isset($taken[$idLang][$name])) {
                continue;
            }
            $errors[] = 'name (' . (string) Language::getIsoById((int) $idLang) . '): the group it moves to already has '
                . 'this value, id_attribute ' . $taken[$idLang][$name] . ' - give it another name in the same call';
        }
    }

    /**
     * The faceted search fields the value holds now, in every language of the shop.
     *
     * A value whose form was never saved with the module has no row: the form
     * shows empty fields, and so does this.
     *
     * @param int $idAttribute
     * @param array<int, int> $idLangs
     *
     * @return array{url_name: array<int, string>, meta_title: array<int, string>}
     */
    private static function storedValueFaceted(int $idAttribute, array $idLangs): array
    {
        $stored = ['url_name' => [], 'meta_title' => []];
        foreach ($idLangs as $idLang) {
            $stored['url_name'][(int) $idLang] = '';
            $stored['meta_title'][(int) $idLang] = '';
        }
        $rows = Db::getInstance()->executeS(
            'SELECT `id_lang`, `url_name`, `meta_title` FROM `' . _DB_PREFIX_ . 'layered_indexable_attribute_lang_value`'
            . ' WHERE `id_attribute` = ' . (int) $idAttribute
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
     * Writes the change, then the texture and the faceted search fields, and answers with what was stored.
     *
     * @param ProductAttribute $attribute
     * @param array<string, mixed> $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function applyUpdate(ProductAttribute $attribute, array $plan, int $idShop, int $idEmployee): array
    {
        $idAttribute = (int) $attribute->id;
        $idLeft = (int) $attribute->id_attribute_group;
        $written = $plan['names'] !== null || $plan['new_color'] !== null || $plan['id_attribute_group'] !== null;

        // update() fires actionAttributeSave, on which the faceted search
        // module deletes the value's rows and writes back only what its form's
        // request carries - nothing, here. The fields this call leaves alone
        // are read first and written back once the value is saved.
        $kept = null;
        if ($written && $plan['faceted'] && $plan['faceted_rows'] === null) {
            $kept = self::storedValueFaceted($idAttribute, $plan['languages']['all']);
        }

        if ($written) {
            if ($plan['names'] !== null) {
                self::fill($attribute, ['name' => $plan['names']]);
            }
            if ($plan['new_color'] !== null) {
                $attribute->color = (string) $plan['new_color'];
            }
            if ($plan['id_attribute_group'] !== null) {
                $attribute->id_attribute_group = (int) $plan['id_attribute_group'];
                // update() keeps the number the value had in the group it
                // leaves: it is put last in the new one, as add() places a
                // new value.
                $attribute->position = (int) ProductAttribute::getHigherPosition((int) $plan['id_attribute_group']) + 1;
            }
            // The shop of the token only: left to the context's list of shops,
            // update() can attach the value to a shop it was never in.
            $attribute->id_shop_list = [$idShop];

            try {
                $done = $attribute->update();
                $failure = $done ? '' : 'PrestaShop refused to save it';
            } catch (Throwable $e) {
                $done = false;
                $failure = $e->getMessage();
            }
            if (!$done) {
                SomcpLogger::log(
                    'attribute_value_update_failed',
                    SomcpLogger::OUTCOME_FAILURE,
                    'attribute value ' . $idAttribute . ': ' . Tools::substr($failure, 0, 500),
                    '',
                    $idEmployee
                );

                return SomcpTool::text(
                    'The attribute value could not be changed: ' . $failure . '. The names may be changed in some '
                        . 'languages only, and the texture and faceted search fields were not touched. Read the '
                        . 'value again with somcp_get_attribute_value, tell the merchant what it holds now, and do '
                        . 'not call again before.',
                    true
                );
            }

            // The positions left behind are closed up, as the core does when a
            // value is deleted, so the old group keeps no gap.
            if ($plan['id_attribute_group'] !== null) {
                $attribute->cleanPositions($idLeft);
            }

            // A value that never had faceted search fields has nothing to put back.
            if ($kept !== null && implode('', $kept['url_name']) . implode('', $kept['meta_title']) !== '') {
                $plan['faceted_rows'] = $kept;
            }
        }

        $changes = $plan['changes'];
        $result = ['changed' => true, 'id_attribute' => $idAttribute];

        if ($plan['texture'] !== null) {
            // Written over the current file only once the new one has been
            // fetched and read as an image, so a refused texture leaves the
            // current one in place.
            $result['texture'] = self::storeTexture($idAttribute, $plan['texture']);
            if (!isset($result['texture']['stored'])) {
                $changes = array_values(array_filter($changes, static function (array $change): bool {
                    return $change['field'] !== 'texture';
                }));
                if ($changes === []) {
                    return SomcpTool::text(
                        'The texture could not be used: ' . (string) ($result['texture']['refused'] ?? 'unknown reason')
                            . '. Nothing was changed: the value keeps its current texture.',
                        true
                    );
                }
            }
        } elseif ($plan['remove_texture']) {
            $file = _PS_COL_IMG_DIR_ . $idAttribute . '.jpg';
            if (is_file($file) && !@unlink($file)) {
                $changes = array_values(array_filter($changes, static function (array $change): bool {
                    return $change['field'] !== 'texture';
                }));
                if ($changes === []) {
                    return SomcpTool::text('The texture could not be removed: the shop could not delete its file. Nothing was changed.', true);
                }
                $result['texture'] = 'not removed: the shop could not delete its file';
            }
        }
        $result['changes'] = $changes;

        if ($plan['colours_unused']) {
            $result['note'] = 'The value is now in a group that is not of type color: it keeps its colour and texture, '
                . 'but the product page no longer shows them. They come back if it moves to a colour group again.';
        }

        if ($plan['faceted_rows'] !== null) {
            $rows = $plan['faceted_rows'];
            if (!self::writeAttributeFaceted($idAttribute, $rows['url_name'], $rows['meta_title'], array_keys($rows['url_name']))) {
                SomcpLogger::log('attribute_value_faceted_failed', SomcpLogger::OUTCOME_FAILURE, 'attribute value ' . $idAttribute, '', $idEmployee);
                if (!$written && !isset($result['texture'])) {
                    return SomcpTool::text(
                        'The faceted search fields could not be saved. Read the value again with '
                            . 'somcp_get_attribute_value, tell the merchant what it holds now, and do not call again before.',
                        true
                    );
                }
                // The rest is kept: it went through on its own, and the
                // merchant is told what did not.
                $result['faceted_search'] = 'not saved: fill url_name and meta_title in the back office';
            }
        } elseif ($plan['faceted'] && $written) {
            // The module's filter blocks carry the value's name, and its own
            // form drops them on every save.
            self::dropFacetedCache();
        }

        if ($plan['position'] !== null) {
            // The core finds the group to reorder from the request the list
            // sends, so the group the value now stands in is handed over.
            // After a move, update() has already put the value last there.
            $failure = SomcpCatalog::movePosition(
                $attribute,
                (int) $attribute->position,
                (int) $plan['position'],
                ['id_attribute_group' => (int) $attribute->id_attribute_group]
            );
            if ($failure !== '') {
                SomcpLogger::log(
                    'attribute_value_update_failed',
                    SomcpLogger::OUTCOME_FAILURE,
                    'attribute value ' . $idAttribute . ' position: ' . Tools::substr($failure, 0, 500),
                    '',
                    $idEmployee
                );

                return SomcpTool::text(
                    'The attribute value could not be moved to position ' . (int) $plan['position'] . ': ' . $failure
                        . '. The other changes of this call may be saved. Read the value again with '
                        . 'somcp_get_attribute_value, tell the merchant what it holds now, and do not call again before.',
                    true
                );
            }
        }

        SomcpLogger::log(
            'attribute_value_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'attribute value ' . $idAttribute . ', ' . count($changes) . ' change(s)',
            '',
            $idEmployee
        );

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $result['attribute_value'] = SomcpToolGetAttributeValue::attributeValue($idAttribute, (int) $plan['languages']['fallback']);

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
