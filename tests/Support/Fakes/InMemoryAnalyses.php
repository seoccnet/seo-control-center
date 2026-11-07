<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Comparison\Analysis;
use SeoControlCenter\Domain\Comparison\AnalysisRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Comparison\Persistence\PdoAnalysisRepository;

/**
 * Analyses in memory with the same rows as competitor_analyses, hydrated by
 * the PDO repository's own mapping.
 */
final class InMemoryAnalyses implements AnalysisRepository
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    private int $sequence = 0;

    public function queue(Project $project, string $clusterId, string $label, string $keyword, array $keywords, float $demand, string $serpSource, int $locationCode, string $languageCode, int $userId, string $now): string
    {
        $id = Uuid::v7();
        $this->rows[$id] = ['id' => ++$this->sequence, 'public_id' => $id, 'workspace_id' => $project->workspaceId, 'project_id' => $project->id, 'cluster_id' => $clusterId, 'label' => $label, 'keyword' => $keyword, 'keywords' => json_encode($keywords, JSON_THROW_ON_ERROR), 'demand' => $demand, 'status' => Analysis::QUEUED, 'serp_source' => $serpSource, 'location_code' => $locationCode, 'language_code' => $languageCode, 'results' => null, 'own_url' => null, 'own_position' => null, 'pages' => null, 'verdict' => null, 'cost' => null, 'error' => null, 'requested_at' => $now, 'finished_at' => null];

        return $id;
    }

    public function find(Project $project, string $publicId): ?Analysis
    {
        $row = $this->rows[$publicId] ?? null;

        return $row !== null && $row['project_id'] === $project->id && $row['workspace_id'] === $project->workspaceId ? PdoAnalysisRepository::hydrate($row) : null;
    }

    public function byId(string $publicId): ?Analysis
    {
        return isset($this->rows[$publicId]) ? PdoAnalysisRepository::hydrate($this->rows[$publicId]) : null;
    }

    public function latestForCluster(Project $project, string $clusterId): ?Analysis
    {
        $match = array_filter($this->rows, static fn(array $r): bool => $r['project_id'] === $project->id && $r['workspace_id'] === $project->workspaceId && $r['cluster_id'] === $clusterId);
        usort($match, static fn(array $a, array $b): int => [$b['requested_at'], $b['id']] <=> [$a['requested_at'], $a['id']]);

        return $match === [] ? null : PdoAnalysisRepository::hydrate($match[0]);
    }

    public function start(string $publicId, string $now): bool
    {
        if (($this->rows[$publicId]['status'] ?? null) !== Analysis::QUEUED) {
            return false;
        }
        $this->rows[$publicId]['status'] = Analysis::RUNNING;

        return true;
    }

    public function complete(string $publicId, array $results, ?string $ownUrl, ?int $ownPosition, array $pages, array $verdict, ?float $cost, string $now): void
    {
        $this->rows[$publicId] = [...$this->rows[$publicId], 'status' => Analysis::DONE, 'results' => json_encode($results, JSON_THROW_ON_ERROR), 'own_url' => $ownUrl, 'own_position' => $ownPosition, 'pages' => json_encode($pages, JSON_THROW_ON_ERROR), 'verdict' => json_encode($verdict, JSON_THROW_ON_ERROR), 'cost' => $cost, 'error' => null, 'finished_at' => $now];
    }

    public function fail(string $publicId, string $error, string $now): void
    {
        $this->rows[$publicId] = [...$this->rows[$publicId], 'status' => Analysis::FAILED, 'error' => $error, 'finished_at' => $now];
    }

    public function latestDone(int $projectId, string $since): array
    {
        $latest = [];
        foreach ($this->rows as $row) {
            if ($row['project_id'] === $projectId && $row['status'] === Analysis::DONE && $row['finished_at'] >= $since && (!isset($latest[$row['cluster_id']]) || $row['finished_at'] > $latest[$row['cluster_id']]['finished_at'])) {
                $latest[$row['cluster_id']] = $row;
            }
        }

        return array_map(PdoAnalysisRepository::hydrate(...), array_values($latest));
    }
}
