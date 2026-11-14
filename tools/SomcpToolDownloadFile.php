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
 * Every file leaves by the same three ways, in the order an assistant uses
 * them: inside the answer when it is small enough to travel there - an image
 * as an image the client shows and the model sees, any other file as an
 * embedded resource; at a signed address the assistant fetches itself
 * (SomcpUpload::share(), the door the chat's files use); and on the back
 * office page that serves it to the merchant, signed in.
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
            'Gives the file behind a download_ref - a document, photo, logo or attachment another tool found: '
                . 'inside this answer when it is small enough (an image as an image), at an address to fetch it from '
                . 'yourself (GET, 15 minutes), and the page that serves it to the merchant signed in to the back '
                . 'office. The reference is made for this account and expires after 15 minutes; ask the tool that '
                . 'gave it again after that.',
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

        $link = SomcpDownload::link($kind, $id, $idEmployee, $idShop);
        $page = $link['url'] === '' ? null : $link['url'];
        $bytes = $file['bytes'];
        $shared = SomcpUpload::share($idEmployee, $file['name'], $bytes);

        $about = [
            'file' => $file['name'],
            'mime_type' => $file['mime'],
            'bytes' => strlen($bytes),
            // Read with a GET, no sign-in, until expires_at: the address the
            // assistant fetches the file from itself.
            'url' => $shared['url'] ?? null,
            'expires_at' => $shared['expires_at'] ?? null,
            'page' => $page,
        ];

        if (strlen($bytes) > SomcpDownload::INLINE_MAX) {
            if ($page === null && $shared === null) {
                return SomcpTool::text(
                    'The file is too large to hand over here, and the shop cannot build the address of its own'
                        . ' back office yet: ask the merchant to open the module settings once, then try again.',
                    true
                );
            }

            SomcpLogger::log('download_page', SomcpLogger::OUTCOME_SUCCESS, $kind . ' ' . $id, '', $idEmployee);

            return SomcpTool::json($about + [
                'inside' => false,
                'then' => 'The file is too large to hand over here. Fetch it yourself from url when you can make an HTTP'
                    . ' request; otherwise give the merchant the page, which opens in their own signed-in back'
                    . ' office only.',
            ]);
        }

        SomcpLogger::log('download_given', SomcpLogger::OUTCOME_SUCCESS, $kind . ' ' . $id, '', $idEmployee);

        $result = SomcpTool::json($about + [
            'inside' => true,
            'then' => 'The file is attached to this answer, and can be fetched from url until expires_at. The page'
                . ' opens it for the merchant, signed in to the back office.',
        ]);
        // An image goes as an image, which a client shows and a model that
        // reads pictures sees; anything else as a resource, read like an
        // attachment. The protocol has both, and an image sent as a resource
        // is shown by most clients as a file to save, not a picture.
        if (strpos($file['mime'], 'image/') === 0 && $file['mime'] !== 'image/x-icon') {
            $result['content'][] = ['type' => 'image', 'data' => base64_encode($bytes), 'mimeType' => $file['mime']];
        } else {
            $result['content'][] = [
                'type' => 'resource',
                'resource' => [
                    'uri' => 'somcp://download/' . $kind . '/' . $id . '/' . rawurlencode($file['name']),
                    'mimeType' => $file['mime'],
                    'blob' => base64_encode($bytes),
                ],
            ];
        }

        return $result;
    }
}
