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
 * CI check: what the PrestaShop Addons validator reports, found before an
 * archive is ever uploaded to it.
 *
 * - Context::getContext() is reported as a core class used the wrong way
 *   (PrestaShop 9 moves the context to Symfony). The module reads it in one
 *   place, Somcp::legacyContext(), which goes through the module instance:
 *   any other call is a copy of that rule that drifted.
 * - HTML written in PHP is reported too: markup belongs in a Smarty template.
 *   Every string literal of the module's PHP is read with the tokenizer, so a
 *   comment or a regular expression on a tag name does not count, only a
 *   string that holds a tag.
 * - The module root must carry a .htaccess that stops the folder from being
 *   listed, and every folder an archive would carry an index.php: the
 *   validator refuses the module without them.
 *
 * Usage: php .github/scripts/check-addons.php [module root]
 * Exit code 0 when everything is in order, 1 otherwise.
 */

$root = rtrim($argv[1] ?? getcwd(), '/');
$errors = [];

// Folders that never reach a merchant: the archive leaves them out
// (.gitattributes export-ignore), or they are not the module's own code.
$skip = '#^(vendor|dev|docs|\.github|\.git|node_modules)(/|$)#';

// Tag names a page is built from. A string holding one of them, opening or
// closing, is markup.
$tag = '#<\s*/?\s*(a|b|body|br|button|code|div|em|form|h[1-6]|html|i|iframe|img|input|label|li|link|meta|nav|ol|option|p|pre|script|section|select|small|span|strong|style|svg|table|td|textarea|th|tr|ul)(\s|>|/)#i';

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    $path = substr($file->getPathname(), strlen($root) + 1);
    if (preg_match($skip, $path) || substr($path, -4) !== '.php') {
        continue;
    }

    $tokens = token_get_all((string) file_get_contents($file->getPathname()));
    $count = count($tokens);

    for ($i = 0; $i < $count; ++$i) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }

        // Context::getContext(), with or without a leading backslash.
        if (in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
            && ltrim($token[1], '\\') === 'Context'
        ) {
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                ++$j;
            }
            if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON) {
                ++$j;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    ++$j;
                }
                if ($j < $count && is_array($tokens[$j]) && $tokens[$j][1] === 'getContext') {
                    $errors[] = $path . ':' . $token[2] . ': Context::getContext() - read the context through Somcp::legacyContext() (or Somcp::currentShopId() for the shop)';
                }
            }
        }

        // Markup in a string literal or outside the PHP tags.
        if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)
            && preg_match($tag, $token[1])
        ) {
            if ($token[0] === T_INLINE_HTML && trim($token[1]) === '') {
                continue;
            }
            $errors[] = $path . ':' . $token[2] . ': HTML in PHP - move it to a template under views/templates: '
                . substr(trim((string) preg_replace('/\s+/', ' ', $token[1])), 0, 80);
        }
    }
}

// The root .htaccess, and the directive that makes it useful.
$htaccess = $root . '/.htaccess';
if (!is_file($htaccess)) {
    $errors[] = '.htaccess: missing at the module root - the validator wants the folder protected from listing';
} elseif (stripos((string) file_get_contents($htaccess), 'Options -Indexes') === false) {
    $errors[] = '.htaccess: present but without "Options -Indexes", so the folder can still be listed';
}

// An index.php in every folder the archive carries. vendor/ is filled by the
// release build, which adds its own index.php files there.
$dirs = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($dirs as $dir) {
    if (!$dir->isDir()) {
        continue;
    }
    $path = substr($dir->getPathname(), strlen($root) + 1);
    if (preg_match($skip, $path)) {
        continue;
    }
    if (!is_file($dir->getPathname() . '/index.php')) {
        $errors[] = $path . '/: no index.php - the validator refuses a folder without one';
    }
}
if (!is_file($root . '/index.php')) {
    $errors[] = 'index.php: missing at the module root';
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    echo 'Addons validator: ' . count($errors) . " problem(s).\n";
    exit(1);
}

echo "Addons validator: no Context::getContext(), no HTML in PHP, .htaccess and index.php in place.\n";
exit(0);
