<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Comparison\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Comparison\Analysis;
use SeoControlCenter\Domain\Comparison\AnalysisRepository;
use SeoControlCenter\Domain\Comparison\PageStructure;
use SeoControlCenter\Domain\Projects\Project;

final readonly class PdoAnalysisRepository implements AnalysisRepository
{
    public function __construct(private Connection $db) {}

    public function queue(Project $project, string $clusterId, string $label, string $keyword, array $keywords, float $demand, string $serpSource, int $locationCode, string $languageCode, int $userId, string $now): string
    {
        $id = Uuid::v7();
        $this->db->execute(
            'INSERT INTO competitor_analyses (public_id, workspace_id, project_id, cluster_id, label, keyword, keywords, demand, status, serp_source, location_code, language_code, requested_by, requested_at)
             SELECT ?, workspace_id, id, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? FROM projects WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL',
            [$id, $clusterId, mb_substr($label, 0, 255), mb_substr($keyword, 0, 255), json_encode(array_values($keywords), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $demand, Analysis::QUEUED, $serpSource, $locationCode, $languageCode, $userId, $now, $project->id, $project->workspaceId],
        );

        return $id;
    }

    public function find(Project $project, string $publicId): ?Analysis
    {
        $row = $this->db->fetchOne('SELECT * FROM competitor_analyses WHERE workspace_id = ? AND project_id = ? AND public_id = ?', [$project->workspaceId, $project->id, $publicId]);

        return $row === null ? null : self::hydrate($row);
    }

    public function byId(string $publicId): ?Analysis
    {
        $row = $this->db->fetchOne('SELECT a.* FROM competitor_analyses a JOIN projects p ON p.id = a.project_id AND p.workspace_id = a.workspace_id WHERE a.public_id = ? AND p.deleted_at IS NULL', [$publicId]);

        return $row === null ? null : self::hydrate($row);
    }

    public function latestForCluster(Project $project, string $clusterId): ?Analysis
    {
        $row = $this->db->fetchOne('SELECT * FROM competitor_analyses WHERE workspace_id = ? AND project_id = ? AND cluster_id = ? ORDER BY requested_at DESC, id DESC LIMIT 1', [$project->workspaceId, $project->id, $clusterId]);

        return $row === null ? null : self::hydrate($row);
    }

    public function start(string $publicId, string $now): bool
    {
        return $this->db->execute('UPDATE competitor_analyses SET status = ?, started_at = ? WHERE public_id = ? AND status = ?', [Analysis::RUNNING, $now, $publicId, Analysis::QUEUED]) === 1;
    }

    public function complete(string $publicId, array $results, ?string $ownUrl, ?int $ownPosition, array $pages, array $verdict, ?float $cost, string $now): void
    {
        $json = static fn(mixed $v): string => json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->execute(
            'UPDATE competitor_analyses SET status = ?, results = ?, own_url = ?, own_position = ?, pages = ?, verdict = ?, cost = ?, error = NULL, finished_at = ? WHERE public_id = ?',
            [Analysis::DONE, $json($results), $ownUrl, $ownPosition, $json($pages), $json($verdict), $cost, $now, $publicId],
        );
    }

    public function fail(string $publicId, string $error, string $now): void
    {
        $this->db->execute('UPDATE competitor_analyses SET status = ?, error = ?, finished_at = ? WHERE public_id = ?', [Analysis::FAILED, mb_substr($error, 0, 500), $now, $publicId]);
    }

    public function latestDone(int $projectId, string $since): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll(
            'SELECT a.* FROM competitor_analyses a
             JOIN (SELECT cluster_id, MAX(finished_at) AS last FROM competitor_analyses WHERE project_id = ? AND status = ? AND finished_at >= ? GROUP BY cluster_id) l
               ON l.cluster_id = a.cluster_id AND l.last = a.finished_at
             WHERE a.project_id = ? AND a.status = ? ORDER BY a.finished_at DESC LIMIT 100',
            [$projectId, Analysis::DONE, $since, $projectId, Analysis::DONE],
        ));
    }

    /** @param array<string, mixed> $row */
    public static function hydrate(array $row): Analysis
    {
        $decode = static function (mixed $raw): array {
            $value = is_string($raw) ? json_decode($raw, true) : null;

            return is_array($value) ? $value : [];
        };
        $pages = [];
        foreach ($decode($row['pages'] ?? null) as $url => $page) {
            if (is_string($url) && is_array($page)) {
                $pages[$url] = PageStructure::fromArray($page);
            }
        }
        $results = [];
        foreach ($decode($row['results'] ?? null) as $r) {
            if (is_array($r) && is_int($r['position'] ?? null) && is_string($r['url'] ?? null)) {
                $results[] = [
                    'position' => $r['position'],
                    'url' => $r['url'],
                    'domain' => (string) ($r['domain'] ?? ''),
                    'title' => is_string($r['title'] ?? null) ? $r['title'] : null,
                    'type' => (string) ($r['type'] ?? 'other'),
                    'confidence' => (string) ($r['confidence'] ?? 'low'),
                    'signals' => array_values(array_filter(is_array($r['signals'] ?? null) ? $r['signals'] : [], is_string(...))),
                    'own' => (bool) ($r['own'] ?? false),
                    'fetched' => (bool) ($r['fetched'] ?? false),
                ];
            }
        }
        /** @var array<string, mixed> $verdict */
        $verdict = $decode($row['verdict'] ?? null);

        return new Analysis(
            (string) $row['public_id'],
            (int) $row['project_id'],
            (int) $row['workspace_id'],
            (string) $row['cluster_id'],
            (string) $row['label'],
            (string) $row['keyword'],
            array_values(array_filter($decode($row['keywords'] ?? null), is_string(...))),
            (float) $row['demand'],
            (string) $row['status'],
            (string) $row['serp_source'],
            (int) $row['location_code'],
            (string) $row['language_code'],
            $results,
            isset($row['own_url']) ? (string) $row['own_url'] : null,
            isset($row['own_position']) ? (int) $row['own_position'] : null,
            $pages,
            $verdict,
            isset($row['cost']) ? (float) $row['cost'] : null,
            isset($row['error']) ? (string) $row['error'] : null,
            (string) $row['requested_at'],
            isset($row['finished_at']) ? (string) $row['finished_at'] : null,
        );
    }
}
