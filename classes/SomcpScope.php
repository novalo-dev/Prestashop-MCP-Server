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

    /** @var string Changing the catalogue: creating and editing products. */
    public const CATALOG_WRITE = 'mcp.catalog.write';

    /** @var string Reading stock: the quantities of products and combinations. */
    public const STOCK_READ = 'mcp.stock.read';

    /**
     * The scopes this module owns.
     *
     * @return array<int, string>
     */
    public static function core(): array
    {
        return [self::ACCESS, self::ORDERS_READ, self::ORDERS_WRITE, self::CATALOG_READ, self::CATALOG_WRITE, self::STOCK_READ];
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
     * @param int|null $idShop the shop the assistant is being connected to. The
     *                         consent screen runs in the back office, where the
     *                         shop in context is whichever one the administrator
     *                         is working on and not the one being connected, so
     *                         it names it rather than letting it be guessed.
     *
     * @return array<int, string>
     */
    public static function grantableFor(int $idEmployee, ?int $idShop = null): array
    {
        // Opening a session asks nothing of the shop, so it is always on offer.
        $grantable = [self::ACCESS];

        foreach (SomcpToolRegistry::getInstance()->all() as $tool) {
            // A tool the merchant switched off cannot make a scope worth
            // granting: the consent screen would be asking for a permission
            // that reaches nothing.
            if (!SomcpToolRegistry::isEnabled($tool->getName())) {
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
                return $module->l10n('Change the catalogue of this shop: create, edit and delete products, create and edit categories, change prices and stock');
            case self::STOCK_READ:
                return $module->l10n('Read the stock of this shop: quantities of products and combinations');
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
