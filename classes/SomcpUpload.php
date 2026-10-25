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
     * arrive. So it says what to show, what to wait for, and exactly how to
     * come back - an assistant told only that something went wrong will
     * apologise and stop.
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
        $files = max(1, (int) ($invitation['max_files'] ?? 1));
        $megabytes = (int) round(((int) ($invitation['max_bytes'] ?? self::DEFAULT_BYTES)) / 1048576);

        return SomcpTool::text(
            ($files === 1 ? 'A file is' : 'Up to ' . $files . ' files are')
            . ' needed, and they cannot travel through this conversation.'
            . ' Show the merchant this address and ask them to open it and deposit'
            . ($files === 1 ? ' the file' : ' them') . ':' . "\n\n"
            . $url . "\n\n"
            . 'Accepted: ' . str_replace(' ', ', ', (string) ($invitation['extensions'] ?? ''))
            . '. Up to ' . $megabytes . ' MB each.'
            . ' The address stops working in ' . (int) round(self::TTL / 60) . ' minutes.' . "\n\n"
            . 'Wait for the merchant to say the files have been sent, then call this same tool again'
            . ' with the same arguments plus ' . self::ARG_REF . ' set to: ' . $reference . "\n\n"
            . 'Do not report this as a failure, and do not try to send the files any other way.'
        );
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
     * Accepts one file the merchant chose.
     *
     * Called from the back office, where the employee is the one signed in -
     * never from a token. The row already says which employee may deposit, and
     * the caller has checked that it is this one.
     *
     * @param array<string, mixed> $invitation the row from find()
     * @param array<string, mixed> $file one entry of $_FILES
     *
     * @return string an empty string on success, otherwise why it was refused
     */
    public static function accept(array $invitation, array $file): string
    {
        $idUpload = (int) $invitation['id_upload'];

        if (count(self::deposited($idUpload)) >= (int) $invitation['max_files']) {
            return 'too_many';
        }

        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return 'no_file';
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return 'no_file';
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > (int) $invitation['max_bytes']) {
            return 'too_large';
        }

        $name = (string) ($file['name'] ?? '');
        $extension = Tools::strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $allowed = explode(' ', (string) $invitation['extensions']);
        if (!in_array($extension, $allowed, true) || !isset(self::ACCEPTED[$extension])) {
            return 'wrong_type';
        }

        if (!self::looksLike($tmp, $extension)) {
            return 'wrong_type';
        }

        $directory = self::directory();
        if ($directory === '') {
            return 'no_store';
        }

        // The name on disk is ours, not the uploader's: a file name is
        // attacker-controlled text and has no business reaching a filesystem.
        $storedAs = SomcpRandom::token(16) . '.' . $extension;
        if (!@move_uploaded_file($tmp, $directory . $storedAs)) {
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
