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
 * SomcpPromptRegistry - the guided tasks this server offers.
 *
 * Built like the tool registry and for the same reasons: filled once per
 * request, open to the other modules of the shop through a hook fired module
 * by module so each name is prefixed with the folder it came from, and read
 * through the same filters a tool goes through.
 *
 * Other modules publish by returning arrays from the hook, never by calling
 * in: see SomcpContract.
 *
 * Prompts of this module live one folder per prompt under prompts/, a
 * prompt.md and a prompt.json beside it.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpPromptRegistry
{
    /** @var string Hook other modules listen to in order to publish their prompts. */
    public const HOOK_REGISTER = 'actionSomcpRegisterPrompts';

    /** @var string Folder holding one folder per prompt, relative to the modules directory. */
    private const PROMPTS_DIR = 'somcp/prompts/';

    /** @var int Longest prompt name, the same bound a tool name has. */
    public const MAX_NAME_LENGTH = 64;

    /** @var array<string, SomcpPrompt> */
    private $prompts = [];

    /** @var string Folder of the module currently being called, empty for our own. */
    private $registering = '';

    /** @var SomcpPromptRegistry|null */
    private static $instance;

    /**
     * @return SomcpPromptRegistry
     */
    public static function getInstance(): SomcpPromptRegistry
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->registerOwnPrompts();
            self::$instance->collectFromModules();
        }

        return self::$instance;
    }

    /**
     * Files a prompt, under a name prefixed with the module that brought it.
     *
     * Refused, and said so in the log, when the name is unusable or taken,
     * when the file cannot be served, or when it asks for a permission nobody
     * declared. A prompt nobody can open and nobody can explain is the worst
     * of the possible outcomes.
     *
     * @param SomcpPrompt $prompt
     *
     * @return bool
     */
    public function register(SomcpPrompt $prompt): bool
    {
        // Same rule as for tools: another module leans on its own scopes and
        // the connection scope, never on a core one it did not declare.
        $tools = SomcpToolRegistry::getInstance();
        $allowed = $this->registering === ''
            ? array_merge(SomcpScope::core(), array_keys($tools->declaredScopes()))
            : array_merge([SomcpScope::ACCESS], $tools->scopesOf($this->registering));
        $unknown = array_diff($prompt->getRequiredScopes(), $allowed);
        if ($unknown !== []) {
            self::refuse('prompt_scope_unknown', $prompt->getName() . ' asks for ' . implode(' ', $unknown));

            return false;
        }

        $name = SomcpToolRegistry::qualify($prompt->getName(), $this->registering);
        if ($name === '' || Tools::strlen($name) > self::MAX_NAME_LENGTH) {
            self::refuse('prompt_name_refused', 'from ' . ($this->registering === '' ? 'this module' : $this->registering));

            return false;
        }

        if (isset($this->prompts[$name])) {
            self::refuse('prompt_name_taken', $name . ' is already registered');

            return false;
        }

        if (!$prompt->isReadable()) {
            self::refuse('prompt_unreadable', $name . ' has no readable file under ' . SomcpPrompt::MAX_BYTES . ' bytes');

            return false;
        }

        $this->prompts[$name] = $name === $prompt->getName() ? $prompt : $prompt->renamed($name);

        return true;
    }

    /**
     * @param string $name
     *
     * @return SomcpPrompt|null
     */
    public function get(string $name): ?SomcpPrompt
    {
        return $this->prompts[$name] ?? null;
    }

    /**
     * @return array<string, SomcpPrompt>
     */
    public function all(): array
    {
        return $this->prompts;
    }

    /**
     * The prompts a given token may be handed.
     *
     * @param string $grantedScopes
     * @param int $idEmployee
     *
     * @return array<int, array<string, mixed>>
     */
    public function describeFor(string $grantedScopes, int $idEmployee): array
    {
        $described = [];
        foreach ($this->prompts as $prompt) {
            if (self::isEnabled($prompt->getName()) && $prompt->isAvailableTo($grantedScopes, $idEmployee)) {
                $described[] = $prompt->describe();
            }
        }

        return $described;
    }

    /**
     * One prompt, if this token may be handed it.
     *
     * Every gate of the listing is applied again: a client keeps a listing
     * for minutes, and a prompt switched off this morning must not stay
     * usable until that copy expires.
     *
     * @param string $name
     * @param string $grantedScopes
     * @param int $idEmployee
     *
     * @return SomcpPrompt|null
     */
    public function locate(string $name, string $grantedScopes, int $idEmployee): ?SomcpPrompt
    {
        $prompt = $this->prompts[$name] ?? null;
        if ($prompt === null || !self::isEnabled($name) || !$prompt->isAvailableTo($grantedScopes, $idEmployee)) {
            return null;
        }

        return $prompt;
    }

    /**
     * Whether the merchant left this prompt switched on for this shop.
     *
     * @param string $name
     *
     * @return bool
     */
    public static function isEnabled(string $name): bool
    {
        return !in_array($name, self::disabled(), true);
    }

    /**
     * The prompts switched off for the shop this request belongs to.
     *
     * @return array<int, string>
     */
    public static function disabled(): array
    {
        $raw = trim(SomcpConfig::get(SomcpConfig::PROMPTS_OFF));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', $raw) ?: []));
    }

    /**
     * Lets the other modules of the shop publish their prompts.
     *
     * Nothing is passed to them: a prompt is published by returning it, so
     * there is no object here for a module to call into, and nothing for the
     * validator to find a class name in.
     */
    private function collectFromModules(): void
    {
        $registered = Hook::getHookModuleExecList(self::HOOK_REGISTER);
        if (!is_array($registered)) {
            return;
        }

        foreach ($registered as $entry) {
            $this->registering = (string) ($entry['module'] ?? '');

            try {
                $output = Hook::exec(self::HOOK_REGISTER, [], (int) ($entry['id_module'] ?? 0), true);
                $declared = SomcpContract::returned($output, $this->registering);
                foreach (SomcpContract::entries($declared, 'prompts') as $definition) {
                    $prompt = is_array($definition) ? SomcpContract::prompt($definition, $this->registering) : null;
                    if ($prompt !== null) {
                        $this->register($prompt);
                    }
                }
            } catch (Throwable $e) {
                SomcpLogger::log(
                    'prompt_registration_failed',
                    SomcpLogger::OUTCOME_FAILURE,
                    $this->registering . ': ' . Tools::substr($e->getMessage(), 0, 200)
                );
            }
        }

        $this->registering = '';
    }

    /**
     * The prompts the module ships with, one folder each under prompts/.
     *
     * The title, the description and the argument descriptions are what the
     * merchant reads in the assistant's menu, so they are written in English
     * in prompt.json and translated on the way out like every other string
     * the module shows.
     */
    private function registerOwnPrompts(): void
    {
        $folders = glob(_PS_MODULE_DIR_ . self::PROMPTS_DIR . '*', GLOB_ONLYDIR) ?: [];
        sort($folders);

        foreach ($folders as $folder) {
            $manifest = $folder . '/prompt.json';
            $declared = is_file($manifest) ? json_decode((string) @file_get_contents($manifest), true) : null;
            if (!is_array($declared)) {
                self::refuse('prompt_file_skipped', basename($folder) . ' has no readable prompt.json');

                continue;
            }

            $acts = [];
            if (!empty($declared['controller']) && !empty($declared['action'])) {
                $acts = SomcpTool::acts((string) $declared['action'], (string) $declared['controller']);
            }

            $arguments = [];
            foreach (is_array($declared['arguments'] ?? null) ? $declared['arguments'] : [] as $argument) {
                if (is_array($argument) && is_string($argument['name'] ?? null)) {
                    $arguments[] = [
                        'name' => $argument['name'],
                        'description' => Somcp::t((string) ($argument['description'] ?? '')),
                        'required' => ($argument['required'] ?? false) === true,
                    ];
                }
            }

            $this->register(new SomcpPrompt(
                basename($folder),
                Somcp::t((string) ($declared['title'] ?? basename($folder))),
                Somcp::t((string) ($declared['description'] ?? '')),
                $folder . '/prompt.md',
                is_array($declared['scopes'] ?? null) ? $declared['scopes'] : [],
                is_array($declared['tools'] ?? null) ? $declared['tools'] : [],
                $acts,
                $arguments,
                (string) ($declared['icon'] ?? '')
            ));
        }
    }

    /**
     * @param string $event
     * @param string $message
     */
    private static function refuse(string $event, string $message): void
    {
        SomcpLogger::log($event, SomcpLogger::OUTCOME_FAILURE, $message);
    }
}
