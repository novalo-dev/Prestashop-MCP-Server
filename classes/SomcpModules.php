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
 * SomcpModules - how the module tools read one module of the shop.
 *
 * somcp_list_modules and somcp_get_module return the same fields for a module;
 * building them here once keeps the mobile bit, the needs_upgrade rule and the
 * null cases identical, so one module never reads differently in two tools.
 *
 * What is on disk comes from the legacy Module class, which PrestaShop 8 and 9
 * both have: the module manager's Symfony layer differs between the two, and a
 * class of PrestaShop 9 in a type would put the back office of PrestaShop 8 in
 * error. The state is read from module and module_shop for the token's shop:
 * the core works it out from the global context, which is not the token's
 * shop on a multistore installation.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpModules
{
    /**
     * module_shop.enable_device is a bit mask; Disable Mobile clears this bit.
     */
    private const DEVICE_MOBILE = 4;

    /**
     * What the core finds on disk, each module's config.xml or class.
     *
     * @return array<int, object>
     */
    public static function onDisk(): array
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
     * @return array<string, array{id_module: int, version: string, enable_device: int|null}>
     */
    public static function states(int $idShop): array
    {
        $query = new DbQuery();
        $query->select('m.`id_module`, m.`name`, m.`version`, ms.`enable_device`');
        $query->from('module', 'm');
        // A left join: a module installed but disabled on this shop has no
        // row in module_shop for it.
        $query->leftJoin('module_shop', 'ms', 'ms.`id_module` = m.`id_module` AND ms.`id_shop` = ' . $idShop);

        $states = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $states[Tools::strtolower((string) $row['name'])] = [
                'id_module' => (int) $row['id_module'],
                'version' => (string) $row['version'],
                'enable_device' => $row['enable_device'] !== null ? (int) $row['enable_device'] : null,
            ];
        }

        return $states;
    }

    /**
     * One module as both tools return it, from what is on disk and its state
     * on this shop (null when it is not installed).
     *
     * @param object $item an entry of onDisk()
     * @param array{id_module: int, version: string, enable_device: int|null}|null $state
     *
     * @return array<string, mixed>
     */
    public static function row($item, ?array $state): array
    {
        $name = (string) ($item->name ?? '');
        $installed = $state !== null;
        $device = $state !== null ? $state['enable_device'] : null;
        $installedVersion = $state !== null ? $state['version'] : null;
        $version = (string) ($item->version ?? '');

        return [
            'name' => $name,
            'display_name' => (string) ($item->displayName ?? $name),
            'version' => $version,
            'version_installed' => $installedVersion,
            // The core's rule for an upgrade waiting on disk: the files
            // carry a higher version than the one recorded at install.
            'needs_upgrade' => $installedVersion !== null && $version !== '' && version_compare($version, $installedVersion, '>'),
            'author' => (string) ($item->author ?? ''),
            'description' => (string) ($item->description ?? ''),
            'installed' => $installed,
            'active' => $device !== null,
            'active_on_mobile' => $installed ? $device !== null && ($device & self::DEVICE_MOBILE) !== 0 : null,
        ];
    }

    /**
     * The hooks a module is attached to on this shop, as Design > Positions
     * lists them, sorted by hook name.
     *
     * @param int $idModule
     * @param int $idShop
     *
     * @return array<int, array{name: string, position: int}>
     */
    public static function hooks(int $idModule, int $idShop): array
    {
        $query = new DbQuery();
        $query->select('h.`name`, hm.`position`');
        $query->from('hook_module', 'hm');
        $query->innerJoin('hook', 'h', 'h.`id_hook` = hm.`id_hook`');
        $query->where('hm.`id_module` = ' . $idModule);
        $query->where('hm.`id_shop` = ' . $idShop);
        $query->orderBy('h.`name` ASC');

        $hooks = [];
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $hooks[] = [
                'name' => (string) $row['name'],
                'position' => (int) $row['position'],
            ];
        }

        return $hooks;
    }
}
