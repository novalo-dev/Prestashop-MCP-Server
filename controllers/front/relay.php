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
 * Where O'So AI Relay calls the shop: to pair with a code from the back office,
 * to say which local AI answers, to wait for chat requests and to send their
 * answers back. PROTOCOL.md in the relay's repository is the contract.
 *
 * Public by necessity, like the MCP endpoint: everything but pairing needs the
 * relay's token, and pairing needs a code an administrator just generated.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpRelayModuleFrontController extends ModuleFrontController
{
    /** @var Somcp the module instance, narrowed from the generic Module the parent declares */
    public $module;

    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ssl = true;

    /** @var bool */
    public $display_header = false;

    /** @var bool */
    public $display_footer = false;

    /**
     * Served even while the shop is closed: a merchant preparing a shop in
     * maintenance is the one chatting with the assistant.
     */
    protected function displayMaintenancePage()
    {
    }

    /**
     * Answers the relay and stops the script.
     */
    public function initContent()
    {
        Somcp::bootClasses();
        $action = (string) Tools::getValue('action');
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($action === 'pair' && $method === 'POST') {
            $this->pair($this->body());
        }

        $relay = SomcpRelay::authenticate(SomcpHttp::bearerToken());
        if ($relay === null) {
            // The relay reads a 401 as "unlinked" and asks to be paired again.
            SomcpHttp::json(['error' => 'unknown or revoked token'], 401);
        }
        $idRelay = (int) $relay['id_relay'];

        if ($action === 'hello' && $method === 'POST') {
            $body = $this->body();
            SomcpRelay::hello($idRelay, is_array($body['ai'] ?? null) ? $body['ai'] : []);
            SomcpHttp::json(['shop' => ['name' => (string) Configuration::get('PS_SHOP_NAME')]]);
        }
        if ($action === 'next' && $method === 'GET') {
            $this->next($idRelay);
        }
        if ($action === 'result' && $method === 'POST') {
            $body = $this->body();
            $response = array_key_exists('response', $body) && !array_key_exists('error', $body)
                ? (string) json_encode($body['response'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null;
            SomcpRelay::complete($idRelay, (string) ($body['id'] ?? ''), (int) ($body['status'] ?? 0), $response, (string) ($body['error'] ?? ''));
            SomcpRelay::touch($idRelay);
            // 204 whether or not the chat still waited: there is nothing the
            // relay could do differently about a chat that gave up.
            SomcpHttp::noContent(204);
        }

        SomcpHttp::json(['error' => 'unknown action'], 400);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function pair(array $body): void
    {
        $relay = is_array($body['relay'] ?? null) ? $body['relay'] : [];
        $token = SomcpRelay::pair((string) ($body['code'] ?? ''), (string) ($relay['version'] ?? ''), (string) ($relay['os'] ?? ''));
        if ($token === '') {
            SomcpHttp::json(['error' => 'this code is wrong, already used or expired: generate a new one in the back office'], 400);
        }
        SomcpHttp::json([
            'token' => $token,
            'shop' => ['name' => (string) Configuration::get('PS_SHOP_NAME'), 'version' => _PS_VERSION_],
        ]);
    }

    /**
     * Holds the call until a request is waiting or WAIT_SECONDS have passed.
     *
     * @param int $idRelay
     */
    private function next(int $idRelay): void
    {
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0 && $limit < SomcpRelay::WAIT_SECONDS + 10 && function_exists('set_time_limit')) {
            @set_time_limit(SomcpRelay::WAIT_SECONDS + 10);
        }
        // A host that refuses more time still gets an answer before PHP ends
        // the script, rather than a cut connection the relay retries slowly.
        $limit = (int) ini_get('max_execution_time');
        $wait = $limit > 0 ? min(SomcpRelay::WAIT_SECONDS, max(1, $limit - 5)) : SomcpRelay::WAIT_SECONDS;
        $until = microtime(true) + $wait;
        SomcpRelay::touch($idRelay);
        do {
            $job = SomcpRelay::claim($idRelay);
            if ($job !== null) {
                SomcpHttp::json(['job' => $job]);
            }
            usleep(500000);
        } while (microtime(true) < $until);

        SomcpRelay::touch($idRelay);
        SomcpHttp::noContent(204);
    }

    /**
     * The JSON body of the call, an empty array when there is none or it is
     * not JSON.
     *
     * @return array<string, mixed>
     */
    private function body(): array
    {
        $raw = (string) file_get_contents('php://input', false, null, 0, SomcpRelay::MAX_BODY + 1);
        if (strlen($raw) > SomcpRelay::MAX_BODY) {
            SomcpHttp::json(['error' => 'body too large'], 413);
        }
        $body = json_decode($raw, true);

        return is_array($body) ? $body : [];
    }
}
