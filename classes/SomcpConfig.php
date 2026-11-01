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
 * SomcpConfig - module settings and the canonical URLs of the server.
 *
 * Settings live in ps_configuration. That table is read through a cache, which
 * is the wrong home for a value that changes on a click; none of the keys
 * below behave that way - they are edited from a settings form, a few times a
 * year.
 *
 * Every URL an OAuth or MCP client is told about is built here and nowhere
 * else. The resource identifier, the issuer and the well-known paths have to
 * agree down to the trailing slash: a client that builds the metadata URL from
 * the issuer and gets a document whose `issuer` differs by one character must
 * reject it (RFC 8414 section 3.3).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpConfig
{
    public const ENABLED = 'SOMCP_ENABLED';
    public const OAUTH_ENABLED = 'SOMCP_OAUTH_ENABLED';
    public const ACCESS_TTL = 'SOMCP_ACCESS_TTL';
    public const REFRESH_TTL = 'SOMCP_REFRESH_TTL';
    public const REFRESH_ROTATION = 'SOMCP_REFRESH_ROTATION';
    public const CIMD_ENABLED = 'SOMCP_CIMD_ENABLED';
    public const DCR_ENABLED = 'SOMCP_DCR_ENABLED';
    public const REDIRECT_HOSTS = 'SOMCP_REDIRECT_HOSTS';
    public const REQUIRED_PROFILE = 'SOMCP_REQUIRED_PROFILE';
    public const LOG_ENABLED = 'SOMCP_LOG_ENABLED';

    /**
     * @var string How long a client may keep the list of tools, in seconds.
     *
     * The server is stateless, so it has no way of telling a client that the
     * list changed - there is no open connection to push down. The client
     * re-reads when this has run out, and not before, which is why a tool
     * added while building one does not show up until the merchant
     * disconnects and reconnects the assistant.
     */
    public const TOOLS_TTL = 'SOMCP_TOOLS_TTL';

    /**
     * @var string Tools the merchant switched off, space separated.
     *
     * A list of what is off rather than what is on, so that a tool added by an
     * update is available without the merchant having to go and allow it - and
     * so that a tool switched off stays off even if the module publishing it is
     * reinstalled.
     */
    public const TOOLS_OFF = 'SOMCP_TOOLS_OFF';

    /**
     * @var string Prompts the merchant switched off, space separated.
     *
     * Kept apart from the tools: a prompt is words a module puts in front of
     * the assistant, so a merchant may well want a module's tools without
     * its guides.
     */
    public const PROMPTS_OFF = 'SOMCP_PROMPTS_OFF';
    public const ADMIN_DIR = 'SOMCP_ADMIN_DIR';

    /**
     * @var string Key the server signs its multi-round request state with.
     *
     * Minted on first use rather than at install, and never shown anywhere: it
     * is the only thing standing between the shop and a state string a caller
     * wrote themselves. Its own key rather than a shared shop secret, so that
     * rotating it - by emptying this one value - invalidates pending questions
     * and nothing else.
     */
    public const STATE_KEY = 'SOMCP_STATE_KEY';

    /** @var string Path of the module inside the shop, used as the issuer suffix. */
    public const ISSUER_PATH = 'module/somcp';

    /** @var int Seconds an authorization code stays valid. OAuth 2.1 recommends one minute or less. */
    public const CODE_TTL = 60;

    /** @var int Seconds a pending authorization request waits for the merchant to sign in and answer. */
    public const REQUEST_TTL = 900;

    /**
     * Default value of every setting. install() writes them, the settings form
     * falls back on them, and a key added by a later version is picked up here
     * without an upgrade script having to seed it.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            self::ENABLED => '1',
            self::OAUTH_ENABLED => '1',
            // One hour. Short enough that a stolen token expires before it is
            // worth much, long enough not to spend a refresh on every call.
            self::ACCESS_TTL => '3600',
            // Thirty days: an assistant a merchant uses weekly should not have
            // to go through the consent screen again every month.
            self::REFRESH_TTL => '2592000',
            self::REFRESH_ROTATION => '1',
            self::CIMD_ENABLED => '1',
            self::DCR_ENABLED => '1',
            self::REDIRECT_HOSTS => '',
            self::REQUIRED_PROFILE => '0',
            self::LOG_ENABLED => '1',
            // Five minutes: long enough that an assistant is not re-reading the
            // list on every question, short enough that a merchant who just
            // switched a tool off does not wait long for it to disappear.
            self::TOOLS_TTL => '300',
            self::TOOLS_OFF => '',
            self::PROMPTS_OFF => '',
            self::ADMIN_DIR => '',
            // Left empty on purpose: the value is a secret, and a secret
            // written by seed() would be the same on every shop that installed
            // the module from the same archive. SomcpRequestState mints one the
            // first time it signs anything.
            self::STATE_KEY => '',
        ];
    }

    /**
     * @param string $key
     * @param int|null $idShop
     *
     * @return string
     */
    public static function get(string $key, ?int $idShop = null): string
    {
        $value = Configuration::get($key, null, null, $idShop);
        if ($value === false || $value === '') {
            $defaults = self::defaults();

            return $defaults[$key] ?? '';
        }

        return (string) $value;
    }

    /**
     * @param string $key
     * @param int|null $idShop
     *
     * @return int
     */
    public static function getInt(string $key, ?int $idShop = null): int
    {
        return (int) self::get($key, $idShop);
    }

    /**
     * @param string $key
     * @param int|null $idShop
     *
     * @return bool
     */
    public static function isOn(string $key, ?int $idShop = null): bool
    {
        return self::get($key, $idShop) === '1';
    }

    /**
     * @param string $key
     * @param string $value
     * @param int|null $idShop
     */
    /**
     * A value as the module stores it, from what a form or the chat sent: the
     * bounds and the allowed characters of each setting, kept in one place so
     * the settings page and the built-in assistant cannot come to disagree.
     *
     * @param string $key one of defaults()
     * @param string $raw
     *
     * @return string
     */
    public static function clean(string $key, string $raw): string
    {
        switch ($key) {
            case self::ENABLED:
            case self::OAUTH_ENABLED:
            case self::REFRESH_ROTATION:
            case self::CIMD_ENABLED:
            case self::DCR_ENABLED:
            case self::LOG_ENABLED:
                return $raw === '1' ? '1' : '0';
            case self::ACCESS_TTL:
                return (string) max(60, (int) $raw);
            case self::REFRESH_TTL:
                return (string) max(600, (int) $raw);
            case self::REQUIRED_PROFILE:
                return (string) max(0, (int) $raw);
            case self::TOOLS_TTL:
                // Bounds repeated here rather than trusted from the form: the
                // endpoint clamps the value it announces anyway, and a field
                // left saying 0 while the shop announces 10 would read as a bug.
                return (string) max(10, min(3600, (int) $raw));
            case self::REDIRECT_HOSTS:
                // Host names only: anything else here would silently widen what
                // the module accepts as a return address.
                $hosts = (string) preg_replace('/[^a-zA-Z0-9.\-\s,;]/', '', $raw);

                return (string) Tools::substr(trim($hosts), 0, 500);
            default:
                return $raw;
        }
    }

    public static function set(string $key, string $value, ?int $idShop = null): void
    {
        Configuration::updateValue($key, $value, false, null, $idShop);
    }

    /**
     * Where the value an administrator currently sees actually comes from.
     *
     * PrestaShop resolves a setting shop first, then group, then global. This
     * reports that resolution so the form can say whether the value belongs to
     * the level being edited or is inherited from above - and `own` says which,
     * for the level the administrator is working on.
     *
     * @param string $key
     *
     * @return array{source:string, own:bool} source is shop, group, global or default
     */
    public static function level(string $key): array
    {
        $idShop = (int) Shop::getContextShopID(true);
        $idGroup = (int) Shop::getContextShopGroupID(true);

        $atShop = $idShop > 0 && Configuration::hasKey($key, null, null, $idShop);
        $atGroup = $idGroup > 0 && Configuration::hasKey($key, null, $idGroup);
        $atGlobal = Configuration::hasKey($key);

        if ($atShop) {
            $source = 'shop';
        } elseif ($atGroup) {
            $source = 'group';
        } elseif ($atGlobal) {
            $source = 'global';
        } else {
            // Nothing stored anywhere: the value shown comes from defaults().
            $source = 'default';
        }

        switch (Shop::getContext()) {
            case Shop::CONTEXT_SHOP:
                $own = $atShop;
                break;
            case Shop::CONTEXT_GROUP:
                $own = $atGroup;
                break;
            default:
                // Editing all shops is editing the global level itself, so the
                // value is always that level's own.
                $own = true;
        }

        return ['source' => $source, 'own' => $own];
    }

    /**
     * True when the administrator is editing a level that can inherit from
     * above - a single shop or a group, on a multistore installation.
     *
     * @return bool
     */
    public static function inheritableContext(): bool
    {
        return Shop::isFeatureActive() && Shop::getContext() !== Shop::CONTEXT_ALL;
    }

    /**
     * Drops the value stored at the level being edited, so the setting falls
     * back to the one above it.
     *
     * @param string $key
     */
    public static function inherit(string $key): void
    {
        // No-op in the all-shops context, where there is nothing above.
        Configuration::deleteFromContext($key);
    }

    /**
     * Writes any default not present yet. Called on install and on upgrade.
     */
    public static function seed(): void
    {
        foreach (self::defaults() as $key => $value) {
            if (Configuration::hasKey($key)) {
                continue;
            }

            // Written at the all-shops level explicitly. Left to the context,
            // a module installed while the administrator was working on one
            // shop would store its defaults for that shop alone, and every
            // other shop would show them as inherited from nowhere - a value
            // of its own on one shop and not on the next, without anyone
            // having chosen that.
            Configuration::updateValue($key, $value, false, 0, 0);
        }
    }

    /**
     * Removes every setting the module owns.
     */
    public static function purge(): void
    {
        foreach (array_keys(self::defaults()) as $key) {
            Configuration::deleteByName($key);
        }
    }

    /**
     * Base URL of the shop, always over HTTPS.
     *
     * Shop::getBaseURL() picks the scheme from the current request, which would
     * give a metadata document that advertises http:// when the merchant opens
     * the settings page without SSL. Bearer tokens over plain HTTP are refused
     * by every client, so the documents state https and the diagnostic reports
     * a shop that cannot serve it.
     *
     * @param Shop|null $shop
     *
     * @return string with a trailing slash
     */
    public static function shopBaseUrl(?Shop $shop = null): string
    {
        if ($shop === null) {
            $shop = Somcp::currentShop();
        }
        $domain = $shop->domain_ssl ?: $shop->domain;

        return 'https://' . $domain . $shop->getBaseURI();
    }

    /**
     * Canonical URI of the MCP server, used as the OAuth resource indicator.
     *
     * No trailing slash, no query, no fragment: RFC 8707 compares it as a
     * string and the specification asks for the form without the trailing
     * slash for interoperability.
     *
     * @param Shop|null $shop defaults to the shop answering the request
     *
     * @return string
     */
    public static function resourceUri(?Shop $shop = null): string
    {
        return rtrim(self::shopBaseUrl($shop), '/') . '/' . self::ISSUER_PATH . '/mcp';
    }

    /**
     * The MCP address of every shop the administrator is working on.
     *
     * Each address is built from its own shop's domain: on a multistore
     * installation the shops usually differ by domain, and handing a merchant
     * the address of another shop would connect an assistant to the wrong one.
     *
     * @return array<int, array{id:int, name:string, url:string, current:bool}>
     */
    public static function contextEndpoints(): array
    {
        // Only meaningful when one shop is selected: in the all-shops or group
        // context PrestaShop still reports a current shop - the default one -
        // and marking it would label an arbitrary shop as the one being worked on.
        $idCurrent = Shop::getContext() === Shop::CONTEXT_SHOP
            ? Somcp::currentShopId()
            : 0;

        $ids = array_map('intval', Shop::getContextListShopID());
        if ($ids === []) {
            $ids = [Somcp::currentShopId()];
        }

        $endpoints = [];
        foreach ($ids as $idShop) {
            $shop = new Shop($idShop);
            if (!Validate::isLoadedObject($shop)) {
                continue;
            }
            $endpoints[] = [
                'id' => $idShop,
                'name' => (string) $shop->name,
                'url' => self::resourceUri($shop),
                'current' => $idCurrent > 0 && $idShop === $idCurrent,
            ];
        }

        return $endpoints;
    }

    /**
     * Issuer identifier of the authorization server.
     *
     * @return string
     */
    public static function issuer(?Shop $shop = null): string
    {
        return rtrim(self::shopBaseUrl($shop), '/') . '/' . self::ISSUER_PATH;
    }

    /**
     * URL of the authorization server metadata document.
     *
     * RFC 8414 section 3.1 inserts the issuer path between the well-known
     * suffix and nothing else, which anchors the document at the domain root -
     * not under the shop directory. A shop installed in a subfolder therefore
     * cannot serve it through PrestaShop, and the diagnostic says so.
     *
     * @return string
     */
    public static function asMetadataUrl(?Shop $shop = null): string
    {
        $parts = parse_url(self::issuer($shop));
        $path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';

        return 'https://' . ($parts['host'] ?? '') . '/.well-known/oauth-authorization-server'
            . ($path !== '' ? '/' . $path : '');
    }

    /**
     * URL of the protected resource metadata document.
     *
     * Served from the module itself rather than from the domain root: since the
     * 2026-07-28 revision a client uses the `resource_metadata` parameter of the
     * WWW-Authenticate challenge when it is present, and the module always sends
     * it. The well-known fallback is only reached by a client that got no
     * challenge, which cannot happen here.
     *
     * @return string
     */
    public static function prmUrl(?Shop $shop = null): string
    {
        return rtrim(self::shopBaseUrl($shop), '/') . '/' . self::ISSUER_PATH . '/prm';
    }

    /**
     * Public address of the module icon, for the shop that answers.
     *
     * A static file under the shop's own web root: an assistant fetches it
     * without a session, a token or a consent, because it is brand metadata and
     * nothing else. Built per shop so that each store on a multistore
     * installation serves it from its own domain - the icon is local, while the
     * website the server points at stays the publisher's.
     *
     * @param Shop|null $shop
     *
     * @return string
     */
    public static function iconUrl(?Shop $shop = null): string
    {
        return rtrim(self::shopBaseUrl($shop), '/') . '/modules/somcp/views/img/icon.png';
    }

    /**
     * @return string
     */
    public static function authorizationEndpoint(): string
    {
        return rtrim(self::shopBaseUrl(), '/') . '/' . self::ISSUER_PATH . '/authorize';
    }

    /**
     * @param string $action token, revoke or register
     *
     * @return string
     */
    public static function oauthEndpoint(string $action): string
    {
        return rtrim(self::shopBaseUrl(), '/') . '/' . self::ISSUER_PATH . '/oauth?a=' . $action;
    }

    /**
     * True when the shop lives in a subdirectory, in which case the
     * authorization server metadata cannot be served (see asMetadataUrl).
     *
     * @return bool
     */
    public static function isInSubdirectory(?Shop $shop = null): bool
    {
        $parts = parse_url(self::shopBaseUrl($shop));
        $path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';

        return $path !== '';
    }
}
