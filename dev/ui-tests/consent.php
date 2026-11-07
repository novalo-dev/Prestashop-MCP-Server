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
 * Prepares the authorization page for the UI test.
 *
 *   php dev/ui-tests/consent.php <prestashop root> on
 *       switches the consent page on, off by default on a new install;
 *   php dev/ui-tests/consent.php <prestashop root> <six digits>
 *       writes that code over the one e-mailed for the last sign-in, since the
 *       test shop sends no e-mail the browser could read.
 */

$psRoot = realpath($argv[1] ?? '');
$what = (string) ($argv[2] ?? '');
if ($psRoot === false || !is_file($psRoot . '/config/config.inc.php') || ($what !== 'on' && !preg_match('/^\d{6}$/', $what))) {
    fwrite(STDERR, "Usage: php dev/ui-tests/consent.php <prestashop root> on|<six digits>\n");
    exit(1);
}

require __DIR__ . '/../shop-tests/boot.php';

$module = Module::getInstanceByName('somcp');
if (!$module instanceof Somcp || !Module::isInstalled('somcp')) {
    fwrite(STDERR, "The somcp module is not installed in this shop.\n");
    exit(1);
}
Somcp::bootClasses();

if ($what === 'on') {
    SomcpConfig::set(SomcpConfig::CONSENT_PAGE, '1');
    echo "Consent page switched on.\n";
    exit(0);
}

$db = Db::getInstance();
$idRequest = (int) $db->getValue('SELECT `id_request` FROM `' . _DB_PREFIX_ . 'somcp_oauth_request`'
    . ' WHERE `id_employee` > 0 AND `otp_verified` = 0 AND `consumed_at` IS NULL ORDER BY `id_request` DESC');
if ($idRequest <= 0) {
    fwrite(STDERR, "No sign-in is waiting for its code.\n");
    exit(1);
}
$db->update('somcp_oauth_request', [
    'otp_hash' => pSQL(SomcpOAuthServer::otpHash($idRequest, $what)),
    'otp_expires_at' => pSQL(date('Y-m-d H:i:s', time() + 600)),
    'otp_tries' => 0,
], '`id_request` = ' . $idRequest);
echo "Code written for request #$idRequest.\n";
