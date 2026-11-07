<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\Pages\PageExport;
use SeoControlCenter\Modules\Pages\PageExportRepository;

final readonly class PdoPageExportRepository implements PageExportRepository
{
    public function __construct(private Connection $db, private Clock $clock) {}

    public function create(int $projectId, int $workspaceId, ?int $requestedBy, string $search, string $sort, string $rangeFrom, string $rangeTo, int $totalUrls): PageExport
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $publicId = Uuid::v7($this->clock);
        $this->db->execute(
            "INSERT INTO page_exports (public_id, project_id, workspace_id, requested_by, search, sort, range_from, range_to, status, total_urls, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'queued', ?, ?, ?)",
            [$publicId, $projectId, $workspaceId, $requestedBy, $search, $sort, $rangeFrom, $rangeTo, $totalUrls, $now, $now],
        );

        return $this->byId($this->db->lastInsertId()) ?? throw new \RuntimeException('The export was not saved.');
    }

    public function active(int $projectId): ?PageExport
    {
        $row = $this->db->fetchOne("SELECT * FROM page_exports WHERE project_id = ? AND status IN ('queued', 'running') ORDER BY id DESC LIMIT 1", [$projectId]);

        return $row === null ? null : self::hydrate($row);
    }

    public function find(int $projectId, string $publicId): ?PageExport
    {
        $row = $this->db->fetchOne('SELECT * FROM page_exports WHERE project_id = ? AND public_id = ?', [$projectId, $publicId]);

        return $row === null ? null : self::hydrate($row);
    }

    public function byId(int $id): ?PageExport
    {
        $row = $this->db->fetchOne('SELECT * FROM page_exports WHERE id = ?', [$id]);

        return $row === null ? null : self::hydrate($row);
    }

    public function recent(int $projectId, int $limit): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll('SELECT * FROM page_exports WHERE project_id = ? ORDER BY id DESC LIMIT ' . max(1, min(20, $limit)), [$projectId]));
    }

    public function progress(int $id, int $rowCount, int $fileBytes, ?array $cursor, string $now): bool
    {
        return $this->db->execute(
            "UPDATE page_exports SET status = 'running', row_count = ?, file_bytes = ?, cursor_key = ?, cursor_url = ?, updated_at = ?
             WHERE id = ? AND status IN ('queued', 'running')",
            [$rowCount, $fileBytes, $cursor['key'] ?? null, $cursor['url'] ?? null, $now, $id],
        ) === 1;
    }

    public function complete(int $id, int $rowCount, int $fileBytes, string $now, string $expiresAt): void
    {
        $this->db->execute(
            "UPDATE page_exports SET status = 'ready', row_count = ?, file_bytes = ?, cursor_key = NULL, cursor_url = NULL, error = NULL, updated_at = ?, completed_at = ?, expires_at = ?
             WHERE id = ? AND status IN ('queued', 'running')",
            [$rowCount, $fileBytes, $now, $now, $expiresAt, $id],
        );
    }

    public function fail(int $id, string $error, string $now, string $expiresAt): void
    {
        $this->db->execute(
            "UPDATE page_exports SET status = 'failed', error = ?, cursor_key = NULL, cursor_url = NULL, updated_at = ?, completed_at = ?, expires_at = ?
             WHERE id = ? AND status IN ('queued', 'running')",
            [mb_substr($error, 0, 500), $now, $now, $expiresAt, $id],
        );
    }

    public function due(string $now, string $staleBefore, int $limit): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll(
            "SELECT * FROM page_exports WHERE (status IN ('ready', 'failed') AND expires_at <= ?) OR (status IN ('queued', 'running') AND updated_at < ?)
             ORDER BY id LIMIT " . max(1, min(500, $limit)),
            [$now, $staleBefore],
        ));
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM page_exports WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): PageExport
    {
        return new PageExport(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['project_id'],
            (int) $row['workspace_id'],
            $row['requested_by'] === null ? null : (int) $row['requested_by'],
            (string) $row['search'],
            (string) $row['sort'],
            (string) $row['range_from'],
            (string) $row['range_to'],
            (string) $row['status'],
            (int) $row['total_urls'],
            (int) $row['row_count'],
            (int) $row['file_bytes'],
            $row['cursor_key'] === null ? null : (int) $row['cursor_key'],
            $row['cursor_url'] === null ? null : (string) $row['cursor_url'],
            $row['error'] === null ? null : (string) $row['error'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
            $row['completed_at'] === null ? null : (string) $row['completed_at'],
            $row['expires_at'] === null ? null : (string) $row['expires_at'],
        );
    }
}
