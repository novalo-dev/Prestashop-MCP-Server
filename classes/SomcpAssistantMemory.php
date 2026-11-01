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

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\CohereEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\GeminiEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\MistralEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider;
use NeuronAI\RAG\Retrieval\SemanticMemoryRetrieval;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The long-term memory of the built-in assistant: past conversations, kept so
 * the assistant can recall an earlier discussion instead of starting blank
 * every time.
 *
 * It is the RAG side of Neuron AI. Each conversation turn is embedded once and
 * stored on the shop's own disk; on a new question the matching passages are
 * retrieved and handed to the model, rather than resending whole conversations.
 * Everything here is per shop, and the retrieval is filtered to the employee's
 * own conversations, so one employee never reads another's through the chat.
 *
 * It stays switched off until the merchant sets an embeddings provider and its
 * key on the settings page: embeddings are a paid call of their own, and the
 * merchant chooses whether to spend on memory.
 */
class SomcpAssistantMemory
{
    /** @var string Whether the merchant switched the memory on. */
    public const ON = 'SOMCP_EMB_ON';

    /** @var string Provider that turns text into vectors. */
    public const PROVIDER = 'SOMCP_EMB_PROVIDER';

    /** @var string A key of its own, encrypted. Only for a provider with no chat assistant to borrow one from (Voyage). */
    public const KEY = 'SOMCP_EMB_KEY';

    /** @var string save() refused: no provider the memory can use. */
    public const NEEDS_PROVIDER = 'provider';

    /** @var string save() refused: the provider has no key to embed with. */
    public const NEEDS_KEY = 'key';

    /**
     * @var string The kind a conversation document carries, matched by the
     *             retrieval. It must equal SemanticMemoryRetrieval::SOURCE_TYPE.
     */
    public const SOURCE_TYPE = 'conversation';

    /** @var string Where the vector files live, under the PrestaShop root, off the web. */
    private const STORE = 'var/somcp/memory/';

    /** @var EmbeddingsProviderInterface|null Stands in for the provider, in the shop tests only. */
    private static $embeddingsOverride;

    /**
     * Test seam: makes memory embed with a stand-in provider instead of calling
     * a real one, so the shop tests run offline. Never used in production.
     *
     * @param EmbeddingsProviderInterface|null $provider
     */
    public static function overrideEmbeddings(?EmbeddingsProviderInterface $provider): void
    {
        self::$embeddingsOverride = $provider;
    }

    /**
     * The embeddings providers Neuron AI supports, each with the model memory
     * uses by default so the merchant only picks a provider. Anthropic is
     * absent: it has no embeddings endpoint. `reuse` says the provider also
     * serves the chat, so memory borrows that assistant's key rather than ask
     * again; Voyage does embeddings only, so it brings a key of its own.
     *
     * @var array<string, array{label:string, model:string, key_url:string, reuse:bool}>
     */
    private const PROVIDERS = [
        'openai' => ['label' => 'OpenAI', 'model' => 'text-embedding-3-small', 'key_url' => 'https://platform.openai.com/api-keys', 'reuse' => true],
        'gemini' => ['label' => 'Gemini (Google)', 'model' => 'gemini-embedding-001', 'key_url' => 'https://aistudio.google.com/apikey', 'reuse' => true],
        'mistral' => ['label' => 'Mistral', 'model' => 'mistral-embed', 'key_url' => 'https://console.mistral.ai/api-keys', 'reuse' => true],
        'cohere' => ['label' => 'Cohere', 'model' => 'embed-v4.0', 'key_url' => 'https://dashboard.cohere.com/api-keys', 'reuse' => true],
        'voyage' => ['label' => 'Voyage', 'model' => 'voyage-3', 'key_url' => 'https://dashboard.voyageai.com', 'reuse' => false],
    ];

    /**
     * The providers offered in the memory selector: the ones that also serve
     * the chat and already have a key on this shop to borrow (so no key is
     * asked twice), plus Voyage, which does embeddings only and brings its own.
     * Voyage is marked special so the form shows its key field on selection.
     *
     * @param int $idShop
     *
     * @return array<string, array{label:string, key_url:string, special:bool}>
     */
    public static function selectable(int $idShop): array
    {
        $offered = [];
        foreach (self::PROVIDERS as $id => $provider) {
            if ($provider['reuse'] && SomcpAssistant::keyForProvider($id) === '') {
                // A reusable provider with no chat assistant to borrow a key
                // from is not offered: memory would have no key to embed with.
                continue;
            }
            $offered[$id] = [
                'label' => $provider['label'],
                'key_url' => $provider['key_url'],
                'special' => !$provider['reuse'],
            ];
        }

        return $offered;
    }

    /**
     * Whether the merchant switched the memory on.
     *
     * @param int $idShop
     *
     * @return bool
     */
    public static function on(int $idShop): bool
    {
        return (string) Configuration::get(self::ON, null, null, $idShop) === '1';
    }

    /**
     * Whether the memory is on, set to a known provider and has a key to embed
     * with - borrowed from the chat, or Voyage's own.
     *
     * @param int $idShop
     *
     * @return bool
     */
    public static function enabled(int $idShop): bool
    {
        return self::on($idShop) && self::providerId($idShop) !== '' && self::key($idShop) !== '';
    }

    /**
     * @param int $idShop
     *
     * @return string the provider id, '' when none is set or it is unknown
     */
    public static function providerId(int $idShop): string
    {
        $provider = (string) Configuration::get(self::PROVIDER, null, null, $idShop);

        return isset(self::PROVIDERS[$provider]) ? $provider : '';
    }

    /**
     * The key memory embeds with: Voyage's own stored key, or the key of a chat
     * assistant using the same provider. Never sent to a page.
     *
     * @param int $idShop
     *
     * @return string
     */
    public static function key(int $idShop): string
    {
        $provider = self::providerId($idShop);
        if ($provider === '') {
            return '';
        }
        if (self::PROVIDERS[$provider]['reuse']) {
            return SomcpAssistant::keyForProvider($provider);
        }

        return SomcpAssistant::open((string) Configuration::get(self::KEY, null, null, $idShop));
    }

    /**
     * Saves the memory settings at the level the back office is set to - all
     * shops, a group or a shop. The switch, the provider and the key are one
     * setting: a level either keeps all three of its own or inherits all three.
     *
     * Switching it on is refused unless it can run: a provider offered here,
     * and a key to embed with. A memory saved as on without them did nothing
     * while the page - and the chat - said it was on. No provider is ever
     * picked on the merchant's behalf, even when only one is offered: the
     * chat once chose one by itself, and the choice of who receives the
     * conversations is the merchant's. Refused, nothing of the memory is
     * changed.
     *
     * @param bool $on
     * @param string $provider
     * @param string $key a key of its own, only for a non-reusable provider; '' keeps the stored one
     * @param bool $own whether this level keeps a value of its own
     *
     * @return string '' when saved, NEEDS_PROVIDER or NEEDS_KEY when refused
     */
    public static function save(bool $on, string $provider, string $key, bool $own = true): string
    {
        if (SomcpConfig::inheritableContext() && !$own) {
            foreach ([self::ON, self::PROVIDER, self::KEY] as $setting) {
                SomcpConfig::inherit($setting);
            }

            return '';
        }

        if ($on) {
            $offered = self::selectable(Somcp::currentShopId());
            if (!isset($offered[$provider])) {
                return self::NEEDS_PROVIDER;
            }
            // A provider of the chat is offered only with a key to lend; one
            // that brings its own needs it typed now or stored before.
            if ($offered[$provider]['special'] && trim($key) === '' && (string) Configuration::get(self::KEY) === '') {
                return self::NEEDS_KEY;
            }
        }

        $provider = isset(self::PROVIDERS[$provider]) ? $provider : '';
        Configuration::updateValue(self::ON, $on ? '1' : '0');
        Configuration::updateValue(self::PROVIDER, $provider);

        $key = trim($key);
        // The own key is kept only for a provider that needs one, and only when
        // the merchant typed it; blank keeps whatever was stored.
        if ($provider === '' || self::PROVIDERS[$provider]['reuse']) {
            Configuration::updateValue(self::KEY, '');
        } elseif ($key !== '') {
            Configuration::updateValue(self::KEY, SomcpAssistant::seal($key));
        }

        return '';
    }

    /**
     * Removes everything memory stored, on uninstall: its settings and the
     * folder of embedded messages. Those messages are the employees' words;
     * leaving them on the server after the module goes would keep data nobody
     * can reach any more and nobody will remember to delete.
     */
    public static function purge(): void
    {
        Configuration::deleteByName(self::ON);
        Configuration::deleteByName(self::PROVIDER);
        Configuration::deleteByName(self::KEY);

        $directory = rtrim((string) _PS_ROOT_DIR_, '/\\') . '/' . self::STORE;
        if (!is_dir($directory)) {
            return;
        }
        $entries = @scandir($directory);
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($directory . $entry)) {
                @unlink($directory . $entry);
            }
        }
        @rmdir($directory);
        // The module's own folder under var/, as SomcpUpload::removeStore()
        // leaves it: rmdir refuses it while anything else is still inside.
        @rmdir(dirname($directory));
    }

    /**
     * Deletes what memory kept of one conversation, when it is deleted: a
     * conversation the employee removed must not live on in what the
     * assistant recalls, nor on the disk. Done whether memory is on or not,
     * since it may have been on while the conversation was written.
     *
     * @param int $idShop
     * @param string $threadId
     */
    public static function forget(int $idShop, string $threadId): void
    {
        // No folder means memory never wrote anything: nothing to delete, and
        // no reason to create the folder now.
        if ($threadId === '' || !is_dir(rtrim((string) _PS_ROOT_DIR_, '/\\') . '/' . self::STORE)) {
            return;
        }

        try {
            self::store($idShop)->delete(FilterGroup::and(
                Filter::eq('sourceType', self::SOURCE_TYPE),
                Filter::eq('sourceName', $threadId)
            ));
        } catch (Throwable $e) {
            SomcpLogger::log('assistant_memory_failed', SomcpLogger::OUTCOME_FAILURE, Tools::substr($e->getMessage(), 0, 200), '', 0);
        }
    }

    /**
     * Stores the new messages of a conversation, each as one embedded document
     * tied to the conversation. Called after a turn is answered, with only the
     * messages written since last time, so nothing is embedded or paid for
     * twice.
     *
     * @param int $idShop
     * @param string $threadId
     * @param array<int, array{role:string, text:string}> $messages
     */
    public static function write(int $idShop, string $threadId, array $messages): void
    {
        $provider = self::embeddings($idShop);
        if ($provider === null || $messages === []) {
            return;
        }

        $documents = [];
        foreach ($messages as $message) {
            $text = trim($message['text']);
            if ($text === '') {
                continue;
            }
            $document = new Document($message['role'] . ': ' . $text);
            $document->setSourceType(self::SOURCE_TYPE);
            $document->setSourceName($threadId);
            $documents[] = $document;
        }
        if ($documents === []) {
            return;
        }

        try {
            self::store($idShop)->addDocuments($provider->embedDocuments($documents));
        } catch (Throwable $e) {
            // Memory is a convenience: a provider that refuses or a disk that
            // will not write must never break the chat. The turn is already
            // answered; the exchange is simply not remembered.
            SomcpLogger::log('assistant_memory_failed', SomcpLogger::OUTCOME_FAILURE, Tools::substr($e->getMessage(), 0, 200), '', 0);
        }
    }

    /**
     * The retrieval the chat reads memory through, scoped to the conversations
     * passed - the employee's own. Null when memory is off or there is nothing
     * to scope it to.
     *
     * @param int $idShop
     * @param array<int, string> $threadIds
     *
     * @return SemanticMemoryRetrieval|null
     */
    public static function retrieval(int $idShop, array $threadIds): ?SemanticMemoryRetrieval
    {
        $provider = self::embeddings($idShop);
        $threadIds = array_values(array_filter(array_unique($threadIds), static function (string $id): bool {
            return $id !== '';
        }));
        if ($provider === null || $threadIds === []) {
            return null;
        }

        return new SemanticMemoryRetrieval(self::store($idShop), $provider, $threadIds);
    }

    /**
     * The embeddings provider set for the shop, ready to call, or null when
     * memory is off.
     *
     * @param int $idShop
     *
     * @return EmbeddingsProviderInterface|null
     */
    public static function embeddings(int $idShop): ?EmbeddingsProviderInterface
    {
        if (self::$embeddingsOverride !== null) {
            return self::$embeddingsOverride;
        }

        $provider = self::providerId($idShop);
        $key = self::key($idShop);
        if ($provider === '' || $key === '') {
            return null;
        }
        $model = self::PROVIDERS[$provider]['model'];

        switch ($provider) {
            case 'openai':
                return new OpenAIEmbeddingsProvider($key, $model);
            case 'gemini':
                return new GeminiEmbeddingsProvider($key, $model);
            case 'mistral':
                return new MistralEmbeddingsProvider($key, $model);
            case 'cohere':
                return new CohereEmbeddingsProvider($key, $model);
            case 'voyage':
                return new VoyageEmbeddingsProvider($key, $model);
            default:
                return null;
        }
    }

    /**
     * The vector store for one shop: a file of its own, under the PrestaShop
     * root and shut off from the web.
     *
     * @param int $idShop
     *
     * @return VectorStoreInterface
     */
    private static function store(int $idShop): VectorStoreInterface
    {
        $directory = rtrim((string) _PS_ROOT_DIR_, '/\\') . '/' . self::STORE;
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }
        if (!is_file($directory . '.htaccess')) {
            @file_put_contents($directory . '.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!is_file($directory . 'index.php')) {
            @file_put_contents($directory . 'index.php', "<?php\nheader('HTTP/1.0 404 Not Found');\n");
        }

        return new FileVectorStore($directory, 4, 'shop_' . $idShop);
    }
}
