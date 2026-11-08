<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdeaRepository;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdeaRun;
use SeoControlCenter\Domain\ArticleIdeas\ArticleOutline;
use SeoControlCenter\Domain\Comparison\PageStructure;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\ArticleIdeas\ArticleIdeaScheduler;

/** Article idea runs, rows and ideas in memory; queued runs are recorded, not run. */
final class InMemoryArticleIdeas implements ArticleIdeaRepository, ArticleIdeaScheduler
{
    /** @var array<int, ArticleIdeaRun> */
    public array $runs = [];

    /** @var array<int, list<array{domain: string, found: bool, url: string, keyword: string, volume: ?int, difficulty: ?int, position: ?int, traffic: ?float, avg_rd: ?int, market?: ?string}>> by run */
    public array $rows = [];

    /** @var array<int, array{workspace_id: int, built_at: string, ideas: list<ArticleIdea>}> by project */
    public array $ideas = [];

    /** @var array<int, array<string, string>> project => idea key => main keyword */
    public array $dismissed = [];

    /** @var list<array{run_id: int, workspace_id: int}> */
    public array $queued = [];

    /** @var array<string, array{project_id: int, workspace_id: int}> by idempotency window */
    public array $rebuilds = [];

    /** @var array<int, array<string, array{workspace_id: int, outline: ArticleOutline}>> project => idea key */
    public array $outlineRows = [];

    /** @var list<array{project_id: int, workspace_id: int, idea_key: string}> */
    public array $outlineJobs = [];

    /** @var list<int> topic plans whose link map was queued */
    public array $planLinkJobs = [];

    /** @var list<int> topic plans whose paid backlink check was queued */
    public array $planBacklinkJobs = [];

    private int $nextId = 1;

    public function __construct(private readonly Clock $clock) {}

    public function queue(int $runId, int $workspaceId): void
    {
        $this->queued[] = ['run_id' => $runId, 'workspace_id' => $workspaceId];
    }

    public function outline(Project $project, string $key): ?ArticleOutline
    {
        return $this->outlines($project, [$key])[$key] ?? null;
    }

    public function queuePlanLinks(int $planId, int $workspaceId): void
    {
        $this->planLinkJobs[] = $planId;
    }

    public function queuePlanBacklinks(int $planId, int $workspaceId): void
    {
        $this->planBacklinkJobs[] = $planId;
    }

    public function queueOutline(int $projectId, int $workspaceId, string $key): void
    {
        $this->outlineJobs[] = ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'idea_key' => $key];
    }

    public function requestOutline(Project $project, string $key, ?int $userId, string $now, string $freshSince, string $staleBefore): bool
    {
        $row = $this->outlineRows[$project->id][$key]['outline'] ?? null;
        if ($row !== null && (($row->status === ArticleOutline::QUEUED && $row->requestedAt >= $staleBefore) || ($row->status === ArticleOutline::DONE && $row->fetchedAt !== null && $row->fetchedAt >= $freshSince))) {
            return false;
        }
        $this->outlineRows[$project->id][$key] = ['workspace_id' => $project->workspaceId, 'outline' => new ArticleOutline($key, ArticleOutline::QUEUED, $row->pages ?? [], $row?->own, null, $now, $row?->fetchedAt)];

        return true;
    }

    public function outlines(Project $project, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $row = $this->outlineRows[$project->id][$key] ?? null;
            if ($row !== null && $row['workspace_id'] === $project->workspaceId) {
                $out[$key] = $row['outline'];
            }
        }

        return $out;
    }

    public function saveOutline(int $projectId, string $key, array $pages, ?PageStructure $own, string $now): void
    {
        $row = $this->outlineRows[$projectId][$key] ?? null;
        if ($row !== null) {
            $this->outlineRows[$projectId][$key]['outline'] = new ArticleOutline($key, ArticleOutline::DONE, array_map(ArticleOutline::slim(...), $pages), $own !== null ? ArticleOutline::slim($own) : null, null, $row['outline']->requestedAt, $now);
        }
    }

    public function failOutline(int $projectId, string $key, string $error, string $now): void
    {
        $row = $this->outlineRows[$projectId][$key] ?? null;
        if ($row !== null) {
            $o = $row['outline'];
            $this->outlineRows[$projectId][$key]['outline'] = new ArticleOutline($key, ArticleOutline::FAILED, $o->pages, $o->own, $error, $o->requestedAt, $now);
        }
    }

    public function rebuild(int $projectId, int $workspaceId, string $window): void
    {
        $this->rebuilds[$projectId . ':' . $window] ??= ['project_id' => $projectId, 'workspace_id' => $workspaceId];
    }

    public function createRun(Project $project, array $competitors, ?string $market, float $estimatedCost, ?int $userId, string $now, array $markets = []): ArticleIdeaRun
    {
        $id = $this->nextId++;

        return $this->runs[$id] = new ArticleIdeaRun($id, Uuid::v7($this->clock), $project->id, $project->workspaceId, ArticleIdeaRun::QUEUED, $market, $competitors, $estimatedCost, null, $now, null, $markets);
    }

    public function run(int $runId): ?ArticleIdeaRun
    {
        return $this->runs[$runId] ?? null;
    }

    public function latestRun(Project $project): ?ArticleIdeaRun
    {
        $found = null;
        foreach ($this->runs as $run) {
            if ($run->projectId === $project->id && $run->workspaceId === $project->workspaceId) {
                $found = $run;
            }
        }

        return $found;
    }

    public function latestDoneRun(int $projectId): ?ArticleIdeaRun
    {
        $found = null;
        foreach ($this->runs as $run) {
            if ($run->projectId === $projectId && $run->status === ArticleIdeaRun::DONE) {
                $found = $run;
            }
        }

        return $found;
    }

    public function hasActiveRun(int $projectId): bool
    {
        foreach ($this->runs as $run) {
            if ($run->projectId === $projectId && $run->active()) {
                return true;
            }
        }

        return false;
    }

    public function markRunning(int $runId): void
    {
        $this->set($runId, ArticleIdeaRun::RUNNING, null, null, [ArticleIdeaRun::QUEUED]);
    }

    public function finish(int $runId, string $now): void
    {
        $this->set($runId, ArticleIdeaRun::DONE, null, $now, [ArticleIdeaRun::QUEUED, ArticleIdeaRun::RUNNING]);
    }

    public function fail(int $runId, string $error, string $now): void
    {
        $this->set($runId, ArticleIdeaRun::FAILED, $error, $now, [ArticleIdeaRun::QUEUED, ArticleIdeaRun::RUNNING]);
    }

    public function saveRows(int $runId, int $projectId, array $rows): void
    {
        $this->rows[$runId] = [...($this->rows[$runId] ?? []), ...$rows];
    }

    public function rows(int $runId): array
    {
        return array_map(static fn(array $r): array => [...$r, 'market' => $r['market'] ?? null], $this->rows[$runId] ?? []);
    }

    public function replaceIdeas(int $projectId, int $workspaceId, int $runId, array $ideas, string $now): void
    {
        $this->ideas[$projectId] = ['workspace_id' => $workspaceId, 'built_at' => $now, 'ideas' => $ideas];
    }

    public function ideas(Project $project, int $limit): array
    {
        $stored = $this->ideas[$project->id] ?? null;
        if ($stored === null || $stored['workspace_id'] !== $project->workspaceId) {
            return [];
        }
        $open = array_values(array_filter($stored['ideas'], fn(ArticleIdea $idea): bool => !isset($this->dismissed[$project->id][$idea->key])));
        usort($open, static fn(ArticleIdea $a, ArticleIdea $b): int => [$b->score, $a->mainKeyword] <=> [$a->score, $b->mainKeyword]);

        return array_slice($open, 0, max(1, $limit));
    }

    public function idea(Project $project, string $key): ?ArticleIdea
    {
        $stored = $this->ideas[$project->id] ?? null;
        if ($stored === null || $stored['workspace_id'] !== $project->workspaceId) {
            return null;
        }
        foreach ($stored['ideas'] as $idea) {
            if ($idea->key === $key) {
                return $idea;
            }
        }

        return null;
    }

    public function builtAt(int $projectId): ?string
    {
        return $this->ideas[$projectId]['built_at'] ?? null;
    }

    public function dismiss(Project $project, string $key, string $mainKeyword, ?int $userId, string $now): void
    {
        $this->dismissed[$project->id][$key] ??= $mainKeyword;
    }

    public function rebuildable(int $limit): array
    {
        $latest = [];
        foreach ($this->runs as $run) {
            if ($run->status === ArticleIdeaRun::DONE) {
                $latest[$run->projectId] = ['run_id' => $run->id, 'project_id' => $run->projectId, 'workspace_id' => $run->workspaceId];
            }
        }

        return array_slice(array_values($latest), 0, max(1, $limit));
    }

    public function pruneRows(int $projectId, int $keep): void
    {
        $done = array_keys(array_filter($this->runs, static fn(ArticleIdeaRun $run): bool => $run->projectId === $projectId && $run->status === ArticleIdeaRun::DONE));
        rsort($done);
        foreach (array_slice($done, max(1, $keep)) as $runId) {
            unset($this->rows[$runId]);
        }
    }

    /** @param list<string> $from */
    private function set(int $runId, string $status, ?string $error, ?string $finishedAt, array $from): void
    {
        $run = $this->runs[$runId] ?? null;
        if ($run === null || !in_array($run->status, $from, true)) {
            return;
        }
        $this->runs[$runId] = new ArticleIdeaRun($run->id, $run->publicId, $run->projectId, $run->workspaceId, $status, $run->market, $run->competitors, $run->estimatedCost, $error ?? $run->error, $run->createdAt, $finishedAt ?? $run->finishedAt, $run->markets);
    }
}
