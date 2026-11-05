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
 * somcp_get_llms_txt - the llms.txt at the root of the installation, the
 * plain text summary some AI tools read, and whether somcp wrote it.
 *
 * The file sits where robots.txt sits and is read by the same code
 * (SomcpToolGetRobotsTxt::read()): same size cap, same byte order mark
 * handling, so the two files can never be read two different ways.
 *
 * Nothing is fetched over the network: what the web server serves at
 * /llms.txt depends on its rules (the .htaccess of PrestaShop 8 and 9 serves
 * an existing file as is, nginx depends on its configuration), so the tool
 * reports the file on disk.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGetLlmsTxt
{
    /**
     * The line somcp writes at the end of an llms.txt it generates. The tool
     * that writes the file takes this constant too, so a file written by one
     * is always recognised by the other, and one written by hand never is.
     */
    public const MARKER = '<!-- Written by O\'So MCP Server -->';

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_llms_txt',
            Somcp::t('Read the llms.txt file'),
            'Reads the shop\'s llms.txt, the plain text summary some AI tools look for at the root of a site, if '
                . 'it has one, and says whether somcp wrote it. llms.txt is a proposed convention, not a standard: '
                . 'no crawler or assistant is bound to read it. On a multistore installation the file is shared by '
                . 'every shop. Read only, it never writes the file. For robots.txt use somcp_get_robots_txt; to '
                . 'know whether AI crawlers may read the shop use somcp_check_ai_crawler_access.',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_READ],
            [self::class, 'handle'],
            // No screen handles llms.txt: Shop Parameters > Traffic & SEO is
            // where the files read by crawlers are managed, as robots.txt.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminMeta')
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
        // This tool takes nothing: every unexpected field is reported in one
        // answer (the shape of #169), as somcp_get_seo_settings does.
        $problems = [];
        foreach (array_keys($arguments) as $field) {
            $problems[] = '- ' . $field . ': not a field of this tool';
        }
        if ($problems !== []) {
            return SomcpTool::text(
                "Nothing was read. Correct these and call again:\n" . implode("\n", array_unique($problems)),
                true
            );
        }

        $file = _PS_ROOT_DIR_ . '/llms.txt';
        $shop = Somcp::currentShop();
        $https = (bool) Configuration::get('PS_SSL_ENABLED');
        // Crawlers ask for /llms.txt at the root of the host, whatever folder
        // the shop is installed in.
        $url = ($https ? 'https://' . (string) $shop->domain_ssl : 'http://' . (string) $shop->domain) . '/llms.txt';

        $warnings = [];
        if ((string) $shop->physical_uri !== '/') {
            $warnings[] = [
                'code' => 'not_at_domain_root',
                'why' => 'The shop is installed in the folder ' . (string) $shop->physical_uri . ', so this file is '
                    . 'served there and not at the root of the domain, where AI tools look for it. The llms.txt at '
                    . 'the root of the domain is the one that is read.',
            ];
        }
        $note = 'llms.txt is a proposed convention, not a standard: no crawler or assistant is bound to read it.';
        if (Shop::isFeatureActive()) {
            $note .= ' The file is shared by every shop of this installation.';
        }

        // An unreadable file is reported as absent: the merchant sees the same
        // thing an AI tool would, never an error.
        if (!is_file($file) || !is_readable($file)) {
            $warnings[] = [
                'code' => 'no_llms_txt',
                'why' => 'No llms.txt at the root of the shop: AI tools that look for one find the shop\'s 404 page.',
            ];

            return SomcpTool::json([
                'present' => false,
                'content' => null,
                'size' => null,
                'last_modified' => null,
                'written_by_somcp' => null,
                'url' => $url,
                'warnings' => $warnings,
                'note' => $note,
            ]);
        }

        $size = (int) filesize($file);
        $mtime = filemtime($file);
        $content = SomcpToolGetRobotsTxt::read($file);
        if ($size > SomcpToolGetRobotsTxt::MAX_BYTES) {
            $warnings[] = [
                'code' => 'too_large',
                'why' => 'The file is over 500 KiB: only its first 500 KiB are returned.',
            ];
        }
        // The proposed format opens with a "# " title line, the name of the site.
        $firstLine = '';
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            if (trim($line) !== '') {
                $firstLine = ltrim($line);
                break;
            }
        }
        if (strncmp($firstLine, '# ', 2) !== 0) {
            $warnings[] = [
                'code' => 'not_markdown',
                'why' => 'The file does not start with a "# " title line, which the proposed llms.txt format opens '
                    . 'with: AI tools that follow it may not read the rest.',
            ];
        }

        return SomcpTool::json([
            'present' => true,
            'content' => $content,
            'size' => $size,
            'last_modified' => $mtime === false ? null : date('c', $mtime),
            'written_by_somcp' => strpos($content, self::MARKER) !== false,
            'url' => $url,
            'warnings' => $warnings,
            'note' => $note,
        ]);
    }
}
