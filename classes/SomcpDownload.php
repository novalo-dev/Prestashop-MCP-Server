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
 * SomcpDownload - the one way a file leaves the shop through an assistant,
 * the outgoing counterpart of SomcpUpload.
 *
 * No tool builds the address of a file itself. A tool that finds one - an
 * invoice, a product photo, a brand logo, an attachment - answers with a
 * download_ref, and somcp_download_file turns that reference into the file.
 * A tool that handed out its own address would decide on its own who may have
 * the file and how it travels; here that is decided once, for every file.
 *
 * The reference names the file by its kind and its identifier, never by a
 * path, and carries its expiry and a signature. Who it was made for and which
 * shop it belongs to are not in it: they are folded into the signature, so a
 * reference presented by another account or on another shop simply fails.
 *
 * Who may have a file is the business of the tool that reads it (KINDS): the
 * scopes and back office rights of somcp_get_product for a product photo, of
 * somcp_get_order_document for an invoice. Read from that tool's own
 * declaration rather than written again here, so the two can never disagree.
 *
 * The file reaches the assistant inside the answer of somcp_download_file
 * when it is small enough to travel there, at a signed address it fetches
 * itself, and for the merchant on a hidden back office page that also
 * requires the back office session of that same employee. Nothing is copied
 * to build a document: a PDF is rendered on demand from the core PDF class,
 * any other file is read where the shop keeps it.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpDownload
{
    /** @var int How long a link stays valid, in seconds: 15 minutes, as a deposit. */
    public const TTL = SomcpUpload::TTL;

    /**
     * @var array<string, array<string, string>> The kinds a reference may name.
     *
     * reader is the tool that reads this kind of file, whose scopes and back
     * office rights decide who may have it. pdf, for a document of an order,
     * is the core PDF template it is rendered with; every other kind is a file
     * the shop already keeps (stored()). A new kind of file is added here and
     * in stored(), and nowhere else.
     */
    public const KINDS = [
        'invoice' => ['reader' => 'somcp_get_order_document', 'pdf' => 'TEMPLATE_INVOICE'],
        'delivery_slip' => ['reader' => 'somcp_get_order_document', 'pdf' => 'TEMPLATE_DELIVERY_SLIP'],
        'credit_slip' => ['reader' => 'somcp_get_order_document', 'pdf' => 'TEMPLATE_ORDER_SLIP'],
        'product_image' => ['reader' => 'somcp_get_product'],
        'product_file' => ['reader' => 'somcp_get_product'],
        'attachment' => ['reader' => 'somcp_list_attachments'],
        'manufacturer_logo' => ['reader' => 'somcp_get_manufacturer'],
        'supplier_logo' => ['reader' => 'somcp_get_supplier'],
        'attribute_texture' => ['reader' => 'somcp_get_attribute_value'],
        'order_state_icon' => ['reader' => 'somcp_list_order_statuses'],
        'carrier_logo' => ['reader' => 'somcp_list_carriers'],
        'category_image' => ['reader' => 'somcp_get_category'],
        'category_thumbnail' => ['reader' => 'somcp_get_category'],
        'shop_logo' => ['reader' => 'somcp_get_theme'],
    ];

    /**
     * @var array<string, string> The logos of a shop, by the name somcp_get_theme gives them, and their setting.
     *
     * Kept here rather than in the tool, since the file behind a shop_logo
     * reference is found from it when the tool is not loaded.
     */
    public const SHOP_LOGOS = [
        'header' => 'PS_LOGO',
        'mail' => 'PS_LOGO_MAIL',
        'invoice' => 'PS_LOGO_INVOICE',
        'favicon' => 'PS_FAVICON',
    ];

    /**
     * @var int The largest file handed over inside a tool answer: 4 MiB.
     *
     * Encoded in base64 it grows by a third, and every client sets its own
     * limit on an answer. Past this, the address and the page are given
     * instead of the assistant getting nothing.
     */
    public const INLINE_MAX = 4194304;

    /** @var string Argument that carries a reference to somcp_download_file. */
    public const ARG_REF = 'download_ref';

    /** @var int The account of the tool call running, the one a reference is made for. */
    private static $caller = 0;

    /**
     * Runs a tool call with the account its references are made for.
     *
     * @template T
     *
     * @param int $idEmployee
     * @param callable(): T $call
     *
     * @return T
     */
    public static function asCaller(int $idEmployee, callable $call)
    {
        // Put back afterwards: a runner of compact mode runs the tool it names
        // inside its own call, for the same account.
        $previous = self::$caller;
        self::$caller = $idEmployee;
        try {
            return $call();
        } finally {
            self::$caller = $previous;
        }
    }

    /**
     * What a tool answers for a file: its reference, or null when there is
     * no file. The one call a tool makes for every file it finds.
     *
     * Made for the account of the tool call running (asCaller()); outside a
     * call it is made for no account, and no one can use it. The reference
     * alone, without its expiry: an update given back a file as its get
     * returned it reads that object, and every reference of one answer
     * expires 15 minutes later, as the tools say.
     *
     * @param string $kind one of KINDS
     * @param int|string $id the file identifier, as the kind reads it
     * @param int $idShop the shop the file belongs to
     *
     * @return array{download_ref:string}|null
     */
    public static function offer(string $kind, $id, int $idShop): ?array
    {
        if (!self::exists($kind, (string) $id)) {
            return null;
        }
        $reference = self::reference($kind, $id, self::$caller, $idShop);

        return [self::ARG_REF => $reference[self::ARG_REF]];
    }

    /**
     * A reference to one file, for one employee on one shop.
     *
     * kind.id.expiry.signature: readable enough for an assistant to copy
     * without mistakes, and meaningless to anyone else.
     *
     * @param string $kind one of KINDS
     * @param int|string $id the file identifier
     * @param int $idEmployee the account the reference is handed to
     * @param int $idShop the shop the file belongs to
     *
     * @return array{download_ref:string, expires_at:string}
     */
    public static function reference(string $kind, $id, int $idEmployee, int $idShop): array
    {
        $id = (string) $id;
        $expires = time() + self::TTL;

        return [
            self::ARG_REF => implode('.', [$kind, $id, (string) $expires, self::signature($kind, $id, $idEmployee, $idShop, $expires)]),
            'expires_at' => date('Y-m-d H:i:s', $expires),
        ];
    }

    /**
     * The file a reference points at, read back into a link's query.
     *
     * @param string $reference
     *
     * @return array{kind:string, id:string, e:int, s:string}
     */
    public static function query(string $reference): array
    {
        $parts = explode('.', trim($reference));
        $whole = count($parts) === 4;

        return [
            'kind' => $whole ? $parts[0] : '',
            'id' => $whole ? $parts[1] : '',
            'e' => $whole ? (int) $parts[2] : 0,
            's' => $whole ? $parts[3] : '',
        ];
    }

    /**
     * Whether a value given back to an update names the file of that kind
     * and identifier, so keeping it changes nothing.
     *
     * Only the kind and the identifier are read, not the signature: the
     * answer is "leave the file as it is", which hands nothing out and lets
     * nothing in, so a forged reference gains nothing a true one would not.
     * An expired reference still names its file.
     *
     * @param string $reference
     * @param string $kind
     * @param string $id
     *
     * @return bool
     */
    public static function names(string $reference, string $kind, string $id): bool
    {
        $query = self::query($reference);

        return $query['kind'] === $kind && $query['id'] === $id;
    }

    /**
     * Whether an account may have files of a kind: when it may run the tool
     * that reads them.
     *
     * The tool's own switch is left aside: the reference was handed out by a
     * tool that was on, and the switches of the built-in assistant and of the
     * connected assistants differ, while the rights are the employee's.
     *
     * @param string $kind one of KINDS
     * @param string|null $scopes the scopes of the connection, or null in the
     *                            back office, where the employee is signed in
     *                            and no token is involved
     * @param int $idEmployee
     *
     * @return bool
     */
    public static function mayHave(string $kind, ?string $scopes, int $idEmployee): bool
    {
        $reader = isset(self::KINDS[$kind]) ? SomcpToolRegistry::getInstance()->get(self::KINDS[$kind]['reader']) : null;
        if ($reader === null) {
            return false;
        }
        if ($scopes !== null) {
            return $reader->isAvailableTo($scopes, $idEmployee);
        }

        return SomcpPermission::employeeHolds($idEmployee, $reader->getRequiredPermissions());
    }

    /**
     * The PDF of a document, ready to render, as the back office builds it.
     *
     * The same calls on both versions: the document is named by its invoice or
     * slip identifier, so it never meets the 9.0.0 redirect that the id_order
     * entry points take. render(false) returns its bytes.
     *
     * @param ObjectModel $object the OrderInvoice or OrderSlip
     * @param string $kind
     * @param string $template a PDF::TEMPLATE_* value
     *
     * @return PDF
     */
    private static function pdf(ObjectModel $object, string $kind, string $template): PDF
    {
        // AdminPdf creates this before rendering; TCPDF writes its working
        // files here and fails silently without it.
        $cache = _PS_CACHE_DIR_ . 'tcpdf/';
        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        // The theme may add invoice lines through this hook, as AdminPdf runs
        // it before rendering an invoice.
        if ($kind === 'invoice') {
            Hook::exec('actionPDFInvoiceRender', ['order_invoice_list' => [$object]]);
        }

        return new PDF($object, $template, Somcp::legacyContext()->smarty);
    }

    /**
     * A signed link to the back office page that serves one file, for one
     * employee on one shop.
     *
     * @param string $kind one of KINDS
     * @param string $id the file identifier
     * @param int $idEmployee the account the link is handed to
     * @param int $idShop the shop the file belongs to
     *
     * @return array{url:string, expires_at:string} empty url when the administration folder is unknown
     */
    public static function link(string $kind, string $id, int $idEmployee, int $idShop): array
    {
        $expires = time() + self::TTL;

        $query = [
            'kind' => $kind,
            'id' => $id,
            'e' => (string) $expires,
            's' => self::signature($kind, $id, $idEmployee, $idShop, $expires),
        ];

        return [
            'url' => SomcpBackOffice::downloadUrl($query, $idEmployee),
            'expires_at' => date('Y-m-d H:i:s', $expires),
        ];
    }

    /**
     * Whether a reference or a link is sound, before anything is looked up.
     *
     * The employee and the shop are not read from the reference: they are the
     * caller and the shop in context, folded back into the signature here. A
     * reference made for another employee or another shop does not verify,
     * which is exactly the check that keeps a leaked one useless.
     *
     * @param array<string, mixed> $query the kind, id, e and s
     * @param int $idEmployee the caller
     * @param int $idShop the shop in context
     *
     * @return string empty when sound, otherwise expired or signature
     */
    public static function check(array $query, int $idEmployee, int $idShop): string
    {
        $kind = (string) ($query['kind'] ?? '');
        $id = (string) ($query['id'] ?? '');
        if (!isset(self::KINDS[$kind]) || preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id) !== 1) {
            return 'signature';
        }

        $expires = (int) ($query['e'] ?? 0);
        $signature = (string) ($query['s'] ?? '');

        if ($expires <= time()) {
            return 'expired';
        }

        // Compared in constant time, against a signature recomputed for the
        // employee asking and the shop in context, as controllers/front/file.php
        // does. A hex shape check first keeps a malformed value out of the
        // comparison.
        $expected = self::signature($kind, $id, $idEmployee, $idShop, $expires);
        if (!preg_match('/^[a-f0-9]{64}$/', $signature) || !hash_equals($expected, $signature)) {
            return 'signature';
        }

        return '';
    }

    /**
     * The file itself, when it exists and belongs to the shop.
     *
     * Called only once check() and mayHave() have passed, so whether a file
     * exists is told to no one who may not have it.
     *
     * @param string $kind one of KINDS
     * @param string $id
     * @param int $idShop
     *
     * @return array{bytes:string, name:string, mime:string}|string the file, or gone
     *                                                              when there is none
     *                                                              or it is not the shop's
     */
    public static function file(string $kind, string $id, int $idShop)
    {
        if (isset(self::KINDS[$kind]['pdf'])) {
            return self::document($kind, (int) $id, $idShop);
        }

        $stored = self::stored($kind, $id, $idShop);
        if ($stored === null) {
            return 'gone';
        }
        $bytes = @file_get_contents($stored['path']);
        if (!is_string($bytes) || $bytes === '') {
            return 'gone';
        }

        // The type from the bytes, never from what was stored beside the
        // file; read here rather than in stored(), which every tool listing a
        // file calls only to know there is one.
        return ['bytes' => $bytes, 'name' => $stored['name'], 'mime' => SomcpUpload::typeOf($stored['path'], $stored['name'])];
    }

    /**
     * The answer that hands a file to the assistant: what somcp_download_file
     * answers, and what a tool of another module answers when it returns a
     * file through the contract. One answer, so a file reaches an assistant
     * the same way whoever found it.
     *
     * Attached, the file is all the answer gives: an address beside it is what
     * an assistant hands the merchant instead of reading the file. The
     * addresses are only for a file that cannot travel here, and for the
     * built-in chat, which passes the model the text of an answer and nothing
     * else.
     *
     * @param array{bytes:string, name:string, mime:string} $file the type read from the bytes
     * @param array<string, mixed> $context of the tool call
     * @param string $kind one of KINDS, for the back office page; empty for a
     *                     file no page of the shop serves
     * @param string $id
     *
     * @return array<string, mixed> a CallToolResult
     */
    public static function handOver(array $file, array $context, string $kind = '', string $id = ''): array
    {
        $idEmployee = (int) ($context['id_employee'] ?? 0);
        $chat = ($context['chat'] ?? false) === true;
        $bytes = $file['bytes'];
        $about = ['file' => $file['name'], 'mime_type' => $file['mime'], 'bytes' => strlen($bytes)];
        $label = $kind !== '' ? $kind . ' ' . $id : $file['name'];

        if (strlen($bytes) > self::INLINE_MAX || $chat) {
            $page = null;
            if ($kind !== '') {
                $link = self::link($kind, $id, $idEmployee, Somcp::currentShopId());
                $page = $link['url'] === '' ? null : $link['url'];
            }
            // The built-in assistant cannot fetch an address itself: it gets
            // the page, or the shared address to give the merchant when no
            // page serves the file.
            $shared = $chat && $page !== null ? null : SomcpUpload::share($idEmployee, $file['name'], $bytes);
            if ($page === null && $shared === null) {
                return SomcpTool::text(
                    'The file cannot be handed over here: it is too large to attach, and the shop could not offer'
                        . ' it at an address.',
                    true
                );
            }

            SomcpLogger::log('download_page', SomcpLogger::OUTCOME_SUCCESS, Tools::substr($label, 0, 120), '', $idEmployee);

            return SomcpTool::json($about + [
                'inside' => false,
                // Read with a GET, no sign-in, until expires_at.
                'url' => $shared['url'] ?? null,
                'expires_at' => $shared['expires_at'] ?? null,
                'page' => $page,
                'then' => $chat
                    ? 'Give the merchant ' . ($page !== null ? 'the page, which opens the file in their own signed-in back office only.' : 'url, which opens the file until expires_at.')
                    : 'The file is too large to hand over here. Fetch it yourself from url when you can make an HTTP'
                        . ' request; give the merchant ' . ($page !== null ? 'the page' : 'url') . ' only when you cannot.',
            ]);
        }

        SomcpLogger::log('download_given', SomcpLogger::OUTCOME_SUCCESS, Tools::substr($label, 0, 120), '', $idEmployee);

        $result = SomcpTool::json($about + [
            'inside' => true,
            'then' => $file['mime'] === 'application/octet-stream'
                // Its bytes do not match the type its name gives
                // (SomcpUpload::typeOf()): handed over all the same, never
                // under a type it is not.
                ? 'The file is attached to this answer, but its content is not the type its name gives: tell the'
                    . ' person so, and do not read it as that type.'
                : 'The file is attached to this answer: read it, or show it to the person. Do not give them a link'
                    . ' instead.',
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
                    'uri' => 'somcp://download/' . ($kind !== '' ? $kind . '/' . $id . '/' : 'file/') . rawurlencode($file['name']),
                    'mimeType' => $file['mime'],
                    'blob' => base64_encode($bytes),
                ],
            ];
        }

        return $result;
    }

    /**
     * Whether there is a file behind a kind and an identifier, for a tool
     * deciding between a reference and null.
     *
     * The shop is not checked here: the tool asking has already read the
     * object on its own shop. A document of an order always exists once its
     * tool found it.
     *
     * @param string $kind
     * @param string $id
     *
     * @return bool
     */
    public static function exists(string $kind, string $id): bool
    {
        if (!isset(self::KINDS[$kind])) {
            return false;
        }
        if (isset(self::KINDS[$kind]['pdf'])) {
            return true;
        }

        return self::stored($kind, $id, null) !== null;
    }

    /**
     * Where the shop keeps a file that is not rendered, and its name.
     *
     * The folders and names are the core's own, the ones its back office
     * screens write. With a shop, the object must be attached to it, as its
     * screen would show it there; without one, only the file is looked for.
     *
     * @param string $kind
     * @param string $id
     * @param int|null $idShop
     *
     * @return array{path:string, name:string}|null
     */
    private static function stored(string $kind, string $id, ?int $idShop): ?array
    {
        $number = (int) $id;
        $name = $kind . '-' . $id;
        $path = '';
        $shopTable = '';
        $shopKey = '';

        switch ($kind) {
            case 'product_image':
                $image = new Image($number);
                if (!Validate::isLoadedObject($image)) {
                    return null;
                }
                $format = (string) ($image->image_format ?: 'jpg');
                // The original the core writes and reads, under the same
                // constant and path its own PDF templates use on 8 and 9.
                $path = _PS_PRODUCT_IMG_DIR_ . $image->getExistingImgPath() . '.' . $format;
                $name .= '.' . $format;
                $shopTable = 'image_shop';
                $shopKey = 'id_image';
                break;
            case 'product_file':
                $file = new ProductDownload($number);
                if (!Validate::isLoadedObject($file) || basename((string) $file->filename) === '') {
                    return null;
                }
                $path = _PS_DOWNLOAD_DIR_ . basename((string) $file->filename);
                $name = (string) $file->display_filename;
                if ($idShop !== null && !self::inShop('product_shop', 'id_product', (int) $file->id_product, $idShop)) {
                    return null;
                }
                break;
            case 'attachment':
                $attachment = new Attachment($number);
                if (!Validate::isLoadedObject($attachment) || basename((string) $attachment->file) === '') {
                    return null;
                }
                // Attachments are shared by every shop, as their screen lists them.
                $path = _PS_DOWNLOAD_DIR_ . basename((string) $attachment->file);
                $name = (string) $attachment->file_name;
                break;
            case 'manufacturer_logo':
                $path = _PS_MANU_IMG_DIR_ . $number . '.jpg';
                $shopTable = 'manufacturer_shop';
                $shopKey = 'id_manufacturer';
                break;
            case 'supplier_logo':
                $path = _PS_SUPP_IMG_DIR_ . $number . '.jpg';
                $shopTable = 'supplier_shop';
                $shopKey = 'id_supplier';
                break;
            case 'attribute_texture':
                $path = _PS_COL_IMG_DIR_ . $number . '.jpg';
                $shopTable = 'attribute_shop';
                $shopKey = 'id_attribute';
                break;
            case 'order_state_icon':
                // Order statuses are shared by every shop.
                $path = _PS_IMG_DIR_ . 'os/' . $number . '.gif';
                break;
            case 'carrier_logo':
                $path = _PS_SHIP_IMG_DIR_ . $number . '.jpg';
                $shopTable = 'carrier_shop';
                $shopKey = 'id_carrier';
                break;
            case 'category_image':
                $path = _PS_CAT_IMG_DIR_ . $number . '.jpg';
                $shopTable = 'category_shop';
                $shopKey = 'id_category';
                break;
            case 'category_thumbnail':
                // The category and the slot of the menu thumbnail, 12-0.
                if (preg_match('/^([0-9]+)-([0-9])$/', $id, $match) !== 1) {
                    return null;
                }
                $number = (int) $match[1];
                $path = _PS_CAT_IMG_DIR_ . $number . '-' . $match[2] . '_thumb.jpg';
                $shopTable = 'category_shop';
                $shopKey = 'id_category';
                break;
            case 'shop_logo':
                if (!isset(self::SHOP_LOGOS[$id])) {
                    return null;
                }
                $shop = $idShop ?? Somcp::currentShopId();
                $file = (string) Configuration::get(self::SHOP_LOGOS[$id], null, (int) Shop::getGroupFromShop($shop), $shop);
                // basename() keeps a stored value inside img/.
                $file = $file !== '' ? basename($file) : '';
                if ($file === '') {
                    return null;
                }
                $path = _PS_IMG_DIR_ . $file;
                $name = $file;
                break;
            default:
                return null;
        }

        if ($number <= 0 && $kind !== 'shop_logo') {
            return null;
        }
        if (!is_file($path)) {
            return null;
        }
        if ($idShop !== null && $shopTable !== '' && !self::inShop($shopTable, $shopKey, $number, $idShop)) {
            return null;
        }
        if ($kind !== 'product_image' && $kind !== 'product_file' && $kind !== 'attachment' && $kind !== 'shop_logo') {
            $name .= '.' . pathinfo($path, PATHINFO_EXTENSION);
        }
        if ($name === '') {
            $name = basename($path);
        }

        return ['path' => $path, 'name' => $name];
    }

    /**
     * Whether an object is attached to a shop, read from its _shop table.
     *
     * @param string $table
     * @param string $key
     * @param int $id
     * @param int $idShop
     *
     * @return bool
     */
    private static function inShop(string $table, string $key, int $id, int $idShop): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . bqSQL($table) . '` WHERE `' . bqSQL($key) . '` = ' . $id . ' AND `id_shop` = ' . $idShop
        );
    }

    /**
     * A document of an order, rendered, when it exists and belongs to the shop.
     *
     * @param string $kind
     * @param int $id
     * @param int $idShop
     *
     * @return array{bytes:string, name:string, mime:string}|string the file, or gone
     */
    private static function document(string $kind, int $id, int $idShop)
    {
        // Named rather than instantiated from the kind string so the property
        // reads below (id_order, delivery_number) are on the concrete core
        // class static analysis knows, not on the bare ObjectModel.
        if ($kind === 'credit_slip') {
            $object = new OrderSlip($id);
            if (!Validate::isLoadedObject($object)) {
                return 'gone';
            }
            $idOrder = (int) $object->id_order;
        } else {
            $object = new OrderInvoice($id);
            if (!Validate::isLoadedObject($object)) {
                return 'gone';
            }
            $idOrder = (int) $object->id_order;

            // A delivery slip is the delivery side of an OrderInvoice: without a
            // delivery number there is no slip to render, the same gap the
            // Documents tab shows by listing none.
            if ($kind === 'delivery_slip' && (int) $object->delivery_number <= 0) {
                return 'gone';
            }
        }

        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order) || (int) $order->id_shop !== $idShop) {
            return 'gone';
        }

        try {
            $pdf = self::pdf($object, $kind, (string) constant('PDF::' . self::KINDS[$kind]['pdf']));
            $bytes = (string) $pdf->render(false);
            $name = (string) $pdf->filename;
        } catch (Throwable $e) {
            return 'gone';
        }
        if ($bytes === '') {
            return 'gone';
        }

        return ['bytes' => $bytes, 'name' => $name !== '' ? $name : $kind . '-' . $id . '.pdf', 'mime' => 'application/pdf'];
    }

    /**
     * The signature of a reference, over its kind, file, employee, shop and expiry.
     *
     * It reuses the one key that already signs the chat file links rather than
     * minting a second: SomcpAssistantChat::signature() keys an HMAC over the
     * string it is given and the expiry, so the whole tuple is covered by
     * passing it as that string.
     *
     * @param string $kind
     * @param string $id
     * @param int $idEmployee
     * @param int $idShop
     * @param int $expires
     *
     * @return string
     */
    private static function signature(string $kind, string $id, int $idEmployee, int $idShop, int $expires): string
    {
        $payload = implode('|', ['download', $kind, $id, (string) $idEmployee, (string) $idShop]);

        return SomcpAssistantChat::signature($payload, $expires);
    }
}
