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
 * somcp_upgrade_module - a newer .zip of an installed module, deposited on
 * the shop: its files replaced, then its upgrade scripts run, as uploading a
 * newer archive of an installed module does in Modules > Module Manager.
 *
 * The other half of somcp_upload_module, which takes new modules only: the
 * archive passes the same checks (SomcpToolUploadModule::check()) and is
 * placed the same way, the current folder moved aside first and put back if
 * anything fails before the upgrade starts.
 *
 * The core's ModuleManager::upgrade() runs the scripts and nothing else: it
 * checks neither the PrestaShop versions the module declares nor its
 * dependencies, which Module::install() checks for a new module. They are
 * checked here, on the new files, before the upgrade, as the back office
 * checks a module before installing it.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in:
// Upgrade sorts before Upload, and a class cannot be declared before the one
// it extends.
require_once __DIR__ . '/SomcpToolUploadModule.php';

class SomcpToolUpgradeModule extends SomcpToolUploadModule
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_upgrade_module',
            Somcp::t('Upgrade a module'),
            'Upgrades an installed module from a newer .zip deposited on the shop: its files replaced, then its '
                . 'upgrade scripts run, like uploading a newer archive in Modules > Module Manager. upload_ref: a '
                . '.zip from somcp_upload_file or attached to the chat; never a url. Refused unless the module is '
                . 'installed, the archive is the same module, of a higher version, compatible with this PrestaShop, '
                . 'with its dependencies installed. Ask the merchant first. Not to be retried on a lost answer: '
                . 'check with somcp_get_module. A new module goes to somcp_upload_module.',
            [
                'type' => 'object',
                'properties' => [
                    'upload_ref' => [
                        'type' => 'string',
                        'description' => 'Reference of the .zip on the shop, from somcp_upload_file or attached to '
                            . 'the chat. Used once.',
                    ],
                ],
                'required' => ['upload_ref'],
                'additionalProperties' => false,
            ],
            [SomcpScope::MODULES_UPLOAD],
            [self::class, 'handle'],
            // The core asks the add right on the Module Manager to upgrade, as
            // to install or upload (AdminModuleDataProvider::isAllowedAccess()).
            SomcpTool::acts(SomcpPermission::ADD, 'AdminModulesSf'),
            files: [SomcpUpload::ARG_REF => SomcpUpload::rule('archive', self::MAX_BYTES, false)]
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
        $problems = [];
        foreach (SomcpTool::unknownFields($arguments, [SomcpUpload::ARG_REF]) as $line) {
            $problems[] = strpos($line, 'url:') === 0
                ? 'url: not taken - a module archive comes by upload_ref only, deposited on the shop'
                : $line;
        }
        $reference = $arguments[SomcpUpload::ARG_REF] ?? null;
        if (!is_string($reference) || trim($reference) === '') {
            $problems[] = 'upload_ref: a string, the reference of the .zip deposited on the shop';
        }
        if ($problems !== []) {
            return SomcpTool::refused('upgraded', $problems);
        }

        // The shop comes from the token, never from the caller.
        $idShop = (int) Somcp::currentShop()->id;
        $idEmployee = (int) ($context['id_employee'] ?? 0);
        $reference = trim((string) $reference);

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($reference, $idShop): array {
            return self::upgrade($reference, $idShop);
        });
    }

    /**
     * Redeems, checks, places, checks the new files, then upgrades.
     *
     * @param string $reference
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    private static function upgrade(string $reference, int $idShop): array
    {
        $work = self::workFolder();
        if ($work === '') {
            return SomcpTool::refused('upgraded', ['upload_ref: the shop has no room to receive it']);
        }

        try {
            $archive = $work . 'archive.zip';
            $fileName = null;
            $problem = SomcpUpload::fromDeposit($reference, $archive, self::MAX_BYTES, $fileName);
            if ($problem !== '') {
                return SomcpTool::refused('upgraded', ['upload_ref: ' . $problem]);
            }

            $name = '';
            $problems = self::check($archive, (string) $fileName, $name, true);
            if ($problems !== []) {
                return SomcpTool::refused('upgraded', $problems);
            }

            $before = SomcpModules::read($name, $idShop);
            if ($before === null || !$before['installed']) {
                return SomcpTool::refused('upgraded', ['upload_ref: ' . $name . ' is on the shop but not installed;'
                    . ' it is installed with somcp_install_module, not upgraded']);
            }
            $installed = (string) $before['version_installed'];
            $version = self::versionIn($archive, $name);
            if ($version === '') {
                return SomcpTool::refused('upgraded', ['upload_ref: the archive says no version (config.xml or'
                    . ' $this->version in ' . $name . '.php)']);
            }
            if (!version_compare($version, $installed, '>')) {
                return SomcpTool::refused('upgraded', ['upload_ref: the archive holds ' . $name . ' ' . $version
                    . ', not higher than the installed ' . $installed . '; nothing was replaced']);
            }

            $placed = self::place($archive, $work, $name, true);
            if ($placed !== '') {
                return SomcpTool::refused('upgraded', ['upload_ref: ' . $placed]);
            }

            $unfit = self::unfit($name, $version);
            if ($unfit !== []) {
                self::restore($work, $name);

                return SomcpTool::refused('upgraded', array_map(static function (string $reason): string {
                    return 'upload_ref: ' . $reason;
                }, $unfit + [count($unfit) => 'the current files are kept']));
            }

            $run = SomcpModules::throughCore($name, static function ($manager) use ($name): bool {
                return (bool) $manager->upgrade($name);
            });
            $after = SomcpModules::read($name, $idShop) ?? ['name' => $name, 'installed' => false];
            if ($run['done'] && (string) ($after['version_installed'] ?? '') === $version) {
                return SomcpTool::json(['upgraded' => true, 'version_before' => $installed, 'version_after' => $version] + $after);
            }

            // The scripts ran, or some of them: what they changed in the
            // database cannot be told apart from here, so the new files stay,
            // as the back office leaves them.
            return SomcpTool::text(
                'The module was not upgraded' . ($run['errors'] !== [] ? ': ' . implode(' ', $run['errors']) : '.')
                    . ' Its new files are in place, and whatever its upgrade scripts already changed in the database'
                    . ' stays changed. State read back: '
                    . json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                true
            );
        } finally {
            Tools::deleteDirectory($work);
            @rmdir(rtrim((string) _PS_ROOT_DIR_, '/\\') . '/' . self::WORK_DIR);
        }
    }

    /**
     * The version the archive declares, read without running any of it.
     *
     * config.xml first, as the Module Manager reads it; the main file's
     * $this->version otherwise, as a text, never included.
     *
     * @param string $archive
     * @param string $name
     *
     * @return string empty when none is found
     */
    private static function versionIn(string $archive, string $name): string
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            return '';
        }
        $config = (string) $zip->getFromName($name . '/config.xml');
        $main = (string) $zip->getFromName($name . '/' . $name . '.php');
        $zip->close();

        if (preg_match('/<version>\s*(?:<!\[CDATA\[)?\s*([0-9][0-9A-Za-z.+-]*)\s*(?:\]\]>)?\s*<\/version>/', $config, $found) === 1
            || preg_match('/\$this->version\s*=\s*[\'"]([0-9][0-9A-Za-z.+-]*)[\'"]/', $main, $found) === 1) {
            return $found[1];
        }

        return '';
    }

    /**
     * What the back office checks before installing a module, on the new
     * files: the core's upgrade checks none of it.
     *
     * Loading the module runs its main file, as the Module Manager does for
     * every module it lists. A module already loaded in this request keeps
     * its old code, which the version tells.
     *
     * @param string $name
     * @param string $version the version the archive declares
     *
     * @return array<int, string> the reasons, empty when the new files fit
     */
    private static function unfit(string $name, string $version): array
    {
        $module = Module::getInstanceByName($name);
        if (!$module instanceof Module) {
            return ['the new files of ' . $name . ' do not load as a module'];
        }
        if ((string) $module->version !== $version) {
            return ['the module answers as version ' . (string) $module->version . ', not the ' . $version
                . ' of its archive; upgrade it from Modules > Module Manager'];
        }

        $reasons = [];
        if (!$module->checkCompliancy()) {
            $reasons[] = $name . ' ' . $version . ' is not made for PrestaShop ' . _PS_VERSION_;
        }
        foreach ((array) $module->dependencies as $dependency) {
            if (!Module::isInstalled((string) $dependency)) {
                $reasons[] = $name . ' ' . $version . ' needs the module ' . (string) $dependency . ' installed first';
            }
        }

        return $reasons;
    }

    /**
     * Puts the module's previous folder back where place() moved it from.
     *
     * @param string $work
     * @param string $name
     */
    private static function restore(string $work, string $name): void
    {
        Tools::deleteDirectory(self::modulesDir() . $name);
        @rename($work . 'previous/' . $name, self::modulesDir() . $name);
    }
}
