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
 * somcp_list_modules, somcp_get_module, somcp_install_module and
 * somcp_set_module_active return the same fields for a module; building them
 * here once keeps the mobile bit, the needs_upgrade rule and the null cases
 * identical, so one module never reads differently in two tools. The writes
 * reach the core's ModuleManager through one function here too, so they all
 * drop what a module prints and gather its errors the same way.
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
     * One module of this shop by its technical name, as somcp_get_module
     * returns it: the row of somcp_list_modules plus its hooks.
     *
     * Kept here so a write on a module answers with exactly what a read of
     * it would give, and the assistant never has to reconcile two shapes.
     *
     * @param string $name compared without case
     * @param int $idShop
     *
     * @return array<string, mixed>|null null when the name is neither on disk nor installed
     */
    public static function read(string $name, int $idShop): ?array
    {
        $key = Tools::strtolower(trim($name));
        $states = self::states($idShop);
        $state = $states[$key] ?? null;

        $found = null;
        foreach (self::onDisk() as $item) {
            if (Tools::strtolower((string) ($item->name ?? '')) === $key) {
                $found = $item;
                break;
            }
        }
        if ($found === null && $state === null) {
            return null;
        }
        if ($found === null) {
            // Installed but its folder is gone: the database still knows it,
            // so its state is returned under the name it was installed with.
            $stored = Db::getInstance()->getValue(
                'SELECT `name` FROM `' . _DB_PREFIX_ . 'module` WHERE `id_module` = ' . (int) $state['id_module']
            );
            $found = (object) ['name' => (string) $stored];
        }

        $module = self::row($found, $state);
        // A module disabled here keeps its rows in hook_module, but none of
        // its hooks runs on this shop: the screen and the tool show none.
        $module['hooks'] = $state !== null && $module['active']
            ? self::hooks($state['id_module'], $idShop)
            : [];

        return $module;
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

    /**
     * Runs one or more actions of the core's ModuleManager on a module, as
     * the buttons of its card in Modules > Module Manager do.
     *
     * @param string $folder the module's technical name, as the core knows it
     * @param callable $action receives the ModuleManager, returns true on success
     *
     * @return array{done: bool, errors: array<int, string>}
     */
    public static function throughCore(string $folder, callable $action): array
    {
        $done = false;
        $errors = [];
        // A module may print while it installs, enables or disables, which
        // would break the JSON of the answer: what it prints is dropped.
        $level = ob_get_level();
        ob_start();
        try {
            self::prepareLanguageContext();
            // The core's own way to reach its ModuleManager from legacy code:
            // the Symfony container when there is one, the same classes built
            // by hand otherwise, on PrestaShop 8 and 9 alike.
            $manager = PrestaShop\PrestaShop\Core\Addon\Module\ModuleManagerBuilder::getInstance()->build();
            $done = (bool) $action($manager);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        if (!$done) {
            $legacy = Module::getInstanceByName($folder);
            if ($legacy instanceof Module) {
                foreach ($legacy->getErrors() as $error) {
                    $errors[] = (string) $error;
                }
            }
        }

        return [
            'done' => $done,
            'errors' => array_values(array_unique(array_filter(array_map('trim', $errors)))),
        ];
    }

    /**
     * Gives the language of the call to the core's language context, on
     * PrestaShop 9.
     *
     * There the ModuleManager's disable and enable reach services built from
     * that context, which only a back office or API request listener prepares.
     * The MCP endpoint is neither, so without this the core throws before it
     * changes anything. The core's own command line and installer prepare it
     * the same way. Named as a string: the class does not exist on 8, which
     * needs nothing here.
     */
    private static function prepareLanguageContext(): void
    {
        $id = 'PrestaShop\\PrestaShop\\Core\\Context\\ContextBuilderPreparer';
        if (!class_exists($id)) {
            return;
        }

        $container = PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
        if ($container === null || !$container->has($id)) {
            return;
        }

        // Typed by hand: PrestaShop 9's container declares a non-null object,
        // 8's an object or null, and one check has to hold on both.
        /** @var object|null $preparer */
        $preparer = $container->get($id);
        if (!is_object($preparer) || !method_exists($preparer, 'prepareLanguageId')) {
            return;
        }

        $language = Somcp::legacyContext()->language;
        $idLang = $language instanceof Language && (int) $language->id > 0
            ? (int) $language->id
            : (int) Configuration::get('PS_LANG_DEFAULT');
        if ($idLang > 0) {
            $preparer->prepareLanguageId($idLang);
        }
    }
}
