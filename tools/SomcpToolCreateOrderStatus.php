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
    /** @var array<int, string> Every argument this tool takes, under the names somcp_list_order_statuses returns. */
    protected const ORDER_STATUS_ARGUMENTS = [
        'languages', 'name', 'color', 'icon', 'counts_as_valid', 'invoice', 'hidden_from_customer', 'sends_email',
        'attaches_invoice_pdf', 'attaches_delivery_slip_pdf', 'shipped', 'paid', 'in_transit', 'email_template',
    ];

    /**
     * @var array<string, string> The column names this tool took before it took the list's names, and the name
     *                            each one now goes by. Still accepted, so a call written for the former names
     *                            keeps working; never both at once.
     */
    protected const ORDER_STATUS_FORMER_NAMES = [
        'logable' => 'counts_as_valid',
        'hidden' => 'hidden_from_customer',
        'send_email' => 'sends_email',
        'pdf_invoice' => 'attaches_invoice_pdf',
        'pdf_delivery' => 'attaches_delivery_slip_pdf',
        'template' => 'email_template',
    ];

    /**
     * @var array<int, string> What somcp_list_order_statuses returns and this tool refuses, because the shop
     *                         works it out. Named, so the assistant learns why rather than meeting an "unknown field".
     */
    protected const ORDER_STATUS_COMPUTED = ['id', 'id_order_state', 'status_id', 'roles', 'built_in', 'payment_module'];

    /** @var int The size of the name column (OrderState::$definition). */
    protected const ORDER_STATUS_NAME_MAX = 64;

    /**
     * @var array<string, string> The form's boxes: the argument the assistant gives, and the column it fills.
     *                            The arguments are the names somcp_list_order_statuses returns, so a status
     *                            read there can be given back. in_transit is the 9.0.0 name of the box 8.0.1
     *                            labels "Show delivery PDF"; the column kept the older name, delivery.
     */
    protected const ORDER_STATUS_BOXES = [
        'counts_as_valid' => 'logable',
        'invoice' => 'invoice',
        'hidden_from_customer' => 'hidden',
        'sends_email' => 'send_email',
        'attaches_invoice_pdf' => 'pdf_invoice',
        'attaches_delivery_slip_pdf' => 'pdf_delivery',
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
            'Creates one order status, as the Add new order status form of Shop Parameters > Order Settings > '
                . 'Statuses: name, colour, GIF icon, the nine boxes and the email templates. Not for '
                . 'return statuses (somcp_create_return_status), nor to give an order a status '
                . '(somcp_set_order_status). Read somcp_list_order_statuses first: create one only when none '
                . 'plays the role; a name another status has in the same language is refused. Each field says '
                . 'who fills it: Merchant - ask and never invent. One status per call. Not to be retried: if the '
                . 'answer is lost, look for the name in somcp_list_order_statuses first.',
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
                    'description' => 'Merchant. Required. Shown wherever an order is followed, 64 characters at '
                        . 'most, without < > = { }. One per language being filled, keyed by ISO code: '
                        . '{"fr": "...", "en": "..."}.',
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
                    'description' => 'Merchant. A GIF, 16x16. Give url or upload_ref, not both. Written once the '
                        . 'status exists: an icon refused in the answer means the status was created without one - '
                        . 'do not call again.',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => 'Public https address of the icon. Give url or upload_ref, not both.'],
                        'upload_ref' => ['type' => 'string', 'description' => 'Reference of an icon already on the shop, exactly as given in the conversation. Give url or upload_ref, not both.'],
                    ],
                    'additionalProperties' => false,
                ],
                'counts_as_valid' => $box('Consider the associated order as validated.'),
                'invoice' => $box('Allow a customer to download and view PDF versions of their invoices.'),
                'hidden_from_customer' => $box('Hide this status in all customer orders.'),
                'sends_email' => $box('Send an email to the customer when their order status has changed.'),
                'attaches_invoice_pdf' => $box('Attach invoice PDF to the email the status sends.'),
                'attaches_delivery_slip_pdf' => $box('Attach delivery slip PDF to the email the status sends.'),
                'shipped' => $box('Set the order as shipped.'),
                'paid' => $box('Set the order as paid.'),
                'in_transit' => $box('Set the order as in transit - the box the 8.0.1 form labels "Show delivery PDF".'),
                'email_template' => [
                    'type' => 'object',
                    'description' => 'Merchant. The email sent, by ISO code: one of the shop\'s own emails (mails/ '
                        . 'or the theme\'s), never a module\'s. Only with sends_email: without it, stored empty.',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ] + self::formerNameProperties(),
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The former names, still declared so a client that checks arguments against the schema lets them through.
     *
     * @return array<string, mixed>
     */
    protected static function formerNameProperties(): array
    {
        $properties = [];
        foreach (self::ORDER_STATUS_FORMER_NAMES as $former => $name) {
            $properties[$former] = [
                'type' => $name === 'email_template' ? 'object' : 'boolean',
                'description' => 'Former name of ' . $name . ', still accepted. Give ' . $name . ' instead.',
            ];
            if ($name === 'email_template') {
                $properties[$former]['additionalProperties'] = ['type' => 'string'];
            }
        }

        return $properties;
    }

    /**
     * The arguments with each former name moved to the name it now goes by.
     *
     * Done before anything else reads them, so every check and every message
     * speaks the list's names. Both names at once are refused rather than one
     * silently winning over the other.
     *
     * @param array<string, mixed> $arguments
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>
     */
    protected static function currentNames(array $arguments, array &$errors): array
    {
        foreach (self::ORDER_STATUS_FORMER_NAMES as $former => $name) {
            if (!array_key_exists($former, $arguments)) {
                continue;
            }
            if (array_key_exists($name, $arguments)) {
                $errors[] = $former . ': the former name of ' . $name . ' - give ' . $name . ' only';
            } else {
                $arguments[$name] = $arguments[$former];
            }
            unset($arguments[$former]);
        }

        return $arguments;
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
            return SomcpTool::refused('created', $errors);
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
    protected static function planOrderStatus(array $arguments, int $idShop, array &$errors): ?array
    {
        $arguments = self::currentNames($arguments, $errors);
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
            $plan['color'] = self::orderStatusColor($arguments['color'], $errors) ?? '';
        }

        if (array_key_exists('icon', $arguments)) {
            $plan['icon'] = self::orderStatusIcon($arguments['icon'], $errors);
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

        $templates = self::texts($arguments, 'email_template', 'email_template', $languages, $errors);
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
     * The colour given in the call, or null with the reason when it is not one the form stores.
     *
     * Shared with somcp_update_order_status, so the two tools take the same colours.
     *
     * @param mixed $value
     * @param array<int, string> $errors
     *
     * @return string|null
     */
    protected static function orderStatusColor($value, array &$errors): ?string
    {
        $color = trim((string) (self::string($value) ?? ''));
        // Validate::isColor() also lets a bare word through, which the
        // colour picker of the form never stores: only the hex code is.
        // The same check somcp_create_attribute_value makes on a swatch.
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color) || !Validate::isColor($color)) {
            $errors[] = 'color: a hex code such as #E84C3D';

            return null;
        }

        return $color;
    }

    /**
     * The icon given in the call, parsed only: it is fetched by storeIcon() once the status has its identifier.
     *
     * Shared with somcp_update_order_status, so the two tools take an icon the same way.
     *
     * @param mixed $icon
     * @param array<int, string> $errors
     *
     * @return array{url: string, upload_ref: string}|null
     */
    protected static function orderStatusIcon($icon, array &$errors): ?array
    {
        if (!is_array($icon) || ($icon !== [] && array_is_list($icon))) {
            $errors[] = 'icon: an object with url or upload_ref';

            return null;
        }
        self::onlyAccepted($icon, 'file', 'icon', $errors);

        return self::imageSource($icon, 'icon', $errors, 'icon');
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
     * @param int $idSelf the status being changed, whose own name is no clash; 0 when creating
     *
     * @return array<int, string>
     */
    protected static function orderStatusNames(array $names, array $languages, OrderState $probe, array &$errors, int $idSelf = 0): array
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
                if ((int) $state['id_order_state'] === $idSelf) {
                    continue;
                }
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
                $errors[] = $where . ': an order status already has this name, status_id '
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
    public static function forgetOrderStatusLists(array $idLangs): void
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
     * stored. A checked one must be an email the status can actually send in
     * its own language (mailTemplates()); the emails of modules the 9.0.0
     * form also lists are refused with the reason, since a status set to one
     * would send the customer nothing.
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
            $iso = $languages['filled'][$idLang];
            $where = 'email_template (' . $iso . ')';
            if (($check = $probe->validateField('template', $template, $idLang)) !== true) {
                $errors[] = $where . ': ' . self::reason($check);
            } elseif (!in_array($template, self::mailTemplates($iso), true)) {
                $errors[] = $where . ': ' . (self::isModuleMail($template, $iso)
                    ? $template . ' is an email of a module - an order status can only send the shop\'s own '
                        . 'emails (mails/ or the theme\'s), so customers would get nothing'
                    : '"' . $template . '" is not one of the mail templates of ' . $iso
                        . ' - the shop\'s own emails, in mails/ or the theme\'s');
            }
        }

        return $templates;
    }

    /**
     * The mail templates of one language: the emails a status can send in it.
     *
     * OrderHistory::sendEmail() sends the status email with no module, and
     * Mail::getTemplateBasePath() then looks for <iso>/<template>.html or .txt
     * in the theme's mails/, the parent theme's, then the shop's mails/ - the
     * same three folders on 8.0.1 and 9.0.0. A template found in none of them
     * is logged as missing and no email leaves, so these folders are the list,
     * not the one either form offers: 8.0.1 forgets the parent theme, 9.0.0
     * adds the layouts of modules.
     *
     * @param string $iso
     *
     * @return array<int, string>
     */
    protected static function mailTemplates(string $iso): array
    {
        $bases = [];
        // The theme read the way somcp_get_theme reads it; a shop whose theme
        // folder is gone still has the shop's own emails.
        $theme = SomcpToolGetTheme::shopTheme();
        if ($theme !== null) {
            $bases[] = _PS_ROOT_DIR_ . '/themes/' . $theme->getName() . '/mails/';
            $parent = (string) $theme->get('parent');
            if ($parent !== '') {
                $bases[] = _PS_ROOT_DIR_ . '/themes/' . $parent . '/mails/';
            }
        }
        $bases[] = _PS_MAIL_DIR_;

        $names = [];
        foreach ($bases as $base) {
            // Two globs rather than GLOB_BRACE, which some C libraries lack.
            foreach (['html', 'txt'] as $extension) {
                $files = glob($base . $iso . '/*.' . $extension);
                foreach ($files === false ? [] : $files as $file) {
                    $names[basename($file, '.' . $extension)] = true;
                }
            }
        }
        $names = array_map('strval', array_keys($names));
        sort($names);

        return $names;
    }

    /**
     * Whether a template the status email cannot send is the email of a module.
     *
     * Only to say why it is refused: the 9.0.0 form lists these next to the
     * shop's own, and the merchant picked one there. The name has already
     * passed Validate::isTplName(), so it holds no glob character.
     *
     * @param string $template
     * @param string $iso
     *
     * @return bool
     */
    protected static function isModuleMail(string $template, string $iso): bool
    {
        foreach (['html', 'txt'] as $extension) {
            $files = glob(_PS_MODULE_DIR_ . '*/mails/' . $iso . '/' . $template . '.' . $extension);
            if (is_array($files) && $files !== []) {
                return true;
            }
        }

        return false;
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
    protected static function storeIcon(int $idOrderState, array $source): array
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
     * One status as somcp_list_order_statuses lists it, built by the list's own row function.
     *
     * Called rather than copied, so the answer of a write can never drift
     * from the list it is checked against (#168), and the update tool answers
     * through it too.
     *
     * @param int $idOrderState
     * @param int $idLang
     *
     * @return array<string, mixed>
     */
    protected static function status(int $idOrderState, int $idLang): array
    {
        $roles = SomcpToolListOrderStatuses::roles();
        foreach (OrderState::getOrderStates($idLang) as $state) {
            if ((int) $state['id_order_state'] === $idOrderState) {
                return SomcpToolListOrderStatuses::row($state, $roles);
            }
        }

        // The row was written a moment ago; not finding it means the read
        // above raced something. Answer from the object, in the shape of a
        // getOrderStates() row, rather than failing a creation that did go through.
        $stored = new OrderState($idOrderState, $idLang);

        return SomcpToolListOrderStatuses::row([
            'id_order_state' => $idOrderState,
            'name' => (string) $stored->name,
            'paid' => $stored->paid,
            'shipped' => $stored->shipped,
            'delivery' => $stored->delivery,
            'send_email' => $stored->send_email,
            'invoice' => $stored->invoice,
            'logable' => $stored->logable,
            'hidden' => $stored->hidden,
            'pdf_invoice' => $stored->pdf_invoice,
            'pdf_delivery' => $stored->pdf_delivery,
            'unremovable' => $stored->unremovable,
            'template' => (string) $stored->template,
            'module_name' => (string) $stored->module_name,
            'color' => (string) $stored->color,
        ], $roles);
    }
}
