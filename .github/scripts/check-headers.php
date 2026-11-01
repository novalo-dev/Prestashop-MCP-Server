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
 * CI check: every source file opens with the module's licence header.
 *
 * PrestaShop Addons reads the header of each file when it validates a
 * module, and a file added without one - or with the header of the project
 * it was copied from - says the code is under someone else's terms. The
 * header is checked word for word, so a wrong author, year or licence fails
 * as surely as a missing one.
 *
 * The CI only reports: a bot commit would be unsigned, and a pull request
 * from a fork cannot be written to anyway. --fix writes the header into the
 * files on your own machine, before you commit.
 *
 * Usage: php .github/scripts/check-headers.php [module root] [--fix]
 * Exit code 0 when every file is in order, 1 otherwise.
 */

const BODY = [
    'NOTICE OF LICENSE',
    '',
    'This source file is subject to the Academic Free License version 3.0',
    'that is bundled with this package in the file LICENSE.txt.',
    'It is also available through the world-wide-web at this URL:',
    'https://opensource.org/licenses/AFL-3.0',
    'If you did not receive a copy of the license and are unable to obtain',
    'it through the world-wide-web, please send an email to',
    'contact@novalo.fr so we can send you a copy immediately.',
    '',
    '@author    Novalo <contact@novalo.fr>',
    '@copyright 2026 Novalo',
    '@license   https://opensource.org/licenses/AFL-3.0 Academic Free License v3.0 (AFL-3.0)',
];

// Comment syntax by extension: how the header opens and closes.
const STYLES = [
    'php' => ['/**', ' */'],
    'js' => ['/**', ' */'],
    'css' => ['/*', ' */'],
    'tpl' => ['{*', ' *}'],
];

// Third-party code, generated files and the documentation's own examples,
// which show a module that is not this one.
const SKIPPED = ['vendor', 'node_modules', 'docs', 'dev/tools/vendor'];

$args = array_slice($argv, 1);
$fix = in_array('--fix', $args, true);
$paths = array_values(array_filter($args, static function (string $arg): bool {
    return $arg !== '--fix';
}));
$root = rtrim($paths[0] ?? getcwd(), '/');

/**
 * The header as it must stand in a file of this extension.
 */
function headerFor(string $extension): string
{
    [$open, $close] = STYLES[$extension];
    $lines = [$open];
    foreach (BODY as $line) {
        $lines[] = $line === '' ? ' *' : ' * ' . $line;
    }
    $lines[] = $close;

    return implode("\n", $lines) . "\n";
}

$files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    static function (SplFileInfo $file) use ($root): bool {
        $relative = substr($file->getPathname(), strlen($root) + 1);

        // Hidden folders hold tooling and caches, except .github and its scripts.
        if ($file->getFilename()[0] === '.' && strpos($relative . '/', '.github/') !== 0) {
            return false;
        }

        return !in_array($relative, SKIPPED, true);
    }
));

$problems = [];
$fixed = 0;
$checked = 0;
foreach ($files as $file) {
    $extension = strtolower($file->getExtension());
    if (!isset(STYLES[$extension])) {
        continue;
    }
    ++$checked;
    $path = $file->getPathname();
    $relative = substr($path, strlen($root) + 1);
    $content = str_replace("\r\n", "\n", (string) file_get_contents($path));
    $header = headerFor($extension);

    // PHP keeps its opening tag on the first line, the header right under it.
    $prefix = '';
    $rest = $content;
    if ($extension === 'php') {
        if (strncmp($content, "<?php\n", 6) !== 0) {
            $problems[] = $relative . ': does not open with <?php on its own line';
            continue;
        }
        $prefix = "<?php\n";
        $rest = substr($content, 6);
    }

    if (strncmp($rest, $header, strlen($header)) === 0) {
        continue;
    }

    // A first comment that names a licence is the header to replace; any
    // other first comment is the file's own and stays below the new header.
    [$open, $close] = STYLES[$extension];
    $end = strpos($rest, trim($close));
    $first = strncmp(ltrim($rest), trim($open), strlen(trim($open))) === 0 && $end !== false
        ? substr($rest, 0, $end + strlen(trim($close)))
        : '';
    $replacing = $first !== '' && stripos($first, 'license') !== false;
    $problems[] = $relative . ($replacing ? ': licence header differs from the module\'s' : ': no licence header');

    if ($fix) {
        $body = $replacing ? ltrim(substr(ltrim($rest), strlen(ltrim($first))), "\n") : ltrim($rest, "\n");
        file_put_contents($path, $prefix . $header . (trim($body) === '' ? '' : $body));
        ++$fixed;
    }
}

foreach ($problems as $problem) {
    echo ($fix ? 'fixed: ' : '::error::') . $problem . PHP_EOL;
}
if ($fix) {
    echo $fixed . ' file(s) given the licence header, ' . $checked . ' checked' . PHP_EOL;
    exit(0);
}
if ($problems !== []) {
    echo count($problems) . ' file(s) without the module\'s licence header, out of ' . $checked
        . '. Run php .github/scripts/check-headers.php . --fix and commit the result.' . PHP_EOL;
    exit(1);
}
echo 'Headers OK: ' . $checked . ' files carry the module\'s licence header.' . PHP_EOL;
exit(0);
