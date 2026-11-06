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
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_3_0($module)
{
    Somcp::bootClasses();
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'configuration';

    // The list of allowed return addresses used to mean "anything goes" when
    // empty, and it was empty on almost every shop. It now means "refuse every
    // public host", so an empty list is filled before the new rule applies:
    // with the clients the module is known to work with, plus the hosts of the
    // assistants already connected - a self-hosted n8n, for one, would
    // otherwise stop working the moment the shop is updated. Only clients an
    // employee has approved count: an application that merely registered
    // itself has proved nothing and stays out.
    $known = array_keys(SomcpOAuthClient::KNOWN_HOSTS);
    $connectedHosts = static function (?int $idShop) use ($db): array {
        $sql = 'SELECT DISTINCT c.`redirect_uris` FROM `' . _DB_PREFIX_ . 'somcp_oauth_client` c'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'somcp_oauth_consent` k ON k.`id_client` = c.`id_client`'
            . ($idShop !== null ? ' WHERE k.`id_shop` = ' . (int) $idShop : '');
        $hosts = [];
        foreach ((array) $db->executeS($sql) as $row) {
            $uris = json_decode((string) $row['redirect_uris'], true);
            foreach (is_array($uris) ? $uris : [] as $uri) {
                $parts = parse_url((string) $uri);
                $host = is_array($parts) ? Tools::strtolower((string) ($parts['host'] ?? '')) : '';
                if (($parts['scheme'] ?? '') === 'https' && $host !== ''
                    && !in_array($host, ['127.0.0.1', '[::1]', '::1', 'localhost'], true)) {
                    $hosts[] = $host;
                }
            }
        }

        return $hosts;
    };
    $listFor = static function (?int $idShop) use ($known, $connectedHosts): string {
        return implode(' ', array_values(array_unique(array_merge($known, $connectedHosts($idShop)))));
    };

    $rows = $db->executeS('SELECT `id_configuration`, `id_shop_group`, `id_shop`, `value` FROM `' . bqSQL($table) . '`'
        . ' WHERE `name` = "' . pSQL(SomcpConfig::REDIRECT_HOSTS) . '"');
    $rows = is_array($rows) ? $rows : [];
    $hasGlobal = false;
    foreach ($rows as $row) {
        $idShop = $row['id_shop'] === null ? null : (int) $row['id_shop'];
        if ($idShop === null && $row['id_shop_group'] === null) {
            $hasGlobal = true;
        }
        if (trim((string) $row['value']) !== '') {
            // A list the merchant chose already narrowed the hosts: kept as it is.
            continue;
        }
        $db->update(
            'configuration',
            ['value' => pSQL($listFor($idShop)), 'date_upd' => date('Y-m-d H:i:s')],
            '`id_configuration` = ' . (int) $row['id_configuration']
        );
    }
    if (!$hasGlobal) {
        Configuration::updateGlobalValue(SomcpConfig::REDIRECT_HOSTS, $listFor(null));
    }

    // The employee who signs in on the authorization page is recorded on the
    // pending request.
    if (!$module->installDatabase()) {
        return false;
    }

    // The consent screen left the back office for the shop front's
    // authorization page: its hidden tab has nothing left to open. Its
    // PrestaShop 9 route went with it, and Symfony keeps a compiled copy of the
    // routes and services that still names the removed class until the cache
    // is rebuilt.
    $idTab = (int) Tab::getIdFromClassName('AdminSomcpConsent');
    if ($idTab > 0) {
        $tab = new Tab($idTab);
        $tab->delete();
    }
    if (method_exists('Tools', 'clearSf2Cache')) {
        Tools::clearSf2Cache();
    }

    return true;
}
