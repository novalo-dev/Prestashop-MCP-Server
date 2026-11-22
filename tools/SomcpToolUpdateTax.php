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
 * somcp_update_tax - one tax of International > Taxes > Taxes, as its Edit
 * then Save or the Enabled switch of its row: Name, Rate, Enable. Only what
 * is given changes; a call that would change nothing writes nothing.
 *
 * It extends the tax creation tool: every field is checked by the same
 * functions, called, never copied, and the answer is the same shared row of
 * somcp_list_taxes.
 *
 * Two edges of the core the tool must say. A tax an order line carries is kept
 * for that order: the change goes on a copy under a new identifier, which the
 * answer gives. And on PrestaShop 8, disabling a tax that tax rules charge
 * deletes those rows from every rule without a word, so those countries stop
 * being charged the tax; the tool refuses it, so the merchant moves the rows
 * first. On PrestaShop 9 the rows stay and the call goes through.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends.
require_once __DIR__ . '/SomcpToolCreateTax.php';

class SomcpToolUpdateTax extends SomcpToolCreateTax
{
    /** @var array<int, string> Every argument this tool takes, under the names somcp_list_taxes returns. */
    protected const UPDATE_ARGUMENTS = ['id_tax', 'languages', 'name', 'rate', 'active'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_tax',
            Somcp::t('Change a tax'),
            'Changes one tax of International > Taxes > Taxes, as its Edit then Save or the Enabled switch of its '
                . 'row: Name, Rate, Enable. Only the fields given change; the rest stay. id_tax from '
                . 'somcp_list_taxes. Changing a rate changes it for every tax rule that charges the tax, without '
                . 'touching the rules. Not for the tax one country charges in a rule (somcp_update_country_rule), '
                . 'nor to delete a tax (somcp_delete_tax). Replayable: the same call twice changes nothing the '
                . 'second time.',
            self::updateSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Editing a tax is what the taxes page asks for in Team,
            // Permissions; adding one is a different right.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminTaxes')
        );
    }

    /**
     * The arguments: the creation schema, with id_tax added and nothing required but it.
     *
     * @return array<string, mixed>
     */
    protected static function updateSchema(): array
    {
        $schema = self::inputSchema();
        $schema['properties'] = [
            'id_tax' => [
                'type' => 'integer',
                'description' => 'The tax to change, from somcp_list_taxes.',
            ],
        ] + $schema['properties'];
        // Only what is given changes, so nothing but the identifier is required.
        $schema['required'] = ['id_tax'];

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

        $idTax = SomcpTool::id($arguments, 'id_tax');
        $tax = $idTax > 0 ? new Tax($idTax) : null;

        $errors = [];
        if ($idTax === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_tax');
        } elseif ($tax === null || !Validate::isLoadedObject($tax) || (int) $tax->deleted === 1) {
            $errors[] = SomcpTool::notFound('id_tax', 'tax', $idTax, 'somcp_list_taxes');
            $tax = null;
        }

        $plan = self::planUpdateTax($arguments, $tax, $idShop, $errors);
        if ($errors !== [] || $plan === null || $tax === null) {
            return SomcpTool::refused('changed', $errors);
        }

        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_tax' => (int) $tax->id,
                'reason' => 'The tax already holds every value given. Nothing was written.',
                'tax' => SomcpToolListTaxes::tax((int) $tax->id, (int) $plan['languages']['fallback']),
            ]);
        }

        return self::asEmployee($idEmployee, static function () use ($tax, $plan, $idEmployee): array {
            return self::applyUpdateTax($tax, $plan, $idEmployee);
        });
    }

    /**
     * Turns the arguments into what will change, or says everything that is wrong with them.
     *
     * Nothing is written here. A field equal to what the shop holds is left out
     * of the changes, so what remains is exactly what will change. Run even
     * when the tax is unknown, so every problem of the call is told at once.
     *
     * @param array<string, mixed> $arguments
     * @param Tax|null $tax loaded in every language
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>|null
     */
    protected static function planUpdateTax(array $arguments, ?Tax $tax, int $idShop, array &$errors): ?array
    {
        array_push($errors, ...SomcpTool::unknownFields($arguments, self::UPDATE_ARGUMENTS));

        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }

        $probe = new Tax();
        $plan = [
            'languages' => $languages,
            'changes' => [],
            'set' => [],
        ];

        // somcp_list_taxes answers the name as one text: given back as read, it is no change.
        if (array_key_exists('name', $arguments)
            && !($tax !== null && self::givenBackAsRead($arguments['name'], is_array($tax->name) ? $tax->name : [], $languages))) {
            $names = self::texts($arguments, 'name', 'name', $languages, $errors, true);
            if ($names !== null) {
                $names = self::taxNames($names, $languages, $probe, $errors);
                if ($tax !== null) {
                    self::compareNames($tax, $names, $languages, $plan);
                }
            }
        }

        if (array_key_exists('rate', $arguments)) {
            $rate = self::taxRate($arguments, $errors, false);
            if ($rate !== null && $tax !== null && round((float) $tax->rate, 3) !== $rate) {
                $plan['changes'][] = ['field' => 'rate', 'old' => round((float) $tax->rate, 3), 'new' => $rate];
                $plan['set']['rate'] = $rate;
            }
        }

        if (array_key_exists('active', $arguments)) {
            self::compareActive($tax, $arguments['active'], $errors, $plan);
        }

        return $plan;
    }

    /**
     * Records a name change per language, and the full set of names to write.
     *
     * A language left out keeps its name: the names to write are the stored
     * ones, with the languages given laid over them.
     *
     * @param Tax $tax loaded in every language
     * @param array<int, string> $names by language identifier, the languages given
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<string, mixed> $plan
     *
     * @return void
     */
    protected static function compareNames(Tax $tax, array $names, array $languages, array &$plan): void
    {
        $stored = is_array($tax->name) ? $tax->name : [];
        $merged = [];
        foreach ($languages['all'] as $idLang) {
            $merged[(int) $idLang] = (string) ($stored[$idLang] ?? '');
        }
        $changed = false;
        foreach ($names as $idLang => $name) {
            $current = (string) ($stored[$idLang] ?? '');
            if ($current === $name) {
                continue;
            }
            $merged[(int) $idLang] = $name;
            $changed = true;
            $plan['changes'][] = [
                'field' => 'name (' . $languages['filled'][$idLang] . ')',
                'old' => $current,
                'new' => $name,
            ];
        }
        if ($changed) {
            $plan['set']['name'] = $merged;
        }
    }

    /**
     * Records the Enable change, or refuses it on PrestaShop 8 when it would delete tax rule rows.
     *
     * The 8.0.1 core deletes every tax rule row that charges a tax when the tax
     * is disabled, in every rule, without a word: refused here so the merchant
     * moves the rows first. On 9.0.0 the rows stay, and the change goes through.
     *
     * @param Tax|null $tax
     * @param mixed $value
     * @param array<int, string> $errors
     * @param array<string, mixed> $plan
     *
     * @return void
     */
    protected static function compareActive(?Tax $tax, $value, array &$errors, array &$plan): void
    {
        if (!is_bool($value)) {
            $errors[] = 'active: true or false';

            return;
        }
        if ($tax === null) {
            return;
        }
        if ($value === false && (bool) $tax->active
            && version_compare(_PS_VERSION_, '9.0.0', '<')) {
            $charging = self::charging((int) $tax->id);
            if ($charging['rows'] > 0) {
                $errors[] = 'active: on PrestaShop 8, disabling this tax deletes its ' . $charging['rows']
                    . ' row(s) in the tax rules ' . implode(', ', $charging['rules'])
                    . ' - change their tax with somcp_update_country_rule first, or keep the tax enabled';

                return;
            }
        }
        if ((bool) $tax->active !== $value) {
            $plan['changes'][] = ['field' => 'active', 'old' => (bool) $tax->active, 'new' => $value];
            $plan['set']['active'] = $value;
        }
    }

    /**
     * The tax rule rows that charge a tax, and the rules they belong to.
     *
     * Public so somcp_delete_tax refuses a tax for the same rows, counted the
     * same way, rather than with a second copy that could drift.
     *
     * @param int $idTax
     *
     * @return array{rows: int, rules: array<int, int>}
     */
    public static function charging(int $idTax): array
    {
        $db = Db::getInstance();
        $rows = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'tax_rule` WHERE `id_tax` = ' . (int) $idTax
        );
        $rules = [];
        foreach ($db->executeS(
            'SELECT DISTINCT `id_tax_rules_group` FROM `' . _DB_PREFIX_ . 'tax_rule`'
            . ' WHERE `id_tax` = ' . (int) $idTax . ' ORDER BY `id_tax_rules_group` ASC'
        ) ?: [] as $row) {
            $rules[] = (int) $row['id_tax_rules_group'];
        }

        return ['rows' => $rows, 'rules' => $rules];
    }

    /**
     * Writes the change and answers with what was stored.
     *
     * On a tax an order line carries, the core keeps the old tax for that order
     * and writes the change on a new one: the new identifier is read back and
     * reported, and the tax rules the core moved to it are checked, so a row
     * left on the hidden old tax is said rather than missed.
     *
     * @param Tax $tax loaded in every language
     * @param array<string, mixed> $plan
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    protected static function applyUpdateTax(Tax $tax, array $plan, int $idEmployee): array
    {
        $oldId = (int) $tax->id;
        $usedBefore = (bool) $tax->isUsed();

        if (isset($plan['set']['name'])) {
            $tax->name = $plan['set']['name'];
        }
        if (array_key_exists('rate', $plan['set'])) {
            $tax->rate = (float) $plan['set']['rate'];
        }
        if (array_key_exists('active', $plan['set'])) {
            $tax->active = (bool) $plan['set']['active'];
        }

        try {
            $done = $tax->update();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        // On a used tax the core hides the old tax and creates the new one
        // before it moves the tax rule rows, so a failed move still leaves both
        // behind. Read the rows back whatever update() said, rather than
        // telling the assistant the rules moved, or that nothing was saved.
        $copiedId = (int) $tax->id;
        if ($usedBefore && $copiedId > 0 && $copiedId !== $oldId) {
            $left = self::charging($oldId);
            if (!$done || $left['rows'] > 0) {
                SomcpLogger::log(
                    'tax_update_failed',
                    SomcpLogger::OUTCOME_FAILURE,
                    'tax ' . $oldId . ' copied to tax ' . $copiedId,
                    '',
                    $idEmployee
                );

                if ($left['rows'] === 0) {
                    return SomcpTool::text(
                        'The tax was copied to a new identifier, ' . $copiedId . ', and the old one, ' . $oldId
                            . ', is now hidden, but PrestaShop reported a failure: ' . $failure
                            . '. Read the taxes with somcp_list_taxes before calling again.',
                        true
                    );
                }

                return SomcpTool::text(
                    'The tax was copied to a new identifier, ' . $copiedId . ', but ' . $left['rows']
                        . ' row(s) of the tax rules ' . implode(', ', $left['rules'])
                        . ' still charge the old one, ' . $oldId . ', which is now hidden - give them the new '
                        . 'tax with somcp_update_country_rule.',
                    true
                );
            }
        }

        if (!$done) {
            SomcpLogger::log('tax_update_failed', SomcpLogger::OUTCOME_FAILURE, 'tax ' . $oldId, '', $idEmployee);

            return SomcpTool::text(
                'The tax could not be changed: ' . $failure . '. Read it with somcp_list_taxes before calling again.',
                true
            );
        }

        // The core gives a used tax a new identifier and moves the tax rules
        // to it, checked above; otherwise the identifier is unchanged.
        $newId = $copiedId;
        SomcpLogger::log('tax_updated', SomcpLogger::OUTCOME_SUCCESS, 'tax ' . $newId, '', $idEmployee);

        $result = [
            'changed' => true,
            'id_tax' => $newId,
        ];
        $warnings = [];
        if ($usedBefore && $newId !== $oldId) {
            $result['replaced_id_tax'] = $oldId;
            $warnings[] = 'This tax is used by orders already placed: PrestaShop keeps it unchanged for them and '
                . 'gives the tax a new identifier, ' . $newId . '. The tax rules now charge the new one.';
        }
        $result['changes'] = $plan['changes'];
        $otherShops = self::otherShops();
        if ($otherShops > 0) {
            $result['also_in_other_shops'] = $otherShops;
            $warnings[] = 'A tax is not tied to a shop: the change shows in every shop of this installation ('
                . ($otherShops + 1) . ').';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        // Read back rather than echoed: what the assistant shows is what the
        // shop stored, under the new identifier for a used tax.
        $result['tax'] = SomcpToolListTaxes::tax($newId, (int) $plan['languages']['fallback']);

        return SomcpTool::json($result);
    }
}
