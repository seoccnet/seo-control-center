<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant;

/**
 * Conversations are always looked up with their workspace and user: a
 * conversation is private to the user who started it.
 *
 * @phpstan-import-type Pending from Conversation
 */
interface ConversationRepository
{
    /** Expiring cross-request mutex; caller always releases in finally.
     * @phpstan-impure
     */
    public function lock(int $workspaceId, int $userId): ?\Closure;

    /**
     * The user's most recent conversation in the workspace.
     */
    public function latest(int $workspaceId, int $userId): ?Conversation;

    public function byPublicId(int $workspaceId, int $userId, string $publicId): ?Conversation;

    public function create(int $workspaceId, int $userId): Conversation;

    /**
     * @return list<array{role: string, content: list<array<string, mixed>>}> oldest first
     */
    public function messages(int $conversationId, int $limit): array;

    /**
     * @param list<array<string, mixed>> $content
     */
    public function append(int $conversationId, string $role, array $content, ?int $inputTokens = null, ?int $outputTokens = null): void;

    /**
     * @param Pending|null $pending
     */
    public function update(int $conversationId, ?array $pending, int $modelCalls): void;
}
