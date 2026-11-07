<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant;

/**
 * A user's chat in a workspace. `pending` is a write Claude proposed that
 * waits for the user's confirmation, with the results of the other tool
 * calls of the same answer; `modelCalls` counts the calls since the last
 * user message.
 *
 * @phpstan-type Pending array{tool_use_id: string, tool: string, input: array<string, mixed>, description: string, results: list<array<string, mixed>>}
 */
final readonly class Conversation
{
    /**
     * @param Pending|null $pending
     */
    public function __construct(
        public int $id,
        public string $publicId,
        public int $workspaceId,
        public int $userId,
        public ?array $pending,
        public int $modelCalls,
        public string $updatedAt,
    ) {}

    /**
     * A stored pending action, or null when the data is not one.
     *
     * @param array<array-key, mixed> $data
     * @return Pending|null
     */
    public static function pendingFrom(array $data): ?array
    {
        if (!is_string($data['tool_use_id'] ?? null) || !is_string($data['tool'] ?? null) || !is_array($data['input'] ?? null)
            || !is_string($data['description'] ?? null) || !is_array($data['results'] ?? null) || !array_is_list($data['results'])) {
            return null;
        }
        /** @var array<string, mixed> $input */
        $input = $data['input'];
        /** @var list<array<string, mixed>> $results */
        $results = array_values(array_filter($data['results'], is_array(...)));

        return ['tool_use_id' => $data['tool_use_id'], 'tool' => $data['tool'], 'input' => $input, 'description' => $data['description'], 'results' => $results];
    }
}
