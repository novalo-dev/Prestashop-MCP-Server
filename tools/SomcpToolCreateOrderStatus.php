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
 * somcp_create_order_status - one order status, with every field of the
 * back office order status form.
 *
 * It extends the feature creation tool for what an order status shares
 * with the other writes and must not come to disagree on: the languages
 * being filled, texts keyed by ISO code, a language left out taking the
 * default one's text, the employee of the token carried on the write, and
 * the guarded fetch of a file given by address or by reference.
 *
 * An order status is not a return status. The two lists sit on the same
 * back office page, which is exactly why they get confused, and the tool
 * says so wherever it can: creating here never creates a return status.
 *
 * Everything is checked before anything is written, and every problem is
 * reported at once - a name another order status already has in that
 * language included, because two statuses the merchant cannot tell apart
 * are a list nobody can work with. The 9.0.0 form no longer runs that
 * check; the tool runs it on both versions, as 8.0.1 does. The status is
 * written through OrderState::add(), and deleted again if that fails
 * after the row went in.
 *
 * The icon is written after the status exists, as the form's uploader
 * writes it once the status has its identifier: a fetched icon that
 * cannot be used leaves the status without one and is reported, never
 * silently and never by undoing the status.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolCreateFeature.php';

class SomcpToolCreateOrderStatus extends SomcpToolCreateFeature
{
    /** @var array<int, string> Every argument this tool takes. */
    protected const ORDER_STATUS_ARGUMENTS = [
        'languages', 'name', 'color', 'icon', 'logable', 'invoice', 'hidden', 'send_email',
        'pdf_invoice', 'pdf_delivery', 'shipped', 'paid', 'in_transit', 'template',
    ];

    /**
     * @var array<int, string> What somcp_list_order_statuses returns and this tool refuses, because the shop
     *                         works it out. Named, so the assistant learns why rather than meeting an "unknown field".
     */
    protected const ORDER_STATUS_COMPUTED = ['id', 'id_order_state', 'roles', 'built_in', 'payment_module'];

    /** @var int The size of the name column (OrderState::$definition). */
    protected const ORDER_STATUS_NAME_MAX = 64;

    /**
     * @var array<string, string> The form's boxes: the argument the assistant gives, and the column it fills.
     *                            in_transit is the 9.0.0 name of the box 8.0.1 labels "Show delivery PDF";
     *                            the column kept the older name, delivery.
     */
    protected const ORDER_STATUS_BOXES = [
        'logable' => 'logable',
        'invoice' => 'invoice',
        'hidden' => 'hidden',
        'send_email' => 'send_email',
        'pdf_invoice' => 'pdf_invoice',
        'pdf_delivery' => 'pdf_delivery',
        'shipped' => 'shipped',
        'paid' => 'paid',
        'in_transit' => 'delivery',
    ];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_order_status',
            Somcp::t('Create an order status'),
            'Creates one order status in this shop, with every field of the Add new order status form of '
                . 'Shop Parameters > Order Settings > Statuses: the name, the colour, the GIF icon, the nine '
                . 'boxes that say what reaching the status sets off, and the email template per language. '
                . 'Only name is required; every box is off by default, as on the empty form. '
                . 'These are the statuses of an order, not of a return: do not use it for the steps of a '
                . 'merchandise return, which somcp_create_return_status creates. '
                . 'Read somcp_list_order_statuses first: a new status is only needed when none of the '
                . 'existing ones plays the role, and a name another order status already has in the same '
                . 'language is refused, so the merchant can always tell the statuses apart. '
                . 'Who fills each field is said at the start of its description, and is a rule for you: '
                . '"Merchant" - ask the merchant and never invent the value; you may suggest one for them to '
                . 'confirm. '
                . 'Texts are objects keyed by ISO code and must be given for every language in languages; a '
                . 'language of the shop left out takes the default language\'s text. '
                . 'The icon is a GIF, given as an object with url (a public https address the shop downloads '
                . 'itself) or upload_ref (a file already on the shop), one of the two. It is written after '
                . 'the status is created: one that cannot be fetched or is not a GIF leaves the status '
                . 'without an icon, and the answer says so under icon. '
                . 'A template is only stored with send_email: without it the templates are stored empty, as '
                . 'the form does. '
                . 'Every problem is reported at once and nothing is written until the whole call is valid. '
                . 'Do not use it to change a status, which is somcp_update_order_status, or to give an order '
                . 'a status, which is somcp_set_order_status. '
                . 'One order status per call: for several, call it once per status and report each result. '
                . 'Not to be retried: if the answer is lost, read somcp_list_order_statuses and look for '
                . 'the name before anything else, because a second call is refused on the name anyway.',
            self::inputSchema(),
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Adding an order status is what the statuses page asks for in
            // Team, Permissions; the same page as the return statuses.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminStatuses')
        );
    }

    /**
     * The arguments, under the names somcp_list_order_statuses returns.
     *
     * @return array<string, mixed>
     */
    protected static function inputSchema(): array
    {
        $box = static function (string $description): array {
            return [
                'type' => 'boolean',
                'description' => 'Merchant. ' . $description . ' false by default, as in the form.',
            ];
        };

        return [
            'type' => 'object',
            'properties' => [
                'languages' => [
                    'type' => 'array',
                    'description' => 'ISO codes of the languages to fill, among the active languages of the shop. '
                        . 'All of them by default. Every text must then be given in each of them.',
                    'items' => ['type' => 'string'],
                ],
                'name' => [
                    'type' => 'object',
                    'description' => 'Merchant. Required. The name of the status, shown wherever an order is '
                        . 'followed, 64 characters at most, without < > = { }. One text per language being '
                        . 'filled, keyed by ISO code: {"fr": "...", "en": "..."}.',
                    'additionalProperties' => ['type' => 'string'],
                ],
                'color' => [
                    'type' => 'string',
                    'description' => 'Merchant. The colour the status is highlighted in, as a hex code '
                        . '#RRGGBB, for example #E84C3D. Optional, as in the form: left out, the status is '
                        . 'stored without one.',
                ],
                'icon' => [
                    'type' => 'object',
                    'description' => 'Merchant. The icon of the status: a GIF file, 16x16 as the form '
                        . 'suggests. Give url or upload_ref, not both.',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => 'Public https address of the icon. Give url or upload_ref, not both.'],
                        'upload_ref' => ['type' => 'string', 'description' => 'Reference of an icon already on the shop, exactly as given in the conversation. Give url or upload_ref, not both.'],
                    ],
                    'additionalProperties' => false,
                ],
                'logable' => $box('Consider the associated order as validated.'),
                'invoice' => $box('Allow a customer to download and view PDF versions of their invoices.'),
                'hidden' => $box('Hide this status in all customer orders.'),
                'send_email' => $box('Send an email to the customer when their order status has changed.'),
                'pdf_invoice' => $box('Attach invoice PDF to the email the status sends.'),
                'pdf_delivery' => $box('Attach delivery slip PDF to the email the status sends.'),
                'shipped' => $box('Set the order as shipped.'),
                'paid' => $box('Set the order as paid.'),
                'in_transit' => $box('Set the order as in transit - the box the 8.0.1 form labels "Show delivery PDF".'),
                'template' => [
                    'type' => 'object',
                    'description' => 'Merchant. The mail template of the email the status sends, one per '
                        . 'language being filled, keyed by ISO code: {"fr": "...", "en": "..."}. Each must '
                        . 'be one of the mail templates of that language, as the form lists them. Only with '
                        . 'send_email: without it, the templates are stored empty, as the form does.',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public static function handle(array $arguments, array $context): array
    {
        $idShop = (int) Somcp::currentShop()->id;
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = [];
        $plan = self::planOrderStatus($arguments, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was created. Correct these and call again:\n- " . implode("\n- ", $errors),
                true
            );
        }

        return self::asEmployee($idEmployee, static function () use ($plan, $idEmployee): array {
            return self::addOrderStatus($plan, $idEmployee);
        });
    }

    /**
     * Turns the arguments into what will be written, or says everything that is wrong with them.
     *
     * Nothing is written here, so a refused call leaves the shop exactly as it was. The icon is
     * only parsed here: it is fetched once the status exists and has the identifier the file
     * is named after, so a bad address cannot refuse a status the merchant asked for.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function planOrderStatus(array $arguments, int $idShop, array &$errors): ?array
    {
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::ORDER_STATUS_ARGUMENTS, true)) {
                $errors[] = $key . (in_array($key, self::ORDER_STATUS_COMPUTED, true)
                    ? ': worked out by the shop, so it is not written here'
                    : ': not a field of this tool');
            }
        }

        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }

        $probe = new OrderState();
        $plan = [
            'languages' => $languages,
            'texts' => [],
            'color' => '',
            'icon' => null,
            'boxes' => [],
        ];

        $names = self::texts($arguments, 'name', 'name', $languages, $errors, true);
        if ($names !== null) {
            $plan['texts']['name'] = self::orderStatusNames($names, $languages, $probe, $errors);
        }

        if (array_key_exists('color', $arguments)) {
            $color = trim((string) (self::string($arguments['color']) ?? ''));
            // Validate::isColor() also lets a bare word through, which the
            // colour picker of the form never stores: only the hex code is.
            // The same check somcp_create_attribute_value makes on a swatch.
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color) || !Validate::isColor($color)) {
                $errors[] = 'color: a hex code such as #E84C3D';
            } else {
                $plan['color'] = $color;
            }
        }

        if (array_key_exists('icon', $arguments)) {
            $icon = $arguments['icon'];
            if (!is_array($icon) || ($icon !== [] && array_is_list($icon))) {
                $errors[] = 'icon: an object with url or upload_ref';
            } else {
                self::onlyAccepted($icon, 'file', 'icon', $errors);
                $plan['icon'] = self::imageSource($icon, 'icon', $errors, 'icon');
            }
        }

        foreach (self::ORDER_STATUS_BOXES as $argument => $column) {
            $plan['boxes'][$column] = false;
            if (array_key_exists($argument, $arguments)) {
                // A boolean only: "false" cast to bool is true, and would
                // email a customer the merchant said not to email.
                if (!is_bool($arguments[$argument])) {
                    $errors[] = $argument . ': true or false';
                } else {
                    $plan['boxes'][$column] = $arguments[$argument];
                }
            }
        }

        $templates = self::texts($arguments, 'template', 'template', $languages, $errors);
        if ($templates !== null) {
            $plan['texts']['template'] = self::orderStatusTemplates(
                $templates,
                $languages,
                $probe,
                (bool) $plan['boxes']['send_email'],
                $errors
            );
        }

        // The same check the core runs before add(), on the whole object, as a
        // last net for a rule of the running version none of the above knew.
        if ($errors === []) {
            $candidate = new OrderState();
            self::fill($candidate, self::completeTexts([
                'name' => $plan['texts']['name'],
                'template' => $plan['boxes']['send_email'] ? ($plan['texts']['template'] ?? []) : [],
            ], $languages));
            $candidate->color = $plan['color'];
            foreach ($plan['boxes'] as $column => $on) {
                $candidate->{$column} = $on;
            }
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'order status: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * The names given in the call, checked with the running version's rules, then against the shop's own.
     *
     * A name another order status already has in that language is refused: on the
     * orders page the statuses are told apart by their name alone, and
     * somcp_set_order_status is called with a name in the merchant's words.
     *
     * @param array<int, string> $names by language identifier
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param OrderState $probe
     * @param array<int, string> $errors
     *
     * @return array<int, string>
     */
    protected static function orderStatusNames(array $names, array $languages, OrderState $probe, array &$errors): array
    {
        // The check is only as good as the list it reads, and the list
        // getOrderStates() hands over is the one it read first in this
        // process: forget the remembered lists, so the names below are
        // the shop's as they stand now.
        self::forgetOrderStatusLists(array_keys($languages['filled']));

        $taken = [];
        foreach (array_keys($languages['filled']) as $idLang) {
            $taken[$idLang] = [];
            foreach (OrderState::getOrderStates($idLang) as $state) {
                $taken[$idLang][Tools::strtolower(trim((string) $state['name']))] = (int) $state['id_order_state'];
            }
        }

        foreach ($names as $idLang => $name) {
            $name = trim($name);
            $names[$idLang] = $name;
            $where = 'name (' . $languages['filled'][$idLang] . ')';
            if (Tools::strlen($name) > self::ORDER_STATUS_NAME_MAX) {
                $errors[] = $where . ': ' . self::ORDER_STATUS_NAME_MAX . ' characters at most, ' . Tools::strlen($name) . ' given';
            } elseif ($name !== '' && ($check = $probe->validateField('name', $name, $idLang)) !== true) {
                // OrderState::$name is checked with isGenericName.
                $errors[] = $where . ': a character PrestaShop refuses in an order status name (< > = { }) - ' . self::reason($check);
            } elseif (isset($taken[$idLang][Tools::strtolower($name)])) {
                $errors[] = $where . ': an order status already has this name, id_order_state '
                    . $taken[$idLang][Tools::strtolower($name)] . ' - the merchant could not tell the two apart';
            }
        }

        return $names;
    }

    /**
     * Forgets the order status lists this process remembers, for these languages.
     *
     * OrderState::getOrderStates() stores the list it read in Cache::$local
     * and serves that copy for the rest of the process, and nothing in the
     * core forgets it when a status is created. A process that lives across
     * calls - the shop test, a worker - would go on answering from the list
     * as it stood before the first create in it: a name taken a moment ago
     * looks free, and a status just written is missing from the list it is
     * read back from. Cache::clean() drops the copies held in this process,
     * so the next getOrderStates() asks the database again. The two keys are
     * the ones the core builds for the list, with and without its
     * deleted-status filter, the same on PrestaShop 8 and 9.
     *
     * @param array<int, int> $idLangs
     */
    private static function forgetOrderStatusLists(array $idLangs): void
    {
        foreach ($idLangs as $idLang) {
            Cache::clean('OrderState::getOrderStates_' . (int) $idLang);
            Cache::clean('OrderState::getOrderStates_' . (int) $idLang . '_filterDeleted');
        }
    }

    /**
     * The email templates given in the call, one per language being filled.
     *
     * A template only means something with the email box on: the 8.0.1 form
     * blanks the templates when it is off, and so does this tool - they are
     * stored empty whatever was given, and checked only when they would be
     * stored. A checked one must be one of the mail templates of its own
     * language, the list the form offers from mails/<iso>/.
     *
     * @param array<int, string> $templates by language identifier
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param OrderState $probe
     * @param bool $sendEmail
     * @param array<int, string> $errors
     *
     * @return array<int, string>
     */
    protected static function orderStatusTemplates(array $templates, array $languages, OrderState $probe, bool $sendEmail, array &$errors): array
    {
        foreach ($templates as $idLang => $template) {
            $template = trim($template);
            $templates[$idLang] = $template;
            if (!$sendEmail || $template === '') {
                continue;
            }
            $where = 'template (' . $languages['filled'][$idLang] . ')';
            if (($check = $probe->validateField('template', $template, $idLang)) !== true) {
                $errors[] = $where . ': ' . self::reason($check);
            } elseif (!in_array($template, self::mailTemplates($languages['filled'][$idLang]), true)) {
                $errors[] = $where . ': "' . $template . '" is not one of the mail templates of '
                    . $languages['filled'][$idLang] . ', as the form lists them';
            }
        }

        return $templates;
    }

    /**
     * The mail templates of one language: the .html files of mails/<iso>/.
     *
     * That folder is the list the form offers (34 templates on 8.0.1, 41 on
     * 9.0.0, and modules add their own there), read the same way on both
     * versions instead of through a class only one of them has.
     *
     * @param string $iso
     *
     * @return array<int, string>
     */
    private static function mailTemplates(string $iso): array
    {
        $names = [];
        $files = glob(_PS_MAIL_DIR_ . $iso . '/*.html');
        foreach ($files === false ? [] : $files as $file) {
            $names[] = basename($file, '.html');
        }
        sort($names);

        return $names;
    }

    /**
     * Writes the status, then its icon, and answers with what was stored.
     *
     * Order statuses are not tied to a shop - the table has no shop column -
     * so there is no shop list to set, unlike the catalogue writes.
     *
     * @param array<string, mixed> $plan
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function addOrderStatus(array $plan, int $idEmployee): array
    {
        $state = new OrderState();
        self::fill($state, self::completeTexts([
            'name' => $plan['texts']['name'],
            // Without the email box the templates are stored empty, as the
            // 8.0.1 form leaves them, whatever the call gave.
            'template' => $plan['boxes']['send_email'] ? ($plan['texts']['template'] ?? []) : [],
        ], $plan['languages']));
        $state->color = (string) $plan['color'];
        foreach ($plan['boxes'] as $column => $on) {
            $state->{$column} = $on;
        }

        try {
            $done = $state->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        if (!$done) {
            $kept = false;
            if ((int) $state->id > 0) {
                try {
                    $kept = !$state->delete();
                } catch (Throwable $e) {
                    $kept = true;
                }
            }
            SomcpLogger::log(
                'order_status_create_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr($failure, 0, 500) . ($kept ? ', order status ' . (int) $state->id . ' could not be removed' : ''),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The order status could not be created: ' . $failure . '. '
                    . ($kept
                        ? 'It could not be removed either: order status ' . (int) $state->id . ' exists in an '
                            . 'incomplete state. Tell the merchant to check it in the back office; do not call again.'
                        : 'Nothing was kept.'),
                true
            );
        }

        $idOrderState = (int) $state->id;

        // The shop's list of statuses has a new member: forget the
        // remembered lists, so the read-back below and any later answer
        // in this process list the status as it was stored.
        self::forgetOrderStatusLists($plan['languages']['all']);

        // The icon is written now that the status has the identifier its file
        // is named after, as the form's uploader does. A refusal is reported
        // in the answer and leaves the status standing without one.
        $icon = null;
        if ($plan['icon'] !== null) {
            $icon = self::storeIcon($idOrderState, $plan['icon']);
            if (isset($icon['refused'])) {
                SomcpLogger::log(
                    'order_status_icon_refused',
                    SomcpLogger::OUTCOME_FAILURE,
                    'order status ' . $idOrderState . ': ' . $icon['refused'],
                    '',
                    $idEmployee
                );
            }
        }

        SomcpLogger::log('order_status_created', SomcpLogger::OUTCOME_SUCCESS, 'order status ' . $idOrderState, '', $idEmployee);

        $result = [
            'created' => true,
            'id_order_state' => $idOrderState,
        ];
        if ($icon !== null) {
            $result['icon'] = $icon;
        }
        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $result['status'] = self::status($idOrderState, (int) $plan['languages']['fallback']);

        return SomcpTool::json($result);
    }

    /**
     * Fetches the icon and writes it where the back office writes it: img/os/<id>.gif, as it arrived.
     *
     * The 9.0.0 uploader moves the file there without converting it, so the
     * bytes are checked rather than resized: a GIF, a real image, small
     * enough. #130 will extract the "get the bytes" part of addImage() into
     * one shared function; until it lands, this is the same three calls that
     * method makes today - fromDeposit() or download(), then checkImage() -
     * with the GIF rule of this form added.
     *
     * @param int $idOrderState
     * @param array{url: string, upload_ref: string} $source
     *
     * @return array<string, mixed> the source, with stored: true or refused: the reason
     */
    private static function storeIcon(int $idOrderState, array $source): array
    {
        $report = self::imageOrigin($source);
        $file = tempnam(_PS_TMP_IMG_DIR_, 'somcp');
        if ($file === false) {
            $report['refused'] = 'the shop has no room to receive it';

            return $report;
        }

        try {
            $refusal = $source['upload_ref'] !== ''
                ? self::fromDeposit($source['upload_ref'], $file)
                : self::download($source['url'], $file);
            if ($refusal === '') {
                $info = @getimagesize($file);
                if (!is_array($info) || (int) $info[2] !== IMAGETYPE_GIF) {
                    // The form takes a .gif and stores it under that name as
                    // it arrived: another format kept under it would be a lie
                    // the browser may not even render.
                    $refusal = 'it is not a GIF image';
                } else {
                    $refusal = self::checkImage($file);
                }
            }
            if ($refusal !== '') {
                $report['refused'] = $refusal;

                return $report;
            }

            $target = _PS_IMG_DIR_ . 'os/' . $idOrderState . '.gif';
            if (!@rename($file, $target) && !(@copy($file, $target) && @unlink($file))) {
                $report['refused'] = 'the shop could not save it';

                return $report;
            }
            $report['stored'] = true;

            return $report;
        } finally {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * One status as somcp_list_order_statuses lists it, read the way it reads.
     *
     * The field names are those of the completed list tool (#131): in_transit
     * for the delivery column, and icon as the public address of its file.
     *
     * @param int $idOrderState
     * @param int $idLang
     *
     * @return array<string, mixed>
     */
    private static function status(int $idOrderState, int $idLang): array
    {
        $roles = self::roles();
        foreach (OrderState::getOrderStates($idLang) as $state) {
            if ((int) $state['id_order_state'] === $idOrderState) {
                return [
                    'id' => $idOrderState,
                    'name' => (string) $state['name'],
                    'roles' => $roles[$idOrderState] ?? [],
                    'paid' => (bool) $state['paid'],
                    'shipped' => (bool) $state['shipped'],
                    'in_transit' => (bool) $state['delivery'],
                    'sends_email' => (bool) $state['send_email'],
                    'invoice' => (bool) $state['invoice'],
                    'counts_as_valid' => (bool) $state['logable'],
                    'hidden_from_customer' => (bool) $state['hidden'],
                    'attaches_invoice_pdf' => (bool) $state['pdf_invoice'],
                    'attaches_delivery_slip_pdf' => (bool) $state['pdf_delivery'],
                    'built_in' => (bool) $state['unremovable'],
                    'email_template' => (string) $state['template'],
                    'payment_module' => (string) $state['module_name'],
                    'color' => (string) $state['color'],
                    'icon' => self::iconUrl($idOrderState),
                ];
            }
        }

        // The row was written a moment ago; not finding it means the read
        // above raced something. Answer from the object rather than failing
        // a creation that did go through.
        $stored = new OrderState($idOrderState, $idLang);

        return [
            'id' => $idOrderState,
            'name' => (string) $stored->name,
            'roles' => $roles[$idOrderState] ?? [],
            'paid' => (bool) $stored->paid,
            'shipped' => (bool) $stored->shipped,
            'in_transit' => (bool) $stored->delivery,
            'sends_email' => (bool) $stored->send_email,
            'invoice' => (bool) $stored->invoice,
            'counts_as_valid' => (bool) $stored->logable,
            'hidden_from_customer' => (bool) $stored->hidden,
            'attaches_invoice_pdf' => (bool) $stored->pdf_invoice,
            'attaches_delivery_slip_pdf' => (bool) $stored->pdf_delivery,
            'built_in' => (bool) $stored->unremovable,
            'email_template' => (string) $stored->template,
            'payment_module' => (string) $stored->module_name,
            'color' => (string) $stored->color,
            'icon' => self::iconUrl($idOrderState),
        ];
    }

    /**
     * Which status plays which part in this shop, by status identifier.
     *
     * The same fourteen keys somcp_list_order_statuses reads: PrestaShop
     * records the part in its own configuration, and a renamed status keeps
     * pointing at the right row.
     *
     * @return array<int, array<int, string>>
     */
    private static function roles(): array
    {
        $parts = [
            'PS_OS_PAYMENT' => 'payment_accepted',
            'PS_OS_WS_PAYMENT' => 'payment_accepted_remotely',
            'PS_OS_PREPARATION' => 'preparation_in_progress',
            'PS_OS_SHIPPING' => 'shipped',
            'PS_OS_DELIVERED' => 'delivered',
            'PS_OS_CANCELED' => 'canceled',
            'PS_OS_REFUND' => 'refunded',
            'PS_OS_ERROR' => 'payment_error',
            'PS_OS_OUTOFSTOCK' => 'out_of_stock',
            'PS_OS_OUTOFSTOCK_PAID' => 'out_of_stock_paid',
            'PS_OS_OUTOFSTOCK_UNPAID' => 'out_of_stock_unpaid',
            'PS_OS_BANKWIRE' => 'awaiting_bank_wire_payment',
            'PS_OS_CHEQUE' => 'awaiting_cheque_payment',
            'PS_OS_COD_VALIDATION' => 'awaiting_cash_on_delivery_validation',
        ];

        $roles = [];
        foreach ($parts as $key => $role) {
            $id = (int) Configuration::get($key);
            if ($id > 0) {
                $roles[$id][] = $role;
            }
        }

        return $roles;
    }

    /**
     * Public address of the icon of a status, as somcp_list_carriers gives a logo's.
     *
     * The icon is not a column: the back office stores the file under the
     * status identifier, and only that file tells whether there is one.
     *
     * @param int $idOrderState
     *
     * @return string|null null when the status has no icon
     */
    private static function iconUrl(int $idOrderState): ?string
    {
        if (!is_file(_PS_IMG_DIR_ . 'os/' . $idOrderState . '.gif')) {
            return null;
        }
        $link = Somcp::legacyContext()->link ?? new Link();

        return (string) $link->getMediaLink('img/os/' . $idOrderState . '.gif');
    }
}
