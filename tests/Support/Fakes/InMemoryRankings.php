<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Rankings\PositionFilter;
use SeoControlCenter\Domain\Rankings\RankCheck;
use SeoControlCenter\Domain\Rankings\RankCheckScheduler;
use SeoControlCenter\Domain\Rankings\RankedKeyword;
use SeoControlCenter\Domain\Rankings\RankingDay;
use SeoControlCenter\Domain\Rankings\RankingMove;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Rankings\RankingSyncState;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;

/**
 * In-memory rankings built on InMemoryKeywords; records check requests
 * instead of queueing jobs. Projects are registered with their domain.
 */
final class InMemoryRankings implements RankingRepository, RankCheckScheduler
{
    /** @var array<int, RankingSettings> */
    public array $settings = [];

    /** @var array<int, array{workspace_id: int, domain: string}> */
    public array $projects = [];

    /** @var array<string, RankSnapshot> keyed by keyword id:device:date */
    public array $snapshots = [];

    /** @var array<string, RankingDay> keyed by project:device:date */
    public array $days = [];

    /** @var array<int, RankingSyncState> */
    public array $sync = [];

    /** @var list<array{project_id: int, workspace_id: int, date: string}> */
    public array $requests = [];

    public function __construct(
        private readonly InMemoryKeywords $keywords,
        private readonly Clock $clock,
    ) {}

    public function settings(int $projectId): RankingSettings
    {
        return $this->settings[$projectId] ?? RankingSettings::default();
    }

    public function positionBuckets(int $projectId, int $workspaceId, SerpDevice $device, string $date, ?string $cohortDate = null): array
    {
        $positions = [];
        foreach ($this->keywords->keywords[$projectId] ?? [] as $keyword) {
            $snapshot = $this->snapshots[$keyword['id'] . ':' . $device->value . ':' . $date] ?? null;
            $other = $cohortDate === null || isset($this->snapshots[$keyword['id'] . ':' . $device->value . ':' . $cohortDate]);
            if ($snapshot !== null && $snapshot->projectId === $projectId && $other) {
                $positions[] = $snapshot->position;
            }
        }
        return \SeoControlCenter\Domain\Rankings\PositionBuckets::count($positions);
    }

    public function saveSettings(int $projectId, RankingSettings $settings, int $userId): void
    {
        $this->settings[$projectId] = $settings;
    }

    public function dueProjects(string $today): array
    {
        $due = [];
        foreach ($this->settings as $projectId => $settings) {
            $last = $this->sync[$projectId]->lastCheckedOn ?? null;
            $threshold = (new \DateTimeImmutable($today))->modify(sprintf('-%d days', $settings->intervalDays()))->format('Y-m-d');
            if ($settings->enabled && isset($this->projects[$projectId]) && ($last === null || $last <= $threshold)) {
                $due[] = ['project_id' => $projectId, 'workspace_id' => $this->projects[$projectId]['workspace_id']];
            }
        }

        return $due;
    }

    public function trackedProjects(): array
    {
        $out = [];
        foreach ($this->settings as $projectId => $settings) {
            if ($settings->enabled && isset($this->projects[$projectId])) {
                $out[] = ['project_id' => (int) $projectId, 'workspace_id' => $this->projects[$projectId]['workspace_id']];
            }
        }

        return $out;
    }

    public function projectDomain(int $projectId, int $workspaceId): ?string
    {
        $project = $this->projects[$projectId] ?? null;

        return $project !== null && $project['workspace_id'] === $workspaceId ? $project['domain'] : null;
    }

    public function unchecked(int $projectId, SerpDevice $device, string $date, int $limit): array
    {
        $result = [];
        foreach ($this->keywords->keywords[$projectId] ?? [] as $row) {
            if (!isset($this->snapshots[$row['id'] . ':' . $device->value . ':' . $date])) {
                $result[] = ['id' => $row['id'], 'keyword' => $row['keyword'], 'locale' => $row['locale']];
            }
        }

        return array_slice($result, 0, $limit);
    }

    public function countUnchecked(int $projectId, SerpDevice $device, string $date): int
    {
        return count($this->unchecked($projectId, $device, $date, PHP_INT_MAX));
    }

    public function storeSnapshot(RankSnapshot $snapshot): void
    {
        $this->snapshots[$snapshot->projectKeywordId . ':' . $snapshot->device->value . ':' . $snapshot->checkedOn] = $snapshot;
    }

    public function aggregate(int $projectId, int $workspaceId, SerpDevice $device, string $date): void
    {
        $keywords = [];
        foreach ($this->snapshots as $s) {
            if ($s->projectId === $projectId && $s->device === $device && $s->checkedOn === $date) {
                $keywords[] = ['position' => $s->position, 'volume' => null];
            }
        }
        if ($keywords !== []) {
            $this->days[$projectId . ':' . $device->value . ':' . $date] = RankingDay::calculate($date, $keywords);
        }
    }

    public function days(int $projectId, SerpDevice $device, string $from, string $to): array
    {
        $days = array_values(array_filter(
            $this->daysOf($projectId, $device),
            static fn(RankingDay $d): bool => $d->date >= $from && $d->date <= $to,
        ));

        return $days;
    }

    public function latestDay(int $projectId, SerpDevice $device): ?RankingDay
    {
        return $this->dayOnOrBefore($projectId, $device, '9999-12-31');
    }

    public function dayOnOrBefore(int $projectId, SerpDevice $device, string $date): ?RankingDay
    {
        $found = null;
        foreach ($this->daysOf($projectId, $device) as $day) {
            if ($day->date <= $date) {
                $found = $day;
            }
        }

        return $found;
    }

    public function keywords(int $projectId, int $workspaceId, SerpDevice $device, int $limit, int $offset, ?PositionFilter $filter = null): array
    {
        $result = [];
        foreach ($this->keywords->keywords[$projectId] ?? [] as $row) {
            $history = array_values(array_filter($this->snapshots, static fn(RankSnapshot $s): bool => $s->projectKeywordId === $row['id'] && $s->device === $device));
            usort($history, static fn(RankSnapshot $a, RankSnapshot $b): int => strcmp($b->checkedOn, $a->checkedOn));
            $positions = array_values(array_filter(array_map(static fn(RankSnapshot $s): ?int => $s->position, $history), static fn(?int $p): bool => $p !== null));
            $result[] = new RankedKeyword(
                $row['public_id'],
                $row['keyword'],
                $row['locale'],
                $history[0]->checkedOn ?? null,
                $history[0]->position ?? null,
                $history[1]->position ?? null,
                $history === [] ? null : isset($history[1]),
                $positions === [] ? null : min($positions),
                $history[0]->url ?? null,
                $history[0]->features ?? [],
                null,
            );
        }

        if ($filter !== null) {
            $result = array_values(array_filter($result, static fn(RankedKeyword $k): bool => $k->checkedOn !== null && $filter->matches($k->position)));
        }

        return array_slice($result, $offset, $limit);
    }

    public function countKeywords(int $projectId, SerpDevice $device, ?PositionFilter $filter = null): int
    {
        return count($this->keywords($projectId, 0, $device, PHP_INT_MAX, 0, $filter));
    }

    public function history(int $projectId, string $keywordPublicId, SerpDevice $device, string $since): array
    {
        $checks = [];
        foreach ($this->keywords->keywords[$projectId] ?? [] as $row) {
            if ($row['public_id'] !== $keywordPublicId) {
                continue;
            }
            foreach ($this->snapshots as $s) {
                if ($s->projectKeywordId === $row['id'] && $s->device === $device && $s->checkedOn >= $since) {
                    $checks[] = new RankCheck($s->checkedOn, $s->position, $s->url, $s->features, $s->top);
                }
            }
        }
        usort($checks, static fn(RankCheck $a, RankCheck $b): int => strcmp($a->date, $b->date));

        return $checks;
    }

    public function comparableChecks(int $projectId, SerpDevice $device, string $date, int $maxGapDays): array
    {
        $since = (new \DateTimeImmutable($date))->modify(sprintf('-%d days', $maxGapDays))->format('Y-m-d');
        $rows = [];
        foreach ($this->keywords->keywords[$projectId] ?? [] as $kw) {
            $current = $this->snapshots[$kw['id'] . ':' . $device->value . ':' . $date] ?? null;
            if ($current === null) {
                continue;
            }
            $previous = null;
            foreach ($this->snapshots as $s) {
                if ($s->projectKeywordId === $kw['id'] && $s->device === $device && $s->checkedOn < $date && $s->checkedOn >= $since && ($previous === null || $s->checkedOn > $previous->checkedOn)) {
                    $previous = $s;
                }
            }
            $rows[] = [
                'keyword_id' => $kw['id'],
                'keyword_public_id' => $kw['public_id'],
                'keyword' => $kw['keyword'],
                'current' => new RankCheck($current->checkedOn, $current->position, $current->url, $current->features, $current->top),
                'previous' => $previous !== null ? new RankCheck($previous->checkedOn, $previous->position, $previous->url, $previous->features, $previous->top) : null,
            ];
        }

        return $rows;
    }

    public function checkDays(int $projectId, SerpDevice $device, string $since): array
    {
        $days = [];
        foreach ($this->snapshots as $s) {
            if ($s->projectId === $projectId && $s->device === $device && $s->checkedOn >= $since) {
                $days[$s->checkedOn] = true;
            }
        }
        ksort($days);

        return array_map('strval', array_keys($days));
    }

    public function moves(int $projectId, SerpDevice $device, string $from, string $to): array
    {
        $moves = [];
        foreach ($this->keywords->keywords[$projectId] ?? [] as $row) {
            $a = $this->snapshots[$row['id'] . ':' . $device->value . ':' . $from] ?? null;
            $b = $this->snapshots[$row['id'] . ':' . $device->value . ':' . $to] ?? null;
            if ($a !== null && $b !== null && $a->position !== $b->position) {
                $moves[] = new RankingMove($row['keyword'], $a->position, $b->position);
            }
        }

        return $moves;
    }

    public function syncState(int $projectId): ?RankingSyncState
    {
        return $this->sync[$projectId] ?? null;
    }

    public function setSyncState(int $projectId, string $status, ?string $error = null, ?string $checkedOn = null): void
    {
        $old = $this->sync[$projectId] ?? null;
        $this->sync[$projectId] = new RankingSyncState(
            $status,
            $status === RankingSyncState::ERROR ? $error : null,
            $checkedOn ?? $old?->lastCheckedOn,
            $status === RankingSyncState::OK ? $this->clock->now()->format('Y-m-d H:i:s') : $old?->lastSuccessAt,
        );
    }

    public function requestCheck(int $projectId, int $workspaceId, string $date): void
    {
        $this->requests[] = ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'date' => $date];
    }

    /**
     * @return list<RankingDay> oldest first
     */
    private function daysOf(int $projectId, SerpDevice $device): array
    {
        $days = [];
        foreach ($this->days as $key => $day) {
            if (str_starts_with($key, $projectId . ':' . $device->value . ':')) {
                $days[] = $day;
            }
        }
        usort($days, static fn(RankingDay $a, RankingDay $b): int => strcmp($a->date, $b->date));

        return $days;
    }
}
