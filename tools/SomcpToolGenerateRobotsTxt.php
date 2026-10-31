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
 * somcp_generate_robots_txt - writes the shop's robots.txt again, as the
 * "Generate robots.txt file" button of Shop Parameters > Traffic & SEO does.
 *
 * The button calls Tools::generateRobotsFile(true) through the core's
 * RobotsTextFileGenerator, on PrestaShop 8 and 9 alike. The same call is made
 * here, hooks included, so a module adding its own lines through
 * actionAdminMetaBeforeWriteRobotsFile or actionAdminMetaAfterWriteRobotsFile
 * still adds them. The file is not assembled here: a second version of the
 * core's rules would drift from it at the next release.
 *
 * Whatever was typed into the file by hand is lost, which is why the previous
 * content is returned in full: it is the only copy left.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolGenerateRobotsTxt
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        // The back office button asks for the add, edit and delete rights on
        // Traffic & SEO together (MetaController::generateRobotsFileAction),
        // since the file is replaced whole. The tool asks for the same three
        // rather than less than the screen it stands for.
        $acts = SomcpTool::acts(SomcpPermission::EDIT, 'AdminMeta');
        $acts['permissions'][] = SomcpPermission::on('AdminMeta', SomcpPermission::ADD);
        $acts['permissions'][] = SomcpPermission::on('AdminMeta', SomcpPermission::DELETE);

        return new SomcpTool(
            'somcp_generate_robots_txt',
            Somcp::t('Generate the robots.txt file'),
            'Writes the shop\'s robots.txt again from PrestaShop\'s own rules, exactly as the Generate '
                . 'robots.txt file button of Shop Parameters > Traffic & SEO does, modules adding their lines '
                . 'included. Any line written into the file by hand is lost: read the file first with '
                . 'somcp_get_robots_txt, show the merchant what will go, and only call this after they agree. '
                . 'On a multistore installation the file is shared by every shop, and its Sitemap line is the '
                . 'one of the shop this call is made for. Returns changed (false when the new file is the same '
                . 'as the old one), previous_content (null when there was no file) and content. Takes no '
                . 'parameter. Do not use it to read the file (somcp_get_robots_txt). Not to be retried: if the '
                . 'answer is lost, read the file with somcp_get_robots_txt to see whether it was written.',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_WRITE],
            [self::class, 'handle'],
            $acts
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
        // The same path Tools::generateRobotsFile() writes to.
        $file = _PS_ROOT_DIR_ . '/robots.txt';

        $previous = self::read($file);

        if (!Tools::generateRobotsFile(true)) {
            return SomcpTool::text(
                'The robots.txt file could not be written at the root of the shop: the web server may not '
                    . 'write there. Nothing was changed. The merchant or their host has to allow it, then '
                    . 'generate the file again.',
                true
            );
        }

        // The core keeps the file open while the hooks run and writes
        // through PHP's buffer: what is on disk now is only known once it
        // has closed it, so the stat cache is cleared before reading back.
        clearstatcache(true, $file);
        $content = self::read($file);

        return SomcpTool::json([
            'changed' => $content !== $previous,
            'previous_content' => $previous,
            'content' => $content,
        ]);
    }

    /**
     * The file as it is on disk, null when there is none.
     *
     * @param string $file
     *
     * @return string|null
     */
    private static function read(string $file): ?string
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $content = file_get_contents($file);

        return $content === false ? null : $content;
    }
}
