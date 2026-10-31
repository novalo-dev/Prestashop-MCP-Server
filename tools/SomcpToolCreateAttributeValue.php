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
 * somcp_create_attribute_value - one value of an attribute group, with every
 * field of the back office value form, faceted search fields included.
 *
 * It extends the feature creation tool, as the attribute group tools do, for
 * what every catalogue write shares and must not come to disagree on: the
 * languages being filled, texts keyed by ISO code, a language left out taking
 * the default one's text, the faceted search URL word and title tag checked the
 * way that module's form checks them, the image download and its guards, and
 * the employee of the token carried on the write.
 *
 * Everything is checked before anything is written, and every problem is
 * reported at once. The value is written through ProductAttribute::add(), in
 * the shop of the token, so actionAttributeSave fires, and deleted again if
 * that fails after the row went in.
 *
 * The URL word and the title tag are not PrestaShop's: the faceted search
 * module's actionAttributeSave hook reads them from the form's own request, so
 * on a value added here it finds nothing and leaves the value without them.
 * The tool writes the rows that module writes once the value exists. The
 * texture is not a column either: the back office keeps it as a file named
 * after the value, which can only be written once the value has its identifier.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends, it tests the group
// the way somcp_get_attribute_group does, and it answers with the value as
// somcp_get_attribute_value returns it.
require_once __DIR__ . '/SomcpToolCreateFeature.php';
require_once __DIR__ . '/SomcpToolGetAttributeGroup.php';
require_once __DIR__ . '/SomcpToolGetAttributeValue.php';

class SomcpToolCreateAttributeValue extends SomcpToolCreateFeature
{
    /** @var array<int, string> Every argument this tool takes. */
    protected const VALUE_ARGUMENTS = ['id_attribute_group', 'languages', 'name', 'url_name', 'meta_title', 'color', 'texture'];

    /**
     * @var array<int, string> What somcp_get_attribute_value returns and this tool refuses, because the shop
     *                         works it out. Named, so the assistant learns why rather than meeting an "unknown field".
     */
    protected const VALUE_COMPUTED = ['id_attribute', 'group_name', 'position'];

    /** @var int The size of the name column (ProductAttribute::$definition). */
    protected const VALUE_NAME_MAX = 128;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_attribute_value',
            Somcp::t('Create an attribute value'),
            'Adds one value (M under Size, Red under Colour...) to an attribute group of this shop, with every '
                . 'field of the back office value form: name, and for a group of type color its colour (hex '
                . 'code) and texture (an image shown instead of the colour), and the faceted search fields of '
                . 'that form - url_name (the word used in filtered URLs) and meta_title - when the faceted search '
                . 'module is installed and enabled. id_attribute_group and name are required; take '
                . 'id_attribute_group from somcp_list_attributes, never guess it. Read somcp_list_attributes '
                . 'first: the value may already exist, and a name the group already holds is refused. '
                . 'The value is placed last in its group. '
                . 'The texture is a public https address the shop downloads itself; if it cannot be used, the '
                . 'value is still created and texture says why. '
                . 'Who fills each field is said at the start of its description, and is a rule for you: '
                . '"Merchant" - ask the merchant and never invent the value; you may suggest one for them to '
                . 'confirm. "Assistant" - write it yourself in every language being filled, and show it to the '
                . 'merchant before the call. '
                . 'Texts are objects keyed by ISO code and must be given for every language in languages; a '
                . 'language of the shop left out takes the default language\'s text. '
                . 'Every problem is reported at once and nothing is written until the whole call is valid. '
                . 'Do not use it to create a group, which is somcp_create_attribute_group, or to change a value, '
                . 'which is somcp_update_attribute_value. One value per call: for several, call it once per '
                . 'value and report each result. '
                . 'Not to be retried: if the answer is lost, read somcp_list_attributes and look for the name '
                . 'before anything else.',
            self::valueSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Values are added under their group, on the back office
            // attributes page, with the right to add there.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminAttributesGroups')
        );
    }

    /**
     * The arguments, under the names somcp_get_attribute_value returns.
     *
     * @return array<string, mixed>
     */
    protected static function valueSchema(): array
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
                'id_attribute_group' => [
                    'type' => 'integer',
                    'description' => 'Merchant. Required. Identifier of the attribute group the value belongs to, '
                        . 'from somcp_list_attributes.',
                ],
                'languages' => [
                    'type' => 'array',
                    'description' => 'ISO codes of the languages to fill, among the active languages of the shop. '
                        . 'All of them by default. Every text must then be given in each of them.',
                    'items' => ['type' => 'string'],
                ],
                'name' => $text('Merchant. Required. The value shown on the product page, 128 characters at '
                    . 'most, without < > = { }.'),
                'url_name' => $text('Assistant. Faceted search: the word used in filtered URLs instead of the '
                    . 'name, lower case letters, digits and hyphens, for example "dark-blue". Empty for the name. '
                    . '128 characters at most.'),
                'meta_title' => $text('Assistant. Faceted search: the title tag of filtered pages instead of the '
                    . 'name. 128 characters at most, without < > = { }.'),
                'color' => [
                    'type' => 'string',
                    'description' => 'Merchant. Groups of type color only: the colour of the swatch, as a hex code '
                        . 'such as #E84C3D.',
                ],
                'texture' => [
                    'type' => 'string',
                    'description' => 'Merchant. Groups of type color only: the public https address of an image '
                        . '(JPEG, PNG, GIF or WebP, 10 MB at most) shown instead of the colour, for a pattern or '
                        . 'a material. The shop downloads it itself.',
                ],
            ],
            'required' => ['id_attribute_group', 'name'],
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

        return self::asEmployee($idEmployee, static function () use ($plan, $idShop, $idEmployee): array {
            return self::addValue($plan, $idShop, $idEmployee);
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

        $group = null;
        if (!array_key_exists('id_attribute_group', $arguments)) {
            $errors[] = 'id_attribute_group: required, from somcp_list_attributes';
        } else {
            // A group of another shop is answered like one that does not
            // exist, the test somcp_get_attribute_group makes.
            $group = SomcpToolGetAttributeGroup::existingGroup(self::integer($arguments['id_attribute_group']) ?? 0, $idShop);
            if ($group === null) {
                $errors[] = 'id_attribute_group: no attribute group of this shop has this identifier - find it with somcp_list_attributes';
            }
        }

        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }

        $plan = [
            'id_attribute_group' => $group === null ? 0 : (int) $group->id,
            'languages' => $languages,
            'texts' => [],
            'color' => '',
            'texture' => '',
            'faceted' => self::facetedSearchActive(),
            'indexable' => false,
        ];

        self::valueNames($arguments, $languages, (int) $plan['id_attribute_group'], $idShop, $plan, $errors, true);
        // Null when the group was refused: whether it draws colours is then
        // unknown, and the group error already says what to fix.
        self::colorFields($arguments, $group === null ? null : (string) $group->group_type === 'color', $plan, $errors);
        // A value has no Indexable of its own: that switch belongs to the
        // group. It is already reported as not a field of this tool, so it is
        // kept away from the shared check, which would report it twice.
        self::facetedFields(array_diff_key($arguments, ['indexable' => true]), $languages, $plan, $errors);

        // The same check the core runs before add(), on the whole object, as a
        // last net for a rule of the running version none of the above knew.
        if ($errors === []) {
            $candidate = self::newValue($plan);
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'attribute value: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * The names of the value, checked with the running version's rules, and
     * refused when the group already holds them.
     *
     * @param array<string, mixed> $arguments
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idGroup 0 when the group was refused, which leaves nothing to compare with
     * @param int $idShop
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     * @param bool $required false when changing a value, which already has a name
     * @param int $idOwn the value being changed, which never clashes with itself; 0 for a new one
     *
     * @return void
     */
    protected static function valueNames(array $arguments, array $languages, int $idGroup, int $idShop, array &$plan, array &$errors, bool $required, int $idOwn = 0): void
    {
        $probe = new ProductAttribute();
        // Given, a name is never empty, whether the value is new or not: the
        // core requires it in every language.
        $names = self::texts($arguments, 'name', 'name', $languages, $errors, $required || array_key_exists('name', $arguments));
        if ($names === null) {
            return;
        }

        $taken = $idGroup > 0 ? self::groupNames($idGroup, $idShop, $idOwn) : [];
        foreach ($names as $idLang => $name) {
            $name = trim($name);
            $names[$idLang] = $name;
            $where = 'name (' . $languages['filled'][$idLang] . ')';
            if ($name === '') {
                // Already reported by texts() as missing.
                continue;
            }
            if (Tools::strlen($name) > self::VALUE_NAME_MAX) {
                $errors[] = $where . ': ' . self::VALUE_NAME_MAX . ' characters at most, ' . Tools::strlen($name) . ' given';
            } elseif (($check = $probe->validateField('name', $name, $idLang)) !== true) {
                $errors[] = $where . ': a character PrestaShop refuses in an attribute value (< > = { }) - ' . self::reason($check);
            } elseif (isset($taken[$idLang][Tools::strtolower($name)])) {
                // The back office refuses a name its group already holds in
                // that language. Compared without case, as MySQL compares it
                // there: the product page would show both under one label.
                $errors[] = $where . ': the group already has this value, id_attribute '
                    . $taken[$idLang][Tools::strtolower($name)]
                    . ($idOwn > 0 ? ' - two values of one group cannot share a name' : ' - use it rather than adding it again');
            }
        }
        $plan['texts']['name'] = $names;
    }

    /**
     * The names the values of a group already hold, per language.
     *
     * Only the values of the shop of the token count: attribute_shop is the
     * set somcp_list_attributes lists, and the back office makes the same test
     * through the shop association of the context.
     *
     * @param int $idGroup
     * @param int $idShop
     * @param int $idOwn a value left out of the comparison, the one being changed; 0 for none
     *
     * @return array<int, array<string, int>> lower-cased name => id_attribute, by language identifier
     */
    protected static function groupNames(int $idGroup, int $idShop, int $idOwn = 0): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT al.`id_attribute`, al.`id_lang`, al.`name`'
            . ' FROM `' . _DB_PREFIX_ . 'attribute` a'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'attribute_shop` ash ON ash.`id_attribute` = a.`id_attribute`'
            . ' AND ash.`id_shop` = ' . (int) $idShop
            . ' INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.`id_attribute` = a.`id_attribute`'
            . ' WHERE a.`id_attribute_group` = ' . (int) $idGroup
            . ' AND a.`id_attribute` != ' . (int) $idOwn
        );

        $taken = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $name = Tools::strtolower(trim((string) $row['name']));
            $taken[(int) $row['id_lang']][$name] = $taken[(int) $row['id_lang']][$name] ?? (int) $row['id_attribute'];
        }

        return $taken;
    }

    /**
     * The colour and the texture, accepted for a group of type color only.
     *
     * Only that type draws them on the product page: stored on another, they
     * would be values the merchant set and no customer ever sees.
     *
     * @param array<string, mixed> $arguments
     * @param bool|null $colorGroup null when the group is unknown, which leaves nothing to check against
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    protected static function colorFields(array $arguments, ?bool $colorGroup, array &$plan, array &$errors): void
    {
        $given = array_values(array_intersect(['color', 'texture'], array_keys($arguments)));
        if ($given === [] || $colorGroup === null) {
            return;
        }
        if (!$colorGroup) {
            $errors[] = implode(', ', $given) . ': only a group of type color shows a colour or a texture, and this '
                . 'one is a drop-down list or radio buttons; leave ' . (count($given) > 1 ? 'them' : 'it') . ' out';

            return;
        }

        if (array_key_exists('color', $arguments)) {
            $color = trim((string) (self::string($arguments['color']) ?? ''));
            // Validate::isColor() also lets a bare word through, which the
            // colour picker of the form never stores: only the hex code is.
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color) || !Validate::isColor($color)) {
                $errors[] = 'color: a hex code such as #E84C3D';
            } else {
                $plan['color'] = $color;
            }
        }

        if (array_key_exists('texture', $arguments)) {
            // Checked for shape only: it is fetched once the value exists,
            // with the guards of the image download, since the file is named
            // after the value's identifier.
            $url = trim((string) (self::string($arguments['texture']) ?? ''));
            $parts = $url === '' ? false : parse_url($url);
            if (!is_array($parts) || Tools::strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
                $errors[] = 'texture: the public https address of an image';
            } else {
                $plan['texture'] = $url;
            }
        }
    }

    /**
     * The value as it will be added, every language of the shop filled.
     *
     * @param array<string, mixed> $plan
     *
     * @return ProductAttribute
     */
    private static function newValue(array $plan): ProductAttribute
    {
        $attribute = new ProductAttribute();
        $attribute->id_attribute_group = (int) $plan['id_attribute_group'];
        $attribute->color = (string) $plan['color'];
        self::fill($attribute, self::completeTexts(['name' => $plan['texts']['name']], $plan['languages']));

        return $attribute;
    }

    /**
     * Writes the value, then its texture and faceted search fields, and answers with what was stored.
     *
     * @param array<string, mixed> $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function addValue(array $plan, int $idShop, int $idEmployee): array
    {
        $attribute = self::newValue($plan);
        // The shop of the token, never the context's list, so on a multistore
        // installation the value goes to that shop only, as the core's
        // AddAttributeHandler does with the shops it is given. The position is
        // left to add(), which puts the value last in its group.
        $attribute->id_shop_list = [$idShop];

        try {
            $done = $attribute->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        if (!$done) {
            $kept = false;
            if ((int) $attribute->id > 0) {
                try {
                    $kept = !$attribute->delete();
                } catch (Throwable $e) {
                    $kept = true;
                }
            }
            SomcpLogger::log(
                'attribute_value_create_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr($failure, 0, 500) . ($kept ? ', attribute value ' . (int) $attribute->id . ' could not be removed' : ''),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The attribute value could not be created: ' . $failure . '. '
                    . ($kept
                        ? 'It could not be removed either: attribute value ' . (int) $attribute->id . ' exists in an '
                            . 'incomplete state. Tell the merchant to check it in the back office; do not call again.'
                        : 'Nothing was kept.'),
                true
            );
        }

        $idAttribute = (int) $attribute->id;
        $result = [
            'created' => true,
            'id_attribute' => $idAttribute,
            'texture' => $plan['texture'] === '' ? null : self::storeTexture($idAttribute, (string) $plan['texture']),
        ];

        // The module's hook has just run on add() and, finding no form in
        // this request, left the value without rows: they are written now, so
        // a value created here reads the same as one saved on screen.
        if ($plan['faceted'] && !self::storeAttributeFaceted($idAttribute, $plan)) {
            // The value is kept: it is usable without them, and removing it
            // would lose what did go through. The merchant is told instead.
            $result['faceted_search'] = 'not saved: fill url_name and meta_title in the back office';
            SomcpLogger::log('attribute_value_faceted_failed', SomcpLogger::OUTCOME_FAILURE, 'attribute value ' . $idAttribute, '', $idEmployee);
        }

        SomcpLogger::log(
            'attribute_value_created',
            SomcpLogger::OUTCOME_SUCCESS,
            'attribute value ' . $idAttribute . ' in group ' . (int) $plan['id_attribute_group']
                . (isset($result['texture']['stored']) ? ', with a texture' : ''),
            '',
            $idEmployee
        );

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored, position included.
        $result['attribute_value'] = SomcpToolGetAttributeValue::attributeValue($idAttribute, (int) $plan['languages']['fallback']);

        return SomcpTool::json($result);
    }

    /**
     * Downloads a texture and writes it where the back office writes it.
     *
     * The attributes page uploads it as img/co/<id>.jpg, re-encoded as JPEG,
     * with no thumbnails: the product page and somcp_get_attribute_value both
     * look for that file and nothing else.
     *
     * @param int $idAttribute
     * @param string $url
     *
     * @return array<string, mixed> the address, and stored or the reason it was refused
     */
    protected static function storeTexture(int $idAttribute, string $url): array
    {
        $file = tempnam(_PS_TMP_IMG_DIR_, 'somcp');
        if ($file === false) {
            return ['url' => $url, 'refused' => 'the shop has no room to receive it'];
        }

        try {
            $refusal = self::download($url, $file);
            if ($refusal === '') {
                $refusal = self::checkImage($file);
            }
            if ($refusal === '') {
                $refusal = ImageManager::resize($file, _PS_COL_IMG_DIR_ . $idAttribute . '.jpg')
                    ? ''
                    : 'the shop could not read it as an image';
            }
        } catch (Throwable $e) {
            $refusal = 'the shop could not save it';
        } finally {
            @unlink($file);
        }

        return ['url' => $url] + ($refusal === '' ? ['stored' => true] : ['refused' => $refusal]);
    }

    /**
     * The faceted search rows of a new value, written as the module's form writes them.
     *
     * A language left out takes the default one's text, as for the name.
     *
     * @param int $idAttribute
     * @param array<string, mixed> $plan
     *
     * @return bool
     */
    protected static function storeAttributeFaceted(int $idAttribute, array $plan): bool
    {
        return self::writeAttributeFaceted(
            $idAttribute,
            self::complete($plan['texts']['url_name'] ?? [], $plan['languages']),
            self::complete($plan['texts']['meta_title'] ?? [], $plan['languages']),
            $plan['languages']['all']
        );
    }

    /**
     * Replaces the faceted search rows of one value, then drops the module's filter cache.
     *
     * The module's own save method is private and its actionAttributeSave hook
     * reads the request, so its rows are written here, through the same
     * columns: one per language whose URL word or title tag is filled - the
     * module writes no row for a language where both are empty, and reads a
     * missing row as empty fields.
     *
     * @param int $idAttribute
     * @param array<int, string> $urls by language identifier
     * @param array<int, string> $titles by language identifier
     * @param array<int, int> $idLangs every language of the shop
     *
     * @return bool
     */
    protected static function writeAttributeFaceted(int $idAttribute, array $urls, array $titles, array $idLangs): bool
    {
        $db = Db::getInstance();

        try {
            // The module replaces the rows rather than updating them, and a
            // row left by a value that once had this identifier would
            // otherwise make the insert fail.
            $done = $db->delete('layered_indexable_attribute_lang_value', '`id_attribute` = ' . (int) $idAttribute);
            foreach ($idLangs as $idLang) {
                $url = (string) ($urls[$idLang] ?? '');
                $title = (string) ($titles[$idLang] ?? '');
                if ($url === '' && $title === '') {
                    continue;
                }
                $done = $done && $db->insert('layered_indexable_attribute_lang_value', [
                    'id_attribute' => (int) $idAttribute,
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
