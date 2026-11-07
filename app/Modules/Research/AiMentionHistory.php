<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research;

use SeoControlCenter\Domain\Research\AiMention;

/**
 * Whether a project checks AI assistant mentions every week, and the
 * mentions of each check day.
 */
interface AiMentionHistory
{
    public function isWeekly(int $projectId): bool;

    public function setWeekly(int $projectId, int $workspaceId, bool $weekly): void;

    /**
     * Projects with the weekly check on whose last check is on or before $before.
     *
     * @return list<array{projectId: int, workspaceId: int, projectPublicId: string}>
     */
    public function dueWeekly(string $before): array;

    /**
     * Stores the mentions of one check (replacing an earlier check of the same day).
     *
     * @param list<AiMention> $mentions
     */
    public function record(int $projectId, int $workspaceId, string $date, array $mentions): void;

    /**
     * The latest check days, newest first, with the mentions per domain and platform.
     *
     * @return list<array{date: string, mentions: array<string, array<string, int>>}> date => domain => platform => mentions
     */
    public function history(int $projectId, int $days): array;
}
