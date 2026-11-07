<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Local\BusinessProfile;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Domain\Local\LocalGrid;
use SeoControlCenter\Domain\Local\LocalResult;
use SeoControlCenter\Modules\Local\LocalRepository;
use SeoControlCenter\Modules\Local\LocalScheduler;
use SeoControlCenter\Modules\Local\LocalSettings;

/**
 * Local SEO settings, keywords, positions, profiles and queued checks in memory.
 */
final class InMemoryLocal implements LocalRepository, LocalScheduler
{
    /** @var array<int, LocalSettings> */
    public array $settings = [];

    /** @var array<int, array<int, string>> project => keyword id => keyword */
    public array $keywords = [];

    /** @var array<string, array{projectId: int, position: ?int, top: list<LocalResult>}> keyword id:date */
    public array $rankings = [];

    /** @var array<int, array<string, BusinessProfile>> project => date => profile */
    public array $profiles = [];

    /** @var list<array{projectId: int, workspaceId: int}> */
    public array $queued = [];

    /** @var list<array{projectId: int, workspaceId: int}> */
    public array $queuedGrids = [];

    /** @var array<int, array{projectId: int, grid: LocalGrid}> */
    public array $grids = [];

    private int $nextId = 1;

    public function __construct(private readonly InMemoryDirectory $directory) {}

    public function check(int $projectId, int $workspaceId): void
    {
        $this->queued[] = ['projectId' => $projectId, 'workspaceId' => $workspaceId];
    }

    public function grid(int $projectId, int $workspaceId): void
    {
        $this->queuedGrids[] = ['projectId' => $projectId, 'workspaceId' => $workspaceId];
    }

    public function createGrid(int $projectId, int $keywordId, string $date, int $size, int $spacing, LocalArea $center): void
    {
        $this->grids[$this->nextId++] = ['projectId' => $projectId, 'grid' => new LocalGrid($keywordId, $date, $size, $spacing, $center, [], 'queued')];
    }

    public function queuedGrid(int $projectId): ?array
    {
        foreach ($this->grids as $id => $row) {
            if ($row['projectId'] === $projectId && $row['grid']->status === 'queued') {
                return ['id' => $id, 'keyword' => $this->keywords[$projectId][$row['grid']->keywordId] ?? '', 'grid' => $row['grid']];
            }
        }

        return null;
    }

    public function saveGridPoint(int $gridId, int $index, ?int $position): void
    {
        $g = $this->grids[$gridId]['grid'];
        $positions = $g->positions;
        $positions[$index] = $position;
        ksort($positions);
        $this->grids[$gridId]['grid'] = new LocalGrid($g->keywordId, $g->checkedOn, $g->size, $g->spacing, $g->center, $positions, $g->status);
    }

    public function finishGrid(int $gridId, string $status, ?string $error = null): void
    {
        $g = $this->grids[$gridId]['grid'];
        $this->grids[$gridId]['grid'] = new LocalGrid($g->keywordId, $g->checkedOn, $g->size, $g->spacing, $g->center, $g->positions, $status);
    }

    public function latestGrids(int $projectId): array
    {
        $grids = [];
        foreach ($this->grids as $row) {
            if ($row['projectId'] === $projectId) {
                $grids[$row['grid']->keywordId] = $row['grid'];
            }
        }

        return $grids;
    }

    public function settings(int $projectId): ?LocalSettings
    {
        return $this->settings[$projectId] ?? null;
    }

    public function projectDomain(int $projectId): ?string
    {
        return ($this->directory->projects[$projectId] ?? null)?->primaryDomain;
    }

    public function saveSettings(int $projectId, int $workspaceId, string $businessName, LocalArea $area, string $language, bool $weekly, int $userId): void
    {
        $old = $this->settings[$projectId] ?? null;
        $cid = $old !== null && $old->businessName === $businessName ? $old->cid : null;
        $this->settings[$projectId] = new LocalSettings($projectId, $workspaceId, $businessName, $area, $language, $weekly, $cid, $old->status ?? 'idle', $old?->lastError, $old?->lastCheckedOn);
    }

    public function setStatus(int $projectId, string $status, ?string $error = null): void
    {
        $this->with($projectId, status: $status, error: $error);
    }

    public function setCid(int $projectId, string $cid): void
    {
        $this->with($projectId, cid: $cid);
    }

    public function markChecked(int $projectId, string $date): void
    {
        $this->with($projectId, status: 'idle', checkedOn: $date);
    }

    public function keywords(int $projectId): array
    {
        $rows = [];
        foreach ($this->keywords[$projectId] ?? [] as $id => $keyword) {
            $rows[] = ['id' => $id, 'keyword' => $keyword];
        }
        usort($rows, static fn(array $a, array $b): int => $a['keyword'] <=> $b['keyword']);

        return $rows;
    }

    public function addKeywords(int $projectId, array $keywords): int
    {
        $added = 0;
        foreach ($keywords as $keyword) {
            if (!in_array($keyword, $this->keywords[$projectId] ?? [], true)) {
                $this->keywords[$projectId][$this->nextId++] = $keyword;
                $added++;
            }
        }

        return $added;
    }

    public function removeKeyword(int $projectId, string $keyword): bool
    {
        $id = array_search($keyword, $this->keywords[$projectId] ?? [], true);
        if ($id === false) {
            return false;
        }
        unset($this->keywords[$projectId][$id]);

        return true;
    }

    public function unchecked(int $projectId, string $date): array
    {
        $rows = [];
        foreach ($this->keywords[$projectId] ?? [] as $id => $keyword) {
            if (!isset($this->rankings[$id . ':' . $date])) {
                $rows[] = ['id' => $id, 'keyword' => $keyword];
            }
        }

        return $rows;
    }

    public function recordRanking(int $projectId, int $keywordId, string $date, ?int $position, array $top): void
    {
        $this->rankings[$keywordId . ':' . $date] = ['projectId' => $projectId, 'position' => $position, 'top' => $top];
    }

    public function recordProfile(int $projectId, string $date, BusinessProfile $profile): void
    {
        $this->profiles[$projectId][$date] = $profile;
    }

    public function latestDate(int $projectId): ?string
    {
        $dates = $this->dates($projectId);

        return $dates !== [] ? max($dates) : null;
    }

    public function dateBefore(int $projectId, string $date): ?string
    {
        $dates = array_filter($this->dates($projectId), static fn(string $d): bool => $d < $date);

        return $dates !== [] ? max($dates) : null;
    }

    public function rankings(int $projectId, string $date): array
    {
        $rows = [];
        foreach ($this->rankings as $key => $row) {
            [$id, $day] = explode(':', $key, 2);
            if ($row['projectId'] === $projectId && $day === $date) {
                $rows[(int) $id] = ['position' => $row['position'], 'top' => $row['top']];
            }
        }

        return $rows;
    }

    public function profiles(int $projectId, int $limit): array
    {
        $rows = $this->profiles[$projectId] ?? [];
        krsort($rows);
        $result = [];
        foreach (array_slice($rows, 0, $limit, true) as $date => $profile) {
            $result[] = ['date' => (string) $date, 'profile' => $profile];
        }

        return $result;
    }

    public function dueWeekly(string $before): array
    {
        $rows = [];
        foreach ($this->settings as $s) {
            if ($s->weekly && $s->status !== 'queued' && ($s->lastCheckedOn === null || $s->lastCheckedOn <= $before)) {
                $rows[] = ['projectId' => $s->projectId, 'workspaceId' => $s->workspaceId];
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function dates(int $projectId): array
    {
        $dates = [];
        foreach ($this->rankings as $key => $row) {
            if ($row['projectId'] === $projectId) {
                $dates[] = explode(':', $key, 2)[1];
            }
        }

        return $dates;
    }

    private function with(int $projectId, ?string $status = null, ?string $error = null, ?string $cid = null, ?string $checkedOn = null): void
    {
        $s = $this->settings[$projectId] ?? null;
        if ($s === null) {
            return;
        }
        $this->settings[$projectId] = new LocalSettings(
            $s->projectId,
            $s->workspaceId,
            $s->businessName,
            $s->area,
            $s->language,
            $s->weekly,
            $cid ?? $s->cid,
            $status ?? $s->status,
            $status !== null ? $error : $s->lastError,
            $checkedOn ?? $s->lastCheckedOn,
        );
    }
}
