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
 * SomcpAgentProvider - the three AI providers, behind one shape.
 *
 * The assistant keeps its conversations in a shape of its own and translates
 * them for whichever provider answers the next turn. That is what lets a
 * merchant switch from Claude to Gemini in the middle of a conversation, and
 * what keeps the rest of the module ignorant of three different APIs.
 *
 * A turn, as stored:
 *   user       {"text": "..."}
 *   assistant  {"text": "...", "calls": [{"id", "name", "arguments"}], "provider", "raw"}
 *   tool       {"call_id", "name", "text", "is_error"}
 *
 * `raw` keeps what a provider needs to see again exactly as it sent it. Gemini
 * signs its reasoning on a function call and refuses the next turn if the
 * signature does not come back; it is only replayed to the provider that
 * produced it.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpAgentProvider
{
    /** @var string Version header Anthropic requires on every call. */
    private const ANTHROPIC_VERSION = '2023-06-01';

    /** @var int Longest answer asked of a model, in tokens. */
    private const MAX_TOKENS = 8192;

    /**
     * The newest models a key gives access to, newest first.
     *
     * @param string $provider anthropic, openai or google
     * @param string $apiKey
     *
     * @return array{models: array<int, array{id:string, label:string}>, error:string}
     */
    public static function listModels(string $provider, string $apiKey): array
    {
        switch ($provider) {
            case 'anthropic':
                $answer = SomcpHttp::api('https://api.anthropic.com/v1/models?limit=100', self::anthropicHeaders($apiKey));
                $data = self::decoded($answer);
                if (!is_array($data)) {
                    return ['models' => [], 'error' => self::failure($answer)];
                }
                // Already sorted newest first by the API.
                $models = [];
                foreach ((array) ($data['data'] ?? []) as $model) {
                    if (is_array($model) && is_string($model['id'] ?? null)) {
                        $models[] = ['id' => $model['id'], 'label' => (string) ($model['display_name'] ?? $model['id'])];
                    }
                }

                return ['models' => $models, 'error' => ''];

            case 'openai':
                $answer = SomcpHttp::api('https://api.openai.com/v1/models', ['Authorization: Bearer ' . $apiKey]);
                $data = self::decoded($answer);
                if (!is_array($data)) {
                    return ['models' => [], 'error' => self::failure($answer)];
                }
                $models = [];
                foreach ((array) ($data['data'] ?? []) as $model) {
                    if (is_array($model) && is_string($model['id'] ?? null) && self::isOpenAiChatModel($model['id'])) {
                        $models[] = ['id' => $model['id'], 'label' => $model['id'], 'created' => (int) ($model['created'] ?? 0)];
                    }
                }
                // The list comes unsorted, and holds every dated snapshot of
                // every model: newest first, and the dated copies of a model
                // already listed under its own name left out.
                usort($models, static function (array $a, array $b): int {
                    return $b['created'] <=> $a['created'];
                });
                $names = array_column($models, 'id');
                $models = array_values(array_filter($models, static function (array $model) use ($names): bool {
                    $base = (string) preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $model['id']);

                    return $base === $model['id'] || !in_array($base, $names, true);
                }));

                return [
                    'models' => array_map(static function (array $model): array {
                        return ['id' => $model['id'], 'label' => $model['label']];
                    }, $models),
                    'error' => '',
                ];

            case 'google':
                $answer = SomcpHttp::api(
                    'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000',
                    ['x-goog-api-key: ' . $apiKey]
                );
                $data = self::decoded($answer);
                if (!is_array($data)) {
                    return ['models' => [], 'error' => self::failure($answer)];
                }
                $models = [];
                foreach ((array) ($data['models'] ?? []) as $model) {
                    if (!is_array($model)) {
                        continue;
                    }
                    $id = (string) preg_replace('#^models/#', '', (string) ($model['name'] ?? ''));
                    $methods = (array) ($model['supportedGenerationMethods'] ?? []);
                    if (self::isGeminiChatModel($id) && in_array('generateContent', $methods, true)) {
                        $models[] = ['id' => $id, 'label' => (string) ($model['displayName'] ?? $id)];
                    }
                }
                // No date in this list: the version number in the name is the
                // only order there is. Highest version first, then the stable
                // names before the previews of the same version.
                usort($models, static function (array $a, array $b): int {
                    $version = version_compare(self::geminiVersion($b['id']), self::geminiVersion($a['id']));
                    if ($version !== 0) {
                        return $version;
                    }

                    return self::geminiRank($a['id']) <=> self::geminiRank($b['id']);
                });

                return ['models' => $models, 'error' => ''];
        }

        return ['models' => [], 'error' => 'unknown provider'];
    }

    /**
     * Whether an OpenAI model id is one that holds a conversation with tools.
     *
     * The list also carries embeddings, speech, transcription, image and
     * moderation models, which the chat endpoint refuses.
     *
     * @param string $id
     *
     * @return bool
     */
    private static function isOpenAiChatModel(string $id): bool
    {
        if (preg_match('/^(gpt-\d|o\d|chatgpt-)/', $id) !== 1) {
            return false;
        }

        return preg_match('/(audio|realtime|transcribe|tts|image|search|embedding|moderation|instruct|codex|-pro\b)/', $id) !== 1;
    }

    /**
     * @param string $id
     *
     * @return bool
     */
    private static function isGeminiChatModel(string $id): bool
    {
        return preg_match('/^gemini-\d/', $id) === 1
            && preg_match('/(embedding|image|tts|audio|live|aqa|vision|thinking-exp|computer-use)/', $id) !== 1;
    }

    /**
     * @param string $id
     *
     * @return string
     */
    private static function geminiVersion(string $id): string
    {
        return preg_match('/^gemini-(\d+(?:\.\d+)?)/', $id, $m) === 1 ? $m[1] : '0';
    }

    /**
     * Within one version: pro, then flash, then the rest; stable before preview.
     *
     * @param string $id
     *
     * @return int
     */
    private static function geminiRank(string $id): int
    {
        $rank = strpos($id, '-pro') !== false ? 0 : (strpos($id, '-flash-lite') !== false ? 20 : (strpos($id, '-flash') !== false ? 10 : 30));

        return $rank + (preg_match('/(preview|exp|latest|\d{3,})/', $id) === 1 ? 5 : 0);
    }

    /**
     * Sends a conversation to a model and returns its next turn.
     *
     * @param string $provider
     * @param string $model
     * @param string $apiKey
     * @param string $system instructions for the whole conversation
     * @param array<int, array{role:string, content:array<string, mixed>}> $turns stored turns, oldest first
     * @param array<int, array{name:string, description:string, schema:array<string, mixed>}> $tools
     *
     * @return array{text:string, calls:array<int, array{id:string, name:string, arguments:array<string, mixed>}>, raw:array<int, mixed>, error:string}
     */
    public static function reply(string $provider, string $model, string $apiKey, string $system, array $turns, array $tools): array
    {
        switch ($provider) {
            case 'anthropic':
                return self::anthropicReply($model, $apiKey, $system, $turns, $tools);
            case 'openai':
                return self::openAiReply($model, $apiKey, $system, $turns, $tools);
            case 'google':
                return self::geminiReply($model, $apiKey, $system, $turns, $tools);
        }

        return self::error('unknown provider');
    }

    /**
     * @param string $model
     * @param string $apiKey
     * @param string $system
     * @param array<int, array{role:string, content:array<string, mixed>}> $turns
     * @param array<int, array{name:string, description:string, schema:array<string, mixed>}> $tools
     *
     * @return array{text:string, calls:array<int, array{id:string, name:string, arguments:array<string, mixed>}>, raw:array<int, mixed>, error:string}
     */
    private static function anthropicReply(string $model, string $apiKey, string $system, array $turns, array $tools): array
    {
        $messages = [];
        foreach ($turns as $turn) {
            $content = $turn['content'];
            if ($turn['role'] === 'user') {
                $messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => (string) ($content['text'] ?? '')]]];
            } elseif ($turn['role'] === 'assistant') {
                $blocks = [];
                if ((string) ($content['text'] ?? '') !== '') {
                    $blocks[] = ['type' => 'text', 'text' => (string) $content['text']];
                }
                foreach (self::calls($content) as $call) {
                    $blocks[] = ['type' => 'tool_use', 'id' => $call['id'], 'name' => $call['name'], 'input' => self::object($call['arguments'])];
                }
                if ($blocks !== []) {
                    $messages[] = ['role' => 'assistant', 'content' => $blocks];
                }
            } else {
                // Every result answering one assistant turn goes in a single
                // user message, right after it: Claude refuses anything else.
                $block = [
                    'type' => 'tool_result',
                    'tool_use_id' => (string) ($content['call_id'] ?? ''),
                    'content' => (string) ($content['text'] ?? ''),
                    'is_error' => ($content['is_error'] ?? false) === true,
                ];
                $last = count($messages) - 1;
                if ($last >= 0 && $messages[$last]['role'] === 'user' && ($messages[$last]['content'][0]['type'] ?? '') === 'tool_result') {
                    $messages[$last]['content'][] = $block;
                } else {
                    $messages[] = ['role' => 'user', 'content' => [$block]];
                }
            }
        }

        $body = [
            'model' => $model,
            'max_tokens' => self::MAX_TOKENS,
            'system' => $system,
            'messages' => $messages,
        ];
        if ($tools !== []) {
            $body['tools'] = array_map(static function (array $tool): array {
                return ['name' => $tool['name'], 'description' => $tool['description'], 'input_schema' => $tool['schema']];
            }, $tools);
        }

        $answer = SomcpHttp::api('https://api.anthropic.com/v1/messages', self::anthropicHeaders($apiKey), self::encode($body));
        $data = self::decoded($answer);
        if (!is_array($data)) {
            return self::error(self::failure($answer));
        }

        $text = '';
        $calls = [];
        foreach ((array) ($data['content'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $calls[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'name' => (string) ($block['name'] ?? ''),
                    'arguments' => is_array($block['input'] ?? null) ? $block['input'] : [],
                ];
            }
        }

        return ['text' => $text, 'calls' => $calls, 'raw' => [], 'error' => ''];
    }

    /**
     * @param string $model
     * @param string $apiKey
     * @param string $system
     * @param array<int, array{role:string, content:array<string, mixed>}> $turns
     * @param array<int, array{name:string, description:string, schema:array<string, mixed>}> $tools
     *
     * @return array{text:string, calls:array<int, array{id:string, name:string, arguments:array<string, mixed>}>, raw:array<int, mixed>, error:string}
     */
    private static function openAiReply(string $model, string $apiKey, string $system, array $turns, array $tools): array
    {
        // "developer" rather than "system": the reasoning models only accept
        // the former, and the others read it the same way.
        $messages = [['role' => 'developer', 'content' => $system]];
        foreach ($turns as $turn) {
            $content = $turn['content'];
            if ($turn['role'] === 'user') {
                $messages[] = ['role' => 'user', 'content' => (string) ($content['text'] ?? '')];
            } elseif ($turn['role'] === 'assistant') {
                $message = ['role' => 'assistant', 'content' => (string) ($content['text'] ?? '')];
                $calls = self::calls($content);
                if ($calls !== []) {
                    $message['tool_calls'] = array_map(static function (array $call): array {
                        return [
                            'id' => $call['id'],
                            'type' => 'function',
                            'function' => ['name' => $call['name'], 'arguments' => self::encode(self::object($call['arguments']))],
                        ];
                    }, $calls);
                }
                $messages[] = $message;
            } else {
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($content['call_id'] ?? ''),
                    'content' => (string) ($content['text'] ?? ''),
                ];
            }
        }

        $body = ['model' => $model, 'messages' => $messages];
        if ($tools !== []) {
            $body['tools'] = array_map(static function (array $tool): array {
                return ['type' => 'function', 'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['schema'],
                ]];
            }, $tools);
        }

        $answer = SomcpHttp::api('https://api.openai.com/v1/chat/completions', ['Authorization: Bearer ' . $apiKey], self::encode($body));
        $data = self::decoded($answer);
        if (!is_array($data)) {
            return self::error(self::failure($answer));
        }

        $message = is_array($data['choices'][0]['message'] ?? null) ? $data['choices'][0]['message'] : [];
        $calls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            if (!is_array($call)) {
                continue;
            }
            $arguments = json_decode((string) ($call['function']['arguments'] ?? ''), true);
            $calls[] = [
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) ($call['function']['name'] ?? ''),
                'arguments' => is_array($arguments) ? $arguments : [],
            ];
        }

        return ['text' => (string) ($message['content'] ?? ''), 'calls' => $calls, 'raw' => [], 'error' => ''];
    }

    /**
     * @param string $model
     * @param string $apiKey
     * @param string $system
     * @param array<int, array{role:string, content:array<string, mixed>}> $turns
     * @param array<int, array{name:string, description:string, schema:array<string, mixed>}> $tools
     *
     * @return array{text:string, calls:array<int, array{id:string, name:string, arguments:array<string, mixed>}>, raw:array<int, mixed>, error:string}
     */
    private static function geminiReply(string $model, string $apiKey, string $system, array $turns, array $tools): array
    {
        $contents = [];
        foreach ($turns as $turn) {
            $content = $turn['content'];
            if ($turn['role'] === 'user') {
                $contents[] = ['role' => 'user', 'parts' => [['text' => (string) ($content['text'] ?? '')]]];
            } elseif ($turn['role'] === 'assistant') {
                // Gemini's own parts when it wrote this turn, so the signature
                // it put on its reasoning comes back untouched.
                if (($content['provider'] ?? '') === 'google' && is_array($content['raw'] ?? null) && $content['raw'] !== []) {
                    $contents[] = ['role' => 'model', 'parts' => $content['raw']];

                    continue;
                }
                $parts = [];
                if ((string) ($content['text'] ?? '') !== '') {
                    $parts[] = ['text' => (string) $content['text']];
                }
                foreach (self::calls($content) as $call) {
                    $parts[] = ['functionCall' => ['name' => $call['name'], 'args' => self::object($call['arguments'])]];
                }
                if ($parts !== []) {
                    $contents[] = ['role' => 'model', 'parts' => $parts];
                }
            } else {
                $part = ['functionResponse' => [
                    'name' => (string) ($content['name'] ?? ''),
                    'response' => [($content['is_error'] ?? false) === true ? 'error' : 'result' => (string) ($content['text'] ?? '')],
                ]];
                $last = count($contents) - 1;
                if ($last >= 0 && $contents[$last]['role'] === 'user' && isset($contents[$last]['parts'][0]['functionResponse'])) {
                    $contents[$last]['parts'][] = $part;
                } else {
                    $contents[] = ['role' => 'user', 'parts' => [$part]];
                }
            }
        }

        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => $contents,
        ];
        if ($tools !== []) {
            $body['tools'] = [['functionDeclarations' => array_map(static function (array $tool): array {
                return ['name' => $tool['name'], 'description' => $tool['description'], 'parameters' => self::geminiSchema($tool['schema'])];
            }, $tools)]];
        }

        $answer = SomcpHttp::api(
            'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
            ['x-goog-api-key: ' . $apiKey],
            self::encode($body)
        );
        $data = self::decoded($answer);
        if (!is_array($data)) {
            return self::error(self::failure($answer));
        }

        $parts = is_array($data['candidates'][0]['content']['parts'] ?? null) ? $data['candidates'][0]['content']['parts'] : [];
        $text = '';
        $calls = [];
        foreach ($parts as $index => $part) {
            if (!is_array($part)) {
                continue;
            }
            if (isset($part['text']) && ($part['thought'] ?? false) !== true) {
                $text .= (string) $part['text'];
            }
            if (is_array($part['functionCall'] ?? null)) {
                // Gemini gives no identifier to a call; one is made up so a
                // result can be matched to it like with the other providers.
                $calls[] = [
                    'id' => 'call_' . $index . '_' . bin2hex(random_bytes(4)),
                    'name' => (string) ($part['functionCall']['name'] ?? ''),
                    'arguments' => is_array($part['functionCall']['args'] ?? null) ? $part['functionCall']['args'] : [],
                ];
            }
        }

        return ['text' => $text, 'calls' => $calls, 'raw' => $parts, 'error' => ''];
    }

    /**
     * A JSON schema reduced to what Gemini's function declarations accept.
     *
     * Gemini reads an OpenAPI subset and refuses the whole request over a key
     * it does not know, such as additionalProperties or $schema.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function geminiSchema(array $schema): array
    {
        $kept = [];
        foreach (['type', 'description', 'enum', 'format', 'nullable', 'minimum', 'maximum', 'minItems', 'maxItems'] as $key) {
            if (array_key_exists($key, $schema)) {
                $kept[$key] = $schema[$key];
            }
        }
        if (is_array($kept['type'] ?? null)) {
            // ["string", "null"] becomes a nullable string.
            $types = array_values(array_diff($kept['type'], ['null']));
            $kept['nullable'] = count($types) !== count($kept['type']);
            $kept['type'] = $types[0] ?? 'string';
        }

        $properties = $schema['properties'] ?? null;
        if (is_array($properties) && $properties !== []) {
            $kept['properties'] = [];
            foreach ($properties as $name => $property) {
                $kept['properties'][(string) $name] = is_array($property) ? self::geminiSchema($property) : ['type' => 'string'];
            }
            if (is_array($schema['required'] ?? null) && $schema['required'] !== []) {
                $kept['required'] = array_values(array_intersect($schema['required'], array_keys($kept['properties'])));
            }
        } elseif (($kept['type'] ?? '') === 'object') {
            // An object with no properties is refused; none is what it means.
            unset($kept['type']);
        }

        if (is_array($schema['items'] ?? null)) {
            $kept['items'] = self::geminiSchema($schema['items']);
        } elseif (($kept['type'] ?? '') === 'array') {
            $kept['items'] = ['type' => 'string'];
        }

        if (!isset($kept['type']) && !isset($kept['properties'])) {
            $kept['type'] = 'object';
            $kept['properties'] = ['note' => ['type' => 'string']];
        }

        return $kept;
    }

    /**
     * @param string $apiKey
     *
     * @return array<int, string>
     */
    private static function anthropicHeaders(string $apiKey): array
    {
        return ['x-api-key: ' . $apiKey, 'anthropic-version: ' . self::ANTHROPIC_VERSION];
    }

    /**
     * The calls of a stored assistant turn.
     *
     * @param array<string, mixed> $content
     *
     * @return array<int, array{id:string, name:string, arguments:array<string, mixed>}>
     */
    private static function calls(array $content): array
    {
        $calls = [];
        foreach ((array) ($content['calls'] ?? []) as $call) {
            if (is_array($call) && is_string($call['name'] ?? null)) {
                $calls[] = [
                    'id' => (string) ($call['id'] ?? ''),
                    'name' => $call['name'],
                    'arguments' => is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
                ];
            }
        }

        return $calls;
    }

    /**
     * Arguments as a JSON object, even when there are none.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>|stdClass
     */
    private static function object(array $arguments)
    {
        return $arguments === [] ? new stdClass() : $arguments;
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    private static function encode($value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * The decoded body of a successful answer.
     *
     * @param array{status:int, body:string, headers:array<string,string>, error:string} $answer
     *
     * @return array<string, mixed>|null null when the call failed
     */
    private static function decoded(array $answer): ?array
    {
        if ($answer['status'] < 200 || $answer['status'] >= 300) {
            return null;
        }
        $data = json_decode($answer['body'], true);

        return is_array($data) ? $data : null;
    }

    /**
     * What went wrong, in the provider's own words when it gave any.
     *
     * The message is shown to the employee, who is the one able to fix a wrong
     * key or an empty balance. The key itself never appears in it: providers
     * quote at most its last characters.
     *
     * @param array{status:int, body:string, headers:array<string,string>, error:string} $answer
     *
     * @return string
     */
    private static function failure(array $answer): string
    {
        if ($answer['status'] === 0) {
            return 'unreachable (' . ($answer['error'] !== '' ? $answer['error'] : 'no answer') . ')';
        }

        $data = json_decode($answer['body'], true);
        $message = '';
        if (is_array($data)) {
            $error = $data['error'] ?? null;
            if (is_array($error)) {
                $message = (string) ($error['message'] ?? '');
            } elseif (is_array($data[0]['error'] ?? null)) {
                $message = (string) ($data[0]['error']['message'] ?? '');
            }
        }

        return 'HTTP ' . $answer['status'] . ($message !== '' ? ': ' . Tools::substr($message, 0, 300) : '');
    }

    /**
     * @param string $message
     *
     * @return array{text:string, calls:array<int, array{id:string, name:string, arguments:array<string, mixed>}>, raw:array<int, mixed>, error:string}
     */
    private static function error(string $message): array
    {
        return ['text' => '', 'calls' => [], 'raw' => [], 'error' => $message];
    }
}
