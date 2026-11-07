<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Clusters\ClusterRepository;
use SeoControlCenter\Domain\Clusters\KeywordCluster;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Clusters\Persistence\PdoClusterRepository;

/**
 * Saved clusters in memory with the same rows as keyword_clusters; hydrated
 * by the PDO repository's own mapping.
 */
final class InMemoryClusters implements ClusterRepository
{
    /** @var array<string, array<string, mixed>> public ID => row */
    public array $rows = [];

    public function forProject(Project $project, bool $withRetired = true): array
    {
        $rows = array_values(array_filter($this->rows, static fn(array $r): bool => $r['project_id'] === $project->id && $r['workspace_id'] === $project->workspaceId && ($withRetired || $r['retired_at'] === null)));
        usort($rows, static fn(array $a, array $b): int => [$a['retired_at'] !== null, -$a['demand'], $a['public_id']] <=> [$b['retired_at'] !== null, -$b['demand'], $b['public_id']]);

        return array_map(PdoClusterRepository::hydrate(...), $rows);
    }

    public function find(Project $project, string $publicId): ?KeywordCluster
    {
        $row = $this->rows[$publicId] ?? null;

        return $row !== null && $row['project_id'] === $project->id && $row['workspace_id'] === $project->workspaceId ? PdoClusterRepository::hydrate($row) : null;
    }

    public function previous(int $projectId): array
    {
        $result = [];
        foreach ($this->rows as $id => $row) {
            if ($row['project_id'] === $projectId) {
                $result[$id] = array_column(json_decode((string) $row['members'], true), 'keyword');
            }
        }

        return $result;
    }

    public function sync(int $projectId, int $workspaceId, array $rows, string $now): void
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[$row['public_id']] = true;
            $old = $this->rows[$row['public_id']] ?? ['manual_owner_url' => null, 'manual_set_at' => null, 'via' => null, 'revision' => 1];
            $this->rows[$row['public_id']] = [
                ...$old,
                ...$row,
                'members' => json_encode($row['members'], JSON_THROW_ON_ERROR),
                'candidates' => json_encode($row['candidates'], JSON_THROW_ON_ERROR),
                'overlap' => json_encode($row['overlap'], JSON_THROW_ON_ERROR),
                'project_id' => $projectId,
                'workspace_id' => $workspaceId,
                'retired_at' => null,
                'computed_at' => $now,
            ];
        }
        foreach ($this->rows as $id => $row) {
            if ($row['project_id'] !== $projectId || isset($ids[$id])) {
                continue;
            }
            if ($row['manual_owner_url'] === null) {
                unset($this->rows[$id]);
            } elseif ($row['retired_at'] === null) {
                $this->rows[$id]['retired_at'] = $now;
            }
        }
    }

    public function setManualOwner(Project $project, string $publicId, ?string $url, int $userId, ?string $via, int $expectedRevision, string $now): bool
    {
        $row = $this->rows[$publicId] ?? null;
        if ($row === null || $row['project_id'] !== $project->id || $row['workspace_id'] !== $project->workspaceId || $row['revision'] !== $expectedRevision) {
            return false;
        }
        $this->rows[$publicId] = [...$row, 'manual_owner_url' => $url, 'manual_set_at' => $url === null ? null : $now, 'via' => $via, 'revision' => $row['revision'] + 1];

        return true;
    }
}
