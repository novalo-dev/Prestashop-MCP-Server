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
 * somcp_upload_module - the Upload a module button of Modules > Module
 * Manager, for a .zip the employee deposited on the shop.
 *
 * The archive is not handed to the core's ZipSourceHandler: that handler
 * extracts every entry over whatever folder is already there, and an install
 * of a module already installed turns into an upgrade. The archive is checked
 * here first, extracted into a folder of our own, moved into modules/ in one
 * rename, then installed through somcp_install_module, so the install, its
 * refusals and its answer are written once. A failed install takes the
 * folder away again: it was not there before.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolUploadModule extends SomcpToolInstallModule
{
    /** @var int Above this, an archive is refused before being opened: the ceiling of any deposit. */
    private const MAX_BYTES = SomcpUpload::MAX_BYTES;

    /** @var int Unpacked size never above this, whatever the archive: a small zip can unpack to fill a disk. */
    private const MAX_UNPACKED = 268435456;

    /** @var int Unpacked size never above this many times the archive, for the same reason. */
    private const MAX_RATIO = 10;

    /** @var string Where the archive is checked and unpacked, out of reach of the web and of modules/. */
    private const WORK_DIR = 'var/somcp/';

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_upload_module',
            Somcp::t('Upload a module'),
            'Puts a module .zip deposited on the shop into the modules folder and installs it, like Upload a '
                . 'module in Modules > Module Manager. upload_ref: a .zip attached to the chat or deposited on the '
                . 'shop; never a url. One folder <name>/ with <name>/<name>.php; a module of that name already on '
                . 'the shop is refused, nothing replaced or upgraded. Answers as somcp_install_module, plus '
                . 'uploaded and file_name. The deposit is used up: not to be retried on a lost answer, check with '
                . 'somcp_get_module. A module already on disk goes to somcp_install_module.',
            [
                'type' => 'object',
                'properties' => [
                    'upload_ref' => [
                        'type' => 'string',
                        'description' => 'Reference of the .zip already on the shop: attached to the chat, or '
                            . 'deposited on the page opened for it. Used once.',
                    ],
                ],
                'required' => ['upload_ref'],
                'additionalProperties' => false,
            ],
            [SomcpScope::MODULES_UPLOAD],
            [self::class, 'handle'],
            // The Upload a module button asks for the add right on the Module
            // Manager, whose tab is AdminModulesSf, as the Install button does.
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
        $problems = [];
        foreach (SomcpTool::unknownFields($arguments, [SomcpUpload::ARG_REF]) as $line) {
            // A link would be read from anywhere and run as the shop: say why
            // it is refused, so the assistant asks for a deposit instead.
            $problems[] = strpos($line, 'url:') === 0
                ? 'url: not taken - a module archive comes by upload_ref only, deposited on the shop'
                : $line;
        }
        $reference = $arguments[SomcpUpload::ARG_REF] ?? null;
        if (!is_string($reference) || trim($reference) === '') {
            $problems[] = 'upload_ref: a string, the reference of the .zip deposited on the shop';
        }
        if ($problems !== []) {
            return SomcpTool::refused('installed', $problems);
        }

        // The shop comes from the token, never from the caller.
        $idShop = (int) Somcp::currentShop()->id;
        $idEmployee = (int) ($context['id_employee'] ?? 0);
        $reference = trim((string) $reference);

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($reference, $idShop): array {
            return self::upload($reference, $idShop);
        });
    }

    /**
     * Redeems, checks, places and installs, removing whatever it made on the way out.
     *
     * @param string $reference
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    private static function upload(string $reference, int $idShop): array
    {
        $work = self::workFolder();
        if ($work === '') {
            return SomcpTool::refused('installed', ['upload_ref: the shop has no room to receive it']);
        }

        try {
            $archive = $work . 'archive.zip';
            $fileName = null;
            $problem = SomcpToolCreateProduct::fromDeposit($reference, $archive, self::MAX_BYTES, $fileName);
            if ($problem !== '') {
                return SomcpTool::refused('installed', ['upload_ref: ' . $problem]);
            }
            $fileName = (string) $fileName;

            $name = '';
            $problems = self::check($archive, $fileName, $name);
            if ($problems !== []) {
                return SomcpTool::refused('installed', $problems);
            }

            $placed = self::place($archive, $work, $name);
            if ($placed !== '') {
                return SomcpTool::refused('installed', ['upload_ref: ' . $placed]);
            }

            $target = self::modulesDir() . $name;

            return self::install($name, $idShop, static function () use ($target): void {
                // The folder did not exist before this call: a module that
                // failed to install leaves nothing behind on disk.
                Tools::deleteDirectory($target);
            }, ['uploaded' => true, 'file_name' => $fileName]);
        } finally {
            Tools::deleteDirectory($work);
            // The module's folder under var/, when this call made it: rmdir
            // refuses it while the uploads or the memory still live there.
            @rmdir(rtrim((string) _PS_ROOT_DIR_, '/\\') . '/' . self::WORK_DIR);
        }
    }

    /**
     * Every problem of the archive, before anything is extracted.
     *
     * @param string $archive
     * @param string $fileName
     * @param string $name receives the module's folder name when the archive is valid
     *
     * @return array<int, string>
     */
    private static function check(string $archive, string $fileName, string &$name): array
    {
        $extension = Tools::strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension !== 'zip' || (string) @file_get_contents($archive, false, null, 0, 4) !== "PK\x03\x04") {
            return ['upload_ref: only a .zip archive is accepted'];
        }
        if (!class_exists('ZipArchive')) {
            return ['upload_ref: the shop cannot open .zip archives (PHP zip extension missing)'];
        }

        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            return ['upload_ref: only a .zip archive is accepted'];
        }

        $problems = [];
        $top = null;
        $unpacked = 0;
        $hasMain = false;
        $count = (int) $zip->numFiles;
        for ($i = 0; $i < $count; ++$i) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                $problems[] = 'upload_ref: the archive cannot be read';
                break;
            }
            $entry = (string) $stat['name'];
            $unpacked += (int) $stat['size'];

            // Paths are judged before anything touches the disk: an entry
            // outside its folder would land anywhere the shop can write.
            if ($entry === '' || $entry[0] === '/' || strpos($entry, '\\') !== false || strpos($entry, ':') !== false
                || strpos($entry, "\0") !== false || in_array('..', explode('/', $entry), true)) {
                $problems[] = 'upload_ref: an entry of the archive points outside its folder';
                continue;
            }
            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                && $opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                $problems[] = 'upload_ref: the archive holds a symbolic link';
                continue;
            }

            // A file at the root has no folder: it is the problem, not the
            // name of the module, which comes from the first folder met.
            $first = strpos($entry, '/') === false ? null : explode('/', $entry, 2)[0];
            if ($top === null && $first !== null) {
                $top = $first;
            }
            if ($first === null || $first !== $top) {
                $problems[] = 'upload_ref: the archive must hold one folder, the module\'s, and nothing beside it';
                continue;
            }
            if ($entry === $top . '/' . $top . '.php') {
                $hasMain = true;
            }
        }
        $zip->close();

        $top = (string) $top;
        if ($top === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $top) || !Validate::isModuleName($top)) {
            $problems[] = 'upload_ref: the folder of the archive is not a module name (letters, digits, - and _)';
        } elseif (!$hasMain) {
            $problems[] = 'upload_ref: ' . $top . '/' . $top . '.php is missing, so this is not a module archive';
        }
        $limit = min(self::MAX_UNPACKED, self::MAX_RATIO * max(1, (int) filesize($archive)));
        if ($unpacked > $limit) {
            $problems[] = 'upload_ref: the archive unpacks to more than ' . self::MAX_RATIO
                . ' times its size or 256 MB';
        }
        if ($problems === [] && self::onDisk($top)) {
            $problems[] = 'upload_ref: a module named ' . $top . ' is already on the shop; nothing was replaced';
        }
        if ($problems === []) {
            $name = $top;
        }

        return array_values(array_unique($problems));
    }

    /**
     * Unpacks into the work folder, then moves the module into modules/ in one rename.
     *
     * @param string $archive
     * @param string $work
     * @param string $name
     *
     * @return string empty on success, the reason otherwise
     */
    private static function place(string $archive, string $work, string $name): string
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            return 'the archive cannot be read';
        }
        $unpacked = $work . 'unpacked/';
        $done = @mkdir($unpacked, 0750) && $zip->extractTo($unpacked);
        $zip->close();
        if (!$done || !is_file($unpacked . $name . '/' . $name . '.php')) {
            return 'the shop could not unpack the archive';
        }

        // Checked again just before the move: another call may have placed
        // the same module meanwhile, and rename() would not refuse it.
        if (self::onDisk($name)) {
            return 'a module named ' . $name . ' is already on the shop; nothing was replaced';
        }
        if (!@rename($unpacked . $name, self::modulesDir() . $name)) {
            return 'the shop could not write inside its modules folder';
        }

        return '';
    }

    /**
     * @param string $name
     *
     * @return bool whether a folder of this name, in any case, is in modules/
     */
    private static function onDisk(string $name): bool
    {
        $key = Tools::strtolower($name);
        foreach (scandir(self::modulesDir()) ?: [] as $folder) {
            if (Tools::strtolower((string) $folder) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string
     */
    private static function modulesDir(): string
    {
        return rtrim((string) _PS_MODULE_DIR_, '/\\') . '/';
    }

    /**
     * A fresh folder for this call only, under var/, which PrestaShop never serves.
     *
     * Directly under var/somcp rather than in a folder shared by every call:
     * upload() removes it whatever happens, so nothing of this tool is left
     * for the uninstall to find.
     *
     * @return string empty when the shop cannot write there
     */
    private static function workFolder(): string
    {
        $base = rtrim((string) _PS_ROOT_DIR_, '/\\') . '/' . self::WORK_DIR;
        if (!is_dir($base) && !@mkdir($base, 0750, true) && !is_dir($base)) {
            return '';
        }
        $work = $base . 'module-upload-' . SomcpRandom::token(16) . '/';

        return @mkdir($work, 0750) ? $work : '';
    }
}
