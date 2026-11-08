<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordMetrics;
use SeoControlCenter\Domain\Keywords\KeywordMetricsScheduler;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Keywords\KeywordSyncState;
use SeoControlCenter\Domain\Keywords\ProjectKeyword;

/**
 * In-memory keywords and metrics; also records metrics requests instead of queueing jobs.
 */
final class InMemoryKeywords implements KeywordRepository, KeywordMetricsScheduler
{
    /** @var array<int, list<array{id: int, public_id: string, keyword: string, locale: KeywordLocale, created_at: string}>> */
    public array $keywords = [];

    /** @var array<string, array{metrics: KeywordMetrics, fetched_at: string}> keyed by workspace:locale:keyword */
    public array $metrics = [];

    /** @var array<int, KeywordSyncState> */
    public array $sync = [];

    /** @var list<array{project_id: int, workspace_id: int}> */
    public array $requests = [];

    private int $nextId = 0;

    public function __construct(private readonly Clock $clock) {}

    public function add(int $projectId, array $keywords, KeywordLocale $locale, int $userId): int
    {
        $added = 0;
        foreach ($keywords as $keyword) {
            foreach ($this->keywords[$projectId] ?? [] as $row) {
                if ($row['keyword'] === $keyword && $row['locale']->equals($locale)) {
                    continue 2;
                }
            }
            $this->keywords[$projectId][] = ['id' => ++$this->nextId, 'public_id' => Uuid::v7($this->clock), 'keyword' => $keyword, 'locale' => $locale, 'created_at' => $this->now()];
            ++$added;
        }

        return $added;
    }

    public function remove(int $projectId, string $publicId): bool
    {
        $before = $this->keywords[$projectId] ?? [];
        $this->keywords[$projectId] = array_values(array_filter($before, static fn(array $row): bool => $row['public_id'] !== $publicId));

        return count($this->keywords[$projectId]) < count($before);
    }

    public function count(int $projectId): int
    {
        return count($this->keywords[$projectId] ?? []);
    }

    public function find(int $projectId, int $workspaceId, string $publicId): ?ProjectKeyword
    {
        foreach ($this->page($projectId, $workspaceId, PHP_INT_MAX, 0) as $keyword) {
            if ($keyword->publicId === $publicId) {
                return $keyword;
            }
        }

        return null;
    }

    public function texts(int $projectId): array
    {
        return array_values(array_unique(array_column($this->keywords[$projectId] ?? [], 'keyword')));
    }

    public function page(int $projectId, int $workspaceId, int $limit, int $offset): array
    {
        $rows = array_map(function (array $row) use ($workspaceId): ProjectKeyword {
            $stored = $this->metrics[self::key($workspaceId, $row['locale'], $row['keyword'])] ?? null;

            return new ProjectKeyword($row['public_id'], $row['keyword'], $row['locale'], $row['created_at'], $stored['metrics'] ?? null, $stored['fetched_at'] ?? null);
        }, $this->keywords[$projectId] ?? []);
        usort($rows, static fn(ProjectKeyword $a, ProjectKeyword $b): int => [$a->metrics?->searchVolume === null, -($a->metrics->searchVolume ?? 0), $a->keyword]
            <=> [$b->metrics?->searchVolume === null, -($b->metrics->searchVolume ?? 0), $b->keyword]);

        return array_slice($rows, $offset, $limit);
    }

    public function lastLocale(int $projectId): ?KeywordLocale
    {
        $rows = $this->keywords[$projectId] ?? [];

        return $rows === [] ? null : $rows[count($rows) - 1]['locale'];
    }

    public function marketsByCount(int $projectId, int $limit): array
    {
        $counts = [];
        $locales = [];
        foreach ($this->keywords[$projectId] ?? [] as $row) {
            $key = $row['locale']->key();
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $locales[$key] ??= $row['locale'];
        }
        // Most keywords first; a stable sort keeps the first-added market first on ties.
        $order = array_keys($counts);
        usort($order, static fn(string $a, string $b): int => $counts[$b] <=> $counts[$a]);

        return array_slice(array_map(static fn(string $key): KeywordLocale => $locales[$key], $order), 0, max(1, $limit));
    }

    public function needingMetrics(int $projectId, int $workspaceId, \DateTimeImmutable $staleBefore, int $limit): array
    {
        $groups = [];
        foreach (array_slice($this->pending($projectId, $workspaceId, $staleBefore), 0, $limit) as $row) {
            $groups[$row['locale']->key()]['locale'] = $row['locale'];
            $groups[$row['locale']->key()]['keywords'][] = $row['keyword'];
        }

        return array_values($groups);
    }

    public function storeMetrics(int $workspaceId, KeywordLocale $locale, array $metrics, \DateTimeImmutable $fetchedAt): void
    {
        foreach ($metrics as $m) {
            $this->metrics[self::key($workspaceId, $locale, Keyword::normalize($m->keyword))] = ['metrics' => $m, 'fetched_at' => $fetchedAt->format('Y-m-d H:i:s')];
        }
    }

    public function syncState(int $projectId): ?KeywordSyncState
    {
        return $this->sync[$projectId] ?? null;
    }

    public function setSyncState(int $projectId, string $status, ?string $error = null): void
    {
        $old = $this->sync[$projectId] ?? null;
        $this->sync[$projectId] = new KeywordSyncState(
            $status,
            $status === KeywordSyncState::ERROR ? $error : null,
            $status === KeywordSyncState::PENDING ? $this->now() : $old?->requestedAt,
            $status === KeywordSyncState::OK ? $this->now() : $old?->lastSuccessAt,
        );
    }

    public function requestMetrics(int $projectId, int $workspaceId): void
    {
        $this->requests[] = ['project_id' => $projectId, 'workspace_id' => $workspaceId];
    }

    /**
     * @return list<array{id: int, public_id: string, keyword: string, locale: KeywordLocale, created_at: string}>
     */
    private function pending(int $projectId, int $workspaceId, \DateTimeImmutable $staleBefore): array
    {
        return array_values(array_filter($this->keywords[$projectId] ?? [], function (array $row) use ($workspaceId, $staleBefore): bool {
            $stored = $this->metrics[self::key($workspaceId, $row['locale'], $row['keyword'])] ?? null;

            return $stored === null || $stored['fetched_at'] < $staleBefore->format('Y-m-d H:i:s');
        }));
    }

    private static function key(int $workspaceId, KeywordLocale $locale, string $keyword): string
    {
        return $workspaceId . ':' . $locale->key() . ':' . $keyword;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
