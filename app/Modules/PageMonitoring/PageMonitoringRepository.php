<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\PageMonitoring;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Projects\Project;

final readonly class PageMonitoringRepository implements PageChangeReader, PageWatchReader, PageWatchWriter
{
    public function __construct(private Connection $db) {}

    public function watch(Project $project, string $url, int $limit = self::DEFAULT_LIMIT, string $reason = 'watch'): bool
    {
        return (bool) $this->db->transaction(function () use ($project, $url, $limit, $reason): bool {
            $this->db->fetchOne('SELECT id FROM projects WHERE id = ? AND workspace_id = ? FOR UPDATE', [$project->id, $project->workspaceId]) ?? throw new HttpException(404);
            if ($this->find($project->id, $url) !== null) {
                return false;
            }
            if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM page_watches WHERE project_id = ?', [$project->id]) >= $limit) {
                throw new HttpException(409, sprintf('This project already watches %d pages, the limit of this workspace. Stop watching a page before adding another.', $limit));
            }
            $this->db->execute('INSERT INTO page_watches (project_id, url_hash, url, generation, reason) VALUES (?, UNHEX(?), ?, ?, ?)', [$project->id, hash('sha256', $url), $url, Uuid::v7(), $reason]);

            return true;
        });
    }

    public function limit(int $workspaceId): int
    {
        $limit = $this->db->fetchValue('SELECT page_watch_limit FROM workspaces WHERE id = ?', [$workspaceId]);

        return $limit === null ? self::DEFAULT_LIMIT : max(1, min(self::MAX_LIMIT, (int) $limit));
    }

    public function setLimit(int $workspaceId, int $limit): void
    {
        $this->db->execute('UPDATE workspaces SET page_watch_limit = ? WHERE id = ?', [max(1, min(self::MAX_LIMIT, $limit)), $workspaceId]);
    }

    public function watched(Project $project, string $url): ?array
    {
        $row = $this->db->fetchOne('SELECT w.checked_at, w.reason, w.after_due_at FROM page_watches w JOIN projects p ON p.id = w.project_id WHERE p.workspace_id = ? AND w.project_id = ? AND w.url_hash = UNHEX(?)', [$project->workspaceId, $project->id, hash('sha256', $url)]);

        return $row === null ? null : ['checked_at' => $row['checked_at'] !== null ? (string) $row['checked_at'] : null, 'reason' => (string) $row['reason'], 'after_due_at' => $row['after_due_at'] !== null ? (string) $row['after_due_at'] : null];
    }

    public function afterCheck(Project $project, string $url, string $dueAt): void
    {
        $this->db->execute('UPDATE page_watches w JOIN projects p ON p.id = w.project_id SET w.after_due_at = ? WHERE p.workspace_id = ? AND w.project_id = ? AND w.url_hash = UNHEX(?)', [$dueAt, $project->workspaceId, $project->id, hash('sha256', $url)]);
    }

    public function unwatch(Project $project, string $url): void
    {
        $this->db->execute('DELETE w FROM page_watches w JOIN projects p ON p.id = w.project_id WHERE p.workspace_id = ? AND w.project_id = ? AND w.url_hash = UNHEX(?)', [$project->workspaceId, $project->id, hash('sha256', $url)]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $projectId, string $url): ?array
    {
        return $this->db->fetchOne('SELECT w.* FROM page_watches w JOIN projects p ON p.id = w.project_id JOIN workspaces ws ON ws.id = p.workspace_id WHERE w.project_id = ? AND w.url_hash = UNHEX(?) AND p.deleted_at IS NULL AND ws.deleted_at IS NULL', [$projectId, hash('sha256', $url)]);
    }

    /** @return list<array<string, mixed>> */
    public function watches(Project $project): array
    {
        return $this->db->fetchAll('SELECT w.* FROM page_watches w JOIN projects p ON p.id = w.project_id WHERE p.workspace_id = ? AND w.project_id = ? ORDER BY w.url_hash LIMIT ' . self::MAX_LIMIT, [$project->workspaceId, $project->id]);
    }

    /**
     * Weekly checks due before $before, and "after" checks due by $now.
     *
     * @return list<array<string, mixed>>
     */
    public function due(string $before, ?string $now = null): array
    {
        return $this->db->fetchAll('SELECT w.*, p.workspace_id, p.public_id FROM page_watches w JOIN projects p ON p.id = w.project_id JOIN workspaces ws ON ws.id = p.workspace_id WHERE p.deleted_at IS NULL AND ws.deleted_at IS NULL AND (w.attempted_at IS NULL OR w.attempted_at <= ? OR (w.after_due_at IS NOT NULL AND w.after_due_at <= ?)) ORDER BY w.attempted_at, w.project_id LIMIT 50', [$before, $now ?? $before]);
    }

    public function attempted(int $projectId, string $url, string $generation, string $at, ?string $error): void
    {
        $this->db->execute('UPDATE page_watches SET attempted_at = ?, error = ?, after_due_at = IF(after_due_at <= ?, NULL, after_due_at) WHERE project_id = ? AND url_hash = UNHEX(?) AND generation = ?', [$at, $error, $at, $projectId, hash('sha256', $url), $generation]);
    }

    public function save(int $projectId, string $url, string $generation, PageSnapshot $snapshot, string $at, string $job): void
    {
        $this->db->transaction(function () use ($projectId, $url, $generation, $snapshot, $at, $job): void {
            $row = $this->db->fetchOne('SELECT w.* FROM page_watches w JOIN projects p ON p.id = w.project_id JOIN workspaces ws ON ws.id = p.workspace_id WHERE w.project_id = ? AND w.url_hash = UNHEX(?) AND w.generation = ? AND p.deleted_at IS NULL AND ws.deleted_at IS NULL FOR UPDATE', [$projectId, hash('sha256', $url), $generation]);
            if ($row === null || $row['last_job'] === $job) {
                return;
            }
            $before = is_string($row['snapshot']) ? PageSnapshot::decode($row['snapshot']) : null;
            if ($before !== null && $before->changes($snapshot) !== []) {
                $this->db->execute('INSERT INTO page_watch_changes (public_id, project_id, url, before_snapshot, after_snapshot, before_at, detected_at, significant) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [Uuid::v7(), $projectId, $url, $before->encode(), $snapshot->encode(), (string) $row['checked_at'], $at, $before->significant($snapshot)]);
                // Keep a bounded history per project; the baseline remains on each watch.
                $old = $this->db->fetchAll('SELECT public_id FROM page_watch_changes WHERE project_id = ? ORDER BY detected_at DESC, public_id DESC LIMIT 50 OFFSET 500', [$projectId]);
                foreach ($old as $item) {
                    $this->db->execute('DELETE FROM page_watch_changes WHERE project_id = ? AND public_id = ?', [$projectId, (string) $item['public_id']]);
                }
            }
            $this->db->execute('UPDATE page_watches SET snapshot = ?, checked_at = ?, attempted_at = ?, error = NULL, last_job = ? WHERE project_id = ? AND url_hash = UNHEX(?) AND generation = ?', [$snapshot->encode(), $at, $at, $job, $projectId, hash('sha256', $url), $generation]);
        });
    }

    public function changesFor(int $projectId, string $url, string $from, string $to): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT public_id, detected_at, before_snapshot, after_snapshot, url FROM page_watch_changes WHERE project_id = ? AND detected_at BETWEEN ? AND ? ORDER BY detected_at LIMIT 200', [$projectId, $from, $to]) as $row) {
            if (\SeoControlCenter\Domain\Opportunities\OpportunityConsolidator::pageKey((string) $row['url']) !== \SeoControlCenter\Domain\Opportunities\OpportunityConsolidator::pageKey($url)) {
                continue;
            }
            $out[] = ['public_id' => (string) $row['public_id'], 'detected_at' => (string) $row['detected_at'], 'fields' => array_map('strval', array_keys(PageSnapshot::decode((string) $row['before_snapshot'])->changes(PageSnapshot::decode((string) $row['after_snapshot']))))];
        }

        return $out;
    }

    public function countChanges(int $projectId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM page_watch_changes WHERE project_id = ?', [$projectId]);
    }

    /** @return list<array<string, mixed>> */
    public function changes(int $projectId, int $page = 1, ?string $since = null): array
    {
        return $since === null
            ? $this->db->fetchAll('SELECT * FROM page_watch_changes WHERE project_id = ? ORDER BY detected_at DESC, public_id DESC LIMIT 25 OFFSET ?', [$projectId, (max(1, $page) - 1) * 25])
            : $this->db->fetchAll('SELECT * FROM page_watch_changes WHERE project_id = ? AND significant = 1 AND detected_at >= ? ORDER BY detected_at, public_id LIMIT 500', [$projectId, $since]);
    }

    /** @return array<string, mixed>|null */
    public function change(Project $project, string $id): ?array
    {
        return $this->db->fetchOne('SELECT c.* FROM page_watch_changes c JOIN projects p ON p.id = c.project_id WHERE p.workspace_id = ? AND c.project_id = ? AND c.public_id = ?', [$project->workspaceId, $project->id, $id]);
    }

    /** @return list<array<string, mixed>> */
    public function exportChanges(Project $project, string $search = '', string $kind = 'all'): array
    {
        [$where, $params] = $this->historyFilter($project, $search, $kind);
        return $this->db->fetchAll('SELECT c.* FROM page_watch_changes c JOIN projects p ON p.id = c.project_id WHERE ' . $where . ' ORDER BY c.detected_at DESC, c.public_id DESC LIMIT 500', $params);
    }

    public function countHistory(Project $project, string $search, string $kind): int
    {
        [$where, $params] = $this->historyFilter($project, $search, $kind);
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM page_watch_changes c JOIN projects p ON p.id = c.project_id WHERE ' . $where, $params);
    }

    /** @return list<array<string, mixed>> */
    public function history(Project $project, string $search, string $kind, int $page): array
    {
        [$where, $params] = $this->historyFilter($project, $search, $kind);
        $params[] = (max(1, $page) - 1) * 25;
        return $this->db->fetchAll('SELECT c.* FROM page_watch_changes c JOIN projects p ON p.id = c.project_id WHERE ' . $where . ' ORDER BY c.detected_at DESC, c.public_id DESC LIMIT 25 OFFSET ?', $params);
    }

    /** @return array{string, list<int|string>} */
    private function historyFilter(Project $project, string $search, string $kind): array
    {
        $where = 'p.workspace_id = ? AND c.project_id = ?';
        $params = [$project->workspaceId, $project->id];
        if ($search !== '') {
            $where .= " AND c.url LIKE ? ESCAPE '!'";
            $params[] = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        if ($kind !== 'all') {
            $where .= ' AND c.significant = ?';
            $params[] = $kind === 'significant' ? 1 : 0;
        }
        return [$where, $params];
    }

    /**
     * @template T
     * @param \Closure(array<string, mixed>): T $save
     * @return T
     */
    public function annotate(Project $project, string $id, \Closure $save): mixed
    {
        return $this->db->transaction(function () use ($project, $id, $save): mixed {
            $row = $this->db->fetchOne('SELECT c.* FROM page_watch_changes c JOIN projects p ON p.id = c.project_id WHERE p.workspace_id = ? AND c.project_id = ? AND c.public_id = ? FOR UPDATE', [$project->workspaceId, $project->id, $id]) ?? throw new HttpException(404);
            return $save($row);
        });
    }

    public function link(Project $project, string $id, string $annotationId): void
    {
        $this->db->execute('UPDATE page_watch_changes c JOIN projects p ON p.id = c.project_id SET c.annotation_id = ? WHERE p.workspace_id = ? AND c.project_id = ? AND c.public_id = ?', [$annotationId, $project->workspaceId, $project->id, $id]);
    }
}
