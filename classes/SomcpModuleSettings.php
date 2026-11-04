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
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The module's own settings, as the built-in assistant reads and changes them.
 *
 * Only the chat uses this, for super administrators, the employees the
 * settings pages are open to: an assistant connected over MCP never sees it,
 * and the module's tool list does not show it. Each setting comes with the
 * tab and the label the page shows, so the model can say where to click.
 *
 * Writing goes through the same code as the settings page - SomcpConfig::clean,
 * SomcpAssistant::saveSetting, saveSearch, SomcpAssistantMemory::save - and at
 * the level the chat acts on: the shop of the conversation. API keys are never
 * read nor written here; they are typed in the form.
 */
class SomcpModuleSettings
{
    /** @var string Prefix of the pseudo settings that switch one tool. */
    public const TOOL_PREFIX = 'tool:';

    /** @var string Prefix of the pseudo settings that switch one prompt. */
    public const PROMPT_PREFIX = 'prompt:';

    /**
     * @var string Prefix that switches one tool for the built-in assistant.
     *
     * The Tools and prompts tab has a column for the assistants connected over
     * MCP and one for the built-in assistant; tool: and prompt: stay the first
     * column, as they always were, and these two are the second.
     */
    public const BUILTIN_TOOL_PREFIX = 'builtin-tool:';

    /** @var string Prefix that switches one prompt for the built-in assistant. */
    public const BUILTIN_PROMPT_PREFIX = 'builtin-prompt:';

    /**
     * @var string Prefix that switches a whole scope - the group of tools that
     *             need it, on the Tools and prompts tab - for the assistants
     *             connected over MCP.
     */
    public const SCOPE_PREFIX = 'scope:';

    /** @var string Prefix that switches a whole scope for the built-in assistant. */
    public const BUILTIN_SCOPE_PREFIX = 'builtin-scope:';

    /**
     * The settings the chat may read and change: key => tab, label as the
     * page shows them, and the kind of value.
     *
     * @return array<string, array{tab:string, label:string, kind:string}>
     */
    private static function catalogue(): array
    {
        $config = 'Configuration';
        $tools = 'Tools and prompts';
        $access = 'Access and security';
        $builtin = 'Built-in assistant';

        return [
            SomcpConfig::ENABLED => ['tab' => $config, 'label' => 'Enable the MCP server', 'kind' => 'switch'],
            SomcpConfig::TOOLS_TTL => ['tab' => $tools, 'label' => 'How long an assistant keeps the list of tools', 'kind' => 'seconds'],
            SomcpConfig::REQUIRED_PROFILE => ['tab' => $access, 'label' => 'Employees allowed to connect an assistant', 'kind' => 'profile'],
            SomcpConfig::OAUTH_ENABLED => ['tab' => $access, 'label' => 'Accept new connections', 'kind' => 'switch'],
            SomcpConfig::ACCESS_TTL => ['tab' => $access, 'label' => 'Access token lifetime (seconds)', 'kind' => 'seconds'],
            SomcpConfig::REFRESH_TTL => ['tab' => $access, 'label' => 'Refresh token lifetime (seconds)', 'kind' => 'seconds'],
            SomcpConfig::REFRESH_ROTATION => ['tab' => $access, 'label' => 'Replace the refresh token on every use', 'kind' => 'switch'],
            SomcpConfig::CIMD_ENABLED => ['tab' => $access, 'label' => 'Let applications identify themselves by address', 'kind' => 'switch'],
            SomcpConfig::DCR_ENABLED => ['tab' => $access, 'label' => 'Let applications register themselves', 'kind' => 'switch'],
            SomcpConfig::REDIRECT_HOSTS => ['tab' => $access, 'label' => 'Only allow these return addresses', 'kind' => 'text'],
            SomcpConfig::LOG_ENABLED => ['tab' => $access, 'label' => 'Keep a security log', 'kind' => 'switch'],
            SomcpAssistant::PROFILE => ['tab' => $builtin, 'label' => 'Employees allowed to use the built-in assistant', 'kind' => 'profile'],
            SomcpAssistant::TOKEN_CAP => ['tab' => $builtin, 'label' => 'Monthly cap, all assistants', 'kind' => 'tokens'],
            SomcpAssistant::INSTRUCTIONS => ['tab' => $builtin, 'label' => 'Instructions for the shop', 'kind' => 'text'],
            SomcpAssistant::SEARCH => ['tab' => $builtin, 'label' => 'Web search', 'kind' => 'choice'],
            SomcpAssistantMemory::ON => ['tab' => $builtin, 'label' => 'Memory', 'kind' => 'switch'],
            SomcpAssistantMemory::PROVIDER => ['tab' => $builtin, 'label' => 'Embeddings provider', 'kind' => 'choice'],
        ];
    }

    /**
     * Everything the settings pages show, for the model.
     *
     * @param int $idShop the shop the chat acts on
     * @param string $docs the address of the module's documentation for the installed version
     *
     * @return array<string, mixed>
     */
    public static function read(int $idShop, string $docs): array
    {
        $settings = [];
        foreach (self::catalogue() as $key => $entry) {
            $level = SomcpConfig::level($key);
            $setting = [
                'key' => $key,
                'tab' => self::t($entry['tab']),
                'label' => self::t($entry['label']),
                'value' => self::value($key, $entry['kind'], $idShop),
                'set_at' => $level['source'],
            ];
            $choices = self::choices($key, $idShop);
            if ($choices !== null) {
                $setting['choices'] = $choices;
            }
            // On is not the same as working: said for the memory, so the model
            // never tells the employee a feature runs when it cannot.
            if ($key === SomcpAssistantMemory::ON) {
                $setting['works'] = SomcpAssistantMemory::enabled($idShop);
            }
            if ($key === SomcpAssistant::SEARCH && !SomcpAssistant::searchUseful()) {
                $setting['greyed'] = 'needs a Claude or Gemini assistant and an assistant of another provider';
            }
            $settings[] = $setting;
        }

        $assistants = [];
        foreach (SomcpAssistant::all() as $assistant) {
            $assistants[] = [
                'id' => $assistant['id_assistant'],
                'provider' => $assistant['label'],
                'level' => $assistant['level'],
                'model' => $assistant['model'] !== '' ? $assistant['model'] : $assistant['resolved_model'],
                'role' => $assistant['role'],
                'active' => $assistant['active'],
                'default' => $assistant['is_default'],
                'key_set' => $assistant['has_key'],
                'token_cap' => $assistant['token_cap'],
            ];
        }

        $tools = [];
        foreach (SomcpToolRegistry::getInstance()->all() as $name => $tool) {
            $described = $tool->describe();
            $tools[] = [
                'name' => $name,
                'title' => (string) ($described['title'] ?? $name),
                'scope' => SomcpScope::groupOf($tool->getRequiredScopes()),
                // Its own switch: a group switched off holds it back without
                // changing it, and the scopes list says which groups are off.
                'on' => SomcpToolRegistry::isSwitchedOn($name),
                'builtin' => SomcpToolRegistry::isSwitchedOn($name, true),
            ];
        }
        $scopes = [];
        $module = Module::getInstanceByName('somcp');
        foreach (array_diff(SomcpScope::supported(), [SomcpScope::ACCESS]) as $scope) {
            $scopes[] = [
                'name' => $scope,
                'label' => $module instanceof Somcp ? SomcpScope::label($scope, $module) : $scope,
                'on' => !in_array($scope, SomcpScope::disabled(), true),
                'builtin' => !in_array($scope, SomcpScope::disabled(true), true),
            ];
        }
        $prompts = [];
        foreach (SomcpPromptRegistry::getInstance()->all() as $name => $prompt) {
            $prompts[] = [
                'name' => $name,
                'title' => $prompt->getTitle(),
                'on' => SomcpPromptRegistry::isSwitchedOn($name),
                'builtin' => SomcpPromptRegistry::isSwitchedOn($name, true),
            ];
        }

        return [
            'shop' => $idShop,
            'note' => 'Change only what the employee asked for, one setting at a time. When a change needs a choice the employee has not made - a provider, a profile, a value - ask them and wait: never choose for them, never change a second setting on your own. Speak to the employee of a setting by its tab and label, never by its key nor in JSON. set_at says where a value comes from: shop, group, global (all shops) or default. A change made here is written for this shop. API keys are only typed in the form. Each tool and prompt has two switches: on, for the assistants connected over MCP, with the keys tool:<name> and prompt:<name>; builtin, for this built-in assistant, with the keys builtin-tool:<name> and builtin-prompt:<name>. Values on or off. Tools are grouped by the scope they need, and each scope has the same two switches, scope:<scope> and builtin-scope:<scope>, which hold back every tool of the group and every prompt needing it, including tools added later, without changing their own switches. When the employee does not say which, ask.',
            'docs' => $docs,
            'settings' => $settings,
            'assistants' => ['tab' => self::t('Built-in assistant'), 'list' => $assistants],
            'tools' => ['tab' => self::t('Tools and prompts'), 'list' => $tools],
            'scopes' => ['tab' => self::t('Tools and prompts'), 'list' => $scopes],
            'prompts' => ['tab' => self::t('Tools and prompts'), 'list' => $prompts],
        ];
    }

    /**
     * Changes one setting, after the employee approved it in the chat.
     *
     * @param string $key a key of read(), or tool:, prompt:, scope:, or the same with builtin- in front, and a name
     * @param string $raw the new value
     * @param int $idShop the shop the chat acts on
     *
     * @return string an error for the model, '' when written
     */
    public static function write(string $key, string $raw, int $idShop): string
    {
        $raw = trim($raw);
        if (self::switchOf($key) !== null) {
            return self::switchOne($key, $raw);
        }
        $catalogue = self::catalogue();
        if (!isset($catalogue[$key])) {
            return 'Unknown setting. Read the settings first and use one of their keys.';
        }
        $kind = $catalogue[$key]['kind'];
        if ($kind === 'switch') {
            $on = self::onOff($raw);
            if ($on === null) {
                return 'This setting takes on or off.';
            }
            $raw = $on ? '1' : '0';
        }
        if ($kind === 'profile' && !SomcpConfig::profileExists(max(0, (int) $raw))) {
            return 'No such profile. Use 0 for every employee, or the id of a profile.';
        }
        // The rules that tie two settings together, as the form holds them.
        if ($key === SomcpConfig::ACCESS_TTL || $key === SomcpConfig::REFRESH_TTL) {
            $value = (int) SomcpConfig::clean($key, $raw);
            $access = $key === SomcpConfig::ACCESS_TTL ? $value : (int) SomcpConfig::get(SomcpConfig::ACCESS_TTL, $idShop);
            $refresh = $key === SomcpConfig::REFRESH_TTL ? $value : (int) SomcpConfig::get(SomcpConfig::REFRESH_TTL, $idShop);
            if (!SomcpConfig::lifetimesHold($access, $refresh)) {
                return 'The refresh token must last longer than the access token (now ' . $access . ' and ' . $refresh . ' seconds). Nothing was changed.';
            }
        }

        switch ($key) {
            case SomcpAssistant::PROFILE:
            case SomcpAssistant::TOKEN_CAP:
            case SomcpAssistant::INSTRUCTIONS:
                SomcpAssistant::saveSetting($key, $raw);

                return '';
            case SomcpAssistant::SEARCH:
                if (!SomcpAssistant::searchUseful()) {
                    return 'This setting is greyed: it needs a Claude or Gemini assistant and an assistant of another provider.';
                }
                if ($raw !== '' && !isset(SomcpAssistant::searchLenders()[$raw])) {
                    return 'Pick one of the choices, or an empty value to switch it off.';
                }
                SomcpAssistant::saveSearch($raw, true);

                return '';
            case SomcpAssistantMemory::ON:
                return self::memoryRefusal(SomcpAssistantMemory::save($raw === '1', SomcpAssistantMemory::providerId($idShop), '', true), $idShop);
            case SomcpAssistantMemory::PROVIDER:
                $offered = SomcpAssistantMemory::selectable($idShop);
                if (!isset($offered[$raw])) {
                    return 'Pick one of the choices.';
                }
                // A provider bringing its own key (Voyage) needs it typed in the
                // form: the chat never handles a key.
                if ($offered[$raw]['special'] && SomcpAssistantMemory::providerId($idShop) !== $raw) {
                    return 'This provider needs its own key, which is typed in the form of the Built-in assistant tab.';
                }

                return self::memoryRefusal(SomcpAssistantMemory::save(SomcpAssistantMemory::on($idShop), $raw, '', true), $idShop);
            default:
                SomcpConfig::set($key, SomcpConfig::clean($key, $raw));

                return '';
        }
    }

    /**
     * What a refused memory save means, for the model.
     *
     * @param string $refused '' or a SomcpAssistantMemory refusal
     * @param int $idShop
     *
     * @return string
     */
    private static function memoryRefusal(string $refused, int $idShop): string
    {
        if ($refused === SomcpAssistantMemory::NEEDS_PROVIDER) {
            $offered = array_keys(SomcpAssistantMemory::selectable($idShop));

            return 'The memory cannot be switched on without an embeddings provider, and nothing was changed. '
                . ($offered !== []
                    ? 'Ask the employee which provider to use (' . implode(', ', $offered) . ') and wait for the answer: never pick one yourself.'
                    : 'None is offered until a chat assistant of OpenAI, Gemini, Mistral or Cohere has a key: tell the employee.');
        }
        if ($refused === SomcpAssistantMemory::NEEDS_KEY) {
            return 'This embeddings provider needs its own key, typed in the form of the Built-in assistant tab. Nothing was changed.';
        }

        return '';
    }

    /**
     * A setting as it now stands, with what it means, told to the model after
     * a change: written is not the same as working, and the model must not
     * say a feature is on while it cannot run.
     *
     * @param string $key
     * @param int $idShop
     *
     * @return string
     */
    public static function state(string $key, int $idShop): string
    {
        $switch = self::switchOf($key);
        if ($switch !== null) {
            ['name' => $name, 'chat' => $chat] = $switch;
            $for = $chat ? ' for the built-in assistant' : ' for the assistants connected over MCP';
            if ($switch['kind'] === 'scope') {
                $off = in_array($name, SomcpScope::disabled($chat), true);
                $held = [];
                foreach (SomcpToolRegistry::getInstance()->all() as $tool) {
                    if (in_array($name, $tool->getRequiredScopes(), true)) {
                        $held[] = $tool->getName();
                    }
                }

                return 'Group ' . $name . ' is ' . ($off ? 'off' : 'on') . $for . '.'
                    . ($off && $held !== [] ? ' These tools are held back there, and the prompts needing them too: ' . implode(', ', $held) . '.' : '');
            }
            if ($switch['kind'] === 'prompt') {
                return 'Prompt ' . $name . ' is ' . (SomcpPromptRegistry::isEnabled($name, $chat) ? 'on' : 'off') . $for . '.'
                    . (SomcpPromptRegistry::isSwitchedOn($name, $chat) && !SomcpPromptRegistry::isEnabled($name, $chat) ? ' Its own switch is on, but a group it needs is off.' : '');
            }
            $line = 'Tool ' . $name . ' is ' . (SomcpToolRegistry::isEnabled($name, $chat) ? 'on' : 'off') . $for . '.';
            if (SomcpToolRegistry::isSwitchedOn($name, $chat) && !SomcpToolRegistry::isEnabled($name, $chat)) {
                $line .= ' Its own switch is on, but its group is off.';
            }
            if (!SomcpToolRegistry::isEnabled($name, $chat)) {
                $using = [];
                foreach (SomcpPromptRegistry::getInstance()->all() as $prompt) {
                    if (in_array($name, $prompt->getTools(), true) && SomcpPromptRegistry::isEnabled($prompt->getName(), $chat)) {
                        $using[] = $prompt->getTitle();
                    }
                }
                if ($using !== []) {
                    $line .= ' These prompts use it and are now incomplete there: ' . implode(', ', $using) . '.';
                }
            }

            return $line;
        }
        $catalogue = self::catalogue();
        if (!isset($catalogue[$key])) {
            return '';
        }
        $line = self::t($catalogue[$key]['tab']) . ' > ' . self::t($catalogue[$key]['label']) . ': ' . self::value($key, $catalogue[$key]['kind'], $idShop) . '.';
        switch ($key) {
            case SomcpConfig::ENABLED:
                if (SomcpConfig::get($key, $idShop) !== '1') {
                    $line .= ' No assistant connected over MCP can reach the shop any more; the built-in assistant still works.';
                }
                break;
            case SomcpConfig::OAUTH_ENABLED:
                if (SomcpConfig::get($key, $idShop) !== '1') {
                    $line .= ' New connections are refused: the registration settings and the return addresses have no effect until it is on again.';
                }
                break;
            case SomcpAssistantMemory::ON:
            case SomcpAssistantMemory::PROVIDER:
                $line .= SomcpAssistantMemory::enabled($idShop) ? ' The memory works.' : ' The memory does not work: it is off, or has no provider or key.';
                break;
            case SomcpAssistant::SEARCH:
                $line .= SomcpAssistant::searchProvider() !== '' ? ' The other assistants can search the web.' : ' The other assistants cannot search the web.';
                break;
        }

        return $line;
    }

    /**
     * Which switch a pseudo setting names, or null for an ordinary setting.
     *
     * @param string $key
     *
     * @return array{kind: string, chat: bool, name: string}|null
     */
    private static function switchOf(string $key): ?array
    {
        // The builtin- prefixes first: tool: and scope: are not prefixes of
        // theirs, but the order keeps it so if one ever is.
        $prefixes = [
            self::BUILTIN_TOOL_PREFIX => ['tool', true],
            self::BUILTIN_PROMPT_PREFIX => ['prompt', true],
            self::BUILTIN_SCOPE_PREFIX => ['scope', true],
            self::TOOL_PREFIX => ['tool', false],
            self::PROMPT_PREFIX => ['prompt', false],
            self::SCOPE_PREFIX => ['scope', false],
        ];
        foreach ($prefixes as $prefix => [$kind, $chat]) {
            if (strpos($key, $prefix) === 0) {
                return ['kind' => $kind, 'chat' => $chat, 'name' => substr($key, strlen($prefix))];
            }
        }

        return null;
    }

    /**
     * Switches one tool, prompt or group of tools, as its switch on the Tools
     * and prompts tab.
     *
     * @param string $key tool:, prompt:, scope:, or the same with builtin- in front, and a name
     * @param string $raw on or off
     *
     * @return string
     */
    private static function switchOne(string $key, string $raw): string
    {
        $on = self::onOff($raw);
        if ($on === null) {
            return 'Tools and prompts take on or off.';
        }
        $switch = self::switchOf($key);
        if ($switch === null) {
            return 'Unknown setting. Read the settings first and use one of their keys.';
        }
        ['kind' => $kind, 'chat' => $chat, 'name' => $name] = $switch;
        // Which names exist comes from the registry, never from the request,
        // as on the settings page. Opening a session is not a group anyone
        // can switch off.
        if ($kind === 'scope') {
            $exists = $name !== SomcpScope::ACCESS && in_array($name, SomcpScope::supported(), true);
        } elseif ($kind === 'tool') {
            $exists = SomcpToolRegistry::getInstance()->has($name);
        } else {
            $exists = SomcpPromptRegistry::getInstance()->get($name) !== null;
        }
        if (!$exists) {
            return 'No such ' . $kind . '.';
        }
        $lists = [
            'tool' => [SomcpConfig::TOOLS_OFF, SomcpConfig::TOOLS_OFF_CHAT, SomcpToolRegistry::disabled($chat)],
            'prompt' => [SomcpConfig::PROMPTS_OFF, SomcpConfig::PROMPTS_OFF_CHAT, SomcpPromptRegistry::disabled($chat)],
            'scope' => [SomcpConfig::SCOPES_OFF, SomcpConfig::SCOPES_OFF_CHAT, SomcpScope::disabled($chat)],
        ];
        [$mcpSetting, $chatSetting, $off] = $lists[$kind];
        $off = $on ? array_values(array_diff($off, [$name])) : array_values(array_unique(array_merge($off, [$name])));
        $setting = $chat ? $chatSetting : $mcpSetting;
        SomcpConfig::set($setting, implode(' ', $off));

        return '';
    }

    /**
     * @param string $key
     * @param string $kind
     * @param int $idShop
     *
     * @return string
     */
    private static function value(string $key, string $kind, int $idShop): string
    {
        if (isset(SomcpConfig::defaults()[$key])) {
            $value = SomcpConfig::get($key, $idShop);
        } else {
            $value = (string) Configuration::get($key, null, null, $idShop);
        }
        if ($kind === 'switch') {
            return $value === '1' ? 'on' : 'off';
        }
        if ($kind === 'profile') {
            if ((int) $value <= 0) {
                return '0 (every employee)';
            }
            foreach (Profile::getProfiles((int) Somcp::legacyContext()->language->id) as $profile) {
                if ((int) $profile['id_profile'] === (int) $value) {
                    return (int) $value . ' (' . $profile['name'] . ')';
                }
            }

            return (string) (int) $value;
        }
        if ($kind === 'tokens') {
            return (int) $value > 0 ? (string) (int) $value : '0 (no cap)';
        }

        return $value;
    }

    /**
     * The values a choice takes, as the page offers them.
     *
     * @param string $key
     * @param int $idShop
     *
     * @return array<string, string>|null
     */
    private static function choices(string $key, int $idShop): ?array
    {
        if ($key === SomcpAssistant::SEARCH) {
            return ['' => self::t('Off')] + SomcpAssistant::searchLenders();
        }
        if ($key === SomcpAssistantMemory::PROVIDER) {
            return array_map(static function (array $provider): string {
                return $provider['label'];
            }, SomcpAssistantMemory::selectable($idShop));
        }

        return null;
    }

    /**
     * @param string $raw
     *
     * @return bool|null null when it is neither
     */
    private static function onOff(string $raw): ?bool
    {
        $raw = strtolower($raw);
        if (in_array($raw, ['1', 'on', 'yes', 'true'], true)) {
            return true;
        }
        if (in_array($raw, ['0', 'off', 'no', 'false'], true)) {
            return false;
        }

        return null;
    }

    /**
     * A label in the employee's language, as the page shows it.
     *
     * @param string $source
     *
     * @return string
     */
    private static function t(string $source): string
    {
        $module = Module::getInstanceByName('somcp');

        return $module instanceof Somcp ? $module->l10n($source) : $source;
    }
}
