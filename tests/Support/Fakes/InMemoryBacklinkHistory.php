<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Modules\Backlinks\BacklinkHistory;
use SeoControlCenter\Modules\Backlinks\BacklinksScheduler;

/**
 * Monthly link profile settings, history and queued lookups in memory.
 */
final class InMemoryBacklinkHistory implements BacklinkHistory, BacklinksScheduler
{
    /** @var list<array{projectId: int, workspaceId: int, kind: string}> */
    public array $queued = [];

    /** @var array<int, array{workspaceId: int, monthly: bool, lastCheckedOn: ?string}> */
    public array $settings = [];

    /** @var array<int, array<string, array<string, BacklinkProfile>>> project => date => domain => profile */
    public array $checks = [];

    /** @var array<int, bool> */
    public array $lostMonitoring = [];
    /** @var array<int, string> */
    public array $lostChecks = [];

    public function monitorsLost(int $projectId): bool
    {
        return $this->lostMonitoring[$projectId] ?? false;
    }
    public function setLostMonitoring(int $projectId, int $workspaceId, bool $enabled): void
    {
        $this->lostMonitoring[$projectId] = $enabled;
    }
    public function recordLostCheck(int $projectId, string $date): void
    {
        $this->lostChecks[$projectId] = $date;
    }
    public function dueLost(string $before): array
    {
        $rows = [];
        foreach ($this->lostMonitoring as $projectId => $enabled) {
            $project = $this->directory->projects[$projectId] ?? null;
            if ($enabled && $project !== null && (!isset($this->lostChecks[$projectId]) || $this->lostChecks[$projectId] <= $before)) {
                $rows[] = ['projectId' => $projectId, 'workspaceId' => $project->workspaceId, 'projectPublicId' => $project->publicId];
            }
        }
        return $rows;
    }

    public function __construct(private readonly InMemoryDirectory $directory) {}

    public function fetch(int $projectId, int $workspaceId, string $kind): void
    {
        $this->queued[] = ['projectId' => $projectId, 'workspaceId' => $workspaceId, 'kind' => $kind];
    }

    public function isMonthly(int $projectId): bool
    {
        return $this->settings[$projectId]['monthly'] ?? false;
    }

    public function setMonthly(int $projectId, int $workspaceId, bool $monthly): void
    {
        $this->settings[$projectId] = ['workspaceId' => $workspaceId, 'monthly' => $monthly, 'lastCheckedOn' => $this->settings[$projectId]['lastCheckedOn'] ?? null];
    }

    public function dueMonthly(string $before): array
    {
        $rows = [];
        foreach ($this->settings as $projectId => $s) {
            $project = $this->directory->projects[$projectId] ?? null;
            if ($project !== null && $s['monthly'] && ($s['lastCheckedOn'] === null || $s['lastCheckedOn'] <= $before)) {
                $rows[] = ['projectId' => $projectId, 'workspaceId' => $s['workspaceId'], 'projectPublicId' => $project->publicId];
            }
        }

        return $rows;
    }

    public function record(int $projectId, int $workspaceId, string $date, array $profiles): void
    {
        $this->checks[$projectId][$date] = [];
        foreach ($profiles as $p) {
            $this->checks[$projectId][$date][$p->domain] = $p;
        }
        $this->settings[$projectId] = ['workspaceId' => $workspaceId, 'monthly' => $this->settings[$projectId]['monthly'] ?? false, 'lastCheckedOn' => $date];
    }

    public function history(int $projectId, int $days): array
    {
        $checks = $this->checks[$projectId] ?? [];
        krsort($checks);
        $rows = [];
        foreach (array_slice($checks, 0, $days, true) as $date => $profiles) {
            $rows[] = ['date' => (string) $date, 'profiles' => $profiles];
        }

        return $rows;
    }
}
