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
 * somcp_get_customer - the page of one customer, Customers > Customers > View.
 *
 * Every block of that page is read from the same core methods as its handler
 * (Customer::getStats(), getGroups(), getAddresses(), getLastEmails(),
 * getLastConnections(), CartRule::getCustomerCartRules(),
 * CustomerThread::getCustomerMessages()). The blocks the two versions build
 * differently (purchased and viewed products, the total of valid orders) are
 * read one way on both, so the answer does not change with the version.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetCustomer
{
    /** @var int Rows given per list block, as the page shows its newest ones. */
    private const LATEST = 10;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_customer',
            Somcp::t('Get a customer'),
            'Returns everything the back office page of one customer of this shop shows '
                . '(Customers > Customers > View): the account (name, email, social title, birthday and '
                . 'age, registration, last visit, language, newsletter, partner offers, status, guest or '
                . 'not, private note, and the B2B fields), the groups, the addresses, a summary of the '
                . 'orders with the 10 newest, the 10 newest carts, purchased and viewed products, the '
                . 'vouchers reserved to the customer, the number of messages, the last emails sent and '
                . 'the last connections. Identify the customer by id_customer, or by an exact email; an '
                . 'email shared by several accounts is refused with the identifier of each. Never '
                . 'gives a password or a reset token. Do not use it to look for customers '
                . '(somcp_search_customers), or to read one of their orders (somcp_get_order); more '
                . 'orders come from somcp_search_orders with customer_email.',
            [
                'type' => 'object',
                'properties' => [
                    'id_customer' => [
                        'type' => 'integer',
                        'description' => 'Identifier of the customer, from somcp_search_customers or '
                            . 'somcp_get_order.',
                    ],
                    'email' => [
                        'type' => 'string',
                        'description' => 'Exact email address of the customer, used when no identifier '
                            . 'is known.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr, '
                            . 'for the social title, group, country, order status and product names; a '
                            . 'code the shop does not have is refused. The shop default language when '
                            . 'left out.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::CUSTOMERS_READ],
            [self::class, 'handle'],
            // The customer page of the back office is where a merchant reads
            // all of this by hand.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminCustomers')
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
        $idShop = (int) Somcp::currentShop()->id;
        $language = SomcpToolSearchCustomers::language($arguments, $idShop);
        if ($language === null) {
            return SomcpTool::text(
                'This language is not one of the shop\'s, which has '
                    . implode(', ', SomcpToolAuditProductSeo::languages([], $idShop) ?? [])
                    . '. Give the ISO code of one of them, or leave language out for the shop default.',
                true
            );
        }
        [$idLang, $iso] = $language;

        $found = self::locate($arguments, $idShop);
        if (is_string($found)) {
            return SomcpTool::text($found, true);
        }
        $customer = $found;

        $currencyIso = self::currencyIso($idShop);

        return SomcpTool::json([
            'customer' => self::account($customer, $idLang, $currencyIso),
            'groups' => self::groups($customer, $idLang),
            'addresses' => self::addresses($customer, $idLang),
            'orders' => self::orders($customer, $idShop, $idLang, $currencyIso),
            'carts' => self::carts($customer, $idShop),
            'purchased_products' => self::purchasedProducts($customer, $idShop),
            'viewed_products' => self::viewedProducts($customer, $idShop, $idLang),
            'vouchers' => self::vouchers($customer, $idLang),
            'messages_count' => count(CustomerThread::getCustomerMessages((int) $customer->id) ?: []),
            'last_emails' => self::lastEmails($customer),
            'last_connections' => self::lastConnections($customer),
            'language' => $iso,
        ]);
    }

    /**
     * The customer asked for, within the token's shop, or the message to answer.
     *
     * A customer deleted with their data kept is answered as unknown, as the
     * list hides them; so is a customer of another shop, whatever is shared.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     *
     * @return Customer|string
     */
    private static function locate(array $arguments, int $idShop)
    {
        if (isset($arguments['id_customer']) && is_numeric($arguments['id_customer']) && (int) $arguments['id_customer'] > 0) {
            $id = (int) $arguments['id_customer'];
            $customer = new Customer($id);
            if (!Validate::isLoadedObject($customer) || (int) $customer->id_shop !== $idShop || (int) $customer->deleted === 1) {
                return 'No customer ' . $id . ' in this shop. Find one with somcp_search_customers.';
            }

            return $customer;
        }

        $email = isset($arguments['email']) && is_string($arguments['email']) ? trim($arguments['email']) : '';
        if ($email === '') {
            return 'Give id_customer or email. Find a customer with somcp_search_customers.';
        }

        $query = new DbQuery();
        $query->select('c.`id_customer`');
        $query->from('customer', 'c');
        $query->where("c.`email` = '" . pSQL($email) . "'");
        $query->where('c.`id_shop` = ' . $idShop);
        $query->where('c.`deleted` = 0');
        $query->orderBy('c.`id_customer` ASC');
        $ids = array_map('intval', array_column(Db::getInstance()->executeS($query) ?: [], 'id_customer'));

        if ($ids === []) {
            return 'No customer with this email in this shop. Find one with somcp_search_customers.';
        }
        // A guest and a registered account can share an email: picking one
        // here could show the merchant the wrong person.
        if (count($ids) > 1) {
            return 'Several customers of this shop have this email: id_customer ' . implode(', ', $ids)
                . '. Ask again with the id_customer of the one meant.';
        }

        return new Customer($ids[0]);
    }

    /**
     * The ISO code of the shop's default currency, the one the amounts of the page are in.
     *
     * @param int $idShop
     *
     * @return string
     */
    public static function currencyIso(int $idShop): string
    {
        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop));

        return Validate::isLoadedObject($currency) ? (string) $currency->iso_code : '';
    }

    /**
     * The header block of the page, and the B2B fields of the edit form.
     *
     * Shared with somcp_create_customer, which answers with the account it
     * wrote in this same shape, so a creation reads like a detail.
     *
     * @param Customer $customer
     * @param int $idLang
     * @param string $currencyIso
     *
     * @return array<string, mixed>
     */
    public static function account(Customer $customer, int $idLang, string $currencyIso): array
    {
        $gender = new Gender((int) $customer->id_gender, $idLang);
        $stats = $customer->getStats();
        $birthday = (string) $customer->birthday;
        $age = $stats['age'] ?? null;
        $idRisk = (int) $customer->id_risk;
        $risk = $idRisk > 0
            ? (string) Db::getInstance()->getValue('SELECT `name` FROM `' . _DB_PREFIX_ . 'risk_lang` WHERE `id_risk` = ' . $idRisk . ' AND `id_lang` = ' . $idLang)
            : '';

        return [
            'id_customer' => (int) $customer->id,
            'firstname' => (string) $customer->firstname,
            'lastname' => (string) $customer->lastname,
            'email' => (string) $customer->email,
            'id_gender' => (int) $customer->id_gender,
            'social_title' => Validate::isLoadedObject($gender) ? (string) $gender->name : '',
            'birthday' => $birthday === '' || strpos($birthday, '0000-00-00') === 0 ? null : $birthday,
            // getStats() gives "--" when there is no birthday.
            'age' => is_numeric($age) && $birthday !== '' && strpos($birthday, '0000-00-00') !== 0 ? (int) $age : null,
            'date_add' => SomcpToolSearchCustomers::isoDate($customer->date_add),
            'last_visit' => SomcpToolSearchCustomers::isoDate($stats['last_visit'] ?? null),
            'customer_language' => (string) Language::getIsoById((int) $customer->id_lang),
            'newsletter' => (bool) $customer->newsletter,
            'newsletter_date_add' => SomcpToolSearchCustomers::isoDate($customer->newsletter_date_add),
            'partner_offers' => (bool) $customer->optin,
            'date_upd' => SomcpToolSearchCustomers::isoDate($customer->date_upd),
            'active' => (bool) $customer->active,
            'is_guest' => (bool) $customer->is_guest,
            // The same name as in somcp_get_order.
            'private_note' => (string) $customer->note,
            // Always given, empty when B2B mode is off, so the shape of the
            // answer does not depend on a setting.
            'company' => (string) $customer->company,
            'siret' => (string) $customer->siret,
            'ape' => (string) $customer->ape,
            'website' => (string) $customer->website,
            'allowed_outstanding_amount' => round((float) $customer->outstanding_allow_amount, 2),
            'currency' => $currencyIso,
            'max_payment_days' => (int) $customer->max_payment_days,
            'id_risk' => $idRisk,
            'risk' => $risk,
        ];
    }

    /**
     * @param Customer $customer
     * @param int $idLang
     *
     * @return array<int, array<string, mixed>>
     */
    private static function groups(Customer $customer, int $idLang): array
    {
        $groups = [];
        foreach ($customer->getGroups() as $idGroup) {
            $group = new Group((int) $idGroup, $idLang);
            $groups[] = [
                'id_group' => (int) $idGroup,
                'name' => Validate::isLoadedObject($group) ? (string) $group->name : '',
                'default' => (int) $idGroup === (int) $customer->id_default_group,
            ];
        }

        return $groups;
    }

    /**
     * The addresses, with the field names of somcp_get_order.
     *
     * @param Customer $customer
     * @param int $idLang
     *
     * @return array<int, array<string, mixed>>
     */
    private static function addresses(Customer $customer, int $idLang): array
    {
        $addresses = [];
        foreach ($customer->getAddresses($idLang) ?: [] as $row) {
            $addresses[] = [
                'id_address' => (int) $row['id_address'],
                'alias' => (string) $row['alias'],
                'company' => (string) ($row['company'] ?? ''),
                'firstname' => (string) $row['firstname'],
                'lastname' => (string) $row['lastname'],
                'address1' => (string) $row['address1'],
                'address2' => (string) ($row['address2'] ?? ''),
                'postcode' => (string) ($row['postcode'] ?? ''),
                'city' => (string) $row['city'],
                'state' => (string) ($row['state'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
                'country_iso' => (string) Country::getIsoById((int) $row['id_country']),
                'phone' => (string) ($row['phone'] ?? ''),
                'phone_mobile' => (string) ($row['phone_mobile'] ?? ''),
                'vat_number' => (string) ($row['vat_number'] ?? ''),
                'dni' => (string) ($row['dni'] ?? ''),
                'other' => (string) ($row['other'] ?? ''),
            ];
        }

        return $addresses;
    }

    /**
     * The Orders block: counts, the total of valid orders and the newest ones.
     *
     * @param Customer $customer
     * @param int $idShop
     * @param int $idLang
     * @param string $currencyIso
     *
     * @return array<string, mixed>
     */
    private static function orders(Customer $customer, int $idShop, int $idLang, string $currencyIso): array
    {
        $idCustomer = (int) $customer->id;
        $db = Db::getInstance();
        // Tax included on both versions, converted to the default currency,
        // as PrestaShop 9 sums it; 8.0 sums the amount paid instead.
        $summary = $db->getRow('SELECT COUNT(*) AS `count`, SUM(o.`valid` = 1) AS `valid_count`,'
            . ' SUM(IF(o.`valid` = 1, o.`total_paid_tax_incl` / o.`conversion_rate`, 0)) AS `valid_total`'
            . ' FROM `' . _DB_PREFIX_ . 'orders` o WHERE o.`id_customer` = ' . $idCustomer . ' AND o.`id_shop` = ' . $idShop) ?: [];
        $count = (int) ($summary['count'] ?? 0);
        $validCount = (int) ($summary['valid_count'] ?? 0);

        $query = new DbQuery();
        $query->select('o.`id_order`, o.`reference`, o.`date_add`, o.`payment`, o.`current_state`, osl.`name` AS `status`');
        $query->select('o.`total_paid_tax_incl`, cu.`iso_code` AS `currency`');
        $query->select('(SELECT SUM(od.`product_quantity`) FROM `' . _DB_PREFIX_ . 'order_detail` od WHERE od.`id_order` = o.`id_order`) AS `products_count`');
        $query->from('orders', 'o');
        $query->leftJoin('order_state_lang', 'osl', 'osl.`id_order_state` = o.`current_state` AND osl.`id_lang` = ' . $idLang);
        $query->leftJoin('currency', 'cu', 'cu.`id_currency` = o.`id_currency`');
        $query->where('o.`id_customer` = ' . $idCustomer);
        $query->where('o.`id_shop` = ' . $idShop);
        $query->orderBy('o.`date_add` DESC, o.`id_order` DESC');
        $query->limit(self::LATEST);

        $latest = [];
        foreach ($db->executeS($query) ?: [] as $row) {
            $latest[] = [
                'id_order' => (int) $row['id_order'],
                'reference' => (string) $row['reference'],
                'date_add' => SomcpToolSearchCustomers::isoDate($row['date_add']),
                'payment' => (string) $row['payment'],
                'status' => ['id_order_state' => (int) $row['current_state'], 'name' => (string) ($row['status'] ?? '')],
                'products_count' => (int) ($row['products_count'] ?? 0),
                'total_paid_tax_incl' => round((float) $row['total_paid_tax_incl'], 2),
                'currency' => (string) ($row['currency'] ?? ''),
            ];
        }

        return [
            'count' => $count,
            'valid_count' => $validCount,
            'valid_total_tax_incl' => round((float) ($summary['valid_total'] ?? 0), 2),
            'currency' => $currencyIso,
            'invalid_count' => $count - $validCount,
            'sales_rank' => self::salesRank($idCustomer, $idShop),
            'latest' => $latest,
        ];
    }

    /**
     * The rank by sales of the page: 1 + the customers who paid more in valid
     * orders, null with no valid order.
     *
     * Same sum as the core handler (total_paid_real); counted with a derived
     * table rather than SQL_CALC_FOUND_ROWS, which MySQL 8 deprecates.
     *
     * @param int $idCustomer
     * @param int $idShop
     *
     * @return int|null
     */
    private static function salesRank(int $idCustomer, int $idShop): ?int
    {
        $db = Db::getInstance();
        $paid = (float) $db->getValue('SELECT SUM(`total_paid_real`) FROM `' . _DB_PREFIX_ . 'orders`'
            . ' WHERE `id_customer` = ' . $idCustomer . ' AND `valid` = 1 AND `id_shop` = ' . $idShop);
        if ($paid <= 0) {
            return null;
        }

        return 1 + (int) $db->getValue('SELECT COUNT(*) FROM (SELECT `id_customer` FROM `' . _DB_PREFIX_ . 'orders`'
            . ' WHERE `valid` = 1 AND `id_customer` != ' . $idCustomer . ' AND `id_shop` = ' . $idShop
            . ' GROUP BY `id_customer` HAVING SUM(`total_paid_real`) > ' . (float) $paid . ') t');
    }

    /**
     * The Carts block.
     *
     * @param Customer $customer
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    private static function carts(Customer $customer, int $idShop): array
    {
        $idCustomer = (int) $customer->id;
        $db = Db::getInstance();
        $count = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cart`'
            . ' WHERE `id_customer` = ' . $idCustomer . ' AND `id_shop` = ' . $idShop);

        $query = new DbQuery();
        $query->select('ca.`id_cart`, ca.`date_add`, ca.`id_currency`, cr.`name` AS `carrier`, cu.`iso_code` AS `currency`');
        $query->select('(SELECT MIN(o.`id_order`) FROM `' . _DB_PREFIX_ . 'orders` o WHERE o.`id_cart` = ca.`id_cart`) AS `id_order`');
        $query->from('cart', 'ca');
        $query->leftJoin('carrier', 'cr', 'cr.`id_carrier` = ca.`id_carrier`');
        $query->leftJoin('currency', 'cu', 'cu.`id_currency` = ca.`id_currency`');
        $query->where('ca.`id_customer` = ' . $idCustomer);
        $query->where('ca.`id_shop` = ' . $idShop);
        $query->orderBy('ca.`date_add` DESC, ca.`id_cart` DESC');
        $query->limit(self::LATEST);

        $latest = [];
        foreach ($db->executeS($query) ?: [] as $row) {
            $latest[] = [
                'id_cart' => (int) $row['id_cart'],
                'date_add' => SomcpToolSearchCustomers::isoDate($row['date_add']),
                'carrier' => (string) ($row['carrier'] ?? ''),
                'total_tax_incl' => self::cartTotal((int) $row['id_cart']),
                'currency' => (string) ($row['currency'] ?? ''),
                'id_order' => $row['id_order'] !== null ? (int) $row['id_order'] : null,
            ];
        }

        return ['count' => $count, 'latest' => $latest];
    }

    /**
     * The total the page shows for a cart, computed by the cart itself so
     * taxes, discounts and shipping follow the shop's rules; null when the
     * cart cannot be priced any more (a product or carrier removed since), so
     * one old cart does not hide the rest of the page.
     *
     * @param int $idCart
     *
     * @return float|null
     */
    private static function cartTotal(int $idCart): ?float
    {
        $cart = new Cart($idCart);
        if (!Validate::isLoadedObject($cart)) {
            return null;
        }
        try {
            return round((float) $cart->getOrderTotal(true), 2);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * The lines of every order of the customer, valid or not, as PrestaShop 9
     * lists them; 8.0 lists the valid ones only, so valid tells them apart.
     *
     * @param Customer $customer
     * @param int $idShop
     *
     * @return array<int, array<string, mixed>>
     */
    private static function purchasedProducts(Customer $customer, int $idShop): array
    {
        $query = new DbQuery();
        $query->select('o.`date_add`, o.`id_order`, o.`valid`, od.`product_id`, od.`product_attribute_id`, od.`product_name`, od.`product_quantity`');
        $query->from('order_detail', 'od');
        $query->innerJoin('orders', 'o', 'o.`id_order` = od.`id_order`');
        $query->where('o.`id_customer` = ' . (int) $customer->id);
        $query->where('o.`id_shop` = ' . $idShop);
        $query->orderBy('o.`date_add` DESC, od.`id_order_detail` DESC');
        $query->limit(self::LATEST);

        $lines = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $lines[] = [
                'date_add' => SomcpToolSearchCustomers::isoDate($row['date_add']),
                'id_order' => (int) $row['id_order'],
                'valid' => (bool) $row['valid'],
                'id_product' => (int) $row['product_id'],
                'id_product_attribute' => (int) $row['product_attribute_id'],
                // Saved with its combination when ordered, as the page shows it.
                'name' => (string) $row['product_name'],
                'quantity' => (int) $row['product_quantity'],
            ];
        }

        return $lines;
    }

    /**
     * The product pages the customer's visits went through, as the Viewed
     * products block of PrestaShop 8; 9 dropped the block, not the data.
     *
     * @param Customer $customer
     * @param int $idShop
     * @param int $idLang
     *
     * @return array<int, array<string, mixed>>
     */
    private static function viewedProducts(Customer $customer, int $idShop, int $idLang): array
    {
        $query = new DbQuery();
        $query->select('pa.`id_object` AS `id_product`, MAX(co.`date_add`) AS `seen`, MAX(pl.`name`) AS `name`');
        $query->from('guest', 'g');
        $query->innerJoin('connections', 'co', 'co.`id_guest` = g.`id_guest`');
        $query->innerJoin('connections_page', 'cp', 'cp.`id_connections` = co.`id_connections`');
        $query->innerJoin('page', 'pa', 'pa.`id_page` = cp.`id_page`');
        $query->innerJoin('page_type', 'pt', 'pt.`id_page_type` = pa.`id_page_type`');
        $query->leftJoin('product_lang', 'pl', 'pl.`id_product` = pa.`id_object` AND pl.`id_lang` = ' . $idLang . ' AND pl.`id_shop` = ' . $idShop);
        $query->where('g.`id_customer` = ' . (int) $customer->id);
        $query->where("pt.`name` = 'product'");
        $query->where('co.`id_shop` = ' . $idShop);
        $query->groupBy('pa.`id_object`');
        $query->orderBy('`seen` DESC');
        $query->limit(self::LATEST);

        $products = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $products[] = [
                'id_product' => (int) $row['id_product'],
                'name' => (string) ($row['name'] ?? ''),
            ];
        }

        return $products;
    }

    /**
     * The vouchers reserved to this customer, as the Vouchers block: the ones
     * open to everybody are not theirs.
     *
     * @param Customer $customer
     * @param int $idLang
     *
     * @return array<int, array<string, mixed>>
     */
    private static function vouchers(Customer $customer, int $idLang): array
    {
        $vouchers = [];
        foreach (CartRule::getCustomerCartRules($idLang, (int) $customer->id, false, false) ?: [] as $row) {
            $vouchers[] = [
                'id_cart_rule' => (int) $row['id_cart_rule'],
                'name' => (string) ($row['name'] ?? ''),
                'code' => (string) ($row['code'] ?? ''),
                'quantity' => (int) ($row['quantity'] ?? 0),
                'date_to' => SomcpToolSearchCustomers::isoDate($row['date_to'] ?? null),
                'active' => (bool) ($row['active'] ?? false),
            ];
        }

        return $vouchers;
    }

    /**
     * @param Customer $customer
     *
     * @return array<int, array<string, mixed>>
     */
    private static function lastEmails(Customer $customer): array
    {
        $emails = [];
        foreach ($customer->getLastEmails() ?: [] as $row) {
            $emails[] = [
                'date_add' => SomcpToolSearchCustomers::isoDate($row['date_add']),
                'template' => (string) $row['template'],
                'subject' => (string) $row['subject'],
                'language' => (string) ($row['language'] ?? ''),
            ];
        }

        return $emails;
    }

    /**
     * @param Customer $customer
     *
     * @return array<int, array<string, mixed>>
     */
    private static function lastConnections(Customer $customer): array
    {
        $connections = [];
        foreach ($customer->getLastConnections() ?: [] as $row) {
            // The core query left joins the connections, so a visitor who
            // never connected gives one empty row.
            if (empty($row['id_connections'])) {
                continue;
            }
            $connections[] = [
                'date_add' => SomcpToolSearchCustomers::isoDate($row['date_add']),
                'pages_viewed' => (int) $row['pages'],
                'total_time' => (string) ($row['time'] ?? ''),
                'origin' => (string) ($row['http_referer'] ?? ''),
                'ip_address' => (string) ($row['ipaddress'] ?? ''),
            ];
        }

        return $connections;
    }
}
