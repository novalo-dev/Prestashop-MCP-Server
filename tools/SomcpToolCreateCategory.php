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
 * somcp_create_category - one category, with every field of the back office
 * category form.
 *
 * It extends the product creation tool for what the two share and must not
 * come to disagree on: the languages being filled, texts keyed by ISO code,
 * rich text cleaned the way the back office cleans it, lengths counted on the
 * visible text, and above all the guarded download of an image from a URL the
 * merchant typed. A second copy of that download would be a second place to
 * forget one of its checks.
 *
 * Everything is checked before anything is written, and every problem is
 * reported at once. The category is then written through Category::add(), so
 * actionCategoryAdd fires, the tree is rebuilt, the position and the group
 * access are set as the back office sets them. If that fails after the row went
 * in, the category is deleted again rather than left half made.
 *
 * Images come after, as for a product: one that cannot be fetched is reported
 * and does not undo the category. The core's category uploaders cannot be
 * called for them - they take an uploaded file and move it with
 * move_uploaded_file(), which refuses anything that did not arrive in a POST -
 * so the files are written where those uploaders write them, under the same
 * names, with the same thumbnails.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolCreateProduct.php';

class SomcpToolCreateCategory extends SomcpToolCreateProduct
{
    /** @var array<int, string> Every argument this tool takes. */
    private const ARGUMENTS = [
        'languages', 'name', 'id_parent', 'active', 'description', 'additional_description', 'groups',
        'link_rewrite', 'meta_title', 'meta_description', 'meta_keywords', 'redirect_type', 'id_type_redirected',
        'cover_image', 'menu_thumbnails',
    ];

    /**
     * @var array<int, string> What somcp_get_category returns and this tool refuses, because the shop works it
     *                         out. Named, so the assistant learns why rather than meeting an "unknown field".
     */
    private const CATEGORY_COMPUTED = [
        'id_category', 'parent_name', 'level', 'position', 'url', 'products_count', 'children_count',
    ];

    /** @var int The size of the name and friendly URL columns. */
    private const NAME_MAX = 128;

    /** @var int Stricter than PrestaShop's 255, so search engines show the title whole. */
    private const CATEGORY_META_TITLE_MAX = 70;

    /** @var int Stricter than PrestaShop's 512, so search results do not cut it. */
    private const CATEGORY_META_DESCRIPTION_MAX = 150;

    /** @var int Keywords past this many are noise to a search engine. */
    private const META_KEYWORDS_MAX = 5;

    /** @var int The menu thumbnail slots of PrestaShop 8 (MenuThumbnailId::ALLOWED_ID_VALUES). */
    private const MENU_THUMBNAILS_MAX = 3;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_category',
            Somcp::t('Create a category'),
            'Creates one category in this shop with every field of the back office category form: name, '
                . 'parent, Displayed, description, additional description, group access, friendly URL, meta '
                . 'title, meta description, meta keywords (PrestaShop 8), what the page does while hidden '
                . '(PrestaShop 9), cover image and menu thumbnails (PrestaShop 8). Only name is required: the '
                . 'category goes under Home and is displayed unless told otherwise. '
                . 'Who fills each field is said at the start of its description, and is a rule for you: '
                . '"Merchant" - ask the merchant and never invent the value; you may suggest one for them to '
                . 'confirm. "Merchant, from the shop" - show the list read with somcp_list_categories or '
                . 'somcp_list_customer_groups and let the merchant pick; never guess an identifier. '
                . '"Assistant" - write it yourself in every language being filled, and show it to the merchant '
                . 'before the call. '
                . 'Texts are objects keyed by ISO code and must be given for every language in languages; a '
                . 'language of the shop left out takes the default language\'s text. Lengths count the visible '
                . 'text, spaces included, HTML tags not counted. '
                . 'Every problem is reported at once and nothing is written until the whole call is valid. '
                . 'Images are downloaded by the shop from public https addresses; one that cannot be fetched '
                . 'is reported in images and does not stop the creation. '
                . 'Do not use it to change a category, which is somcp_update_category, or to show or hide one, '
                . 'which is somcp_set_category_active. One category per call: for several, call it once per '
                . 'category and report each result. '
                . 'Not to be retried: if the answer is lost, read somcp_list_categories and look for the name '
                . 'before anything else, because a second call creates a second category.',
            self::inputSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Adding a category is what the categories page asks for in Team,
            // Permissions; editing one is a different right.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminCategories')
        );
    }

    /**
     * The arguments, under the names somcp_get_category returns.
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
                'name' => $text('Merchant. Required, 128 characters at most, without < > { } (nor ; = # before PrestaShop 9.1).'),
                'id_parent' => [
                    'type' => 'integer',
                    'description' => 'Merchant, from the shop: the parent category, from somcp_list_categories. '
                        . 'Home when left out; never the root category.',
                ],
                'active' => [
                    'type' => 'boolean',
                    'description' => 'Merchant. Displayed on the storefront; true by default.',
                ],
                'description' => $text('Assistant. Shown at the top of the category page, HTML allowed.'),
                'additional_description' => $text('Assistant. Additional information shown below the '
                    . 'products, HTML allowed.'),
                'groups' => [
                    'type' => 'array',
                    'description' => 'Merchant, from the shop: the customer groups that can see the category, '
                        . 'from somcp_list_customer_groups. Every group when left out, as in the back office.',
                    'items' => ['type' => 'integer'],
                ],
                'link_rewrite' => $text('Assistant. The friendly URL, 128 characters at most; made from the '
                    . 'name when left out.'),
                'meta_title' => $text('Assistant. 70 characters at most, without < > { } (nor = before PrestaShop 9.1).'),
                'meta_description' => $text('Assistant. 150 characters at most, without < > { } (nor = before PrestaShop 9.1).'),
                'meta_keywords' => $text('Assistant. PrestaShop 8 only, refused on 9, which dropped them. '
                    . '5 keywords at most, comma separated, without < > = { }.'),
                'redirect_type' => [
                    'type' => 'string',
                    'description' => 'Assistant. PrestaShop 9 only: what the category page does while it is '
                        . 'not displayed - 301 or 302 (redirect), 404 or 410. Left out: the shop default.',
                ],
                'id_type_redirected' => [
                    'type' => 'integer',
                    'description' => 'Assistant. PrestaShop 9 only: the category a 301 or 302 redirects to, from '
                        . 'somcp_list_categories; 0 or left out for the closest parent.',
                ],
                'cover_image' => [
                    'type' => 'string',
                    'description' => 'Merchant. Public https address of the cover image. Downloaded by the shop: '
                        . 'no redirect, 10 MB at most, JPEG, PNG, GIF or WebP.',
                ],
                'menu_thumbnails' => [
                    'type' => 'array',
                    'description' => 'Merchant. PrestaShop 8 only, refused on 9, which dropped them: up to 3 '
                        . 'public https addresses of the images the main menu shows, downloaded like the cover.',
                    'items' => ['type' => 'string'],
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
        $shop = Somcp::currentShop();
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = [];
        $plan = self::plan($arguments, $shop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was created. Correct these and call again:\n- " . implode("\n- ", $errors),
                true
            );
        }

        return self::asEmployee($idEmployee, static function () use ($plan, $shop, $idEmployee): array {
            return self::addCategory($plan, (int) $shop->id, $idEmployee);
        });
    }

    /**
     * Turns the arguments into what will be written, or says everything that is wrong with them.
     *
     * Nothing is written here, so a refused call leaves the shop exactly as it was.
     *
     * @param array<string, mixed> $arguments
     * @param Shop $shop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function plan(array $arguments, Shop $shop, array &$errors): ?array
    {
        $idShop = (int) $shop->id;
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::ARGUMENTS, true)) {
                $errors[] = $key . (in_array($key, self::CATEGORY_COMPUTED, true)
                    ? ': worked out by the shop, so it is not written here'
                    : ': not a field of this tool');
            }
        }

        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }

        $probe = new Category();
        $plan = [
            'languages' => $languages,
            'fields' => [
                'id_parent' => self::parentCategory($arguments, $shop, $errors),
                'active' => true,
                'id_shop_default' => $idShop,
            ],
            'texts' => [],
            'groups' => self::customerGroups($arguments['groups'] ?? null, $errors),
            'images' => self::imageUrls($arguments, $errors),
        ];

        if (array_key_exists('active', $arguments)) {
            // A boolean only: "false" cast to bool is true, and would display a
            // category the merchant asked to keep hidden.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } else {
                $plan['fields']['active'] = $arguments['active'];
            }
        }

        $names = self::texts($arguments, 'name', 'name', $languages, $errors, true);
        foreach ($names ?? [] as $idLang => $name) {
            $name = trim($name);
            $names[$idLang] = $name;
            $where = 'name (' . $languages['filled'][$idLang] . ')';
            if (Tools::strlen($name) > self::NAME_MAX) {
                $errors[] = $where . ': ' . self::NAME_MAX . ' characters at most, ' . Tools::strlen($name) . ' given';
            } elseif ($name !== '' && !Validate::isCatalogName($name)) {
                // The rule is the running version's: PrestaShop 9.1 dropped ; = # from it.
                $errors[] = $where . ': a character PrestaShop refuses in a name (< > { }, and ; = # before PrestaShop 9.1)';
            } elseif ($name !== '' && ($check = $probe->validateField('name', $name, $idLang)) !== true) {
                $errors[] = $where . ': ' . self::reason($check);
            }
        }
        if ($names !== null) {
            $plan['texts']['name'] = $names;
        }

        foreach (['description', 'additional_description'] as $field) {
            $texts = self::texts($arguments, $field, $field, $languages, $errors);
            foreach ($texts ?? [] as $idLang => $html) {
                $where = $field . ' (' . $languages['filled'][$idLang] . ')';
                $texts[$idLang] = self::html($html, $where, $errors);
                if (($check = $probe->validateField($field, $texts[$idLang], $idLang)) !== true) {
                    $errors[] = $where . ': ' . self::reason($check);
                }
            }
            if ($texts !== null) {
                $plan['texts'][$field] = $texts;
            }
        }

        self::categoryMeta($arguments, $languages, $probe, $plan, $errors);
        $plan['texts']['link_rewrite'] = self::categoryLinkRewrites($arguments, $languages, $plan, $errors);
        self::categoryRedirect($arguments, $idShop, $plan, $errors);

        // The same check the core runs before add(), on the whole object, as a
        // last net for a rule of the running version none of the above knew.
        if ($errors === []) {
            $candidate = new Category(null, null, $idShop);
            self::fill($candidate, $plan['fields'] + self::completeTexts($plan['texts'], $languages));
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'category: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * The parent: given, an existing category of this shop other than the root; left out, Home.
     *
     * @param array<string, mixed> $arguments
     * @param Shop $shop
     * @param array<int, string> $errors
     *
     * @return int
     */
    private static function parentCategory(array $arguments, Shop $shop, array &$errors): int
    {
        // Home is the top category of the shop, which on a multistore
        // installation is not necessarily PS_HOME_CATEGORY.
        $home = (int) $shop->id_category > 0 ? (int) $shop->id_category : (int) Configuration::get('PS_HOME_CATEGORY');
        if (!array_key_exists('id_parent', $arguments)) {
            return $home;
        }

        $idParent = self::integer($arguments['id_parent']);
        if ($idParent === null || $idParent <= 0) {
            $errors[] = 'id_parent: a category identifier from somcp_list_categories';

            return $home;
        }
        if ($idParent === (int) Configuration::get('PS_ROOT_CATEGORY')) {
            $errors[] = 'id_parent: ' . $idParent . ' is the root category, which takes no category of its own; '
                . 'leave id_parent out for Home';

            return $home;
        }
        $exists = (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'category_shop` WHERE `id_category` = ' . (int) $idParent
            . ' AND `id_shop` = ' . (int) $shop->id
        );
        if (!$exists) {
            $errors[] = 'id_parent: no category ' . $idParent . ' in this shop; take one from somcp_list_categories';
        }

        return $idParent;
    }

    /**
     * Customer groups that exist, each once.
     *
     * @param mixed $value
     * @param array<int, string> $errors
     *
     * @return array<int, int> empty for every group
     */
    private static function customerGroups($value, array &$errors): array
    {
        if ($value === null) {
            return [];
        }
        if ($value === []) {
            // An empty list would read as every group, the opposite of what an
            // assistant sending "no group" means.
            $errors[] = 'groups: at least one group, or leave it out for every group';

            return [];
        }

        $known = array_map('intval', Group::getAllGroupIds());
        $groups = [];
        foreach (array_unique(self::ids($value, 'id_group', 'groups', $errors)) as $idGroup) {
            if (!in_array($idGroup, $known, true)) {
                $errors[] = 'groups: no customer group ' . $idGroup . ' in this shop; take one from somcp_list_customer_groups';
                continue;
            }
            $groups[] = $idGroup;
        }

        return $groups;
    }

    /**
     * The meta title, description and keywords given in the call.
     *
     * @param array<string, mixed> $arguments
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param Category $probe
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function categoryMeta(array $arguments, array $languages, Category $probe, array &$plan, array &$errors): void
    {
        $limits = ['meta_title' => self::CATEGORY_META_TITLE_MAX, 'meta_description' => self::CATEGORY_META_DESCRIPTION_MAX];
        foreach ($limits as $field => $max) {
            $texts = self::texts($arguments, $field, $field, $languages, $errors);
            foreach ($texts ?? [] as $idLang => $text) {
                $text = trim($text);
                $texts[$idLang] = $text;
                $where = $field . ' (' . $languages['filled'][$idLang] . ')';
                // Refused rather than cut: a title cut by the tool is a title
                // nobody wrote, and the merchant would not know it changed.
                if (self::visibleLength($text) > $max) {
                    $errors[] = $where . ': ' . $max . ' characters at most, ' . self::visibleLength($text) . ' given';
                } elseif (($check = $probe->validateField($field, $text, $idLang)) !== true) {
                    $errors[] = $where . ': a character PrestaShop refuses there (< > { }, and = before PrestaShop 9.1) - ' . self::reason($check);
                }
            }
            if ($texts !== null) {
                $plan['texts'][$field] = $texts;
            }
        }

        if (!array_key_exists('meta_keywords', $arguments)) {
            return;
        }
        if (!isset(Category::$definition['fields']['meta_keywords'])) {
            // PrestaShop 9 dropped the column. Accepting the field and storing
            // nothing would tell the merchant something false.
            $errors[] = 'meta_keywords: this PrestaShop version no longer has meta keywords; leave the field out';

            return;
        }
        $texts = self::texts($arguments, 'meta_keywords', 'meta_keywords', $languages, $errors);
        foreach ($texts ?? [] as $idLang => $text) {
            $where = 'meta_keywords (' . $languages['filled'][$idLang] . ')';
            $keywords = array_values(array_filter(array_map('trim', explode(',', $text)), static function (string $keyword): bool {
                return $keyword !== '';
            }));
            $texts[$idLang] = implode(', ', $keywords);
            if (count($keywords) > self::META_KEYWORDS_MAX) {
                $errors[] = $where . ': ' . self::META_KEYWORDS_MAX . ' keywords at most, ' . count($keywords) . ' given';
            } elseif (($check = $probe->validateField('meta_keywords', $texts[$idLang], $idLang)) !== true) {
                $errors[] = $where . ': a character PrestaShop refuses there (< > { }, and = before PrestaShop 9.1) - ' . self::reason($check);
            }
        }
        if ($texts !== null) {
            $plan['texts']['meta_keywords'] = $texts;
        }
    }

    /**
     * Friendly URLs: given, converted the way the back office converts them; left out, made from the name.
     *
     * @param array<string, mixed> $arguments
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return array<int, string>
     */
    private static function categoryLinkRewrites(array $arguments, array $languages, array $plan, array &$errors): array
    {
        $given = self::texts($arguments, 'link_rewrite', 'link_rewrite', $languages, $errors) ?? [];
        $rewrites = [];
        foreach ($languages['filled'] as $idLang => $iso) {
            if (isset($given[$idLang])) {
                [$source, $what] = [$given[$idLang], 'it'];
            } elseif (isset($plan['texts']['name'][$idLang])) {
                [$source, $what] = [$plan['texts']['name'][$idLang], 'the name'];
            } else {
                continue;
            }
            $rewrite = (string) Tools::str2url($source);
            $where = 'link_rewrite (' . $iso . ')';
            if ($rewrite === '') {
                $errors[] = $where . ': nothing usable could be made from ' . $what . '; give one';
                continue;
            }
            if (Tools::strlen($rewrite) > self::NAME_MAX) {
                $errors[] = $where . ': ' . self::NAME_MAX . ' characters at most, ' . Tools::strlen($rewrite) . ' in "' . $rewrite . '"';
                continue;
            }
            if (!Validate::isLinkRewrite($rewrite)) {
                $errors[] = $where . ': "' . $rewrite . '" is not a valid friendly URL';
                continue;
            }
            $rewrites[$idLang] = $rewrite;
        }

        return $rewrites;
    }

    /**
     * What the category page does while hidden: a field of PrestaShop 9 only.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function categoryRedirect(array $arguments, int $idShop, array &$plan, array &$errors): void
    {
        $typeGiven = array_key_exists('redirect_type', $arguments);
        $targetGiven = array_key_exists('id_type_redirected', $arguments);
        if (!$typeGiven && !$targetGiven) {
            return;
        }
        if (!isset(Category::$definition['fields']['redirect_type'])) {
            // PrestaShop 8 has no such field on a category: the page answers
            // 404 while hidden, whatever is sent.
            $errors[] = ($typeGiven ? 'redirect_type' : 'id_type_redirected')
                . ': this PrestaShop version has no redirection for a hidden category; leave the field out';

            return;
        }

        // Read from the core's own list rather than repeated here; the class
        // exists on PrestaShop 9 only, hence the name in a string.
        $constant = 'PrestaShop\PrestaShop\Core\Domain\Category\ValueObject\RedirectType::AVAILABLE_REDIRECT_TYPES';
        $types = defined($constant) ? array_values((array) constant($constant)) : ['404', '410', '301', '302'];
        $type = $typeGiven ? self::string($arguments['redirect_type']) : null;
        if ($typeGiven) {
            if ($type === null || !in_array($type, $types, true)) {
                $errors[] = 'redirect_type: one of ' . implode(', ', $types);

                return;
            }
            $plan['fields']['redirect_type'] = $type;
        }

        $target = $targetGiven ? self::integer($arguments['id_type_redirected']) : 0;
        if ($target === null || $target < 0) {
            $errors[] = 'id_type_redirected: a category identifier, or 0 for the closest parent';

            return;
        }
        if ($target === 0) {
            return;
        }
        // The core refuses a target next to a 404 or a 410, which go nowhere.
        if (!in_array($type, ['301', '302'], true)) {
            $errors[] = 'id_type_redirected: only a 301 or 302 redirection goes somewhere; give redirect_type with it';

            return;
        }
        $exists = (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'category_shop` WHERE `id_category` = ' . (int) $target
            . ' AND `id_shop` = ' . (int) $idShop
        );
        if (!$exists || $target === (int) Configuration::get('PS_ROOT_CATEGORY')) {
            $errors[] = 'id_type_redirected: no category ' . $target . ' in this shop to redirect to';

            return;
        }
        $plan['fields']['id_type_redirected'] = $target;
    }

    /**
     * The image addresses, checked for shape only: they are fetched once the category exists.
     *
     * @param array<string, mixed> $arguments
     * @param array<int, string> $errors
     *
     * @return array<int, array{url: string, for: string}>
     */
    private static function imageUrls(array $arguments, array &$errors): array
    {
        $images = [];
        if (array_key_exists('cover_image', $arguments)) {
            $url = self::string($arguments['cover_image']);
            if ($url === null || trim($url) === '') {
                $errors[] = 'cover_image: a public https address';
            } else {
                $images[] = ['url' => trim($url), 'for' => 'cover_image'];
            }
        }

        if (!array_key_exists('menu_thumbnails', $arguments)) {
            return $images;
        }
        // PrestaShop 9 removed menu thumbnails from the category form along
        // with the uploader that wrote them; MenuThumbnailId went with them.
        if (!class_exists('PrestaShop\PrestaShop\Core\Domain\Category\ValueObject\MenuThumbnailId')) {
            $errors[] = 'menu_thumbnails: this PrestaShop version no longer has menu thumbnails; leave the field out';

            return $images;
        }
        $urls = self::items($arguments['menu_thumbnails'], 'menu_thumbnails', $errors, false);
        if (count($urls) > self::MENU_THUMBNAILS_MAX) {
            $errors[] = 'menu_thumbnails: ' . self::MENU_THUMBNAILS_MAX . ' at most, ' . count($urls) . ' given';

            return $images;
        }
        foreach ($urls as $i => $url) {
            $url = self::string($url);
            if ($url === null || trim($url) === '') {
                $errors[] = 'menu_thumbnails[' . $i . ']: a public https address';
                continue;
            }
            $images[] = ['url' => trim($url), 'for' => 'menu_thumbnails'];
        }

        return $images;
    }

    /**
     * Writes the category, then its images, and answers with what was stored.
     *
     * @param array<string, mixed> $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function addCategory(array $plan, int $idShop, int $idEmployee): array
    {
        $category = new Category(null, null, $idShop);
        self::fill($category, $plan['fields'] + self::completeTexts($plan['texts'], $plan['languages']));
        // Null lets add() give access to every group, as the back office does
        // when no group is ticked.
        $category->groupBox = $plan['groups'] === [] ? null : $plan['groups'];
        // The shop of the token, never the context's list, so on a multistore
        // installation the category, its texts and its position go to that shop.
        $category->id_shop_list = [$idShop];

        try {
            $done = $category->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        if (!$done) {
            $kept = false;
            if ((int) $category->id > 0) {
                try {
                    $kept = !$category->delete();
                } catch (Throwable $e) {
                    $kept = true;
                }
            }
            SomcpLogger::log(
                'category_create_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr($failure, 0, 500) . ($kept ? ', category ' . (int) $category->id . ' could not be removed' : ''),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The category could not be created: ' . $failure . '. '
                    . ($kept
                        ? 'It could not be removed either: category ' . (int) $category->id . ' exists in an '
                            . 'incomplete state. Tell the merchant to check it in the back office; do not call again.'
                        : 'Nothing was kept.'),
                true
            );
        }

        $idCategory = (int) $category->id;
        $images = self::storeImages($idCategory, $plan['images']);
        $warnings = self::categoryWarnings($idCategory, $plan, $idShop);

        SomcpLogger::log(
            'category_created',
            SomcpLogger::OUTCOME_SUCCESS,
            'category ' . $idCategory . ' under ' . (int) $plan['fields']['id_parent'] . ', '
                . count(array_filter($images, static function (array $image): bool {
                    return !empty($image['stored']);
                })) . ' image(s)',
            '',
            $idEmployee
        );

        $languages = $plan['languages'];

        return SomcpTool::json([
            'created' => true,
            'id_category' => $idCategory,
            'seo_warnings' => $warnings === [] ? new stdClass() : $warnings,
            'images' => $images,
            // Read back rather than echoed from the arguments: what the
            // assistant shows the merchant is what the shop stored, generated
            // URL, position and group access included.
            'category' => self::category($idCategory, (int) $languages['fallback'], $idShop),
        ]);
    }

    /**
     * Each image downloaded, checked and written where the core's uploaders write it.
     *
     * @param int $idCategory
     * @param array<int, array{url: string, for: string}> $images
     *
     * @return array<int, array<string, mixed>>
     */
    private static function storeImages(int $idCategory, array $images): array
    {
        $report = [];
        $slot = 0;
        $thumbnails = false;
        foreach ($images as $image) {
            $file = tempnam(_PS_TMP_IMG_DIR_, 'somcp');
            if ($file === false) {
                $report[] = ['url' => $image['url'], 'for' => $image['for'], 'refused' => 'the shop has no room to receive it'];
                continue;
            }

            try {
                $refusal = self::download($image['url'], $file);
                if ($refusal === '') {
                    $refusal = self::checkImage($file);
                }
                if ($refusal === '') {
                    if ($image['for'] === 'cover_image') {
                        // CategoryCoverImageUploader: the original as
                        // c/<id>.jpg, then one file per category image type.
                        $target = _PS_CAT_IMG_DIR_ . $idCategory . '.jpg';
                        $stored = ImageManager::resize($file, $target);
                        if ($stored) {
                            self::thumbnails($target, _PS_CAT_IMG_DIR_ . $idCategory, 'categories');
                        }
                    } else {
                        // CategoryMenuThumbnailUploader: c/<id>-<slot>_thumb.jpg,
                        // slots 0 to 2, with no thumbnails of their own.
                        $stored = ImageManager::resize($file, _PS_CAT_IMG_DIR_ . $idCategory . '-' . $slot . '_thumb.jpg');
                        if ($stored) {
                            ++$slot;
                            $thumbnails = true;
                        }
                    }
                    $refusal = $stored ? '' : 'the shop could not read it as an image';
                }
            } catch (Throwable $e) {
                $refusal = 'the shop could not save it';
            } finally {
                @unlink($file);
            }

            $report[] = ['url' => $image['url'], 'for' => $image['for']]
                + ($refusal === '' ? ['stored' => true] : ['refused' => $refusal]);
        }

        // What the menu thumbnail uploader does once they are written: the
        // main menu is cached by Smarty and would keep showing the old ones.
        if ($thumbnails) {
            Tools::clearSmartyCache();
        }

        return $report;
    }

    /**
     * What the merchant should know about the SEO of each language filled.
     *
     * @param int $idCategory
     * @param array<string, mixed> $plan
     * @param int $idShop
     *
     * @return array<string, array<int, string>>
     */
    private static function categoryWarnings(int $idCategory, array $plan, int $idShop): array
    {
        $warnings = [];
        foreach ($plan['languages']['filled'] as $idLang => $iso) {
            $list = [];
            if ((string) ($plan['texts']['meta_title'][$idLang] ?? '') === '') {
                $list[] = 'no meta_title: the storefront uses the category name';
            }
            if ((string) ($plan['texts']['meta_description'][$idLang] ?? '') === '') {
                $list[] = 'no meta_description: search engines pick a passage of the page';
            }
            $rewrite = (string) ($plan['texts']['link_rewrite'][$idLang] ?? '');
            if ($rewrite !== '') {
                $other = (int) Db::getInstance()->getValue(
                    'SELECT cl.`id_category` FROM `' . _DB_PREFIX_ . 'category_lang` cl'
                    . ' INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.`id_category` = cl.`id_category` AND cs.`id_shop` = cl.`id_shop`'
                    . ' WHERE cl.`id_shop` = ' . (int) $idShop . ' AND cl.`id_lang` = ' . (int) $idLang
                    . " AND cl.`link_rewrite` = '" . pSQL($rewrite) . "'"
                    . ' AND cl.`id_category` != ' . (int) $idCategory
                );
                if ($other > 0) {
                    $list[] = 'link_rewrite already used by category ' . $other;
                }
            }
            if ($list !== []) {
                $warnings[$iso] = $list;
            }
        }

        return $warnings;
    }

    /**
     * The category with the fields somcp_get_category returns.
     *
     * @param int $idCategory
     * @param int $idLang
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    private static function category(int $idCategory, int $idLang, int $idShop): array
    {
        $category = new Category($idCategory, $idLang, $idShop);
        $parent = new Category((int) $category->id_parent, $idLang, $idShop);
        $link = Somcp::legacyContext()->link ?? new Link();
        $db = Db::getInstance();

        $names = [];
        foreach (Group::getGroups($idLang, $idShop) ?: [] as $row) {
            $names[(int) $row['id_group']] = (string) $row['name'];
        }
        $groups = [];
        foreach ($category->getGroups() as $idGroup) {
            $groups[] = ['id_group' => (int) $idGroup, 'name' => $names[(int) $idGroup] ?? ''];
        }

        $cover = null;
        if (is_file(_PS_CAT_IMG_DIR_ . $idCategory . '.jpg')) {
            $cover = (string) $link->getMediaLink(_THEME_CAT_DIR_ . $idCategory . '.jpg');
        }
        // Null rather than an empty list where the version has no menu
        // thumbnails at all, as meta_keywords on PrestaShop 9.
        $thumbnails = null;
        if (class_exists('PrestaShop\PrestaShop\Core\Domain\Category\ValueObject\MenuThumbnailId')) {
            $thumbnails = [];
            for ($slot = 0; $slot < self::MENU_THUMBNAILS_MAX; ++$slot) {
                $file = $idCategory . '-' . $slot . '_thumb.jpg';
                if (is_file(_PS_CAT_IMG_DIR_ . $file)) {
                    $thumbnails[] = (string) $link->getMediaLink(_THEME_CAT_DIR_ . $file);
                }
            }
        }

        // Fields one version has and the other does not are read from the
        // object's properties: the core declares them only where its definition
        // has them, and a property access, even behind the definition check, is
        // an unknown property to static analysis against the other version.
        $properties = get_object_vars($category);
        $redirects = isset(Category::$definition['fields']['redirect_type']);

        return [
            'id_category' => $idCategory,
            'name' => self::textOf($category->name, $idLang),
            'active' => (bool) $category->active,
            'id_parent' => (int) $category->id_parent,
            'parent_name' => self::textOf($parent->name, $idLang),
            'level' => (int) $category->level_depth,
            'position' => (int) $db->getValue(
                'SELECT `position` FROM `' . _DB_PREFIX_ . 'category_shop` WHERE `id_category` = ' . (int) $idCategory
                . ' AND `id_shop` = ' . (int) $idShop
            ),
            'description' => self::textOf($category->description, $idLang),
            'additional_description' => self::textOf($category->additional_description, $idLang),
            'groups' => $groups,
            'link_rewrite' => self::textOf($category->link_rewrite, $idLang),
            'url' => (string) $link->getCategoryLink($category, null, $idLang, null, $idShop),
            'meta_title' => self::textOf($category->meta_title, $idLang),
            'meta_description' => self::textOf($category->meta_description, $idLang),
            'meta_keywords' => isset(Category::$definition['fields']['meta_keywords']) ? self::textOf($properties['meta_keywords'] ?? '', $idLang) : null,
            'cover_image' => $cover,
            'menu_thumbnails' => $thumbnails,
            'products_count' => (int) $db->getValue(
                'SELECT COUNT(DISTINCT cp.`id_product`) FROM `' . _DB_PREFIX_ . 'category_product` cp'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = cp.`id_product` AND ps.`id_shop` = ' . (int) $idShop
                . ' WHERE cp.`id_category` = ' . (int) $idCategory
            ),
            'children_count' => (int) $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'category` c'
                . ' INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . (int) $idShop
                . ' WHERE c.`id_parent` = ' . (int) $idCategory
            ),
            'redirect_type' => $redirects ? (string) ($properties['redirect_type'] ?? '') : null,
            'id_type_redirected' => $redirects ? (int) ($properties['id_type_redirected'] ?? 0) : null,
        ];
    }

    /**
     * A translated field of a category loaded in one language.
     *
     * Loaded with a language, the core gives a string; an override or a hook
     * may still leave the array of every language.
     *
     * @param mixed $value
     * @param int $idLang
     *
     * @return string
     */
    private static function textOf($value, int $idLang): string
    {
        if (is_array($value)) {
            $value = $value[$idLang] ?? reset($value);
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
