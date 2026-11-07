<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

/**
 * Whose work a quest check looks for: one user in one workspace, limited to
 * the projects that user can see. Dates are UTC (Y-m-d).
 */
final readonly class EvidenceScope
{
    /**
     * @param list<int> $projectIds projects the user can see in this workspace
     * @param string $weekStart Monday of the current ISO week
     */
    public function __construct(
        public int $workspaceId,
        public int $userId,
        public array $projectIds,
        public string $today,
        public string $weekStart,
    ) {}

    /** @param list<int> $projectIds */
    public static function at(int $workspaceId, int $userId, array $projectIds, \DateTimeImmutable $now): self
    {
        $day = $now->setTimezone(new \DateTimeZone('UTC'));

        return new self($workspaceId, $userId, array_values($projectIds), $day->format('Y-m-d'), $day->modify('monday this week')->format('Y-m-d'));
    }

    /** "?, ?, ?" for an IN list; callers must check hasProjects() first. */
    public function placeholders(): string
    {
        return implode(', ', array_fill(0, count($this->projectIds), '?'));
    }

    public function hasProjects(): bool
    {
        return $this->projectIds !== [];
    }

    /** ISO week key used to store a Weekly Check-in, e.g. "week-2026-W41". */
    public function weekKey(): string
    {
        return 'week-' . new \DateTimeImmutable($this->weekStart)->format('o-\WW');
    }
}
