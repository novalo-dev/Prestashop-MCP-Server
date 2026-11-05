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
 * somcp_get_module - one module of this shop, as its card in Modules > Module
 * Manager shows it, plus the hooks it is attached to (Design > Positions).
 *
 * The fields are built by SomcpModules, as somcp_list_modules builds them, so
 * one module reads the same in both tools; somcp_install_module answers
 * through the same read. The hooks are read from hook_module
 * for the token's shop only: on a multistore installation each shop attaches
 * its own.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetModule
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_module',
            Somcp::t('Get a module'),
            'Returns one module of this shop, installed or only on disk, with the fields of an entry of '
                . 'somcp_list_modules plus hooks: the hooks it is attached to on this shop (Design > Positions), '
                . 'each with its name and position, empty when it is not installed or not enabled here. name comes '
                . 'from somcp_list_modules: never guess one. It does not install, enable or disable a module, and '
                . 'does not open its Configure page.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Technical name of the module, its folder, as the name field of '
                            . 'somcp_list_modules returns it. Compared without case.',
                    ],
                ],
                'required' => ['name'],
                'additionalProperties' => false,
            ],
            [SomcpScope::MODULES_READ],
            [self::class, 'handle'],
            // Same right as somcp_list_modules: Modules > Module Manager, whose
            // tab is AdminModulesSf (AdminModules has no tab of its own).
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
        $name = $arguments['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return SomcpTool::text('name: a string, the technical name from somcp_list_modules.', true);
        }
        // The shop comes from the token, never from the caller.
        $module = SomcpModules::read($name, (int) Somcp::currentShop()->id);
        if ($module === null) {
            return SomcpTool::text('No module of this shop has this name. Find it with somcp_list_modules.', true);
        }

        return SomcpTool::json($module);
    }
}
