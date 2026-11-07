<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant\OpenAi;

use SensitiveParameter;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Domain\Assistant\LanguageModel;
use SeoControlCenter\Domain\Assistant\ModelReply;
use SeoControlCenter\Domain\Integrations\IntegrationException;

/**
 * GPT through the OpenAI Responses API (ADR-028 amendment). The assistant
 * keeps its history in the Claude message shape; this adapter translates
 * it to Responses input items (`message`, `function_call`,
 * `function_call_output`) and the output back to text and `tool_use`
 * blocks. Nothing is stored at OpenAI (`store: false`); function tools are
 * sent with `strict: false` because the MCP schemas are not strict-mode
 * schemas.
 *
 * Prices are USD per million tokens, from config/assistant.php; cached
 * input is billed at the model's `cached` price.
 */
final readonly class OpenAiModel implements LanguageModel
{
    public const string HOST = 'api.openai.com';
    public const string URL = 'https://api.openai.com/v1/responses';

    /**
     * @param array<string, array{label: string, input: float, output: float, cached: float}> $models
     */
    public function __construct(
        private HttpClient $http,
        private array $models,
    ) {}

    public function complete(#[SensitiveParameter] string $apiKey, string $model, string $system, array $messages, array $tools, int $maxTokens, ?string $workspace = null): ModelReply
    {
        $body = [
            'model' => $model,
            'instructions' => $system,
            'input' => self::input($messages),
            'max_output_tokens' => $maxTokens,
            'store' => false,
            'include' => ['reasoning.encrypted_content'],
        ];
        if ($tools !== []) {
            $body['tools'] = array_map(static fn(array $t): array => [
                'type' => 'function',
                'name' => $t['name'],
                'description' => $t['description'],
                'parameters' => $t['input_schema'],
                'strict' => false,
            ], $tools);
        }
        $data = $this->post($apiKey, $body);

        $content = [];
        $calls = false;
        foreach (is_array($data['output'] ?? null) ? $data['output'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            // Keep the original ordered items for stateless tool continuation.
            // Display blocks below are separate and must not be replayed twice.
            if (in_array($item['type'] ?? null, ['reasoning', 'message', 'function_call'], true)) {
                $content[] = ['type' => 'openai_item', 'item' => $item];
            }
            if (($item['type'] ?? null) === 'message') {
                foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $part) {
                    if (is_array($part) && ($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                        $content[] = ['type' => 'text', 'text' => $part['text']];
                    } elseif (is_array($part) && ($part['type'] ?? null) === 'refusal' && is_string($part['refusal'] ?? null)) {
                        $content[] = ['type' => 'text', 'text' => $part['refusal']];
                    }
                }
            } elseif (($item['type'] ?? null) === 'function_call' && is_string($item['call_id'] ?? null) && is_string($item['name'] ?? null)) {
                $input = json_decode(is_string($item['arguments'] ?? null) ? $item['arguments'] : '{}', true);
                $content[] = ['type' => 'tool_use', 'id' => $item['call_id'], 'name' => $item['name'], 'input' => is_array($input) ? $input : []];
                $calls = true;
            }
        }
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $input = is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : 0;
        $details = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];
        $cached = is_int($details['cached_tokens'] ?? null) ? min($input, $details['cached_tokens']) : 0;
        $written = is_int($details['cache_write_tokens'] ?? null) ? $details['cache_write_tokens'] : 0;

        return new ModelReply(
            $content,
            ($data['status'] ?? null) === 'incomplete' ? 'max_tokens' : ($calls ? 'tool_use' : 'end_turn'),
            $input - $cached - $written,
            is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : 0,
            $written,
            $cached,
        );
    }

    public function verify(#[SensitiveParameter] string $apiKey, string $model, ?string $workspace = null): void
    {
        $this->post($apiKey, ['model' => $model, 'input' => 'Hi', 'max_output_tokens' => 16, 'store' => false]);
    }

    public function cost(string $model, int $inputTokens, int $outputTokens, int $cacheWriteTokens = 0, int $cacheReadTokens = 0): float
    {
        $first = array_key_first($this->models);
        $price = $this->models[$model] ?? ($first !== null ? $this->models[$first] : ['input' => 0.0, 'output' => 0.0, 'cached' => 0.0]);

        return (($inputTokens + $cacheWriteTokens * 1.25) * $price['input'] + $cacheReadTokens * $price['cached'] + $outputTokens * $price['output']) / 1_000_000;
    }

    public function models(): array
    {
        return array_map(static fn(array $m): string => $m['label'], $this->models);
    }

    /**
     * Claude-shaped history to Responses input items.
     *
     * @param list<array{role: string, content: list<array<string, mixed>>}> $messages
     * @return list<array<string, mixed>>
     */
    public static function input(array $messages): array
    {
        $items = [];
        foreach ($messages as $message) {
            $hasOriginal = $message['role'] === 'assistant' && array_any($message['content'], static fn(array $b): bool => ($b['type'] ?? null) === 'openai_item');
            foreach ($message['content'] as $block) {
                $type = $block['type'] ?? null;
                if ($type === 'openai_item' && is_array($block['item'] ?? null)) {
                    $items[] = $block['item'];
                } elseif ($hasOriginal) {
                    continue;
                } elseif ($type === 'text' && is_string($block['text'] ?? null)) {
                    $items[] = ['role' => $message['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $block['text']];
                } elseif ($type === 'tool_use' && is_string($block['id'] ?? null) && is_string($block['name'] ?? null)) {
                    $input = $block['input'] ?? [];
                    $items[] = ['type' => 'function_call', 'call_id' => $block['id'], 'name' => $block['name'], 'arguments' => json_encode(is_array($input) && $input !== [] ? $input : new \stdClass(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
                } elseif ($type === 'tool_result' && is_string($block['tool_use_id'] ?? null)) {
                    $output = is_string($block['content'] ?? null) ? $block['content'] : '';
                    $items[] = ['type' => 'function_call_output', 'call_id' => $block['tool_use_id'], 'output' => ($block['is_error'] ?? false) === true ? 'Error: ' . $output : $output];
                }
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    private function post(#[SensitiveParameter] string $apiKey, array $body): array
    {
        try {
            $response = $this->http->send('POST', self::URL, [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ], json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [self::HOST], 2_000_000);
        } catch (HttpClientException $e) {
            throw new IntegrationException('OpenAI could not be reached: ' . $e->getMessage(), true, chargeUncertain: true);
        }
        $data = $response->json();
        if ($response->isSuccess()) {
            if ($data === null || $response->truncated || !is_array($data['output'] ?? null) || !array_is_list($data['output'])
                || !is_array($data['usage'] ?? null) || !is_int($data['usage']['input_tokens'] ?? null) || $data['usage']['input_tokens'] < 0
                || !is_int($data['usage']['output_tokens'] ?? null) || $data['usage']['output_tokens'] < 0) {
                throw new IntegrationException('OpenAI returned an unreadable or oversized response.', true, chargeUncertain: true);
            }
            $details = $data['usage']['input_tokens_details'] ?? null;
            if (is_array($details) && array_key_exists('cached_tokens', $details) && (!is_int($details['cached_tokens']) || $details['cached_tokens'] < 0 || $details['cached_tokens'] > $data['usage']['input_tokens'])) {
                throw new IntegrationException('OpenAI returned invalid token usage.', true, chargeUncertain: true);
            }
            if (is_array($details) && array_key_exists('cache_write_tokens', $details) && (!is_int($details['cache_write_tokens']) || $details['cache_write_tokens'] < 0 || $details['cache_write_tokens'] > $data['usage']['input_tokens'] - ($details['cached_tokens'] ?? 0))) {
                throw new IntegrationException('OpenAI returned invalid token usage.', true, chargeUncertain: true);
            }
            return $data;
        }
        $data ??= [];
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'HTTP ' . $response->status;
        $code = is_string($error['code'] ?? null) ? $error['code'] : '';

        throw match (true) {
            $response->status === 401, $response->status === 403 => new IntegrationException('OpenAI rejected the API key: ' . $message, false, true),
            $code === 'insufficient_quota' => new IntegrationException('The OpenAI account has no credit left: ' . $message, false),
            $response->status === 429 => new IntegrationException('OpenAI is rate limiting this key; please wait a moment. ' . $message, true, false, $response->retryAfterSeconds),
            $response->status >= 500 => new IntegrationException('OpenAI is temporarily unavailable: ' . $message, true, chargeUncertain: true),
            default => new IntegrationException('OpenAI refused the request: ' . $message, false),
        };
    }
}
