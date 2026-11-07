<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Modules\Research\AiMentionHistory;

/**
 * Weekly AI mention settings and history in memory.
 */
final class InMemoryAiMentionHistory implements AiMentionHistory
{
    /** @var array<int, array{workspaceId: int, weekly: bool, lastCheckedOn: ?string}> */
    public array $settings = [];

    /** @var array<int, array<string, array<string, array<string, int>>>> project => date => domain => platform => mentions */
    public array $checks = [];

    public function __construct(private readonly InMemoryDirectory $directory) {}

    public function isWeekly(int $projectId): bool
    {
        return $this->settings[$projectId]['weekly'] ?? false;
    }

    public function setWeekly(int $projectId, int $workspaceId, bool $weekly): void
    {
        $this->settings[$projectId] = ['workspaceId' => $workspaceId, 'weekly' => $weekly, 'lastCheckedOn' => $this->settings[$projectId]['lastCheckedOn'] ?? null];
    }

    public function dueWeekly(string $before): array
    {
        $rows = [];
        foreach ($this->settings as $projectId => $s) {
            $project = $this->directory->projects[$projectId] ?? null;
            if ($project !== null && $s['weekly'] && ($s['lastCheckedOn'] === null || $s['lastCheckedOn'] <= $before)) {
                $rows[] = ['projectId' => $projectId, 'workspaceId' => $s['workspaceId'], 'projectPublicId' => $project->publicId];
            }
        }

        return $rows;
    }

    public function record(int $projectId, int $workspaceId, string $date, array $mentions): void
    {
        $this->checks[$projectId][$date] = [];
        foreach ($mentions as $m) {
            $this->checks[$projectId][$date][$m->domain][$m->platform] = $m->mentions;
        }
        $this->settings[$projectId] = ['workspaceId' => $workspaceId, 'weekly' => $this->settings[$projectId]['weekly'] ?? false, 'lastCheckedOn' => $date];
    }

    public function history(int $projectId, int $days): array
    {
        $checks = $this->checks[$projectId] ?? [];
        krsort($checks);
        $rows = [];
        foreach (array_slice($checks, 0, $days, true) as $date => $mentions) {
            $rows[] = ['date' => (string) $date, 'mentions' => $mentions];
        }

        return $rows;
    }
}
