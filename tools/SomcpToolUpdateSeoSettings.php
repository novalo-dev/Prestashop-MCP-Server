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
 * somcp_update_seo_settings - Save on the cards Set up URLs and SEO options
 * of Shop Parameters > Traffic & SEO > SEO & URLs, for three of their fields.
 *
 * Accented URL, Redirect to the canonical URL and Display attributes in the
 * product meta title change no address: the core reads them when it builds
 * a page. Only their configuration keys are written, for the shop of the
 * token, and .htaccess is never written again. Friendly URL, the language
 * prefix and the Apache options move every address, the one an assistant is
 * connected through included, and the shop URL moves the whole shop: they
 * stay with the back office.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in:
// the read answered and the failure sentence are theirs.
require_once __DIR__ . '/SomcpToolGetSeoSettings.php';
require_once __DIR__ . '/SomcpToolUpdateGeneralSettings.php';

class SomcpToolUpdateSeoSettings
{
    /** @var array<string, string> The switches written, in the order of the screen, with their keys. */
    private const SWITCHES = [
        'accented_urls' => 'PS_ALLOW_ACCENTED_CHARS_URL',
        'product_attributes_in_title' => 'PS_PRODUCT_ATTRIBUTES_IN_TITLE',
    ];

    /**
     * The choices of Redirect to the canonical URL, under the names the read
     * answers, at the place of the value stored. Kept as a list: as keys,
     * '302' and '301' would turn into numbers.
     *
     * @var list<string>
     */
    private const CANONICAL = [
        0 => 'none',
        1 => '302',
        2 => '301',
    ];

    /**
     * @var array<string, string> what somcp_get_seo_settings answers and this tool does not write, with where it
     *                            is changed: taken out when given back as read, so the read can be given back whole
     */
    private const KEPT_AS_READ = [
        'friendly_urls' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'default_language_url_prefix' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'disable_apache_multiviews' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'disable_apache_mod_security' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'https' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'https_everywhere' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'domains' => 'not changed by this tool - change it in Shop Parameters > Traffic & SEO',
        'routes' => 'change them with somcp_update_url_schema',
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
            'somcp_update_seo_settings',
            Somcp::t('Change the SEO and URL settings'),
            'Changes three settings of Shop Parameters > Traffic & SEO > SEO & URLs, as Save: Accented URL, '
                . 'Redirect to the canonical URL and Display attributes in the product meta title. Only the fields '
                . 'given change; the read of somcp_get_seo_settings given back whole changes nothing. Friendly URL, '
                . 'the language prefix, the Apache options and the shop URL stay with the back office; the URL '
                . 'patterns are changed with somcp_update_url_schema. Changes the shop: ask first.',
            [
                'type' => 'object',
                'properties' => [
                    'accented_urls' => [
                        'type' => 'boolean',
                        'description' => 'Accented URL: whether a Friendly URL may hold accented letters.',
                    ],
                    'canonical_redirect' => [
                        'type' => 'string',
                        'enum' => self::CANONICAL,
                        'description' => 'Redirect to the canonical URL: none, 302 (while setting up the shop) or '
                            . '301 (once it is live).',
                    ],
                    'product_attributes_in_title' => [
                        'type' => 'boolean',
                        'description' => 'Display attributes in the product meta title of a product page with a '
                            . 'combination.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_WRITE],
            [self::class, 'handle'],
            // The cards are saved from SEO & URLs, which takes the edit right
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
        $stored = SomcpToolGetSeoSettings::settings($idShop);

        // Every mistake of the call in one refusal (#169), so it is corrected in one go.
        $problems = SomcpTool::unknownFields(
            $arguments,
            array_merge(['canonical_redirect'], array_keys(self::SWITCHES), array_keys(self::KEPT_AS_READ))
        );
        foreach (self::KEPT_AS_READ as $field => $line) {
            // Null is left out, as a setting the version lacks reads null.
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            if (!SomcpToolGetSeoSettings::sameAsRead($arguments[$field], $stored[$field])) {
                $problems[] = $field . ': ' . $line;
            }
        }

        $given = [];
        if (array_key_exists('canonical_redirect', $arguments) && $arguments['canonical_redirect'] !== null) {
            if (is_string($arguments['canonical_redirect']) && in_array($arguments['canonical_redirect'], self::CANONICAL, true)) {
                $given['canonical_redirect'] = $arguments['canonical_redirect'];
            } else {
                $problems[] = SomcpTool::oneOf('canonical_redirect', self::CANONICAL);
            }
        }
        foreach (array_keys(self::SWITCHES) as $field) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            if (!is_bool($arguments[$field])) {
                $problems[] = $field . ': true or false';
                continue;
            }
            $given[$field] = $arguments[$field];
        }

        if ($problems !== []) {
            return SomcpTool::refused('changed', $problems);
        }

        $plan = self::plan($given, $stored);
        if ($plan === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The SEO settings already hold every value given. Nothing was written.',
                'settings' => $stored,
            ]);
        }

        $saved = [];
        foreach ($plan as $change) {
            if (!Configuration::updateValue($change['key'], $change['stored'], false, $idShopGroup, $idShop)) {
                return SomcpToolUpdateGeneralSettings::failed(
                    $saved,
                    $change['field'] . ' could not be saved',
                    $idEmployee,
                    'The SEO settings',
                    'somcp_get_seo_settings',
                    'seo_settings_update_failed'
                );
            }
            $saved[] = $change['field'];
        }
        SomcpLogger::log('seo_settings_updated', SomcpLogger::OUTCOME_SUCCESS, implode(', ', $saved), '', $idEmployee);

        // Read back rather than echoed: what the assistant shows is what the
        // shop stored, in the shape of the read.
        $after = SomcpToolGetSeoSettings::settings($idShop);

        $warnings = [];
        foreach ($plan as $change) {
            if ($change['field'] === 'accented_urls') {
                $warnings[] = $change['new'] === true
                    ? 'New friendly URLs may now hold accented letters; those already saved keep theirs.'
                    : 'Friendly URLs can now hold only letters without accents, digits, _ and -: a product, '
                        . 'category, brand, supplier or page whose Friendly URL has an accent is refused at its next '
                        . 'save until it is corrected.';
            } elseif ($change['field'] === 'canonical_redirect' && $change['new'] === 'none') {
                // The read's own reason, so the two never say it differently.
                foreach ($after['warnings'] as $warning) {
                    if (($warning['code'] ?? null) === 'no_canonical_redirect') {
                        $warnings[] = (string) $warning['why'];
                    }
                }
            } elseif ($change['field'] === 'product_attributes_in_title') {
                $warnings[] = 'The meta title of a product page with a combination now '
                    . ($change['new'] === true ? 'shows' : 'no longer shows') . ' its attributes.';
            }
        }
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'The settings changed for this shop only, as the back office saves them in a shop context.';
        }

        $result = [
            'changed' => true,
            'changes' => array_map(static function (array $change): array {
                return ['field' => $change['field'], 'old' => $change['old'], 'new' => $change['new']];
            }, $plan),
            'settings' => $after,
        ];
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }

    /**
     * The keys to save, in the order of the screen (Set up URLs, then SEO
     * options), each with its field, its old and new value under the names
     * and codes of the parameters, and the value stored.
     *
     * @param array<string, mixed> $given
     * @param array<string, mixed> $stored
     *
     * @return array<int, array{field: string, key: string, old: mixed, new: mixed, stored: int}>
     */
    private static function plan(array $given, array $stored): array
    {
        $plan = [];
        foreach (['accented_urls', 'canonical_redirect', 'product_attributes_in_title'] as $field) {
            if (!array_key_exists($field, $given) || $given[$field] === $stored[$field]) {
                continue;
            }
            $plan[] = [
                'field' => $field,
                'key' => $field === 'canonical_redirect' ? 'PS_CANONICAL_REDIRECT' : self::SWITCHES[$field],
                'old' => $stored[$field],
                'new' => $given[$field],
                'stored' => $field === 'canonical_redirect'
                    ? (int) array_search($given[$field], self::CANONICAL, true)
                    : ($given[$field] ? 1 : 0),
            ];
        }

        return $plan;
    }
}
