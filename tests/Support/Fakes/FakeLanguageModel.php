<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SensitiveParameter;
use SeoControlCenter\Domain\Assistant\LanguageModel;
use SeoControlCenter\Domain\Assistant\ModelReply;
use SeoControlCenter\Domain\Integrations\IntegrationException;

/**
 * Scripted Claude: answers with the queued replies in order and records each call.
 */
final class FakeLanguageModel implements LanguageModel
{
    /** @var list<ModelReply|IntegrationException> */
    public array $replies = [];

    /** @var list<array{key: string, workspace: ?string, model: string, system: string, messages: list<array{role: string, content: list<array<string, mixed>>}>, tools: list<string>}> */
    public array $calls = [];

    public ?IntegrationException $verifyFails = null;

    /**
     * @param array<string, string> $models
     */
    public function __construct(private readonly array $models = ['claude-sonnet-5-5' => 'Claude Sonnet 5.5', 'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5']) {}

    public function complete(#[SensitiveParameter] string $apiKey, string $model, string $system, array $messages, array $tools, int $maxTokens, ?string $workspace = null): ModelReply
    {
        $this->calls[] = ['key' => $apiKey, 'workspace' => $workspace, 'model' => $model, 'system' => $system, 'messages' => $messages, 'tools' => array_column($tools, 'name')];
        $reply = array_shift($this->replies) ?? self::text('(no scripted reply)');
        if ($reply instanceof IntegrationException) {
            throw $reply;
        }

        return $reply;
    }

    public function verify(#[SensitiveParameter] string $apiKey, string $model, ?string $workspace = null): void
    {
        if ($this->verifyFails !== null) {
            throw $this->verifyFails;
        }
    }

    public function cost(string $model, int $inputTokens, int $outputTokens, int $cacheWriteTokens = 0, int $cacheReadTokens = 0): float
    {
        return ($inputTokens * 3 + $cacheWriteTokens * 3.75 + $cacheReadTokens * 0.3 + $outputTokens * 15) / 1_000_000;
    }

    public function models(): array
    {
        return $this->models;
    }

    public static function text(string $text): ModelReply
    {
        return new ModelReply([['type' => 'text', 'text' => $text]], 'end_turn', 1000, 200);
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function tool(string $id, string $name, array $input, string $text = ''): ModelReply
    {
        $content = $text !== '' ? [['type' => 'text', 'text' => $text]] : [];
        $content[] = ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input];

        return new ModelReply($content, 'tool_use', 2000, 100, 500, 0);
    }
}
