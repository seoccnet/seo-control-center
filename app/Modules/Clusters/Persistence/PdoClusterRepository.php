<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Clusters\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Domain\Clusters\ClusterRepository;
use SeoControlCenter\Domain\Clusters\KeywordCluster;
use SeoControlCenter\Domain\Projects\Project;

final readonly class PdoClusterRepository implements ClusterRepository
{
    private const string COLUMNS = 'public_id, label, size, demand, members, status, owner_url, owner_source, inferred_url, owner_reason, candidates, overlap, overlap_flagged, manual_owner_url, manual_set_at, via, revision, retired_at, computed_at';

    public function __construct(private Connection $db) {}

    public function forProject(Project $project, bool $withRetired = true): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM keyword_clusters WHERE workspace_id = ? AND project_id = ?' . ($withRetired ? '' : ' AND retired_at IS NULL') . ' ORDER BY retired_at IS NOT NULL, demand DESC, id LIMIT 1000',
            [$project->workspaceId, $project->id],
        ));
    }

    public function find(Project $project, string $publicId): ?KeywordCluster
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM keyword_clusters WHERE workspace_id = ? AND project_id = ? AND public_id = ?', [$project->workspaceId, $project->id, $publicId]);

        return $row === null ? null : self::hydrate($row);
    }

    public function previous(int $projectId): array
    {
        $result = [];
        foreach ($this->db->fetchAll('SELECT public_id, members FROM keyword_clusters WHERE project_id = ?', [$projectId]) as $row) {
            $members = json_decode((string) $row['members'], true);
            $result[(string) $row['public_id']] = is_array($members) ? array_values(array_filter(array_map(static fn(mixed $m): mixed => is_array($m) ? ($m['keyword'] ?? null) : null, $members), is_string(...))) : [];
        }

        return $result;
    }

    public function sync(int $projectId, int $workspaceId, array $rows, string $now): void
    {
        $this->db->transaction(function () use ($projectId, $workspaceId, $rows, $now): void {
            $this->db->fetchOne('SELECT id FROM projects WHERE id = ? AND workspace_id = ? FOR UPDATE', [$projectId, $workspaceId]) ?? throw new \RuntimeException('Project not found.');
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = $row['public_id'];
                $json = static fn(mixed $v): string => json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->db->execute(
                    'INSERT INTO keyword_clusters (public_id, workspace_id, project_id, label, size, demand, members, status, owner_url, owner_source, inferred_url, owner_reason, candidates, overlap, overlap_flagged, computed_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE label = VALUES(label), size = VALUES(size), demand = VALUES(demand), members = VALUES(members), status = VALUES(status),
                         owner_url = VALUES(owner_url), owner_source = VALUES(owner_source), inferred_url = VALUES(inferred_url), owner_reason = VALUES(owner_reason), candidates = VALUES(candidates),
                         overlap = VALUES(overlap), overlap_flagged = VALUES(overlap_flagged), computed_at = VALUES(computed_at), updated_at = VALUES(updated_at), retired_at = NULL',
                    [$row['public_id'], $workspaceId, $projectId, $row['label'], $row['size'], $row['demand'], $json($row['members']), $row['status'], $row['owner_url'], $row['owner_source'], $row['inferred_url'], $row['owner_reason'], $json($row['candidates']), $json($row['overlap']), $row['overlap_flagged'], $now, $now, $now],
                );
            }
            $keep = $ids === [] ? '' : ' AND public_id NOT IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
            $this->db->execute('DELETE FROM keyword_clusters WHERE project_id = ? AND manual_owner_url IS NULL' . $keep, [$projectId, ...$ids]);
            $this->db->execute('UPDATE keyword_clusters SET retired_at = ?, updated_at = ? WHERE project_id = ? AND retired_at IS NULL AND manual_owner_url IS NOT NULL' . $keep, [$now, $now, $projectId, ...$ids]);
        });
    }

    public function setManualOwner(Project $project, string $publicId, ?string $url, int $userId, ?string $via, int $expectedRevision, string $now): bool
    {
        return $this->db->execute(
            'UPDATE keyword_clusters SET manual_owner_url = ?, manual_set_by = ?, manual_set_at = ?, via = ?, revision = revision + 1, updated_at = ? WHERE workspace_id = ? AND project_id = ? AND public_id = ? AND revision = ?',
            [$url, $url === null ? null : $userId, $url === null ? null : $now, $via, $now, $project->workspaceId, $project->id, $publicId, $expectedRevision],
        ) === 1;
    }

    /** @param array<string, mixed> $row */
    public static function hydrate(array $row): KeywordCluster
    {
        $list = static function (mixed $raw): array {
            $value = is_string($raw) ? json_decode($raw, true) : null;

            return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
        };
        $manual = isset($row['manual_owner_url']) ? (string) $row['manual_owner_url'] : null;
        $members = array_values(array_map(static fn(array $m): array => ['keyword' => (string) ($m['keyword'] ?? ''), 'demand' => (float) ($m['demand'] ?? 0)], $list($row['members'])));
        $candidates = array_values(array_map(static fn(array $c): array => ['url' => (string) ($c['url'] ?? ''), 'clicks' => (int) ($c['clicks'] ?? 0), 'impressions' => (int) ($c['impressions'] ?? 0), 'share' => (float) ($c['share'] ?? 0), 'best_position' => isset($c['best_position']) ? (float) $c['best_position'] : null], $list($row['candidates'])));
        /** @var list<array<string, mixed>> $overlap */
        $overlap = $list($row['overlap']);

        return new KeywordCluster(
            (string) $row['public_id'],
            (string) $row['label'],
            (int) $row['size'],
            (float) $row['demand'],
            $members,
            $manual !== null ? KeywordCluster::OWNED : (string) $row['status'],
            $manual ?? (isset($row['owner_url']) ? (string) $row['owner_url'] : null),
            $manual !== null ? 'manual' : (isset($row['owner_source']) ? (string) $row['owner_source'] : null),
            isset($row['inferred_url']) ? (string) $row['inferred_url'] : null,
            (string) ($row['owner_reason'] ?? ''),
            $candidates,
            $overlap,
            (int) $row['overlap_flagged'],
            $manual,
            isset($row['manual_set_at']) ? (string) $row['manual_set_at'] : null,
            isset($row['via']) ? (string) $row['via'] : null,
            (int) $row['revision'],
            isset($row['retired_at']) ? (string) $row['retired_at'] : null,
            (string) $row['computed_at'],
        );
    }
}
