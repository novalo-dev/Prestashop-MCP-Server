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
 * Each module is built by SomcpModules, which somcp_get_module calls too, so
 * one module reads the same in both tools: what is on disk from the legacy
 * Module class, the state from module and module_shop for the token's shop.
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
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_modules',
            Somcp::t('List modules'),
            'Lists the modules of this shop as Modules > Module Manager shows them, installed or only on disk: '
                . 'name (the folder, the identifier every module tool takes), display_name, version on disk and '
                . 'version_installed (null when not installed), needs_upgrade, author, description, installed, '
                . 'active on this shop and active_on_mobile. Sorted by display name. Read from the shop only: '
                . 'the marketplace\'s upgrades and categories are not returned. For one module and its hooks use '
                . 'somcp_get_module; to install one somcp_install_module, to enable or disable one '
                . 'somcp_set_module_active.',
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
        $states = SomcpModules::states($idShop);
        $needle = $query !== null ? Tools::strtolower(trim($query)) : '';

        $modules = [];
        foreach (SomcpModules::onDisk() as $item) {
            $name = (string) ($item->name ?? '');
            if ($name === '') {
                continue;
            }
            $row = SomcpModules::row($item, $states[Tools::strtolower($name)] ?? null);
            // Compared in PHP rather than with LIKE, so that % or _ in what
            // the merchant typed are plain characters, as on the screen.
            if ($needle !== ''
                && strpos(Tools::strtolower($name), $needle) === false
                && strpos(Tools::strtolower($row['display_name']), $needle) === false
            ) {
                continue;
            }

            $installed = $row['installed'];
            $active = $row['active'];
            if ($status === 'enabled' && !$active
                || $status === 'disabled' && ($active || !$installed)
                || $status === 'installed' && !$installed
                || $status === 'uninstalled' && $installed
            ) {
                continue;
            }

            $modules[] = $row;
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
}
