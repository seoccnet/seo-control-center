<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant\Anthropic;

use SensitiveParameter;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Domain\Assistant\LanguageModel;
use SeoControlCenter\Domain\Assistant\ModelReply;
use SeoControlCenter\Domain\Integrations\IntegrationException;

/**
 * Claude through the Anthropic Messages API (ADR-028). The system prompt
 * and the tool definitions are marked for prompt caching, so the repeated
 * calls of one answer (tool use loops) read them from the cache.
 *
 * Prices are USD per million tokens, from config/assistant.php; cache
 * writes cost 1.25× input; cache reads use the configured rate (default 0.1× input).
 */
final readonly class AnthropicModel implements LanguageModel
{
    public const string HOST = 'api.anthropic.com';
    public const string URL = 'https://api.anthropic.com/v1/messages';
    private const string VERSION = '2023-06-01';

    /**
     * @param array<string, array{label: string, input: float, output: float, cached?: float}> $models
     */
    public function __construct(
        private HttpClient $http,
        private array $models,
    ) {}

    public function complete(#[SensitiveParameter] string $apiKey, string $model, string $system, array $messages, array $tools, int $maxTokens, ?string $workspace = null): ModelReply
    {
        if ($tools !== []) {
            $tools[array_key_last($tools)]['cache_control'] = ['type' => 'ephemeral'];
        }
        // Stored history decodes `{}` as an empty array; the API wants tool inputs as JSON objects.
        foreach ($messages as $i => $message) {
            $messages[$i]['content'] = array_values(array_filter($message['content'], static fn(array $b): bool => ($b['type'] ?? null) !== 'openai_item'));
            $message = $messages[$i];
            foreach ($message['content'] as $j => $block) {
                if (($block['type'] ?? null) === 'tool_use' && ($block['input'] ?? null) === []) {
                    $messages[$i]['content'][$j]['input'] = new \stdClass();
                }
            }
        }
        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => $messages,
        ];
        if ($tools !== []) {
            $body['tools'] = $tools;
        }
        $data = $this->post($apiKey, $body, $workspace);
        $content = is_array($data['content'] ?? null) ? array_values(array_filter($data['content'], is_array(...))) : [];
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $int = static fn(string $key): int => is_int($usage[$key] ?? null) ? $usage[$key] : 0;

        /** @var list<array<string, mixed>> $content */
        return new ModelReply(
            $content,
            is_string($data['stop_reason'] ?? null) ? $data['stop_reason'] : 'end_turn',
            $int('input_tokens'),
            $int('output_tokens'),
            $int('cache_creation_input_tokens'),
            $int('cache_read_input_tokens'),
        );
    }

    public function verify(#[SensitiveParameter] string $apiKey, string $model, ?string $workspace = null): void
    {
        $this->post($apiKey, ['model' => $model, 'max_tokens' => 1, 'messages' => [['role' => 'user', 'content' => 'Hi']]], $workspace);
    }

    public function cost(string $model, int $inputTokens, int $outputTokens, int $cacheWriteTokens = 0, int $cacheReadTokens = 0): float
    {
        $first = array_key_first($this->models);
        $price = $this->models[$model] ?? ($first !== null ? $this->models[$first] : ['input' => 0.0, 'output' => 0.0]);

        return ($inputTokens * $price['input'] + $cacheWriteTokens * $price['input'] * 1.25 + $cacheReadTokens * ($price['cached'] ?? $price['input'] * 0.1) + $outputTokens * $price['output']) / 1_000_000;
    }

    public function models(): array
    {
        return array_map(static fn(array $m): string => $m['label'], $this->models);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    private function post(#[SensitiveParameter] string $apiKey, array $body, ?string $workspace = null): array
    {
        $headers = ['x-api-key' => $apiKey, 'anthropic-version' => self::VERSION, 'content-type' => 'application/json'];
        if ($workspace !== null) {
            // Keys that can act in several Claude workspaces must name one per request.
            $headers['anthropic-workspace-id'] = $workspace;
        }
        try {
            $response = $this->http->send('POST', self::URL, $headers, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [self::HOST], 2_000_000);
        } catch (HttpClientException $e) {
            throw new IntegrationException('Claude could not be reached: ' . $e->getMessage(), true, chargeUncertain: true);
        }
        $data = $response->json();
        if ($response->isSuccess()) {
            if ($data === null || $response->truncated || !is_array($data['content'] ?? null) || !array_is_list($data['content'])
                || !is_array($data['usage'] ?? null) || !is_int($data['usage']['input_tokens'] ?? null) || $data['usage']['input_tokens'] < 0
                || !is_int($data['usage']['output_tokens'] ?? null) || $data['usage']['output_tokens'] < 0) {
                throw new IntegrationException('Claude returned an unreadable or oversized response.', true, chargeUncertain: true);
            }
            foreach (['cache_creation_input_tokens', 'cache_read_input_tokens'] as $field) {
                if (array_key_exists($field, $data['usage']) && (!is_int($data['usage'][$field]) || $data['usage'][$field] < 0)) {
                    throw new IntegrationException('Claude returned invalid token usage.', true, chargeUncertain: true);
                }
            }
            return $data;
        }
        $data ??= [];
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'HTTP ' . $response->status;

        throw match (true) {
            // A key that can act in several Claude workspaces, sent without (or with a wrong) workspace ID.
            str_contains($message, 'anthropic-workspace-id') => new IntegrationException(
                $workspace === null
                    ? 'This Claude key works in several Claude workspaces, so it needs a workspace ID. Enter it under "Claude workspace ID" (Claude Console → Settings → Workspaces, it starts with wrkspc_).'
                    : 'Claude did not accept the workspace ID ' . $workspace . ' for this key. Check the ID in Claude Console → Settings → Workspaces and that the key has access to that workspace. ' . $message,
                false,
                true,
            ),
            $response->status === 401, $response->status === 403 => new IntegrationException('Claude rejected the API key: ' . $message, false, true),
            $response->status === 429 => new IntegrationException('Claude is rate limiting this key; please wait a moment. ' . $message, true, false, $response->retryAfterSeconds),
            $response->status >= 500 => new IntegrationException('Claude is temporarily unavailable: ' . $message, true, chargeUncertain: true),
            default => new IntegrationException('Claude refused the request: ' . $message, false),
        };
    }
}
