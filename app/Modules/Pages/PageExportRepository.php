<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages;

/** Queued Page library exports. Reads are always scoped to a project. */
interface PageExportRepository
{
    public function create(int $projectId, int $workspaceId, ?int $requestedBy, string $search, string $sort, string $rangeFrom, string $rangeTo, int $totalUrls): PageExport;

    /** The queued or running export of a project, if any. */
    public function active(int $projectId): ?PageExport;

    public function find(int $projectId, string $publicId): ?PageExport;

    /** For the job, which only has the internal ID from its payload. */
    public function byId(int $id): ?PageExport;

    /** @return list<PageExport> newest first */
    public function recent(int $projectId, int $limit): array;

    /**
     * Records a written chunk. Returns false if the export is no longer
     * queued or running (for example, cleanup already failed it).
     *
     * @param array{key: int, url: string}|null $cursor
     */
    public function progress(int $id, int $rowCount, int $fileBytes, ?array $cursor, string $now): bool;

    public function complete(int $id, int $rowCount, int $fileBytes, string $now, string $expiresAt): void;

    public function fail(int $id, string $error, string $now, string $expiresAt): void;

    /**
     * Finished exports past their expiry, and active exports without
     * progress since $staleBefore.
     *
     * @return list<PageExport>
     */
    public function due(string $now, string $staleBefore, int $limit): array;

    public function delete(int $id): void;
}
