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
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider;
use NeuronAI\RAG\Retrieval\SemanticMemoryRetrieval;
use NeuronAI\RAG\VectorStore\FileVectorStore;
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
    /** @var string Provider that turns text into vectors, '' when memory is off. */
    public const PROVIDER = 'SOMCP_EMB_PROVIDER';

    /** @var string The provider's key (or an Ollama server address), encrypted. */
    public const KEY = 'SOMCP_EMB_KEY';

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
     * uses by default so the merchant only picks a provider and pastes a key.
     * Anthropic is absent on purpose: it has no embeddings endpoint. Ollama
     * runs on the merchant's own server, so its "key" is that address.
     *
     * @var array<string, array{label:string, model:string, key_url:string, local:bool}>
     */
    private const PROVIDERS = [
        'openai' => ['label' => 'OpenAI', 'model' => 'text-embedding-3-small', 'key_url' => 'https://platform.openai.com/api-keys', 'local' => false],
        'gemini' => ['label' => 'Gemini (Google)', 'model' => 'text-embedding-004', 'key_url' => 'https://aistudio.google.com/apikey', 'local' => false],
        'mistral' => ['label' => 'Mistral', 'model' => 'mistral-embed', 'key_url' => 'https://console.mistral.ai/api-keys', 'local' => false],
        'cohere' => ['label' => 'Cohere', 'model' => 'embed-v4.0', 'key_url' => 'https://dashboard.cohere.com/api-keys', 'local' => false],
        'voyage' => ['label' => 'Voyage', 'model' => 'voyage-3', 'key_url' => 'https://dashboard.voyageai.com', 'local' => false],
        'ollama' => ['label' => 'Ollama', 'model' => 'nomic-embed-text', 'key_url' => 'https://ollama.com/download', 'local' => true],
    ];

    /**
     * The providers offered on the settings page, each with its label, the
     * default model and where to get a key.
     *
     * @return array<string, array{label:string, model:string, key_url:string, local:bool}>
     */
    public static function providers(): array
    {
        return self::PROVIDERS;
    }

    /**
     * Whether the memory is set up and usable for a shop: a known provider and,
     * unless it is a local server, a key.
     *
     * @param int $idShop
     *
     * @return bool
     */
    public static function enabled(int $idShop): bool
    {
        $provider = self::providerId($idShop);

        return $provider !== '' && self::key($idShop) !== '';
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
     * The key in clear, or the Ollama server address. Never sent to a page.
     *
     * @param int $idShop
     *
     * @return string
     */
    public static function key(int $idShop): string
    {
        return SomcpAssistant::open((string) Configuration::get(self::KEY, null, null, $idShop));
    }

    /**
     * @param int $idShop
     * @param string $provider '' turns the memory off
     * @param string $key the provider's key, or an Ollama address; '' keeps the stored one
     */
    public static function save(int $idShop, string $provider, string $key): void
    {
        $provider = isset(self::PROVIDERS[$provider]) ? $provider : '';
        Configuration::updateValue(self::PROVIDER, $provider, false, null, $idShop);

        $key = trim($key);
        if ($provider === '') {
            Configuration::updateValue(self::KEY, '', false, null, $idShop);
        } elseif ($key !== '') {
            Configuration::updateValue(self::KEY, SomcpAssistant::seal($key), false, null, $idShop);
        }
    }

    /**
     * Removes what memory stored outside its files, on uninstall.
     */
    public static function purge(): void
    {
        Configuration::deleteByName(self::PROVIDER);
        Configuration::deleteByName(self::KEY);
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
            case 'ollama':
                // The key field holds the merchant's own server address, read
                // the same way the chat reads an Ollama address.
                return new OllamaEmbeddingsProvider($model, rtrim(SomcpAssistant::ollamaBase($key), '/') . '/api');
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
