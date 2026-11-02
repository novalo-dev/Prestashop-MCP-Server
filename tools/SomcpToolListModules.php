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
 * somcp_list_modules - the modules of this shop, as Modules > Module Manager
 * shows them.
 *
 * What is on disk (name, version, author, description) comes from the core's
 * Module::getModulesOnDisk(), the legacy class both PrestaShop 8 and 9 have:
 * the module manager's Symfony layer differs between the two, and a class of
 * PrestaShop 9 in a type would put the back office of PrestaShop 8 in error.
 * Whether a module is installed, enabled and enabled on mobile is read from
 * module and module_shop for the token's shop, not from what the core worked
 * out: getModulesOnDisk() takes the shop from the global context, which is not
 * the token's on a multistore installation.
 *
 * Nothing asks the marketplace: its Upgrade available and its categories need
 * a network call and are not the shop's data.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolListModules
{
    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 100;

    /** @var array<int, string> the Status filter of the screen, Show all modules being none */
    private const STATUSES = ['enabled', 'disabled', 'installed', 'uninstalled'];

    /**
     * module_shop.enable_device is a bit mask; Disable Mobile clears this bit.
     */
    private const DEVICE_MOBILE = 4;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_modules',
            Somcp::t('List modules'),
            'Lists the modules of this shop as Modules > Module Manager shows them, installed or only '
                . 'on disk: technical name (name, the folder, the identifier every module tool takes), '
                . 'display name (display_name), the version of the files on disk (version), the version '
                . 'installed in the database (version_installed, null when not installed), needs_upgrade '
                . '(true when the files are newer than the installed version, which the next Upgrade '
                . 'applies), author, description, installed, active (enabled on this shop, false when not '
                . 'installed) and active_on_mobile (null when not installed). Sorted by display name. '
                . 'Read from the shop only: the marketplace\'s Upgrade available and categories are not '
                . 'returned. Do not use it for one module\'s details and hooks (somcp_get_module), to '
                . 'install a module (somcp_install_module) or to enable or disable one '
                . '(somcp_set_module_active).',
            [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'enum' => self::STATUSES,
                        'description' => 'The Status filter: enabled, disabled (installed but disabled), '
                            . 'installed or uninstalled (on disk only). Left out, every module (Show all modules).',
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Part of the technical name or of the display name, case-insensitive. '
                            . 'Left out, every module.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many modules to return, 20 by default and 100 at most.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'How many to skip, to read the next page.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::MODULES_READ],
            [self::class, 'handle'],
            // The modules are managed on Modules > Module Manager, whose tab is
            // AdminModulesSf: AdminModules is only the legacy configure page and
            // has no tab of its own, so no profile holds a right on it.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminModulesSf')
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
        $status = $arguments['status'] ?? null;
        if ($status !== null && (!is_string($status) || !in_array($status, self::STATUSES, true))) {
            return SomcpTool::text('status: one of ' . implode(', ', self::STATUSES) . ', or left out for every module.', true);
        }
        $query = $arguments['query'] ?? null;
        if ($query !== null && !is_string($query)) {
            return SomcpTool::text('query: a string, part of the technical or display name.', true);
        }
        $limit = $arguments['limit'] ?? self::DEFAULT_LIMIT;
        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            return SomcpTool::text('limit: an integer from 1 to ' . self::MAX_LIMIT . '.', true);
        }
        $offset = $arguments['offset'] ?? 0;
        if (!is_int($offset) || $offset < 0) {
            return SomcpTool::text('offset: an integer, 0 or more.', true);
        }

        // The shop comes from the token, never from the caller.
        $idShop = (int) Somcp::currentShop()->id;
        $states = self::states($idShop);
        $needle = $query !== null ? Tools::strtolower(trim($query)) : '';

        $modules = [];
        foreach (self::onDisk() as $item) {
            $name = (string) ($item->name ?? '');
            if ($name === '') {
                continue;
            }
            $displayName = (string) ($item->displayName ?? $name);
            // Compared in PHP rather than with LIKE, so that % or _ in what
            // the merchant typed are plain characters, as on the screen.
            if ($needle !== ''
                && strpos(Tools::strtolower($name), $needle) === false
                && strpos(Tools::strtolower($displayName), $needle) === false
            ) {
                continue;
            }

            $state = $states[Tools::strtolower($name)] ?? null;
            $installed = $state !== null;
            $device = $state !== null ? $state['enable_device'] : null;
            $installedVersion = $state !== null ? $state['version'] : null;
            $active = $device !== null;
            if ($status === 'enabled' && !$active
                || $status === 'disabled' && ($active || !$installed)
                || $status === 'installed' && !$installed
                || $status === 'uninstalled' && $installed
            ) {
                continue;
            }

            $version = (string) ($item->version ?? '');
            $modules[] = [
                'name' => $name,
                'display_name' => $displayName,
                'version' => $version,
                'version_installed' => $installedVersion,
                // The core's rule for an upgrade waiting on disk: the files
                // carry a higher version than the one recorded at install.
                'needs_upgrade' => $installedVersion !== null && $version !== '' && version_compare($version, $installedVersion, '>'),
                'author' => (string) ($item->author ?? ''),
                'description' => (string) ($item->description ?? ''),
                'installed' => $installed,
                'active' => $active,
                'active_on_mobile' => $installed ? $device !== null && ($device & self::DEVICE_MOBILE) !== 0 : null,
            ];
        }

        // By display name, as the core sorts the screen, so pages stay stable.
        usort($modules, static function (array $a, array $b): int {
            return strnatcasecmp($a['display_name'], $b['display_name']) ?: strcmp($a['name'], $b['name']);
        });

        return SomcpTool::json([
            'modules' => array_slice($modules, $offset, $limit),
            'total_count' => count($modules),
            'offset' => $offset,
        ]);
    }

    /**
     * What the core finds on disk, each module's config.xml or class.
     *
     * @return array<int, object>
     */
    private static function onDisk(): array
    {
        // getModulesOnDisk() prints the modules it could not load when no
        // controller is there to collect them, which would break the JSON of
        // the answer: what it prints is dropped, the modules it loaded kept.
        $level = ob_get_level();
        ob_start();
        try {
            $modules = Module::getModulesOnDisk(true);
        } catch (Throwable $e) {
            $modules = [];
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return array_values($modules);
    }

    /**
     * The installed modules, keyed by lower-case name, with their state on
     * this shop: enable_device is null when the module is not enabled here.
     *
     * @param int $idShop
     *
     * @return array<string, array{version: string, enable_device: int|null}>
     */
    private static function states(int $idShop): array
    {
        $query = new DbQuery();
        $query->select('m.`name`, m.`version`, ms.`enable_device`');
        $query->from('module', 'm');
        // A left join: a module installed but disabled on this shop has no
        // row in module_shop for it.
        $query->leftJoin('module_shop', 'ms', 'ms.`id_module` = m.`id_module` AND ms.`id_shop` = ' . $idShop);

        $states = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $states[Tools::strtolower((string) $row['name'])] = [
                'version' => (string) $row['version'],
                'enable_device' => $row['enable_device'] !== null ? (int) $row['enable_device'] : null,
            ];
        }

        return $states;
    }
}
