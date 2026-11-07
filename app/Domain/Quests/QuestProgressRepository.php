<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

/**
 * Quest Log progress per user per workspace. Quest definitions are code;
 * only completions, skips, earned ranks and preferences are stored.
 * Completions are sticky: once recorded they are never removed.
 */
interface QuestProgressRepository
{
    public function profile(int $userId, int $workspaceId): QuestProfile;

    public function setHidden(int $userId, int $workspaceId, bool $hidden): void;

    /** @param list<string> $questKeys */
    public function markSeen(int $userId, int $workspaceId, array $questKeys): void;

    public function markChecked(int $userId, int $workspaceId, string $at): void;

    /**
     * @return array<string, array<string, array{completed: ?string, skipped: ?string}>> quest key => step key ('' = the quest itself)
     */
    public function progress(int $userId, int $workspaceId): array;

    /** Idempotent; keeps the first completion time. */
    public function complete(int $userId, int $workspaceId, string $questKey, string $stepKey, string $at): void;

    public function skip(int $userId, int $workspaceId, string $questKey, string $at): void;

    public function unskip(int $userId, int $workspaceId, string $questKey): void;

    /** @return array<int, array{earned: string, announced: bool}> chapter number => rank */
    public function ranks(int $userId, int $workspaceId): array;

    /** Idempotent; an earned rank is never removed. */
    public function earnRank(int $userId, int $workspaceId, int $chapter, string $at): void;

    /** @param list<int> $chapters */
    public function announceRanks(int $userId, int $workspaceId, array $chapters, string $at): void;

    /**
     * Profiles whose learning quests were checked least recently, for the cron re-check.
     *
     * @return list<array{user_id: int, workspace_id: int}>
     */
    public function due(string $before, int $limit): array;
}
