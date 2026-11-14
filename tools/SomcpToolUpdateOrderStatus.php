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
 * somcp_update_order_status - one existing order status, changed with
 * everything somcp_create_order_status can write.
 *
 * It extends the creation tool rather than copying it: a field accepted at
 * creation is accepted here under the same name, the former names included,
 * and checked by the same code - the name rules and the clash with another
 * status, the colour, the icon and the email templates - so the two tools
 * cannot come to disagree on what a valid status is.
 *
 * Only what is given changes. Each value is compared with what the shop holds
 * before anything is written, which gives the answer its old and new values
 * and lets a call that would change nothing write nothing at all: the answer
 * says changed: false. A status read with somcp_list_order_statuses can
 * therefore be given back as it is.
 *
 * Every status can be changed, the ones of PrestaShop and of modules too, as
 * in the back office: unremovable only forbids deleting them. A deleted one is
 * refused like an unknown one, since the back office no longer lists it.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolCreateOrderStatus.php';

class SomcpToolUpdateOrderStatus extends SomcpToolCreateOrderStatus
{
    /** @var array<int, string> What this tool takes besides the fields of the creation. */
    private const OWN_ARGUMENTS = ['status_id', 'remove_icon'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_order_status',
            Somcp::t('Edit an order status'),
            'Changes one order status, as its Edit form of Shop Parameters > Order Settings > Statuses, under '
                . 'the names and rules of somcp_create_order_status. status_id from somcp_list_order_statuses, '
                . 'never guessed. Only what is given changes; a status read there can be given back unchanged. A '
                . 'change to what a status sets off (email, invoice, paid, shipped) applies to orders reaching it '
                . 'from now on: tell the merchant first. Not for return statuses (somcp_update_return_status). One '
                . 'status per call. Not to be retried: if the answer is lost, read somcp_list_order_statuses first.',
            self::inputSchema(),
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Changing an order status is the edit right of the statuses page
            // in Team, Permissions, as the form's save checks it.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminStatuses'),
            files: ['icon' => SomcpUpload::rule(['gif'], SomcpUpload::IMAGE_MAX_BYTES)]
        );
    }

    /**
     * The creation schema, made to name an existing status and to keep what is left out.
     *
     * Derived rather than written again, so a field added to the creation is
     * offered here too, with the same description.
     *
     * @return array<string, mixed>
     */
    protected static function inputSchema(): array
    {
        $schema = parent::inputSchema();
        $properties = $schema['properties'];
        $kept = ' Left out: unchanged.';

        $properties['languages']['description'] = 'ISO codes of the languages whose texts are given, among the '
            . 'active languages of the shop. All of them by default. A language left out keeps its texts.';
        $properties['name']['description'] = 'Merchant. Shown wherever an order is followed, 64 characters at '
            . 'most, without < > = { }. One per language being filled, keyed by ISO code: {"fr": "...", "en": "..."}.'
            . $kept;
        $properties['icon'] = SomcpUpload::schema('Merchant. A new 16x16 GIF; not with remove_icon.', 'the icon');
        $properties['email_template']['description'] = 'Merchant. The email sent, by ISO code: one of the shop\'s '
            . 'own emails, never a module\'s. Only when sends_email is or becomes true; turning it off empties them.'
            . $kept;
        foreach (array_keys(self::ORDER_STATUS_BOXES) as $box) {
            $properties[$box]['description'] = str_replace(' false by default, as in the form.', $kept, $properties[$box]['description']);
        }
        $properties['color']['description'] = 'Merchant. The colour the status is highlighted in, as a hex code '
            . '#RRGGBB, for example #E84C3D.' . $kept;

        $schema['properties'] = [
            'status_id' => [
                'type' => 'integer',
                'description' => 'The status to change, from somcp_list_order_statuses. Never guessed.',
            ],
        ] + $properties + [
            'remove_icon' => [
                'type' => 'boolean',
                'description' => 'Merchant. true deletes the icon of the status. Not with icon.',
            ],
        ];
        $schema['required'] = ['status_id'];

        return $schema;
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

        // The same test as somcp_delete_order_status, so a status this tool
        // refuses is never one another order status tool works on.
        $idOrderState = SomcpTool::id($arguments, 'status_id');
        $state = SomcpToolDeleteOrderStatus::locate($idOrderState);

        $errors = [];
        if ($idOrderState === 0) {
            $errors[] = SomcpTool::notAnIdentifier('status_id');
        } elseif ($state === null) {
            $errors[] = SomcpTool::notFound('status_id', 'order status', $idOrderState, 'somcp_list_order_statuses');
        }
        $plan = self::planUpdate($arguments, $state, $idShop, $errors);
        if ($errors !== [] || $plan === null || $state === null) {
            return SomcpTool::refused('changed', $errors);
        }

        if ($plan['changes'] === [] && $plan['icon'] === null) {
            return SomcpTool::json([
                'changed' => false,
                'status_id' => (int) $state->id,
                'reason' => 'The status already holds everything given. Nothing was written.',
                'status' => self::status((int) $state->id, (int) $plan['languages']['fallback']),
            ]);
        }

        return self::asEmployee($idEmployee, static function () use ($state, $plan, $idEmployee): array {
            return self::applyUpdate($state, $plan, $idEmployee);
        });
    }

    /**
     * Turns the arguments into what will be written, or says everything that is wrong with them.
     *
     * Nothing is written here. A field equal to what the shop holds is left
     * out of the plan, so what remains is exactly what will change. Run even
     * when the status is unknown, so every problem of the call is told at once.
     *
     * @param array<string, mixed> $arguments
     * @param OrderState|null $state loaded in every language
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function planUpdate(array $arguments, ?OrderState $state, int $idShop, array &$errors): ?array
    {
        $arguments = self::currentNames($arguments, $errors);
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::ORDER_STATUS_ARGUMENTS, true) && !in_array($key, self::OWN_ARGUMENTS, true)) {
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
        $idSelf = $state === null ? 0 : (int) $state->id;
        $plan = [
            'languages' => $languages,
            'changes' => [],
            'values' => [],
            'icon' => null,
            'remove_icon' => false,
        ];

        $names = array_key_exists('name', $arguments)
            ? self::texts($arguments, 'name', 'name', $languages, $errors, true)
            : null;
        if ($names !== null) {
            $names = self::orderStatusNames($names, $languages, $probe, $errors, $idSelf);
        }

        $color = null;
        if (array_key_exists('color', $arguments)) {
            $color = self::orderStatusColor($arguments['color'], $errors);
        }

        // The icon as somcp_list_order_statuses gave it - its download_ref,
        // or null when there is none - is the stored one: nothing to fetch.
        $given = $arguments;
        if (array_key_exists('icon', $given) && SomcpUpload::same($given['icon'], 'order_state_icon', (string) $idSelf)) {
            unset($given['icon']);
        }
        $plan['icon'] = self::orderStatusIcon($given, $errors);
        if (array_key_exists('remove_icon', $arguments)) {
            if (!is_bool($arguments['remove_icon'])) {
                $errors[] = 'remove_icon: true or false';
            } elseif ($arguments['remove_icon'] && array_key_exists('icon', $arguments)) {
                $errors[] = 'remove_icon: not with icon - give one or the other';
            } else {
                $plan['remove_icon'] = $arguments['remove_icon'];
            }
        }

        $boxes = [];
        foreach (self::ORDER_STATUS_BOXES as $argument => $column) {
            if (!array_key_exists($argument, $arguments)) {
                continue;
            }
            // A boolean only: "false" cast to bool is true, and would email a
            // customer the merchant said not to email.
            if (!is_bool($arguments[$argument])) {
                $errors[] = $argument . ': true or false';
            } else {
                $boxes[$column] = $arguments[$argument];
            }
        }

        // The email box as it will be: given in the call, or as stored.
        $sendEmail = array_key_exists('send_email', $boxes)
            ? (bool) $boxes['send_email']
            : ($state !== null && (bool) $state->send_email);

        $templates = self::texts($arguments, 'email_template', 'email_template', $languages, $errors);
        if ($templates !== null) {
            $given = array_filter($templates, static function (string $template): bool {
                return trim($template) !== '';
            });
            // Refused rather than dropped: a template the merchant asked for
            // would otherwise vanish without a word, the box being off.
            if (!$sendEmail && $given !== []) {
                $errors[] = 'email_template: only with sends_email - the status sends no email, so the template '
                    . 'would be stored empty; give sends_email: true in the same call';
            }
            $templates = self::orderStatusTemplates($templates, $languages, $probe, $sendEmail, $errors);
        }

        if ($errors !== [] || $state === null) {
            return $plan;
        }

        self::compareStatus($state, $names, $color, $boxes, $templates, $sendEmail, $plan);

        // The same check the core runs before update(), on the whole object as
        // it will be written, as a last net for a rule none of the above knew.
        if ($plan['values'] !== []) {
            $candidate = clone $state;
            self::fill($candidate, $plan['values']);
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'order status: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * Keeps the values that differ from the shop, and records each one.
     *
     * @param OrderState $state loaded in every language
     * @param array<int, string>|null $names by language identifier
     * @param string|null $color
     * @param array<string, bool> $boxes by column
     * @param array<int, string>|null $templates by language identifier
     * @param bool $sendEmail the email box as it will be
     * @param array<string, mixed> $plan
     *
     * @return void
     */
    private static function compareStatus(OrderState $state, ?array $names, ?string $color, array $boxes, ?array $templates, bool $sendEmail, array &$plan): void
    {
        $languages = $plan['languages'];

        if ($names !== null) {
            $current = self::byLanguage($state->name, $languages);
            $merged = $current;
            foreach ($names as $idLang => $name) {
                if (($current[$idLang] ?? '') === $name) {
                    continue;
                }
                $merged[$idLang] = $name;
                self::record($plan, 'name (' . $languages['filled'][$idLang] . ')', $current[$idLang] ?? '', $name);
            }
            if ($merged !== $current) {
                $plan['values']['name'] = $merged;
            }
        }

        if ($color !== null && Tools::strtolower((string) $state->color) !== Tools::strtolower($color)) {
            self::record($plan, 'color', (string) $state->color, $color);
            $plan['values']['color'] = $color;
        }

        $arguments = array_flip(self::ORDER_STATUS_BOXES);
        foreach ($boxes as $column => $on) {
            if ((bool) $state->{$column} === $on) {
                continue;
            }
            self::record($plan, $arguments[$column], (bool) $state->{$column}, $on);
            $plan['values'][$column] = $on;
        }

        // The templates are what the box makes them: emptied in every language
        // when this call turns it off, as the 8.0.1 form blanks them; otherwise
        // the ones given replace the stored ones in their language. A box that
        // stays off leaves the stored templates alone, so a status read with
        // the list and given back as it is changes nothing.
        $current = self::byLanguage($state->template, $languages);
        $merged = $current;
        if (!$sendEmail && (bool) $state->send_email) {
            $merged = array_fill_keys(array_keys($merged), '');
        } else {
            foreach ($templates ?? [] as $idLang => $template) {
                $merged[$idLang] = $template;
            }
        }
        foreach ($merged as $idLang => $template) {
            if (($current[$idLang] ?? '') !== $template) {
                $iso = (string) ($languages['filled'][$idLang] ?? Language::getIsoById((int) $idLang));
                self::record($plan, 'email_template (' . $iso . ')', $current[$idLang] ?? '', $template);
            }
        }
        if ($merged !== $current) {
            $plan['values']['template'] = $merged;
        }

        if ($plan['remove_icon'] && is_file(self::iconPath((int) $state->id))) {
            self::record($plan, 'icon', 'stored', 'removed');
        }
    }

    /**
     * One translated field of the status, in every language of the shop.
     *
     * Loaded without a language, the field is one text per language; read as
     * one anyway, should a version hand back a single string.
     *
     * @param mixed $value
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     *
     * @return array<int, string>
     */
    private static function byLanguage($value, array $languages): array
    {
        $texts = [];
        foreach ($languages['all'] as $idLang) {
            $texts[(int) $idLang] = is_array($value)
                ? (string) ($value[$idLang] ?? '')
                : ((int) $idLang === (int) $languages['fallback'] ? (string) $value : '');
        }

        return $texts;
    }

    /**
     * Where the back office keeps the icon of a status.
     *
     * @param int $idOrderState
     *
     * @return string
     */
    private static function iconPath(int $idOrderState): string
    {
        return _PS_IMG_DIR_ . 'os/' . $idOrderState . '.gif';
    }

    /**
     * Writes the change, then the icon, and answers with what was stored.
     *
     * @param OrderState $state
     * @param array<string, mixed> $plan
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function applyUpdate(OrderState $state, array $plan, int $idEmployee): array
    {
        $idOrderState = (int) $state->id;

        if ($plan['values'] !== []) {
            self::fill($state, $plan['values']);
            try {
                $done = $state->update();
                $failure = $done ? '' : 'PrestaShop refused to save it';
            } catch (Throwable $e) {
                $done = false;
                $failure = $e->getMessage();
            }
            if (!$done) {
                SomcpLogger::log(
                    'order_status_update_failed',
                    SomcpLogger::OUTCOME_FAILURE,
                    'order status ' . $idOrderState . ': ' . Tools::substr($failure, 0, 500),
                    '',
                    $idEmployee
                );

                return SomcpTool::text(
                    'The order status could not be changed: ' . $failure . '. The icon was not touched. Read it '
                        . 'again with somcp_list_order_statuses, tell the merchant what it holds now, and do not call '
                        . 'again before.',
                    true
                );
            }
        }

        // The list's remembered copies would still show the status as it was.
        self::forgetOrderStatusLists($plan['languages']['all']);

        $result = [
            'changed' => true,
            'status_id' => $idOrderState,
            'changes' => $plan['changes'],
        ];

        // The icon comes last, as at creation: a refused file leaves the
        // current icon and the fields above stand, and the answer says so.
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
            } else {
                $result['changes'][] = ['field' => 'icon', 'old' => null, 'new' => 'stored'];
            }
            $result['icon'] = $icon;
        } elseif ($plan['remove_icon'] && is_file(self::iconPath($idOrderState))) {
            $result['icon'] = @unlink(self::iconPath($idOrderState))
                ? ['removed' => true]
                : ['refused' => 'the shop could not delete the file'];
        }

        if ($result['changes'] === []) {
            // Only an icon was given, and it was refused: nothing changed.
            $result['changed'] = false;
        }

        SomcpLogger::log(
            'order_status_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'order status ' . $idOrderState . ', ' . count($result['changes']) . ' change(s)',
            '',
            $idEmployee
        );

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $result['status'] = self::status($idOrderState, (int) $plan['languages']['fallback']);

        return SomcpTool::json($result);
    }

    /**
     * @param array<string, mixed> $plan
     * @param string $field
     * @param mixed $old
     * @param mixed $new
     *
     * @return void
     */
    private static function record(array &$plan, string $field, $old, $new): void
    {
        $plan['changes'][] = ['field' => $field, 'old' => $old, 'new' => $new];
    }
}
