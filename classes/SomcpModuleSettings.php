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
            $tools[] = ['name' => $name, 'title' => (string) ($described['title'] ?? $name), 'on' => SomcpToolRegistry::isEnabled($name)];
        }
        $prompts = [];
        foreach (SomcpPromptRegistry::getInstance()->all() as $name => $prompt) {
            $prompts[] = ['name' => $name, 'title' => $prompt->getTitle(), 'on' => SomcpPromptRegistry::isEnabled($name)];
        }

        return [
            'shop' => $idShop,
            'note' => 'set_at says where a value comes from: shop, group, global (all shops) or default. A change made here is written for this shop. API keys are only typed in the form. Tools and prompts switch with the keys tool:<name> and prompt:<name>, values on or off.',
            'docs' => $docs,
            'settings' => $settings,
            'assistants' => ['tab' => self::t('Built-in assistant'), 'list' => $assistants],
            'tools' => ['tab' => self::t('Tools and prompts'), 'list' => $tools],
            'prompts' => ['tab' => self::t('Tools and prompts'), 'list' => $prompts],
        ];
    }

    /**
     * Changes one setting, after the employee approved it in the chat.
     *
     * @param string $key a key of read(), or tool:<name> / prompt:<name>
     * @param string $raw the new value
     * @param int $idShop the shop the chat acts on
     *
     * @return string an error for the model, '' when written
     */
    public static function write(string $key, string $raw, int $idShop): string
    {
        $raw = trim($raw);
        if (strpos($key, self::TOOL_PREFIX) === 0 || strpos($key, self::PROMPT_PREFIX) === 0) {
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
        if ($kind === 'profile' && (int) $raw > 0 && !Validate::isLoadedObject(new Profile((int) $raw))) {
            return 'No such profile. Use 0 for every employee, or the id of a profile.';
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
                SomcpAssistantMemory::save($raw === '1', SomcpAssistantMemory::providerId($idShop), '', true);

                return '';
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
                SomcpAssistantMemory::save(SomcpAssistantMemory::on($idShop), $raw, '', true);

                return '';
            default:
                SomcpConfig::set($key, SomcpConfig::clean($key, $raw));

                return '';
        }
    }

    /**
     * Switches one tool or prompt, as its box on the Tools and prompts tab.
     *
     * @param string $key tool:<name> or prompt:<name>
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
        $tool = strpos($key, self::TOOL_PREFIX) === 0;
        $name = substr($key, strlen($tool ? self::TOOL_PREFIX : self::PROMPT_PREFIX));
        // Which names exist comes from the registry, never from the request,
        // as on the settings page.
        $exists = $tool ? SomcpToolRegistry::getInstance()->has($name) : SomcpPromptRegistry::getInstance()->get($name) !== null;
        if (!$exists) {
            return 'No such ' . ($tool ? 'tool' : 'prompt') . '.';
        }
        $off = $tool ? SomcpToolRegistry::disabled() : SomcpPromptRegistry::disabled();
        $off = $on ? array_values(array_diff($off, [$name])) : array_values(array_unique(array_merge($off, [$name])));
        SomcpConfig::set($tool ? SomcpConfig::TOOLS_OFF : SomcpConfig::PROMPTS_OFF, implode(' ', $off));

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
            foreach (Profile::getProfiles((int) Context::getContext()->language->id) as $profile) {
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
