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
 * The part of the dependencies the module calls, checked by reflection.
 *
 * Every class, method and constructor parameter listed here is one that
 * classes/SomcpAssistantChat.php, classes/SomcpAssistantMemory.php or
 * classes/SomcpRelayClient.php relies on, and below them the part of
 * novalo/mcp-tool-discovery that classes/SomcpCompact.php and
 * src/Discovery/ShopGate.php rely on. A new version of either that renames,
 * removes or reshapes one of them fails here, in CI, instead of in the shop of
 * a merchant. Keep the list in
 * step with those files. The CI runs it on the pruned archive, so a part
 * left out of the module is caught here too.
 *
 * Usage: php dev/package/contract.php <module folder>
 */

$module = rtrim($argv[1] ?? '.', '/');
require $module . '/vendor/autoload.php';

$methods = [
    'NeuronAI\Agent\Agent' => [
        'make', 'setThreadId', 'setMessageStore', 'setPersistence', 'setAiProvider', 'setInstructions',
        'setTools', 'toolMaxRuns', 'chat', 'inspect', 'submitToolResults', 'submitApprovalDecisions',
        'resetConversation',
    ],
    'NeuronAI\Agent\AgentState' => ['getSteps', 'getMessage'],
    'NeuronAI\Agent\Interrupt\ApprovalRequest' => ['getActions'],
    'NeuronAI\Agent\Interrupt\ToolResultsRequest' => ['getToolCalls', 'getResults'],
    'NeuronAI\Agent\Interrupt\Action' => ['isPending'],
    'NeuronAI\Chat\History\SQLMessageStore' => ['loadAll'],
    'NeuronAI\Chat\Messages\ToolCallMessage' => ['getToolCalls'],
    'NeuronAI\Tools\FrontendTool' => ['requireApproval', 'getInputSchema'],
    'NeuronAI\Tools\ToolCall' => ['getName', 'getCallId', 'getInputs', 'getApprovalState', 'hasResult', 'getResult'],
    'NeuronAI\Chat\Messages\Message' => ['getContent', 'getUsage', 'getContentBlocks', 'getTextBlocks', 'getMetadata'],
    // Web search and deep thinking.
    'NeuronAI\Tools\ProviderTool' => ['make'],
    'NeuronAI\Workflow\Persistence\DatabasePersistence' => ['get'],
    // The memory.
    'NeuronAI\RAG\RAG' => ['make', 'setRetrieval'],
    'NeuronAI\RAG\Document' => ['setSourceType', 'setSourceName'],
    'NeuronAI\RAG\VectorStore\FileVectorStore' => ['addDocuments', 'delete'],
    'NeuronAI\RAG\VectorStore\Filter\FilterGroup' => ['and'],
    'NeuronAI\RAG\VectorStore\Filter\Filter' => ['eq'],
    'NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider' => ['embedDocuments'],
    // The local assistant's relay client.
    'NeuronAI\Exceptions\HttpException' => ['networkError', 'statusError'],
    // The summary of long conversations, whose call is counted.
    'NeuronAI\Agent\Middleware\Summarization' => ['generateSummary', 'getDefaultSummaryPrompt', 'formatMessagesForSummarization'],
    // Compact mode and the tool search, from novalo/mcp-tool-discovery.
    'Novalo\McpToolDiscovery\Discovery' => ['call'],
    'Novalo\McpToolDiscovery\Mode\Compact\CompactMode' => ['listTools', 'guideHeader'],
    'Novalo\McpToolDiscovery\Tool\Tool' => ['fromArray', 'toArray', 'title', 'description', 'readsOnly'],
    'Novalo\McpToolDiscovery\Tool\ToolSet' => ['of', 'filter'],
    'Novalo\McpToolDiscovery\Gate\CallResult' => ['text', 'error', 'fromArray'],
    'Novalo\McpToolDiscovery\Search\SearchSetup' => ['searchFor'],
    'Novalo\McpToolDiscovery\Search\SearchQuery' => ['fromArguments'],
    'Novalo\McpToolDiscovery\Search\ToolSearch' => ['find'],
];

// Constructor parameters by position, as the assistant passes them.
$constructors = [
    'NeuronAI\Providers\Anthropic\Anthropic' => ['key', 'model', 'version', 'max_tokens', 'parameters'],
    'NeuronAI\Agent\Middleware\Summarization' => ['provider', 'maxTokens'],
    'NeuronAI\Providers\OpenAI\OpenAI' => ['key', 'model', 'parameters'],
    'NeuronAI\Providers\Gemini\Gemini' => ['key', 'model', 'parameters'],
    'NeuronAI\Tools\ProviderTool' => ['type', 'name', 'options'],
    'NeuronAI\Providers\Mistral\Mistral' => ['key', 'model'],
    'NeuronAI\Providers\Deepseek\Deepseek' => ['key', 'model'],
    'NeuronAI\Providers\XAI\Grok' => ['key', 'model'],
    'NeuronAI\Providers\Cohere\Cohere' => ['key', 'model'],
    'NeuronAI\Providers\Ollama\Ollama' => ['url', 'model'],
    'NeuronAI\Providers\OpenAILike' => ['baseUri', 'key', 'model'],
    'NeuronAI\HttpClient\HttpResponse' => ['statusCode', 'body', 'headers'],
    'NeuronAI\Providers\OpenAI\Image\OpenAIImage' => ['key', 'model'],
    'NeuronAI\Chat\Messages\ContentBlocks\ImageContent' => ['content', 'sourceType', 'mediaType'],
    'NeuronAI\Chat\Messages\ContentBlocks\FileContent' => ['content', 'sourceType', 'mediaType', 'filename'],
    'NeuronAI\Chat\Messages\ContentBlocks\TextContent' => ['content'],
    'NeuronAI\Tools\FrontendTool' => ['name', 'description', 'inputSchema'],
    'NeuronAI\Chat\History\SQLMessageStore' => ['pdo', 'table'],
    'NeuronAI\Workflow\Persistence\DatabasePersistence' => ['pdo', 'table'],
    'NeuronAI\Chat\Messages\UserMessage' => ['content'],
    'NeuronAI\RAG\Document' => ['content'],
    'NeuronAI\RAG\VectorStore\FileVectorStore' => ['directory', 'topK', 'name'],
    'NeuronAI\RAG\Retrieval\SemanticMemoryRetrieval' => ['vectorStore', 'embeddingProvider', 'threadIds'],
    'NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider' => ['key', 'model'],
    'NeuronAI\RAG\Embeddings\GeminiEmbeddingsProvider' => ['key', 'model'],
    'NeuronAI\RAG\Embeddings\MistralEmbeddingsProvider' => ['key', 'model'],
    'NeuronAI\RAG\Embeddings\CohereEmbeddingsProvider' => ['key', 'model'],
    'NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider' => ['key', 'model'],
    'Novalo\McpToolDiscovery\Mode\Compact\CompactMode' => ['prefix', 'pinned', 'titles', 'groupOf', 'maxDescribed'],
    'Novalo\McpToolDiscovery\Discovery' => ['mode', 'gate', 'search'],
];

$properties = [
    'NeuronAI\Workflow\WorkflowRunSnapshot' => ['interrupt'],
    'NeuronAI\Agent\Interrupt\Action' => ['id', 'name', 'inputs'],
    'NeuronAI\Chat\Messages\Usage' => ['inputTokens', 'outputTokens', 'cachedInputTokens', 'reasoningTokens'],
    'NeuronAI\Agent\Middleware\Summarization' => ['summaryPrompt'],
    'NeuronAI\Chat\Messages\Citation' => ['source', 'title'],
    'NeuronAI\Chat\Messages\ContentBlocks\ImageContent' => ['content', 'sourceType', 'mediaType'],
    'NeuronAI\HttpClient\HttpRequest' => ['body', 'timeout'],
    'Novalo\McpToolDiscovery\Tool\Tool' => ['name'],
    'Novalo\McpToolDiscovery\Gate\CallResult' => ['content', 'isError', 'structuredContent'],
    'Novalo\McpToolDiscovery\Search\SearchResult' => ['tools'],
];

// Implemented by SomcpRelayClient and Novalo\Somcp\Discovery\ShopGate: a
// method added here would leave them abstract.
$interfaces = [
    'NeuronAI\HttpClient\HttpClientInterface' => ['request', 'stream'],
    'Novalo\McpToolDiscovery\Gate\ToolGate' => ['visible', 'authorize', 'call'],
];

$constants = [
    // SomcpCompact::SEARCH_TOOL and its runners are written out as the prefix
    // plus these, so a renamed tool of the library is caught here.
    'Novalo\McpToolDiscovery\Mode\Compact\CompactMode::SEARCH' => 'search_tools',
    'Novalo\McpToolDiscovery\Mode\Compact\CompactMode::RUN_READ' => 'run_read_tool',
    'Novalo\McpToolDiscovery\Mode\Compact\CompactMode::RUN_WRITE' => 'run_write_tool',
    'Novalo\McpToolDiscovery\Exception\InvalidTool::class' => 'Novalo\McpToolDiscovery\Exception\InvalidTool',
];

$classes = [
    'NeuronAI\Exceptions\RunInFlightException',
    'NeuronAI\Chat\Messages\AssistantMessage',
    'NeuronAI\Chat\Messages\ToolResultMessage',
    'NeuronAI\Tools\ApprovalState',
    'NeuronAI\Chat\Enums\SourceType',
    'NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent',
];

$problems = [];

// Read by the memory: it must say the same as SomcpAssistantMemory::SOURCE_TYPE,
// or recalled passages are tagged with a type the instructions do not name.
if (!defined('NeuronAI\RAG\Retrieval\SemanticMemoryRetrieval::SOURCE_TYPE')
    || constant('NeuronAI\RAG\Retrieval\SemanticMemoryRetrieval::SOURCE_TYPE') !== 'conversation') {
    $problems[] = 'SemanticMemoryRetrieval::SOURCE_TYPE is no longer conversation: compare it with SomcpAssistantMemory::SOURCE_TYPE';
}

foreach ($constants as $constant => $value) {
    if (strpos($constant, '::class') !== false) {
        if (!class_exists($value)) {
            $problems[] = $value . ' is gone';
        }
        continue;
    }
    if (!defined($constant) || constant($constant) !== $value) {
        $problems[] = $constant . ' is no longer ' . $value;
    }
}

foreach ($classes as $class) {
    if (!class_exists($class) && !enum_exists($class)) {
        $problems[] = $class . ' is gone';
    }
}

foreach ($interfaces as $interface => $names) {
    if (!interface_exists($interface)) {
        $problems[] = $interface . ' is gone';
        continue;
    }
    $declared = array_map(static function (ReflectionMethod $method): string {
        return $method->getName();
    }, (new ReflectionClass($interface))->getMethods());
    sort($declared);
    $known = $names;
    sort($known);
    if ($declared !== $known) {
        $problems[] = $interface . ' now declares ' . implode(', ', $declared) . ': compare it with classes/SomcpRelayClient.php';
    }
}

foreach ($methods as $class => $names) {
    if (!class_exists($class)) {
        $problems[] = $class . ' is gone';
        continue;
    }
    foreach ($names as $name) {
        if (!method_exists($class, $name)) {
            $problems[] = $class . '::' . $name . '() is gone';
        }
    }
}

foreach ($constructors as $class => $expected) {
    if (!class_exists($class)) {
        $problems[] = $class . ' is gone';
        continue;
    }
    $parameters = (new ReflectionMethod($class, '__construct'))->getParameters();
    foreach ($expected as $position => $name) {
        $actual = isset($parameters[$position]) ? $parameters[$position]->getName() : '(none)';
        if ($actual !== $name) {
            $problems[] = $class . ' constructor: parameter ' . ($position + 1) . ' is now ' . $actual . ', expected ' . $name;
        }
    }
}

foreach ($properties as $class => $names) {
    foreach ($names as $name) {
        if (!property_exists($class, $name)) {
            $problems[] = $class . '::$' . $name . ' is gone';
        }
    }
}

// The assistant overrides generateSummary() to count the summary's tokens:
// a changed signature would make PHP refuse the override at load time.
if (method_exists('NeuronAI\Agent\Middleware\Summarization', 'generateSummary')) {
    $method = new ReflectionMethod('NeuronAI\Agent\Middleware\Summarization', 'generateSummary');
    $names = array_map(static function (ReflectionParameter $p): string {
        return $p->getName();
    }, $method->getParameters());
    if ($names !== ['provider', 'messages'] || (string) $method->getReturnType() !== '?string' || !$method->isProtected()) {
        $problems[] = 'Summarization::generateSummary() changed shape: compare it with the override in classes/SomcpAssistantChat.php';
    }
}

// The chat hides the sentence Neuron writes for an answer a broken turn
// never gave, matched word for word: a reworded one would reach the employee.
if (trait_exists('NeuronAI\Agent\ClosesOpenTurn')) {
    $closer = new class {
        use NeuronAI\Agent\ClosesOpenTurn;

        public function answer(): string
        {
            return (string) $this->interruptedAnswer()->getContent();
        }
    };
    // Read from the source: the class needs PrestaShop to load.
    preg_match("/INTERRUPTED_ANSWER = '([^']*)'/", (string) file_get_contents($module . '/classes/SomcpAssistantChat.php'), $hidden);
    if ($closer->answer() !== ($hidden[1] ?? null)) {
        $problems[] = 'Neuron reworded the answer of an interrupted turn: update SomcpAssistantChat::INTERRUPTED_ANSWER';
    }
} else {
    $problems[] = 'NeuronAI\Agent\ClosesOpenTurn is gone: check how the chat shows a turn that broke half way';
}

// The run store must still accept the table layout of sql/install.sql: the
// framework documents it in the class comment, which is read here.
$doc = (string) (new ReflectionClass('NeuronAI\Workflow\Persistence\DatabasePersistence'))->getDocComment();
foreach (['`partition`', '`key`', '`value`'] as $column) {
    if (strpos($doc, $column) === false) {
        $problems[] = 'DatabasePersistence no longer documents the column ' . $column . ': compare its schema with sql/install.sql';
    }
}
$doc = (string) (new ReflectionClass('NeuronAI\Chat\History\SQLMessageStore'))->getDocComment();
foreach (['thread_id', 'message_id', 'role', 'content', 'meta', 'archived_at'] as $column) {
    if (strpos($doc, $column) === false) {
        $problems[] = 'SQLMessageStore no longer documents the column ' . $column . ': compare its schema with sql/install.sql';
    }
}

foreach ($problems as $problem) {
    echo '::error::' . $problem . PHP_EOL;
}
echo count($problems) === 0 ? 'Neuron AI API as the assistant expects it' . PHP_EOL : count($problems) . ' change(s) in the API the assistant calls' . PHP_EOL;
exit($problems === [] ? 0 : 1);
