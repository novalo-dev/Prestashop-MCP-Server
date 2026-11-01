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
 * The part of Neuron AI the built-in assistant calls, checked by reflection.
 *
 * Every class, method and constructor parameter listed here is one that
 * classes/SomcpAssistantChat.php or classes/SomcpAssistantMemory.php relies
 * on. A new version of Neuron that renames, removes or reshapes one of them
 * fails here, in CI, instead of in the chat of a merchant. Keep the list in
 * step with those two files. The CI runs it on the pruned archive, so a part
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
];

// Constructor parameters by position, as the assistant passes them.
$constructors = [
    'NeuronAI\Providers\Anthropic\Anthropic' => ['key', 'model', 'version', 'max_tokens', 'parameters'],
    'NeuronAI\Providers\OpenAI\OpenAI' => ['key', 'model', 'parameters'],
    'NeuronAI\Providers\Gemini\Gemini' => ['key', 'model', 'parameters'],
    'NeuronAI\Tools\ProviderTool' => ['type', 'name', 'options'],
    'NeuronAI\Providers\Mistral\Mistral' => ['key', 'model'],
    'NeuronAI\Providers\Deepseek\Deepseek' => ['key', 'model'],
    'NeuronAI\Providers\XAI\Grok' => ['key', 'model'],
    'NeuronAI\Providers\Cohere\Cohere' => ['key', 'model'],
    'NeuronAI\Providers\Ollama\Ollama' => ['url', 'model'],
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
];

$properties = [
    'NeuronAI\Workflow\WorkflowRunSnapshot' => ['interrupt'],
    'NeuronAI\Agent\Interrupt\Action' => ['id', 'name', 'inputs'],
    'NeuronAI\Chat\Messages\Usage' => ['inputTokens', 'outputTokens', 'reasoningTokens'],
    'NeuronAI\Chat\Messages\Citation' => ['source', 'title'],
    'NeuronAI\Chat\Messages\ContentBlocks\ImageContent' => ['content', 'sourceType', 'mediaType'],
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

foreach ($classes as $class) {
    if (!class_exists($class) && !enum_exists($class)) {
        $problems[] = $class . ' is gone';
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
