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
 * SomcpRelay - the O'So AI Relay programs linked to the shop, and the requests
 * they carry to an AI running on the merchant's own computer.
 *
 * The shop never calls the relay: a shop hosted online cannot reach a computer
 * behind a home router, and opening that router is not something to ask of a
 * merchant. So the relay calls the shop, holds a request open until there is
 * work, and posts the answer back. The chat, on its side, writes the request
 * to a table and waits for the answer there. PROTOCOL.md in the relay's
 * repository describes the four calls.
 *
 * A relay is linked with a code shown in the back office, single use and
 * short-lived, and then holds a token of which the shop keeps only the hash.
 * Removing the local assistant removes the relay, which revokes the token.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpRelay
{
    /** @var string Where the merchant downloads the relay. */
    public const DOWNLOAD_URL = 'https://github.com/novalo-dev/Somcp-Relay/releases/latest';

    /** @var int Seconds a pairing code stays valid. */
    public const CODE_SECONDS = 600;

    /**
     * @var int Seconds the shop holds a "next" call while it has nothing to
     *          send. Below the relay's own client timeout, so a quiet wait ends
     *          on the shop's side, and below what most hosts let PHP run.
     */
    public const WAIT_SECONDS = 20;

    /**
     * @var int a relay not heard from for this long is shown offline: it asks
     *          again as soon as a wait ends, so two missed waits mean it stopped
     */
    private const ONLINE_SECONDS = 60;

    /** @var int Seconds after which a job nobody finished is deleted. */
    private const JOB_SECONDS = 3600;

    /** @var int Largest body the relay may send: an answer of a local model, with room to spare. */
    public const MAX_BODY = 8388608;

    /** @var int Models kept from what a relay reports. */
    private const MAX_MODELS = 100;

    /** @var string Letters of a pairing code: no 0/O or 1/I, which read alike. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** @var int Microseconds between two looks at the table while the chat waits for an answer. */
    private const POLL_MICROSECONDS = 300000;

    /**
     * @var int Seconds between two looks at the table while a relay waits for
     *          work. A request queued in between waits at most this long, and
     *          the shop runs a quarter of the queries it ran at half a second.
     */
    public const NEXT_POLL_SECONDS = 2;

    /**
     * @var string until when the relays keep a request open, a timestamp. Set
     *             whenever the chat is used; past it, nobody is chatting and
     *             a relay that knows how is told to come back later instead
     *             of holding one of the shop's PHP processes for nothing.
     */
    public const AWAKE = 'SOMCP_RELAY_AWAKE';

    /** @var int Seconds the relays stay quick after the chat was last used. */
    private const AWAKE_SECONDS = 600;

    /**
     * @var int Seconds an idle relay waits before asking again. Below the
     *          shortest wait of the chat for a provider, so a message sent the
     *          moment the chat opens is still answered.
     */
    public const IDLE_WAIT_SECONDS = 15;

    /**
     * Opens a pairing at the level the back office edits, and gives back the
     * relay that will receive the token.
     *
     * Relays of the level that no assistant uses are removed first: a code
     * that was never typed, or a relay linked and then abandoned before its
     * assistant was saved.
     *
     * @return int
     */
    public static function createPairing(): int
    {
        [$idShop, $idGroup] = SomcpAssistant::editedLevel();
        $db = Db::getInstance();
        $rows = $db->executeS(
            'SELECT `id_relay` FROM `' . _DB_PREFIX_ . 'somcp_relay`'
            . ' WHERE `id_shop` = ' . (int) $idShop . ' AND `id_shop_group` = ' . (int) $idGroup,
            true,
            false
        );
        foreach (is_array($rows) ? $rows : [] as $row) {
            self::dropUnused((int) $row['id_relay']);
        }

        $code = '';
        for ($i = 0; $i < 8; ++$i) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $db->insert('somcp_relay', [
            'id_shop' => (int) $idShop,
            'id_shop_group' => (int) $idGroup,
            'code_hash' => pSQL(SomcpRandom::hash($code)),
            'code' => pSQL(SomcpAssistant::seal($code)),
            'code_expires' => date('Y-m-d H:i:s', time() + self::CODE_SECONDS),
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->Insert_ID();
    }

    /**
     * A relay of the level the back office edits, for the settings page.
     *
     * @param int $idRelay
     *
     * @return array<string, mixed>|null
     */
    public static function edited(int $idRelay): ?array
    {
        [$idShop, $idGroup] = SomcpAssistant::editedLevel();
        $relay = self::get($idRelay);

        return $relay !== null && $relay['id_shop'] === $idShop && $relay['id_shop_group'] === $idGroup ? $relay : null;
    }

    /**
     * A relay, whatever its level.
     *
     * @param int $idRelay
     *
     * @return array<string, mixed>|null
     */
    public static function get(int $idRelay): ?array
    {
        if ($idRelay <= 0) {
            return null;
        }
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'somcp_relay` WHERE `id_relay` = ' . (int) $idRelay,
            false
        );

        return is_array($row) ? self::shape($row) : null;
    }

    /**
     * Trades a pairing code for a token. The code is spent whatever happens
     * next, so a code read over someone's shoulder is worth nothing once used.
     *
     * @param string $code as typed, dash and case free
     * @param string $version the relay's version
     * @param string $os the relay's system
     *
     * @return string the token, '' when the code is wrong or expired
     */
    public static function pair(string $code, string $version, string $os): string
    {
        $code = self::normalize($code);
        if (strlen($code) !== 8) {
            return '';
        }
        $db = Db::getInstance();
        $id = (int) $db->getValue(
            'SELECT `id_relay` FROM `' . _DB_PREFIX_ . 'somcp_relay`'
            . " WHERE `code_hash` = '" . pSQL(SomcpRandom::hash($code)) . "'"
            . " AND `token_hash` = '' AND `code_expires` > '" . pSQL(date('Y-m-d H:i:s')) . "'",
            false
        );
        if ($id <= 0) {
            return '';
        }

        $token = SomcpRandom::token();
        // Conditional on the code still being there: two relays sending the
        // same code at once, only one gets a token.
        $db->update('somcp_relay', [
            'token_hash' => pSQL(SomcpRandom::hash($token)),
            'code_hash' => '',
            'code' => '',
            'relay_version' => pSQL(Tools::substr($version, 0, 32)),
            'relay_os' => pSQL(Tools::substr($os, 0, 64)),
            'last_seen' => date('Y-m-d H:i:s'),
        ], '`id_relay` = ' . $id . " AND `token_hash` = ''");

        return $db->Affected_Rows() === 1 ? $token : '';
    }

    /**
     * The relay a token belongs to, or null when it was revoked.
     *
     * @param string $token
     *
     * @return array<string, mixed>|null
     */
    public static function authenticate(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'somcp_relay`'
            . " WHERE `token_hash` = '" . pSQL(SomcpRandom::hash($token)) . "'",
            false
        );

        return is_array($row) ? self::shape($row) : null;
    }

    /**
     * Records which AI the relay reaches and which models passed its tool
     * test. Only those are offered in the back office: a model that cannot
     * call tools cannot act on the shop.
     *
     * @param int $idRelay
     * @param array<string, mixed> $ai {software, models: [{id, tools}]}
     */
    public static function hello(int $idRelay, array $ai): void
    {
        $models = [];
        foreach (array_slice((array) ($ai['models'] ?? []), 0, self::MAX_MODELS) as $model) {
            if (!is_array($model) || !is_string($model['id'] ?? null) || trim($model['id']) === '') {
                continue;
            }
            $models[] = [
                'id' => (string) Tools::substr(trim($model['id']), 0, 128),
                // Absent for a model the relay did not test: not offered.
                'tools' => ($model['tools'] ?? null) === true,
            ];
        }

        Db::getInstance()->update('somcp_relay', [
            'software' => pSQL(Tools::substr(trim((string) ($ai['software'] ?? '')), 0, 64)),
            'models' => pSQL((string) json_encode($models), true),
            'last_seen' => date('Y-m-d H:i:s'),
        ], '`id_relay` = ' . (int) $idRelay);
    }

    /**
     * Notes that somebody uses the chat, so the relays answer quickly for the
     * next minutes. Written at most once a minute: the chat calls it on every
     * request.
     */
    public static function wake(): void
    {
        $until = (int) Configuration::getGlobalValue(self::AWAKE);
        if ($until - time() < self::AWAKE_SECONDS - 60) {
            Configuration::updateGlobalValue(self::AWAKE, (string) (time() + self::AWAKE_SECONDS));
        }
    }

    /**
     * Whether the chat was used in the last minutes.
     *
     * @return bool
     */
    public static function awake(): bool
    {
        return (int) Configuration::getGlobalValue(self::AWAKE) > time();
    }

    /**
     * Removes what the relays stored outside their tables, on uninstall.
     */
    public static function purge(): void
    {
        Configuration::deleteByName(self::AWAKE);
    }

    /**
     * Notes that the relay asked, which is what the online status reads.
     *
     * @param int $idRelay
     */
    public static function touch(int $idRelay): void
    {
        Db::getInstance()->update('somcp_relay', ['last_seen' => date('Y-m-d H:i:s')], '`id_relay` = ' . (int) $idRelay);
    }

    /**
     * Takes the oldest request waiting for this relay, so that no other call
     * of the same relay takes it too.
     *
     * @param int $idRelay
     *
     * @return array{id:string, request:array<string, mixed>}|null
     */
    public static function claim(int $idRelay): ?array
    {
        $db = Db::getInstance();
        $row = $db->getRow(
            'SELECT `id_job`, `job_key`, `request` FROM `' . _DB_PREFIX_ . 'somcp_relay_job`'
            . ' WHERE `id_relay` = ' . (int) $idRelay . " AND `status` = 'pending' ORDER BY `id_job` ASC",
            false
        );
        if (!is_array($row)) {
            return null;
        }
        $db->update('somcp_relay_job', ['status' => 'taken', 'date_upd' => date('Y-m-d H:i:s')], '`id_job` = ' . (int) $row['id_job'] . " AND `status` = 'pending'");
        if ($db->Affected_Rows() !== 1) {
            return null;
        }
        $request = json_decode((string) $row['request'], true);

        return ['id' => (string) $row['job_key'], 'request' => is_array($request) ? $request : []];
    }

    /**
     * Stores the answer of a job the relay took.
     *
     * @param int $idRelay
     * @param string $key
     * @param int $status the AI's HTTP status, or the relay's when there is no answer
     * @param string|null $response the AI's body, JSON, null when there is none
     * @param string $error why there is no answer
     *
     * @return bool false when the job is unknown, not this relay's, or no longer waited for
     */
    public static function complete(int $idRelay, string $key, int $status, ?string $response, string $error): bool
    {
        $failed = $response === null || $status < 200 || $status >= 300;
        $db = Db::getInstance();
        $db->update('somcp_relay_job', [
            'status' => $failed ? 'failed' : 'done',
            'http_status' => max(0, min(999, $status)),
            'response' => pSQL((string) $response, true),
            'error' => pSQL(Tools::substr($error, 0, 255)),
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_relay` = ' . (int) $idRelay . " AND `job_key` = '" . pSQL($key) . "' AND `status` = 'taken'");

        return $db->Affected_Rows() === 1;
    }

    /**
     * Queues a chat request for a relay.
     *
     * @param int $idRelay
     * @param array<string, mixed> $request OpenAI-compatible chat body
     *
     * @return string the job's key, for wait()
     */
    public static function enqueue(int $idRelay, array $request): string
    {
        // A request is the chat in use, if nothing woke the relays before.
        self::wake();
        $db = Db::getInstance();
        // Jobs a chat stopped waiting for, as when PHP ended the request
        // before the chat could cancel: nothing will read them any more.
        $db->delete('somcp_relay_job', "`date_add` < '" . pSQL(date('Y-m-d H:i:s', time() - self::JOB_SECONDS)) . "'");

        $key = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        $db->insert('somcp_relay_job', [
            'id_relay' => (int) $idRelay,
            'job_key' => pSQL($key),
            'status' => 'pending',
            'request' => pSQL((string) json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true),
            'date_add' => $now,
            'date_upd' => $now,
        ]);

        return $key;
    }

    /**
     * Waits for the answer to a job.
     *
     * @param string $key
     * @param float $seconds
     *
     * @return array{status:int, body:string, error:string}|null null when no answer came in time
     */
    public static function wait(string $key, float $seconds): ?array
    {
        $until = microtime(true) + $seconds;
        do {
            $row = Db::getInstance()->getRow(
                'SELECT `status`, `http_status`, `response`, `error` FROM `' . _DB_PREFIX_ . 'somcp_relay_job`'
                . " WHERE `job_key` = '" . pSQL($key) . "'",
                false
            );
            if (!is_array($row)) {
                return null;
            }
            if ($row['status'] === 'done' || $row['status'] === 'failed') {
                Db::getInstance()->delete('somcp_relay_job', "`job_key` = '" . pSQL($key) . "'");

                return [
                    'status' => (int) $row['http_status'],
                    'body' => (string) $row['response'],
                    'error' => (string) $row['error'],
                ];
            }
            usleep(self::POLL_MICROSECONDS);
        } while (microtime(true) < $until);

        return null;
    }

    /**
     * Forgets a job no longer waited for: a relay that takes it afterwards
     * finds nothing to answer.
     *
     * @param string $key
     */
    public static function cancel(string $key): void
    {
        Db::getInstance()->delete('somcp_relay_job', "`job_key` = '" . pSQL($key) . "'");
    }

    /**
     * Whether the relay is running: it asked recently, or it is busy with a
     * request, during which it does not ask.
     *
     * @param array<string, mixed> $relay
     *
     * @return bool
     */
    public static function online(array $relay): bool
    {
        if ($relay['last_seen'] !== null && time() - (int) strtotime((string) $relay['last_seen']) <= self::ONLINE_SECONDS) {
            return true;
        }

        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'somcp_relay_job`'
            . ' WHERE `id_relay` = ' . (int) $relay['id_relay'] . " AND `status` = 'taken'"
            . " AND `date_upd` > '" . pSQL(date('Y-m-d H:i:s', time() - self::JOB_SECONDS)) . "'",
            false
        ) > 0;
    }

    /**
     * Where the pairing of a relay stands: its code waits to be typed, the
     * code expired, the relay is linked and its models are awaited, or
     * models able to call tools are there to choose from.
     *
     * @param array<string, mixed> $relay
     *
     * @return string code, expired, wait or ready
     */
    public static function stage(array $relay): string
    {
        if (!$relay['paired']) {
            return $relay['code'] !== '' ? 'code' : 'expired';
        }

        return self::toolModels($relay) !== [] ? 'ready' : 'wait';
    }

    /**
     * The models of a relay that passed its tool test, the only ones a local
     * assistant may run.
     *
     * @param array<string, mixed> $relay
     *
     * @return array<int, string>
     */
    public static function toolModels(array $relay): array
    {
        $ids = [];
        foreach ($relay['models'] as $model) {
            if ($model['tools']) {
                $ids[] = $model['id'];
            }
        }

        return $ids;
    }

    /**
     * Removes a relay and its jobs when no assistant uses it any more, at any
     * level: an assistant copied to a shop keeps the relay of the one it was
     * copied from. Its token stops working at once.
     *
     * @param int $idRelay
     */
    public static function dropUnused(int $idRelay): void
    {
        if ($idRelay <= 0) {
            return;
        }
        $db = Db::getInstance();
        $used = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'somcp_assistant` WHERE `id_relay` = ' . (int) $idRelay,
            false
        );
        if ($used > 0) {
            return;
        }
        $db->delete('somcp_relay_job', '`id_relay` = ' . (int) $idRelay);
        $db->delete('somcp_relay', '`id_relay` = ' . (int) $idRelay);
    }

    /**
     * The code shown to the merchant, in two groups of four.
     *
     * @param string $code
     *
     * @return string
     */
    public static function display(string $code): string
    {
        return strlen($code) === 8 ? substr($code, 0, 4) . '-' . substr($code, 4) : $code;
    }

    /**
     * A code as typed - lower case, with or without its dash or spaces - back
     * to the eight letters it was made of.
     *
     * @param string $code
     *
     * @return string
     */
    private static function normalize(string $code): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $models = json_decode((string) ($row['models'] ?? ''), true);
        $expires = $row['code_expires'] !== null ? (int) strtotime((string) $row['code_expires']) : 0;
        $paired = (string) $row['token_hash'] !== '';
        // The code is shown only while it can still be used.
        $code = !$paired && $expires > time() ? SomcpAssistant::open((string) $row['code']) : '';
        $list = [];
        foreach (is_array($models) ? $models : [] as $model) {
            if (is_array($model) && isset($model['id'])) {
                $list[] = ['id' => (string) $model['id'], 'tools' => !empty($model['tools'])];
            }
        }

        return [
            'id_relay' => (int) $row['id_relay'],
            'id_shop' => (int) $row['id_shop'],
            'id_shop_group' => (int) $row['id_shop_group'],
            'paired' => $paired,
            'code' => $code !== '' ? self::display($code) : '',
            'code_expires' => $expires,
            'version' => (string) $row['relay_version'],
            'os' => (string) $row['relay_os'],
            'software' => (string) $row['software'],
            'models' => $list,
            'last_seen' => $row['last_seen'] !== null ? (string) $row['last_seen'] : null,
        ];
    }
}
