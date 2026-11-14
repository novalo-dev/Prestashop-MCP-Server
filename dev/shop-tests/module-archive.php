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
 * A minimal module archive for the shop tests of somcp_upload_module and
 * somcp_upgrade_module: one folder <name>/ with its main file and config.xml,
 * as a merchant's .zip holds it. A version and extra files (an upgrade
 * script) can be given, so one builder serves the install of a 1.0.0 and the
 * upgrade to a 1.1.0.
 */

/**
 * @param string $name the module's technical name, lower case
 * @param string $version
 * @param array<string, string> $extra more files, by path inside the module's folder
 * @param string $minimum the lowest PrestaShop version the module declares
 *
 * @return string the bytes of the .zip
 */
function somcpTestModuleArchive(string $name, string $version, array $extra = [], string $minimum = '8.0.0'): string
{
    $main = "<?php\nif (!defined('_PS_VERSION_')) {\n    exit;\n}\n\nclass " . ucfirst($name) . " extends Module\n{\n"
        . "    public function __construct()\n    {\n"
        . "        \$this->name = '" . $name . "';\n"
        . "        \$this->tab = 'administration';\n"
        . "        \$this->version = '" . $version . "';\n"
        . "        \$this->author = 'Novalo';\n"
        . "        \$this->need_instance = 0;\n"
        . "        \$this->ps_versions_compliancy = ['min' => '" . $minimum . "', 'max' => _PS_VERSION_];\n"
        . "        parent::__construct();\n"
        . "        \$this->displayName = 'Somcp test module';\n"
        . "        \$this->description = 'Installed and removed by the shop tests.';\n"
        . "    }\n}\n";
    $config = "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n<module>\n    <name>" . $name . "</name>\n"
        . "    <displayName><![CDATA[Somcp test module]]></displayName>\n"
        . "    <version><![CDATA[" . $version . "]]></version>\n"
        . "    <description><![CDATA[Installed and removed by the shop tests.]]></description>\n"
        . "    <author><![CDATA[Novalo]]></author>\n    <tab><![CDATA[administration]]></tab>\n"
        . "    <is_configurable>0</is_configurable>\n    <need_instance>0</need_instance>\n</module>\n";

    $file = (string) tempnam(sys_get_temp_dir(), 'somcp-module');
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::OVERWRITE);
    $zip->addFromString($name . '/' . $name . '.php', $main);
    $zip->addFromString($name . '/config.xml', $config);
    foreach ($extra as $path => $contents) {
        $zip->addFromString($name . '/' . $path, $contents);
    }
    $zip->close();
    $bytes = (string) file_get_contents($file);
    @unlink($file);

    return $bytes;
}

/**
 * An upgrade script that leaves a trace the test reads back from the database.
 *
 * @param string $version
 * @param string $key the configuration key it writes the version into
 *
 * @return string
 */
function somcpTestUpgradeScript(string $version, string $key): string
{
    return "<?php\nif (!defined('_PS_VERSION_')) {\n    exit;\n}\n\nfunction upgrade_module_" . str_replace('.', '_', $version)
        . "(\$module)\n{\n    return Configuration::updateGlobalValue('" . $key . "', '" . $version . "');\n}\n";
}
