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
 * somcp_install_module - the Install button of a module's card in Modules >
 * Module Manager, for a module whose folder is already in the shop.
 *
 * Disk only: no archive and no link, so nothing from outside the shop runs
 * through a token. The install goes through the core's ModuleManager, as the
 * button does, so the module's own checks, hooks and tabs are set up as from
 * the back office. The answer is read back through SomcpModules, the same read
 * as somcp_get_module, so the assistant sees what a read would show.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolInstallModule
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_install_module',
            Somcp::t('Install a module'),
            'Installs one module whose folder is already in the shop\'s modules folder and that is not '
                . 'installed yet, as the Install button of its card in Modules > Module Manager does, with '
                . 'the module\'s own install checks, hooks and tabs. Find the name with somcp_list_modules '
                . '(status "uninstalled" lists the candidates). Answers with the module as somcp_get_module '
                . 'reads it after the install: version, version_installed, installed, active, '
                . 'active_on_mobile and hooks. A module already installed is refused and left unchanged: '
                . 'upgrading it is done in the Module Manager. On a multistore installation PrestaShop '
                . 'installs it for the shops of the current context. Do not retry on a lost answer: read '
                . 'somcp_get_module to check. It does not upload or download a module, and does not '
                . 'upgrade, uninstall, reset, enable or disable one.',
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
            [SomcpScope::MODULES_WRITE],
            [self::class, 'handle'],
            // The Install button asks for the add right on the Module
            // Manager, whose tab is AdminModulesSf; the core checks the same.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminModulesSf')
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
        $idShop = (int) Somcp::currentShop()->id;
        $before = SomcpModules::read($name, $idShop);
        if ($before === null) {
            return SomcpTool::text('No module of this shop has this name. Find it with somcp_list_modules.', true);
        }
        if ($before['installed']) {
            // ModuleManager::install() upgrades a module already installed:
            // refused here so an install never turns into an upgrade.
            return SomcpTool::text(
                'This module is already installed, version ' . (string) $before['version_installed']
                . '. Nothing was changed; use the Module Manager to upgrade it.',
                true
            );
        }
        // The name the core knows the folder by, not the caller's spelling;
        // a module installed whose folder is gone never reaches this point.
        $folder = (string) $before['name'];
        if ($before['version'] === '' || !Validate::isModuleName($folder)) {
            return SomcpTool::text('No module of this shop has this name. Find it with somcp_list_modules.', true);
        }

        $idEmployee = (int) ($context['id_employee'] ?? 0);

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($folder, $idShop): array {
            return self::install($folder, $idShop);
        });
    }

    /**
     * Installs through the core and answers with the state read back.
     *
     * @param string $folder
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    private static function install(string $folder, int $idShop): array
    {
        $installed = false;
        $errors = [];
        // A module may print while it installs, which would break the JSON
        // of the answer: what it prints is dropped.
        $level = ob_get_level();
        ob_start();
        try {
            // The core's own way to reach its ModuleManager from legacy code:
            // the Symfony container when there is one, the same classes built
            // by hand otherwise, on PrestaShop 8 and 9 alike.
            $manager = PrestaShop\PrestaShop\Core\Addon\Module\ModuleManagerBuilder::getInstance()->build();
            $installed = (bool) $manager->install($folder);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        if (!$installed) {
            $legacy = Module::getInstanceByName($folder);
            if ($legacy instanceof Module) {
                foreach ($legacy->getErrors() as $error) {
                    $errors[] = (string) $error;
                }
            }
        }

        // Read back whatever happened: PrestaShop 8 dispatches the install
        // event even after a failure, so nothing is assumed either way.
        $module = SomcpModules::read($folder, $idShop) ?? ['name' => $folder, 'installed' => false];
        if ($installed && $module['installed']) {
            return SomcpTool::json($module);
        }

        $errors = array_values(array_unique(array_filter(array_map('trim', $errors))));

        return SomcpTool::text(
            'The module was not installed' . ($errors !== [] ? ': ' . implode(' ', $errors) : '.')
                . ' State read back: ' . json_encode($module, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            true
        );
    }
}
