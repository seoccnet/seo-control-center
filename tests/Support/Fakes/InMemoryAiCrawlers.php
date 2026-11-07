<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\AiVisibility\AiCrawlerCheck;
use SeoControlCenter\Modules\AiVisibility\AiCrawlerRepository;
use SeoControlCenter\Modules\AiVisibility\AiCrawlerScheduler;

/**
 * AI crawler checks (0.43.0) in memory; queued checks are recorded, not run.
 */
final class InMemoryAiCrawlers implements AiCrawlerRepository, AiCrawlerScheduler
{
    /** @var array<int, AiCrawlerCheck> */
    public array $checks = [];

    /** @var list<array{project_id: int, workspace_id: int, project_public_id: string}> */
    public array $queued = [];

    public function __construct(private readonly InMemoryDirectory $directory) {}

    public function latest(int $projectId): ?AiCrawlerCheck
    {
        return $this->checks[$projectId] ?? null;
    }

    public function save(int $workspaceId, AiCrawlerCheck $check): void
    {
        $this->checks[$check->projectId] = $check;
    }

    public function dueProjects(string $checkedBefore, int $limit): array
    {
        $rows = [];
        foreach ($this->directory->projects as $project) {
            $check = $this->checks[$project->id] ?? null;
            if (isset($this->directory->deleted[$project->id]) || isset($this->directory->deletedWorkspaces[$project->workspaceId]) || ($check !== null && $check->checkedAt >= $checkedBefore)) {
                continue;
            }
            $rows[] = ['project_id' => $project->id, 'workspace_id' => $project->workspaceId, 'project_public_id' => $project->publicId];
        }

        return array_slice($rows, 0, $limit);
    }

    public function check(int $projectId, int $workspaceId, string $publicId): void
    {
        $this->queued[] = ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'project_public_id' => $publicId];
    }
}
