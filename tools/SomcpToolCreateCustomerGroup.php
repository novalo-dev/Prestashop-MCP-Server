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
 * somcp_create_customer_group - Add new group of Shop Parameters > Customer
 * Settings > Groups, then Save.
 *
 * The same legacy AdminGroupsController on 8.0 and 9.0 checks the fields
 * with the rules somcp_update_customer_group applies (planGroup(),
 * planModules()), adds the Group object, then saves the Modules
 * authorization block, which on a new group lists every installed module as
 * authorized. The core writes the Category discount rows before the group
 * exists, with id_group 0, so the screen loses them; the tool writes them to
 * the new group, in the same transaction.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The languages and texts of #149, shared with every create tool.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
// The fields are checked by the rules of the Edit form.
require_once __DIR__ . '/SomcpToolUpdateCustomerGroup.php';
// The answer is the group as the read gives it.
require_once __DIR__ . '/SomcpToolGetCustomerGroup.php';

class SomcpToolCreateCustomerGroup extends SomcpToolCreateProduct
{
    /** @var array<int, string> The fields this tool takes. */
    private const FIELDS = ['languages', 'name', 'reduction', 'price_display_method', 'show_prices', 'category_discounts', 'authorized_modules', 'unauthorized_modules'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_customer_group',
            Somcp::t('Create a customer group'),
            'Creates a customer group of Shop Parameters > Customer Settings > Groups, as Add new group then '
                . 'Save: name per language, Discount, Price display method, Show prices, Category discount and '
                . 'the modules authorized (every installed one by default). The new group sees the home category '
                . 'and the categories given a discount only: give it the others with somcp_update_category. Not '
                . 'replayable: read somcp_list_customer_groups when an answer is lost. To change a group use '
                . 'somcp_update_customer_group; to put a customer in it, somcp_update_customer.',
            [
                'type' => 'object',
                'properties' => [
                    'languages' => [
                        'type' => 'array',
                        'description' => 'ISO codes of the languages to fill, among the active languages of the '
                            . 'shop. All of them by default; the others take the text of the default language.',
                        'items' => ['type' => 'string'],
                    ],
                    'name' => [
                        'type' => 'object',
                        'description' => 'Name, required, 32 characters at most, without < > = { }. One text per '
                            . 'language being filled, keyed by ISO code: {"fr": "...", "en": "..."}.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'reduction' => [
                        'type' => 'number',
                        'description' => 'Discount, in percent as shown (5 for 5 %), from 0 to 100, 2 decimals at '
                            . 'most: applied to every product for the members of the group. 0 by default.',
                    ],
                    'price_display_method' => [
                        'type' => 'string',
                        'enum' => array_keys(SomcpToolUpdateCustomerGroup::PRICE_DISPLAY),
                        'description' => 'Price display method: how prices are shown in the order summary for '
                            . 'this group. The default of the shop when left out, as the form presets it.',
                    ],
                    'show_prices' => [
                        'type' => 'boolean',
                        'description' => 'Show prices: whether the members of the group see prices. true by default.',
                    ],
                    'category_discounts' => [
                        'type' => 'array',
                        'description' => 'Category discount: the list of {id_category, reduction}, none by default. '
                            . 'id_category from somcp_list_categories, reduction in percent (0 to 100), as '
                            . 'somcp_get_customer_group reads it.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id_category' => ['type' => 'integer'],
                                'reduction' => ['type' => 'number'],
                                'category' => ['type' => ['string', 'null']],
                            ],
                            'required' => ['id_category', 'reduction'],
                        ],
                    ],
                    'authorized_modules' => [
                        'type' => 'array',
                        'description' => 'Modules authorization: the modules set to Yes, by technical name of '
                            . 'somcp_list_modules; every installed module by default, every one left out set to No.',
                        'items' => ['type' => 'string'],
                    ],
                    'unauthorized_modules' => [
                        'type' => 'array',
                        'description' => 'Read by somcp_get_customer_group: taken only when it is exactly the '
                            . 'installed modules left out of authorized_modules, so a group read can be given to '
                            . 'create a copy.',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => ['name'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::ADD, 'AdminGroups')
        );
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
        $idShopGroup = (int) $shop->id_shop_group;
        $idEmployee = (int) ($context['id_employee'] ?? 0);
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);

        // An item of the list given back is not a creation: its id_group is
        // named as unknown rather than ignored.
        $errors = SomcpTool::unknownFields($arguments, self::FIELDS);
        $installed = [];
        foreach (Module::getModulesInstalled() ?: [] as $row) {
            $installed[(int) $row['id_module']] = (string) $row['name'];
        }
        // A new group starts with every installed module authorized, as the
        // form shows it: the list given replaces that one.
        $everyModule = array_values($installed);
        sort($everyModule);
        $modules = SomcpToolUpdateCustomerGroup::planModules($arguments, ['authorized_modules' => $everyModule], $errors);
        $given = $arguments;
        unset($given['authorized_modules'], $given['unauthorized_modules']);
        $plan = SomcpToolUpdateCustomerGroup::planGroup($given, $idShop, $errors, true);
        if ($errors !== [] || $plan === null || !isset($plan['name'])) {
            return SomcpTool::refused('created', $errors);
        }
        $modules = $modules ?? $installed;
        $discounts = $plan['category_discounts'] ?? [];

        // Left out, the fields take what the Add new group form presets.
        $method = $plan['price_display_method']
            ?? ((int) Configuration::get('PRICE_DISPLAY_METHOD', null, $idShopGroup, $idShop) === 0 ? 'tax_included' : 'tax_excluded');
        $group = new Group();
        $group->name = self::complete($plan['name'], $plan['languages']);
        $group->reduction = $plan['reduction'] ?? 0;
        $group->price_display_method = SomcpToolUpdateCustomerGroup::PRICE_DISPLAY[$method];
        $group->show_prices = $plan['show_prices'] ?? true;
        // The shop of the token only, never the context's list, as
        // somcp_create_store does.
        $group->id_shop_list = [$idShop];

        // Read before the write: the warnings name what the group is left without.
        $paymentsLeftOut = SomcpToolUpdateCustomerGroup::paymentModules(array_values(array_diff($installed, $modules)), $idShop);
        $home = (int) Configuration::get('PS_HOME_CATEGORY', null, $idShopGroup, $idShop);
        $opened = [];
        foreach (array_keys($discounts) as $idCategory) {
            if ((int) $idCategory !== $home) {
                $category = new Category((int) $idCategory, $idLang, $idShop);
                $opened[] = is_string($category->name) ? $category->name : (string) $idCategory;
            }
        }

        $db = Db::getInstance();
        // One transaction for the group and its rows, as the update writes
        // its lists: a group with no row of module_group sees no module on
        // the storefront, so none is ever left without them.
        $transaction = (bool) $db->execute('START TRANSACTION');
        $idGroup = 0;
        try {
            $done = $transaction;
            $failure = $done ? '' : 'The customer group could not be created: the database refused to open a transaction';
            if ($done && !$group->add()) {
                $done = false;
                $failure = 'The customer group could not be created: PrestaShop refused to save it';
            }
            $idGroup = (int) $group->id;
            if ($done && $discounts !== []) {
                $failure = SomcpToolUpdateCustomerGroup::writeDiscounts($idGroup, $discounts);
                $done = $failure === '';
                if (!$done) {
                    $failure = 'The category discounts could not be saved: ' . $failure;
                }
            }
            // The shop of the token only: the core passes the shops of the
            // context, and a token acts for one shop.
            if ($done && ($modules === [] || !Group::addModulesRestrictions($idGroup, array_keys($modules), [$idShop]))) {
                $done = false;
                $failure = 'The authorized modules could not be saved';
            }
        } catch (Throwable $e) {
            $done = false;
            $failure = 'The customer group could not be created: ' . $e->getMessage();
        }
        if ($transaction) {
            $db->execute($done ? 'COMMIT' : 'ROLLBACK');
        }
        if (!$done) {
            SomcpLogger::log('customer_group_create_failed', SomcpLogger::OUTCOME_FAILURE, Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text($failure . '. Nothing was created.', true);
        }
        // The storefront keeps the modules of each hook per group in cache.
        Cache::clean(Hook::MODULE_LIST_BY_HOOK_KEY . '*');
        // The identifier only: the name is the merchant's text.
        SomcpLogger::log('customer_group_created', SomcpLogger::OUTCOME_SUCCESS, 'group ' . $idGroup, '', $idEmployee);

        $result = [
            'created' => true,
            'id_group' => $idGroup,
            // Read back by the read itself: the creation answers what
            // somcp_get_customer_group answers.
            'group' => SomcpToolGetCustomerGroup::group($idGroup, $idShop, $idShopGroup, $idLang),
        ];
        // Group::add() gives the new group the home category only, and each
        // category given a discount is opened to it.
        $warnings = ['The group sees only the home category' . ($opened !== [] ? ' and ' . implode(', ', $opened) : '')
            . ': give it the other categories with the groups field of somcp_update_category, or its members see no '
            . 'product of them.'];
        if ($paymentsLeftOut !== []) {
            $warnings[] = 'The customers of this group no longer see these payment modules: '
                . implode(', ', $paymentsLeftOut) . '.';
        }
        if (((float) $group->reduction > 0 || $discounts !== []) && !(bool) Configuration::get('PS_GROUP_FEATURE_ACTIVE', null, $idShopGroup, $idShop)) {
            $warnings[] = SomcpToolGetCustomerGroup::GROUPS_DISABLED;
        }
        if (!$group->show_prices && !(bool) Configuration::get('PS_CATALOG_MODE', null, $idShopGroup, $idShop)) {
            $warnings[] = 'Customers of this group no longer see prices; to hide them for every group, turn on '
                . 'catalog mode in Product Settings instead.';
        }
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'The group was created for this shop only.';
        }
        $result['warning'] = implode(' ', $warnings);

        return SomcpTool::json($result);
    }
}
