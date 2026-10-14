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
 * SomcpAgentConfig - the settings of the back office assistant.
 *
 * Kept apart from SomcpConfig on purpose. Those settings follow the shop,
 * group and global inheritance of PrestaShop, and the settings form deletes
 * a value whose "own value" box is unticked. The assistant's settings are one
 * document for the whole installation - the merchant's API keys do not change
 * from one shop to the next - so they live in a single global value that the
 * main settings form never touches.
 *
 * The API keys are encrypted at rest with the shop's own cookie key, and never
 * sent back to the browser: the form shows that a key is saved and how it
 * ends, and an empty field on save means "keep the one I have".
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpAgentConfig
{
    /** @var string Configuration key holding the whole document, as JSON. */
    public const KEY = 'SOMCP_AGENT';

    /** @var int How many models of each provider are offered: the newest ones. */
    public const MODELS_PER_PROVIDER = 3;

    /** @var int Seconds before the model lists are read again from the providers. */
    public const MODELS_TTL = 86400;

    /**
     * The providers the assistant can use, in the order they are shown.
     *
     * The label is the product name the merchant knows, not the company: a
     * merchant pastes "a ChatGPT key", not "an OpenAI key".
     *
     * @return array<string, array{label:string, console:string}>
     */
    public static function providers(): array
    {
        return [
            'anthropic' => ['label' => 'Claude', 'console' => 'https://console.anthropic.com/settings/keys'],
            'openai' => ['label' => 'ChatGPT', 'console' => 'https://platform.openai.com/api-keys'],
            'google' => ['label' => 'Gemini', 'console' => 'https://aistudio.google.com/apikey'],
        ];
    }

    /**
     * The stored document, completed with whatever a provider is missing.
     *
     * @return array{providers: array<string, array{enabled:bool, key:string, role:string, models:array<int, array{id:string, label:string}>, off:array<int, string>, refreshed:int, error:string}>, instructions:string}
     */
    public static function load(): array
    {
        $raw = Configuration::getGlobalValue(self::KEY);
        $stored = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $stored = is_array($stored) ? $stored : [];

        $document = [
            'providers' => [],
            'instructions' => is_string($stored['instructions'] ?? null) ? $stored['instructions'] : '',
        ];

        foreach (array_keys(self::providers()) as $provider) {
            $row = is_array($stored['providers'][$provider] ?? null) ? $stored['providers'][$provider] : [];
            $models = [];
            foreach (is_array($row['models'] ?? null) ? $row['models'] : [] as $model) {
                if (is_array($model) && is_string($model['id'] ?? null) && $model['id'] !== '') {
                    $models[] = [
                        'id' => $model['id'],
                        'label' => is_string($model['label'] ?? null) && $model['label'] !== '' ? $model['label'] : $model['id'],
                    ];
                }
            }

            $document['providers'][$provider] = [
                'enabled' => ($row['enabled'] ?? false) === true,
                'key' => is_string($row['key'] ?? null) ? $row['key'] : '',
                'role' => is_string($row['role'] ?? null) ? $row['role'] : '',
                'models' => $models,
                'off' => array_values(array_filter(is_array($row['off'] ?? null) ? $row['off'] : [], 'is_string')),
                'refreshed' => (int) ($row['refreshed'] ?? 0),
                'error' => is_string($row['error'] ?? null) ? $row['error'] : '',
            ];
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document as returned by load()
     */
    public static function save(array $document): void
    {
        // Global value, whatever shop the administrator is working on: there is
        // one set of API keys per installation.
        Configuration::updateGlobalValue(
            self::KEY,
            (string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Removes the whole document. Called on uninstall.
     */
    public static function purge(): void
    {
        Configuration::deleteByName(self::KEY);
    }

    /**
     * The API key of a provider, decrypted.
     *
     * @param string $provider
     *
     * @return string empty when none is saved or it cannot be read back
     */
    public static function apiKey(string $provider): string
    {
        $document = self::load();
        $sealed = $document['providers'][$provider]['key'] ?? '';
        if ($sealed === '') {
            return '';
        }

        try {
            $plain = self::cipher()->decrypt($sealed);
        } catch (Throwable $e) {
            // The cookie key changed (a migrated shop, a restored database):
            // the key cannot be read any more and has to be entered again.
            return '';
        }

        return is_string($plain) ? $plain : '';
    }

    /**
     * The key as it is stored.
     *
     * @param string $plain
     *
     * @return string
     */
    public static function sealKey(string $plain): string
    {
        return (string) self::cipher()->encrypt($plain);
    }

    /**
     * The last characters of a saved key, to show which one is in place.
     *
     * @param string $provider
     *
     * @return string empty when no key is saved
     */
    public static function keyHint(string $provider): string
    {
        $key = self::apiKey($provider);

        return $key === '' ? '' : Tools::substr($key, -4);
    }

    /**
     * @return PhpEncryption
     */
    private static function cipher(): PhpEncryption
    {
        return new PhpEncryption(_NEW_COOKIE_KEY_);
    }

    /**
     * Every model the employee can talk to right now.
     *
     * A provider counts when it is switched on and has a key; a model when the
     * merchant has not switched it off. A model the provider published since
     * the last visit is on by default - that is the point of reading the lists
     * from the providers rather than shipping them with the module.
     *
     * @return array<int, array{provider:string, provider_label:string, model:string, label:string, role:string}>
     */
    public static function agents(): array
    {
        $document = self::load();
        $agents = [];

        foreach (self::providers() as $provider => $meta) {
            $row = $document['providers'][$provider];
            if (!$row['enabled'] || $row['key'] === '') {
                continue;
            }

            foreach ($row['models'] as $model) {
                if (in_array($model['id'], $row['off'], true)) {
                    continue;
                }
                $agents[] = [
                    'provider' => $provider,
                    'provider_label' => $meta['label'],
                    'model' => $model['id'],
                    'label' => $model['label'],
                    'role' => $row['role'],
                ];
            }
        }

        return $agents;
    }

    /**
     * The role of each provider the employee can talk to right now.
     *
     * A role left empty takes the default one, so a merchant who switches on
     * three providers gets a sensible split of the work without writing it.
     *
     * @return array<string, string> provider to role
     */
    public static function roles(): array
    {
        $roles = [];
        foreach (self::agents() as $agent) {
            $roles[$agent['provider']] = trim($agent['role']) !== '' ? trim($agent['role']) : self::defaultRole($agent['provider']);
        }

        return $roles;
    }

    /**
     * The role a provider takes when the merchant wrote none.
     *
     * Shown as the placeholder of the role field too, so it is the merchant's
     * language and not the model's.
     *
     * @param string $provider
     *
     * @return string
     */
    public static function defaultRole(string $provider): string
    {
        $module = Module::getInstanceByName('somcp');
        if (!$module instanceof Somcp) {
            return '';
        }

        switch ($provider) {
            case 'anthropic':
                return $module->l10n('SEO: product and category titles, meta descriptions, friendly URLs and keywords.');
            case 'openai':
                return $module->l10n('Writing and research: product descriptions, customer replies, market and competitor research.');
            case 'google':
                return $module->l10n('Images: describing, checking and naming the pictures of the catalogue, and writing their alternative text.');
        }

        return '';
    }

    /**
     * Reads the model lists again where they are older than a day.
     *
     * Called when the assistant page opens, so a model released this week
     * shows up without anyone updating the module or opening the settings.
     */
    public static function refreshStaleModels(): void
    {
        $document = self::load();
        $changed = false;

        foreach (array_keys(self::providers()) as $provider) {
            $row = $document['providers'][$provider];
            if (!$row['enabled'] || $row['key'] === '' || time() - $row['refreshed'] < self::MODELS_TTL) {
                continue;
            }

            $document['providers'][$provider] = self::refreshedRow($provider, $row, self::apiKey($provider));
            $changed = true;
        }

        if ($changed) {
            self::save($document);
        }
    }

    /**
     * A provider row with its model list read again from the provider.
     *
     * On failure the previous list is kept and the reason recorded: a provider
     * that is down for an hour must not make the assistant forget its models.
     *
     * @param string $provider
     * @param array<string, mixed> $row
     * @param string $apiKey
     *
     * @return array<string, mixed>
     */
    public static function refreshedRow(string $provider, array $row, string $apiKey): array
    {
        $row['refreshed'] = time();

        if ($apiKey === '') {
            return $row;
        }

        $listed = SomcpAgentProvider::listModels($provider, $apiKey);
        if ($listed['error'] !== '') {
            $row['error'] = $listed['error'];

            return $row;
        }

        $row['error'] = '';
        $row['models'] = array_slice($listed['models'], 0, self::MODELS_PER_PROVIDER);

        return $row;
    }
}
