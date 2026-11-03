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
 * somcp_get_cache_status - every card of Advanced Parameters > Performance
 * for the token's shop: Smarty, Debug mode, Optional features, CCC, Media
 * servers and Caching, with warnings on what slows the storefront.
 *
 * The PS_ keys are read for the token's shop rather than through the back
 * office form handlers, which take the shop from the global context. Debug
 * mode is read from the defines file, as the screen does, never from the
 * constant of the running request: with a debug cookie that constant is only
 * true for the browser sending it.
 *
 * Nothing is written: somcp_clear_cache clears the cache, and changing these
 * settings stays in the back office.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolGetSeoSettings.php';

class SomcpToolGetCacheStatus
{
    /**
     * Template compilation, by the value PS_SMARTY_FORCE_COMPILE stores.
     */
    private const COMPILATION = [0 => 'never', 1 => 'if_updated', 2 => 'force'];

    /**
     * The caching systems of the screen, with the PHP extensions its
     * CachingType tests to offer each one.
     */
    private const SYSTEMS = [
        'CacheMemcache' => ['memcache'],
        'CacheMemcached' => ['memcached'],
        'CacheApc' => ['apc', 'apcu'],
        'CacheXcache' => ['xcache'],
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_get_cache_status',
            Somcp::t('Read the performance settings'),
            'Reads Advanced Parameters > Performance: Smarty compilation and cache, debug mode and overrides, '
                . 'optional features, CCC for CSS and JavaScript, media servers and the caching system with its '
                . 'servers, plus warnings on what slows the storefront or should not stay on in production. A '
                . 'setting the running version lacks is null. Read only: clear the cache with somcp_clear_cache; '
                . 'changing these settings stays in the back office.',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::SYSTEM_READ],
            [self::class, 'handle'],
            // These settings live under Advanced Parameters > Performance, and
            // reading them takes the view right there.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminPerformance')
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
        // A misspelt argument would otherwise be ignored, and the assistant
        // would take the answer for the one it asked for.
        if ($arguments !== []) {
            $lines = [];
            foreach (array_keys($arguments) as $key) {
                $lines[] = '- ' . $key . ': not a field of this tool';
            }

            return SomcpTool::text("Nothing was read. Correct these and call again:\n" . implode("\n", $lines), true);
        }

        $idShop = Somcp::currentShopId();
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $flag = static function (string $key) use ($idShopGroup, $idShop): ?bool {
            return SomcpToolGetSeoSettings::flag($key, $idShopGroup, $idShop);
        };

        $compile = Configuration::get('PS_SMARTY_FORCE_COMPILE', null, $idShopGroup, $idShop);
        $clear = Configuration::get('PS_SMARTY_CLEAR_CACHE', null, $idShopGroup, $idShop);
        $smarty = [
            'template_compilation' => $compile === false ? null : (self::COMPILATION[(int) $compile] ?? null),
            'cache' => $flag('PS_SMARTY_CACHE'),
            'multi_front_optimization' => $flag('PS_SMARTY_LOCAL'),
            'clear_cache' => $clear === false ? null : (string) $clear,
        ];

        $debug = array_merge(['disable_overrides' => $flag('PS_DISABLE_OVERRIDES')], self::debug());

        $optional = [
            // The keys Combination, Feature and Group::isFeatureActive() read,
            // taken for the token's shop rather than the context's.
            'combinations' => $flag('PS_COMBINATION_FEATURE_ACTIVE'),
            'features' => $flag('PS_FEATURE_FEATURE_ACTIVE'),
            'customer_groups' => $flag('PS_GROUP_FEATURE_ACTIVE'),
        ];

        $ccc = [
            'smart_cache_css' => $flag('PS_CSS_THEME_CACHE'),
            'smart_cache_js' => $flag('PS_JS_THEME_CACHE'),
            'apache_optimization' => $flag('PS_HTACCESS_CACHE_CONTROL'),
        ];

        $mediaServers = [];
        foreach (['PS_MEDIA_SERVER_1', 'PS_MEDIA_SERVER_2', 'PS_MEDIA_SERVER_3'] as $key) {
            $domain = trim((string) Configuration::get($key, null, $idShopGroup, $idShop));
            if ($domain !== '') {
                $mediaServers[] = $domain;
            }
        }

        $caching = self::caching();

        return SomcpTool::json([
            'smarty' => $smarty,
            'debug' => $debug,
            'optional_features' => $optional,
            'ccc' => $ccc,
            'media_servers' => $mediaServers,
            'caching' => $caching,
            'warnings' => self::warnings($smarty, $debug, $ccc, $caching),
        ]);
    }

    /**
     * Debug mode as the defines file sets it, and on PrestaShop 9 whether it
     * is limited to a cookie and whether the profiler is on.
     *
     * @return array{debug_mode: bool, debug_cookie: bool|null, debug_profiling: bool|null}
     */
    private static function debug(): array
    {
        $debugMode = new PrestaShop\PrestaShop\Adapter\Debug\DebugMode();
        $enabled = (bool) $debugMode->isDebugModeEnabled();

        $isNine = version_compare(_PS_VERSION_, '9.0.0', '>=');
        $cookie = null;
        $profiling = null;
        if ($isNine) {
            // PrestaShop 9 writes the cookie test into the _PS_MODE_DEV_ line
            // itself. Only whether there is one is returned: its name and
            // value work as a password to the debug output.
            $current = (string) self::callIfPresent($debugMode, 'getCurrentDebugMode');
            $cookie = $enabled && stripos($current, '$_COOKIE') !== false;

            $profilingClass = 'PrestaShop\PrestaShop\Adapter\Debug\DebugProfiling';
            if (class_exists($profilingClass)) {
                $profiler = new $profilingClass();
                $profiling = self::callIfPresent($profiler, 'isProfilingEnabled');
                $profiling = $profiling === null ? null : (bool) $profiling;
            }
        }

        return ['debug_mode' => $enabled, 'debug_cookie' => $cookie, 'debug_profiling' => $profiling];
    }

    /**
     * The Caching card: stored in app/config/parameters.php, which the core
     * turns into constants at boot, and the Memcache servers table.
     *
     * @return array{use_cache: bool, caching_system: string|null, available_systems: array<int, string>, servers: array<int, array<string, mixed>>}
     */
    private static function caching(): array
    {
        $available = [];
        foreach (self::SYSTEMS as $system => $extensions) {
            foreach ($extensions as $extension) {
                if (extension_loaded($extension)) {
                    $available[] = $system;
                    break;
                }
            }
        }

        $servers = [];
        $query = new DbQuery();
        $query->select('`id_memcached_server`, `ip`, `port`, `weight`');
        $query->from('memcached_servers');
        $query->orderBy('`id_memcached_server` ASC');
        foreach (Db::getInstance()->executeS($query) ?: [] as $row) {
            $servers[] = [
                'id' => (int) $row['id_memcached_server'],
                'ip' => (string) $row['ip'],
                'port' => (int) $row['port'],
                'weight' => (int) $row['weight'],
            ];
        }

        $system = (string) SomcpBackOffice::constant('_PS_CACHING_SYSTEM_');

        return [
            'use_cache' => (bool) SomcpBackOffice::constant('_PS_CACHE_ENABLED_'),
            // As stored, even with the cache off: the screen keeps it selected.
            'caching_system' => $system === '' ? null : $system,
            'available_systems' => $available,
            'servers' => $servers,
        ];
    }

    /**
     * @param array<string, mixed> $smarty
     * @param array<string, mixed> $debug
     * @param array<string, mixed> $ccc
     * @param array<string, mixed> $caching
     *
     * @return array<int, array{code: string, why: string}>
     */
    private static function warnings(array $smarty, array $debug, array $ccc, array $caching): array
    {
        $warnings = [];
        if ($debug['debug_mode'] === true) {
            $warnings[] = [
                'code' => 'debug_mode_on',
                'why' => $debug['debug_cookie'] === true
                    ? 'Debug mode is on for the browsers that send the debug cookie: it slows the pages they load.'
                    : 'Debug mode is on: errors are shown to visitors and every page is slower. Turn it off in production.',
            ];
        }
        if ($debug['debug_profiling'] === true) {
            $warnings[] = [
                'code' => 'debug_profiling_on',
                'why' => 'The debug profiler is on: it measures every page and slows the whole shop.',
            ];
        }
        if ($smarty['template_compilation'] === 'force') {
            $warnings[] = [
                'code' => 'force_compilation',
                'why' => 'Templates are compiled again on every page: useful while editing the theme, slow in production.',
            ];
        }
        if ($smarty['cache'] === false) {
            $warnings[] = [
                'code' => 'smarty_cache_off',
                'why' => 'The Smarty cache is off, so every page is rendered from scratch.',
            ];
        }
        if ($debug['disable_overrides'] === true) {
            $warnings[] = [
                'code' => 'overrides_disabled',
                'why' => 'All overrides are disabled: modules relying on one do not work as installed.',
            ];
        }
        if ($ccc['smart_cache_css'] === false) {
            $warnings[] = [
                'code' => 'ccc_css_off',
                'why' => 'The CSS files are not combined: the storefront loads each one separately.',
            ];
        }
        if ($ccc['smart_cache_js'] === false) {
            $warnings[] = [
                'code' => 'ccc_js_off',
                'why' => 'The JavaScript files are not combined: the storefront loads each one separately.',
            ];
        }
        // Only when the cache is in use: with it off the stored system is
        // never loaded, whatever the server offers.
        if ($caching['use_cache'] === true && $caching['caching_system'] !== null
            && !in_array($caching['caching_system'], $caching['available_systems'], true)) {
            $warnings[] = [
                'code' => 'cache_system_unavailable',
                'why' => 'The caching system chosen needs a PHP extension this server does not load.',
            ];
        }

        return $warnings;
    }

    /**
     * Calls a method that only some PrestaShop releases have.
     *
     * Taken as any object, with the method name held in a variable:
     * each analysis sees one release, where the answer is fixed, and would
     * call the check useless there - it is what keeps the other releases
     * working.
     *
     * @return mixed null when this release does not have the method
     */
    private static function callIfPresent(object $object, string $method)
    {
        return method_exists($object, $method) ? $object->{$method}() : null;
    }
}
