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
 * SomcpUpload - the one way a file gets into the shop through an assistant.
 *
 * There is no attachment channel in the protocol: a tool argument is JSON, so
 * a file put there is bytes the model has to write out, token by token. The
 * way out is to send the merchant to a page of their own back office; the
 * bytes never touch the assistant.
 *
 * Written once, here, rather than in each module that needs it. A page that
 * receives files behind an administrator session is the last place to
 * duplicate: three copies would be three chances to forget the size cap, the
 * type list, or the check on who is depositing.
 *
 * What a tool may ask for is the tool's business: which formats, how many
 * files, how large. What is never the tool's business is the list of formats
 * that exist at all. An upload endpoint in a back office that accepts any
 * extension is the oldest hole there is, so the list below is closed and a
 * tool chooses from it.
 *
 * An invitation is opened, files are deposited against it, and the tool takes
 * them. The invitation stops existing when it is taken, when it expires, or
 * when the merchant declines - and the files go with it, every time.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpUpload
{
    /** @var int How long an invitation stays open, in seconds. */
    public const TTL = 900;

    /** @var int What a tool gets when it names no size: 8 MiB. */
    public const DEFAULT_BYTES = 8388608;

    /**
     * @var int The most any tool may ask for, whatever it declares: 64 MiB.
     *
     * Not a policy so much as an outer edge. PHP's own upload_max_filesize and
     * post_max_size cut in well below this on most hosting, and ceiling()
     * below reads them - a merchant meeting a silently truncated upload is
     * worse served than one told the file is too big.
     */
    public const MAX_BYTES = 67108864;

    /** @var int The most files one invitation may hold, whatever a tool asks. */
    public const MAX_FILES = 20;

    /** @var int 10 MB per image a tool downloads or takes from a deposit. */
    public const IMAGE_MAX_BYTES = 10485760;

    /** @var array<int, string> What an image may be, by the type getimagesize() reads in its bytes. */
    public const IMAGE_TYPES = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_GIF => 'image/gif',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    /** @var array<int, string> The extensions of IMAGE_TYPES, what an image field of a tool takes. */
    public const IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** @var int Bytes kept aside in every POST for the multipart envelope: 64 KiB. */
    private const ENVELOPE = 65536;

    /** @var string Key the file request is filed under, in inputRequests and in the state. */
    public const REQUEST_KEY = 'somcp_file';

    /**
     * @var string Argument a tool accepts so a merchant can hand the reference back.
     *
     * Only used when the client cannot carry the question itself. A tool that
     * asks for files declares it as an optional string; on the second call the
     * assistant fills it with what the first call printed, and take() finds it.
     */
    public const ARG_REF = 'upload_ref';

    /**
     * @var string marks the deposits made by attaching a file in the built-in
     *             assistant, the only ones a signed link may serve
     */
    public const CHAT_MODULE = 'somcp-assistant';

    /**
     * @var string Front controller an assistant sends the file of an invitation to, by itself.
     *
     * The page of the back office needs the merchant: a person signed in, a
     * click, a file chosen. An assistant that can make an HTTP request - a code
     * tool, a terminal, an HTTP client - has the file already and needs none
     * of that, so it gets an address of its own, outside the administration
     * folder, that takes one invitation's files and nothing else.
     */
    public const DIRECT_CONTROLLER = 'deposit';

    /** @var string Multipart field the file is sent in, to the direct address. */
    public const DIRECT_FIELD = 'file';

    /**
     * @var string Folder the files live in, relative to the shop root.
     *
     * Under var/ rather than under the module, and the difference is the web
     * server. A module folder has to stay partly servable - its scripts,
     * styles and images are fetched over HTTP - so keeping a deposit there
     * means asking every merchant's configuration to make an exception for one
     * subfolder. The .htaccess written below does that on Apache and is simply
     * ignored by nginx.
     *
     * Nothing under var/ is ever linked to, and the configurations that deny a
     * directory outright deny this one. It is not a guarantee - a server can
     * be set up to serve anything - but it is the difference between relying
     * on a rule nobody wrote and relying on the ordinary one.
     */
    private const STORE = 'var/somcp/uploads/';

    /**
     * @var array<string, array<int, string>> Shorthands a tool can ask for instead of listing extensions.
     *
     * A convenience, not the boundary: a tool may name its own extensions, and
     * they are checked against ACCEPTED below either way.
     */
    private const KINDS = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'avif'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'rtf', 'txt'],
        'data' => ['csv', 'xml', 'json'],
        'archive' => ['zip'],
    ];

    /**
     * @var array<string, array{mime:string, check:string}> Every extension the shop will ever store.
     *
     * `check` names how the bytes are verified. Nothing is accepted on the
     * strength of its extension alone: the extension is what the uploader
     * called the file, the bytes are what it is, and both have to agree.
     */
    private const ACCEPTED = [
        'jpg' => ['mime' => 'image/jpeg', 'check' => 'image'],
        'jpeg' => ['mime' => 'image/jpeg', 'check' => 'image'],
        'png' => ['mime' => 'image/png', 'check' => 'image'],
        'gif' => ['mime' => 'image/gif', 'check' => 'image'],
        'webp' => ['mime' => 'image/webp', 'check' => 'image'],
        'bmp' => ['mime' => 'image/bmp', 'check' => 'image'],
        'tif' => ['mime' => 'image/tiff', 'check' => 'image'],
        'tiff' => ['mime' => 'image/tiff', 'check' => 'image'],
        // What an iPhone produces by default, and what getimagesize does not
        // read on most builds, so these are checked by their container brand.
        'heic' => ['mime' => 'image/heic', 'check' => 'bmff'],
        'heif' => ['mime' => 'image/heif', 'check' => 'bmff'],
        'avif' => ['mime' => 'image/avif', 'check' => 'bmff'],
        'pdf' => ['mime' => 'application/pdf', 'check' => 'pdf'],
        // Office and OpenDocument files are zip containers, and that is as far
        // as their shape can be checked without opening them.
        // The pre-2007 pair. Both are OLE2 compound files and share one
        // signature, so the check confirms the container and not which of the
        // two it holds - a .xls renamed .doc passes. That is as far as the
        // format allows without opening it, and neither is dangerous to store.
        'doc' => ['mime' => 'application/msword', 'check' => 'ole2'],
        'xls' => ['mime' => 'application/vnd.ms-excel', 'check' => 'ole2'],
        'docx' => ['mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'check' => 'zip'],
        'xlsx' => ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'check' => 'zip'],
        'odt' => ['mime' => 'application/vnd.oasis.opendocument.text', 'check' => 'zip'],
        'ods' => ['mime' => 'application/vnd.oasis.opendocument.spreadsheet', 'check' => 'zip'],
        'zip' => ['mime' => 'application/zip', 'check' => 'zip'],
        'rtf' => ['mime' => 'application/rtf', 'check' => 'rtf'],
        'txt' => ['mime' => 'text/plain', 'check' => 'text'],
        'csv' => ['mime' => 'text/csv', 'check' => 'text'],
        'xml' => ['mime' => 'application/xml', 'check' => 'xml'],
        'json' => ['mime' => 'application/json', 'check' => 'json'],
    ];

    /**
     * Everything a tool has to do to ask the merchant for files.
     *
     * The one call a module makes. It opens the invitation, builds the address,
     * and returns the result a handler gives straight back - so nothing outside
     * this class deals with the reference, the page, the identity check or the
     * purge. On the second call the same handler calls take().
     *
     * Two ways out, and which one is taken depends on the client rather than on
     * the tool. A client that declared it can open an address gets the proper
     * multi-round question: it opens the page itself, replays the call, and the
     * merchant never handles a reference. A client that did not is handed the
     * address and the reference as plain text, and the merchant comes back and
     * says so. Clumsier, and it works everywhere.
     *
     * The fallback exists because the polite path was found, in production, to
     * reach nobody: no assistant tested so far declares url-mode elicitation,
     * and the specification forbids sending a question that was not declared.
     *
     * @param array<string, mixed> $context the context a tool handler is given
     * @param string $purpose what the files are for, in the merchant's language
     * @param string|array<int, string> $accept a shorthand from KINDS, or the extensions
     *                                          the tool wants, which must be in ACCEPTED
     * @param string $module folder of the module asking, empty for this one
     * @param array<string, mixed> $state anything else the tool wants back
     * @param array{files?:int, bytes?:int} $limits how many files and how large, both
     *                                              clamped to what the shop can take
     *
     * @return array<string, mixed> the result to return from the handler
     */
    public static function request(
        array $context,
        string $purpose,
        $accept,
        string $module = '',
        array $state = [],
        array $limits = [],
    ): array {
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $reference = self::invite($idEmployee, $purpose, $accept, $module, $limits);
        if ($reference === '') {
            return SomcpTool::text(
                'The shop could not open a file request. Either the account is not usable on this shop,'
                . ' or the tool asked for a file type the shop does not accept.',
                true
            );
        }

        $url = SomcpBackOffice::uploadUrl($reference, $idEmployee);
        if ($url === '') {
            // The address is built from the administration folder, which the
            // module only learns by running in the back office once.
            return SomcpTool::text(
                'The shop cannot build the address of its own back office yet.'
                . ' Open the module settings once, then try again.',
                true
            );
        }

        $capabilities = is_array($context['capabilities'] ?? null) ? $context['capabilities'] : [];

        if (!SomcpElicitation::supported($capabilities, SomcpElicitation::MODE_URL)) {
            $invitation = self::find($reference, $idEmployee);

            return self::askByHand($url, $reference, $invitation ?? []);
        }

        return SomcpTool::needsInput(
            [self::REQUEST_KEY => SomcpElicitation::url($purpose, $url)],
            $state + ['upload' => $reference]
        );
    }

    /**
     * The files the merchant deposited, on the second half of a call.
     *
     * A list, even for one file: a tool that asked for one gets an array of
     * one, and nothing has to change the day it asks for three.
     *
     * @param array<string, mixed> $context the context a tool handler is given
     *
     * @return array<int, array{name:string, mimeType:string, size:int, contents:string}>
     */
    public static function take(array $context): array
    {
        return self::redeem(self::reference($context), (int) ($context['id_employee'] ?? 0));
    }

    /**
     * The invitation a call comes back to, if it is still open on this shop.
     *
     * Told apart from take() because "nothing came back" covers two cases
     * that need opposite answers. A reference that leads nowhere - invented,
     * expired, already redeemed, cancelled - has to be dropped so a new
     * deposit can be opened, or the assistant has nowhere to go. An open
     * invitation the merchant has not filled yet has to be handed over
     * again, and opening a second one would lose what is sent to the first.
     *
     * @param array<string, mixed> $context the context a tool handler is given
     *
     * @return array<string, mixed>|null the invitation row, or null when the reference leads nowhere
     */
    public static function pending(array $context): ?array
    {
        $row = self::find(self::reference($context), (int) ($context['id_employee'] ?? 0));
        if ($row === null || (int) $row['id_shop'] !== Somcp::currentShopId()) {
            return null;
        }

        return $row;
    }

    /**
     * The address of an open, still empty deposit, written out for the
     * assistant to show.
     *
     * Reached when a call comes back to a deposit nobody filled. Seen in
     * production: a client that declares it can open an address took the
     * question, never showed the page to the merchant, and replayed the call
     * at once. Answering "nothing came back" then left the merchant without
     * the one thing they needed. The same invitation is handed over in plain
     * text instead, so whatever the client did with the question, the
     * address reaches a person.
     *
     * @param array<string, mixed> $context the context a tool handler is given
     * @param array<string, mixed> $invitation the row from pending()
     *
     * @return array<string, mixed> the result to return from the handler
     */
    public static function handOver(array $context, array $invitation): array
    {
        $reference = self::reference($context);
        $url = SomcpBackOffice::uploadUrl($reference, (int) ($context['id_employee'] ?? 0));
        if ($url === '') {
            return SomcpTool::text(
                'The shop cannot build the address of its own back office yet.'
                . ' Open the module settings once, then try again.',
                true
            );
        }

        return self::askByHand($url, $reference, $invitation);
    }

    /**
     * The deposit this call comes back from, if it comes back from one.
     *
     * Public because the array contract has to tell a first call from a
     * return before deciding whether to redeem, and reading the two places a
     * reference can travel in a second time elsewhere is how the two readings
     * end up disagreeing.
     *
     * @param array<string, mixed> $context the context a tool handler is given
     *
     * @return string empty on a first call
     */
    public static function reference(array $context): string
    {
        $resumed = is_array($context['resumed'] ?? null) ? $context['resumed'] : [];
        $reference = (string) ($resumed['upload'] ?? '');

        if ($reference === '') {
            // Handed back by the merchant's assistant rather than by the
            // protocol. Nothing is trusted about it: redeem() still requires
            // it to match an invitation opened for this very employee, on this
            // shop, and not yet taken.
            $arguments = is_array($context['arguments'] ?? null) ? $context['arguments'] : [];
            $reference = (string) ($arguments[self::ARG_REF] ?? '');
        }

        return $reference;
    }

    /**
     * The same request, written out for a client that cannot open an address.
     *
     * Addressed to the assistant rather than to the merchant: it is the one
     * reading this, and what it does next decides whether the files ever
     * arrive. So it says what to send or show, and exactly how to come back -
     * an assistant told only that something went wrong will apologise and stop.
     *
     * Not marked as an error. A client that sees isError often abandons the
     * task, and nothing has failed here: the invitation is open and waiting.
     *
     * @param string $url
     * @param string $reference
     * @param array<string, mixed> $invitation
     *
     * @return array<string, mixed>
     */
    private static function askByHand(string $url, string $reference, array $invitation): array
    {
        return SomcpTool::text(self::directions(
            $url,
            $reference,
            $invitation,
            'Once the ' . ((int) ($invitation['max_files'] ?? 1) > 1 ? 'files are' : 'file is') . ' deposited,'
                . ' by you or by the merchant, call this same tool again with the same arguments plus '
                . self::ARG_REF . ' set to: ' . $reference . "\n\n"
                . 'Do not report this as a failure.'
        ));
    }

    /**
     * How the files of an invitation reach the shop, in the order to try.
     *
     * One text for every way a deposit is asked for - a tool of this module,
     * a tool of another module through the contract, the request tool - so an
     * assistant meets the same steps wherever the file is needed. The
     * assistant sends the file itself when it can; the merchant is asked only
     * when it cannot. It cannot know in advance whether its own network lets it
     * reach the shop, so it is told what a refusal looks like and what the
     * person can change for next time, in words that fit any assistant.
     *
     * @param string $url the deposit page of the back office, for the merchant; empty when it cannot be built yet
     * @param string $reference
     * @param array<string, mixed> $invitation the row from find()
     * @param string $then what to do once the files are in
     *
     * @return string
     */
    public static function directions(string $url, string $reference, array $invitation, string $then): string
    {
        $files = max(1, (int) ($invitation['max_files'] ?? 1));
        $megabytes = (int) round(((int) ($invitation['max_bytes'] ?? self::DEFAULT_BYTES)) / 1048576);
        $one = $files === 1;
        $direct = self::directUrl($reference, $invitation);
        $host = (string) parse_url($direct, PHP_URL_HOST);

        return ($one ? 'A file is' : 'Up to ' . $files . ' files are') . ' needed, and '
            . ($one ? 'it cannot' : 'they cannot') . ' travel inside this conversation.'
            . ' Accepted: ' . str_replace(' ', ', ', (string) ($invitation['extensions'] ?? ''))
            . '. Up to ' . $megabytes . ' MB each. Both addresses below stop working in '
            . (int) round(max(60, ((int) strtotime((string) ($invitation['expires_at'] ?? ''))) - time()) / 60) . ' minutes.' . "\n\n"
            . '1. If you can make an HTTP request yourself (a code tool, a terminal, an HTTP or fetch tool),'
            . ' send ' . ($one ? 'the file' : 'each file') . ' now, without asking the merchant: POST it to' . "\n\n"
            . $direct . "\n\n"
            . 'as multipart/form-data, in the field "' . self::DIRECT_FIELD . '", under its real name with its'
            . ' extension (for example curl -F "' . self::DIRECT_FIELD . '=@photo.jpg" followed by the address).'
            . ' The shop answers {"received": true} when it keeps the file, or the reason it refused it.' . "\n\n"
            . '2. If that request never reaches the shop - refused by your own environment, a blocked domain or'
            . ' no network - tell the person once that allowing ' . $host . ' in the network or allowed domains'
            . ' settings of their assistant would let you deposit files yourself next time, then go to step 3.'
            . ' If you know you cannot make HTTP requests at all, go straight to step 3.' . "\n\n"
            . ($url === ''
                // The page's address is built from the administration folder,
                // which the module only learns by running in the back office.
                ? '3. The deposit page of the back office cannot be offered yet: ask the merchant to open the'
                    . ' module settings once, then ask for a new deposit.' . "\n\n"
                : '3. Show the merchant this address and ask them to open it and deposit '
                    . ($one ? 'the file' : 'the files') . ':' . "\n\n"
                    . $url . "\n\n"
                    . 'Wait for them to say ' . ($one ? 'it has' : 'they have') . ' been sent.' . "\n\n")
            . $then;
    }

    /**
     * The address an assistant sends the files of an invitation to, by itself.
     *
     * It carries the hash of the reference, never the reference: the address
     * may end up in the logs of whatever made the request, and the reference
     * is what redeems the file for a tool. It lasts as long as the invitation,
     * and is signed with the key of the chat's file links, under a label of its
     * own, so a link to read a file can never be used to deposit one.
     *
     * @param string $reference
     * @param array<string, mixed> $invitation the row from find()
     *
     * @return string
     */
    public static function directUrl(string $reference, array $invitation): string
    {
        $hash = SomcpRandom::hash($reference);
        $expires = (int) strtotime((string) ($invitation['expires_at'] ?? ''));

        return Somcp::legacyContext()->link->getModuleLink('somcp', self::DIRECT_CONTROLLER, [
            'u' => $hash,
            'e' => $expires,
            's' => self::directSignature($hash, $expires),
        ], true);
    }

    /**
     * The open invitation a direct address points at, or null.
     *
     * Every way to fail answers null, so the door can answer every failure the
     * same way and nothing is learnt by trying. Never an invitation of the
     * built-in assistant: those hold a file attached in the chat, already in.
     *
     * @param string $hash
     * @param int $expires
     * @param string $signature
     *
     * @return array<string, mixed>|null
     */
    public static function directInvitation(string $hash, int $expires, string $signature): ?array
    {
        if ($expires <= time() || !preg_match('/^[a-f0-9]{64}$/', $hash) || !preg_match('/^[a-f0-9]{64}$/', $signature)
            || !hash_equals(self::directSignature($hash, $expires), $signature)) {
            return null;
        }

        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . "somcp_upload`
             WHERE `reference_hash` = '" . pSQL($hash) . "'
               AND `module` <> '" . pSQL(self::CHAT_MODULE) . "'
               AND `expires_at` > '" . pSQL(date('Y-m-d H:i:s')) . "'"
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * @param string $hash
     * @param int $expires
     *
     * @return string
     */
    private static function directSignature(string $hash, int $expires): string
    {
        return SomcpAssistantChat::signature('deposit|' . $hash, $expires);
    }

    /**
     * Why accept() refused a file, worded for an assistant.
     *
     * @param string $problem a code accept() returns
     * @param array<string, mixed> $invitation
     *
     * @return string
     */
    public static function refusal(string $problem, array $invitation): string
    {
        switch ($problem) {
            case 'too_many':
                return 'this deposit already holds the ' . (int) $invitation['max_files'] . ' file(s) it was opened for';
            case 'too_large':
                return 'the file is larger than ' . (int) round(((int) $invitation['max_bytes']) / 1048576) . ' MB';
            case 'wrong_type':
                return 'only ' . str_replace(' ', ', ', (string) $invitation['extensions'])
                    . ' are accepted, and the content must match the extension';
            case 'no_file':
                return 'no file in the multipart field "' . self::DIRECT_FIELD . '"';
            default:
                return 'the shop could not store it';
        }
    }

    /**
     * What a file field of a tool takes, as SomcpTool declares it.
     *
     * @param string|array<int, string> $accept a shorthand from KINDS, or extensions from ACCEPTED
     * @param int|callable(): int $bytes the largest file the tool takes, or what gives it when it is a
     *                                   setting of the shop: a tool is defined before the shop is read,
     *                                   so a setting is read only when a deposit is opened
     * @param bool $byUrl false when the field must never be filled from an
     *                    address - a module archive, where a link read anywhere
     *                    would become code running on the shop
     *
     * @return array{accept: string|array<int, string>, bytes: int|callable(): int, url: bool}
     */
    public static function rule($accept, $bytes, bool $byUrl = true): array
    {
        return ['accept' => $accept, 'bytes' => $bytes, 'url' => $byUrl];
    }

    /**
     * What an image field takes, the same for every tool.
     *
     * @return array{accept: string|array<int, string>, bytes: int|callable(): int, url: bool}
     */
    public static function images(): array
    {
        return self::rule(self::IMAGES, self::IMAGE_MAX_BYTES);
    }

    /**
     * Opens a deposit for one file field of a tool, with that field's rules.
     *
     * What somcp_upload_file does for an assistant: the tool named decides the
     * formats and the size, as a module decides them through the contract, so
     * an assistant cannot widen what a tool takes by asking for something else.
     * One file per deposit: every field that takes a file takes one upload_ref
     * per file.
     *
     * @param int $idEmployee
     * @param SomcpTool $tool the tool the file is for
     * @param string $field one of the fields it declares
     *
     * @return string the reference, or an empty string when refused
     */
    public static function inviteFor(int $idEmployee, SomcpTool $tool, string $field): string
    {
        $rule = $tool->getFiles()[$field] ?? null;
        if ($rule === null) {
            return '';
        }

        return self::invite(
            $idEmployee,
            $tool->getName() . ' ' . $field,
            $rule['accept'],
            '',
            ['files' => 1, 'bytes' => is_callable($rule['bytes']) ? (int) call_user_func($rule['bytes']) : (int) $rule['bytes']]
        );
    }

    /**
     * The schema of one image field, in the same words for every tool.
     *
     * Image fields used to be described tool by tool, and the descriptions
     * drifted: one said 10 MB, another said nothing. Built here, a field says
     * the same thing wherever it goes, including the tool that deposits a file
     * for it when the assistant has no address to give.
     *
     * @param string $description who fills it and what it is, first
     * @param string $of the image named in the property descriptions, e.g. "the logo"
     *
     * @return array<string, mixed>
     */
    public static function schema(string $description, string $of): array
    {
        return [
            'type' => 'object',
            'description' => $description . ' Its url (public https, no redirect, 10 MB at most) or an upload_ref '
                . '(somcp_upload_file). JPEG, PNG, GIF or WebP; never file content.',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'Public https address of ' . $of . '. Give url or upload_ref, not both.'],
                'upload_ref' => ['type' => 'string', 'description' => 'Reference of ' . $of . ' on the shop, from somcp_upload_file or the chat, exactly as given. Give url or upload_ref, not both.'],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Where one file comes from: a public address the shop downloads, or
     * the reference of a file already on the shop. One of the two, never
     * both, and never the bytes themselves - the two doors somcp lets a file
     * in through, whatever tool it is for.
     *
     * @param array<string, mixed> $item
     * @param string $path
     * @param array<int, string> $errors
     * @param string $what what the file is, for the message
     *
     * @return array{url: string, upload_ref: string}|null
     */
    public static function source(array $item, string $path, array &$errors, string $what = 'image'): ?array
    {
        $url = trim(self::scalar($item['url'] ?? null));
        $reference = trim(self::scalar($item[self::ARG_REF] ?? null));
        if ($url !== '' && $reference !== '') {
            $errors[] = $path . ': url or upload_ref, not both';

            return null;
        }
        if ($url === '' && $reference === '') {
            $errors[] = $path . ': the public https address of the ' . $what . ' (url), or the upload_ref of a file on the shop';

            return null;
        }

        return ['url' => $url, 'upload_ref' => $reference];
    }

    /**
     * One file field of a tool: absent, or an object read by source().
     *
     * Anything other than an object - a bare address, a bare reference - is
     * refused with the message source() gives for an empty one, which
     * names both forms the field accepts.
     *
     * @param array<string, mixed> $arguments
     * @param string $field
     * @param array<int, string> $errors
     * @param string $what what the file is, for the message
     *
     * @return array{url: string, upload_ref: string}|null null when not given or refused
     */
    public static function argument(array $arguments, string $field, array &$errors, string $what): ?array
    {
        if (!array_key_exists($field, $arguments)) {
            return null;
        }
        $value = $arguments[$field];

        return self::source(is_array($value) && !array_is_list($value) ? $value : [], $field, $errors, $what);
    }

    /**
     * How a file is named in the answer: its address, or its reference.
     *
     * @param array<string, mixed> $image
     *
     * @return array<string, string>
     */
    public static function origin(array $image): array
    {
        return ($image['upload_ref'] ?? '') !== '' ? ['upload_ref' => (string) $image['upload_ref']] : ['url' => (string) $image['url']];
    }

    /**
     * Whether an image field given back to an update carries the value its get
     * returned for the stored file, so writing it would change nothing.
     *
     * A get hands an image back as the public address of the shop's own file,
     * or as null when there is none. Given back to the matching update under
     * the same name, that value is not a new image: null when there is no file,
     * or the file's own address as a bare string or as {"url": ...}. An
     * upload_ref, or any other address, is a real image and is not matched
     * here. The addresses are compared on their host and path, the scheme left
     * aside since the get can answer in http while the assistant writes https.
     * A file name alone would take a new photo that happens to share the stored
     * file's name (3.jpg) for the stored one; the whole address is safe to
     * compare because getMediaLink() picks the media server from the file
     * path, so the same file always gets the same host.
     *
     * @param mixed $given the field exactly as the assistant sent it
     * @param string|null $current the address the get returns for the stored file, or null when there is none
     */
    public static function same($given, ?string $current): bool
    {
        if ($given === null) {
            return $current === null;
        }
        if ($current === null) {
            return false;
        }

        $url = null;
        if (is_string($given)) {
            $url = $given;
        } elseif (is_array($given) && !array_is_list($given)
            && array_key_exists('url', $given) && !array_key_exists(self::ARG_REF, $given)
            && is_string($given['url'])) {
            $url = $given['url'];
        }
        if ($url === null) {
            return false;
        }

        $url = trim($url);
        $givenHost = SomcpHttp::authority($url);
        $currentHost = SomcpHttp::authority($current);
        if ($givenHost === null || $givenHost !== $currentHost) {
            return false;
        }
        $givenPath = (string) (parse_url($url, PHP_URL_PATH) ?: '');

        return $givenPath !== '' && $givenPath === (string) (parse_url($current, PHP_URL_PATH) ?: '');
    }

    /**
     * The bytes of one image, from its address or its upload_ref, checked.
     *
     * Every tool that takes an image gets it through here, whatever object it
     * ends up on: a guard added to the download, the deposit or the image
     * check then reaches all of them at once, instead of the one tool that
     * happened to be edited.
     *
     * @param array{url: string, upload_ref: string} $source as source() reads it
     * @param string $target where the file is written
     *
     * @return string empty on success, the reason otherwise
     */
    public static function fetchImage(array $source, string $target): string
    {
        $refusal = $source['upload_ref'] !== ''
            ? self::fromDeposit($source['upload_ref'], $target)
            : self::fromUrl($source['url'], $target);

        return $refusal === '' ? self::checkImage($target) : $refusal;
    }

    /**
     * The bytes of any file, from its address or its upload_ref, unchecked.
     *
     * For a tool whose file is not an image, which checks the content its own
     * way afterwards: an archive is opened, a download is stored as it is.
     *
     * @param array{url: string, upload_ref: string} $source as source() reads it
     * @param string $target where the file is written
     * @param int $maxBytes the largest file the tool takes
     * @param string|null $fileName receives the name of a deposited file
     *
     * @return string empty on success, the reason otherwise
     */
    public static function receive(array $source, string $target, int $maxBytes, ?string &$fileName = null): string
    {
        return $source['upload_ref'] !== ''
            ? self::fromDeposit($source['upload_ref'], $target, $maxBytes, $fileName)
            : self::fromUrl($source['url'], $target, $maxBytes);
    }

    /**
     * A field value as text, as the tools read their own strings.
     *
     * @param mixed $value
     *
     * @return string empty for anything that is not a string or a number
     */
    private static function scalar($value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    /**
     * @param string $file
     *
     * @return string empty when the file is an image the shop can use
     */
    public static function checkImage(string $file): string
    {
        $info = @getimagesize($file);
        $type = is_array($info) ? (int) $info[2] : 0;
        if (!isset(self::IMAGE_TYPES[$type])) {
            return 'it is not a JPEG, PNG, GIF or WebP image';
        }
        if (!ImageManager::isRealImage($file, self::IMAGE_TYPES[$type], array_values(self::IMAGE_TYPES))) {
            return 'its content is not the image it claims to be';
        }
        if (!ImageManager::checkImageMemoryLimit($file)) {
            return 'it is too large for the shop to resize';
        }

        return '';
    }

    /**
     * Writes the file behind an upload_ref where the tool puts it together.
     *
     * Redeemed for the employee the write runs as, on this shop: a reference
     * belonging to someone else, expired or already used finds nothing. The
     * built-in assistant hands over a copy, so its conversation keeps the
     * file. Public so every tool taking an upload_ref words its problems the
     * same way, somcp_upload_module included.
     *
     * @param string $reference
     * @param string $target
     * @param int $maxBytes the largest file accepted
     * @param string|null $fileName receives the name the file was deposited under
     *
     * @return string empty on success, the reason otherwise
     */
    public static function fromDeposit(string $reference, string $target, int $maxBytes = self::IMAGE_MAX_BYTES, ?string &$fileName = null): string
    {
        $employee = Somcp::legacyContext()->employee;
        $idEmployee = $employee instanceof Employee ? (int) $employee->id : 0;
        $files = self::redeem($reference, $idEmployee);
        if ($files === []) {
            return 'no file of this account matches this upload_ref, or it expired or was already used';
        }
        $fileName = $files[0]['name'];
        if (strlen($files[0]['contents']) > $maxBytes) {
            return self::tooLarge($maxBytes);
        }

        return @file_put_contents($target, $files[0]['contents']) === false ? 'the shop has no room to receive it' : '';
    }

    /**
     * Fetches a merchant's file without letting the address reach inside.
     *
     * This is a request the shop makes on the strength of a URL typed in a
     * conversation, so it is held to what such a request needs: https only,
     * an address that resolves to the public internet - checked after
     * resolution, then pinned so a second lookup cannot answer differently -
     * no redirect, no proxy, and a size cap enforced while reading.
     *
     * @param string $url
     * @param string $target
     * @param int $maxBytes the largest file accepted
     *
     * @return string empty on success, the reason otherwise
     */
    public static function fromUrl(string $url, string $target, int $maxBytes = self::IMAGE_MAX_BYTES): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || Tools::strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            return 'only public https addresses are accepted';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'an address carrying credentials is refused';
        }
        if ((int) ($parts['port'] ?? 443) !== 443) {
            return 'only the standard https port is accepted';
        }
        if (!function_exists('curl_init')) {
            return 'this shop has no cURL to download with';
        }

        $host = Tools::strtolower(trim((string) $parts['host'], '[]'));
        $addresses = self::addresses($host);
        if ($addresses === []) {
            return 'the host does not resolve';
        }
        foreach ($addresses as $address) {
            if (!self::isPublic($address)) {
                return 'the host resolves to a private, local or reserved address';
            }
        }

        $handle = fopen($target, 'wb');
        if ($handle === false) {
            return 'the shop has no room to receive it';
        }
        $size = 0;
        $tooLarge = false;
        $pinned = strpos($addresses[0], ':') !== false ? '[' . $addresses[0] . ']' : $addresses[0];

        $curl = curl_init();
        if ($curl === false) {
            fclose($handle);

            return 'this shop could not start a download';
        }
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => [$host . ':443:' . $pinned],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            // A proxy would resolve the host itself, out of reach of the check above.
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'PrestaShop somcp',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($handle, &$size, &$tooLarge, $maxBytes): int {
                $size += strlen($chunk);
                if ($size > $maxBytes) {
                    $tooLarge = true;

                    return 0;
                }

                return (int) fwrite($handle, $chunk);
            },
        ]);
        $done = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        unset($curl);
        fclose($handle);

        if ($tooLarge) {
            return self::tooLarge($maxBytes);
        }
        if ($done === false) {
            return 'the download failed';
        }
        if ($status >= 300 && $status < 400) {
            return 'the address redirects elsewhere, and redirections are not followed; give the final address';
        }
        if ($status !== 200) {
            return 'the address answered HTTP ' . $status;
        }

        return '';
    }

    /**
     * @param string $host
     *
     * @return array<int, string>
     */
    private static function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // A name that does not resolve makes these functions warn; that is an
        // answer here, not an incident worth a line in the PHP log.
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            $addresses = gethostbynamel($host) ?: [];
            if (function_exists('dns_get_record')) {
                foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                    if (isset($record['ipv6'])) {
                        $addresses[] = (string) $record['ipv6'];
                    }
                }
            }
        } finally {
            restore_error_handler();
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Whether an address is on the public internet.
     *
     * PHP's own flags cover the private and reserved ranges; the list below
     * adds what they leave out and what can still lead inside - shared address
     * space, benchmarking, documentation, multicast, and IPv6 forms that carry
     * an IPv4 address.
     *
     * @param string $address
     *
     * @return bool
     */
    private static function isPublic(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        // ::ffff:a.b.c.d and the NAT64 prefix both end in an IPv4 address,
        // which is the one actually reached.
        if (strlen($packed) === 16
            && (strncmp($packed, str_repeat("\0", 10) . "\xff\xff", 12) === 0 || strncmp($packed, "\x00\x64\xff\x9b" . str_repeat("\0", 8), 12) === 0)) {
            return self::isPublic((string) inet_ntop(substr($packed, 12)));
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $blocked = strlen($packed) === 4
            ? ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3']
            : ['::/8', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8'];
        foreach ($blocked as $range) {
            [$network, $bits] = explode('/', $range);
            $base = (string) inet_pton($network);
            $bytes = intdiv((int) $bits, 8);
            $rest = (int) $bits % 8;
            if (strncmp($packed, $base, $bytes) !== 0) {
                continue;
            }
            if ($rest === 0 || ((ord($packed[$bytes]) ^ ord($base[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param int $maxBytes
     *
     * @return string
     */
    public static function tooLarge(int $maxBytes): string
    {
        return 'the file is larger than ' . rtrim(rtrim(number_format($maxBytes / 1048576, 1, '.', ''), '0'), '.') . ' MB';
    }

    /**
     * Opens an invitation and returns its reference.
     *
     * @param int $idEmployee the account the assistant acts as
     * @param string $purpose what the files are for, shown to the merchant
     * @param string|array<int, string> $accept a KINDS shorthand or a list of extensions
     * @param string $module folder of the module asking, empty for this one
     * @param array{files?:int, bytes?:int} $limits
     * @param int $ttl seconds the invitation and its files are kept
     *
     * @return string the reference, or an empty string when refused
     */
    public static function invite(
        int $idEmployee,
        string $purpose,
        $accept,
        string $module = '',
        array $limits = [],
        int $ttl = self::TTL,
    ): string {
        $extensions = self::resolve($accept);

        if ($idEmployee <= 0 || $extensions === [] || !SomcpPermission::employeeIsUsable($idEmployee)) {
            return '';
        }

        self::purge();

        $reference = SomcpRandom::token(24);
        $stored = Db::getInstance()->insert('somcp_upload', [
            'reference_hash' => pSQL(SomcpRandom::hash($reference)),
            'id_shop' => Somcp::currentShopId(),
            'id_employee' => $idEmployee,
            'module' => pSQL(Tools::substr($module, 0, 64)),
            'extensions' => pSQL(implode(' ', $extensions)),
            'purpose' => pSQL(Tools::substr($purpose, 0, 255)),
            'max_files' => self::clampFiles($limits['files'] ?? 1),
            'max_bytes' => self::clampBytes($limits['bytes'] ?? self::DEFAULT_BYTES),
            'expires_at' => pSQL(date('Y-m-d H:i:s', time() + max(60, $ttl))),
        ]);

        if (!$stored) {
            SomcpLogger::log('upload_invite_failed', SomcpLogger::OUTCOME_FAILURE, $module, '', $idEmployee);

            return '';
        }

        SomcpLogger::log(
            'upload_invited',
            SomcpLogger::OUTCOME_INFO,
            implode(' ', $extensions) . ' for ' . ($module ?: 'somcp'),
            '',
            $idEmployee
        );

        return $reference;
    }

    /**
     * The extensions a caller is asking for, kept to what the shop accepts.
     *
     * An unknown extension is dropped rather than refusing the whole request,
     * but a request left with nothing at all is refused: a page accepting no
     * format is a dead end the merchant would have to work out for themselves.
     *
     * @param string|array<int, string> $accept
     *
     * @return array<int, string>
     */
    private static function resolve($accept): array
    {
        $asked = is_array($accept) ? $accept : (self::KINDS[$accept] ?? []);

        $extensions = [];
        foreach ($asked as $extension) {
            $extension = Tools::strtolower(trim((string) $extension));
            if (isset(self::ACCEPTED[$extension]) && !in_array($extension, $extensions, true)) {
                $extensions[] = $extension;
            }
        }

        return $extensions;
    }

    /**
     * @param mixed $files
     *
     * @return int
     */
    private static function clampFiles($files): int
    {
        return max(1, min(self::MAX_FILES, (int) $files));
    }

    /**
     * A size the shop can actually receive.
     *
     * PHP refuses or truncates beyond upload_max_filesize and post_max_size,
     * and it does so without telling the merchant anything useful. So a tool
     * asking for more than the hosting allows is quietly brought back down to
     * what will work, rather than promising a size the page cannot honour.
     *
     * @param mixed $bytes
     *
     * @return int
     */
    private static function clampBytes($bytes): int
    {
        return max(1, min(self::ceiling(), (int) $bytes));
    }

    /**
     * The largest file this installation can receive, whatever anyone asks.
     *
     * @return int
     */
    public static function ceiling(): int
    {
        $limits = [self::MAX_BYTES, self::postCeiling()];

        $upload = self::toBytes((string) ini_get('upload_max_filesize'));
        if ($upload > 0) {
            $limits[] = $upload;
        }

        return (int) min($limits);
    }

    /**
     * The most one POST may carry in total, whatever it holds.
     *
     * Separate from ceiling(), and it matters as soon as a tool asks for
     * several files: post_max_size caps the whole request, not each part. A
     * browser sending three files that each pass the per-file cap can still
     * blow this one, and PHP then discards the entire body - no $_POST, no
     * $_FILES, no error. The page would look like nothing had happened.
     *
     * @return int
     */
    public static function postCeiling(): int
    {
        $value = self::toBytes((string) ini_get('post_max_size'));
        $value = $value > 0 ? $value : self::MAX_BYTES;

        // Room for the envelope. post_max_size caps the whole body, and the
        // body is not only the files: every part carries a boundary, a
        // disposition header and a field name, and the hidden fields ride
        // along too. A selection measured at exactly the limit is refused on
        // arrival, which is the one refusal the merchant cannot act on.
        return max(1, $value - self::ENVELOPE);
    }

    /**
     * Whether the request that just arrived was thrown away for being too big.
     *
     * PHP drops an oversized body before any script runs and leaves nothing
     * behind but the declared length, so this is the only way to tell that
     * case apart from a merchant who pressed the button with no file.
     *
     * @return bool
     */
    public static function postWasDropped(): bool
    {
        $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        return $declared > 0
            && $_POST === []
            && Tools::strtolower((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'post'
            && $declared > self::postCeiling();
    }

    /**
     * One of PHP's shorthand sizes - "8M", "512K" - as a number of bytes.
     *
     * @param string $value
     *
     * @return int 0 when the setting is empty or unlimited
     */
    private static function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1' || $value === '0') {
            return 0;
        }

        $number = (int) $value;
        switch (Tools::strtolower(Tools::substr($value, -1))) {
            case 'g':
                return $number * 1073741824;
            case 'm':
                return $number * 1048576;
            case 'k':
                return $number * 1024;
            default:
                return $number;
        }
    }

    /**
     * The invitation behind a reference, for the employee it was opened for.
     *
     * The shop is deliberately not part of this lookup. On a multistore
     * installation the back office has a shop in context that has nothing to do
     * with the one the assistant is connected to, and filtering here would have
     * the deposit page tell the merchant their own invitation does not exist.
     * The shop is checked where it means something - at redemption, on the same
     * request that opened the invitation, and on the page against the employee.
     *
     * @param string $reference
     * @param int $idEmployee
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $reference, int $idEmployee): ?array
    {
        if ($reference === '' || $idEmployee <= 0) {
            return null;
        }

        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . "somcp_upload`
             WHERE `reference_hash` = '" . pSQL(SomcpRandom::hash($reference)) . "'
               AND `id_employee` = " . $idEmployee . "
               AND `expires_at` > '" . pSQL(date('Y-m-d H:i:s')) . "'"
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * The files already deposited against one invitation.
     *
     * @param int $idUpload
     *
     * @return array<int, array<string, mixed>>
     */
    public static function deposited(int $idUpload): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'somcp_upload_file`
             WHERE `id_upload` = ' . $idUpload . '
             ORDER BY `id_upload_file` ASC'
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Accepts one file posted to the page or to the direct address.
     *
     * Called from the back office, where the employee is the one signed in and
     * the caller has checked that the row is theirs, or from the direct
     * address, where the signed link names the row and nothing else. Never
     * from a token.
     *
     * @param array<string, mixed> $invitation the row from find()
     * @param array<string, mixed> $file one entry of $_FILES
     *
     * @return string an empty string on success, otherwise why it was refused
     */
    public static function accept(array $invitation, array $file): string
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return count(self::deposited((int) $invitation['id_upload'])) >= (int) $invitation['max_files'] ? 'too_many' : 'no_file';
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return 'no_file';
        }

        return self::admit($invitation, (string) ($file['name'] ?? ''), $tmp, true);
    }

    /**
     * Keeps a file against an invitation, whichever way it arrived.
     *
     * The one place a file is let in: the page of the back office, the
     * address an assistant posts to and the address the shop downloads all
     * end here, so the count, the size, the type and the content are checked
     * the same way for each. A door with checks of its own would be the one
     * that forgets one.
     *
     * @param array<string, mixed> $invitation the row from find()
     * @param string $name the name the file was sent under
     * @param string $path where it is now
     * @param bool $uploaded true for a file PHP received in this request, moved
     *                       with move_uploaded_file(); false for one the shop
     *                       downloaded itself, which is renamed
     *
     * @return string empty when kept, otherwise too_many, too_large, wrong_type or no_store
     */
    private static function admit(array $invitation, string $name, string $path, bool $uploaded): string
    {
        $idUpload = (int) $invitation['id_upload'];

        if (count(self::deposited($idUpload)) >= (int) $invitation['max_files']) {
            return 'too_many';
        }

        $size = (int) @filesize($path);
        if ($size <= 0 || $size > (int) $invitation['max_bytes']) {
            return 'too_large';
        }

        $extension = Tools::strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $allowed = explode(' ', (string) $invitation['extensions']);
        if (!in_array($extension, $allowed, true) || !isset(self::ACCEPTED[$extension])) {
            return 'wrong_type';
        }

        if (!self::looksLike($path, $extension)) {
            return 'wrong_type';
        }

        $directory = self::directory();
        if ($directory === '') {
            return 'no_store';
        }

        // The name on disk is ours, not the uploader's: a file name is
        // attacker-controlled text and has no business reaching a filesystem.
        $storedAs = SomcpRandom::token(16) . '.' . $extension;
        $moved = $uploaded ? @move_uploaded_file($path, $directory . $storedAs) : @rename($path, $directory . $storedAs);
        if (!$moved) {
            return 'no_store';
        }
        @chmod($directory . $storedAs, 0640);

        Db::getInstance()->insert('somcp_upload_file', [
            'id_upload' => $idUpload,
            'file_name' => pSQL(Tools::substr($name, 0, 255)),
            'mime_type' => pSQL(self::ACCEPTED[$extension]['mime']),
            'stored_as' => pSQL($storedAs),
            'file_size' => $size,
        ]);

        SomcpLogger::log(
            'upload_received',
            SomcpLogger::OUTCOME_SUCCESS,
            $extension . ', ' . $size . ' bytes',
            '',
            (int) $invitation['id_employee']
        );

        return '';
    }

    /**
     * Downloads a file from a public address into an invitation.
     *
     * The third way in, for an assistant that has an address rather than the
     * bytes: the shop fetches it with the guards of fromUrl(), then admit()
     * judges it exactly as a file posted to the page. The name is taken from
     * the end of the address, since its extension is what admit() checks
     * against the content.
     *
     * @param array<string, mixed> $invitation the row from find()
     * @param string $url
     *
     * @return string empty when kept, the reason otherwise, worded for an assistant
     */
    public static function depositUrl(array $invitation, string $url): string
    {
        $name = basename(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
        $directory = self::directory();
        if ($directory === '') {
            return self::refusal('no_store', $invitation);
        }

        $temporary = $directory . SomcpRandom::token(16) . '.part';
        try {
            $refusal = self::fromUrl($url, $temporary, (int) $invitation['max_bytes']);
            if ($refusal !== '') {
                return $refusal;
            }
            $problem = self::admit($invitation, $name, $temporary, false);

            return $problem === '' ? '' : self::refusal($problem, $invitation);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Hands the deposited files to the tool that asked for them, once.
     *
     * The rows go first, then the bytes are read: a reference presented twice
     * finds nothing the second time. That is the single-use guarantee the
     * protocol says has to live server-side, and it lives with the thing being
     * redeemed rather than in the state that names it.
     *
     * @param string $reference
     * @param int $idEmployee
     *
     * @return array<int, array{name:string, mimeType:string, size:int, contents:string}>
     */
    public static function redeem(string $reference, int $idEmployee): array
    {
        $row = self::find($reference, $idEmployee);
        if ($row === null || (int) $row['id_shop'] !== Somcp::currentShopId()) {
            return [];
        }

        $deposited = self::deposited((int) $row['id_upload']);
        if ($deposited === []) {
            return [];
        }

        self::forget((int) $row['id_upload']);

        $directory = self::directory();
        $taken = [];
        foreach ($deposited as $file) {
            $path = $directory . (string) $file['stored_as'];
            $contents = @file_get_contents($path);
            @unlink($path);

            if ($contents === false) {
                continue;
            }

            $taken[] = [
                'name' => (string) $file['file_name'],
                'mimeType' => (string) $file['mime_type'],
                'size' => (int) $file['file_size'],
                'contents' => $contents,
            ];
        }

        return $taken;
    }

    /**
     * The first file of a deposit, read without taking it.
     *
     * For the built-in assistant, which shows an attached file to the model on
     * every turn of the conversation and must not use it up doing so.
     *
     * @param string $reference
     * @param int $idEmployee
     *
     * @return array{name:string, mimeType:string, size:int, contents:string}|null
     */
    public static function peek(string $reference, int $idEmployee): ?array
    {
        $row = self::find($reference, $idEmployee);
        $file = $row !== null ? (self::deposited((int) $row['id_upload'])[0] ?? null) : null;
        if ($file === null) {
            return null;
        }

        $contents = @file_get_contents(self::directory() . (string) $file['stored_as']);

        return $contents === false ? null : [
            'name' => (string) $file['file_name'],
            'mimeType' => (string) $file['mime_type'],
            'size' => (int) $file['file_size'],
            'contents' => $contents,
        ];
    }

    /**
     * The file a signed link points at: a deposit of the built-in assistant,
     * found by the hash of its reference, which is what the link carries.
     *
     * The reference itself never goes into a link. It redeems the file for a
     * tool, and a link is read by the provider's servers and may end up in
     * their logs; the hash only lets a file be looked at.
     *
     * @param string $referenceHash
     *
     * @return array{name:string, mimeType:string, path:string}|null
     */
    public static function linked(string $referenceHash): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $referenceHash)) {
            return null;
        }

        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . "somcp_upload`
             WHERE `reference_hash` = '" . pSQL($referenceHash) . "'
               AND `module` = '" . pSQL(self::CHAT_MODULE) . "'
               AND `expires_at` > '" . pSQL(date('Y-m-d H:i:s')) . "'"
        );
        $file = is_array($row) && $row !== [] ? (self::deposited((int) $row['id_upload'])[0] ?? null) : null;
        if ($file === null) {
            return null;
        }

        $path = self::directory() . (string) $file['stored_as'];

        return is_file($path) ? [
            'name' => (string) $file['file_name'],
            'mimeType' => (string) $file['mime_type'],
            'path' => $path,
        ] : null;
    }

    /**
     * Keeps a file the shop received itself - an image a provider generated
     * for the built-in assistant - as a deposit of an employee.
     *
     * Same store, same formats, same check of the bytes against the
     * extension as a file a merchant deposits: a provider's answer is not
     * trusted more than an upload.
     *
     * @param int $idEmployee
     * @param string $name shown to the merchant and to the model
     * @param string $bytes
     * @param string $extension one of ACCEPTED
     * @param string $module
     * @param int $ttl
     *
     * @return string the reference, empty when refused
     */
    public static function keep(int $idEmployee, string $name, string $bytes, string $extension, string $module, int $ttl): string
    {
        $extension = Tools::strtolower($extension);
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES || !isset(self::ACCEPTED[$extension])) {
            return '';
        }

        $reference = self::invite($idEmployee, $name, [$extension], $module, ['files' => 1, 'bytes' => strlen($bytes)], $ttl);
        $invitation = $reference !== '' ? self::find($reference, $idEmployee) : null;
        $directory = self::directory();
        if ($invitation === null || $directory === '') {
            return '';
        }

        $storedAs = SomcpRandom::token(16) . '.' . $extension;
        if (@file_put_contents($directory . $storedAs, $bytes) === false || !self::looksLike($directory . $storedAs, $extension)) {
            @unlink($directory . $storedAs);
            self::forget((int) $invitation['id_upload']);

            return '';
        }
        @chmod($directory . $storedAs, 0640);

        Db::getInstance()->insert('somcp_upload_file', [
            'id_upload' => (int) $invitation['id_upload'],
            'file_name' => pSQL(Tools::substr($name, 0, 255)),
            'mime_type' => pSQL(self::ACCEPTED[$extension]['mime']),
            'stored_as' => pSQL($storedAs),
            'file_size' => strlen($bytes),
        ]);

        return $reference;
    }

    /**
     * A single-use copy of a deposit, for a tool.
     *
     * A tool redeems what it is given, which deletes it: right for a file
     * deposited for that one call, wrong for a file attached in the built-in
     * assistant, which the conversation still shows. The tool is handed a copy
     * under a new reference instead, so both keep working.
     *
     * @param string $reference a deposit of this employee
     * @param int $idEmployee
     *
     * @return string the reference of the copy, empty when there is nothing to copy
     */
    public static function copyForTool(string $reference, int $idEmployee): string
    {
        $row = self::find($reference, $idEmployee);
        if ($row === null) {
            return '';
        }

        $copy = self::invite(
            $idEmployee,
            (string) $row['purpose'],
            explode(' ', (string) $row['extensions']),
            (string) $row['module'],
            ['files' => (int) $row['max_files'], 'bytes' => (int) $row['max_bytes']]
        );
        $copied = self::find($copy, $idEmployee);
        if ($copied === null) {
            return '';
        }

        $directory = self::directory();
        foreach (self::deposited((int) $row['id_upload']) as $file) {
            $storedAs = SomcpRandom::token(16) . '.' . Tools::strtolower((string) pathinfo((string) $file['stored_as'], PATHINFO_EXTENSION));
            if (!@copy($directory . (string) $file['stored_as'], $directory . $storedAs)) {
                continue;
            }
            @chmod($directory . $storedAs, 0640);
            Db::getInstance()->insert('somcp_upload_file', [
                'id_upload' => (int) $copied['id_upload'],
                'file_name' => pSQL((string) $file['file_name']),
                'mime_type' => pSQL((string) $file['mime_type']),
                'stored_as' => pSQL($storedAs),
                'file_size' => (int) $file['file_size'],
            ]);
        }

        return $copy;
    }

    /**
     * Drops an invitation and whatever was deposited against it.
     *
     * @param array<string, mixed> $invitation
     */
    public static function drop(array $invitation): void
    {
        $idUpload = (int) $invitation['id_upload'];
        $directory = self::directory();

        foreach (self::deposited($idUpload) as $file) {
            @unlink($directory . (string) $file['stored_as']);
        }

        self::forget($idUpload);
    }

    /**
     * Removes one deposited file, so the merchant can replace a wrong one.
     *
     * @param array<string, mixed> $invitation
     * @param int $idFile
     *
     * @return bool
     */
    public static function remove(array $invitation, int $idFile): bool
    {
        foreach (self::deposited((int) $invitation['id_upload']) as $file) {
            if ((int) $file['id_upload_file'] !== $idFile) {
                continue;
            }

            @unlink(self::directory() . (string) $file['stored_as']);
            Db::getInstance()->delete('somcp_upload_file', '`id_upload_file` = ' . $idFile);

            return true;
        }

        return false;
    }

    /**
     * Clears what has run out, rows and files together.
     *
     * Called when a new invitation is opened, and again whenever the merchant
     * opens the module settings - otherwise an installation where nobody asks
     * for a file again would keep the last expired one on disk for ever.
     */
    public static function purge(): void
    {
        $now = pSQL(date('Y-m-d H:i:s'));
        $expired = Db::getInstance()->executeS(
            'SELECT `id_upload` FROM `' . _DB_PREFIX_ . "somcp_upload` WHERE `expires_at` <= '" . $now . "'"
        );

        $directory = self::directory();
        foreach (is_array($expired) ? $expired : [] as $row) {
            foreach (self::deposited((int) $row['id_upload']) as $file) {
                @unlink($directory . (string) $file['stored_as']);
            }
            self::forget((int) $row['id_upload']);
        }
    }

    /**
     * Removes an invitation and its file rows.
     *
     * @param int $idUpload
     */
    private static function forget(int $idUpload): void
    {
        Db::getInstance()->delete('somcp_upload_file', '`id_upload` = ' . $idUpload);
        Db::getInstance()->delete('somcp_upload', '`id_upload` = ' . $idUpload);
    }

    /**
     * Removes every deposit and the folder itself. Called on uninstall.
     *
     * Files a merchant deposited are theirs, but they were deposited for one
     * call that is long over: leaving them behind after the module goes would
     * be leaving data nobody can reach and nobody will remember to delete.
     */
    public static function removeStore(): void
    {
        $directory = rtrim((string) _PS_ROOT_DIR_, '/\\') . '/' . self::STORE;
        if (!is_dir($directory)) {
            return;
        }

        $entries = @scandir($directory);
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($directory . $entry)) {
                @unlink($directory . $entry);
            }
        }

        @rmdir($directory);
        // The module's own folder under var/, left behind by the line above.
        // rmdir refuses a directory that is not empty, so another module's
        // data there would stop this and that is the right outcome.
        @rmdir(dirname($directory));
    }

    /**
     * The shorthands a tool can ask for.
     *
     * @return array<int, string>
     */
    public static function kinds(): array
    {
        return array_keys(self::KINDS);
    }

    /**
     * Every extension the shop will store, for documentation and the settings page.
     *
     * @return array<int, string>
     */
    public static function accepted(): array
    {
        return array_keys(self::ACCEPTED);
    }

    /**
     * The folder deposits live in, created and closed off on first use.
     *
     * @return string an absolute path with a trailing slash, or an empty string
     */
    private static function directory(): string
    {
        $directory = rtrim((string) _PS_ROOT_DIR_, '/\\') . '/' . self::STORE;

        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            return '';
        }

        // Belt and braces against a server that would serve the folder: the
        // files are not meant to be reachable over HTTP at all.
        if (!is_file($directory . '.htaccess')) {
            @file_put_contents($directory . '.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!is_file($directory . 'index.php')) {
            @file_put_contents($directory . 'index.php', "<?php\nheader('HTTP/1.0 404 Not Found');\n");
        }

        return $directory;
    }

    /**
     * Whether the bytes of a file match the extension it was given.
     *
     * Read here rather than handed to finfo, for two reasons. finfo is an
     * extension a shop may not have, and this has to work on every shop.
     * And for the formats that matter it is the weaker check: it reports
     * `text/plain` for a CSV, an XML and a JSON alike, so agreeing with it
     * would mean accepting any of the three under any of those names.
     *
     * @param string $path
     * @param string $extension already known to belong to the invitation
     *
     * @return bool
     */
    private static function looksLike(string $path, string $extension): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 8192);
        if ($head === '') {
            return false;
        }

        switch (self::ACCEPTED[$extension]['check']) {
            case 'image':
                // Reads the real dimensions and format out of the header, so a
                // script renamed .png does not get through.
                $image = @getimagesize($path);

                return is_array($image) && in_array((int) $image[2], self::imageTypesOf($extension), true);

            case 'bmff':
                // ISO base media: a four-byte length, then "ftyp", then the
                // brand that says which of the family it is.
                return Tools::substr($head, 4, 4) === 'ftyp'
                    && in_array(
                        Tools::substr($head, 8, 4),
                        ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1', 'heif', 'avif', 'avis'],
                        true
                    );

            case 'pdf':
                return strncmp($head, '%PDF-', 5) === 0;

            case 'zip':
                // Office and OpenDocument files are zip archives. An empty
                // archive has a different signature and is of no use here.
                return strncmp($head, "PK\x03\x04", 4) === 0;

            case 'ole2':
                return strncmp($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0;

            case 'rtf':
                return strncmp($head, '{\\rtf', 5) === 0;

            case 'xml':
                return self::isText($head) && Tools::substr(self::trimmed($head), 0, 1) === '<';

            case 'json':
                $contents = @file_get_contents($path);

                return self::isText($head) && $contents !== false && json_decode($contents) !== null;

            default:
                // Plain text and CSV: being text is as far as a format this
                // loose can be checked.
                return self::isText($head);
        }
    }

    /**
     * What getimagesize must report for an extension to be believed.
     *
     * A list rather than one value, because TIFF comes in two byte orders and
     * the core reports each with its own constant.
     *
     * @param string $extension
     *
     * @return array<int, int>
     */
    private static function imageTypesOf(string $extension): array
    {
        switch ($extension) {
            case 'jpg':
            case 'jpeg':
                return [IMAGETYPE_JPEG];
            case 'png':
                return [IMAGETYPE_PNG];
            case 'gif':
                return [IMAGETYPE_GIF];
            case 'webp':
                return [IMAGETYPE_WEBP];
            case 'bmp':
                return [IMAGETYPE_BMP];
            default:
                return [IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM];
        }
    }

    /**
     * @param string $head
     *
     * @return bool
     */
    private static function isText(string $head): bool
    {
        // A NUL byte says it is not.
        return strpos($head, "\0") === false;
    }

    /**
     * @param string $head
     *
     * @return string
     */
    private static function trimmed(string $head): string
    {
        return ltrim($head, " \t\r\n\xEF\xBB\xBF");
    }
}
