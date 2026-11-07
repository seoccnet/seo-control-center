<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Modules\Indexing\IndexingRepository;
use SeoControlCenter\Modules\Indexing\Inspection;
use SeoControlCenter\Modules\Indexing\InspectionRecord;
use SeoControlCenter\Modules\Indexing\InspectionScheduler;

final class InMemoryIndexing implements IndexingRepository, InspectionScheduler
{
    /** @var array<int, array<string, InspectionRecord>> */
    public array $rows = [];
    /** @var array<int, array<string, string>> */
    public array $bindings = [];
    /** @var list<array{project_id: int, workspace_id: int, public_id: string, binding: string, url: string}> */
    public array $jobs = [];
    /** @var list<array{project_id: int, workspace_id: int, public_id: string, binding: string, url: string}> */
    public array $dueRows = [];
    /** @var array<int, list<array{id: int, url: string, before: string, after: string, canonical_changed: bool, binding: string, at: string}>> */
    private array $events = [];
    /** @var array<int, array<string, string>> */
    private array $lastJobs = [];
    /** @var array<int, array<string, list<array{binding: string, checked_at: string, verdict: string, coverage: string, indexing_state: string, robots: string, last_crawl: ?string, google_canonical: string, user_canonical: string}>>> */
    private array $history = [];
    /** @var array<int, array<string, list<array{public_id: string, channel: string, requested_on: string, evidence: string, note: string, created_at: string}>>> */
    public array $requests = [];

    public function track(int $projectId, string $binding, string $url, ?bool $watch): bool
    {
        $old = $this->find($projectId, $binding, $url);
        if (!isset($this->rows[$projectId][$url]) && count($this->rows[$projectId] ?? []) >= 500) {
            return false;
        }
        $watched = $watch ?? ($old === null ? false : $old->watched);
        if ($watched && !($old === null ? false : $old->watched) && count(array_filter($this->records($projectId, $binding), static fn(InspectionRecord $r): bool => $r->watched)) >= 50) {
            return false;
        }
        $this->bindings[$projectId][$url] = $binding;
        $this->rows[$projectId][$url] = new InspectionRecord($url, $watched, $old?->result, $old?->checkedAt, $old?->attemptedAt, $old?->error);
        return true;
    }
    public function find(int $projectId, string $binding, string $url): ?InspectionRecord
    {
        return ($this->bindings[$projectId][$url] ?? '') === $binding ? $this->rows[$projectId][$url] ?? null : null;
    }
    public function records(int $projectId, string $binding): array
    {
        return array_values(array_filter($this->rows[$projectId] ?? [], fn(InspectionRecord $r): bool => ($this->bindings[$projectId][$r->url] ?? '') === $binding));
    }
    public function inspect(int $projectId, int $workspaceId, string $publicId, string $binding, string $url): void
    {
        $this->jobs[] = ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'public_id' => $publicId, 'binding' => $binding, 'url' => $url];
    }
    public function due(string $before, int $limit): array
    {
        return array_slice(array_values(array_filter($this->dueRows, function (array $r) use ($before): bool {
            $record = $this->find($r['project_id'], $r['binding'], $r['url']);
            return $record !== null && $record->watched && ($record->attemptedAt === null || $record->attemptedAt < $before);
        })), 0, $limit);
    }
    public function attempted(int $projectId, string $binding, string $url, string $at, ?string $error): void
    {
        $old = $this->find($projectId, $binding, $url);
        if ($old !== null) {
            $this->rows[$projectId][$url] = new InspectionRecord($url, $old->watched, $old->result, $old->checkedAt, $at, $error);
        }
    }
    public function save(int $projectId, string $binding, string $url, Inspection $result, string $at, string $jobId): void
    {
        $old = $this->find($projectId, $binding, $url);
        if ($old === null || ($this->lastJobs[$projectId][$url] ?? '') === $jobId || ($old->checkedAt ?? '') > $at) {
            return;
        }
        if ($old->result !== null && ($old->result->verdict !== $result->verdict || $old->result->googleCanonical !== $result->googleCanonical)) {
            $this->events[$projectId][] = ['id' => count($this->events[$projectId] ?? []) + 1, 'url' => $url, 'before' => $old->result->verdict, 'after' => $result->verdict, 'canonical_changed' => $old->result->googleCanonical !== $result->googleCanonical, 'binding' => $binding, 'at' => $at];
        }
        $this->lastJobs[$projectId][$url] = $jobId;
        $this->rows[$projectId][$url] = new InspectionRecord($url, $old->watched, $result, $at, $at, null);
        $this->history[$projectId][$url] = [['binding' => $binding, 'checked_at' => $at, 'verdict' => $result->verdict, 'coverage' => $result->coverage, 'indexing_state' => $result->indexing, 'robots' => $result->robots, 'last_crawl' => $result->lastCrawl, 'google_canonical' => $result->googleCanonical, 'user_canonical' => $result->userCanonical], ...($this->history[$projectId][$url] ?? [])];
        $this->history[$projectId][$url] = array_slice($this->history[$projectId][$url], 0, 30);
    }
    public function history(int $projectId, string $binding, string $url): array
    {
        $rows = array_values(array_filter($this->history[$projectId][$url] ?? [], static fn(array $r): bool => $r['binding'] === $binding));
        return array_map(static function (array $r): array {
            unset($r['binding']);
            return $r;
        }, $rows);
    }
    public function addRequest(int $workspaceId, int $projectId, string $publicId, string $url, string $channel, string $requestedOn, string $evidence, string $note, ?int $userId, string $at): void
    {
        $this->requests[$projectId][$url][] = ['public_id' => $publicId, 'channel' => $channel, 'requested_on' => $requestedOn, 'evidence' => $evidence, 'note' => $note, 'created_at' => $at];
        usort($this->requests[$projectId][$url], static fn(array $a, array $b): int => [$b['requested_on'], $b['created_at']] <=> [$a['requested_on'], $a['created_at']]);
    }
    public function requests(int $projectId, string $url): array
    {
        return array_slice($this->requests[$projectId][$url] ?? [], 0, 50);
    }
    public function changes(int $projectId, string $binding, string $since): array
    {
        return array_map(static fn(array $r): array => ['id' => $r['id'], 'url' => $r['url'], 'before' => $r['before'], 'after' => $r['after'], 'canonical_changed' => $r['canonical_changed']], array_values(array_filter($this->events[$projectId] ?? [], static fn(array $r): bool => $r['binding'] === $binding && $r['at'] >= $since)));
    }
}
