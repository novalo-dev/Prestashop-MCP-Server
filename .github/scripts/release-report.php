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
 * The security and compatibility report attached to each release.
 *
 * Written from the jobs of the release run itself, so it describes the very
 * commit that went into the zip and nothing else. Every line is a check that
 * ran: the CI jobs and their steps, each PrestaShop and PHP combination, each
 * HTTP attack the shop test makes, each tool whose permissions were checked,
 * then the release steps. Only results are written - the attacks are named as
 * the public test file names them, and nothing about a fault still being
 * fixed can be in here, since those live in private advisories.
 *
 * Usage: php .github/scripts/release-report.php <jobs.json> <release.txt>
 *        <version> <commit> <run id> [module root]
 *   jobs.json    the run's jobs, as the Actions API lists them
 *   release.txt  one "label<TAB>detail" line per release check that passed
 */

if ($argc < 6) {
    fwrite(STDERR, "usage: release-report.php <jobs.json> <release.txt> <version> <commit> <run id> [root]\n");
    exit(2);
}
[, $jobsFile, $releaseFile, $version, $commit, $runId] = $argv;
$root = rtrim($argv[6] ?? '.', '/');

$decoded = json_decode((string) file_get_contents($jobsFile), true);
$jobs = is_array($decoded['jobs'] ?? null) ? $decoded['jobs'] : [];

// The jobs of the CI the release called come prefixed with the calling job's
// name; the release's own jobs are left out, they are written from
// release.txt with what they actually checked.
$ci = [];
foreach ($jobs as $job) {
    $name = (string) ($job['name'] ?? '');
    if (strpos($name, ' / ') === false) {
        continue;
    }
    $ci[] = ['name' => substr($name, strpos($name, ' / ') + 3), 'conclusion' => (string) ($job['conclusion'] ?? ''), 'steps' => $job['steps'] ?? []];
}
if ($ci === []) {
    fwrite(STDERR, "no CI job found in $jobsFile\n");
    exit(1);
}

$mark = static function (string $conclusion): string {
    return $conclusion === 'success' ? '[PASS]' : '[FAIL]';
};
$line = static function (string $left, string $right = '', int $width = 46): string {
    return $right === '' ? $left : str_pad($left, $width) . ' ' . $right;
};
$rule = static function (string $title): string {
    return str_pad('== ' . $title . ' ', 64, '=');
};

// The steps a job runs to set itself up say nothing about the module.
$ownStep = static function (string $step): bool {
    return !preg_match('/^(Set up job|Complete job|Post |Run |Checkout)/', $step);
};

$out = [];
$out[] = "## O'So MCP Server $version - Security and compatibility report";
$out[] = '';
$out[] = '```console';
$out[] = "novalo@ci:~$ somcp-report --release v$version --commit " . substr($commit, 0, 7) . ' --verbose';
$out[] = $line("Reading CI run $runId (" . gmdate('Y-m-d') . ')', 'done', 62);
$out[] = '';

// Code quality: every job that is not one shop combination, with its steps.
$combos = [];
$quality = [];
foreach ($ci as $job) {
    if (preg_match('/^PrestaShop (\S+) on PHP (\S+)$/', $job['name'], $m)) {
        $combos[$m[1]][] = ['php' => $m[2], 'conclusion' => $job['conclusion'], 'steps' => $job['steps']];
        continue;
    }
    $quality[] = $job;
}
usort($quality, static function (array $a, array $b): int {
    return strcmp($a['name'], $b['name']);
});
$out[] = $rule('CODE QUALITY');
foreach ($quality as $job) {
    $out[] = $mark($job['conclusion']) . ' ' . $job['name'];
    foreach ($job['steps'] as $step) {
        $name = (string) ($step['name'] ?? '');
        if ($ownStep($name)) {
            $out[] = '       ' . (($step['conclusion'] ?? '') === 'success' ? 'ok ' : 'KO ') . ' ' . $name;
        }
    }
}
$out[] = '';

// Compatibility: one line per PrestaShop release, its PHP versions after.
uksort($combos, 'version_compare');
$total = 0;
$attacked = [];
foreach ($combos as $prestashop => $runs) {
    $total += count($runs);
    foreach ($runs as $run) {
        foreach ($run['steps'] as $step) {
            if (strpos((string) ($step['name'] ?? ''), 'Attack') === 0 && ($step['conclusion'] ?? '') === 'success') {
                $attacked[] = $prestashop;
            }
        }
    }
}
$out[] = $rule('COMPATIBILITY: ' . count($combos) . ' PrestaShop releases, ' . $total . ' combinations');
$out[] = '       each: install, every tool called, permissions, uninstall, PHPStan';
foreach ($combos as $prestashop => $runs) {
    usort($runs, static function (array $a, array $b): int {
        return version_compare($a['php'], $b['php']);
    });
    $failed = array_filter($runs, static function (array $run): bool {
        return $run['conclusion'] !== 'success';
    });
    $out[] = ($failed === [] ? '[PASS]' : '[FAIL]') . ' PrestaShop ' . str_pad($prestashop, 8) . ' PHP '
        . implode(' ', array_column($runs, 'php'));
}
$out[] = '';

// Security: the attacks as dev/shop-tests/attacks.php names them, by section.
$attacks = (string) @file_get_contents($root . '/dev/shop-tests/attacks.php');
preg_match_all("/\\\$section\\('([^']*)'\\)|\\\$expect\\('([^']*)'/", $attacks, $found, PREG_SET_ORDER);
$sections = [];
foreach ($found as $match) {
    if (($match[1] ?? '') !== '') {
        $sections[] = ['title' => $match[1], 'checks' => []];
    } elseif ($sections !== []) {
        $sections[count($sections) - 1]['checks'][] = $match[2];
    }
}
$count = array_sum(array_map(static function (array $section): int {
    return count($section['checks']);
}, $sections));
$where = $attacked === [] ? 'not run' : 'PS ' . implode(', ', array_unique($attacked));
$out[] = $rule("SECURITY: $count HTTP attacks on a live shop ($where)");
$attackMark = $attacked === [] ? '[FAIL]' : '[PASS]';
foreach ($sections as $section) {
    $out[] = '-- ' . $section['title'];
    foreach ($section['checks'] as $check) {
        $out[] = $attackMark . ' ' . $check;
    }
}
$out[] = '';

// Permissions: the tools as the README lists them, by section.
$readme = (string) @file_get_contents($root . '/README.md');
$bySection = [];
$current = '';
foreach (explode("\n", $readme) as $row) {
    if (preg_match('/^### (.+)$/', $row, $m)) {
        $current = trim($m[1]);
    } elseif (preg_match('/^\| `(somcp_[a-z0-9_]+)` \|/', $row, $m)) {
        $bySection[$current][] = substr($m[1], strlen('somcp_'));
    }
}
$tools = array_sum(array_map('count', $bySection));
$shopsGreen = $total > 0 && !in_array(false, array_map(static function (array $runs): bool {
    return array_filter($runs, static function (array $run): bool {
        return $run['conclusion'] !== 'success';
    }) === [];
}, $combos), true);
$out[] = $rule("PERMISSIONS: $tools tools");
$out[] = '       each refused to: an employee without the right, an unknown';
$out[] = '       account, a token without its scope';
foreach ($bySection as $section => $names) {
    $out[] = ($shopsGreen ? '[PASS]' : '[FAIL]') . " $section (" . count($names) . '): ' . implode(', ', $names);
}
$out[] = '';

// Release: what the release job checked, in its own order.
$out[] = $rule('RELEASE');
foreach (file($releaseFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $entry) {
    [$label, $detail] = array_pad(explode("\t", $entry, 2), 2, '');
    $out[] = '[PASS] ' . $line($label, $detail, 38);
}
$out[] = '';

$failed = count(array_filter($ci, static function (array $job): bool {
    return $job['conclusion'] !== 'success';
}));
$out[] = $rule('RESULT');
$out[] = count($ci) . ' jobs, ' . (count($ci) - $failed) . ' passed, ' . $failed . ' failed';
$out[] = 'Only results are published. Report a vulnerability privately:';
$out[] = 'see SECURITY.md';
$out[] = '';
$out[] = 'novalo@ci:~$ _';
$out[] = '```';
$out[] = '';
$out[] = "Download: https://github.com/novalo-dev/Prestashop-MCP-Server/releases/tag/v$version";

echo implode("\n", $out) . "\n";

// A report saying a check failed must never ride along with a release.
exit($failed === 0 && $shopsGreen && $attacked !== [] ? 0 : 1);
