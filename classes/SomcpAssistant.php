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
 * SomcpAssistant - the assistants a merchant connected to the chat built into
 * the back office: who they are, which model each one runs, what they cost.
 *
 * The merchant never picks a model by name unless they want to. They pick a
 * level, and the level resolves to the newest model of that tier in the list
 * the provider returns for their key. A model released next month is used
 * next month, without an update of the module - which a list of model names
 * written here could never do.
 *
 * Costs are estimates. Providers report tokens, not money, so the module
 * multiplies by public prices per tier, in US dollars, the currency every one
 * of these providers bills in. A merchant setting a cap reads "about".
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpAssistant
{
    /** @var string Monthly cap in US dollars, all assistants together. Empty or 0: no cap. */
    public const BUDGET = 'SOMCP_ASSISTANT_BUDGET';

    /** @var string The merchant's own instructions, added to every conversation. */
    public const INSTRUCTIONS = 'SOMCP_ASSISTANT_INSTRUCTIONS';

    /** @var string Key the links to attached files are signed with. Never shown anywhere. */
    public const LINK_KEY = 'SOMCP_ASSISTANT_LINK_KEY';

    public const LEVEL_ECONOMY = 'economy';
    public const LEVEL_BALANCED = 'balanced';
    public const LEVEL_POWER = 'power';

    /** @var int Seconds a resolved model is trusted before the provider is asked again. */
    private const RESOLVE_TTL = 86400;

    /** @var string Marks a key stored without encryption, on a shop with no cookie key. */
    private const PLAIN = 'plain:';

    /**
     * The providers the chat can use, keyed by the id stored in the table.
     *
     * Every one of them takes a single key, which is what keeps the form to
     * three fields. Neuron AI knows others - Bedrock, Azure, Vertex - that
     * need a region, a deployment or a service account; they are left out
     * until the form can ask for those without growing for everyone else.
     *
     * Ollama takes the address of the merchant's own server instead of a key.
     *
     * @return array<string, array{label:string, key_url:string, address:bool}>
     */
    public static function providers(): array
    {
        return [
            'anthropic' => ['label' => 'Claude (Anthropic)', 'key_url' => 'https://console.anthropic.com/settings/keys', 'address' => false],
            'openai' => ['label' => 'ChatGPT (OpenAI)', 'key_url' => 'https://platform.openai.com/api-keys', 'address' => false],
            'gemini' => ['label' => 'Gemini (Google)', 'key_url' => 'https://aistudio.google.com/apikey', 'address' => false],
            'mistral' => ['label' => 'Mistral', 'key_url' => 'https://console.mistral.ai/api-keys', 'address' => false],
            'deepseek' => ['label' => 'DeepSeek', 'key_url' => 'https://platform.deepseek.com/api_keys', 'address' => false],
            'xai' => ['label' => 'Grok (xAI)', 'key_url' => 'https://console.x.ai', 'address' => false],
            'cohere' => ['label' => 'Cohere', 'key_url' => 'https://dashboard.cohere.com/api-keys', 'address' => false],
            'ollama' => ['label' => 'Ollama', 'key_url' => 'https://ollama.com/download', 'address' => true],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function levels(): array
    {
        return [self::LEVEL_ECONOMY, self::LEVEL_BALANCED, self::LEVEL_POWER];
    }

    /**
     * Every assistant, the default one first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'somcp_assistant` ORDER BY `is_default` DESC, `id_assistant` ASC'
        );

        return is_array($rows) ? array_map([self::class, 'shape'], $rows) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function active(): array
    {
        return array_values(array_filter(self::all(), static function (array $row): bool {
            return $row['active'];
        }));
    }

    /**
     * The assistant that answers when the merchant picked none: the one marked
     * default if it is switched on, the first one switched on otherwise.
     *
     * @return array<string, mixed>|null
     */
    public static function leader(): ?array
    {
        $active = self::active();

        return $active[0] ?? null;
    }

    /**
     * @param int $idAssistant
     *
     * @return array<string, mixed>|null
     */
    public static function get(int $idAssistant): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'somcp_assistant` WHERE `id_assistant` = ' . (int) $idAssistant
        );

        return is_array($row) ? self::shape($row) : null;
    }

    /**
     * Creates or updates an assistant. An empty key leaves the stored one in
     * place: the form never shows it back, so a merchant changing the role
     * must not have to paste it again.
     *
     * @param int $idAssistant 0 to create
     * @param array{provider:string, api_key:string, level:string, model:string, role:string, active:bool} $values
     *
     * @return int the assistant id
     */
    public static function save(int $idAssistant, array $values): int
    {
        $providers = self::providers();
        $provider = isset($providers[$values['provider']]) ? $values['provider'] : 'anthropic';
        $level = in_array($values['level'], self::levels(), true) ? $values['level'] : self::LEVEL_ECONOMY;

        $data = [
            'provider' => pSQL($provider),
            'level' => pSQL($level),
            'model' => pSQL(Tools::substr(trim($values['model']), 0, 128)),
            'role' => pSQL(Tools::substr(trim($values['role']), 0, 255)),
            'active' => $values['active'] ? 1 : 0,
            // A changed provider, level or model invalidates what the level
            // resolved to before.
            'resolved_model' => '',
        ];
        if (trim($values['api_key']) !== '') {
            $data['api_key'] = pSQL(self::seal(trim($values['api_key'])));
        }

        $db = Db::getInstance();
        if ($idAssistant > 0 && self::get($idAssistant) !== null) {
            $db->update('somcp_assistant', $data, '`id_assistant` = ' . (int) $idAssistant);
        } else {
            $data['api_key'] = $data['api_key'] ?? '';
            // The first assistant is the default one: a chat with one
            // assistant and no default would have nobody to answer.
            $data['is_default'] = self::all() === [] ? 1 : 0;
            $db->insert('somcp_assistant', $data);
            $idAssistant = (int) $db->Insert_ID();
        }

        return $idAssistant;
    }

    /**
     * @param int $idAssistant
     */
    public static function delete(int $idAssistant): void
    {
        $wasDefault = (self::get($idAssistant)['is_default'] ?? false) === true;

        Db::getInstance()->delete('somcp_assistant', '`id_assistant` = ' . (int) $idAssistant);
        Db::getInstance()->delete('somcp_assistant_usage', '`id_assistant` = ' . (int) $idAssistant);

        if ($wasDefault) {
            $next = self::all()[0] ?? null;
            if ($next !== null) {
                self::makeDefault((int) $next['id_assistant']);
            }
        }
    }

    /**
     * @param int $idAssistant
     */
    public static function makeDefault(int $idAssistant): void
    {
        $db = Db::getInstance();
        $db->update('somcp_assistant', ['is_default' => 0], '1');
        $db->update('somcp_assistant', ['is_default' => 1], '`id_assistant` = ' . (int) $idAssistant);
    }

    /**
     * The key in clear, for the request to the provider. Never sent to a page.
     *
     * @param array<string, mixed> $assistant
     *
     * @return string
     */
    public static function key(array $assistant): string
    {
        $stored = (string) Db::getInstance()->getValue(
            'SELECT `api_key` FROM `' . _DB_PREFIX_ . 'somcp_assistant` WHERE `id_assistant` = ' . (int) $assistant['id_assistant']
        );

        return self::open($stored);
    }

    /**
     * The model this assistant runs: the one chosen by hand, or what its level
     * resolves to, read from the provider at most once a day.
     *
     * @param array<string, mixed> $assistant
     * @param bool $refresh ask the provider even if the last answer is recent
     *
     * @return string empty when the provider could not be read and nothing was resolved before
     */
    public static function model(array $assistant, bool $refresh = false): string
    {
        if ($assistant['model'] !== '') {
            return (string) $assistant['model'];
        }

        $fresh = $assistant['resolved_model'] !== ''
            && $assistant['resolved_at'] !== null
            && time() - (int) strtotime((string) $assistant['resolved_at']) < self::RESOLVE_TTL;
        if ($fresh && !$refresh) {
            return (string) $assistant['resolved_model'];
        }

        $listed = self::listModels((string) $assistant['provider'], self::key($assistant));
        if ($listed === null) {
            // The provider did not answer: keep the last model that worked
            // rather than leave the merchant without a chat for the day.
            return (string) $assistant['resolved_model'];
        }

        $model = self::pick((string) $assistant['provider'], (string) $assistant['level'], $listed);
        Db::getInstance()->update('somcp_assistant', [
            'resolved_model' => pSQL($model),
            'resolved_at' => date('Y-m-d H:i:s'),
        ], '`id_assistant` = ' . (int) $assistant['id_assistant']);

        return $model;
    }

    /**
     * Checks a key by asking the provider for its models, the cheapest call
     * every provider offers and one that costs nothing.
     *
     * @param array<string, mixed> $assistant
     *
     * @return string the model the level resolves to, empty when the key does not work
     */
    public static function test(array $assistant): string
    {
        if (self::listModels((string) $assistant['provider'], self::key($assistant)) === null) {
            return '';
        }

        return self::model($assistant, true);
    }

    /**
     * What one model call cost, in US dollars, from public prices per tier.
     *
     * @param string $provider
     * @param string $model
     * @param int $inputTokens
     * @param int $outputTokens
     *
     * @return float
     */
    public static function price(string $provider, string $model, int $inputTokens, int $outputTokens): float
    {
        [$in, $out] = self::rates($provider, $model);

        return ($inputTokens * $in + $outputTokens * $out) / 1000000;
    }

    /**
     * Adds a call to the month of an assistant.
     *
     * @param int $idAssistant
     * @param int $inputTokens
     * @param int $outputTokens
     * @param float $cost
     */
    public static function record(int $idAssistant, int $inputTokens, int $outputTokens, float $cost): void
    {
        if ($inputTokens === 0 && $outputTokens === 0) {
            return;
        }

        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'somcp_assistant_usage` (`id_assistant`, `month`, `input_tokens`, `output_tokens`, `cost`)'
            . ' VALUES (' . (int) $idAssistant . ', \'' . pSQL(date('Y-m')) . '\', ' . (int) $inputTokens . ', ' . (int) $outputTokens . ', ' . (float) $cost . ')'
            . ' ON DUPLICATE KEY UPDATE `input_tokens` = `input_tokens` + VALUES(`input_tokens`),'
            . ' `output_tokens` = `output_tokens` + VALUES(`output_tokens`), `cost` = `cost` + VALUES(`cost`)'
        );
    }

    /**
     * What each assistant cost this month, keyed by assistant id.
     *
     * @return array<int, float>
     */
    public static function monthCosts(): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_assistant`, `cost` FROM `' . _DB_PREFIX_ . 'somcp_assistant_usage`'
            . ' WHERE `month` = \'' . pSQL(date('Y-m')) . '\''
        );

        $costs = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $costs[(int) $row['id_assistant']] = (float) $row['cost'];
        }

        return $costs;
    }

    /**
     * @return float
     */
    public static function budget(): float
    {
        return max(0.0, (float) Configuration::getGlobalValue(self::BUDGET));
    }

    /**
     * Whether the monthly cap is reached. Checked before every model call.
     *
     * @return bool
     */
    public static function overBudget(): bool
    {
        $budget = self::budget();

        return $budget > 0 && array_sum(self::monthCosts()) >= $budget;
    }

    /**
     * @return string
     */
    public static function instructions(): string
    {
        return (string) Configuration::getGlobalValue(self::INSTRUCTIONS);
    }

    /**
     * @param float $budget
     * @param string $instructions
     */
    public static function saveSettings(float $budget, string $instructions): void
    {
        Configuration::updateGlobalValue(self::BUDGET, (string) max(0.0, round($budget, 2)));
        // Stored as HTML-allowed so the merchant's punctuation survives: the
        // value is only ever sent to the model and escaped wherever shown.
        Configuration::updateGlobalValue(self::INSTRUCTIONS, Tools::substr($instructions, 0, 4000), true);
    }

    /**
     * Removes what the module stored outside its tables, on uninstall.
     */
    public static function purge(): void
    {
        Configuration::deleteByName(self::BUDGET);
        Configuration::deleteByName(self::INSTRUCTIONS);
        Configuration::deleteByName(self::LINK_KEY);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $providers = self::providers();
        $provider = (string) $row['provider'];

        return [
            'id_assistant' => (int) $row['id_assistant'],
            'provider' => $provider,
            'label' => $providers[$provider]['label'] ?? $provider,
            'has_key' => (string) $row['api_key'] !== '',
            'level' => (string) $row['level'],
            'model' => (string) $row['model'],
            'resolved_model' => (string) $row['resolved_model'],
            'resolved_at' => $row['resolved_at'] !== null ? (string) $row['resolved_at'] : null,
            'role' => (string) $row['role'],
            'active' => (int) $row['active'] === 1,
            'is_default' => (int) $row['is_default'] === 1,
        ];
    }

    /**
     * @param string $plain
     *
     * @return string
     */
    private static function seal(string $plain): string
    {
        if (!defined('_NEW_COOKIE_KEY_')) {
            return self::PLAIN . $plain;
        }

        return (new PhpEncryption(_NEW_COOKIE_KEY_))->encrypt($plain);
    }

    /**
     * @param string $stored
     *
     * @return string empty when the key cannot be read back, as after the shop's
     *                cookie key was regenerated - the merchant then pastes it again
     */
    private static function open(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        if (strpos($stored, self::PLAIN) === 0) {
            return substr($stored, strlen(self::PLAIN));
        }
        if (!defined('_NEW_COOKIE_KEY_')) {
            return '';
        }

        try {
            $plain = (new PhpEncryption(_NEW_COOKIE_KEY_))->decrypt($stored);
        } catch (Throwable $e) {
            return '';
        }

        return is_string($plain) ? $plain : '';
    }

    /**
     * The models a key can use, as the provider lists them.
     *
     * @param string $provider
     * @param string $key
     *
     * @return array<int, array{id:string, created:int}>|null null when the provider refused or did not answer
     */
    private static function listModels(string $provider, string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        $guard = true;
        switch ($provider) {
            case 'anthropic':
                $url = 'https://api.anthropic.com/v1/models?limit=100';
                $headers = ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
                break;
            case 'openai':
                $url = 'https://api.openai.com/v1/models';
                $headers = ['Authorization: Bearer ' . $key];
                break;
            case 'gemini':
                $url = 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000';
                $headers = ['x-goog-api-key: ' . $key];
                break;
            case 'mistral':
                $url = 'https://api.mistral.ai/v1/models';
                $headers = ['Authorization: Bearer ' . $key];
                break;
            case 'deepseek':
                $url = 'https://api.deepseek.com/models';
                $headers = ['Authorization: Bearer ' . $key];
                break;
            case 'xai':
                $url = 'https://api.x.ai/v1/models';
                $headers = ['Authorization: Bearer ' . $key];
                break;
            case 'cohere':
                $url = 'https://api.cohere.com/v1/models?endpoint=chat&page_size=1000';
                $headers = ['Authorization: Bearer ' . $key];
                break;
            case 'ollama':
                // The merchant's own server, which may well sit on a private
                // address next to the shop: the address guard would refuse
                // exactly the setup Ollama is for. Only an administrator can
                // type this address.
                $url = rtrim(self::ollamaBase($key), '/') . '/tags';
                $headers = [];
                $guard = false;
                break;
            default:
                return null;
        }

        $response = SomcpHttp::fetch($url, $guard, $headers, null, 2097152);
        if ($response['status'] !== 200) {
            return null;
        }
        $body = json_decode($response['body'], true);
        if (!is_array($body)) {
            return null;
        }

        $models = [];
        foreach ((array) ($body['data'] ?? $body['models'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = (string) ($entry['id'] ?? $entry['name'] ?? '');
            if ($provider === 'gemini') {
                // Gemini names models "models/gemini-2.5-flash" and lists some
                // that cannot chat at all.
                $id = (string) preg_replace('#^models/#', '', $id);
                if (!in_array('generateContent', (array) ($entry['supportedGenerationMethods'] ?? []), true)) {
                    continue;
                }
            }
            if ($id === '') {
                continue;
            }
            $created = $entry['created'] ?? $entry['created_at'] ?? 0;
            $models[] = ['id' => $id, 'created' => is_numeric($created) ? (int) $created : (int) strtotime((string) $created)];
        }

        return $models;
    }

    /**
     * The address Neuron AI's Ollama provider expects, from what the merchant
     * typed: "http://192.168.1.10:11434" becomes ".../api".
     *
     * @param string $typed
     *
     * @return string
     */
    public static function ollamaBase(string $typed): string
    {
        $base = rtrim(trim($typed), '/');
        if ($base === '') {
            $base = 'http://localhost:11434';
        }

        return preg_match('#/api$#', $base) ? $base : $base . '/api';
    }

    /**
     * Picks the newest model of a level in a provider's list.
     *
     * The rules go by family names, which providers keep for years - haiku,
     * sonnet and opus, nano, mini and the full model, flash-lite, flash and
     * pro - and never by full model names, which change every few months.
     * Dated snapshots, previews and specialised variants are left out: a
     * merchant asking for "the economy one" wants the stable chat model.
     *
     * @param string $provider
     * @param string $level
     * @param array<int, array{id:string, created:int}> $models
     *
     * @return string empty when nothing in the list fits
     */
    public static function pick(string $provider, string $level, array $models): string
    {
        $rules = [
            'anthropic' => [
                self::LEVEL_ECONOMY => '/^claude-(.+-)?haiku/',
                self::LEVEL_BALANCED => '/^claude-(.+-)?sonnet/',
                self::LEVEL_POWER => '/^claude-(.+-)?opus/',
            ],
            // The full model is the strongest one chat completions serves: the
            // pro variants only answer on another API.
            'openai' => [
                self::LEVEL_ECONOMY => '/^gpt-\d+(\.\d+)?-nano$/',
                self::LEVEL_BALANCED => '/^gpt-\d+(\.\d+)?-mini$/',
                self::LEVEL_POWER => '/^gpt-\d+(\.\d+)?$/',
            ],
            'gemini' => [
                self::LEVEL_ECONOMY => '/^gemini-\d+(\.\d+)?-flash-lite$/',
                self::LEVEL_BALANCED => '/^gemini-\d+(\.\d+)?-flash$/',
                self::LEVEL_POWER => '/^gemini-\d+(\.\d+)?-pro$/',
            ],
            // Mistral maintains these aliases on purpose: each always points
            // at the current model of its size.
            'mistral' => [
                self::LEVEL_ECONOMY => '/^mistral-small-latest$/',
                self::LEVEL_BALANCED => '/^mistral-medium-latest$/',
                self::LEVEL_POWER => '/^mistral-large-latest$/',
            ],
            // One chat model behind every level: the reasoner does not call
            // tools reliably, and a level that loses the shop's tools is worse
            // than no level at all.
            'deepseek' => [
                self::LEVEL_ECONOMY => '/^deepseek-chat$/',
                self::LEVEL_BALANCED => '/^deepseek-chat$/',
                self::LEVEL_POWER => '/^deepseek-chat$/',
            ],
            'xai' => [
                self::LEVEL_ECONOMY => '/^grok-\d+(\.\d+)?(-\d+)?-(fast|mini)/',
                self::LEVEL_BALANCED => '/^grok-\d+(\.\d+)?(-\d+)?-(fast|mini)/',
                self::LEVEL_POWER => '/^grok-\d+(\.\d+)?(-\d{4})?$/',
            ],
            'cohere' => [
                self::LEVEL_ECONOMY => '/^command-r7b/',
                self::LEVEL_BALANCED => '/^command-r-\d/',
                self::LEVEL_POWER => '/^command-a-\d/',
            ],
        ];

        $ids = [];
        foreach ($models as $model) {
            $id = $model['id'];
            if (preg_match('/(preview|exp|embed|tts|audio|image|vision|realtime|transcribe|search|translate|-\d{4}-\d{2}-\d{2}$)/', $id)
                && $provider !== 'anthropic' && $provider !== 'ollama') {
                continue;
            }
            $ids[$id] = $model['created'];
        }

        if ($provider === 'ollama') {
            // Whatever the merchant pulled on their own server; levels mean
            // nothing there.
            return (string) (array_key_first($ids) ?? '');
        }

        $rule = $rules[$provider][$level] ?? null;
        if ($rule === null) {
            return '';
        }

        $candidates = [];
        foreach ($ids as $id => $created) {
            if (preg_match($rule, (string) $id)) {
                $candidates[(string) $id] = $created;
            }
        }
        if ($candidates === []) {
            // A level the account cannot reach, as on a key limited to some
            // models: fall back to the cheaper levels rather than to nothing.
            $lower = array_slice(self::levels(), 0, (int) array_search($level, self::levels(), true));

            return $lower === [] ? '' : self::pick($provider, (string) end($lower), $models);
        }

        // Newest first: by the date the provider gives, then by the version
        // numbers in the name for those that give none.
        uksort($candidates, static function (string $a, string $b) use ($candidates): int {
            if ($candidates[$a] !== $candidates[$b]) {
                return $candidates[$b] <=> $candidates[$a];
            }

            return version_compare(self::versionOf($b), self::versionOf($a));
        });

        return (string) array_key_first($candidates);
    }

    /**
     * @param string $id
     *
     * @return string
     */
    private static function versionOf(string $id): string
    {
        preg_match_all('/\d+/', $id, $numbers);

        return implode('.', $numbers[0]);
    }

    /**
     * Public prices in US dollars per million tokens, input then output, by
     * family. Estimates: they move, and the cap they feed is a safety net,
     * not an invoice.
     *
     * @param string $provider
     * @param string $model
     *
     * @return array{0:float, 1:float}
     */
    private static function rates(string $provider, string $model): array
    {
        $table = [
            'anthropic' => [['haiku', 1.0, 5.0], ['sonnet', 3.0, 15.0], ['opus', 5.0, 25.0]],
            'openai' => [['nano', 0.05, 0.4], ['mini', 0.25, 2.0], ['gpt', 1.25, 10.0]],
            'gemini' => [['flash-lite', 0.1, 0.4], ['flash', 0.3, 2.5], ['pro', 1.25, 10.0]],
            'mistral' => [['small', 0.1, 0.3], ['medium', 0.4, 2.0], ['large', 2.0, 6.0]],
            'deepseek' => [['deepseek', 0.28, 0.42]],
            'xai' => [['fast', 0.2, 0.5], ['mini', 0.3, 0.5], ['grok', 3.0, 15.0]],
            'cohere' => [['r7b', 0.0375, 0.15], ['command-r', 0.15, 0.6], ['command-a', 2.5, 10.0]],
        ];

        foreach ($table[$provider] ?? [] as [$family, $in, $out]) {
            if (strpos($model, $family) !== false) {
                return [$in, $out];
            }
        }

        // A model the table does not know, or one running on the merchant's
        // own server: counted as free rather than guessed.
        return [0.0, 0.0];
    }
}
