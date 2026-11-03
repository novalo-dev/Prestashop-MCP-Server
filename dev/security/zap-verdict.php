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
 * Reads the report of dev/security/zap.yaml and decides.
 *
 * A high-risk finding on one of the module's addresses fails the run. Medium
 * ones are shown as warnings: ZAP rates there things like a missing header on
 * a JSON answer no browser renders, which deserve a look but not a red build.
 * Findings on addresses outside the module are left out: they are
 * PrestaShop's, and the core is not what is being tested.
 *
 * Usage: php dev/security/zap-verdict.php <traditional-json report>
 * Exit code 0 when nothing high was found, 1 otherwise.
 */

$report = json_decode((string) @file_get_contents($argv[1] ?? ''), true);
if (!is_array($report) || !isset($report['site'])) {
    echo "::error title=ZAP report missing::the scan did not produce a readable report\n";
    exit(1);
}

$annotation = static function (string $text): string {
    return str_replace(["\r", "\n", '%'], [' ', ' ', '%25'], $text);
};

$high = 0;
$medium = 0;
foreach ($report['site'] as $site) {
    foreach ($site['alerts'] ?? [] as $alert) {
        $ours = array_values(array_filter($alert['instances'] ?? [], static function (array $instance): bool {
            return strpos((string) ($instance['uri'] ?? ''), 'module=somcp') !== false;
        }));
        if ($ours === []) {
            continue;
        }
        $risk = (int) ($alert['riskcode'] ?? 0);
        $first = $ours[0];
        $where = ($first['method'] ?? '') . ' ' . ($first['uri'] ?? '')
            . (($first['param'] ?? '') !== '' ? ' [' . $first['param'] . ']' : '')
            . (count($ours) > 1 ? ' and ' . (count($ours) - 1) . ' more' : '');
        printf("%-6s %s - %s\n", ['Info', 'Low', 'Medium', 'High'][$risk] ?? '?', (string) $alert['alert'], $where);
        if ($risk >= 3) {
            ++$high;
            echo '::error title=ZAP: ' . $annotation((string) $alert['alert']) . '::' . $annotation($where) . "\n";
        } elseif ($risk === 2) {
            ++$medium;
            echo '::warning title=ZAP: ' . $annotation((string) $alert['alert']) . '::' . $annotation($where) . "\n";
        }
    }
}

printf("\n%d high, %d medium on the module's addresses\n", $high, $medium);
exit($high === 0 ? 0 : 1);
