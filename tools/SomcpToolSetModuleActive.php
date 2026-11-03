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
 * somcp_set_module_active - the Enable and Disable buttons of a module's card
 * in Modules > Module Manager, and on PrestaShop 8 its Enable Mobile and
 * Disable Mobile actions.
 *
 * The core answers success to every one of these actions whatever the state,
 * fires the module's hooks anyway, and a second Enable Mobile corrupts the
 * device mask. So the state is read first and only the actions that change
 * something are called; a call that would change nothing is refused. The
 * answer is read back through SomcpModules, as somcp_get_module reads it.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolSetModuleActive
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_set_module_active',
            Somcp::t('Enable or disable a module'),
            'Enables or disables one installed module on this shop, as the Enable / Disable buttons of its '
                . 'card in Modules > Module Manager; on PrestaShop 8, active_on_mobile is Enable Mobile / '
                . 'Disable Mobile. Name from somcp_list_modules. Enabling again turns mobile back on: pass '
                . 'active_on_mobile false with it to keep it off. Say first that disabling a payment or carrier '
                . 'module stops orders or deliveries. Do not retry on a lost answer: read somcp_get_module. '
                . 'Installing is somcp_install_module.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Technical name of the module, its folder, as the name field of '
                            . 'somcp_list_modules returns it. Compared without case.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'true to enable the module on this shop, false to disable it.',
                    ],
                    'active_on_mobile' => [
                        'type' => 'boolean',
                        'description' => 'PrestaShop 8 only: true to enable the module on mobile, false to '
                            . 'disable it there. The module must be enabled, or enabled in the same call.',
                    ],
                ],
                'required' => ['name'],
                'additionalProperties' => false,
            ],
            [SomcpScope::MODULES_WRITE],
            [self::class, 'handle'],
            // Enable and Disable ask for the edit right on the Module
            // Manager, whose tab is AdminModulesSf; the core checks the same.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminModulesSf')
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
        $name = $arguments['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return SomcpTool::text('name: a string, the technical name from somcp_list_modules.', true);
        }
        $active = $arguments['active'] ?? null;
        if ($active !== null && !is_bool($active)) {
            return SomcpTool::text('active: true or false.', true);
        }
        $mobile = $arguments['active_on_mobile'] ?? null;
        if ($mobile !== null && !is_bool($mobile)) {
            return SomcpTool::text('active_on_mobile: true or false.', true);
        }
        if ($active === null && $mobile === null) {
            return SomcpTool::text('active: give active, active_on_mobile or both.', true);
        }
        // PrestaShop 9 deprecated the mobile switch and made it do nothing:
        // accepting it there would claim a change that never happens.
        if ($mobile !== null && version_compare(_PS_VERSION_, '9.0.0', '>=')) {
            return SomcpTool::text(
                'active_on_mobile: not available on this version of PrestaShop, which has no mobile switch for modules.',
                true
            );
        }

        // The shop comes from the token, never from the caller.
        $idShop = (int) Somcp::currentShop()->id;
        $before = SomcpModules::read($name, $idShop);
        if ($before === null) {
            return SomcpTool::text('No module of this shop has this name. Find it with somcp_list_modules.', true);
        }
        if (!$before['installed']) {
            return SomcpTool::text(
                'This module is not installed. Nothing was changed; install it first with somcp_install_module.',
                true
            );
        }
        // The name the core knows the folder by, not the caller's spelling.
        $folder = (string) $before['name'];
        if (!Validate::isModuleName($folder)) {
            return SomcpTool::text('No module of this shop has this name. Find it with somcp_list_modules.', true);
        }
        if ($active === false && Tools::strtolower($folder) === 'somcp') {
            // Disabled, this module would cut every connected assistant off,
            // this answer included.
            return SomcpTool::text(
                'active: this module cannot disable itself, every connected assistant would lose its access. '
                    . 'Nothing was changed; disable it from Modules > Module Manager.',
                true
            );
        }

        $plan = self::plan((bool) $before['active'], (bool) $before['active_on_mobile'], $active, $mobile);
        if (is_string($plan)) {
            return SomcpTool::text($plan, true);
        }

        if (in_array('disable', $plan, true) && version_compare(_PS_VERSION_, '9.0.0', '<')) {
            // PrestaShop 8 disables a module on every shop whatever the
            // context; the token covers one shop only.
            $idModule = (int) Module::getModuleIdByName($folder);
            $elsewhere = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'module_shop` WHERE `id_module` = ' . $idModule
                    . ' AND `id_shop` != ' . $idShop
            );
            if ($elsewhere > 0) {
                return SomcpTool::text(
                    'active: this module is also enabled on other shops, and this version of PrestaShop would '
                        . 'disable it on all of them. Nothing was changed; disable it from Modules > Module Manager.',
                    true
                );
            }
        }

        $idEmployee = (int) ($context['id_employee'] ?? 0);

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($folder, $idShop, $plan, $before): array {
            return self::apply($folder, $idShop, $plan, $before);
        });
    }

    /**
     * The core actions to call, in the order the buttons would be used, or
     * the refusal when nothing would change.
     *
     * @param bool $activeBefore
     * @param bool $mobileBefore
     * @param bool|null $active
     * @param bool|null $mobile
     *
     * @return array<int, string>|string
     */
    private static function plan(bool $activeBefore, bool $mobileBefore, ?bool $active, ?bool $mobile)
    {
        $plan = [];
        $activeAfter = $active ?? $activeBefore;
        if ($activeAfter !== $activeBefore) {
            $plan[] = $activeAfter ? 'enable' : 'disable';
        }

        if ($mobile !== null) {
            if (!$activeAfter) {
                if ($mobile) {
                    // The core leaves a disabled module as it is and still
                    // answers success.
                    return 'active_on_mobile: the module is disabled on this shop; pass active true with it. '
                        . 'Nothing was changed.';
                }
            } else {
                // Enabling writes a fresh row whose device mask includes
                // mobile, so that is the state the mobile action starts from.
                $mobileStart = in_array('enable', $plan, true) ? true : $mobileBefore;
                if ($mobile !== $mobileStart) {
                    $plan[] = $mobile ? 'enableMobile' : 'disableMobile';
                }
            }
        }

        if ($plan === []) {
            return 'The module is already in this state (active: ' . ($activeBefore ? 'true' : 'false')
                . ', active_on_mobile: ' . ($mobileBefore ? 'true' : 'false') . '). Nothing was changed.';
        }

        return $plan;
    }

    /**
     * Runs the plan through the core and answers with the state read back.
     *
     * @param string $folder
     * @param int $idShop
     * @param array<int, string> $plan
     * @param array<string, mixed> $before
     *
     * @return array<string, mixed>
     */
    private static function apply(string $folder, int $idShop, array $plan, array $before): array
    {
        $run = SomcpModules::throughCore($folder, static function ($manager) use ($folder, $plan): bool {
            foreach ($plan as $method) {
                // enableMobile() and disableMobile() exist on PrestaShop 8
                // only; plan() never asks for them on 9.
                if (!method_exists($manager, $method) || !call_user_func([$manager, $method], $folder)) {
                    return false;
                }
            }

            return true;
        });

        // Read back whatever happened: a plan stopped half way has still
        // written its first action.
        $module = SomcpModules::read($folder, $idShop) ?? $before;
        $changed = [];
        foreach (['active', 'active_on_mobile'] as $field) {
            if (($before[$field] ?? null) !== ($module[$field] ?? null)) {
                $changed[$field] = ['from' => $before[$field] ?? null, 'to' => $module[$field] ?? null];
            }
        }

        if ($run['done']) {
            $module['changed'] = $changed;

            return SomcpTool::json($module);
        }

        $errors = $run['errors'];

        return SomcpTool::text(
            'The module was not changed as asked' . ($errors !== [] ? ': ' . implode(' ', $errors) : '.')
                . ' State read back: ' . json_encode($module + ['changed' => $changed], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            true
        );
    }
}
