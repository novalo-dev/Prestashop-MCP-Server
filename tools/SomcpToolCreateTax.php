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
 * somcp_create_tax - one tax of International > Taxes > Taxes, as Add new tax
 * then Save: a name per language, a rate, and whether it is enabled.
 *
 * It extends the feature creation tool for what a tax shares with the other
 * writes and must not come to disagree on: the languages being filled, texts
 * keyed by ISO code, a language left out taking the default one's text, and
 * the employee of the token carried on the write.
 *
 * A tax rule charges a tax through its country rows (somcp_add_country_rule,
 * id_tax): without this tool an assistant setting up a shop could only pick
 * among the taxes the install left, never make the rate a country needs. The
 * answer is the tax read back through the same row function as
 * somcp_list_taxes, so the creation and the list give the same fields.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// class below cannot be declared before the one it extends, and it answers
// with the tax as somcp_list_taxes returns it.
require_once __DIR__ . '/SomcpToolCreateFeature.php';
require_once __DIR__ . '/SomcpToolListTaxes.php';

class SomcpToolCreateTax extends SomcpToolCreateFeature
{
    /** @var array<int, string> Every argument this tool takes, under the names somcp_list_taxes returns. */
    protected const TAX_ARGUMENTS = ['languages', 'name', 'rate', 'active'];

    /**
     * @var array<int, string> What somcp_list_taxes returns and this tool refuses, because the shop works it
     *                         out. Named, so the assistant learns why rather than meeting an "unknown field".
     */
    protected const TAX_COMPUTED = ['id_tax'];

    /** @var int The size of the name column (Tax::$definition), used only when the column cannot be read. */
    protected const TAX_NAME_MAX = 32;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_tax',
            Somcp::t('Create a tax'),
            'Creates one tax of International > Taxes > Taxes, as Add new tax then Save: a Name per language, a '
                . 'Rate and Enable. A tax rule charges a tax per country: a product takes a tax rule '
                . '(somcp_create_tax_rule, somcp_add_country_rule with this id_tax), never a tax. Read '
                . 'somcp_list_taxes first: create one only when no tax holds the rate. The name is the '
                . 'merchant\'s - ask, never invent. Left out, Enable is No, as in the form. One tax per call. Not '
                . 'to be retried: if the answer is lost, look for the rate in somcp_list_taxes first.',
            self::inputSchema(),
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Adding a tax is what the taxes page asks for in Team,
            // Permissions; editing one is a different right.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminTaxes')
        );
    }

    /**
     * The arguments, under the names somcp_list_taxes returns.
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
                        . 'All of them by default. The name must then be given in each of them.',
                    'items' => ['type' => 'string'],
                ],
                'name' => [
                    'type' => 'object',
                    'description' => 'Merchant. Required. The tax name shown in carts and on invoices (e.g. VAT), '
                        . '32 characters at most, without < > = { }. One per language being filled, keyed by ISO '
                        . 'code: {"fr": "...", "en": "..."}.',
                    'additionalProperties' => ['type' => 'string'],
                ],
                'rate' => [
                    'type' => 'number',
                    'description' => 'Merchant. Required. The rate as a percentage from 0 to 100, at most three '
                        . 'decimals, for example 20 or 5.5. Stored as given: 5.5 is kept as 5.500.',
                ],
                'active' => [
                    'type' => 'boolean',
                    'description' => 'Merchant. Enable: true makes the tax available to tax rules. false by '
                        . 'default, as in the form.',
                ],
            ],
            'required' => ['name', 'rate'],
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
        $plan = self::planTax($arguments, $idShop, $errors);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::refused('created', $errors);
        }

        return self::asEmployee($idEmployee, static function () use ($plan, $idShop, $idEmployee): array {
            return self::addTax($plan, $idShop, $idEmployee);
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
    protected static function planTax(array $arguments, int $idShop, array &$errors): ?array
    {
        array_push($errors, ...SomcpTool::unknownFields($arguments, self::TAX_ARGUMENTS, self::TAX_COMPUTED));

        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return null;
        }

        $probe = new Tax();
        $plan = [
            'languages' => $languages,
            'texts' => [],
            'rate' => 0.0,
            'active' => false,
        ];

        $names = self::texts($arguments, 'name', 'name', $languages, $errors, true);
        if ($names !== null) {
            $plan['texts']['name'] = self::taxNames($names, $languages, $probe, $errors);
        }

        $rate = self::taxRate($arguments, $errors, true);
        if ($rate !== null) {
            $plan['rate'] = $rate;
        }

        if (array_key_exists('active', $arguments)) {
            // A boolean only: "false" cast to bool is true, and would enable a
            // tax the merchant wanted left off a tax rule.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } else {
                $plan['active'] = $arguments['active'];
            }
        }

        // The same check the core runs before add(), on the whole object, as a
        // last net for a rule of the running version none of the above knew.
        if ($errors === [] && isset($plan['texts']['name'])) {
            $candidate = new Tax();
            self::fill($candidate, self::completeTexts(['name' => $plan['texts']['name']], $languages));
            $candidate->rate = $plan['rate'];
            $candidate->active = $plan['active'];
            foreach ([$candidate->validateFields(false, true), $candidate->validateFieldsLang(false, true)] as $check) {
                if ($check !== true) {
                    $errors[] = 'tax: ' . strip_tags((string) $check);
                }
            }
        }

        return $plan;
    }

    /**
     * The names given in the call, checked with the running version's rules.
     *
     * Shared with somcp_update_tax, so the two tools take a name the same way.
     *
     * @param array<int, string> $names by language identifier
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param Tax $probe
     * @param array<int, string> $errors
     *
     * @return array<int, string>
     */
    protected static function taxNames(array $names, array $languages, Tax $probe, array &$errors): array
    {
        $max = SomcpTool::columnSize($probe, 'name', self::TAX_NAME_MAX);
        foreach ($names as $idLang => $name) {
            $name = trim($name);
            $names[$idLang] = $name;
            $where = 'name (' . $languages['filled'][$idLang] . ')';
            if (Tools::strlen($name) > $max) {
                $errors[] = $where . ': ' . $max . ' characters at most, ' . Tools::strlen($name) . ' given';
            } elseif ($name !== '' && ($check = $probe->validateField('name', $name, $idLang)) !== true) {
                // Tax::$name is checked with isGenericName.
                $errors[] = $where . ': a character PrestaShop refuses in a tax name (< > = { }) - ' . self::reason($check);
            }
        }

        return $names;
    }

    /**
     * The rate given in the call, or null with the reason.
     *
     * A percentage from 0 to 100, rather than the form's "numeric in 6
     * characters", which takes -5 and 999999: a negative rate or one above
     * 100 % is a typing mistake that, stored, would charge every order of the
     * countries the tax is given to. At most three decimals, what the column
     * keeps, so a fourth decimal is refused rather than stored as another rate.
     *
     * Shared with somcp_update_tax, so both take a rate the same way.
     *
     * @param array<string, mixed> $arguments
     * @param array<int, string> $errors
     * @param bool $required
     *
     * @return float|null
     */
    protected static function taxRate(array $arguments, array &$errors, bool $required): ?float
    {
        $line = 'rate: a number from 0 to 100, at most three decimals, for example 20 or 5.5';
        if (!array_key_exists('rate', $arguments) || $arguments['rate'] === null) {
            if ($required) {
                $errors[] = 'rate: required, a number from 0 to 100, at most three decimals, for example 20 or 5.5';
            }

            return null;
        }
        $value = $arguments['rate'];
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            $errors[] = $line;

            return null;
        }
        $rate = (float) $value;
        // The three decimals the column keeps: a fourth would be stored rounded.
        if ($rate < 0 || $rate > 100 || $rate !== round($rate, 3)) {
            $errors[] = $line;

            return null;
        }

        return $rate;
    }

    /**
     * Writes the tax and answers with what was stored.
     *
     * A tax is not tied to a shop - the table has no shop column - so there is
     * no shop list to set, unlike the catalogue writes, and the answer says so
     * when the installation has more than one shop.
     *
     * @param array<string, mixed> $plan
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    protected static function addTax(array $plan, int $idShop, int $idEmployee): array
    {
        $tax = new Tax();
        self::fill($tax, self::completeTexts(['name' => $plan['texts']['name']], $plan['languages']));
        $tax->rate = (float) $plan['rate'];
        $tax->active = (bool) $plan['active'];

        try {
            $done = $tax->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        if (!$done) {
            $kept = false;
            if ((int) $tax->id > 0) {
                try {
                    $kept = !$tax->delete();
                } catch (Throwable $e) {
                    $kept = true;
                }
            }
            SomcpLogger::log(
                'tax_create_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr($failure, 0, 500) . ($kept ? ', tax ' . (int) $tax->id . ' could not be removed' : ''),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The tax could not be created: ' . $failure . '. '
                    . ($kept
                        ? 'It could not be removed either: tax ' . (int) $tax->id . ' exists in an incomplete '
                            . 'state. Tell the merchant to check it in the back office; do not call again.'
                        : 'Nothing was kept. Read somcp_list_taxes before calling again.'),
                true
            );
        }

        $idTax = (int) $tax->id;
        SomcpLogger::log('tax_created', SomcpLogger::OUTCOME_SUCCESS, 'tax ' . $idTax, '', $idEmployee);

        $result = [
            'created' => true,
            'id_tax' => $idTax,
        ];
        $warnings = [];
        if (!$plan['active']) {
            $warnings[] = 'The tax is created disabled, as the form\'s default: enable it with somcp_update_tax '
                . 'before giving it to a tax rule.';
        }
        $shops = self::shopCount();
        if ($shops > 1) {
            $result['in_every_shop'] = true;
            $warnings[] = 'A tax is not tied to a shop: this tax now exists in every shop of this installation ('
                . $shops . ').';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $result['tax'] = SomcpToolListTaxes::tax($idTax, (int) $plan['languages']['fallback']);

        return SomcpTool::json($result);
    }

    /**
     * How many shops this installation has.
     *
     * A tax shows in every one of them; the answer says so past the first, so
     * the merchant is not surprised to find the tax in another shop.
     *
     * @return int
     */
    protected static function shopCount(): int
    {
        if (!Shop::isFeatureActive()) {
            return 1;
        }
        $shops = Shop::getShops(false);

        return is_array($shops) ? count($shops) : 1;
    }
}
