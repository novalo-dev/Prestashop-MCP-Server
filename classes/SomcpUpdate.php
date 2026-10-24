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
 * SomcpUpdate - tells the merchant when a newer version is published.
 *
 * It only reads. The module never downloads or installs anything by itself:
 * code that replaces itself from the internet is what a stolen release would
 * need to reach every shop at once, and PrestaShop Addons refuses it. The
 * merchant downloads the archive and uploads it in the Module Manager, which
 * replaces the module in place.
 *
 * GitHub is asked at most once a day, and only while an administrator has the
 * settings page open, so a shop that nobody administers never calls out. A
 * failure is remembered like a success: an unreachable GitHub must not slow
 * every page load for the rest of the day.
 *
 * Both values are stored for all shops at once. There is one module on disk
 * whatever the number of shops, so there is one answer to "is it up to date".
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpUpdate
{
    /** @var string Repository whose releases are read. */
    public const REPOSITORY = 'https://github.com/novalo-dev/Prestashop-MCP-Server';

    /** @var string '0' when the merchant turned the check off. Missing means on. */
    public const CHECK = 'SOMCP_UPDATE_CHECK';

    /** @var string Last answer, as JSON. */
    public const CACHE = 'SOMCP_UPDATE_CACHE';

    private const LATEST_URL = 'https://api.github.com/repos/novalo-dev/Prestashop-MCP-Server/releases/latest';

    /** @var int Seconds between two automatic checks. */
    private const INTERVAL = 86400;

    /**
     * @var int Largest answer read, in bytes.
     *
     * The release notes are the whole CHANGELOG section of the version, which
     * grows with every tool added; the default cap of SomcpHttp would cut a
     * large release short and report it as a failure.
     */
    private const MAX_BYTES = 1048576;

    /** @var int Changelog titles shown before "and N more". */
    private const MAX_TITLES = 5;

    /**
     * @return bool
     */
    public static function isOn(): bool
    {
        return (string) Configuration::getGlobalValue(self::CHECK) !== '0';
    }

    /**
     * @param bool $on
     */
    public static function setOn(bool $on): void
    {
        Configuration::updateGlobalValue(self::CHECK, $on ? '1' : '0');
        if (!$on) {
            // A version seen months ago is not news; turning the check back on
            // starts from a fresh answer.
            Configuration::deleteByName(self::CACHE);
        }
    }

    /**
     * What the settings page shows, asking GitHub first when the last answer
     * is a day old or when $force is set.
     *
     * @param string $installed version of the module on disk
     * @param bool $force
     *
     * @return array{enabled:bool, installed:string, checked:int, failed:bool, latest:string, available:bool, titles:array<int, string>, more:int, download:string, notes:string}
     */
    public static function state(string $installed, bool $force = false): array
    {
        $state = [
            'enabled' => self::isOn(),
            'installed' => $installed,
            'checked' => 0,
            'failed' => false,
            'latest' => '',
            'available' => false,
            'titles' => [],
            'more' => 0,
            'download' => self::REPOSITORY . '/releases/latest',
            'notes' => self::REPOSITORY . '/releases',
        ];
        if (!$state['enabled']) {
            return $state;
        }

        $cache = self::cached();
        if ($force || $cache === null || time() - (int) $cache['checked'] >= self::INTERVAL) {
            $cache = self::ask();
            Configuration::updateGlobalValue(self::CACHE, (string) json_encode($cache, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES));
        }

        $state['checked'] = (int) $cache['checked'];
        $state['failed'] = !$cache['ok'];
        if ($cache['ok']) {
            $state['latest'] = (string) $cache['latest'];
            $state['available'] = version_compare($state['latest'], $installed, '>');
            $state['titles'] = $cache['titles'];
            $state['more'] = (int) $cache['more'];
            if ($cache['download'] !== '') {
                $state['download'] = (string) $cache['download'];
            }
            if ($cache['notes'] !== '') {
                $state['notes'] = (string) $cache['notes'];
            }
        }

        return $state;
    }

    /**
     * Removes what the module stored, on uninstall.
     */
    public static function purge(): void
    {
        Configuration::deleteByName(self::CHECK);
        Configuration::deleteByName(self::CACHE);
    }

    /**
     * @return array{checked:int, ok:bool, latest:string, titles:array<int, string>, more:int, download:string, notes:string}|null
     */
    private static function cached(): ?array
    {
        $decoded = json_decode((string) Configuration::getGlobalValue(self::CACHE), true);
        if (!is_array($decoded) || !isset($decoded['checked'], $decoded['ok'])) {
            return null;
        }

        return [
            'checked' => (int) $decoded['checked'],
            'ok' => (bool) $decoded['ok'],
            'latest' => (string) ($decoded['latest'] ?? ''),
            'titles' => array_values(array_map('strval', (array) ($decoded['titles'] ?? []))),
            'more' => (int) ($decoded['more'] ?? 0),
            'download' => (string) ($decoded['download'] ?? ''),
            'notes' => (string) ($decoded['notes'] ?? ''),
        ];
    }

    /**
     * @return array{checked:int, ok:bool, latest:string, titles:array<int, string>, more:int, download:string, notes:string}
     */
    private static function ask(): array
    {
        $answer = [
            'checked' => time(),
            'ok' => false,
            'latest' => '',
            'titles' => [],
            'more' => 0,
            'download' => '',
            'notes' => '',
        ];

        $response = SomcpHttp::fetch(self::LATEST_URL, true, ['Accept: application/vnd.github+json'], null, self::MAX_BYTES);
        if ($response['status'] !== 200) {
            return $answer;
        }
        $release = json_decode($response['body'], true);
        if (!is_array($release)) {
            return $answer;
        }

        // Anything that is not a plain version number is ignored rather than
        // compared: version_compare() would rank "v2-beta" against "1.0.0" in
        // ways nobody would guess.
        $latest = ltrim((string) ($release['tag_name'] ?? ''), 'vV');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $latest)) {
            return $answer;
        }
        $answer['ok'] = true;
        $answer['latest'] = $latest;

        // Links are kept only when they point into this repository: they end
        // up behind buttons on the back office, and the answer came from the
        // network.
        $notes = (string) ($release['html_url'] ?? '');
        if (strpos($notes, self::REPOSITORY . '/releases/') === 0) {
            $answer['notes'] = $notes;
        }
        foreach ((array) ($release['assets'] ?? []) as $asset) {
            $url = is_array($asset) ? (string) ($asset['browser_download_url'] ?? '') : '';
            if (strpos($url, self::REPOSITORY . '/releases/download/') === 0 && substr($url, -4) === '.zip') {
                $answer['download'] = $url;
                break;
            }
        }

        $titles = self::titles((string) ($release['body'] ?? ''));
        $answer['titles'] = array_slice($titles, 0, self::MAX_TITLES);
        $answer['more'] = max(0, count($titles) - self::MAX_TITLES);

        return $answer;
    }

    /**
     * The headings of the release notes.
     *
     * The notes are the CHANGELOG section of the version, where every entry
     * opens with its subject in bold - "**Settings page.** The page was...".
     * Those subjects alone make a summary a merchant can read at a glance;
     * the whole text is one click away. They are kept as plain text and
     * escaped when shown, never rendered: the notes are Markdown from the
     * network, not HTML the module wrote.
     *
     * @param string $body
     *
     * @return array<int, string>
     */
    private static function titles(string $body): array
    {
        if (!preg_match_all('/^\*\*([^*\r\n]{1,120})\*\*/m', $body, $matches)) {
            return [];
        }

        $titles = [];
        foreach ($matches[1] as $title) {
            $title = rtrim(trim($title), '.:');
            if ($title !== '' && !in_array($title, $titles, true)) {
                $titles[] = $title;
            }
        }

        return $titles;
    }
}
