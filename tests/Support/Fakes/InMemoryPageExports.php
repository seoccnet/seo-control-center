<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\Pages\PageExport;
use SeoControlCenter\Modules\Pages\PageExportRepository;
use SeoControlCenter\Modules\Pages\PageExportScheduler;

final class InMemoryPageExports implements PageExportRepository, PageExportScheduler
{
    /** @var array<int, PageExport> */
    public array $exports = [];
    /** @var list<array{export_id: int, workspace_id: int, row_count: int}> */
    public array $dispatched = [];
    private int $nextId = 1;

    public function __construct(private readonly Clock $clock) {}

    public function export(int $exportId, int $workspaceId, int $rowCount): void
    {
        foreach ($this->dispatched as $job) {
            if ($job['export_id'] === $exportId && $job['row_count'] === $rowCount) {
                return;
            }
        }
        $this->dispatched[] = ['export_id' => $exportId, 'workspace_id' => $workspaceId, 'row_count' => $rowCount];
    }

    public function create(int $projectId, int $workspaceId, ?int $requestedBy, string $search, string $sort, string $rangeFrom, string $rangeTo, int $totalUrls): PageExport
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $id = $this->nextId++;
        return $this->exports[$id] = new PageExport($id, Uuid::v7($this->clock), $projectId, $workspaceId, $requestedBy, $search, $sort, $rangeFrom, $rangeTo, PageExport::QUEUED, $totalUrls, 0, 0, null, null, null, $now, $now, null, null);
    }

    public function active(int $projectId): ?PageExport
    {
        foreach (array_reverse($this->exports) as $export) {
            if ($export->projectId === $projectId && $export->active()) {
                return $export;
            }
        }
        return null;
    }

    public function find(int $projectId, string $publicId): ?PageExport
    {
        foreach ($this->exports as $export) {
            if ($export->projectId === $projectId && $export->publicId === $publicId) {
                return $export;
            }
        }
        return null;
    }

    public function byId(int $id): ?PageExport
    {
        return $this->exports[$id] ?? null;
    }

    public function recent(int $projectId, int $limit): array
    {
        return array_slice(array_values(array_filter(array_reverse($this->exports), static fn(PageExport $e): bool => $e->projectId === $projectId)), 0, $limit);
    }

    public function progress(int $id, int $rowCount, int $fileBytes, ?array $cursor, string $now): bool
    {
        $export = $this->exports[$id] ?? null;
        if ($export === null || !$export->active()) {
            return false;
        }
        $this->exports[$id] = $this->with($export, PageExport::RUNNING, $rowCount, $fileBytes, $cursor, null, $now, null, null);
        return true;
    }

    public function complete(int $id, int $rowCount, int $fileBytes, string $now, string $expiresAt): void
    {
        $export = $this->exports[$id] ?? null;
        if ($export !== null && $export->active()) {
            $this->exports[$id] = $this->with($export, PageExport::READY, $rowCount, $fileBytes, null, null, $now, $now, $expiresAt);
        }
    }

    public function fail(int $id, string $error, string $now, string $expiresAt): void
    {
        $export = $this->exports[$id] ?? null;
        if ($export !== null && $export->active()) {
            $this->exports[$id] = $this->with($export, PageExport::FAILED, $export->rowCount, $export->fileBytes, null, $error, $now, $now, $expiresAt);
        }
    }

    public function due(string $now, string $staleBefore, int $limit): array
    {
        return array_slice(array_values(array_filter($this->exports, static fn(PageExport $e): bool => $e->active()
            ? $e->updatedAt < $staleBefore
            : $e->expiresAt !== null && $e->expiresAt <= $now)), 0, $limit);
    }

    public function delete(int $id): void
    {
        unset($this->exports[$id]);
    }

    /** Test helper: rewrites stored fields, for example to age an export. */
    public function set(int $id, string $field, mixed $value): void
    {
        $values = get_object_vars($this->exports[$id]);
        $values[$field] = $value;
        $this->exports[$id] = new PageExport(...$values);
    }

    /** @param array{key: int, url: string}|null $cursor */
    private function with(PageExport $e, string $status, int $rows, int $bytes, ?array $cursor, ?string $error, string $updatedAt, ?string $completedAt, ?string $expiresAt): PageExport
    {
        return new PageExport($e->id, $e->publicId, $e->projectId, $e->workspaceId, $e->requestedBy, $e->search, $e->sort, $e->rangeFrom, $e->rangeTo, $status, $e->totalUrls, $rows, $bytes, $cursor['key'] ?? null, $cursor['url'] ?? null, $error, $e->createdAt, $updatedAt, $completedAt, $expiresAt);
    }
}
