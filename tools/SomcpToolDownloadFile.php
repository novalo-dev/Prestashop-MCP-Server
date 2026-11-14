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
 * somcp_download_file - hands a file of the shop to the assistant, from the
 * download_ref another tool gave.
 *
 * The outgoing counterpart of somcp_upload_file. No tool gives out the
 * address of a file: a tool that finds one - an invoice, a product photo, a
 * logo, an attachment - answers with a download_ref, and this tool is the one
 * place that turns a reference into the file, so who may have it is decided
 * once (SomcpDownload).
 *
 * A file small enough to travel inside the answer comes attached to it and
 * nothing else - an image as an image the client shows and the model sees,
 * any other file as an embedded resource. Only a file too large for that
 * comes as a signed address the assistant fetches itself
 * (SomcpUpload::share(), the door the chat's files use) and the back office
 * page that serves it to the merchant, signed in. The built-in chat, which
 * passes the model the text of an answer only, gets the page.
 *
 * It asks for no back office right of its own, because what it may hand over
 * depends on the file: the scopes and rights of the tool that reads that kind
 * of file, checked here at every call. The reference itself is signed for the
 * account and the shop it was made for.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolDownloadFile
{
    /** @var string[] The arguments the tool takes, so a misspelt one is refused by name. */
    private const KEYS = [SomcpDownload::ARG_REF];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_download_file',
            Somcp::t('Get a file from the shop'),
            'Gives the file behind a download_ref - a document, photo, logo or attachment another tool found - '
                . 'attached to this answer (an image as an image): read it or show it, never a link instead. Only a '
                . 'file too large to attach comes as an address to fetch yourself (GET, 15 minutes) and a back office '
                . 'page for the merchant. The reference is made for this account and expires after 15 minutes; ask '
                . 'the tool that gave it again after that.',
            [
                'type' => 'object',
                'properties' => [
                    SomcpDownload::ARG_REF => [
                        'type' => 'string',
                        'description' => 'The download_ref exactly as the other tool gave it.',
                    ],
                ],
                'required' => [SomcpDownload::ARG_REF],
                'additionalProperties' => false,
            ],
            [SomcpScope::ACCESS],
            [self::class, 'handle'],
            // No back office right of its own, deliberately: the right that
            // matters is the one of the file asked for, checked in the handler.
            SomcpTool::actsOnNothing()
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
        $idEmployee = (int) ($context['id_employee'] ?? 0);
        $idShop = Somcp::currentShopId();
        $problems = SomcpTool::unknownFields($arguments, self::KEYS);

        $reference = is_string($arguments[SomcpDownload::ARG_REF] ?? null) ? trim($arguments[SomcpDownload::ARG_REF]) : '';
        if ($reference === '') {
            $problems[] = 'download_ref: the reference another tool gave, e.g. somcp_get_order_document';
        }
        if ($problems !== []) {
            return SomcpTool::refused('read', $problems);
        }

        $query = SomcpDownload::query($reference);
        $sound = SomcpDownload::check($query, $idEmployee, $idShop);
        if ($sound === 'expired') {
            return SomcpTool::refused('read', ['download_ref: expired; ask the tool that gave it for a new one']);
        }
        if ($sound !== '') {
            // A reference typed wrong, made for another account or for another
            // shop: told apart nowhere, so trying one teaches nothing.
            return SomcpTool::refused('read', ['download_ref: not a reference made for this account on this shop']);
        }

        $kind = $query['kind'];
        $id = $query['id'];
        if (!SomcpDownload::mayHave($kind, (string) ($context['scopes'] ?? ''), $idEmployee)) {
            SomcpLogger::log('download_refused', SomcpLogger::OUTCOME_FAILURE, 'no permission', '', $idEmployee);

            return SomcpTool::refused('read', ['download_ref: this account or connection may not read ' . SomcpDownload::KINDS[$kind]['reader']]);
        }

        // Looked up only now, so whether a file exists is told to no one who
        // may not have it.
        $file = SomcpDownload::file($kind, $id, $idShop);
        if (!is_array($file)) {
            return SomcpTool::refused('read', ['download_ref: this file no longer exists']);
        }

        return SomcpDownload::handOver($file, $context, $kind, $id);
    }
}
