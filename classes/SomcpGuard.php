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
 * SomcpGuard - the limits on the public addresses, and the anti-robot check of
 * the authorization page.
 *
 * Both rest on maintained libraries rather than code of our own: the counters
 * on nikolaposa/rate-limit (with the database storage of
 * Novalo\Somcp\Security\DbRateLimiter), the check on ALTCHA, a proof of work
 * the visitor's browser solves in a second - no image to read, no service of a
 * third party, no key for the merchant to create, nothing sent outside the
 * shop. The libraries are named only inside the methods, so this file loads on
 * every page without pulling them in.
 *
 * Counted by the visitor's address, read so a forwarded header cannot invent
 * a new one (see address()), and for sign-ins by the account tried as well.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpGuard
{
    /** @var int Failed sign-ins allowed from one address before it is blocked. */
    public const SIGNIN_ATTEMPTS = 5;

    /** @var int How long an address stays blocked after too many failed sign-ins, in seconds. */
    public const SIGNIN_BLOCK = 900;

    /**
     * @var int Failed sign-ins allowed on one account, whatever the addresses
     *          they come from, before it is blocked on this page.
     *
     * Higher than the limit per address: several employees behind one office
     * address share that one, while an account is tried by its owner alone.
     */
    public const ACCOUNT_ATTEMPTS = 10;

    /**
     * @var array<int, string> Cloudflare's published ranges (cloudflare.com/ips),
     *                         whose requests carry the visitor in CF-Connecting-IP.
     */
    private const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** @var int Most the anti-robot work is multiplied by after failed sign-ins. */
    private const CAPTCHA_MAX_FACTOR = 10;

    /** @var array<string, array{0:int, 1:int}> what is counted => [hits, seconds] */
    private const LIMITS = [
        // A new connection request parks a row in the database: enough for a
        // merchant who tries a few assistants, not for a script filling the table.
        'authorize' => [30, 900],
        // An application registers once; a script registering in a loop does not.
        'register' => [10, 3600],
    ];

    /** @var int Seconds a diagnostic visit of the authorization page stays valid. */
    private const PROBE_TTL = 60;

    /** @var int PBKDF2 iterations per attempt of the anti-robot challenge. */
    private const CAPTCHA_COST = 1000;

    /**
     * Counts a request to one of the limited addresses.
     *
     * @param string $what a key of LIMITS
     *
     * @return bool false once the address has used up its quota
     */
    public static function allow(string $what): bool
    {
        [$hits, $seconds] = self::LIMITS[$what];

        return !self::limiter($what, $hits, $seconds)->limitSilently(self::address())->limitExceeded();
    }

    /**
     * Whether this address has failed to sign in too often lately.
     *
     * Checked before the password: once blocked, even the right password is
     * refused until the block ends, or guessing would simply go on.
     *
     * @return bool
     */
    public static function signinBlocked(): bool
    {
        return self::signinFailures() >= self::SIGNIN_ATTEMPTS;
    }

    /**
     * Whether this account has failed to sign in too often lately, from any
     * address: changing proxy for each guess gains nothing.
     *
     * Counted by the e-mail typed, whether or not an account has it, so the
     * block says nothing about which accounts exist.
     *
     * @param string $email
     *
     * @return bool
     */
    public static function accountBlocked(string $email): bool
    {
        $status = self::limiter('signin_account', self::ACCOUNT_ATTEMPTS, self::SIGNIN_BLOCK)->status(self::accountKey($email));

        return $status->getRemainingAttempts() <= 0;
    }

    /**
     * Counts a failed sign-in, against the address and, when one was typed,
     * against the account.
     *
     * @param string $email
     */
    public static function signinFailed(string $email = ''): void
    {
        self::limiter('signin', self::SIGNIN_ATTEMPTS, self::SIGNIN_BLOCK)->limitSilently(self::address());
        if ($email !== '') {
            self::limiter('signin_account', self::ACCOUNT_ATTEMPTS, self::SIGNIN_BLOCK)->limitSilently(self::accountKey($email));
        }
    }

    /**
     * @return int failed sign-ins from this address in the current window
     */
    private static function signinFailures(): int
    {
        $status = self::limiter('signin', self::SIGNIN_ATTEMPTS, self::SIGNIN_BLOCK)->status(self::address());

        return self::SIGNIN_ATTEMPTS - max(0, $status->getRemainingAttempts());
    }

    /**
     * @param string $email
     *
     * @return string
     */
    private static function accountKey(string $email): string
    {
        return Tools::strtolower(trim($email));
    }

    /**
     * A new anti-robot challenge, as the widget reads it.
     *
     * Each failed sign-in from the address makes the next one longer to
     * solve - about a second at first, ten at most - so guessing slows down
     * while someone who mistyped once barely notices.
     *
     * @return string JSON
     */
    public static function challenge(): string
    {
        self::dependencies();

        $factor = min(self::CAPTCHA_MAX_FACTOR, 1 + 2 * self::signinFailures());
        $challenge = self::altcha()->createChallenge(new AltchaOrg\Altcha\CreateChallengeOptions(
            algorithm: new AltchaOrg\Altcha\Algorithm\Pbkdf2(),
            cost: self::CAPTCHA_COST,
            counter: random_int(1000, 3000) * $factor,
            expiresAt: time() + SomcpConfig::REQUEST_TTL,
        ));

        return (string) json_encode($challenge->toArray(), JSON_UNESCAPED_SLASHES);
    }

    /**
     * A short-lived pass the diagnostic opens the authorization page with.
     *
     * Without a request that page is the shop's "page not found", like every
     * other address of the module; the pass is what lets the diagnostic still
     * see the module's own page and tell it apart from one another module
     * serves in its place. Only the shop can write it.
     *
     * @return string
     */
    public static function probe(): string
    {
        $expires = (string) (time() + self::PROBE_TTL);

        return $expires . '.' . hash_hmac('sha256', 'probe|' . $expires, self::probeKey());
    }

    /**
     * @param string $pass what the visit carried
     *
     * @return bool
     */
    public static function isProbe(string $pass): bool
    {
        $parts = explode('.', $pass, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || (int) $parts[0] < time()) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', 'probe|' . $parts[0], self::probeKey()), $parts[1]);
    }

    /**
     * Whether the visitor solved a challenge of this shop, and solved it now.
     *
     * Each challenge is accepted once: a solution that passed is remembered
     * until it expires, so it cannot be posted again with the next guess.
     *
     * @param string $payload what the widget posted
     *
     * @return bool
     */
    public static function solved(string $payload): bool
    {
        if ($payload === '' || Tools::strlen($payload) > 8192) {
            return false;
        }

        self::dependencies();

        try {
            $parsed = AltchaOrg\Altcha\Payload::fromBase64($payload);
            $result = self::altcha()->verifySolution(new AltchaOrg\Altcha\VerifySolutionOptions(
                algorithm: new AltchaOrg\Altcha\Algorithm\Pbkdf2(),
                payload: $parsed,
            ));
        } catch (Throwable $e) {
            return false;
        }
        if (!$result->verified) {
            return false;
        }

        $used = self::limiter('captcha', 1, SomcpConfig::REQUEST_TTL);
        $signature = (string) $parsed->challenge->signature;
        if ($used->status($signature)->getRemainingAttempts() <= 0) {
            return false;
        }
        $used->limitSilently($signature);

        return true;
    }

    /**
     * The limits and the anti-robot check live in vendor/ and src/.
     */
    private static function dependencies(): void
    {
        Somcp::dependencies();
    }

    /**
     * Its own key, like the other secrets the module seeds, minted on first use.
     *
     * @return string
     */
    private static function probeKey(): string
    {
        $key = (string) Configuration::get(SomcpConfig::PROBE_KEY);
        if ($key === '') {
            $key = SomcpRandom::token(32);
            Configuration::updateValue(SomcpConfig::PROBE_KEY, $key, false, 0, 0);
        }

        return $key;
    }

    /**
     * @return AltchaOrg\Altcha\Altcha
     */
    private static function altcha(): AltchaOrg\Altcha\Altcha
    {
        self::dependencies();
        // Its own key rather than the shop's, written at the all-shops level
        // like the other secrets the module seeds: a challenge signed on one
        // shop of a multistore is solved on the page of that same shop anyway.
        $key = (string) Configuration::get(SomcpConfig::CAPTCHA_KEY);
        if ($key === '') {
            $key = SomcpRandom::token(32);
            Configuration::updateValue(SomcpConfig::CAPTCHA_KEY, $key, false, 0, 0);
        }

        return new AltchaOrg\Altcha\Altcha($key);
    }

    /**
     * @param string $what
     * @param int $hits
     * @param int $seconds
     *
     * @return Novalo\Somcp\Security\DbRateLimiter
     */
    private static function limiter(string $what, int $hits, int $seconds): Novalo\Somcp\Security\DbRateLimiter
    {
        self::dependencies();

        return new Novalo\Somcp\Security\DbRateLimiter(RateLimit\Rate::custom($hits, $seconds), $what);
    }

    /**
     * The visitor's address, as the counters see it.
     *
     * Not Tools::getRemoteAddr(): behind a proxy it returns the first address
     * of X-Forwarded-For, which is whatever the visitor wrote there, so each
     * guess could claim a new address. The chain is read from the right
     * instead, where each proxy of the shop appended the address it saw, and
     * the first one that is not such a proxy is the visitor. Cloudflare names
     * the visitor in its own header, trusted only on a request that comes
     * from Cloudflare.
     *
     * An IPv6 address counts as its /64: one connection holds billions of
     * them, and a counter per address would never fill.
     *
     * @return string
     */
    private static function address(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $address = self::isProxy($remote) ? self::behindProxy($remote) : $remote;

        return self::group($address);
    }

    /**
     * @param string $remote the proxy that called the shop
     *
     * @return string
     */
    private static function behindProxy(string $remote): string
    {
        if (Symfony\Component\HttpFoundation\IpUtils::checkIp($remote, self::CLOUDFLARE)) {
            $visitor = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
            if (filter_var($visitor, FILTER_VALIDATE_IP) !== false) {
                return $visitor;
            }
        }

        $chain = array_reverse(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
        foreach ($chain as $hop) {
            $hop = trim($hop);
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                // What lies further left was written by someone else.
                break;
            }
            if (!self::isProxy($hop)) {
                return $hop;
            }
        }

        return $remote;
    }

    /**
     * Whether an address is one a proxy of the shop would have: on the
     * server's own network, or Cloudflare's.
     *
     * @param string $address
     *
     * @return bool
     */
    private static function isProxy(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || Symfony\Component\HttpFoundation\IpUtils::checkIp($address, self::CLOUDFLARE);
    }

    /**
     * @param string $address
     *
     * @return string
     */
    private static function group(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $address;
        }
        $packed = (string) inet_pton($address);

        return (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
