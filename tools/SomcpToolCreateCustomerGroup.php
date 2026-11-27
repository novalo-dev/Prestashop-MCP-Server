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
 * with the rules somcp_update_customer_group applies (planGroup()), adds the
 * Group object, then saves the Modules authorization block, which on a new
 * group lists every installed module as authorized.
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
    private const FIELDS = ['languages', 'name', 'reduction', 'price_display_method', 'show_prices'];

    /** @var array<int, string> Fields of the read the form saves too, not taken by this tool yet. */
    private const LATER = ['category_discounts', 'authorized_modules', 'unauthorized_modules'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_customer_group',
            Somcp::t('Create a customer group'),
            'Creates a customer group of Shop Parameters > Customer Settings > Groups, as Add new group then '
                . 'Save: its name per language, its Discount, its Price display method and Show prices, every '
                . 'installed module authorized. The new group sees the home category only: give it the others '
                . 'with somcp_update_category. Not replayable: read somcp_list_customer_groups when an answer is '
                . 'lost. To change a group use somcp_update_customer_group; to put a customer in it, '
                . 'somcp_update_customer.',
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
        $errors = SomcpTool::unknownFields($arguments, array_merge(self::FIELDS, self::LATER));
        $given = $arguments;
        foreach (self::LATER as $field) {
            if (array_key_exists($field, $given)) {
                $errors[] = $field . ': not taken by this tool yet - create the group, then use somcp_update_customer_group';
                unset($given[$field]);
            }
        }
        $plan = SomcpToolUpdateCustomerGroup::planGroup($given, $idShop, $errors, true);
        if ($errors !== [] || $plan === null || !isset($plan['name'])) {
            return SomcpTool::refused('created', $errors);
        }

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

        try {
            $done = (bool) $group->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('customer_group_create_failed', SomcpLogger::OUTCOME_FAILURE, Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text('The customer group could not be created: ' . $failure . '.', true);
        }
        $idGroup = (int) $group->id;

        // What the form saves for a new group with nothing unticked. A group
        // with no row of module_group sees no module on the storefront, so
        // one whose list cannot be saved is taken away again.
        $modules = array_map('intval', array_column(Module::getModulesInstalled() ?: [], 'id_module'));
        $saved = false;
        try {
            $saved = $modules !== [] && Group::addModulesRestrictions($idGroup, $modules, [$idShop]);
        } catch (Throwable $e) {
            $saved = false;
        }
        if (!$saved) {
            $group->delete();
            SomcpLogger::log('customer_group_create_failed', SomcpLogger::OUTCOME_FAILURE, 'the authorized modules', '', $idEmployee);

            return SomcpTool::text('The customer group could not be created: the authorized modules could not be saved.', true);
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
        // Group::add() gives the new group the home category only.
        $warnings = ['The group sees only the home category: give it the other categories with the groups field of '
            . 'somcp_update_category, or its members see no product of them.'];
        if ((float) $group->reduction > 0 && !(bool) Configuration::get('PS_GROUP_FEATURE_ACTIVE', null, $idShopGroup, $idShop)) {
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
