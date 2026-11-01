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
 * somcp_create_return_status - one return status, with the two fields of
 * the back office return status form.
 *
 * It extends the feature creation tool for what a return status shares with
 * the other writes and must not come to disagree on: the languages being
 * filled, texts keyed by ISO code, a language left out taking the default
 * one's text, and the employee of the token carried on the write.
 *
 * A return status is not an order status. The two lists sit on the same
 * back office page, which is exactly why they get confused, and the tool
 * says so wherever it can: creating here never creates an order status.
 *
 * Everything is checked before anything is written, and every problem is
 * reported at once - a name another return status already has in that
 * language included, because two steps the merchant cannot tell apart are
 * a list nobody can work with. The status is written through
 * OrderReturnState::add(), as the back office does on PrestaShop 8
 * (AdminStatusesController) and 9 (AddOrderReturnStateHandler), and deleted
 * again if that fails after the row went in.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolCreateFeature.php';

class SomcpToolCreateReturnStatus extends SomcpToolCreateFeature
{
    /** @var array<int, string> Every argument this tool takes. */
    protected const RETURN_STATUS_ARGUMENTS = ['languages', 'name', 'color'];

    /**
     * @var array<int, string> What somcp_list_return_statuses returns and this tool refuses, because the shop
     *                         works it out. Named, so the assistant learns why rather than meeting an "unknown field".
     */
    protected const RETURN_STATUS_COMPUTED = ['id_order_return_state', 'removable'];

    /** @var int The size of the name column (OrderReturnStateSettings::NAME_MAX_LENGTH). */
    protected const RETURN_STATUS_NAME_MAX = 64;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_return_status',
            Somcp::t('Create a return status'),
            'Creates one return status in this shop: a step a merchandise return goes through, with the '
                . 'fields of the Add new return status form of Shop Parameters > Order Settings > Statuses - '
                . 'the name, and the colour the status is highlighted in. Only name is required. '
                . 'These statuses are the steps of a return, not of an order: do not use it for the '
                . 'statuses of an order, which somcp_list_order_statuses lists and no tool here creates. '
                . 'Read somcp_list_return_statuses first: a name another return status already has in the '
                . 'same language is refused, so the merchant can always tell the steps apart. '
                . 'Who fills each field is said at the start of its description, and is a rule for you: '
                . '"Merchant" - ask the merchant and never invent the value; you may suggest one for them to '
                . 'confirm. '
                . 'Texts are objects keyed by ISO code and must be given for every language in languages; a '
                . 'language of the shop left out takes the default language\'s text. '
                . 'Every problem is reported at once and nothing is written until the whole call is valid. '
                . 'One return status per call: for several, call it once per status and report each result. '
                . 'Not to be retried: if the answer is lost, read somcp_list_return_statuses and look for '
                . 'the name before anything else.',
            self::inputSchema(),
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Adding a return status is what the statuses page asks for in
            // Team, Permissions; the same page as the order statuses.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminStatuses')
        );
    }

    /**
     * The arguments, under the names somcp_list_return_statuses returns.
     *
     * @return array<string, mixed>
     */
    protected static function inputSchema(): array
    {
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
                    'description' => 'Merchant. Required. The name of the step, shown wherever a return is '
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
        $plan = self::planReturnStatus($arguments, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::text(
                "Nothing was created. Correct these and call again:\n- " . implode("\n- ", $errors),
                true
            );
        }

        return self::asEmployee($idEmployee, static function () use ($plan, $idEmployee): array {
            return self::addReturnStatus($plan, $idEmployee);
        });
    }

    /**
     * Turns the arguments into what will be written, or says everything that is wrong with them.
     *
     * Nothing is written here, so a refused call leaves the shop exactly as it was.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    private static function planReturnStatus(array $arguments, int $idShop, array &$errors): ?array
    {
        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (!in_array($key, self::RETURN_STATUS_ARGUMENTS, true)) {
                $errors[] = $key . (in_array($key, self::RETURN_STATUS_COMPUTED, true)
                    ? ': worked out by the shop, so it is not written here'
                    : ': not a field of this tool');
            }
        }

        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }

        $probe = new OrderReturnState();
        $plan = [
            'languages' => $languages,
            'texts' => [],
            'color' => '',
        ];

        $names = self::texts($arguments, 'name', 'name', $languages, $errors, true);
        if ($names !== null) {
            $plan['texts']['name'] = self::returnStatusNames($names, $languages, $probe, $errors);
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

        // The same check the core runs before add(), on the whole object, as a
        // last net for a rule of the running version none of the above knew.
        if ($errors === []) {
            $candidate = new OrderReturnState();
            self::fill($candidate, self::completeTexts(['name' => $plan['texts']['name']], $languages));
            $candidate->color = $plan['color'];
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'return status: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * The names given in the call, checked with the running version's rules, then against the shop's own.
     *
     * A name another return status already has in that language is refused:
     * the steps are told apart by their name alone on the returns page. The
     * status being changed is left out of that check: keeping its own name is
     * not a change, and giving it back is how a call is repeated safely.
     *
     * @param array<int, string> $names by language identifier
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param OrderReturnState $probe
     * @param array<int, string> $errors
     * @param int $idSelf the status being changed, 0 at creation
     *
     * @return array<int, string>
     */
    protected static function returnStatusNames(array $names, array $languages, OrderReturnState $probe, array &$errors, int $idSelf = 0): array
    {
        $taken = [];
        foreach (array_keys($languages['filled']) as $idLang) {
            $taken[$idLang] = [];
            foreach (OrderReturnState::getOrderReturnStates($idLang) as $state) {
                if ($idSelf > 0 && (int) $state['id_order_return_state'] === $idSelf) {
                    continue;
                }
                $taken[$idLang][Tools::strtolower(trim((string) $state['name']))] = (int) $state['id_order_return_state'];
            }
        }

        foreach ($names as $idLang => $name) {
            $name = trim($name);
            $names[$idLang] = $name;
            $where = 'name (' . $languages['filled'][$idLang] . ')';
            if (Tools::strlen($name) > self::RETURN_STATUS_NAME_MAX) {
                $errors[] = $where . ': ' . self::RETURN_STATUS_NAME_MAX . ' characters at most, ' . Tools::strlen($name) . ' given';
            } elseif ($name !== '' && ($check = $probe->validateField('name', $name, $idLang)) !== true) {
                // OrderReturnState::$name is checked with isGenericName.
                $errors[] = $where . ': a character PrestaShop refuses in a return status name (< > = { }) - ' . self::reason($check);
            } elseif (isset($taken[$idLang][Tools::strtolower($name)])) {
                $errors[] = $where . ': a return status already has this name, id_order_return_state '
                    . $taken[$idLang][Tools::strtolower($name)] . ' - the merchant could not tell the two steps apart';
            }
        }

        return $names;
    }

    /**
     * Writes the status and answers with what was stored.
     *
     * Return statuses are not tied to a shop - the table has no shop column -
     * so there is no shop list to set, unlike the catalogue writes.
     *
     * @param array<string, mixed> $plan
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function addReturnStatus(array $plan, int $idEmployee): array
    {
        $state = new OrderReturnState();
        self::fill($state, self::completeTexts(['name' => $plan['texts']['name']], $plan['languages']));
        $state->color = (string) $plan['color'];

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
                'return_status_create_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr($failure, 0, 500) . ($kept ? ', return status ' . (int) $state->id . ' could not be removed' : ''),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The return status could not be created: ' . $failure . '. '
                    . ($kept
                        ? 'It could not be removed either: return status ' . (int) $state->id . ' exists in an '
                            . 'incomplete state. Tell the merchant to check it in the back office; do not call again.'
                        : 'Nothing was kept.'),
                true
            );
        }

        $idReturnState = (int) $state->id;
        SomcpLogger::log('return_status_created', SomcpLogger::OUTCOME_SUCCESS, 'return status ' . $idReturnState, '', $idEmployee);

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        return SomcpTool::json([
            'created' => true,
            'id_order_return_state' => $idReturnState,
            'status' => self::status($idReturnState, (int) $plan['languages']['fallback']),
        ]);
    }

    /**
     * One status as somcp_list_return_statuses lists it, read the way it reads.
     *
     * Protected so the update tool answers with the same row, the removable
     * rule included, instead of keeping its own copy.
     *
     * @param int $idReturnState
     * @param int $idLang
     *
     * @return array<string, mixed>
     */
    protected static function status(int $idReturnState, int $idLang): array
    {
        foreach (OrderReturnState::getOrderReturnStates($idLang) as $state) {
            if ((int) $state['id_order_return_state'] === $idReturnState) {
                return [
                    'id_order_return_state' => $idReturnState,
                    'name' => (string) $state['name'],
                    'color' => (string) $state['color'],
                    // The five statuses every shop starts with cannot be
                    // removed: both back offices hide Delete on 1 to 5.
                    'removable' => $idReturnState < 1 || $idReturnState > 5,
                ];
            }
        }

        // The row was written a moment ago; not finding it means the read
        // above raced something. Answer from the object rather than failing
        // a write that did go through.
        $stored = new OrderReturnState($idReturnState, $idLang);

        return [
            'id_order_return_state' => $idReturnState,
            'name' => (string) $stored->name,
            'color' => (string) $stored->color,
            'removable' => $idReturnState < 1 || $idReturnState > 5,
        ];
    }
}
