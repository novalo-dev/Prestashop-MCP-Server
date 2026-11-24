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
 * SomcpOAuthToken - authorization codes, access tokens and refresh tokens.
 *
 * All three share one idea: the clear value leaves the server once, in the
 * response that carries it, and only its digest is kept. They also share a
 * family identifier, which is what makes replay containment possible - a code
 * presented twice, or a refresh token presented after it was rotated, takes
 * down every credential descended from the same consent.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpOAuthToken
{
    /**
     * Mints an authorization code for a consent that was just granted.
     *
     * @param array<string, mixed> $request the pending authorization request row
     * @param int $idEmployee
     * @param string $scopes what the merchant approved, empty to keep what was asked
     *
     * @return string the clear code, to be put in the redirect
     */
    public static function issueCode(array $request, int $idEmployee, string $scopes = ''): string
    {
        $code = SomcpRandom::token();
        // What the merchant approved, which may be wider than what the client
        // asked for - allowed by OAuth as long as the token response says so,
        // and it does.
        $granted = $scopes !== '' ? $scopes : (string) $request['scopes'];

        Db::getInstance()->insert('somcp_oauth_code', [
            'code_hash' => pSQL(SomcpRandom::hash($code)),
            'id_shop' => (int) $request['id_shop'],
            'id_client' => (int) $request['id_client'],
            'id_employee' => (int) $idEmployee,
            'redirect_uri' => pSQL((string) $request['redirect_uri']),
            'scopes' => pSQL($granted),
            'resource' => pSQL((string) $request['resource']),
            'code_challenge' => pSQL((string) $request['code_challenge']),
            'code_challenge_method' => pSQL((string) $request['code_challenge_method']),
            'id_family' => pSQL(SomcpRandom::familyId()),
            'expires_at' => pSQL(date('Y-m-d H:i:s', time() + SomcpConfig::CODE_TTL)),
        ]);

        return $code;
    }

    /**
     * Looks up a code and marks it used, in that order.
     *
     * A code that was already used is not simply refused: it means either a
     * replay or a stolen code that the thief got to first, and OAuth 2.1 asks
     * the server to revoke everything issued from it.
     *
     * @param string $code
     *
     * @return array{row:array<string,mixed>|null, error:string}
     */
    public static function consumeCode(string $code): array
    {
        // Scoped to the shop being asked, not just to the code: on a multistore
        // installation every shop answers on its own address, and a code issued
        // by one must not be redeemable at another's token endpoint.
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . "somcp_oauth_code`
             WHERE `code_hash` = '" . pSQL(SomcpRandom::hash($code)) . "'
               AND `id_shop` = " . Somcp::currentShopId()
        );

        if (!is_array($row) || $row === []) {
            return ['row' => null, 'error' => 'invalid_grant'];
        }

        if (!empty($row['used_at'])) {
            return self::codeReplayed($row);
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return ['row' => null, 'error' => 'invalid_grant'];
        }

        if ((string) $row['resource'] !== '' && !SomcpOAuthServer::resourceMatches((string) $row['resource'])) {
            // The audience written on the code is not this server. Comparing the
            // client's resource parameter is not enough: that one is whatever
            // the caller chose to send.
            return ['row' => null, 'error' => 'invalid_grant'];
        }

        // Claimed in the statement that checks it: two requests carrying the
        // same code at the same time would otherwise both read it unused, and
        // neither would be seen as the second one.
        $db = Db::getInstance();
        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . "somcp_oauth_code`
             SET `used_at` = '" . pSQL(date('Y-m-d H:i:s')) . "'
             WHERE `id_code` = " . (int) $row['id_code'] . ' AND `used_at` IS NULL'
        );
        if ((int) $db->Affected_Rows() !== 1) {
            return self::codeReplayed($row);
        }

        return ['row' => $row, 'error' => ''];
    }

    /**
     * A code presented a second time: either a replay or a stolen code the
     * thief got to first, and OAuth 2.1 asks to revoke what was issued from it.
     *
     * @param array<string, mixed> $row
     *
     * @return array{row:null, error:string}
     */
    private static function codeReplayed(array $row): array
    {
        self::revokeFamily((string) $row['id_family']);
        SomcpLogger::log(
            'code_replayed',
            SomcpLogger::OUTCOME_FAILURE,
            'authorization code presented twice, grant revoked',
            '',
            (int) $row['id_employee']
        );

        return ['row' => null, 'error' => 'invalid_grant'];
    }

    /**
     * Issues an access token and its refresh token.
     *
     * @param int $idClient
     * @param int $idEmployee
     * @param string $scopes
     * @param string $resource
     * @param string $family
     *
     * @return array<string, mixed> the token response body, with clear values
     */
    public static function issuePair(
        int $idClient,
        int $idEmployee,
        string $scopes,
        string $resource,
        string $family,
    ): array {
        $idShop = Somcp::currentShopId();
        $accessTtl = max(60, SomcpConfig::getInt(SomcpConfig::ACCESS_TTL));
        $refreshTtl = max(600, SomcpConfig::getInt(SomcpConfig::REFRESH_TTL));

        $accessToken = SomcpRandom::token();
        $refreshToken = SomcpRandom::token();

        Db::getInstance()->insert('somcp_oauth_access_token', [
            'token_hash' => pSQL(SomcpRandom::hash($accessToken)),
            'id_shop' => $idShop,
            'id_client' => (int) $idClient,
            'id_employee' => (int) $idEmployee,
            'scopes' => pSQL($scopes),
            'resource' => pSQL($resource),
            'id_family' => pSQL($family),
            'expires_at' => pSQL(date('Y-m-d H:i:s', time() + $accessTtl)),
        ]);

        Db::getInstance()->insert('somcp_oauth_refresh_token', [
            'token_hash' => pSQL(SomcpRandom::hash($refreshToken)),
            'id_shop' => $idShop,
            'id_client' => (int) $idClient,
            'id_employee' => (int) $idEmployee,
            'scopes' => pSQL($scopes),
            'resource' => pSQL($resource),
            'id_family' => pSQL($family),
            'expires_at' => pSQL(date('Y-m-d H:i:s', time() + $refreshTtl)),
        ]);

        self::purgeExpired();

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'refresh_token' => $refreshToken,
            'scope' => $scopes,
        ];
    }

    /**
     * Exchanges a refresh token for a new pair.
     *
     * @param string $refreshToken
     * @param int $idClient
     *
     * @return array{body:array<string,mixed>|null, error:string}
     */
    public static function refresh(string $refreshToken, int $idClient): array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . "somcp_oauth_refresh_token`
             WHERE `token_hash` = '" . pSQL(SomcpRandom::hash($refreshToken)) . "'
               AND `id_shop` = " . Somcp::currentShopId()
        );

        if (!is_array($row) || $row === []) {
            return ['body' => null, 'error' => 'invalid_grant'];
        }

        if ((int) $row['id_client'] !== $idClient) {
            // The token belongs to someone else. Revoking here would let any
            // client take down another's grant by guessing, so this only fails.
            return ['body' => null, 'error' => 'invalid_grant'];
        }

        if (!empty($row['revoked_at']) || strtotime((string) $row['expires_at']) < time()) {
            return ['body' => null, 'error' => 'invalid_grant'];
        }

        if (!empty($row['used_at'])) {
            return self::refreshReused($row);
        }

        $db = Db::getInstance();
        $rotation = SomcpConfig::isOn(SomcpConfig::REFRESH_ROTATION);
        if ($rotation) {
            // Claimed before a new pair exists, in the statement that checks
            // it: two requests carrying the same token at the same time would
            // otherwise both be answered, each with a family of its own to
            // keep, and the second presentation would never be noticed.
            $db->execute(
                'UPDATE `' . _DB_PREFIX_ . "somcp_oauth_refresh_token`
                 SET `used_at` = '" . pSQL(date('Y-m-d H:i:s')) . "'
                 WHERE `id_refresh_token` = " . (int) $row['id_refresh_token'] . '
                   AND `used_at` IS NULL AND `revoked_at` IS NULL'
            );
            if ((int) $db->Affected_Rows() !== 1) {
                return self::refreshReused($row);
            }
        }

        $body = self::issuePair(
            (int) $row['id_client'],
            (int) $row['id_employee'],
            (string) $row['scopes'],
            (string) $row['resource'],
            (string) $row['id_family']
        );

        if ($rotation) {
            $db->update(
                'somcp_oauth_refresh_token',
                ['rotated_to' => pSQL(SomcpRandom::hash((string) $body['refresh_token']))],
                '`id_refresh_token` = ' . (int) $row['id_refresh_token']
            );
        } else {
            // Rotation off: the token is reusable by design, so it is not
            // claimed - a client refreshing twice must keep its grant - and
            // the new one is dropped from the response rather than leaving
            // two live tokens behind.
            self::revokeRefreshHash(SomcpRandom::hash((string) $body['refresh_token']));
            $body['refresh_token'] = $refreshToken;
        }

        return ['body' => $body, 'error' => ''];
    }

    /**
     * A rotated refresh token presented again: whoever presents it is one
     * step behind the legitimate client, which is what a stolen token looks
     * like.
     *
     * @param array<string, mixed> $row
     *
     * @return array{body:null, error:string}
     */
    private static function refreshReused(array $row): array
    {
        self::revokeFamily((string) $row['id_family']);
        SomcpLogger::log(
            'refresh_reused',
            SomcpLogger::OUTCOME_FAILURE,
            'rotated refresh token presented again, grant revoked',
            '',
            (int) $row['id_employee']
        );

        return ['body' => null, 'error' => 'invalid_grant'];
    }

    /**
     * Validates a bearer token presented on the MCP endpoint.
     *
     * @param string $token
     * @param string $expectedResource canonical URI of this server
     *
     * @return array{row:array<string,mixed>|null, error:string}
     */
    public static function validateAccessToken(string $token, string $expectedResource): array
    {
        if ($token === '') {
            return ['row' => null, 'error' => 'missing_token'];
        }

        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . "somcp_oauth_access_token`
             WHERE `token_hash` = '" . pSQL(SomcpRandom::hash($token)) . "'"
        );

        if (!is_array($row) || $row === []) {
            return ['row' => null, 'error' => 'invalid_token'];
        }
        if (!empty($row['revoked_at'])) {
            return ['row' => null, 'error' => 'invalid_token'];
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return ['row' => null, 'error' => 'invalid_token'];
        }
        if ((int) $row['id_shop'] !== Somcp::currentShopId()) {
            return ['row' => null, 'error' => 'invalid_token'];
        }
        if ((string) $row['resource'] !== '' && (string) $row['resource'] !== $expectedResource) {
            // A token minted for another audience must not be accepted here,
            // however valid it looks: that is the whole point of RFC 8707.
            return ['row' => null, 'error' => 'invalid_token'];
        }

        Db::getInstance()->update(
            'somcp_oauth_access_token',
            ['last_used_at' => pSQL(date('Y-m-d H:i:s'))],
            '`id_access_token` = ' . (int) $row['id_access_token']
        );

        return ['row' => $row, 'error' => ''];
    }

    /**
     * Revokes a token presented at the revocation endpoint, whichever kind it is.
     *
     * @param string $token
     * @param int $idClient
     */
    public static function revokeByValue(string $token, int $idClient): void
    {
        $hash = pSQL(SomcpRandom::hash($token));
        $now = pSQL(date('Y-m-d H:i:s'));
        $db = Db::getInstance();

        $idShop = Somcp::currentShopId();

        // A refresh token ends the whole session it belongs to: the access
        // tokens issued with it must stop working too (RFC 7009, 2.1), not
        // live on until they expire. An access token alone ends only itself.
        $family = (string) $db->getValue(
            'SELECT `id_family` FROM `' . _DB_PREFIX_ . "somcp_oauth_refresh_token`
             WHERE `token_hash` = '" . $hash . "'
               AND `id_client` = " . (int) $idClient . '
               AND `id_shop` = ' . $idShop
        );
        if ($family !== '') {
            self::revokeFamily($family);

            return;
        }

        foreach (['somcp_oauth_access_token', 'somcp_oauth_refresh_token'] as $table) {
            $db->execute(
                'UPDATE `' . _DB_PREFIX_ . $table . "`
                 SET `revoked_at` = '" . $now . "'
                 WHERE `token_hash` = '" . $hash . "'
                   AND `id_client` = " . (int) $idClient . '
                   AND `id_shop` = ' . $idShop
            );
        }
    }

    /**
     * Revokes every credential of one grant.
     *
     * @param string $family
     */
    public static function revokeFamily(string $family): void
    {
        $now = pSQL(date('Y-m-d H:i:s'));
        $family = pSQL($family);
        $db = Db::getInstance();

        foreach (['somcp_oauth_access_token', 'somcp_oauth_refresh_token'] as $table) {
            $db->execute(
                'UPDATE `' . _DB_PREFIX_ . $table . "`
                 SET `revoked_at` = '" . $now . "'
                 WHERE `id_family` = '" . $family . "' AND `revoked_at` IS NULL"
            );
        }
    }

    /**
     * Revokes everything an employee granted to a client on one shop, used
     * when a consent is withdrawn from the back office.
     *
     * The same employee may have granted the same assistant on another shop.
     * That grant is a separate decision and stays untouched.
     *
     * @param int $idClient
     * @param int $idEmployee
     * @param int $idShop
     */
    public static function revokeGrant(int $idClient, int $idEmployee, int $idShop): void
    {
        $now = pSQL(date('Y-m-d H:i:s'));
        $db = Db::getInstance();

        foreach (['somcp_oauth_access_token', 'somcp_oauth_refresh_token'] as $table) {
            $db->execute(
                'UPDATE `' . _DB_PREFIX_ . $table . "`
                 SET `revoked_at` = '" . $now . "'
                 WHERE `id_client` = " . (int) $idClient . '
                   AND `id_employee` = ' . (int) $idEmployee . '
                   AND `id_shop` = ' . (int) $idShop . '
                   AND `revoked_at` IS NULL'
            );
        }
    }

    /**
     * @param string $hash
     */
    private static function revokeRefreshHash(string $hash): void
    {
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . "somcp_oauth_refresh_token`
             SET `revoked_at` = '" . pSQL(date('Y-m-d H:i:s')) . "'
             WHERE `token_hash` = '" . pSQL($hash) . "'"
        );
    }

    /**
     * Drops what has expired. Called when tokens are issued rather than on a
     * cron, so a shop that never opens the settings page still stays clean.
     */
    public static function purgeExpired(): void
    {
        $db = Db::getInstance();
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'somcp_oauth_code` WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'somcp_oauth_request` WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'somcp_oauth_access_token` WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 7 DAY)');
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'somcp_oauth_refresh_token` WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 7 DAY)');
    }

    /**
     * Live access tokens of the current shop, for the settings page.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activeSessions(): array
    {
        return Db::getInstance()->executeS(
            'SELECT t.`id_employee`, t.`scopes`, t.`expires_at`, t.`last_used_at`,
                    c.`client_id`, c.`client_name`, c.`id_client`
             FROM `' . _DB_PREFIX_ . 'somcp_oauth_access_token` t
             INNER JOIN `' . _DB_PREFIX_ . 'somcp_oauth_client` c ON c.`id_client` = t.`id_client`
             WHERE t.`revoked_at` IS NULL
               AND t.`expires_at` > NOW()
               AND t.`id_shop` ' . SomcpLogger::contextShopCondition() . '
             ORDER BY t.`id_access_token` DESC'
        ) ?: [];
    }
}
