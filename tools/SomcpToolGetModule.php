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
 * one module reads the same in both tools. The hooks are read from hook_module
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
            'Returns one module of this shop, installed or only on disk, with the same fields as an '
                . 'entry of somcp_list_modules: name, display_name, version (files on disk), '
                . 'version_installed (null when not installed), needs_upgrade, author, description, '
                . 'installed, active (enabled on this shop) and active_on_mobile (null when not installed), '
                . 'plus hooks: the hooks the module is attached to on this shop (Design > Positions), each '
                . 'with its name and position, sorted by hook name, empty when the module is not installed '
                . 'or not enabled here. Use somcp_list_modules to list or search modules and find the name. '
                . 'It does not install, enable or disable a module, and does not open its Configure page.',
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
        $key = Tools::strtolower(trim($name));

        // The shop comes from the token, never from the caller.
        $idShop = (int) Somcp::currentShop()->id;
        $states = SomcpModules::states($idShop);
        $state = $states[$key] ?? null;

        $found = null;
        foreach (SomcpModules::onDisk() as $item) {
            if (Tools::strtolower((string) ($item->name ?? '')) === $key) {
                $found = $item;
                break;
            }
        }
        if ($found === null && $state === null) {
            return SomcpTool::text('No module of this shop has this name. Find it with somcp_list_modules.', true);
        }
        if ($found === null) {
            // Installed but its folder is gone: the database still knows it,
            // so its state is returned under the name it was installed with.
            $stored = Db::getInstance()->getValue(
                'SELECT `name` FROM `' . _DB_PREFIX_ . 'module` WHERE `id_module` = ' . (int) $state['id_module']
            );
            $found = (object) ['name' => (string) $stored];
        }

        $module = SomcpModules::row($found, $state);
        // A module disabled here keeps its rows in hook_module, but none of
        // its hooks runs on this shop: the screen and the tool show none.
        $module['hooks'] = $state !== null && $module['active']
            ? SomcpModules::hooks($state['id_module'], $idShop)
            : [];

        return SomcpTool::json($module);
    }
}
