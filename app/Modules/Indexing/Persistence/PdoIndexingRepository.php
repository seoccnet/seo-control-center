<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Indexing\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Modules\Indexing\IndexingRepository;
use SeoControlCenter\Modules\Indexing\Inspection;
use SeoControlCenter\Modules\Indexing\InspectionRecord;

final readonly class PdoIndexingRepository implements IndexingRepository
{
    private const int HISTORY = 30;

    public function __construct(private Connection $db) {}

    public function track(int $projectId, string $binding, string $url, ?bool $watch): bool
    {
        return $this->db->transaction(function () use ($projectId, $binding, $url, $watch): bool {
            // Serialize URL/watch limits with all writers for this project.
            if ($this->db->fetchValue('SELECT id FROM projects WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$projectId]) === null) {
                return false;
            }
            $existing = $this->find($projectId, $binding, $url);
            $existsAnyBinding = $this->db->fetchValue('SELECT project_id FROM google_inspections WHERE project_id = ? AND url_hash = UNHEX(?)', [$projectId, hash('sha256', $url)]) !== null;
            if (!$existsAnyBinding && (int) $this->db->fetchValue('SELECT COUNT(*) FROM google_inspections WHERE project_id = ?', [$projectId]) >= 500) {
                return false;
            }
            $watched = $watch ?? ($existing === null ? false : $existing->watched);
            if ($watched && !($existing === null ? false : $existing->watched) && (int) $this->db->fetchValue('SELECT COUNT(*) FROM google_inspections WHERE project_id = ? AND binding = UNHEX(?) AND watched = 1', [$projectId, $binding]) >= 50) {
                return false;
            }
            if ($existing === null) {
                $this->db->execute('INSERT INTO google_inspections (project_id, url_hash, binding, url, watched) VALUES (?, UNHEX(?), UNHEX(?), ?, ?) ON DUPLICATE KEY UPDATE binding = VALUES(binding), url = VALUES(url), watched = VALUES(watched), result = NULL, checked_at = NULL, attempted_at = NULL, error = NULL, last_job = NULL', [$projectId, hash('sha256', $url), $binding, $url, $watched]);
            } else {
                $this->db->execute('UPDATE google_inspections SET watched = ? WHERE project_id = ? AND binding = UNHEX(?) AND url_hash = UNHEX(?)', [$watched, $projectId, $binding, hash('sha256', $url)]);
            }
            return true;
        });
    }

    public function find(int $projectId, string $binding, string $url): ?InspectionRecord
    {
        $row = $this->db->fetchOne('SELECT * FROM google_inspections WHERE project_id = ? AND binding = UNHEX(?) AND url_hash = UNHEX(?)', [$projectId, $binding, hash('sha256', $url)]);
        return $row === null ? null : self::record($row);
    }

    public function records(int $projectId, string $binding): array
    {
        return array_map(self::record(...), $this->db->fetchAll('SELECT * FROM google_inspections WHERE project_id = ? AND binding = UNHEX(?) ORDER BY watched DESC, url LIMIT 500', [$projectId, $binding]));
    }

    public function due(string $before, int $limit): array
    {
        $rows = $this->db->fetchAll('SELECT i.project_id, p.workspace_id, p.public_id, HEX(i.binding) AS binding, i.url FROM google_inspections i JOIN projects p ON p.id = i.project_id WHERE p.deleted_at IS NULL AND i.watched = 1 AND (i.attempted_at IS NULL OR i.attempted_at < ?) ORDER BY COALESCE(i.attempted_at, "2000-01-01"), i.project_id, i.url LIMIT ' . max(1, min(50, $limit)), [$before]);
        return array_map(static fn(array $r): array => ['project_id' => (int) $r['project_id'], 'workspace_id' => (int) $r['workspace_id'], 'public_id' => (string) $r['public_id'], 'binding' => strtolower((string) $r['binding']), 'url' => (string) $r['url']], $rows);
    }

    public function attempted(int $projectId, string $binding, string $url, string $at, ?string $error): void
    {
        $this->db->execute('UPDATE google_inspections SET attempted_at = ?, error = ? WHERE project_id = ? AND binding = UNHEX(?) AND url_hash = UNHEX(?)', [$at, $error, $projectId, $binding, hash('sha256', $url)]);
    }

    public function save(int $projectId, string $binding, string $url, Inspection $result, string $at, string $jobId): void
    {
        $this->db->transaction(function () use ($projectId, $binding, $url, $result, $at, $jobId): void {
            $row = $this->db->fetchOne('SELECT * FROM google_inspections WHERE project_id = ? AND binding = UNHEX(?) AND url_hash = UNHEX(?) FOR UPDATE', [$projectId, $binding, hash('sha256', $url)]);
            if ($row === null || $row['last_job'] === $jobId || ($row['checked_at'] !== null && (string) $row['checked_at'] > $at)) {
                return;
            }
            $old = self::record($row)->result;
            $canonicalChanged = $old !== null && $old->googleCanonical !== $result->googleCanonical;
            if ($old !== null && ($old->verdict !== $result->verdict || $canonicalChanged)) {
                $this->db->execute('INSERT INTO google_inspection_changes (project_id, binding, url, before_verdict, after_verdict, canonical_changed, checked_at) VALUES (?, UNHEX(?), ?, ?, ?, ?, ?)', [$projectId, $binding, $url, $old->verdict, $result->verdict, $canonicalChanged, $at]);
                // Bounded history per project, used only for deduplicated alerts.
                $keepFrom = $this->db->fetchValue('SELECT id FROM google_inspection_changes WHERE project_id = ? ORDER BY id DESC LIMIT 1 OFFSET 99', [$projectId]);
                if ($keepFrom !== null) {
                    $this->db->execute('DELETE FROM google_inspection_changes WHERE project_id = ? AND id < ?', [$projectId, (int) $keepFrom]);
                }
            }
            $this->db->execute('UPDATE google_inspections SET result = ?, checked_at = ?, attempted_at = ?, error = NULL, last_job = ? WHERE project_id = ? AND binding = UNHEX(?) AND url_hash = UNHEX(?)', [json_encode($result->present(), JSON_THROW_ON_ERROR), $at, $at, $jobId, $projectId, $binding, hash('sha256', $url)]);
            $this->db->execute('INSERT INTO google_inspection_history (project_id, binding, url_hash, checked_at, verdict, coverage, indexing_state, robots, last_crawl, google_canonical, user_canonical) VALUES (?, UNHEX(?), UNHEX(?), ?, ?, ?, ?, ?, ?, ?, ?)', [$projectId, $binding, hash('sha256', $url), $at, mb_substr($result->verdict, 0, 16), mb_substr($result->coverage, 0, 300), mb_substr($result->indexing, 0, 60), mb_substr($result->robots, 0, 40), $result->lastCrawl, mb_substr($result->googleCanonical, 0, 2048), mb_substr($result->userCanonical, 0, 2048)]);
            // Bounded history per URL.
            $keepFrom = $this->db->fetchValue('SELECT id FROM google_inspection_history WHERE project_id = ? AND binding = UNHEX(?) AND url_hash = UNHEX(?) ORDER BY id DESC LIMIT 1 OFFSET ' . (self::HISTORY - 1), [$projectId, $binding, hash('sha256', $url)]);
            if ($keepFrom !== null) {
                $this->db->execute('DELETE FROM google_inspection_history WHERE project_id = ? AND binding = UNHEX(?) AND url_hash = UNHEX(?) AND id < ?', [$projectId, $binding, hash('sha256', $url), (int) $keepFrom]);
            }
        });
    }

    public function history(int $projectId, string $binding, string $url): array
    {
        return array_map(static fn(array $r): array => ['checked_at' => (string) $r['checked_at'], 'verdict' => (string) $r['verdict'], 'coverage' => (string) $r['coverage'], 'indexing_state' => (string) $r['indexing_state'], 'robots' => (string) $r['robots'], 'last_crawl' => $r['last_crawl'] === null ? null : (string) $r['last_crawl'], 'google_canonical' => (string) $r['google_canonical'], 'user_canonical' => (string) $r['user_canonical']], $this->db->fetchAll('SELECT * FROM google_inspection_history WHERE project_id = ? AND binding = UNHEX(?) AND url_hash = UNHEX(?) ORDER BY checked_at DESC, id DESC LIMIT ' . self::HISTORY, [$projectId, $binding, hash('sha256', $url)]));
    }

    public function addRequest(int $workspaceId, int $projectId, string $publicId, string $url, string $channel, string $requestedOn, string $evidence, string $note, ?int $userId, string $at): void
    {
        $this->db->execute('INSERT INTO indexing_requests (public_id, workspace_id, project_id, url_hash, url, channel, requested_on, evidence, note, created_by, created_at) VALUES (?, ?, ?, UNHEX(?), ?, ?, ?, ?, ?, ?, ?)', [$publicId, $workspaceId, $projectId, hash('sha256', $url), $url, $channel, $requestedOn, $evidence, $note, $userId, $at]);
    }

    public function requests(int $projectId, string $url): array
    {
        return array_map(static fn(array $r): array => ['public_id' => (string) $r['public_id'], 'channel' => (string) $r['channel'], 'requested_on' => (string) $r['requested_on'], 'evidence' => (string) $r['evidence'], 'note' => (string) $r['note'], 'created_at' => (string) $r['created_at']], $this->db->fetchAll('SELECT public_id, channel, requested_on, evidence, note, created_at FROM indexing_requests WHERE project_id = ? AND url_hash = UNHEX(?) ORDER BY requested_on DESC, id DESC LIMIT 50', [$projectId, hash('sha256', $url)]));
    }

    public function changes(int $projectId, string $binding, string $since): array
    {
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'url' => (string) $r['url'], 'before' => (string) $r['before_verdict'], 'after' => (string) $r['after_verdict'], 'canonical_changed' => (bool) $r['canonical_changed']], $this->db->fetchAll('SELECT * FROM google_inspection_changes WHERE project_id = ? AND binding = UNHEX(?) AND checked_at >= ? ORDER BY id DESC LIMIT 100', [$projectId, $binding, $since]));
    }

    /** @param array<string, mixed> $row */
    private static function record(array $row): InspectionRecord
    {
        $data = $row['result'] === null ? null : json_decode((string) $row['result'], true);
        /** @var array<string, mixed>|null $data */
        return new InspectionRecord((string) $row['url'], (bool) $row['watched'], is_array($data) ? Inspection::fromArray($data) : null, $row['checked_at'] === null ? null : (string) $row['checked_at'], $row['attempted_at'] === null ? null : (string) $row['attempted_at'], $row['error'] === null ? null : (string) $row['error']);
    }
}
