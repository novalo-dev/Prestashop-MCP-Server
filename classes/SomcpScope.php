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
 * SomcpScope - the permissions a token can carry.
 *
 * The scopes that gate products, orders or customers are added together
 * with the tools that need them, not before: a scope advertised in the
 * metadata but enforced nowhere is a promise the server does not keep, and a
 * client that asks for it gets a token that grants more than the shop can
 * honour.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpScope
{
    /** @var string Lets a client open a session and list the tools. */
    public const ACCESS = 'mcp.access';

    /** @var string Reading orders, and the records they produce. */
    public const ORDERS_READ = 'mcp.orders.read';

    /** @var string Acting on orders: statuses, notes, documents. */
    public const ORDERS_WRITE = 'mcp.orders.write';

    /** @var string Reading the catalogue: products, categories, brands, suppliers. */
    public const CATALOG_READ = 'mcp.catalog.read';

    /** @var string Changing the catalogue: products, categories and brands. */
    public const CATALOG_WRITE = 'mcp.catalog.write';

    /** @var string Reading stock: the quantities of products and combinations. */
    public const STOCK_READ = 'mcp.stock.read';

    /** @var string Changing stock: setting or moving the quantities of products and combinations. */
    public const STOCK_WRITE = 'mcp.stock.write';

    /** @var string Reading customers: their accounts, as the back office lists them. */
    public const CUSTOMERS_READ = 'mcp.customers.read';

    /** @var string Changing customers: creating and editing their accounts. */
    public const CUSTOMERS_WRITE = 'mcp.customers.write';

    /** @var string Reading how the shop appears to search engines. */
    public const SEO_READ = 'mcp.seo.read';

    /** @var string Changing how the shop appears to search engines. */
    public const SEO_WRITE = 'mcp.seo.write';

    /** @var string Reading statistics: sales figures over a period. */
    public const STATS_READ = 'mcp.stats.read';

    /** @var string Reading content: the CMS pages and their categories. */
    public const CONTENT_READ = 'mcp.content.read';

    /** @var string Reading the modules: which ones the shop has, their version and state. */
    public const MODULES_READ = 'mcp.modules.read';

    /** @var string Changing the modules: installing a module already on the shop. */
    public const MODULES_WRITE = 'mcp.modules.write';

    /** @var string Reading how the storefront looks: its main menu. */
    public const APPEARANCE_READ = 'mcp.appearance.read';

    /** @var string Changing how the storefront looks: the entries of its main menu. */
    public const APPEARANCE_WRITE = 'mcp.appearance.write';

    /** @var string Reading how the shop runs: its performance and cache settings. */
    public const SYSTEM_READ = 'mcp.system.read';

    /** @var string Acting on how the shop runs: clearing its cache. */
    public const SYSTEM_WRITE = 'mcp.system.write';

    /** @var string Reading where sales are lost: abandoned carts, searches, product views and promotions. */
    public const SALES_READ = 'mcp.sales.read';

    /** @var string Acting on promotions: creating and changing cart rules and catalogue price rules. */
    public const SALES_WRITE = 'mcp.sales.write';

    /**
     * The scopes this module owns.
     *
     * @return array<int, string>
     */
    public static function core(): array
    {
        return [self::ACCESS, self::ORDERS_READ, self::ORDERS_WRITE, self::CATALOG_READ, self::CATALOG_WRITE, self::STOCK_READ, self::STOCK_WRITE, self::CUSTOMERS_READ, self::CUSTOMERS_WRITE, self::SEO_READ, self::SEO_WRITE, self::STATS_READ, self::CONTENT_READ, self::MODULES_READ, self::MODULES_WRITE, self::APPEARANCE_READ, self::APPEARANCE_WRITE, self::SYSTEM_READ, self::SYSTEM_WRITE, self::SALES_READ, self::SALES_WRITE];
    }

    /**
     * Every scope the server understands, ours and the ones other modules add.
     *
     * A module publishing tools of its own declares the permission that goes
     * with them, rather than borrowing one of ours. A merchant who let an
     * assistant read their orders has not let it read their customers'
     * complaints, and the consent screen has to be able to ask the two
     * questions apart.
     *
     * @return array<int, string>
     */
    public static function supported(): array
    {
        return array_merge(self::core(), array_keys(self::declared()));
    }

    /**
     * The scopes other modules brought, if the registry is there to ask.
     *
     * The module loads its classes together, so it normally is. The guard is
     * for the boot that stopped short: a discovery document listing our own
     * scopes is a document a client can still work from, while a fatal error
     * on the well-known address takes the whole server down.
     *
     * @return array<string, string>
     */
    private static function declared(): array
    {
        if (!class_exists('SomcpToolRegistry')) {
            return [];
        }

        return SomcpToolRegistry::getInstance()->declaredScopes();
    }

    /**
     * Granted when a client asks for nothing in particular.
     *
     * @return array<int, string>
     */
    public static function defaults(): array
    {
        return [self::ACCESS];
    }

    /**
     * The scopes an employee could actually put to use.
     *
     * A scope is offered only when at least one loaded tool needs it and that
     * employee holds the back office permission that tool requires. Nothing is
     * listed by hand: the answer comes from the tools themselves, so a scope
     * added later needs no thought here, and a bookkeeper with no right to read
     * orders is never offered the permission to read them - they could not
     * grant what they do not have.
     *
     * @param int $idEmployee
     * @param int|null $idShop the shop the assistant is being connected to,
     *                         named rather than taken from the context: the
     *                         request may have been made on another shop than
     *                         the one in context, so it is not guessed
     * @param bool $chat true for the built-in assistant, which reads its own
     *                   list of tools switched off
     *
     * @return array<int, string>
     */
    public static function grantableFor(int $idEmployee, ?int $idShop = null, bool $chat = false): array
    {
        // Opening a session asks nothing of the shop, so it is always on offer.
        $grantable = [self::ACCESS];

        foreach (SomcpToolRegistry::getInstance()->all() as $tool) {
            // A tool the merchant switched off cannot make a scope worth
            // granting: the consent screen would be asking for a permission
            // that reaches nothing.
            if (!SomcpToolRegistry::isEnabled($tool->getName(), $chat)) {
                continue;
            }
            if (!SomcpPermission::employeeHolds($idEmployee, $tool->getRequiredPermissions(), $idShop)) {
                continue;
            }
            foreach ($tool->getRequiredScopes() as $scope) {
                if (in_array($scope, self::supported(), true) && !in_array($scope, $grantable, true)) {
                    $grantable[] = $scope;
                }
            }
        }

        return $grantable;
    }

    /**
     * The scopes the merchant switched off for one kind of assistant.
     *
     * Opening a session is never among them: an assistant that cannot connect
     * cannot even say why, and the merchant has the server switch for that.
     *
     * @param bool $chat true for the built-in assistant's list
     *
     * @return array<int, string>
     */
    public static function disabled(bool $chat = false): array
    {
        $raw = trim(SomcpConfig::get($chat ? SomcpConfig::SCOPES_OFF_CHAT : SomcpConfig::SCOPES_OFF));

        return array_values(array_diff(self::parse($raw), [self::ACCESS]));
    }

    /**
     * Whether any of these scopes is switched off, which takes down whatever
     * needs it whatever its own switch says.
     *
     * @param array<int, string> $scopes
     * @param bool $chat true for the built-in assistant
     *
     * @return bool
     */
    public static function anyOff(array $scopes, bool $chat = false): bool
    {
        return array_intersect($scopes, self::disabled($chat)) !== [];
    }

    /**
     * The scope a tool or a prompt is listed under on the Tools and prompts
     * tab: the first one it needs besides opening a session, in the order of
     * core() and then of the modules that declared theirs.
     *
     * @param array<int, string> $scopes
     *
     * @return string
     */
    public static function groupOf(array $scopes): string
    {
        $order = self::supported();
        $best = self::ACCESS;
        $rank = PHP_INT_MAX;
        foreach ($scopes as $scope) {
            $at = array_search($scope, $order, true);
            if ($scope !== self::ACCESS && ($at === false ? PHP_INT_MAX - 1 : $at) < $rank) {
                $rank = $at === false ? PHP_INT_MAX - 1 : $at;
                $best = $scope;
            }
        }

        return $best;
    }

    /**
     * The short name of a scope, as the group rows of the Tools and prompts
     * tab show it. describe() is the sentence of the consent screen, too long
     * for a row heading.
     *
     * @param string $scope
     * @param Somcp $module
     *
     * @return string
     */
    public static function label(string $scope, Somcp $module): string
    {
        switch ($scope) {
            case self::ACCESS:
                return $module->l10n('Connection');
            case self::ORDERS_READ:
                return $module->l10n('Orders - read');
            case self::ORDERS_WRITE:
                return $module->l10n('Orders - changes');
            case self::CATALOG_READ:
                return $module->l10n('Catalog - read');
            case self::CATALOG_WRITE:
                return $module->l10n('Catalog - changes');
            case self::STOCK_READ:
                return $module->l10n('Stock - read');
            case self::STOCK_WRITE:
                return $module->l10n('Stock - changes');
            case self::CUSTOMERS_READ:
                return $module->l10n('Customers - read');
            case self::CUSTOMERS_WRITE:
                return $module->l10n('Customers - changes');
            case self::SEO_READ:
                return $module->l10n('SEO - read');
            case self::SEO_WRITE:
                return $module->l10n('SEO - changes');
            case self::STATS_READ:
                return $module->l10n('Statistics - read');
            case self::CONTENT_READ:
                return $module->l10n('Pages - read');
            case self::MODULES_READ:
                return $module->l10n('Modules - read');
            case self::MODULES_WRITE:
                return $module->l10n('Modules - changes');
            case self::APPEARANCE_READ:
                return $module->l10n('Design - read');
            case self::APPEARANCE_WRITE:
                return $module->l10n('Design - changes');
            case self::SYSTEM_READ:
                return $module->l10n('Performance - read');
            case self::SYSTEM_WRITE:
                return $module->l10n('Performance - changes');
            case self::SALES_READ:
                return $module->l10n('Sales - read');
            case self::SALES_WRITE:
                return $module->l10n('Sales - changes');
            default:
                // Another module's scope comes with the label it declared,
                // already in the merchant's language.
                return self::declared()[$scope] ?? $scope;
        }
    }

    /**
     * Turns a space separated scope parameter into a clean list.
     *
     * @param string $raw
     *
     * @return array<int, string>
     */
    public static function parse(string $raw): array
    {
        $parts = preg_split('/\s+/', trim($raw)) ?: [];

        return array_values(array_unique(array_filter($parts, static function ($scope) {
            return $scope !== '';
        })));
    }

    /**
     * @param array<int, string> $scopes
     *
     * @return string
     */
    public static function toString(array $scopes): string
    {
        return implode(' ', $scopes);
    }

    /**
     * Scopes the server refuses to issue, out of a requested list.
     *
     * @param array<int, string> $requested
     *
     * @return array<int, string>
     */
    public static function unknown(array $requested): array
    {
        return array_values(array_diff($requested, self::supported()));
    }

    /**
     * @param string $granted space separated list carried by the token
     * @param array<int, string> $required
     *
     * @return bool
     */
    public static function covers(string $granted, array $required): bool
    {
        $held = self::parse($granted);
        foreach ($required as $scope) {
            if (!in_array($scope, $held, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Label shown to the merchant on the consent screen.
     *
     * Kept next to the scope itself so that adding a scope and forgetting to
     * describe it is impossible: an unknown scope falls back to its own name
     * rather than to an empty line.
     *
     * @param string $scope
     * @param Somcp $module
     *
     * @return string
     */
    public static function describe(string $scope, Somcp $module): string
    {
        switch ($scope) {
            case self::ORDERS_READ:
                return $module->l10n('Read the orders of this shop, and who placed them');
            case self::ORDERS_WRITE:
                return $module->l10n(
                    'Change the orders of this shop, which can email your customers'
                );
            case self::CATALOG_READ:
                return $module->l10n('Read the catalogue of this shop: products, prices and categories');
            case self::CATALOG_WRITE:
                return $module->l10n('Change the catalogue of this shop: create, edit and delete products, create and edit categories, create and edit brands, create, edit and delete their addresses, create and edit suppliers, create and edit attribute groups, create and edit attribute values, create and edit features and their values, change prices and stock');
            case self::STOCK_READ:
                return $module->l10n('Read the stock of this shop: quantities of products and combinations');
            case self::STOCK_WRITE:
                return $module->l10n('Change the stock of this shop: set the quantities of products and combinations, recorded as stock movements');
            case self::CUSTOMERS_READ:
                return $module->l10n('Read the customers of this shop: names, email addresses, groups and what they spent');
            case self::CUSTOMERS_WRITE:
                return $module->l10n('Change the customers of this shop: create and edit customer accounts');
            case self::SEO_READ:
                return $module->l10n('Read how the shop appears to search engines: titles, descriptions, URLs, robots.txt, sitemap and public pages');
            case self::SEO_WRITE:
                return $module->l10n('Change the shop\'s SEO settings, page titles and descriptions, robots.txt and llms.txt');
            case self::STATS_READ:
                return $module->l10n('Read the statistics of this shop: revenue, orders, products sold and new customers over a period');
            case self::CONTENT_READ:
                return $module->l10n('Read the content pages of this shop: their titles, addresses and categories, displayed or not');
            case self::MODULES_READ:
                return $module->l10n('Read the modules of this shop: their version, author and whether they are installed and enabled');
            case self::MODULES_WRITE:
                return $module->l10n('Install, enable and disable the modules already present on this shop, which runs their own code');
            case self::APPEARANCE_READ:
                return $module->l10n('Read how the storefront looks: the entries and links of its main menu, its theme, logos and page layouts');
            case self::APPEARANCE_WRITE:
                return $module->l10n('Change how the storefront looks: add entries and custom links to its main menu, change those links, and the layout of its pages');
            case self::SYSTEM_READ:
                return $module->l10n('Read how this shop runs: its performance settings, cache and debug mode');
            case self::SYSTEM_WRITE:
                return $module->l10n('Clear the cache of this shop and of every shop of the installation, which slows them until it rebuilds');
            case self::SALES_READ:
                return $module->l10n('Read abandoned carts, searches, product views and promotions');
            case self::SALES_WRITE:
                return $module->l10n('Create and change cart rules and catalogue price rules');
            case self::ACCESS:
                return $module->l10n(
                    'Connect to this shop, see the list of available tools, and read your account details'
                    . ' and the shop configuration'
                );
            default:
                // A scope another module declared comes with its own label,
                // already in the merchant's language: that module knows what
                // its tools do and this one does not.
                return self::declared()[$scope] ?? $scope;
        }
    }
}
