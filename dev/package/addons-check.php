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
 * The rules a PrestaShop Addons module is held to, checked on the module
 * folder as it ships: laid out by build.sh from git archive, given its
 * Composer dependencies and pruned. Run on every push, so a breach fails the
 * commit that brought it rather than the release.
 *
 * Usage: php dev/package/addons-check.php <module folder>
 *
 * Exits 1 on any finding, printing one line per finding and a GitHub
 * annotation for each, then a summary line.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = rtrim($argv[1] ?? '', '/');
if ($root === '' || !is_file($root . '/somcp.php')) {
    fwrite(STDERR, "usage: php addons-check.php <module folder holding somcp.php>\n");
    exit(2);
}

// The module's own .htaccess keeps the shop from serving its files directly.
const HIDDEN_ALLOWED = ['.htaccess'];

// What a merchant's shop has no reason to receive: executables, archives,
// scripts for another runtime, source maps and editor leftovers.
const FORBIDDEN_EXTENSIONS = [
    'sh', 'bash', 'bat', 'cmd', 'exe', 'com', 'dll', 'so', 'bin', 'phar',
    'py', 'rb', 'pl', 'jar', 'zip', 'tar', 'gz', 'tgz', 'rar', '7z',
    'map', 'bak', 'orig', 'swp', 'log', 'sql~', 'php~', 'dist',
];

// Calls that run code or a process, or rebuild objects from a string. None
// belongs in a shop module; prune.sh removes the dependency parts that hold
// them, so a finding here is either new module code or a pruning gap.
const FORBIDDEN_FUNCTIONS = [
    'eval', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen',
    'pcntl_exec', 'unserialize', 'create_function', 'assert',
];

// Neuron AI copies its own workflow state with serialize() / unserialize()
// on values it built itself, never on a request. The process and eval calls
// stay forbidden in the dependencies; this one is checked in the module only.
const VENDOR_ALLOWED_FUNCTIONS = ['unserialize'];

// The request is read through Tools::getValue() and the cookie through
// Context. The few places that cannot are listed with the reason, and only
// for the superglobal they need. $_SERVER and $_FILES have no PrestaShop
// wrapper that gives the raw value, so they are not checked.
const REQUEST_GLOBALS = ['_GET', '_POST', '_REQUEST', '_COOKIE'];
const REQUEST_GLOBALS_ALLOWED = [
    // Sets the id a core controller reads, then puts back what was there.
    'classes/SomcpCatalog.php' => ['_GET', '_POST'],
    // An empty $_POST on a POST is how PHP says the body was over post_max_size.
    'classes/SomcpUpload.php' => ['_POST'],
    // The token endpoint walks the whole form body to refuse repeated fields.
    'controllers/front/oauth.php' => ['_POST'],
    // The pending authorization request, a cookie the module itself sets with
    // its own name and flags, outside the PrestaShop cookie.
    'controllers/front/authorize.php' => ['_COOKIE'],
];

$findings = [];
$report = function (string $rule, string $path, string $detail = '', int $line = 0) use (&$findings): void {
    $findings[] = [$rule, $path, $detail, $line];
};

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$phpFiles = 0;
foreach ($iterator as $item) {
    $path = substr($item->getPathname(), strlen($root) + 1);
    $name = $item->getFilename();

    if ($name[0] === '.' && !($path === $name && in_array($name, HIDDEN_ALLOWED, true))) {
        $report('hidden file', $path);
        continue;
    }

    if ($item->isDir()) {
        if (!is_file($item->getPathname() . '/index.php')) {
            $report('no index.php', $path . '/');
        }
        continue;
    }

    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($extension, FORBIDDEN_EXTENSIONS, true)) {
        $report('file type', $path, '.' . $extension);
    }

    if ($extension === 'php') {
        ++$phpFiles;
        checkPhp($item->getPathname(), $path, $report);
    }
}

if (!is_file($root . '/index.php')) {
    $report('no index.php', './');
}
checkLogo($root, $report);
checkVersion($root, $report);

foreach ($findings as [$rule, $path, $detail, $line]) {
    $where = $line > 0 ? "$path:$line" : $path;
    echo "$rule: $where" . ($detail !== '' ? " ($detail)" : '') . "\n";
    if (getenv('GITHUB_ACTIONS')) {
        // Paths inside the built folder match the repository for module files,
        // which is where an annotation is useful.
        echo "::error file=$path" . ($line > 0 ? ",line=$line" : '') . "::$rule" . ($detail !== '' ? ": $detail" : '') . "\n";
    }
}

$count = count($findings);
echo $count === 0
    ? "Addons rules: $phpFiles PHP files, no finding\n"
    : "Addons rules: $count finding(s) in $phpFiles PHP files\n";
exit($count === 0 ? 0 : 1);

/**
 * Tokens rather than a text search: a name in a comment, a string or a
 * method of another class (Hook::exec, $db->exec) is not a call.
 */
function checkPhp(string $file, string $path, callable $report): void
{
    $tokens = token_get_all((string) file_get_contents($file));
    $inVendor = strncmp($path, 'vendor/', 7) === 0;
    $allowed = REQUEST_GLOBALS_ALLOWED[$path] ?? [];
    $count = count($tokens);

    for ($i = 0; $i < $count; ++$i) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }

        if ($token[0] === T_VARIABLE && !$inVendor) {
            $global = substr($token[1], 1);
            if (in_array($global, REQUEST_GLOBALS, true) && !in_array($global, $allowed, true)) {
                $report('request superglobal', $path, $token[1] . ', read it with Tools::getValue()', $token[2]);
            }
            continue;
        }

        $name = $token[0] === T_STRING ? strtolower($token[1])
            : ($token[0] === T_NAME_FULLY_QUALIFIED ? strtolower(ltrim($token[1], '\\')) : null);
        if ($name === null || !in_array($name, FORBIDDEN_FUNCTIONS, true)
            || ($inVendor && in_array($name, VENDOR_ALLOWED_FUNCTIONS, true))) {
            continue;
        }
        if (nextCode($tokens, $i, 1) !== '(') {
            continue;
        }
        $before = nextCode($tokens, $i, -1);
        if (in_array($before, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true)) {
            continue;
        }
        $report('forbidden function', $path, $name . '()', $token[2]);
    }
}

/**
 * The next or previous token that is not whitespace or a comment: its id
 * for a named token, the character itself otherwise.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 */
function nextCode(array $tokens, int $i, int $step): int|string|null
{
    for ($j = $i + $step; isset($tokens[$j]); $j += $step) {
        $token = $tokens[$j];
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token[0];
        }

        return $token;
    }

    return null;
}

function checkLogo(string $root, callable $report): void
{
    $size = is_file($root . '/logo.png') ? @getimagesize($root . '/logo.png') : false;
    if ($size === false) {
        $report('logo', 'logo.png', 'missing or not an image');
    } elseif ($size[0] !== $size[1] || $size[0] < 32) {
        $report('logo', 'logo.png', "{$size[0]}x{$size[1]}, a square of at least 32 pixels");
    }
}

/**
 * config.xml is what the module list shows before the module class loads:
 * a name or version that differs from somcp.php shows the merchant one
 * module and installs another.
 */
function checkVersion(string $root, callable $report): void
{
    $xml = @simplexml_load_file($root . '/config.xml');
    if ($xml === false) {
        $report('config.xml', 'config.xml', 'missing or not well-formed');

        return;
    }

    $source = (string) file_get_contents($root . '/somcp.php');
    preg_match('/\$this->name\s*=\s*\'([^\']+)\'/', $source, $name);
    preg_match('/\$this->version\s*=\s*\'([^\']+)\'/', $source, $version);

    // PrestaShop takes the technical name from the folder the zip unpacks to.
    $folder = basename((string) realpath($root));
    if (($name[1] ?? '') !== (string) $xml->name || (string) $xml->name !== $folder) {
        $report('config.xml', 'config.xml', 'name ' . $xml->name . ', somcp.php ' . ($name[1] ?? 'none') . ', folder ' . $folder);
    }
    if (($version[1] ?? '') !== (string) $xml->version) {
        $report('config.xml', 'config.xml', 'version ' . $xml->version . ', somcp.php ' . ($version[1] ?? 'none'));
    }
}
