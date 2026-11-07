<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Queue;

/**
 * Queue overview for installation administrators: counts by status and failed
 * jobs (ADR-022: they stay in `jobs` with status 'failed') with manual retry.
 */
interface QueueMonitor
{
    /**
     * @return array{queued: int, running: int, completed: int, failed: int}
     */
    public function stats(): array;

    /**
     * @return list<FailedJob> newest first
     */
    public function failed(int $limit = 50): array;

    public function failedCount(): int;

    /**
     * Queues a failed job again with fresh attempts. False if it is not (or no longer) failed.
     */
    public function retry(string $publicId): bool;

    /**
     * @return int jobs queued again
     */
    public function retryAll(): int;

    /**
     * Deletes a failed job. False if it is not (or no longer) failed.
     */
    public function dismiss(string $publicId): bool;
}
