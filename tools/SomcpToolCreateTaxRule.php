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
 * somcp_create_tax_rule - one tax rule of International > Taxes > Tax Rules,
 * with its Name and Enable, as Add new tax rule then Save and stay do.
 *
 * The rule is created without rows: the countries it taxes are added to it
 * afterwards, as the back office only shows them once the rule is saved. It
 * answers with the rule read back through somcp_get_tax_rule, so the creation
 * and the read give the same fields under the same names.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in:
// the answer is the rule as somcp_get_tax_rule reads it.
require_once __DIR__ . '/SomcpToolGetTaxRule.php';

class SomcpToolCreateTaxRule
{
    /** @var array<int, string> Every argument this tool takes. */
    private const ARGUMENTS = ['name', 'active'];

    /**
     * @var array<int, string> what somcp_get_tax_rule returns and the shop works out, named so the
     *                         assistant learns why it is dropped rather than meeting an "unknown field";
     *                         public so somcp_update_tax_rule refuses the same fields
     */
    public const COMPUTED = [
        'id_tax_rules_group', 'date_add', 'date_upd', 'country_rules', 'rate_in_default_country',
        'default_country', 'taxes_enabled', 'language',
    ];

    /**
     * The size of the name column in TaxRulesGroup::$definition, used only when
     * the column itself cannot be read.
     *
     * @var int
     */
    private const NAME_MAX = 64;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_tax_rule',
            Somcp::t('Create a tax rule'),
            'Creates one tax rule of International > Taxes > Tax Rules with its Name and Enable, without '
                . 'countries: products then take its id_tax_rules_group. Read somcp_list_tax_rules first: '
                . 'PrestaShop accepts two rules of the same name. The name is the merchant\'s - ask, never '
                . 'invent. Left out, Enable is No, as in the form. One rule per call. Not to be retried: if the '
                . 'answer is lost, look for the name in somcp_list_tax_rules, with active true and with active '
                . 'false, before anything else. To give a rule to a product use somcp_update_product.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Merchant. Required. The Name of the rule, at most the size of the '
                            . 'Name column (64 characters on PrestaShop 9, 50 on some 8.0 shops), without < > = { }.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Enable: true offers the rule on the product page. false by '
                            . 'default, as in the form.',
                    ],
                ],
                'required' => ['name'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Adding a rule is what the tax rules page asks for in Team,
            // Permissions; editing one is a different right.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminTaxRulesGroup')
        );
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

        // Every problem is gathered before anything is written, so a refused
        // call leaves the shop as it was and the assistant fixes all at once.
        $errors = SomcpTool::unknownFields($arguments, self::ARGUMENTS, self::COMPUTED);
        $name = self::name($arguments, $errors);
        $active = false;
        if (array_key_exists('active', $arguments)) {
            // A boolean only: "false" cast to bool is true, and would offer
            // a rule the merchant wanted kept off the product page.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } else {
                $active = $arguments['active'];
            }
        }
        if ($errors !== [] || $name === null) {
            return SomcpTool::refused('created', $errors);
        }

        $rule = new TaxRulesGroup();
        $rule->name = $name;
        $rule->active = $active;
        // The shop of the token, never the context's list, so on a multistore
        // installation the rule goes to that shop only, as the form saves it
        // in one shop's context.
        $rule->id_shop_list = [$idShop];

        try {
            $done = $rule->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        if (!$done) {
            $kept = false;
            if ((int) $rule->id > 0) {
                try {
                    $kept = !$rule->delete();
                } catch (Throwable $e) {
                    $kept = true;
                }
            }
            SomcpLogger::log(
                'tax_rule_create_failed',
                SomcpLogger::OUTCOME_FAILURE,
                Tools::substr($failure, 0, 500) . ($kept ? ', tax rule ' . (int) $rule->id . ' could not be removed' : ''),
                '',
                $idEmployee
            );

            return SomcpTool::text(
                'The tax rule could not be created: ' . $failure . '. '
                    . ($kept
                        ? 'It could not be removed either: tax rule ' . (int) $rule->id . ' exists in an '
                            . 'incomplete state. Tell the merchant to check it in the back office; do not call again.'
                        : 'Nothing was kept.'),
                true
            );
        }

        $idGroup = (int) $rule->id;
        SomcpLogger::log('tax_rule_created', SomcpLogger::OUTCOME_SUCCESS, 'tax rule ' . $idGroup, '', $idEmployee);

        $result = ['created' => true];
        if (!array_key_exists('active', $arguments)) {
            // Said, so the merchant is not surprised that the product page
            // does not offer the rule yet.
            $result['note'] = 'Created disabled, as the form does when Enable is left on No: the product page '
                . 'does not offer it until it is enabled.';
        }

        // Read back rather than echoed from the arguments: what the assistant
        // shows the merchant is what the shop stored.
        $language = SomcpTool::readLanguage([], $idShop);
        if ($language !== null) {
            $result['tax_rule'] = SomcpToolGetTaxRule::rule($idGroup, $language[0], $language[1], $idShop);
        } else {
            $result['id_tax_rules_group'] = $idGroup;
        }

        return SomcpTool::json($result);
    }

    /**
     * The size of the name column of this shop's database.
     *
     * The class says 64, but some 8.0 shops still have the 50-character
     * column of their install: a longer name passes the class check, then is
     * cut or refused by the database, so the rule saved is not the name given.
     *
     * @return int
     */
    private static function nameMax(): int
    {
        // executeS rather than getRow, which appends a LIMIT that SHOW COLUMNS
        // does not take.
        $columns = Db::getInstance()->executeS(
            'SHOW COLUMNS FROM `' . bqSQL(_DB_PREFIX_ . 'tax_rules_group') . "` LIKE 'name'"
        );
        if (is_array($columns) && isset($columns[0]['Type'])
            && preg_match('/^varchar\((\d+)\)/i', (string) $columns[0]['Type'], $match)) {
            return (int) $match[1];
        }

        return self::NAME_MAX;
    }

    /**
     * The name given in the call, or null with the reason.
     *
     * Public so somcp_update_tax_rule checks a new name here, against the
     * same column, rather than with a second copy that could drift.
     *
     * @param array<string, mixed> $arguments
     * @param array<int, string> $errors
     *
     * @return string|null
     */
    public static function name(array $arguments, array &$errors): ?string
    {
        if (!array_key_exists('name', $arguments) || $arguments['name'] === null) {
            $errors[] = 'name: required';

            return null;
        }
        if (!is_string($arguments['name'])) {
            $errors[] = 'name: a text';

            return null;
        }
        $name = trim($arguments['name']);
        if ($name === '') {
            $errors[] = 'name: empty - give the Name of the rule';

            return null;
        }
        $max = self::nameMax();
        if (Tools::strlen($name) > $max) {
            $errors[] = 'name: ' . $max . ' characters at most, ' . Tools::strlen($name) . ' given';

            return null;
        }
        // TaxRulesGroup::$name is checked with isGenericName: refused here
        // rather than by add(), so it is listed with the other problems.
        if (!Validate::isGenericName($name)) {
            $errors[] = 'name: a character PrestaShop refuses in a tax rule name (< > = { })';

            return null;
        }

        return $name;
    }
}
