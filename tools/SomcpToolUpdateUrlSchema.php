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
 * somcp_update_url_schema - Save on the card Schema of URLs of Shop
 * Parameters > Traffic & SEO > SEO & URLs.
 *
 * The back office checks each pattern with Validate::isRoutePattern() and
 * the required keywords of its route, then stores PS_ROUTE_<route id> for
 * the shop of the context; Dispatcher::loadRoutes() takes an empty value as
 * the default. The route to modules is left out: it carries the address of
 * every module page, this module's own included, and an assistant that
 * changed it could not reach the shop again to put it back.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in:
// the routes, the read answered and the failure sentence are theirs.
require_once __DIR__ . '/SomcpToolGetSeoSettings.php';
require_once __DIR__ . '/SomcpToolUpdateGeneralSettings.php';

class SomcpToolUpdateUrlSchema
{
    /** The route this tool never writes, by the name of the read. */
    private const MODULE_ROUTE = 'module';

    /** @var array<string, string> The pages whose addresses each route makes, for the warnings. */
    private const PAGES = [
        'product' => 'products',
        'category' => 'categories',
        'brand' => 'brands',
        'supplier' => 'suppliers',
        'cms' => 'pages',
        'cms_category' => 'page categories',
    ];

    /**
     * @var array<string, string> what somcp_get_seo_settings answers beside the routes and this tool does not
     *                            write, with where it is changed: taken out when given back as read, so the
     *                            read can be given back whole
     */
    private const KEPT_AS_READ = [
        'friendly_urls' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'accented_urls' => 'not changed by this tool - change it with somcp_update_seo_settings',
        'default_language_url_prefix' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'canonical_redirect' => 'not changed by this tool - change it with somcp_update_seo_settings',
        'disable_apache_multiviews' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'disable_apache_mod_security' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'https' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'https_everywhere' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'domains' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'product_attributes_in_title' => 'not changed by this tool - change it with somcp_update_seo_settings',
        'robots_txt_present' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'sitemap_module' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'languages' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'warnings' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_url_schema',
            Somcp::t('Change the schema of URLs'),
            'Changes the Schema of URLs of Shop Parameters > Traffic & SEO > SEO & URLs, as Save: the pattern of '
                . 'the addresses of the products, categories, suppliers, brands, pages and page categories, the '
                . 'routes of somcp_get_seo_settings given back with a new pattern. The default pattern of the '
                . 'version restores the default. The route to modules stays with the back office. Every address '
                . 'of a route changed moves: ask first, showing the old and new pattern.',
            [
                'type' => 'object',
                'properties' => [
                    'routes' => [
                        'type' => 'object',
                        'description' => 'The routes to change, by the names of somcp_get_seo_settings: product, '
                            . 'category, supplier, brand, cms, cms_category. Each {pattern}, as the read gives it.',
                        'properties' => self::routeSchema(),
                        'additionalProperties' => false,
                    ],
                ],
                'required' => ['routes'],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_WRITE],
            [self::class, 'handle'],
            // The card is saved from SEO & URLs, which takes the edit right
            // on its tab.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminMeta')
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
        $idShop = Somcp::currentShopId();
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        $idEmployee = (int) ($context['id_employee'] ?? 0);
        $read = SomcpToolGetSeoSettings::settings($idShop);

        // Every mistake of the call in one refusal, so it is corrected in one go.
        $problems = SomcpTool::unknownFields($arguments, array_merge(['routes'], array_keys(self::KEPT_AS_READ)));
        foreach (self::KEPT_AS_READ as $field => $line) {
            // Null is left out, as a setting the version lacks reads null.
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            if (!SomcpToolGetSeoSettings::sameAsRead($arguments[$field], $read[$field])) {
                $problems[] = $field . ': ' . $line;
            }
        }

        $defaults = self::defaults();
        $patterns = [];
        if (!array_key_exists('routes', $arguments) || $arguments['routes'] === null) {
            $problems[] = 'routes: required - at least one route, as somcp_get_seo_settings reads them';
        } elseif (!is_array($arguments['routes']) || $arguments['routes'] === []) {
            $problems[] = 'routes: at least one route, as somcp_get_seo_settings reads them';
        } else {
            $routes = $arguments['routes'];
            $problems = array_merge($problems, SomcpTool::unknownFields(
                $routes,
                array_keys(SomcpToolGetSeoSettings::ROUTES),
                [],
                'routes.'
            ));
            foreach (SomcpToolGetSeoSettings::ROUTES as $name => $routeId) {
                if (!array_key_exists($name, $routes)) {
                    continue;
                }
                if ($name === self::MODULE_ROUTE) {
                    if (!SomcpToolGetSeoSettings::sameAsRead($routes[$name], $read['routes'][$name])) {
                        $problems[] = 'routes.module: changed in the back office only - it carries the address of '
                            . 'every module page';
                    }
                    continue;
                }
                $pattern = self::pattern(
                    $name,
                    $routes[$name],
                    $defaults[$routeId] ?? null,
                    (bool) $read['accented_urls'],
                    $problems
                );
                if ($pattern !== null) {
                    $patterns[$name] = $pattern;
                }
            }
        }

        if ($problems !== []) {
            return SomcpTool::refused('changed', $problems);
        }

        // A pattern equal to the one the read gives is no change, so a read
        // given back whole writes nothing.
        $changes = [];
        foreach ($patterns as $name => $pattern) {
            if ($pattern !== $read['routes'][$name]['pattern']) {
                $changes[] = ['route' => $name, 'old' => $read['routes'][$name]['pattern'], 'new' => $pattern];
            }
        }
        if ($changes === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The routes already hold every pattern given. Nothing was written.',
                'routes' => $read['routes'],
            ]);
        }

        $saved = [];
        foreach ($changes as $change) {
            $routeId = SomcpToolGetSeoSettings::ROUTES[$change['route']];
            // The default is stored as an empty value, which
            // Dispatcher::loadRoutes() and the read both take as the default
            // of the running version, so an upgrade that changes the default
            // is followed.
            $stored = $change['new'] === ($defaults[$routeId]['rule'] ?? null) ? '' : $change['new'];
            if (!Configuration::updateValue('PS_ROUTE_' . $routeId, $stored, false, $idShopGroup, $idShop)) {
                return SomcpToolUpdateGeneralSettings::failed(
                    $saved,
                    'the route of ' . $change['route'] . ' could not be saved',
                    $idEmployee,
                    'The routes',
                    'somcp_get_seo_settings',
                    'url_schema_update_failed'
                );
            }
            $saved[] = $change['route'];
        }
        SomcpLogger::log('url_schema_updated', SomcpLogger::OUTCOME_SUCCESS, implode(', ', $saved), '', $idEmployee);

        // Read back rather than echoed: what the assistant shows is what the
        // shop stored.
        $after = SomcpToolGetSeoSettings::routes($idShopGroup, $idShop);

        $warnings = [];
        foreach ($changes as $change) {
            $warnings[] = 'The addresses of all ' . self::PAGES[$change['route']] . ' change: links and search '
                . 'results that point to the old ones lead to a page not found. Regenerate the sitemap so search '
                . 'engines find the new ones.';
        }
        if ($read['friendly_urls'] !== true) {
            $warnings[] = 'Friendly URL is off: the patterns apply only once it is switched on in Traffic & SEO.';
        }
        $warnings = array_merge($warnings, self::sameShapes($after, array_column($changes, 'route')));
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'The routes changed for this shop only, as the back office saves them in a shop context.';
        }

        return SomcpTool::json([
            'changed' => true,
            'changes' => $changes,
            'routes' => $after,
            'warning' => implode(' ', $warnings),
        ]);
    }

    /**
     * The routes of the running version, by route identifier.
     *
     * Read from the class rather than written here, as the read does: the
     * keywords and the defaults differ between versions, and no instance is
     * needed, which keeps the dispatcher of the request untouched.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function defaults(): array
    {
        $vars = get_class_vars('Dispatcher');

        return is_array($vars['default_routes'] ?? null) ? $vars['default_routes'] : [];
    }

    /**
     * The pattern given for one route, checked as the back office checks it,
     * or null when it is wrong (the reasons then in $problems).
     *
     * @param string $name
     * @param mixed $given
     * @param array<string, mixed>|null $default
     * @param bool $accented
     * @param array<int, string> $problems
     *
     * @return string|null
     */
    private static function pattern(string $name, $given, ?array $default, bool $accented, array &$problems): ?string
    {
        $path = 'routes.' . $name;
        if (!is_array($given)) {
            $problems[] = $path . ': an object with its pattern, as somcp_get_seo_settings reads it';

            return null;
        }
        $problems = array_merge($problems, SomcpTool::unknownFields($given, ['pattern', 'customized'], [], $path . '.'));

        if (!array_key_exists('pattern', $given) || $given['pattern'] === null
            || (is_string($given['pattern']) && trim($given['pattern']) === '')) {
            $problems[] = $path . '.pattern: required';

            return null;
        }
        if (!is_string($given['pattern'])) {
            $problems[] = $path . '.pattern: a text, such as the pattern somcp_get_seo_settings reads';

            return null;
        }
        $pattern = trim($given['pattern']);
        $keywords = is_array($default['keywords'] ?? null) ? $default['keywords'] : [];

        $valid = true;
        if (!Validate::isRoutePattern($pattern)) {
            $problems[] = $path . '.pattern: only letters, digits and _ ( ) . { } : / -'
                . ($accented ? ' and accented letters' : '');
            $valid = false;
        }
        // The keywords the route requires, tested as Dispatcher::validateRoute()
        // tests them, on the keywords of the class.
        foreach ($keywords as $keyword => $data) {
            if (isset($data['param']) && !preg_match('#\{([^{}]*:)?' . preg_quote((string) $keyword, '#') . '(:[^{}]*)?\}#', $pattern)) {
                $problems[] = $path . '.pattern: keyword {' . $keyword . '} required';
                $valid = false;
            }
        }
        // The back office takes an unknown keyword, which the storefront then
        // writes as nothing: refused here, with the route's own list.
        foreach (self::unknownKeywords($pattern, array_keys($keywords)) as $unknown) {
            $problems[] = $path . '.pattern: {' . $unknown . '} is not a keyword of this route - one of '
                . implode(', ', array_keys($keywords));
            $valid = false;
        }

        // Worked out by the shop: given back as the pattern makes it, it is
        // taken out.
        if (array_key_exists('customized', $given) && $given['customized'] !== null
            && $given['customized'] !== ($pattern !== ($default['rule'] ?? null))) {
            $problems[] = $path . '.customized: worked out by the shop, so it is not written here';
        }

        return $valid ? $pattern : null;
    }

    /**
     * The keywords written in a pattern that its route does not have.
     *
     * A keyword is written {keyword}, {prepend:keyword} or
     * {prepend:keyword:append}, as Dispatcher::computeRoute() reads them.
     *
     * @param string $pattern
     * @param array<int, int|string> $known
     *
     * @return array<int, string>
     */
    private static function unknownKeywords(string $pattern, array $known): array
    {
        $unknown = [];
        preg_match_all('/\{([^{}]*)\}/', $pattern, $matches);
        foreach ($matches[1] as $content) {
            $found = false;
            foreach ($known as $keyword) {
                if (preg_match('#^([^{}]*:)?' . preg_quote((string) $keyword, '#') . '(:[^{}]*)?$#', $content)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $parts = explode(':', $content);
                $unknown[] = count($parts) === 3 ? $parts[1] : (count($parts) === 1 ? $parts[0] : $content);
            }
        }

        return array_values(array_unique($unknown));
    }

    /**
     * The routes whose addresses now have the same shape as one changed.
     *
     * The storefront tries the routes one after the other and takes the first
     * that matches, so two patterns with the same text around their keywords
     * send some addresses to the wrong kind of page.
     *
     * @param array<string, array{pattern: string|null, customized: bool}> $routes
     * @param array<int, string> $changed
     *
     * @return array<int, string>
     */
    private static function sameShapes(array $routes, array $changed): array
    {
        $shapes = [];
        foreach (array_keys(self::PAGES) as $name) {
            if (isset($routes[$name]['pattern'])) {
                $shapes[$name] = (string) preg_replace('/\{[^{}]*\}/', '', (string) $routes[$name]['pattern']);
            }
        }

        $warnings = [];
        $names = array_keys($shapes);
        foreach ($names as $i => $a) {
            foreach (array_slice($names, $i + 1) as $b) {
                if ($shapes[$a] === $shapes[$b] && (in_array($a, $changed, true) || in_array($b, $changed, true))) {
                    $warnings[] = ucfirst(self::PAGES[$a]) . ' and ' . self::PAGES[$b] . ' now have addresses of the '
                        . 'same shape: the storefront takes the first route that matches, so some pages open the '
                        . 'wrong kind of page.';
                }
            }
        }

        return $warnings;
    }

    /**
     * The schema of each route taken, the shape of the read.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function routeSchema(): array
    {
        $schema = [];
        foreach (array_keys(self::PAGES) as $name) {
            $schema[$name] = [
                'type' => 'object',
                'properties' => [
                    'pattern' => [
                        'type' => 'string',
                        'description' => 'The pattern, with the keywords of the route in braces, such as '
                            . '{id}-{rewrite}; {id} is always required.',
                    ],
                    'customized' => [
                        'type' => 'boolean',
                        'description' => 'As the read gives it; worked out by the shop.',
                    ],
                ],
                'required' => ['pattern'],
                'additionalProperties' => false,
            ];
        }
        // Accepted given back as read, so the routes of the read can be
        // given back whole; changing it is refused.
        $schema[self::MODULE_ROUTE] = [
            'type' => 'object',
            'description' => 'Only as somcp_get_seo_settings reads it: the route to modules stays with the back office.',
        ];

        return $schema;
    }
}
