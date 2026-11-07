<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Http\Csv;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Queued CSV exports of the Page library for results above the direct
 * download limit. A job writes 10,000 rows at a time to a private file under
 * storage/tmp, records the file size and sort cursor after each chunk, and
 * queues a continuation before its time runs out. A retry truncates the file
 * back to the recorded size, so a crash never duplicates rows. Files expire
 * after 24 hours. Called only after ProjectService has authorized the project.
 */
final readonly class PageExportService
{
    public const int MAX_URLS = 200000;
    public const int CHUNK = 10000;
    public const int RETENTION_HOURS = 24;
    /** Active exports without progress for this long are failed by cleanup. */
    private const int STALE_HOURS = 6;
    /** Time left for one more chunk; below this the job queues a continuation. */
    private const int RESERVE_SECONDS = 25;
    private const int REQUESTS_PER_HOUR = 6;

    public function __construct(
        private PageExportRepository $exports,
        private PageRepository $pages,
        private PageService $pageService,
        private PageExportScheduler $scheduler,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private Clock $clock,
        private LoggerInterface $logger,
        private string $directory,
    ) {}

    /**
     * Queues an export of every URL matching the search. A project prepares
     * one export at a time; while one is queued or running it is returned
     * instead (created: false).
     *
     * @return array{export: PageExport, created: bool}
     */
    public function request(User $user, Project $project, string $search, string $sort, ClientInfo $client): array
    {
        $search = PageService::search($search);
        $sort = PageService::sort($sort);
        $active = $this->exports->active($project->id);
        if ($active !== null) {
            return ['export' => $active, 'created' => false];
        }
        $total = $this->pages->count($project->id, $search);
        if ($total === 0) {
            throw new HttpException(422, 'No URLs match this search.');
        }
        if ($total > self::MAX_URLS) {
            throw new HttpException(422, sprintf('CSV export supports up to %s matching URLs; this search matches %s. Narrow the URL search and try again.', number_format(self::MAX_URLS), number_format($total)));
        }
        if (!$this->rateLimiter->hit('pages-export:project:' . $project->id, self::REQUESTS_PER_HOUR, 3600)->allowed) {
            throw new HttpException(429, 'Too many exports for this project. Try again later.');
        }
        $range = $this->pageService->range($project);
        $export = $this->exports->create($project->id, $project->workspaceId, $user->id, $search, $sort, $range->fromString(), $range->toString(), $total);
        $this->scheduler->export($export->id, $project->workspaceId, 0);
        $this->audit->record(new AuditEvent('project.pages_export_requested', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'export' => $export->publicId, 'search' => $search !== '' ? $search : null, 'sort' => $sort, 'urls' => $total,
        ]), $client);

        return ['export' => $export, 'created' => true];
    }

    /** @return list<PageExport> */
    public function recent(Project $project): array
    {
        return $this->exports->recent($project->id, 5);
    }

    /**
     * Job: writes chunks until the export is complete or time runs short,
     * then queues a continuation.
     *
     * @param \Closure(): int $secondsRemaining
     * @param (\Closure(): bool)|null $renewLease returns false when another worker took the job over
     */
    public function run(int $exportId, \Closure $secondsRemaining, ?\Closure $renewLease = null): void
    {
        $export = $this->exports->byId($exportId);
        if ($export === null || !$export->active()) {
            return;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('The export directory cannot be created.');
        }
        $handle = @fopen($this->path($export), 'c+');
        if ($handle === false) {
            throw new \RuntimeException('The export file cannot be opened.');
        }
        try {
            // A second worker on the same export (after a lease takeover) leaves it to the first.
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return;
            }
            $this->write($export, $handle, $secondsRemaining, $renewLease ?? static fn(): bool => true);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @param \Closure(): int $secondsRemaining
     * @param \Closure(): bool $renewLease
     */
    private function write(PageExport $export, $handle, \Closure $secondsRemaining, \Closure $renewLease): void
    {
        $rows = $export->rowCount;
        $cursor = $export->cursor();
        $size = (int) (fstat($handle)['size'] ?? 0);
        if ($size < $export->fileBytes || ($export->fileBytes === 0 && $rows > 0)) {
            // The recorded file is missing or shorter than recorded: start over.
            [$rows, $cursor, $size] = [0, null, 0];
        }
        ftruncate($handle, $rows === 0 ? 0 : max(0, $export->fileBytes));
        fseek($handle, 0, SEEK_END);
        if ($rows === 0) {
            self::append($handle, Csv::encode([PageService::EXPORT_HEADER]));
        }
        $range = new DateRange($export->rangeFrom, $export->rangeTo);
        while (true) {
            $limit = min(self::CHUNK, self::MAX_URLS - $rows);
            $chunk = $limit > 0 ? $this->pages->exportChunk($export->projectId, $range, $export->search, $export->sort, $cursor, $limit) : [];
            if ($chunk !== []) {
                self::append($handle, Csv::rows(array_map(static fn(PageSummary $page): array => PageService::exportRow($page, $range), $chunk)));
                $last = $chunk[count($chunk) - 1];
                $cursor = ['key' => self::sortValue($last, $export->sort), 'url' => $last->url];
                $rows += count($chunk);
            }
            fflush($handle);
            $size = (int) (fstat($handle)['size'] ?? 0);
            $now = $this->clock->now();
            if (count($chunk) < $limit || $limit === 0) {
                $this->exports->complete($export->id, $rows, $size, $now->format('Y-m-d H:i:s'), $now->modify('+' . self::RETENTION_HOURS . ' hours')->format('Y-m-d H:i:s'));
                $this->logger->info('Page export completed', ['project_id' => $export->projectId, 'rows' => $rows]);

                return;
            }
            if (!$this->exports->progress($export->id, $rows, $size, $cursor, $now->format('Y-m-d H:i:s'))) {
                return;
            }
            // Out of time, or the lease went to another worker (which finds the file locked and
            // stops): a continuation resumes from this chunk once the lock is released.
            if ($secondsRemaining() < self::RESERVE_SECONDS || !$renewLease()) {
                $this->scheduler->export($export->id, $export->workspaceId, $rows);

                return;
            }
        }
    }

    /** Job: marks an export failed after its last attempt and removes the partial file. */
    public function fail(int $exportId, string $error): void
    {
        $export = $this->exports->byId($exportId);
        if ($export === null) {
            return;
        }
        $now = $this->clock->now();
        $this->exports->fail($export->id, $error, $now->format('Y-m-d H:i:s'), $now->modify('+' . self::RETENTION_HOURS . ' hours')->format('Y-m-d H:i:s'));
        $this->remove($export);
    }

    /**
     * @return array{path: string, fileName: string}
     * @throws HttpException 404 when the export is unknown, unfinished or expired
     */
    public function download(Project $project, string $publicId): array
    {
        $export = $this->exports->find($project->id, $publicId);
        $path = $export !== null ? $this->path($export) : '';
        if ($export === null || $export->status !== PageExport::READY || $export->expiresAt === null
            || $export->expiresAt <= $this->clock->now()->format('Y-m-d H:i:s') || !is_file($path) || filesize($path) !== $export->fileBytes) {
            throw new HttpException(404, 'This export is not available. Prepare a new export.');
        }

        return ['path' => $path, 'fileName' => Csv::fileName($project->name, 'pages-all', $export->rangeTo)];
    }

    /**
     * Cleanup job: deletes expired exports and their files, fails exports
     * that stopped progressing, and removes files without a record (for
     * example after a project was deleted).
     */
    public function cleanup(): int
    {
        $now = $this->clock->now();
        $removed = 0;
        foreach ($this->exports->due($now->format('Y-m-d H:i:s'), $now->modify('-' . self::STALE_HOURS . ' hours')->format('Y-m-d H:i:s'), 200) as $export) {
            if ($export->active()) {
                $this->fail($export->id, 'The export stopped making progress. Prepare a new export.');
            } else {
                $this->remove($export);
                $this->exports->delete($export->id);
            }
            ++$removed;
        }
        $oldest = $now->modify('-' . (self::RETENTION_HOURS + self::STALE_HOURS * 2) . ' hours')->getTimestamp();
        foreach (glob($this->directory . '/*.csv') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $oldest && @unlink($file)) {
                ++$removed;
            }
        }

        return $removed;
    }

    private function path(PageExport $export): string
    {
        return $this->directory . '/' . $export->publicId . '.csv';
    }

    private function remove(PageExport $export): void
    {
        $path = $this->path($export);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @param resource $handle */
    private static function append($handle, string $bytes): void
    {
        if (fwrite($handle, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('The export file could not be written.');
        }
    }

    private static function sortValue(PageSummary $page, string $sort): int
    {
        return match ($sort) {
            'google_clicks' => $page->google?->clicks,
            'bing_clicks' => $page->bing?->clicks,
            'impressions' => $page->google?->impressions,
            default => 0,
        } ?? -1;
    }
}
