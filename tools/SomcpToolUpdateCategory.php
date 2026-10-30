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
 * somcp_update_category - one existing category, changed with everything
 * somcp_create_category can write.
 *
 * It extends the creation tool rather than copying it: a field accepted at
 * creation is accepted here under the same name and checked by the same code,
 * so the two tools cannot come to disagree on what a valid name, meta title or
 * redirection is, and an image is downloaded with the same guards.
 *
 * Only what is given changes. Each value is compared with what the shop holds
 * before anything is written, which gives the answer its old and new values
 * and lets a call that would change nothing write nothing at all: no hook
 * fires, and the answer says changed: false.
 *
 * Every check runs before the write, which is a single Category::update() in
 * the shop of the token, so actionCategoryUpdate fires and the tree, the level
 * and the positions are worked out again by the core when the parent moves. A
 * position given follows, through Category::updatePosition(), where the drag
 * and drop of the back office list ends, so the siblings shift with it.
 * Images come after, as at creation: one that cannot be fetched is reported
 * and does not undo the change.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolCreateCategory.php';

class SomcpToolUpdateCategory extends SomcpToolCreateCategory
{
    /** @var array<int, string> What this tool takes besides the fields of the creation. */
    private const OWN_ARGUMENTS = ['id_category', 'position', 'remove_cover_image', 'remove_menu_thumbnails'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_category',
            Somcp::t('Change a category'),
            'Changes one existing category of this shop. Everything somcp_create_category can write can be '
                . 'changed here, under the same names and with the same rules - who fills each field, lengths, '
                . 'identifiers from somcp_list_categories and somcp_list_customer_groups. Only what is given '
                . 'changes: a value left out is kept, a text is replaced in the languages filled and kept in the '
                . 'others, groups given replaces the whole set, menu_thumbnails given replaces the current ones. '
                . 'The cover image and the menu thumbnails are only removed through remove_cover_image and '
                . 'remove_menu_thumbnails. When languages is left out every language of the shop is filled, so '
                . 'a text must then be given in each; to change one language, pass languages with it alone. '
                . 'The friendly URL is never made again from a new name: give link_rewrite to change it, and tell '
                . 'the merchant first when the category is displayed, because its old address stops working. '
                . 'position places the category among those of the same parent, from 0, as somcp_get_category '
                . 'numbers it; the others shift, as when the merchant drags it in the list. Home, the top category '
                . 'of this shop, can be changed with the fields of its own form in the back office, but never '
                . 'moved: id_parent and position are refused on it, and on PrestaShop 9 it only answers 404 or 410 '
                . 'while hidden. The root category cannot be changed here, and a category cannot be moved under '
                . 'itself or one of its subcategories. Show the merchant what will change and call once they '
                . 'agree. Every problem is reported at once and nothing is written until the whole call is '
                . 'valid. Images are downloaded by the shop from public https addresses after the change; one '
                . 'that cannot be fetched is reported in images and does not undo it. The answer gives each '
                . 'field changed with its old and new value. A call that would change nothing writes nothing '
                . 'and says changed: false. '
                . 'Do not use it to create a category (somcp_create_category) or only to show or hide one '
                . '(somcp_set_category_active). One category per call. '
                . 'Not to be retried blindly: if the answer is lost, read the category again (somcp_get_category '
                . 'when the shop offers it, otherwise somcp_list_categories) and compare before calling again.',
            self::inputSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Changing a category is the edit right of the categories page in
            // Team, Permissions; creating one is a different right.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminCategories')
        );
    }

    /**
     * The creation schema, made to name an existing category and to keep what is left out.
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

        $properties['name']['description'] = 'Merchant. 128 characters at most, never empty, without < > { } (nor '
            . '; = # before PrestaShop 9.1). One text per language being filled, keyed by ISO code: {"fr": "...", "en": "..."}.';
        $properties['id_parent']['description'] = 'Merchant, from the shop: moves the category under this one, from '
            . 'somcp_list_categories; never the root category, the category itself or one of its subcategories. '
            . 'Refused on Home.' . $kept;
        $properties['active']['description'] = 'Merchant. Displayed on the storefront.' . $kept;
        $properties['groups']['description'] = 'Merchant, from the shop: the customer groups that can see the '
            . 'category, from somcp_list_customer_groups. Given, it replaces the current set entirely.' . $kept;
        $properties['link_rewrite']['description'] = 'Assistant. The friendly URL, 128 characters at most, only in '
            . 'the languages given; never made again from a new name. On a displayed category the old address '
            . 'stops working: tell the merchant first. One text per language being filled, keyed by ISO code: '
            . '{"fr": "...", "en": "..."}.';
        $properties['redirect_type']['description'] = 'Assistant. PrestaShop 9 only: what the category page does '
            . 'while it is not displayed - 301 or 302 (redirect), 404 or 410.' . $kept;
        $properties['id_type_redirected']['description'] = 'Assistant. PrestaShop 9 only: the category a 301 or 302 '
            . 'redirects to, from somcp_list_categories; 0 for the closest parent.' . $kept;
        $properties['cover_image']['description'] = 'Merchant. Public https address of a new cover image, which '
            . 'replaces the current one. Downloaded by the shop: no redirect, 10 MB at most, JPEG, PNG, GIF or WebP.';
        $properties['menu_thumbnails']['description'] = 'Merchant. PrestaShop 8 only, refused on 9, which dropped '
            . 'them: up to 3 public https addresses, which replace the current menu thumbnails, downloaded like the cover.';
        $properties['position'] = [
            'type' => 'integer',
            'description' => 'Merchant. The place among the categories of the same parent, from 0 as somcp_get_category '
                . 'gives it (the back office list shows it plus 1); the others shift. With id_parent, the place under '
                . 'the new parent. Refused on Home.' . $kept,
        ];
        $properties['remove_cover_image'] = [
            'type' => 'boolean',
            'description' => 'Merchant. true removes the cover image. Not with cover_image, which already replaces it.',
        ];
        $properties['remove_menu_thumbnails'] = [
            'type' => 'boolean',
            'description' => 'Merchant. PrestaShop 8 only: true removes every menu thumbnail. Not with '
                . 'menu_thumbnails, which already replaces them.',
        ];

        $schema['properties'] = [
            'id_category' => ['type' => 'integer', 'description' => 'The category to change, from somcp_list_categories.'],
        ] + $properties;
        $schema['required'] = ['id_category'];

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
        $shop = Somcp::currentShop();
        $idShop = (int) $shop->id;
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $category = self::existingCategory(self::integer($arguments['id_category'] ?? null) ?? 0, $idShop);
        if ($category === null) {
            return SomcpTool::text('No category of this shop has this identifier. Nothing was changed. Find it with somcp_list_categories.', true);
        }
        // The Home of this shop has a form of its own in the back office and is
        // changed with its fields; the root, and the Home of another shop, are
        // not offered there at all.
        $isHome = (int) $category->id === (int) $shop->id_category;
        if (!$isHome && self::isTop($category, $shop)) {
            return SomcpTool::text(
                'Category ' . (int) $category->id . ' is the root category or the Home of another shop, which is '
                    . 'not changed here. Nothing was changed. The merchant changes it in the back office.',
                true
            );
        }

        $errors = [];
        $plan = self::planUpdate($arguments, $category, $shop, $isHome, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
        }

        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_category' => (int) $category->id,
                'reason' => 'The category already holds every value given. Nothing was written.',
            ]);
        }

        return self::asEmployee($idEmployee, static function () use ($category, $plan, $idShop, $idEmployee): array {
            return self::applyUpdate($category, $plan, $idShop, $idEmployee);
        });
    }

    /**
     * The category as this shop holds it, every language loaded.
     *
     * Public because somcp_get_category decides with it what exists, so a
     * category this tool would refuse is never one that tool describes.
     *
     * @param int $idCategory
     * @param int $idShop
     *
     * @return Category|null
     */
    public static function existingCategory(int $idCategory, int $idShop): ?Category
    {
        if ($idCategory <= 0) {
            return null;
        }
        // A category of another shop is answered like one that does not exist.
        $inShop = (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'category_shop` WHERE `id_category` = ' . (int) $idCategory
            . ' AND `id_shop` = ' . (int) $idShop
        );
        if (!$inShop) {
            return null;
        }
        $category = new Category($idCategory, null, $idShop);

        return Validate::isLoadedObject($category) ? $category : null;
    }

    /**
     * Whether the category is the root of the tree or the top category of a shop.
     *
     * Public because somcp_set_category_active refuses the same categories,
     * so hiding one is never allowed where changing it is not.
     *
     * @param Category $category
     * @param Shop $shop
     *
     * @return bool
     */
    public static function isTop(Category $category, Shop $shop): bool
    {
        return (int) $category->id === (int) Configuration::get('PS_ROOT_CATEGORY')
            || (int) $category->id_parent === 0
            || (bool) $category->is_root_category
            || (int) $category->id === (int) $shop->id_category;
    }

    /**
     * Turns the arguments into what will be written, or says everything that is wrong with them.
     *
     * Nothing is written here. A value equal to what the shop holds is left
     * out of the plan, so what remains is exactly what will change.
     *
     * @param array<string, mixed> $arguments
     * @param Category $category
     * @param Shop $shop
     * @param bool $isHome whether the category is the Home of this shop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function planUpdate(array $arguments, Category $category, Shop $shop, bool $isHome, array &$errors): ?array
    {
        $idShop = (int) $shop->id;
        $idCategory = (int) $category->id;
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::ARGUMENTS, true) && !in_array($key, self::OWN_ARGUMENTS, true)) {
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
            'fields' => [],
            'texts' => [],
            'changes' => [],
            'touched' => [],
            'moved' => [],
            'category' => [],
            'groups' => null,
            'position' => null,
            'images' => self::imageUrls($arguments, $errors),
            'remove_cover' => false,
            'remove_thumbnails' => false,
            'replace_thumbnails' => false,
        ];

        if ($isHome) {
            self::homeLimits($arguments, $errors);
        }

        // Never planned on Home: a moved top would leave every category of the
        // shop unreachable, and the form of Home has no parent.
        if (!$isHome && array_key_exists('id_parent', $arguments)) {
            $idParent = self::parentCategory($arguments, $shop, $errors);
            if ($idParent === $idCategory || self::isBelow($idParent, $idCategory)) {
                $errors[] = 'id_parent: a category cannot be moved under itself or one of its subcategories';
            } else {
                $plan['fields']['id_parent'] = $idParent;
            }
        }

        if (array_key_exists('active', $arguments)) {
            // A boolean only: "false" cast to bool is true, and would display a
            // category the merchant asked to hide.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } else {
                $plan['fields']['active'] = $arguments['active'];
            }
        }

        self::categoryNames($arguments, $languages, $probe, $plan, $errors, false);
        self::categoryDescriptions($arguments, $languages, $probe, $plan, $errors);
        self::categoryMeta($arguments, $languages, $probe, $plan, $errors);

        // Unlike at creation, the friendly URL is not made again from a new
        // name: the page already has an address, and moving it is a decision.
        if (array_key_exists('link_rewrite', $arguments)) {
            $rewrites = self::categoryLinkRewrites($arguments, $languages, ['texts' => []], $errors);
            if ($rewrites !== []) {
                $plan['texts']['link_rewrite'] = $rewrites;
            }
        }

        if (!$isHome && array_key_exists('position', $arguments)) {
            self::planPosition($arguments['position'], $category, $idShop, $plan, $errors);
        }

        self::planRedirect($arguments, $category, $idShop, $plan, $errors);

        $groups = $arguments['groups'] ?? null;
        if ($groups !== null) {
            $plan['groups'] = self::customerGroups($groups, $errors);
        }

        self::planRemovals($arguments, $plan, $errors);

        if ($errors !== []) {
            return $plan;
        }

        self::compareCategory($category, $plan, $idShop);

        // The same check the core runs in update(), on the whole object as it
        // will be written, as a last net for a rule none of the above knew.
        if ($plan['category'] !== []) {
            $candidate = clone $category;
            self::fill($candidate, $plan['category']);
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'category: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * What the form of Home in the back office does not offer, refused on it.
     *
     * @param array<string, mixed> $arguments
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function homeLimits(array $arguments, array &$errors): void
    {
        if (array_key_exists('id_parent', $arguments)) {
            $errors[] = 'id_parent: Home stays at the top of the shop\'s tree, it cannot be moved';
        }
        if (array_key_exists('position', $arguments)) {
            $errors[] = 'position: Home stays at the top of the shop\'s tree, it has no place among other categories';
        }
        // On PrestaShop 8 the redirection fields are refused for every
        // category by the creation's rules; only 9 needs the narrower check.
        if (!isset(Category::$definition['fields']['redirect_type'])) {
            return;
        }
        if (array_key_exists('redirect_type', $arguments)
            && in_array((string) self::string($arguments['redirect_type']), ['301', '302'], true)) {
            $errors[] = 'redirect_type: Home only answers 404 or 410 while it is not displayed, as its form in the back office offers';
        }
        if (array_key_exists('id_type_redirected', $arguments)) {
            $errors[] = 'id_type_redirected: Home is never redirected; leave the field out';
        }
    }

    /**
     * The new place of the category among its siblings, checked against how many there are.
     *
     * The siblings are those of the parent it will have, so a position given
     * with id_parent is a place under the new parent.
     *
     * @param mixed $value
     * @param Category $category
     * @param int $idShop
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function planPosition($value, Category $category, int $idShop, array &$plan, array &$errors): void
    {
        $idParent = (int) ($plan['fields']['id_parent'] ?? $category->id_parent);
        // Counted in the shop of the token, the positions the list shows there.
        $others = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'category` c
            INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . (int) $idShop . '
            WHERE c.`id_parent` = ' . (int) $idParent . ' AND c.`id_category` != ' . (int) $category->id
        );
        $position = self::integer($value);
        if ($position === null || $position < 0 || $position > $others) {
            $errors[] = 'position: a whole number from 0 to ' . $others . ', the place among the categories of the same parent';

            return;
        }
        $plan['position'] = $position;
    }

    /**
     * Where the category stands among its siblings in the shop, from 0.
     *
     * @param int $idCategory
     * @param int $idShop
     *
     * @return int
     */
    private static function currentPosition(int $idCategory, int $idShop): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT `position` FROM `' . _DB_PREFIX_ . 'category_shop` WHERE `id_category` = ' . (int) $idCategory
            . ' AND `id_shop` = ' . (int) $idShop
        );
    }

    /**
     * Whether a category sits somewhere below another one.
     *
     * Walked up through id_parent rather than read from nleft and nright,
     * which are only as fresh as the last time the tree was rebuilt.
     *
     * @param int $idCategory the category that would become the parent
     * @param int $idAncestor the category being moved
     *
     * @return bool
     */
    private static function isBelow(int $idCategory, int $idAncestor): bool
    {
        $db = Db::getInstance();
        $seen = [];
        $id = $idCategory;
        // The seen list stops a tree that loops, which a broken import can leave.
        while ($id > 0 && !isset($seen[$id])) {
            if ($id === $idAncestor) {
                return true;
            }
            $seen[$id] = true;
            $id = (int) $db->getValue(
                'SELECT `id_parent` FROM `' . _DB_PREFIX_ . 'category` WHERE `id_category` = ' . (int) $id
            );
        }

        return false;
    }

    /**
     * What the page does while hidden, read with the creation's rules against what the category already does.
     *
     * @param array<string, mixed> $arguments
     * @param Category $category
     * @param int $idShop
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function planRedirect(array $arguments, Category $category, int $idShop, array &$plan, array &$errors): void
    {
        $typeGiven = array_key_exists('redirect_type', $arguments);
        $targetGiven = array_key_exists('id_type_redirected', $arguments);
        if (!$typeGiven && !$targetGiven) {
            return;
        }

        // Read from the object's properties: the fields exist on PrestaShop 9
        // only, and a property access is unknown to static analysis on 8.
        $properties = get_object_vars($category);
        $current = isset($properties['redirect_type']) ? (string) $properties['redirect_type'] : null;
        $count = count($errors);
        self::categoryRedirect($arguments, $idShop, $plan, $errors, $current);
        if (count($errors) > $count || !isset(Category::$definition['fields']['redirect_type'])) {
            return;
        }

        $type = (string) ($plan['fields']['redirect_type'] ?? $current);
        // The core accepts a target only next to a redirection, so one left
        // from a former 301 is cleared when the page now answers 404 or 410;
        // and an explicit 0 asks for the closest parent again.
        if (!in_array($type, ['301', '302'], true) || ($targetGiven && self::integer($arguments['id_type_redirected']) === 0)) {
            $plan['fields']['id_type_redirected'] = 0;
        }
        if ((int) ($plan['fields']['id_type_redirected'] ?? 0) === (int) $category->id) {
            $errors[] = 'id_type_redirected: a category cannot redirect to itself';
        }
    }

    /**
     * The explicit removals of images, each a boolean, never next to the field that already replaces them.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $plan
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function planRemovals(array $arguments, array &$plan, array &$errors): void
    {
        foreach (['remove_cover_image' => 'cover_image', 'remove_menu_thumbnails' => 'menu_thumbnails'] as $flag => $field) {
            if (!array_key_exists($flag, $arguments)) {
                continue;
            }
            if (!is_bool($arguments[$flag])) {
                $errors[] = $flag . ': true or false';
                continue;
            }
            if (!$arguments[$flag]) {
                continue;
            }
            if (array_key_exists($field, $arguments)) {
                $errors[] = $flag . ': not with ' . $field . ', which already replaces the current one';
                continue;
            }
            if ($flag === 'remove_menu_thumbnails'
                && !class_exists('PrestaShop\PrestaShop\Core\Domain\Category\ValueObject\MenuThumbnailId')) {
                $errors[] = 'remove_menu_thumbnails: this PrestaShop version no longer has menu thumbnails; leave the field out';
                continue;
            }
            $plan[$flag === 'remove_cover_image' ? 'remove_cover' : 'remove_thumbnails'] = true;
        }
    }

    /**
     * Keeps the fields, texts, groups and images that differ from the shop, and records each one.
     *
     * @param Category $category
     * @param array<string, mixed> $plan
     * @param int $idShop
     *
     * @return void
     */
    private static function compareCategory(Category $category, array &$plan, int $idShop): void
    {
        $languages = $plan['languages'];
        $properties = get_object_vars($category);

        foreach ($plan['fields'] as $field => $value) {
            $old = $properties[$field] ?? null;
            $same = is_bool($value) ? (bool) $old === $value
                : (is_int($value) ? (int) $old === $value : (string) $old === (string) $value);
            if ($same) {
                continue;
            }
            $plan['category'][$field] = $value;
            $shown = is_bool($value) ? (bool) $old : (is_int($value) ? (int) $old : ($old === null ? null : (string) $old));
            self::record($plan, $field, $shown, $value);
        }

        foreach ($plan['texts'] as $field => $texts) {
            $current = is_array($properties[$field] ?? null) ? $properties[$field] : [];
            $merged = $current;
            foreach ($texts as $idLang => $text) {
                $old = (string) ($current[$idLang] ?? '');
                if ($old === $text) {
                    continue;
                }
                $merged[$idLang] = $text;
                self::record($plan, $field . ' (' . $languages['filled'][$idLang] . ')', $old, $text);
                $plan['touched'][$idLang] = true;
                if ($field === 'link_rewrite') {
                    $plan['moved'][$idLang] = true;
                }
            }
            if ($merged !== $current) {
                $plan['category'][$field] = $merged;
            }
        }

        if ($plan['groups'] !== null) {
            $old = self::sortedIds($category->getGroups());
            $new = self::sortedIds($plan['groups']);
            if ($old === $new) {
                $plan['groups'] = null;
            } else {
                self::record($plan, 'groups', $old, $new);
            }
        }

        if ($plan['position'] !== null) {
            $old = self::currentPosition((int) $category->id, $idShop);
            // Under a new parent the place always changes, even with the same number.
            if ($old === $plan['position'] && !isset($plan['category']['id_parent'])) {
                $plan['position'] = null;
            } else {
                self::record($plan, 'position', $old, $plan['position']);
            }
        }

        if ($plan['images'] === [] && !$plan['remove_cover'] && !$plan['remove_thumbnails']) {
            return;
        }
        $before = self::category((int) $category->id, (int) $languages['fallback'], $idShop);
        // An address cannot be compared with a stored file, so an image given
        // always counts as a change; a removal counts only when there is one.
        $covers = array_values(array_filter($plan['images'], static function (array $image): bool {
            return $image['for'] === 'cover_image';
        }));
        if ($covers !== []) {
            self::record($plan, 'cover_image', $before['cover_image'], $covers[0]['url']);
        } elseif ($plan['remove_cover']) {
            if ($before['cover_image'] === null) {
                $plan['remove_cover'] = false;
            } else {
                self::record($plan, 'cover_image', $before['cover_image'], null);
            }
        }
        $thumbnails = array_values(array_map(static function (array $image): string {
            return $image['url'];
        }, array_filter($plan['images'], static function (array $image): bool {
            return $image['for'] === 'menu_thumbnails';
        })));
        $plan['replace_thumbnails'] = $thumbnails !== [];
        if ($thumbnails !== []) {
            self::record($plan, 'menu_thumbnails', $before['menu_thumbnails'], $thumbnails);
        } elseif ($plan['remove_thumbnails']) {
            if (empty($before['menu_thumbnails'])) {
                $plan['remove_thumbnails'] = false;
            } else {
                self::record($plan, 'menu_thumbnails', $before['menu_thumbnails'], []);
            }
        }
    }

    /**
     * Writes the change, then the images, and answers with what was stored.
     *
     * @param Category $category
     * @param array<string, mixed> $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function applyUpdate(Category $category, array $plan, int $idShop, int $idEmployee): array
    {
        $idCategory = (int) $category->id;
        if ($plan['category'] !== [] || $plan['groups'] !== null) {
            self::fill($category, $plan['category']);
            // Null keeps the group access as it is: update() only rewrites it
            // when a list is given.
            $category->groupBox = $plan['groups'];
            // The shop of the token only: category_shop and category_lang of
            // the other shops keep their own values.
            $category->id_shop_list = [$idShop];

            try {
                $done = $category->update();
                $failure = $done ? '' : 'PrestaShop refused to save it';
            } catch (Throwable $e) {
                $done = false;
                $failure = $e->getMessage();
            }
            if (!$done) {
                SomcpLogger::log(
                    'category_update_failed',
                    SomcpLogger::OUTCOME_FAILURE,
                    'category ' . $idCategory . ': ' . Tools::substr($failure, 0, 500),
                    '',
                    $idEmployee
                );

                return SomcpTool::text(
                    'The category could not be changed: ' . $failure . '. The write may be partly done and no image '
                        . 'was touched. Read the category again, tell the merchant what it holds now, and do not '
                        . 'call again before.',
                    true
                );
            }
            // getGroups() keeps what it read for the rest of the request, and
            // the answer must show the access just written.
            Cache::clean('Category::getGroups_' . $idCategory);
        }

        if ($plan['position'] !== null) {
            $failure = self::applyPosition($idCategory, (int) $plan['position'], $idShop);
            if ($failure !== '') {
                SomcpLogger::log(
                    'category_update_failed',
                    SomcpLogger::OUTCOME_FAILURE,
                    'category ' . $idCategory . ' position: ' . Tools::substr($failure, 0, 500),
                    '',
                    $idEmployee
                );

                return SomcpTool::text(
                    'The category could not be moved to position ' . (int) $plan['position'] . ': ' . $failure
                        . '. The other changes of this call may be saved and no image was touched. Read the category '
                        . 'again, tell the merchant what it holds now, and do not call again before.',
                    true
                );
            }
        }

        $images = self::applyImages($category, $plan);

        $stored = new Category($idCategory, null, $idShop);
        $warnings = self::updateWarnings($stored, $plan, $idShop);

        SomcpLogger::log(
            'category_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'category ' . $idCategory . ', ' . count($plan['changes']) . ' change(s)',
            '',
            $idEmployee
        );

        return SomcpTool::json([
            'changed' => true,
            'id_category' => $idCategory,
            'changes' => $plan['changes'],
            'seo_warnings' => $warnings === [] ? new stdClass() : $warnings,
            'images' => $images,
            // Read back rather than echoed from the arguments: what the
            // assistant shows the merchant is what the shop stored.
            'category' => self::category($idCategory, (int) $plan['languages']['fallback'], $idShop),
        ]);
    }

    /**
     * Moves the category to its new place, as the drag and drop of the back office list does.
     *
     * Category::updatePosition() shifts the siblings and fires
     * actionCategoryUpdate. It runs after update(), which has already put a
     * category given a new parent at the end of its new siblings.
     *
     * @param int $idCategory
     * @param int $position
     * @param int $idShop
     *
     * @return string empty on success, the reason otherwise
     */
    private static function applyPosition(int $idCategory, int $position, int $idShop): string
    {
        $current = self::currentPosition($idCategory, $idShop);
        if ($current === $position) {
            return '';
        }
        // Loaded again so id_parent is the one just written.
        $category = new Category($idCategory, null, $idShop);
        try {
            return $category->updatePosition($position > $current, $position) ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Removes what was asked to go, then stores the images given, each one reported.
     *
     * @param Category $category
     * @param array<string, mixed> $plan
     *
     * @return array<int, array<string, mixed>>
     */
    private static function applyImages(Category $category, array $plan): array
    {
        $idCategory = (int) $category->id;
        $report = [];

        if ($plan['remove_cover']) {
            // What the back office does to delete a cover: the original, the
            // file of every image type and the cached copies, all of it in
            // ObjectModel::deleteImage(), forced so multistore does not keep it.
            try {
                $removed = $category->deleteImage(true);
            } catch (Throwable $e) {
                $removed = false;
            }
            $report[] = ['for' => 'cover_image'] + ($removed ? ['removed' => true] : ['refused' => 'the shop could not delete it']);
        }

        if ($plan['remove_thumbnails'] || $plan['replace_thumbnails']) {
            // Given thumbnails replace the current ones, which go first so the
            // new ones take the slots from 0, as the back office numbers them.
            $left = 0;
            for ($slot = 0; $slot < self::MENU_THUMBNAILS_MAX; ++$slot) {
                $file = _PS_CAT_IMG_DIR_ . $idCategory . '-' . $slot . '_thumb.jpg';
                if (is_file($file) && !@unlink($file)) {
                    ++$left;
                }
            }
            // The main menu is cached by Smarty and would keep showing them.
            Tools::clearSmartyCache();
            if ($plan['remove_thumbnails']) {
                $report[] = ['for' => 'menu_thumbnails'] + ($left === 0 ? ['removed' => true] : ['refused' => 'the shop could not delete ' . $left . ' of them']);
            }
        }

        // A new cover is written over the current files, under the same names,
        // rather than after deleting them: if it cannot be fetched, the
        // category keeps the cover it had.
        return array_merge($report, self::storeImages($idCategory, $plan['images']));
    }

    /**
     * The creation's SEO warnings for the languages whose texts changed, plus a moved address.
     *
     * @param Category $stored the category as stored after the change
     * @param array<string, mixed> $plan
     * @param int $idShop
     *
     * @return array<string, array<int, string>>
     */
    private static function updateWarnings(Category $stored, array $plan, int $idShop): array
    {
        $touched = array_intersect_key($plan['languages']['filled'], $plan['touched']);
        if ($touched === []) {
            return [];
        }

        // On the texts as they are stored now rather than on the ones this
        // call gave, since a language may have been changed only in part.
        $properties = get_object_vars($stored);
        $texts = [];
        foreach (['meta_title', 'meta_description', 'link_rewrite'] as $field) {
            $texts[$field] = is_array($properties[$field] ?? null) ? $properties[$field] : [];
        }
        $warnings = self::categoryWarnings((int) $stored->id, ['languages' => ['filled' => $touched], 'texts' => $texts], $idShop);

        if ((bool) $stored->active) {
            foreach (array_keys($plan['moved']) as $idLang) {
                if (isset($touched[$idLang])) {
                    $warnings[$touched[$idLang]][] = 'link_rewrite changed on a displayed category: the old address of its page no longer works';
                }
            }
        }

        return $warnings;
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

    /**
     * @param array<int, mixed> $ids
     *
     * @return array<int, int>
     */
    private static function sortedIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }
}
