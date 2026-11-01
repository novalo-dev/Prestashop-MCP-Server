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
 *
 * On a multistore install the assistants and the settings follow the levels
 * of every other setting: all shops, then a group, then a shop. A shop uses
 * the assistants of the most precise level that has any, and a setting the
 * value PrestaShop resolves for it. Conversations and spending stay per shop:
 * the chat acts on one shop, and a cap set above applies to each shop below.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpAssistant
{
    /**
     * @var string The monthly cap of earlier versions, in estimated US dollars.
     *             Read only by the 1.2.0 upgrade, which turns it into tokens.
     */
    public const BUDGET = 'SOMCP_ASSISTANT_BUDGET';

    /**
     * @var string Monthly cap in tokens, all assistants of a shop together.
     *             Empty or 0: no cap. Tokens are what every provider reports,
     *             exactly; a price would be an estimate that ages.
     */
    public const TOKEN_CAP = 'SOMCP_ASSISTANT_TOKENS';

    /** @var string The merchant's own instructions, added to every conversation. */
    public const INSTRUCTIONS = 'SOMCP_ASSISTANT_INSTRUCTIONS';

    /** @var string Key the links to attached files are signed with. Never shown anywhere. */
    public const LINK_KEY = 'SOMCP_ASSISTANT_LINK_KEY';

    /** @var string Profile allowed to use the built-in assistant, 0 for every employee. */
    public const PROFILE = 'SOMCP_ASSISTANT_PROFILE';

    public const LEVEL_ECONOMY = 'economy';
    public const LEVEL_BALANCED = 'balanced';
    public const LEVEL_POWER = 'power';

    /**
     * @var string The level that makes pictures. Offered only by the providers
     *             in IMAGE_PROVIDERS, and never leading a conversation: image
     *             models do not call the shop's tools, so the leading
     *             assistant hands them the work of their role instead.
     */
    public const LEVEL_IMAGE = 'image';

    /**
     * @var string The level where the merchant picks the exact model by hand,
     *             chosen from the list the provider returns. Offered by every
     *             provider, since any of them may expose a model the three
     *             automatic levels do not map to.
     */
    public const LEVEL_CUSTOM = 'custom';

    /**
     * @var array<int, string> providers whose image models the chat can reach:
     *                         Gemini through its chat API, OpenAI through its image API
     */
    public const IMAGE_PROVIDERS = ['gemini', 'openai'];

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
     * The levels one provider offers: the three text levels everywhere, and
     * images where the provider makes them.
     *
     * @param string $provider
     *
     * @return array<int, string>
     */
    public static function levelsOf(string $provider): array
    {
        $levels = self::levels();
        if (in_array($provider, self::IMAGE_PROVIDERS, true)) {
            $levels[] = self::LEVEL_IMAGE;
        }
        $levels[] = self::LEVEL_CUSTOM;

        return $levels;
    }

    /**
     * The models the provider exposes for this assistant, for the Custom level
     * to pick from. Empty when the key is missing or the provider cannot be
     * reached - the form then says to save a working key first.
     *
     * @param array<string, mixed> $assistant
     *
     * @return array<int, string> model ids, sorted
     */
    public static function models(array $assistant): array
    {
        $listed = self::listModels((string) $assistant['provider'], self::key($assistant));
        if ($listed === null) {
            return [];
        }

        $ids = array_map(static function (array $model): string {
            return (string) $model['id'];
        }, $listed);
        sort($ids);

        return $ids;
    }

    /**
     * Every assistant the shop of the chat uses, the default one first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::forShop(self::shop());
    }

    /**
     * Every assistant a shop uses: those of the shop, else of its group, else
     * of all shops - whichever level is the most precise to have any.
     *
     * @param int $idShop
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forShop(int $idShop): array
    {
        [$atShop, $atGroup] = self::levelOf($idShop);

        return self::atLevel($atShop, $atGroup);
    }

    /**
     * The assistants stored at exactly one level.
     *
     * @param int $idShop the shop for a shop level, 0 otherwise
     * @param int $idShopGroup the group for a group level, 0 otherwise
     *
     * @return array<int, array<string, mixed>>
     */
    public static function atLevel(int $idShop, int $idShopGroup): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'somcp_assistant`'
            . ' WHERE `id_shop` = ' . (int) $idShop . ' AND `id_shop_group` = ' . (int) $idShopGroup
            . ' ORDER BY `is_default` DESC, `id_assistant` ASC'
        );

        return is_array($rows) ? array_map([self::class, 'shape'], $rows) : [];
    }

    /**
     * The level whose assistants a shop uses, in the order PrestaShop reads a
     * setting: the shop, its group, all shops.
     *
     * @param int $idShop
     *
     * @return array{0:int, 1:int} id_shop and id_shop_group of that level
     */
    public static function levelOf(int $idShop): array
    {
        foreach (self::levelsAbove($idShop, true) as $level) {
            if (self::atLevel($level[0], $level[1]) !== []) {
                return [$level[0], $level[1]];
            }
        }

        return [$idShop, 0];
    }

    /**
     * The levels a shop reads from, the most precise first.
     *
     * @param int $idShop
     * @param bool $withShop whether the shop's own level comes first
     *
     * @return array<int, array{0:int, 1:int, 2:string}> id_shop, id_shop_group, and the level's name
     */
    private static function levelsAbove(int $idShop, bool $withShop): array
    {
        $levels = $withShop ? [[$idShop, 0, 'shop']] : [];
        $idGroup = (int) Shop::getGroupFromShop($idShop, true);
        if ($idGroup > 0) {
            $levels[] = [0, $idGroup, 'group'];
        }
        $levels[] = [0, 0, 'global'];

        return $levels;
    }

    /**
     * The level the settings page edits: the shop, the group or all shops the
     * back office is set to. A single shop install has the shop level only, as
     * it always had.
     *
     * @return array{0:int, 1:int}
     */
    public static function editedLevel(): array
    {
        if (!Shop::isFeatureActive()) {
            return [self::shop(), 0];
        }
        switch (Shop::getContext()) {
            case Shop::CONTEXT_SHOP:
                return [(int) Shop::getContextShopID(), 0];
            case Shop::CONTEXT_GROUP:
                return [0, (int) Shop::getContextShopGroupID()];
            default:
                return [0, 0];
        }
    }

    /**
     * What the settings page shows for the assistants of the level it edits:
     * its own, or those it inherits, read-only.
     *
     * The source is shop, group, global or default (nothing anywhere).
     *
     * @return array{own:bool, source:string, assistants:array<int, array<string, mixed>>}
     */
    public static function editedState(): array
    {
        [$idShop, $idGroup] = self::editedLevel();
        $own = self::atLevel($idShop, $idGroup);
        if ($own !== [] || !Shop::isFeatureActive() || Shop::getContext() === Shop::CONTEXT_ALL) {
            return ['own' => true, 'source' => $idShop > 0 ? 'shop' : ($idGroup > 0 ? 'group' : 'global'), 'assistants' => $own];
        }

        // A group reads from all shops only; a shop from its group, then all shops.
        $above = $idShop > 0 ? self::levelsAbove($idShop, false) : [[0, 0, 'global']];
        foreach ($above as [$atShop, $atGroup, $source]) {
            $rows = self::atLevel($atShop, $atGroup);
            if ($rows !== []) {
                return ['own' => false, 'source' => $source, 'assistants' => $rows];
            }
        }

        return ['own' => false, 'source' => 'default', 'assistants' => []];
    }

    /**
     * Gives the level being edited assistants of its own: a copy of those it
     * inherited, keys included, which can then be changed here alone.
     *
     * @return bool false when a copy could not be written
     */
    public static function copyHere(): bool
    {
        $state = self::editedState();
        if ($state['own']) {
            return true;
        }
        [$idShop, $idGroup] = self::editedLevel();
        $db = Db::getInstance();
        $copied = true;
        foreach ($state['assistants'] as $assistant) {
            $row = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'somcp_assistant` WHERE `id_assistant` = ' . (int) $assistant['id_assistant']);
            if (!is_array($row)) {
                continue;
            }
            unset($row['id_assistant'], $row['date_add'], $row['date_upd']);
            $row['id_shop'] = $idShop;
            $row['id_shop_group'] = $idGroup;
            // A column that is NULL is left out and takes its default. Db's
            // null option would also turn every empty text into NULL, which
            // the NOT NULL columns refuse - and the copy failed without a word.
            $values = [];
            foreach ($row as $column => $value) {
                if ($value !== null) {
                    $values[$column] = pSQL((string) $value);
                }
            }
            $copied = $db->insert('somcp_assistant', $values) && $copied;
        }

        return $copied;
    }

    /**
     * Drops the assistants of the level being edited, so it uses those of the
     * level above again. Their spending stays recorded for the month.
     *
     * @return void
     */
    public static function inheritHere(): void
    {
        if (!Shop::isFeatureActive() || Shop::getContext() === Shop::CONTEXT_ALL) {
            return;
        }
        [$idShop, $idGroup] = self::editedLevel();
        Db::getInstance()->delete('somcp_assistant', '`id_shop` = ' . (int) $idShop . ' AND `id_shop_group` = ' . (int) $idGroup);
    }

    /**
     * One assistant of the level being edited, for the settings page.
     *
     * @param int $idAssistant
     *
     * @return array<string, mixed>|null
     */
    public static function edited(int $idAssistant): ?array
    {
        [$idShop, $idGroup] = self::editedLevel();
        foreach (self::atLevel($idShop, $idGroup) as $assistant) {
            if ($assistant['id_assistant'] === $idAssistant) {
                return $assistant;
            }
        }

        return null;
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
        return self::leaderFor(self::shop());
    }

    /**
     * The assistant that leads the chat of a shop, if it has one.
     *
     * @param int $idShop
     *
     * @return array<string, mixed>|null
     */
    public static function leaderFor(int $idShop): ?array
    {
        foreach (self::forShop($idShop) as $assistant) {
            if ($assistant['active'] && $assistant['level'] !== self::LEVEL_IMAGE) {
                return $assistant;
            }
        }

        return null;
    }

    /**
     * @param int $idAssistant
     *
     * @return array<string, mixed>|null
     */
    public static function get(int $idAssistant): ?array
    {
        // Among the assistants the shop of the chat uses: an id from a request
        // never reaches another shop's key.
        foreach (self::all() as $assistant) {
            if ($assistant['id_assistant'] === $idAssistant) {
                return $assistant;
            }
        }

        return null;
    }

    /**
     * Creates or updates an assistant. An empty key leaves the stored one in
     * place: the form never shows it back, so a merchant changing the role
     * must not have to paste it again.
     *
     * @param int $idAssistant 0 to create
     * @param array{provider:string, api_key:string, level:string, model:string, role:string, active:bool, token_cap?:int} $values
     *
     * @return int the assistant id
     */
    public static function save(int $idAssistant, array $values): int
    {
        $providers = self::providers();
        $provider = isset($providers[$values['provider']]) ? $values['provider'] : 'anthropic';
        $level = in_array($values['level'], self::levelsOf($provider), true) ? $values['level'] : self::LEVEL_ECONOMY;

        // The exact model belongs to the Custom level only; the automatic
        // levels resolve it themselves, so it is cleared for them. A Custom
        // level left without a model has nothing to run, so it falls back to
        // the Economy level rather than a chat that cannot answer.
        $model = Tools::substr(trim($values['model']), 0, 128);
        if ($level !== self::LEVEL_CUSTOM) {
            $model = '';
        } elseif ($model === '') {
            // Custom with no model yet, on a first save: read the models from
            // the key the merchant just typed (or the stored one) and start on
            // a sensible mid-tier one, which the drop-down then lets them change.
            // No separate request from the browser - the key is the one posted.
            $plain = trim($values['api_key']);
            if ($plain === '' && $idAssistant > 0) {
                $existing = self::edited($idAssistant);
                $plain = $existing !== null ? self::key($existing) : '';
            }
            $listed = $plain !== '' ? self::listModels($provider, $plain) : null;
            if (is_array($listed) && $listed !== []) {
                $model = self::pick($provider, self::LEVEL_BALANCED, $listed);
                if ($model === '') {
                    $model = (string) $listed[0]['id'];
                }
            } else {
                // No key or no answer: Custom cannot run without a model.
                $level = self::LEVEL_ECONOMY;
            }
        }

        $data = [
            'provider' => pSQL($provider),
            'level' => pSQL($level),
            'model' => pSQL($model),
            'role' => pSQL(Tools::substr(trim($values['role']), 0, 255)),
            'token_cap' => max(0, (int) ($values['token_cap'] ?? 0)),
            'active' => $values['active'] ? 1 : 0,
            // A changed provider, level or model invalidates what the level
            // resolved to before.
            'resolved_model' => '',
        ];
        if (trim($values['api_key']) !== '') {
            $data['api_key'] = pSQL(self::seal(trim($values['api_key'])));
        }

        $db = Db::getInstance();
        [$idShop, $idGroup] = self::editedLevel();
        if ($idAssistant > 0 && self::edited($idAssistant) !== null) {
            $db->update('somcp_assistant', $data, '`id_assistant` = ' . (int) $idAssistant);
        } else {
            $data['api_key'] = $data['api_key'] ?? '';
            $data['id_shop'] = $idShop;
            $data['id_shop_group'] = $idGroup;
            // The first assistant of the level that can lead is its default
            // one: a chat with no default would have nobody to answer.
            $leads = array_filter(self::atLevel($idShop, $idGroup), static function (array $other): bool {
                return $other['level'] !== self::LEVEL_IMAGE;
            });
            $data['is_default'] = $level !== self::LEVEL_IMAGE && $leads === [] ? 1 : 0;
            $db->insert('somcp_assistant', $data);
            $idAssistant = (int) $db->Insert_ID();
        }

        return $idAssistant;
    }

    /**
     * The key in clear of an active assistant of this shop using a provider, so
     * the memory can embed with a key the merchant already set for the chat
     * rather than ask for it twice. '' when no such assistant has a key.
     *
     * @param string $provider
     *
     * @return string
     */
    public static function keyForProvider(string $provider): string
    {
        foreach (self::active() as $assistant) {
            if ($assistant['provider'] === $provider && $assistant['has_key']) {
                $key = self::key($assistant);
                if ($key !== '') {
                    return $key;
                }
            }
        }

        return '';
    }

    /**
     * @param int $idAssistant
     */
    public static function delete(int $idAssistant): void
    {
        $removed = self::edited($idAssistant);
        if ($removed === null) {
            return;
        }

        Db::getInstance()->delete('somcp_assistant', '`id_assistant` = ' . (int) $idAssistant);
        Db::getInstance()->delete('somcp_assistant_usage', '`id_assistant` = ' . (int) $idAssistant);

        if ($removed['is_default']) {
            [$idShop, $idGroup] = self::editedLevel();
            foreach (self::atLevel($idShop, $idGroup) as $next) {
                if ($next['level'] !== self::LEVEL_IMAGE) {
                    self::makeDefault((int) $next['id_assistant']);
                    break;
                }
            }
        }
    }

    /**
     * @param int $idAssistant
     */
    public static function makeDefault(int $idAssistant): void
    {
        $assistant = self::edited($idAssistant);
        if ($assistant === null || $assistant['level'] === self::LEVEL_IMAGE) {
            return;
        }
        [$idShop, $idGroup] = self::editedLevel();
        $level = '`id_shop` = ' . (int) $idShop . ' AND `id_shop_group` = ' . (int) $idGroup;
        $db = Db::getInstance();
        // Only the assistants of this level: each level keeps its own default.
        $db->update('somcp_assistant', ['is_default' => 0], $level);
        $db->update('somcp_assistant', ['is_default' => 1], '`id_assistant` = ' . (int) $idAssistant . ' AND ' . $level);
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
            'SELECT `api_key` FROM `' . _DB_PREFIX_ . 'somcp_assistant`'
            . ' WHERE `id_assistant` = ' . (int) $assistant['id_assistant']
            . ' AND `id_shop` = ' . (int) $assistant['id_shop'] . ' AND `id_shop_group` = ' . (int) $assistant['id_shop_group']
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
     * How this assistant's model takes a request to think longer, or '' when
     * it cannot: each provider has its own switch, and a model offered a
     * switch it does not know refuses the whole request.
     *
     * Read from the model last used, without asking the provider: the chat
     * calls this to draw its menu. A model not resolved yet offers nothing
     * until its first answer.
     *
     * @param array<string, mixed> $assistant
     *
     * @return string adaptive or budget (Anthropic), level or budget (Gemini), effort (OpenAI), or ''
     */
    public static function thinking(array $assistant): string
    {
        $model = (string) ($assistant['model'] !== '' ? $assistant['model'] : $assistant['resolved_model']);
        switch ($assistant['provider']) {
            case 'anthropic':
                // Claude 3.7 and 4.0 to 4.5 take a token budget; from 4.6 on
                // the model decides how far to think, and the newest refuse a
                // budget. Older Claude 3 models do not think at all.
                if (preg_match('/^claude-3-(?!7)/', $model)) {
                    return '';
                }
                if (preg_match('/^claude-(3-7|(opus|sonnet|haiku)-4-[15](-|$)|(opus|sonnet)-4-\d{8})/', $model)) {
                    return 'budget';
                }

                return $model !== '' ? 'adaptive' : '';
            case 'gemini':
                // Gemini 2.5 takes a budget, Gemini 3 and later a level; 2.0
                // and older do not think.
                if (preg_match('/^gemini-2\.5/', $model)) {
                    return 'budget';
                }

                return preg_match('/^gemini-([3-9]|\d{2})/', $model) ? 'level' : '';
            case 'openai':
                // Only the reasoning models accept an effort; the chat variant
                // of GPT-5 is not one.
                return preg_match('/^(o\d|gpt-5)/', $model) && strpos($model, '-chat') === false ? 'effort' : '';
            default:
                return '';
        }
    }

    /**
     * Whether this assistant can search the web on its own, with the key it
     * already has.
     *
     * Only Claude does, for now: Gemini searches only when no function tool is
     * offered with it, and the chat always offers the shop's tools; OpenAI
     * searches only through another of its APIs.
     *
     * @param array<string, mixed> $assistant
     *
     * @return bool
     */
    public static function searches(array $assistant): bool
    {
        return $assistant['provider'] === 'anthropic';
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
            // A key the provider refuses would only surface on the first
            // message. The assistant is switched off instead, so it never
            // leads a conversation and, when it was the last one on, the chat
            // button goes away rather than opening onto a dead key.
            Db::getInstance()->update('somcp_assistant', ['active' => 0], '`id_assistant` = ' . (int) $assistant['id_assistant']);

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
            'INSERT INTO `' . _DB_PREFIX_ . 'somcp_assistant_usage` (`id_assistant`, `id_shop`, `month`, `input_tokens`, `output_tokens`, `cost`)'
            . ' VALUES (' . (int) $idAssistant . ', ' . self::shop() . ', \'' . pSQL(date('Y-m')) . '\', ' . (int) $inputTokens . ', ' . (int) $outputTokens . ', ' . (float) $cost . ')'
            . ' ON DUPLICATE KEY UPDATE `input_tokens` = `input_tokens` + VALUES(`input_tokens`),'
            . ' `output_tokens` = `output_tokens` + VALUES(`output_tokens`), `cost` = `cost` + VALUES(`cost`)'
        );
    }

    /**
     * What each assistant cost this month, keyed by assistant id.
     *
     * @param array<int, int>|null $shops the shops to count, the shop of the chat when null
     *
     * @return array<int, float>
     */
    public static function monthCosts(?array $shops = null): array
    {
        $ids = array_map('intval', $shops ?? [self::shop()]);
        $rows = Db::getInstance()->executeS(
            'SELECT `id_assistant`, SUM(`cost`) AS `cost` FROM `' . _DB_PREFIX_ . 'somcp_assistant_usage`'
            . ' WHERE `month` = \'' . pSQL(date('Y-m')) . '\' AND `id_shop` IN (' . implode(',', $ids ?: [0]) . ')'
            . ' GROUP BY `id_assistant`'
        );

        $costs = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $costs[(int) $row['id_assistant']] = (float) $row['cost'];
        }

        return $costs;
    }

    /**
     * The tokens each assistant used this month, input and output together,
     * keyed by assistant id.
     *
     * @param array<int, int>|null $shops the shops to count, the shop of the chat when null
     *
     * @return array<int, int>
     */
    public static function monthTokens(?array $shops = null): array
    {
        $ids = array_map('intval', $shops ?? [self::shop()]);
        $rows = Db::getInstance()->executeS(
            'SELECT `id_assistant`, SUM(`input_tokens` + `output_tokens`) AS `tokens` FROM `' . _DB_PREFIX_ . 'somcp_assistant_usage`'
            . ' WHERE `month` = \'' . pSQL(date('Y-m')) . '\' AND `id_shop` IN (' . implode(',', $ids ?: [0]) . ')'
            . ' GROUP BY `id_assistant`'
        );

        $tokens = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $tokens[(int) $row['id_assistant']] = (int) $row['tokens'];
        }

        return $tokens;
    }

    /**
     * The monthly cap of the shop of the chat, in tokens; 0 for none.
     *
     * @return int
     */
    public static function tokenCap(): int
    {
        return max(0, (int) Configuration::get(self::TOKEN_CAP, null, null, self::shop()));
    }

    /**
     * The shop the built-in assistant works for: the one in context. Every
     * assistant, its usage and its settings belong to a shop, so a multistore
     * install keeps each shop's chat, keys and spending apart.
     *
     * @return int
     */
    private static function shop(): int
    {
        return max(1, Somcp::currentShopId());
    }

    /**
     * Which monthly cap is reached, if any: the shop's, for all assistants,
     * or the assistant's own. Checked before every model call.
     *
     * @param array<string, mixed>|null $assistant the assistant about to be called
     *
     * @return string shop, assistant, or empty when neither is reached
     */
    public static function capReached(?array $assistant = null): string
    {
        $used = self::monthTokens();
        $cap = self::tokenCap();
        if ($cap > 0 && array_sum($used) >= $cap) {
            return 'shop';
        }
        $own = (int) ($assistant['token_cap'] ?? 0);
        if ($own > 0 && ($used[(int) ($assistant['id_assistant'] ?? 0)] ?? 0) >= $own) {
            return 'assistant';
        }

        return '';
    }

    /**
     * How many tokens a sum in US dollars buys at the output price of a model,
     * the higher of its two prices: a cap converted this way is never looser
     * than the one it replaces. 0 when the model's price is not known.
     *
     * @param float $dollars
     * @param string $provider
     * @param string $model
     *
     * @return int
     */
    public static function tokensFor(float $dollars, string $provider, string $model): int
    {
        $output = self::rates($provider, $model)[1];

        return $output > 0 ? (int) floor($dollars / $output * 1000000) : 0;
    }

    /**
     * Whether an employee profile may use the built-in assistant.
     *
     * A rule of its own, beside the one for connecting an assistant over MCP:
     * a merchant may well let every employee chat in the back office and keep
     * outside assistants to a few, or the other way round. The shop's
     * administrators always may, as they may change this very setting.
     *
     * @param int $idProfile
     *
     * @return bool
     */
    public static function mayUse(int $idProfile): bool
    {
        $required = (int) Configuration::get(self::PROFILE, null, null, self::shop());

        return $required <= 0 || $idProfile === $required || $idProfile === (int) _PS_ADMIN_PROFILE_;
    }

    /**
     * @return string
     */
    public static function instructions(): string
    {
        return (string) Configuration::get(self::INSTRUCTIONS, null, null, self::shop());
    }

    /**
     * Saves the settings at the level being edited. A setting whose "value of
     * its own" box is unchecked is not written: its row at this level is
     * removed and the value comes from the level above, as on the other tabs.
     *
     * @param int $tokenCap
     * @param string $instructions
     * @param int $idProfile
     * @param array<string, bool> $own key => whether this level keeps a value of its own
     */
    public static function saveSettings(int $tokenCap, string $instructions, int $idProfile, array $own = []): void
    {
        $values = [
            self::PROFILE => [(string) max(0, $idProfile), false],
            self::TOKEN_CAP => [(string) max(0, $tokenCap), false],
            // Stored as HTML-allowed so the merchant's punctuation survives:
            // the value is only ever sent to the model and escaped wherever shown.
            self::INSTRUCTIONS => [Tools::substr($instructions, 0, 4000), true],
        ];
        foreach ($values as $key => [$value, $html]) {
            if (SomcpConfig::inheritableContext() && empty($own[$key])) {
                SomcpConfig::inherit($key);
                continue;
            }
            // No shop given: PrestaShop writes at the level the back office is
            // set to - all shops, the group or the shop.
            Configuration::updateValue($key, $value, $html);
        }
    }

    /**
     * The settings as the level being edited sees them, for the form.
     *
     * @return array{token_cap:int, instructions:string, profile:int}
     */
    public static function editedSettings(): array
    {
        return [
            'token_cap' => max(0, (int) Configuration::get(self::TOKEN_CAP)),
            'instructions' => (string) Configuration::get(self::INSTRUCTIONS),
            'profile' => (int) Configuration::get(self::PROFILE),
        ];
    }

    /**
     * Removes what the module stored outside its tables, on uninstall.
     */
    public static function purge(): void
    {
        Configuration::deleteByName(self::BUDGET);
        Configuration::deleteByName(self::TOKEN_CAP);
        Configuration::deleteByName(self::INSTRUCTIONS);
        Configuration::deleteByName(self::LINK_KEY);
        Configuration::deleteByName(self::PROFILE);
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
            'id_shop' => (int) $row['id_shop'],
            'id_shop_group' => (int) ($row['id_shop_group'] ?? 0),
            'token_cap' => (int) ($row['token_cap'] ?? 0),
        ];
    }

    /**
     * @param string $plain
     *
     * @return string
     */
    public static function seal(string $plain): string
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
    public static function open(string $stored): string
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

        if ($level === self::LEVEL_IMAGE) {
            return self::pickImage($provider, $models);
        }

        $ids = [];
        foreach ($models as $model) {
            $id = $model['id'];
            if (preg_match('/(preview|exp|embed|tts|audio|image|vision|realtime|transcribe|search|translate|-\d{4}-\d{2}-\d{2}$)/', $id)
                && $provider !== 'anthropic') {
                continue;
            }
            $ids[$id] = $model['created'];
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
     * The newest image model of a provider: the stable one when there is
     * one, a preview otherwise, and the smaller variant last.
     *
     * @param string $provider
     * @param array<int, array{id:string, created:int}> $models
     *
     * @return string empty when the account lists none
     */
    private static function pickImage(string $provider, array $models): string
    {
        $rules = [
            'gemini' => ['/^gemini-\d+(\.\d+)?-flash-image$/', '/^gemini-\d+(\.\d+)?-flash-image-preview$/', '/^gemini-[\d.]+-pro-image/'],
            'openai' => ['/^gpt-image-\d+(\.\d+)?$/', '/^gpt-image-\d+(\.\d+)?-mini$/', '/^dall-e-3$/'],
        ];

        foreach ($rules[$provider] ?? [] as $rule) {
            $candidates = [];
            foreach ($models as $model) {
                if (preg_match($rule, $model['id'])) {
                    $candidates[$model['id']] = $model['created'];
                }
            }
            if ($candidates !== []) {
                uksort($candidates, static function (string $a, string $b) use ($candidates): int {
                    return $candidates[$b] <=> $candidates[$a] ?: version_compare(self::versionOf($b), self::versionOf($a));
                });

                return (string) array_key_first($candidates);
            }
        }

        return '';
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
            // Image models first: their names hold the family names of the
            // text models, and an image costs far more per output token.
            'openai' => [['gpt-image', 5.0, 40.0], ['dall-e', 0.0, 40.0], ['nano', 0.05, 0.4], ['mini', 0.25, 2.0], ['gpt', 1.25, 10.0]],
            'gemini' => [['-image', 0.3, 30.0], ['flash-lite', 0.1, 0.4], ['flash', 0.3, 2.5], ['pro', 1.25, 10.0]],
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
