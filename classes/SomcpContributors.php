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
 * SomcpContributors - the people who built the module, for the settings page.
 *
 * The list is GitHub's own count of commits on the published code, so it
 * follows the repository without a new release: someone whose first pull
 * request is released shows up, and a new profile picture shows at once,
 * because the picture is GitHub's address for that account, not a copy.
 *
 * It goes through the same door as the update check: asked at most once a
 * day, only while an administrator has the page open, and not at all when
 * the merchant switched the check off - pictures included, since the browser
 * would fetch those from GitHub. A shop that cannot reach GitHub, or chose
 * not to, falls back on contributors.json, written into the module at each
 * release.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpContributors
{
    /** @var string Last answer, as JSON. */
    public const CACHE = 'SOMCP_CONTRIBUTORS_CACHE';

    private const URL = 'https://api.github.com/repos/novalo-dev/Prestashop-MCP-Server/contributors?per_page=100';

    /** @var string Shipped with the module, for a shop that cannot reach GitHub. */
    private const FALLBACK = 'contributors.json';

    /** @var int Seconds between two automatic reads, the same as the update check. */
    private const INTERVAL = 86400;

    /**
     * Who to thank, the most active first.
     *
     * @param bool $force ask GitHub now, as the Check now button does for updates
     *
     * @return array<int, array{login:string, profile:string, avatar:string, contributions:int, percent:float}>
     */
    public static function all(bool $force = false): array
    {
        $people = [];
        if (SomcpUpdate::isOn()) {
            $cache = self::cached();
            if ($force || $cache === null || time() - (int) $cache['checked'] >= self::INTERVAL) {
                $cache = ['checked' => time(), 'people' => self::ask()];
                Configuration::updateGlobalValue(self::CACHE, (string) json_encode($cache, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES));
            }
            $people = $cache['people'];
        }

        // An empty answer is a failed one: the repository has at least its
        // author, so nobody to thank means GitHub was not reached.
        if ($people === []) {
            $people = self::fallback();
        }

        return self::shares($people, SomcpUpdate::isOn());
    }

    /**
     * Removes what the module stored, on uninstall.
     */
    public static function purge(): void
    {
        Configuration::deleteByName(self::CACHE);
    }

    /**
     * @return array{checked:int, people:array<int, array{login:string, id:int, contributions:int}>}|null
     */
    private static function cached(): ?array
    {
        $decoded = json_decode((string) Configuration::getGlobalValue(self::CACHE), true);
        if (!is_array($decoded) || !isset($decoded['checked'])) {
            return null;
        }

        return ['checked' => (int) $decoded['checked'], 'people' => self::clean((array) ($decoded['people'] ?? []))];
    }

    /**
     * @return array<int, array{login:string, id:int, contributions:int}>
     */
    private static function ask(): array
    {
        $response = SomcpHttp::fetch(self::URL, true, ['Accept: application/vnd.github+json']);
        if ($response['status'] !== 200) {
            return [];
        }
        $decoded = json_decode($response['body'], true);

        return is_array($decoded) ? self::clean($decoded) : [];
    }

    /**
     * @return array<int, array{login:string, id:int, contributions:int}>
     */
    private static function fallback(): array
    {
        $file = _PS_MODULE_DIR_ . 'somcp/' . self::FALLBACK;
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($decoded) ? self::clean($decoded) : [];
    }

    /**
     * Only what the page needs, checked one field at a time.
     *
     * The answer comes from the network and ends up in links and images on
     * the back office, so nothing is taken as a link: the profile and picture
     * addresses are built from the login and the numeric identifier, which
     * cannot carry anything else. Bots, which GitHub lists among the
     * contributors when a workflow commits, are left out.
     *
     * @param array<mixed> $rows
     *
     * @return array<int, array{login:string, id:int, contributions:int}>
     */
    private static function clean(array $rows): array
    {
        $people = [];
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['type'] ?? 'User') !== 'User') {
                continue;
            }
            $login = (string) ($row['login'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            $contributions = (int) ($row['contributions'] ?? 0);
            // GitHub's own rule for a login: letters, digits and single
            // hyphens, 39 characters at most.
            if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/', $login) || $id <= 0 || $contributions <= 0) {
                continue;
            }
            $people[] = ['login' => $login, 'id' => $id, 'contributions' => $contributions];
        }

        return $people;
    }

    /**
     * Each person's share of the commits, with the addresses built.
     *
     * @param array<int, array{login:string, id:int, contributions:int}> $people
     * @param bool $pictures false when the merchant asked the module not to
     *                       call GitHub: the browser would still fetch the
     *                       pictures from it
     *
     * @return array<int, array{login:string, profile:string, avatar:string, contributions:int, percent:float}>
     */
    private static function shares(array $people, bool $pictures): array
    {
        usort($people, static function (array $a, array $b): int {
            return $b['contributions'] <=> $a['contributions'];
        });
        $total = array_sum(array_column($people, 'contributions'));

        $shares = [];
        foreach ($people as $person) {
            $percent = $total > 0 ? 100 * $person['contributions'] / $total : 0.0;
            $shares[] = [
                'login' => $person['login'],
                'profile' => 'https://github.com/' . $person['login'],
                // The address GitHub keeps current for the account: a picture
                // changed on GitHub changes here without anything stored.
                'avatar' => $pictures ? 'https://avatars.githubusercontent.com/u/' . $person['id'] . '?s=96' : '',
                'contributions' => $person['contributions'],
                // One decimal below 10 so a first contribution never reads 0.
                'percent' => round($percent, $percent < 10 ? 1 : 0),
            ];
        }

        return $shares;
    }
}
