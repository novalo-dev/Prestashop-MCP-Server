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
 * Counted by the visitor's address, as PrestaShop reads it.
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

    /** @var array<string, array{0:int, 1:int}> what is counted => [hits, seconds] */
    private const LIMITS = [
        // A new connection request parks a row in the database: enough for a
        // merchant who tries a few assistants, not for a script filling the table.
        'authorize' => [30, 900],
        // An application registers once; a script registering in a loop does not.
        'register' => [10, 3600],
    ];

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
        $status = self::limiter('signin', self::SIGNIN_ATTEMPTS, self::SIGNIN_BLOCK)->status(self::address());

        return $status->getRemainingAttempts() <= 0;
    }

    /**
     * Counts a failed sign-in.
     */
    public static function signinFailed(): void
    {
        self::limiter('signin', self::SIGNIN_ATTEMPTS, self::SIGNIN_BLOCK)->limitSilently(self::address());
    }

    /**
     * A new anti-robot challenge, as the widget reads it.
     *
     * @return string JSON
     */
    public static function challenge(): string
    {
        $challenge = self::altcha()->createChallenge(new AltchaOrg\Altcha\CreateChallengeOptions(
            algorithm: new AltchaOrg\Altcha\Algorithm\Pbkdf2(),
            cost: self::CAPTCHA_COST,
            counter: random_int(1000, 3000),
            expiresAt: time() + SomcpConfig::REQUEST_TTL,
        ));

        return (string) json_encode($challenge->toArray(), JSON_UNESCAPED_SLASHES);
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
     * @return AltchaOrg\Altcha\Altcha
     */
    private static function altcha(): AltchaOrg\Altcha\Altcha
    {
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
        return new Novalo\Somcp\Security\DbRateLimiter(RateLimit\Rate::custom($hits, $seconds), $what);
    }

    /**
     * @return string
     */
    private static function address(): string
    {
        return (string) Tools::getRemoteAddr();
    }
}
