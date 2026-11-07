<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Assistant;

use SensitiveParameter;
use SeoControlCenter\Domain\Integrations\IntegrationException;

/**
 * A chat model with tool use (ADR-028). Messages and content blocks follow
 * the Claude Messages API shape: a message is
 * `{role: user|assistant, content: list<block>}` and a block is `text`,
 * `tool_use` or `tool_result`.
 */
interface LanguageModel
{
    /**
     * @param list<array{role: string, content: list<array<string, mixed>>}> $messages
     * @param list<array{name: string, description: string, input_schema: array<string, mixed>}> $tools
     *
     * @throws IntegrationException when the provider rejects the request or cannot be reached
     */
    public function complete(#[SensitiveParameter] string $apiKey, string $model, string $system, array $messages, array $tools, int $maxTokens, ?string $workspace = null): ModelReply;

    /**
     * Checks the key with a minimal request.
     *
     * $workspace names the provider workspace for keys that can act in several
     * (Claude: `anthropic-workspace-id`); providers without that concept ignore it.
     *
     * @throws IntegrationException
     */
    public function verify(#[SensitiveParameter] string $apiKey, string $model, ?string $workspace = null): void;

    /**
     * USD for the given token counts (cache reads and writes included).
     */
    public function cost(string $model, int $inputTokens, int $outputTokens, int $cacheWriteTokens = 0, int $cacheReadTokens = 0): float;

    /**
     * @return array<string, string> model id => label, the first is the default
     */
    public function models(): array;
}
