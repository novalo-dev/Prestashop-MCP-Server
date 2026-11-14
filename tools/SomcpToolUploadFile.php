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
 * somcp_upload_file - puts a file on the shop for another tool, and answers
 * with the upload_ref that tool takes.
 *
 * A file cannot travel inside a conversation the way text does, and before
 * this tool only the built-in assistant and the modules using the contract
 * could open a deposit. Here the assistant names the tool and the field the
 * file is for, and the shop opens a deposit with that field's own rules
 * (SomcpTool::getFiles()), so asking through this tool can never widen what a
 * tool takes.
 *
 * The file then gets in by one of the ways of SomcpUpload::ENTRIES - an
 * address the shop downloads, the bytes as a data URI, a file the client
 * passes from its own conversation - or, when the call brings none, by the
 * two ways the answer gives: the assistant posts the bytes itself to a signed
 * address, or the merchant deposits the file on the page of the back office.
 * Every one of them ends in the same check (SomcpUpload::admit()).
 *
 * It asks for no back office right of its own: what it opens is useless
 * without the tool it is for, and that tool's scopes and rights are checked
 * here, before anything is opened, as they will be again when it is called.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolUploadFile
{
    /** @var string[] The arguments the tool takes besides its entries, so a misspelt one is refused by name. */
    private const KEYS = ['for', 'field'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_upload_file',
            Somcp::t('Put a file on the shop'),
            'Puts a file on the shop for another tool and answers with its upload_ref, used once. Name the tool '
                . '(for) and its field; the shop applies that field\'s formats and size. Bring the file in this call '
                . 'when you can, with one of: file (a file the person attached in your conversation, when your client '
                . 'passes it), data (the bytes as a data URI) or url (a public address). Without any, the answer says '
                . 'how to send it: POST it to the address given, or show the merchant the deposit page. Then pass '
                . 'upload_ref to the tool. A file the merchant attached in this module\'s back office chat already has one.',
            [
                'type' => 'object',
                'properties' => [
                    'for' => [
                        'type' => 'string',
                        'description' => 'The tool the file is for, e.g. somcp_create_product. Its file fields '
                            . 'are named in the refusal when the field is wrong.',
                    ],
                    'field' => [
                        'type' => 'string',
                        'description' => 'Its argument that takes the file, e.g. images or virtual_file.file; '
                            . 'may be left out when the tool has only one.',
                    ],
                ] + SomcpUpload::entrySchema(),
                'required' => ['for'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ACCESS],
            [self::class, 'handle'],
            // No back office right of its own, deliberately: the deposit is
            // only of use to the tool named, whose scopes and rights the
            // handler checks before opening anything. It changes no shop data,
            // so it asks no confirmation; but each call opens a new deposit,
            // and with an address the shop reaches outside, which the hints say.
            [
                'permissions' => [],
                'annotations' => [
                    'readOnlyHint' => true,
                    'destructiveHint' => false,
                    'idempotentHint' => false,
                    'openWorldHint' => true,
                ],
            ],
            SomcpUpload::entryMeta()
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
        $problems = SomcpTool::unknownFields($arguments, array_merge(self::KEYS, array_keys(SomcpUpload::ENTRIES)));

        $for = is_string($arguments['for'] ?? null) ? trim($arguments['for']) : '';
        $tool = $for === '' ? null : SomcpToolRegistry::getInstance()->get($for);
        if ($for === '') {
            $problems[] = 'for: the name of the tool the file is for, e.g. somcp_create_product';
        } elseif ($tool === null || !SomcpToolRegistry::isEnabled($for)
            || !$tool->isAvailableTo((string) ($context['scopes'] ?? ''), $idEmployee)) {
            // One answer for a tool that does not exist, is switched off or is
            // out of this connection's reach: the list of tools this connection
            // sees is where the difference is told, not here.
            $problems[] = 'for: no tool of that name is available to this connection';
            $tool = null;
        } elseif ($tool->getFiles() === []) {
            $problems[] = 'for: ' . $for . ' takes no file';
            $tool = null;
        }

        $field = '';
        $rule = null;
        if ($tool !== null) {
            $fields = $tool->getFiles();
            $asked = $arguments['field'] ?? null;
            if ($asked === null && count($fields) === 1) {
                $field = (string) array_key_first($fields);
            } elseif (is_string($asked) && isset($fields[trim($asked)])) {
                $field = trim($asked);
            } else {
                $problems[] = 'field: one of ' . implode(', ', array_keys($fields)) . ', the fields of ' . $for . ' that take a file';
            }
            $rule = $field === '' ? null : $fields[$field];
        } elseif (array_key_exists('field', $arguments) && !is_string($arguments['field'])) {
            $problems[] = 'field: a string, the argument of the tool that takes the file';
        }

        // One entry at most: two ways of bringing a file in one call would
        // leave the shop to choose which file was meant.
        $given = array_values(array_intersect(array_keys(SomcpUpload::ENTRIES), array_keys($arguments)));
        $entry = $given[0] ?? null;
        if (count($given) > 1) {
            $problems[] = implode(', ', $given) . ': one of them, not several';
        } elseif ($entry !== null && ($arguments[$entry] === null || $arguments[$entry] === [] || (is_string($arguments[$entry]) && trim($arguments[$entry]) === ''))) {
            $problems[] = $entry . ': ' . ($entry === 'url' ? 'the public https address of the file' : 'the file') . ', or left out';
        } elseif ($entry !== null && $rule !== null && SomcpUpload::ENTRIES[$entry]['address'] && !$rule['url']) {
            $problems[] = $entry . ': not taken for ' . $for . ' ' . $field . ', whose file is never fetched from an'
                . ' address; send the bytes with data, or as the answer says';
        }

        if ($problems !== [] || $tool === null || $rule === null) {
            return SomcpTool::refused('deposited', $problems);
        }

        $reference = SomcpUpload::inviteFor($idEmployee, $tool, $field);
        $invitation = $reference === '' ? null : SomcpUpload::find($reference, $idEmployee);
        if ($invitation === null) {
            return SomcpTool::text(
                'The shop could not open a deposit: the account is not usable on this shop, or the shop takes'
                    . ' none of the formats ' . $for . ' accepts.',
                true
            );
        }

        $then = 'Then call ' . $for . ' with upload_ref ' . $reference . ' in ' . $field . '. It is used once.';

        if ($entry !== null) {
            $refusal = SomcpUpload::enter($invitation, $entry, $arguments[$entry]);
            if ($refusal !== '') {
                // The deposit stays open: the same file can still be sent by
                // the other ways, and the answer says them.
                return SomcpTool::text(
                    $entry . ': ' . $refusal . ". Nothing was deposited.\n\n"
                        . SomcpUpload::directions(SomcpBackOffice::uploadUrl($reference, $idEmployee), $reference, $invitation, $then),
                    true
                );
            }

            return SomcpTool::json([
                'deposited' => true,
                SomcpUpload::ARG_REF => $reference,
                'for' => $for,
                'field' => $field,
                'then' => $then,
            ]);
        }

        $page = SomcpBackOffice::uploadUrl($reference, $idEmployee);

        return SomcpTool::json([
            'deposited' => false,
            SomcpUpload::ARG_REF => $reference,
            'for' => $for,
            'field' => $field,
            'accepted' => explode(' ', (string) $invitation['extensions']),
            'max_bytes' => (int) $invitation['max_bytes'],
            'expires_at' => (string) $invitation['expires_at'],
            'send_to' => [
                'url' => SomcpUpload::directUrl($reference, $invitation),
                'method' => 'POST',
                'encoding' => 'multipart/form-data',
                'field' => SomcpUpload::DIRECT_FIELD,
            ],
            'deposit_page' => $page === '' ? null : $page,
            'how' => SomcpUpload::directions($page, $reference, $invitation, $then),
        ]);
    }
}
