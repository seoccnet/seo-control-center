<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Assistant;

/**
 * One answer of the model: its content blocks, why it stopped (`end_turn`,
 * `tool_use`, `max_tokens`, …) and the tokens used.
 */
final readonly class ModelReply
{
    /**
     * @param list<array<string, mixed>> $content
     */
    public function __construct(
        public array $content,
        public string $stopReason,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheWriteTokens = 0,
        public int $cacheReadTokens = 0,
    ) {}

    /**
     * @return list<array{id: string, name: string, input: array<string, mixed>}>
     */
    public function toolCalls(): array
    {
        $calls = [];
        foreach ($this->content as $block) {
            if (($block['type'] ?? null) === 'tool_use' && is_string($block['id'] ?? null) && is_string($block['name'] ?? null)) {
                $input = is_array($block['input'] ?? null) ? $block['input'] : [];
                /** @var array<string, mixed> $input */
                $calls[] = ['id' => $block['id'], 'name' => $block['name'], 'input' => $input];
            }
        }

        return $calls;
    }
}
