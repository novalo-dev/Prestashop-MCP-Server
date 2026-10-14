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
 * SomcpBackOffice - sending a visitor from the shop front to the consent screen.
 *
 * Two things stand in the way and both are handled here.
 *
 * The first is the address: a front controller has no _PS_ADMIN_DIR_, so the
 * name of the administration folder is recorded whenever the module runs in the
 * back office and read back later.
 *
 * The second is the security token PrestaShop puts in every administration URL.
 * It is derived from the controller, its tab and the employee, so it can be
 * computed here as soon as the employee is known - which is the case exactly
 * when it is needed, since an employee who is not signed in gets sent to the
 * login form and comes back on a URL PrestaShop built itself. The employee
 * identifier is read from the back office cookie for that one purpose and is
 * never treated as proof of anything: whether the visitor is allowed in remains
 * PrestaShop's decision, and a wrong guess only costs the extra click on the
 * standard invalid-token page.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpBackOffice
{
    /** @var string Controller that shows the consent screen. */
    public const CONSENT_CONTROLLER = 'AdminSomcpConsent';

    /**
     * @var string Controller the merchant deposits a file on.
     *
     * The second page reached from outside the back office, and it goes through
     * the same door for the same reasons: PrestaShop decides who the visitor is,
     * and an employee who is not signed in is sent to the login form and comes
     * back here on an address PrestaShop built.
     */
    public const UPLOAD_CONTROLLER = 'AdminSomcpUpload';

    /** @var string Controller of the AI Agent page, the one the module puts in the menu. */
    public const AGENT_CONTROLLER = 'AdminSomcpAgent';

    /**
     * Whether an employee profile may connect an assistant to this shop.
     *
     * The merchant can restrict this to one profile. Left unset, any employee
     * may connect one on their own behalf - and the assistant then holds
     * exactly their rights, no more.
     *
     * Read from here by both the consent screen and the settings page, so the
     * button an employee is offered and the answer they get when they press it
     * can never disagree.
     *
     * @param int $idProfile
     *
     * @return bool
     */
    public static function mayGrant(int $idProfile): bool
    {
        $required = SomcpConfig::getInt(SomcpConfig::REQUIRED_PROFILE);
        if ($required <= 0) {
            return true;
        }

        return $idProfile === $required || $idProfile === (int) _PS_ADMIN_PROFILE_;
    }

    /**
     * Records the administration folder name. Called from the back office only,
     * where the constant exists.
     */
    public static function rememberAdminDir(): void
    {
        if (!defined('_PS_ADMIN_DIR_')) {
            return;
        }

        $current = basename((string) _PS_ADMIN_DIR_);
        if ($current !== '' && $current !== SomcpConfig::get(SomcpConfig::ADMIN_DIR)) {
            // PrestaShop renames this folder by itself the first time a shop is
            // used, so a value stored at install time can already be stale.
            //
            // Written for all shops, not for the one in context: there is one
            // back office per installation, whatever shop an administrator
            // happens to be working on. Stored at the context level, a value
            // recorded while working on one shop was invisible to the others,
            // whose authorization flow then had no address to send anyone to.
            Configuration::updateValue(SomcpConfig::ADMIN_DIR, $current, false, 0, 0);
        }
    }

    /**
     * @return string empty when the module has never run in the back office
     */
    public static function adminDir(): string
    {
        return SomcpConfig::get(SomcpConfig::ADMIN_DIR);
    }

    /**
     * Absolute URL of the consent screen.
     *
     * @return string empty when the administration folder is unknown
     */
    public static function consentUrl(): string
    {
        return self::entryUrl(self::CONSENT_CONTROLLER, 'consent', []);
    }

    /**
     * Absolute URL of the page a file is deposited on.
     *
     * The reference travels in the address because the page has to know which
     * deposit it is serving. It authorizes nothing on its own: the page is
     * behind the back office session, and the deposit is refused unless the
     * employee signed in is the one the invitation was opened for. That check
     * is what the specification demands of a URL-mode elicitation, and it is the
     * same one the consent screen already makes.
     *
     * @param string $reference
     * @param int $idEmployee the account the deposit was opened for
     *
     * @return string empty when the administration folder is unknown
     */
    public static function uploadUrl(string $reference, int $idEmployee): string
    {
        return self::entryUrl(self::UPLOAD_CONTROLLER, 'upload', ['ref' => $reference], $idEmployee);
    }

    /**
     * Address of one of the module's back office pages, reachable from outside.
     *
     * @param string $controller
     * @param string $route last segment of the PrestaShop 9 entry route
     * @param array<string, string> $extra query parameters the page needs
     * @param int|null $idEmployee who the legacy token is computed for. Null reads
     *                             the back office cookie, which is right when the
     *                             merchant's own browser is the one asking - and
     *                             wrong for an address built for an assistant to
     *                             hand over, where no such cookie exists.
     *
     * @return string empty when the administration folder is unknown
     */
    private static function entryUrl(string $controller, string $route, array $extra, ?int $idEmployee = null): string
    {
        $dir = self::adminDir();
        if ($dir === '') {
            return '';
        }

        if (self::usesSymfonyEntry()) {
            // A route of our own, which PrestaShop authenticates natively and
            // which the token listener leaves alone. It is that listener, not
            // the legacy token check, that used to meet an already signed-in
            // employee with the "token compromised" screen: it demands a
            // Symfony token tied to the back office session, and a front
            // controller has no way to produce one. From inside that route we
            // do, so the page is reached with both tokens in hand.
            $url = self::adminBaseUrl() . '/modules/somcp/' . $route;

            return $extra === [] ? $url : $url . '?' . http_build_query($extra);
        }

        // PrestaShop 8 serves its legacy pages outside the Symfony router and
        // asks only for the legacy token, which is computed here.
        return self::legacyUrl(
            $controller,
            $idEmployee ?? self::currentEmployeeIdHint(),
            '',
            $extra
        );
    }

    /**
     * Address of the consent screen in its legacy form.
     *
     * @param int $idEmployee employee the legacy token is computed for, 0 when unknown
     * @param string $symfonyToken CSRF token of the back office session, empty on PrestaShop 8
     *
     * @return string
     */
    public static function legacyConsentUrl(int $idEmployee, string $symfonyToken): string
    {
        return self::legacyUrl(self::CONSENT_CONTROLLER, $idEmployee, $symfonyToken, []);
    }

    /**
     * Address of one of the module's back office pages in its legacy form.
     *
     * @param string $controller
     * @param int $idEmployee employee the legacy token is computed for, 0 when unknown
     * @param string $symfonyToken CSRF token of the back office session, empty on PrestaShop 8
     * @param array<string, string> $extra query parameters the page needs
     *
     * @return string
     */
    public static function legacyUrl(string $controller, int $idEmployee, string $symfonyToken, array $extra = []): string
    {
        $url = self::adminBaseUrl() . '/index.php?controller=' . $controller;

        if ($idEmployee > 0) {
            $url .= '&token=' . self::adminToken($controller, $idEmployee);
        }
        if ($symfonyToken !== '') {
            $url .= '&_token=' . urlencode($symfonyToken);
        }
        if ($extra !== []) {
            $url .= '&' . http_build_query($extra);
        }

        return $url;
    }

    /**
     * Root of the back office, without a trailing slash.
     *
     * @return string
     */
    public static function adminBaseUrl(): string
    {
        // Built from the domain the browser is already on, never from the shop
        // in context. There is one back office for the whole installation, and
        // it answers on every shop domain - but the session that authenticates
        // the employee belongs to one domain. Taking the context shop sent a
        // visitor who came in on one shop over to the default shop's domain,
        // where their session does not exist and their token is refused. It
        // worked on the default shop only because the two domains were the same.
        return 'https://' . rtrim(Tools::getHttpHost(), '/')
            . rtrim(__PS_BASE_URI__, '/') . '/' . self::adminDir();
    }

    /**
     * The CSRF token of the back office session, on PrestaShop 9.
     *
     * Looked up when it is needed rather than injected into the entry
     * controllers. Their services are registered on every PrestaShop, and a
     * constructor asking for this class made Symfony refuse to build its
     * container on PrestaShop 8, where the class does not exist: the whole
     * back office answered 500, not just this module. Named as a string so
     * nothing resolves it before the version that has it is running.
     *
     * @return string empty where there is no such token, which is PrestaShop 8
     */
    public static function symfonyToken(): string
    {
        $id = 'PrestaShopBundle\\Security\\Admin\\UserTokenManager';
        if (!class_exists($id)) {
            return '';
        }

        $container = PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
        if ($container === null || !$container->has($id)) {
            return '';
        }

        $manager = $container->get($id);

        return is_object($manager) && method_exists($manager, 'getSymfonyToken')
            ? (string) $manager->getSymfonyToken()
            : '';
    }

    /**
     * Whether this PrestaShop routes its back office pages through Symfony.
     *
     * Version 9 does, and only there is the entry route needed: on 8 the
     * listener lets a legacy address through, and the legacy controller
     * handles authentication as it always has.
     *
     * @return bool
     */
    public static function usesSymfonyEntry(): bool
    {
        return version_compare(_PS_VERSION_, '9.0.0', '>=');
    }

    /**
     * The administration token of one controller for a given employee.
     *
     * @param string $controller
     * @param int $idEmployee
     *
     * @return string
     */
    public static function adminToken(string $controller, int $idEmployee): string
    {
        return (string) Tools::getAdminToken(
            $controller . (int) Tab::getIdFromClassName($controller) . (int) $idEmployee
        );
    }

    /**
     * Reads the employee identifier from the back office cookie.
     *
     * Used to build a URL, never to decide access. Writing is disabled on the
     * instance before anything else happens: the back office cookie belongs to
     * the back office, and a front controller reading it must not be able to
     * rewrite it - or, on a checksum mismatch, to sign the merchant out.
     *
     * @return int 0 when no back office cookie is readable
     */
    private static function currentEmployeeIdHint(): int
    {
        try {
            $cookie = new Cookie('psAdmin');
            $cookie->disallowWriting();
            $idEmployee = (int) $cookie->id_employee;
        } catch (Throwable $e) {
            return 0;
        }

        return $idEmployee > 0 ? $idEmployee : 0;
    }
}
