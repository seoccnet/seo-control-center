<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\PageMonitoring\PageWatchReader;
use SeoControlCenter\Modules\PageMonitoring\PageWatchWriter;

/**
 * Watched pages and detected changes in memory, keyed by project ID, with
 * the same rows (snapshot JSON, public IDs) as the page_watches tables.
 */
final class InMemoryPageWatches implements PageWatchReader, PageWatchWriter, \SeoControlCenter\Modules\PageMonitoring\PageChangeReader
{
    /** @var array<int, list<array<string, mixed>>> */
    public array $watches = [];

    /** @var array<int, list<array<string, mixed>>> newest first */
    public array $changes = [];

    /** @var array<int, int> page watch limit per workspace */
    public array $limits = [];

    public function watches(Project $project): array
    {
        return array_slice($this->watches[$project->id] ?? [], 0, self::MAX_LIMIT);
    }

    public function watch(Project $project, string $url, int $limit = self::DEFAULT_LIMIT, string $reason = 'watch'): bool
    {
        foreach ($this->watches[$project->id] ?? [] as $row) {
            if ($row['url'] === $url) {
                return false;
            }
        }
        if (count($this->watches[$project->id] ?? []) >= $limit) {
            throw new HttpException(409, sprintf('This project already watches %d pages, the limit of this workspace. Stop watching a page before adding another.', $limit));
        }
        $this->watches[$project->id][] = ['url' => $url, 'snapshot' => null, 'checked_at' => null, 'attempted_at' => null, 'error' => null, 'reason' => $reason, 'after_due_at' => null];

        return true;
    }

    public function limit(int $workspaceId): int
    {
        return $this->limits[$workspaceId] ?? self::DEFAULT_LIMIT;
    }

    public function setLimit(int $workspaceId, int $limit): void
    {
        $this->limits[$workspaceId] = max(1, min(self::MAX_LIMIT, $limit));
    }

    public function watched(Project $project, string $url): ?array
    {
        foreach ($this->watches[$project->id] ?? [] as $row) {
            if ($row['url'] === $url) {
                return ['checked_at' => is_string($row['checked_at'] ?? null) ? $row['checked_at'] : null, 'reason' => is_string($row['reason'] ?? null) ? $row['reason'] : 'watch', 'after_due_at' => is_string($row['after_due_at'] ?? null) ? $row['after_due_at'] : null];
            }
        }

        return null;
    }

    public function afterCheck(Project $project, string $url, string $dueAt): void
    {
        foreach ($this->watches[$project->id] ?? [] as $i => $row) {
            if ($row['url'] === $url) {
                $this->watches[$project->id][$i]['after_due_at'] = $dueAt;
            }
        }
    }

    public function unwatch(Project $project, string $url): void
    {
        $this->watches[$project->id] = array_values(array_filter($this->watches[$project->id] ?? [], static fn(array $row): bool => $row['url'] !== $url));
    }

    public function changes(int $projectId, int $page = 1, ?string $since = null): array
    {
        return array_values(array_filter($this->changes[$projectId] ?? [], static fn(array $row): bool => $since === null || (string) $row['detected_at'] >= $since));
    }

    public function changesFor(int $projectId, string $url, string $from, string $to): array
    {
        $out = [];
        foreach (array_reverse($this->changes[$projectId] ?? []) as $row) {
            if (\SeoControlCenter\Domain\Opportunities\OpportunityConsolidator::pageKey((string) $row['url']) === \SeoControlCenter\Domain\Opportunities\OpportunityConsolidator::pageKey($url) && (string) $row['detected_at'] >= $from && (string) $row['detected_at'] <= $to) {
                $out[] = ['public_id' => (string) $row['public_id'], 'detected_at' => (string) $row['detected_at'], 'fields' => is_array($row['fields'] ?? null) ? array_values(array_map('strval', $row['fields'])) : []];
            }
        }

        return $out;
    }

    public function countHistory(Project $project, string $search, string $kind): int
    {
        return count($this->filtered($project, $search, $kind));
    }

    public function history(Project $project, string $search, string $kind, int $page): array
    {
        return array_slice($this->filtered($project, $search, $kind), (max(1, $page) - 1) * 25, 25);
    }

    public function change(Project $project, string $id): ?array
    {
        foreach ($this->changes[$project->id] ?? [] as $row) {
            if ($row['public_id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function filtered(Project $project, string $search, string $kind): array
    {
        return array_values(array_filter($this->changes[$project->id] ?? [], static fn(array $row): bool => ($search === '' || str_contains((string) $row['url'], $search))
            && ($kind === 'all' || (bool) $row['significant'] === ($kind === 'significant'))));
    }
}
