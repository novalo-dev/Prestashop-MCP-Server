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

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/*
 * The HTTP client of a local assistant: instead of sending the request to an
 * address, it hands it to the somcp-relay linked to the shop and waits for
 * the answer the relay brings back.
 *
 * Neuron builds an ordinary OpenAI-compatible request; only its body travels.
 * The address in it is never used - the relay calls only the AI address typed
 * on the merchant's computer - so nothing in a request can point the relay
 * elsewhere.
 *
 * Not in Somcp::CLASSES: it implements a Neuron interface, so it is loaded by
 * the chat, which only runs when Neuron is there.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpRelayClient implements HttpClientInterface
{
    /** @var int */
    private $idRelay;

    /** @var float */
    private $timeout;

    /**
     * @param int $idRelay
     * @param float $timeout seconds to wait for the answer
     */
    public function __construct(int $idRelay, float $timeout)
    {
        $this->idRelay = $idRelay;
        $this->timeout = $timeout;
    }

    /**
     * {@inheritdoc}
     */
    public function request(HttpRequest $request): HttpResponse
    {
        $relay = SomcpRelay::get($this->idRelay);
        if ($relay === null || !$relay['paired']) {
            throw HttpException::networkError($request, 'the local assistant has no relay linked any more');
        }
        // Told at once rather than after a wait nobody would answer.
        if (!SomcpRelay::online($relay)) {
            throw HttpException::networkError($request, 'the relay is offline');
        }

        $body = is_array($request->body) ? $request->body : json_decode((string) $request->body, true);
        if (!is_array($body)) {
            throw HttpException::networkError($request, 'the request has no JSON body');
        }
        // The relay forces an answer in one piece; asking for a stream here
        // would only be refused.
        unset($body['stream'], $body['stream_options']);

        $key = SomcpRelay::enqueue($this->idRelay, $body);
        $answer = SomcpRelay::wait($key, $request->timeout ?? $this->timeout);
        if ($answer === null) {
            SomcpRelay::cancel($key);
            throw HttpException::networkError($request, 'the relay did not answer in time');
        }
        if ($answer['error'] !== '') {
            throw HttpException::networkError($request, 'relay: ' . $answer['error']);
        }

        $response = new HttpResponse($answer['status'], $answer['body'], ['Content-Type' => 'application/json']);
        if ($answer['status'] >= 400 || $answer['status'] < 200) {
            throw HttpException::statusError($request, $response);
        }

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function stream(HttpRequest $request): StreamInterface
    {
        // The chat never streams, and the relay answers in one piece.
        throw HttpException::networkError($request, 'the relay does not stream');
    }
}
