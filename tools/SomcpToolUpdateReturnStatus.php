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
 * somcp_update_return_status - one existing return status, changed with
 * everything somcp_create_return_status can write.
 *
 * It extends the creation tool rather than copying it: a field accepted at
 * creation is accepted here under the same name and checked by the same
 * code, so the two tools cannot come to disagree on what a valid name or
 * colour is.
 *
 * Only what is given changes. Each value is compared with what the shop
 * holds before anything is written, which gives the answer its old and new
 * values and lets a call that would change nothing write nothing at all:
 * the answer says changed: false. A name another return status already
 * has in that language is refused, as at creation - the status's own name
 * is not a duplicate, it is not a change at all.
 *
 * The write goes through OrderReturnState::update(), as the Edit form of
 * the back office does on both versions. Return statuses are not tied to
 * a shop - the table has no shop column - so there is no shop test and no
 * shop list to set, unlike the catalogue writes.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolCreateReturnStatus.php';

class SomcpToolUpdateReturnStatus extends SomcpToolCreateReturnStatus
{
    /** @var array<int, string> What this tool takes besides the fields of the creation. */
    private const OWN_ARGUMENTS = ['id_order_return_state'];

    /**
     * @var array<int, string> What somcp_list_return_statuses returns and this tool refuses, because the shop
     *                         works it out. The identifier of the creation is this tool's own argument here.
     */
    private const UPDATE_COMPUTED = ['removable'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_return_status',
            Somcp::t('Change a return status'),
            'Changes one existing return status of this shop: a step a merchandise return goes through, '
                . 'with everything somcp_create_return_status can write, under the same names and with the '
                . 'same rules - name and color. Only what is given changes: a value left out is kept, a name '
                . 'is replaced in the languages filled and kept in the others. When languages is left out '
                . 'every language of the shop is filled, so a name must then be given in each; to change one '
                . 'language, pass languages with it alone. '
                . 'Take the status from somcp_list_return_statuses, never guessed. Every return status can '
                . 'be changed, the five the shop starts with included. A name another return status already '
                . 'has in the same language is refused, so the merchant can always tell the steps apart. '
                . 'Every problem is reported at once and nothing is written until the whole call is valid. '
                . 'The answer gives each field changed with its old and new value, and the status as '
                . 'somcp_list_return_statuses lists it. A call that would change nothing writes nothing and '
                . 'says changed: false. '
                . 'Do not use it to create a return status (somcp_create_return_status), and not for the '
                . 'statuses of an order: a return status is not an order status. One return status per call. '
                . 'Not to be retried blindly: if the answer is lost, read the status again with '
                . 'somcp_list_return_statuses and compare before calling again.',
            self::inputSchema(),
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Changing a return status is the edit right of the statuses page
            // in Team, Permissions; creating one is a different right.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminStatuses')
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

        $properties['name']['description'] = 'Merchant. The name of the step, shown wherever a return is '
            . 'followed, 64 characters at most, without < > = { }. One text per language being filled, '
            . 'keyed by ISO code: {"fr": "...", "en": "..."}.' . $kept;
        $properties['color']['description'] .= $kept;

        $schema['properties'] = [
            'id_order_return_state' => ['type' => 'integer', 'description' => 'The return status to change, from somcp_list_return_statuses.'],
        ] + $properties;
        $schema['required'] = ['id_order_return_state'];

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
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $status = self::existingStatus(self::integer($arguments['id_order_return_state'] ?? null) ?? 0);
        if ($status === null) {
            return SomcpTool::text('No return status has this identifier. Nothing was changed. Find it with somcp_list_return_statuses.', true);
        }

        $errors = [];
        $plan = self::planUpdate($arguments, $status, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was changed. Correct these and call again:\n- " . implode("\n- ", array_values(array_unique($errors))),
                true
            );
        }

        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_order_return_state' => (int) $status->id,
                'changes' => [],
                'status' => self::status((int) $status->id, (int) $plan['languages']['fallback']),
                'reason' => 'The return status already holds every value given. Nothing was written.',
            ]);
        }

        return self::asEmployee($idEmployee, static function () use ($status, $plan, $idEmployee): array {
            return self::applyUpdate($status, $plan, $idEmployee);
        });
    }

    /**
     * The status of this identifier, or null when the shop has none.
     *
     * No shop test, unlike the catalogue reads: return statuses are not
     * tied to a shop, the table has no shop column.
     *
     * @param int $idReturnState
     *
     * @return OrderReturnState|null
     */
    private static function existingStatus(int $idReturnState): ?OrderReturnState
    {
        if ($idReturnState < 1) {
            return null;
        }

        $status = new OrderReturnState($idReturnState);

        return (int) $status->id === $idReturnState ? $status : null;
    }

    /**
     * Turns the arguments into what will be written, or says everything that is wrong with them.
     *
     * Nothing is written here. A value equal to what the shop holds is left
     * out of the plan, so what remains is exactly what will change.
     *
     * @param array<string, mixed> $arguments
     * @param OrderReturnState $status loaded in every language
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function planUpdate(array $arguments, OrderReturnState $status, array &$errors): ?array
    {
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::RETURN_STATUS_ARGUMENTS, true) && !in_array($key, self::OWN_ARGUMENTS, true)) {
                $errors[] = $key . (in_array($key, self::UPDATE_COMPUTED, true)
                    ? ': worked out by the shop, so it is not written here'
                    : ': not a field of this tool');
            }
        }

        $languages = self::languages($arguments, (int) Somcp::currentShop()->id, $errors);
        if ($languages === null) {
            return null;
        }

        $plan = [
            'languages' => $languages,
            'names' => null,
            'color' => null,
            'changes' => [],
        ];

        if (array_key_exists('name', $arguments)) {
            // Required once given: a name passed with an empty text in a
            // language being filled is refused, as at creation.
            $names = self::texts($arguments, 'name', 'name', $languages, $errors, true);
            if ($names !== null) {
                $plan['given_names'] = self::returnStatusNames($names, $languages, new OrderReturnState(), $errors, (int) $status->id);
            }
        }

        if (array_key_exists('color', $arguments)) {
            $color = trim((string) (self::string($arguments['color']) ?? ''));
            // The same check as at creation: Validate::isColor() also lets a
            // bare word through, which the colour picker of the form never
            // stores - only the hex code is.
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color) || !Validate::isColor($color)) {
                $errors[] = 'color: a hex code such as #E84C3D';
            } else {
                $plan['given_color'] = $color;
            }
        }

        if ($errors !== []) {
            return $plan;
        }

        self::compareStatus($status, $plan);

        // The same check the core runs before update(), on the whole object
        // as it will be written, as a last net for a rule none of the above
        // knew.
        if ($plan['names'] !== null || $plan['color'] !== null) {
            $candidate = clone $status;
            if ($plan['names'] !== null) {
                self::fill($candidate, ['name' => $plan['names']]);
            }
            if ($plan['color'] !== null) {
                $candidate->color = $plan['color'];
            }
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'return status: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * Keeps the names and the colour that differ from the shop, and records each one.
     *
     * @param OrderReturnState $status loaded in every language
     * @param array<string, mixed> $plan
     *
     * @return void
     */
    private static function compareStatus(OrderReturnState $status, array &$plan): void
    {
        $languages = $plan['languages'];

        if (isset($plan['given_names'])) {
            // Loaded without a language, the name is one text per language;
            // read as one anyway, should a version hand back a single string.
            $current = is_array($status->name) ? $status->name : [(int) $languages['fallback'] => (string) $status->name];
            $merged = $current;
            foreach ($plan['given_names'] as $idLang => $name) {
                $old = (string) ($current[$idLang] ?? '');
                if ($old === $name) {
                    continue;
                }
                $merged[$idLang] = $name;
                self::record($plan, 'name (' . $languages['filled'][$idLang] . ')', $old, $name);
            }
            if ($merged !== $current) {
                $plan['names'] = $merged;
            }
        }

        if (isset($plan['given_color']) && $plan['given_color'] !== (string) $status->color) {
            self::record($plan, 'color', (string) $status->color, $plan['given_color']);
            $plan['color'] = $plan['given_color'];
        }
    }

    /**
     * Writes the change and answers with what was stored.
     *
     * @param OrderReturnState $status
     * @param array<string, mixed> $plan
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function applyUpdate(OrderReturnState $status, array $plan, int $idEmployee): array
    {
        $idReturnState = (int) $status->id;
        if ($plan['names'] !== null) {
            self::fill($status, ['name' => $plan['names']]);
        }
        if ($plan['color'] !== null) {
            $status->color = $plan['color'];
        }

        try {
            $done = $status->update();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        if (!$done) {
            SomcpLogger::log(
                'return_status_update_failed',
                SomcpLogger::OUTCOME_FAILURE,
                'return status ' . $idReturnState . ': ' . Tools::substr($failure, 0, 500),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The return status could not be changed: ' . $failure . '. The name may be changed in some '
                    . 'languages only. Read it again with somcp_list_return_statuses, tell the merchant what '
                    . 'it holds now, and do not call again before.',
                true
            );
        }

        SomcpLogger::log(
            'return_status_updated',
            SomcpLogger::OUTCOME_SUCCESS,
            'return status ' . $idReturnState . ', ' . count($plan['changes']) . ' change(s)',
            '',
            $idEmployee
        );

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        return SomcpTool::json([
            'changed' => true,
            'id_order_return_state' => $idReturnState,
            'changes' => $plan['changes'],
            'status' => self::status($idReturnState, (int) $plan['languages']['fallback']),
        ]);
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
