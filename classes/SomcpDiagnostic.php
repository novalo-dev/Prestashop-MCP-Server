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
 * SomcpDiagnostic - calls the shop's own URLs and reports what a client finds.
 *
 * Every check here exists because something in a real hosting setup can break
 * it silently. Friendly URLs off, a shop in a subdirectory, an Authorization
 * header dropped by the FastCGI layer: none of these produce an error anywhere,
 * they just make the server invisible or unusable, and the merchant has no way
 * to tell which.
 *
 * The requests go out without the address guard of SomcpHttp, on purpose: a
 * shop behind a load balancer legitimately resolves to a private address, and
 * the URLs being called are its own.
 *
 * When a line that every address depends on fails - the shop disabled, friendly
 * URLs off, no certificate - the addresses are not called at all. Each would
 * fail for that one reason and report a cause of its own, which sent the
 * merchant after three problems that did not exist.
 *
 * Everything below the first two checks is done for one named shop, not for
 * "the current context": on a multistore installation PrestaShop still reports
 * a current shop when the administrator is working on all of them, so testing
 * that one and showing a row of green ticks would claim things about shops that
 * were never called.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpDiagnostic
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    /** @var string An address left uncalled because a line above already explains why it fails. */
    public const SKIPPED = 'skipped';

    /** @var array<int, int> cURL errors that come from TLS: the certificate or the handshake. */
    private const TLS_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    /** @var int cURL error when nothing accepts the connection. */
    private const CONNECT_ERROR = 7;

    /**
     * Runs every check.
     *
     * @param Somcp $module used for the translated labels
     *
     * @return array<int, array{status:string, label:string, detail:string, url:string, fix:string}>
     */
    public static function run(Somcp $module, ?int $idShop = null): array
    {
        $shop = new Shop($idShop > 0 ? $idShop : Somcp::currentShopId());
        if (!Validate::isLoadedObject($shop)) {
            $shop = Somcp::currentShop();
        }

        $curl = self::checkCurl($module);
        $active = self::checkShopActive($module, $shop);
        $friendly = self::checkFriendlyUrls($module, $shop);
        $location = self::checkSubdirectory($module, $shop);
        $certificate = $curl['status'] === self::OK
            ? self::checkCertificate($module, $shop)
            : self::skipped($module, $module->l10n('HTTPS certificate'), [$curl['label']], SomcpConfig::shopBaseUrl($shop));

        // Every address an assistant calls goes through these: one of them
        // failing is the whole reason the addresses fail.
        $blocking = [];
        foreach ([$curl, $active, $friendly, $certificate] as $line) {
            if ($line['status'] === self::ERROR) {
                $blocking[] = $line['label'];
            }
        }
        // The location only matters to the document anchored at the root.
        $blockingRoot = $location['status'] === self::ERROR ? array_merge($blocking, [$location['label']]) : $blocking;

        return [
            $curl,
            self::checkAdminDir($module),
            $active,
            self::checkMaintenance($module, $shop),
            $friendly,
            $location,
            $certificate,
            self::checkSslSetting($module, $shop),
            $blockingRoot === []
                ? self::checkAuthorizationServerMetadata($module, $shop)
                : self::skipped($module, $module->l10n('Authorization server document'), $blockingRoot, SomcpConfig::asMetadataUrl($shop)),
            $blocking === []
                ? self::checkProtectedResourceMetadata($module, $shop)
                : self::skipped($module, $module->l10n('Protected resource document'), $blocking, SomcpConfig::prmUrl($shop)),
            $blocking === []
                ? self::checkEndpointChallenge($module, $shop)
                : self::skipped($module, $module->l10n('MCP endpoint'), $blocking, SomcpConfig::resourceUri($shop)),
        ];
    }

    /**
     * A shop switched off in the multistore settings serves nothing at all.
     *
     * @param Somcp $module
     * @param Shop $shop
     *
     * @return array{status:string, label:string, detail:string, url:string, fix:string}
     */
    private static function checkShopActive(Somcp $module, Shop $shop): array
    {
        $active = (bool) $shop->active;

        return self::line(
            $active ? self::OK : self::ERROR,
            $module->l10n('Shop enabled'),
            $active
                ? $module->l10n('This shop is enabled.')
                : $module->l10n('This shop is disabled in the multistore settings. It serves nothing at all, so no assistant can reach it. Enable it to use the MCP server on this shop.'),
            '',
            $active ? '' : self::settingsPage('AdminShopGroup')
        );
    }

    /**
     * Maintenance mode hides the storefront, not the module's own addresses.
     *
     * @param Somcp $module
     * @param Shop $shop
     *
     * @return array{status:string, label:string, detail:string, url:string, fix:string}
     */
    private static function checkMaintenance(Somcp $module, Shop $shop): array
    {
        $open = self::isOpen($shop);

        return self::line(
            $open ? self::OK : self::WARNING,
            $module->l10n('Shop open'),
            $open
                ? $module->l10n('This shop is open.')
                : $module->l10n('This shop is in maintenance. Assistants still connect and work: the MCP address belongs to you, not to your visitors. Turn off the MCP server above if you want it closed too.'),
            '',
            $open ? '' : self::settingsPage('AdminMaintenance')
        );
    }

    /**
     * @param Somcp $module
     *
     * @return array{status:string, label:string, detail:string, url:string, fix:string}
     */
    private static function checkCurl(Somcp $module): array
    {
        $available = function_exists('curl_init');

        return self::line(
            $available ? self::OK : self::ERROR,
            $module->l10n('Outgoing HTTP requests'),
            $available
                ? $module->l10n('The cURL extension is available.')
                : $module->l10n('The cURL extension is missing. The module cannot read the metadata document published by a client, so no assistant can connect.'),
            ''
        );
    }

    /**
     * @param Somcp $module
     * @param Shop $shop
     *
     * @return array{status:string, label:string, detail:string, url:string, fix:string}
     */
    private static function checkFriendlyUrls(Somcp $module, Shop $shop): array
    {
        // Read for this shop: the setting can be on for one and off for another,
        // which is exactly how a merchant ends up with one shop unreachable.
        $enabled = (bool) Configuration::get('PS_REWRITING_SETTINGS', null, null, (int) $shop->id);

        return self::line(
            $enabled ? self::OK : self::ERROR,
            $module->l10n('Friendly URLs'),
            $enabled
                ? $module->l10n('Friendly URLs are enabled.')
                : $module->l10n('Friendly URLs are disabled. PrestaShop then routes nothing outside index.php, so the discovery documents cannot be reached and no assistant can connect. Enable them in Shop parameters, Traffic and SEO.'),
            '',
            $enabled ? '' : self::settingsPage('AdminMeta')
        );
    }

    /**
     * @param Somcp $module
     * @param Shop $shop
     *
     * @return array{status:string, label:string, detail:string, url:string, fix:string}
     */
    private static function checkSubdirectory(Somcp $module, Shop $shop): array
    {
        $inSubdirectory = SomcpConfig::isInSubdirectory($shop);

        return self::line(
            $inSubdirectory ? self::ERROR : self::OK,
            $module->l10n('Shop location'),
            $inSubdirectory
                ? $module->l10n('This shop is installed in a subdirectory. The authorization server document has to sit at the root of the domain, which is outside the shop, so PrestaShop cannot serve it. Moving the shop to the root of a domain or a subdomain is the only way around it.')
                : $module->l10n('The shop sits at the root of its domain.'),
            SomcpConfig::shopBaseUrl($shop)
        );
    }

    /**
     * Calls the shop over HTTPS and checks the certificate, as an assistant
     * does before anything else.
     *
     * The module writes every address in https whatever the shop settings say,
     * so the certificate is what decides whether an assistant gets in. Without
     * this line, a refused certificate surfaced as three failing addresses
     * blaming rewrite rules.
     *
     * @param Somcp $module
     * @param Shop $shop
     *
     * @return array{status:string, label:string, detail:string, url:string, fix:string}
     */
    private static function checkCertificate(Somcp $module, Shop $shop): array
    {
        $url = SomcpConfig::shopBaseUrl($shop);
        $label = $module->l10n('HTTPS certificate');
        // Only the handshake matters: any answer, even an error page, proves it.
        $response = SomcpHttp::fetch($url, false, [], null, 1024);
        if ($response['status'] > 0) {
            return self::line(self::OK, $label, $module->l10n('The certificate is valid.'), $url);
        }

        if (self::isLocal($shop)) {
            $detail = $module->l10n('This shop runs on a local address, which assistants cannot reach. To test it, give it a public HTTPS address with a tunnel such as cloudflared or ngrok, and set the shop domain to it.');
        } elseif (in_array($response['errno'], self::TLS_ERRORS, true)) {
            $detail = $module->l10n(
                'The certificate is refused: %reason%. Assistants only connect to a shop whose certificate is valid. Install or renew it from your hosting panel.',
                ['%reason%' => $response['reason']]
            );
        } elseif ($response['errno'] === self::CONNECT_ERROR) {
            $detail = $module->l10n('Nothing answers over HTTPS on this domain. Assistants only connect over HTTPS: enable a certificate for it from your hosting panel.');
        } else {
            $detail = $module->l10n(
                'The shop could not be reached over HTTPS: %reason%.',
                ['%reason%' => $response['reason']]
            );
        }

        return self::line(self::ERROR, $label, $detail, $url);
    }

    /**
     * SSL switched off in PrestaShop leaves the shop and its back office on
     * plain HTTP, employees' sign-in included. The module's own addresses are
     * https regardless, which is why this line does not stop the checks below.
     *
     * @param Somcp $module
     * @param Shop $shop
     *
     * @return array{status:string, label:string, detail:string, url:string, fix:string}
     */
    private static function checkSslSetting(Somcp $module, Shop $shop): array
    {
        $label = $module->l10n('SSL in PrestaShop');
        if ((bool) Configuration::get('PS_SSL_ENABLED', null, null, (int) $shop->id)) {
            return self::line(self::OK, $label, $module->l10n('SSL is enabled.'), '');
        }

        // A shop on the developer's own machine has no certificate to turn on.
        if (self::isLocal($shop)) {
            return self::line(
                self::WARNING,
                $label,
                $module->l10n('SSL is disabled, which is fine on a local shop. Enable it before the shop goes online.'),
                '',
                self::settingsPage('AdminPreferences')
            );
        }

        return self::line(
            self::ERROR,
            $label,
            $module->l10n('SSL is disabled. The shop and its back office answer over plain HTTP, and employees sign in unencrypted. Enable it in Shop parameters, General.'),
            '',
            self::settingsPage('AdminPreferences')
        );
    }

    /**
     * @param Somcp $module
     *
     * @return array{status:string, label:string, detail:string, url:string, fix:string}
     */
    private static function checkAdminDir(Somcp $module): array
    {
        $known = SomcpBackOffice::adminDir() !== '';

        return self::line(
            $known ? self::OK : self::WARNING,
            $module->l10n('Consent screen'),
            $known
                ? $module->l10n('The consent screen can be reached from the authorization flow.')
                : $module->l10n('The administration folder is not known yet. Save the settings once and this resolves itself.'),
            ''
        );
    }

    /**
     * @param Somcp $module
     * @param Shop $shop
     *
     * @return array{status:string, label:string, detail:string, url:string, fix:string}
     */
    private static function checkAuthorizationServerMetadata(Somcp $module, Shop $shop): array
    {
        $url = SomcpConfig::asMetadataUrl($shop);
        $label = $module->l10n('Authorization server document');
        $response = SomcpHttp::fetch($url, false);

        if ($response['status'] !== 200) {
            if ($response['status'] === 404) {
                // The one case the rewrite rule explains: the path never reached PrestaShop's router.
                $detail = $module->l10n(
                    'This address answered %status% instead of 200. Assistants read it to find out where to sign in, so none of them can connect until it works. Some hosting setups answer paths starting with .well-known themselves, before PrestaShop ever sees them; a rewrite rule sending that path to index.php fixes it.',
                    ['%status%' => '404']
                );
            } else {
                $detail = self::failure($module, $response, $shop)
                    ?? $module->l10n(
                        'This address answered %status% instead of 200. Assistants read it to find out where to sign in, so none of them can connect until it works.',
                        ['%status%' => (string) $response['status']]
                    );
            }

            return self::line(self::ERROR, $label, $detail, $url);
        }

        $document = json_decode($response['body'], true);
        if (!is_array($document) || ($document['issuer'] ?? '') !== SomcpConfig::issuer($shop)) {
            return self::line(
                self::ERROR,
                $label,
                $module->l10n('This address answered, but not with the expected document. Something else on the hosting is serving that path.'),
                $url
            );
        }

        return self::line(self::OK, $label, $module->l10n('Served correctly.'), $url);
    }

    /**
     * @param Somcp $module
     * @param Shop $shop
     *
     * @return array{status:string, label:string, detail:string, url:string, fix:string}
     */
    private static function checkProtectedResourceMetadata(Somcp $module, Shop $shop): array
    {
        $url = SomcpConfig::prmUrl($shop);
        $label = $module->l10n('Protected resource document');
        $response = SomcpHttp::fetch($url, false);
        $document = json_decode($response['body'], true);

        if ($response['status'] !== 200 || !is_array($document)
            || ($document['resource'] ?? '') !== SomcpConfig::resourceUri($shop)) {
            return self::line(
                self::ERROR,
                $label,
                self::failure($module, $response, $shop)
                    ?? $module->l10n(
                        'This address answered %status% instead of the expected document.',
                        ['%status%' => (string) $response['status']]
                    ),
                $url
            );
        }

        return self::line(self::OK, $label, $module->l10n('Served correctly.'), $url);
    }

    /**
     * Calls the MCP endpoint the way an assistant does before it has a token,
     * and checks two things at once: that the challenge comes back, and that
     * the Authorization header survived the trip to PHP.
     *
     * @param Somcp $module
     * @param Shop $shop
     *
     * @return array{status:string, label:string, detail:string, url:string, fix:string}
     */
    private static function checkEndpointChallenge(Somcp $module, Shop $shop): array
    {
        $url = SomcpConfig::resourceUri($shop);
        $label = $module->l10n('MCP endpoint');
        // POST, like a real client: the endpoint answers 405 to anything else,
        // which would make this check fail for the wrong reason.
        $response = SomcpHttp::fetch(
            $url,
            false,
            ['Authorization: Bearer somcp-diagnostic'],
            (string) json_encode(['jsonrpc' => '2.0', 'id' => 'somcp-diagnostic', 'method' => 'ping'])
        );

        if ($response['status'] !== 401) {
            return self::line(
                self::ERROR,
                $label,
                self::failure($module, $response, $shop)
                    ?? $module->l10n(
                        'The endpoint answered %status% where an unauthenticated call must answer 401.',
                        ['%status%' => (string) $response['status']]
                    ),
                $url
            );
        }

        if (strpos(Tools::strtolower($response['headers']['www-authenticate'] ?? ''), 'resource_metadata') === false) {
            return self::line(
                self::ERROR,
                $label,
                $module->l10n('The endpoint answered 401 but without the challenge that tells an assistant where to sign in.'),
                $url
            );
        }

        $body = json_decode($response['body'], true);
        if (is_array($body) && ($body['authorization_received'] ?? null) === false) {
            return self::line(
                self::ERROR,
                $label,
                $module->l10n('The endpoint answers correctly, but the Authorization header never reached PHP: this hosting drops it. Assistants will always be treated as signed out. Adding a rewrite rule that copies the header into the environment fixes it.'),
                $url
            );
        }

        return self::line(
            self::OK,
            $label,
            $module->l10n('Answers with the expected challenge, and credentials reach the module.'),
            $url
        );
    }

    /**
     * The causes an address shares with the others: no answer at all, or the
     * maintenance page answering in the module's place.
     *
     * @param Somcp $module
     * @param array{status:int, errno:int, reason:string} $response
     * @param Shop $shop
     *
     * @return string|null null when the answer needs the caller's own explanation
     */
    private static function failure(Somcp $module, array $response, Shop $shop): ?string
    {
        if ($response['status'] === 0) {
            return $module->l10n('This address could not be reached: %reason%.', ['%reason%' => $response['reason']]);
        }
        // The module's controllers answer through maintenance; a 503 from a
        // shop in maintenance is the storefront's page, so the request never
        // reached the module.
        if ($response['status'] === 503 && !self::isOpen($shop)) {
            return $module->l10n('The maintenance page answered instead of the module: this address does not reach it.');
        }

        return null;
    }

    /**
     * @param Shop $shop
     *
     * @return bool
     */
    private static function isOpen(Shop $shop): bool
    {
        return (bool) Configuration::get('PS_SHOP_ENABLE', null, null, (int) $shop->id);
    }

    /**
     * Whether the shop's domain only exists on the machine or network it runs on.
     *
     * @param Shop $shop
     *
     * @return bool
     */
    private static function isLocal(Shop $shop): bool
    {
        $host = Tools::strtolower((string) parse_url(SomcpConfig::shopBaseUrl($shop), PHP_URL_HOST));
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return $host === 'localhost' || (bool) preg_match('/\.(localhost|local|test)$/', $host);
    }

    /**
     * The PrestaShop page where a failing setting is changed.
     *
     * @param string $controller
     *
     * @return string
     */
    private static function settingsPage(string $controller): string
    {
        return (string) Somcp::legacyContext()->link->getAdminLink($controller);
    }

    /**
     * @param Somcp $module
     * @param string $label
     * @param array<int, string> $causes labels of the failing lines above
     * @param string $url
     *
     * @return array{status:string, label:string, detail:string, url:string, fix:string}
     */
    private static function skipped(Somcp $module, string $label, array $causes, string $url): array
    {
        return self::line(
            self::SKIPPED,
            $label,
            $module->l10n('Not checked: fix %causes% above first.', ['%causes%' => implode(', ', $causes)]),
            $url
        );
    }

    /**
     * @param string $status
     * @param string $label
     * @param string $detail
     * @param string $url
     * @param string $fix the PrestaShop page where the setting is changed
     *
     * @return array{status:string, label:string, detail:string, url:string, fix:string}
     */
    private static function line(string $status, string $label, string $detail, string $url, string $fix = ''): array
    {
        return ['status' => $status, 'label' => $label, 'detail' => $detail, 'url' => $url, 'fix' => $fix];
    }
}
