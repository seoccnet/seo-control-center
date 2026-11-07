<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Queue;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;

final readonly class PdoQueueMonitor implements QueueMonitor
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function stats(): array
    {
        $stats = ['queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status') as $row) {
            $status = (string) $row['status'];
            if (isset($stats[$status])) {
                $stats[$status] = (int) $row['n'];
            }
        }

        return $stats;
    }

    public function failed(int $limit = 50): array
    {
        $rows = $this->db->fetchAll(
            sprintf("SELECT public_id, type, queue, attempts, last_error, finished_at FROM jobs WHERE status = 'failed' ORDER BY finished_at DESC, id DESC LIMIT %d", max(1, min(500, $limit))),
        );

        return array_map(static fn(array $row): FailedJob => new FailedJob(
            (string) $row['public_id'],
            (string) $row['type'],
            (string) $row['queue'],
            (int) $row['attempts'],
            $row['last_error'] !== null ? (string) $row['last_error'] : null,
            $row['finished_at'] !== null ? (string) $row['finished_at'] : null,
        ), $rows);
    }

    public function failedCount(): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM jobs WHERE status = 'failed'");
    }

    public function retry(string $publicId): bool
    {
        return $this->requeue('public_id = ?', [$publicId]) === 1;
    }

    public function retryAll(): int
    {
        return $this->requeue('1 = 1', []);
    }

    public function dismiss(string $publicId): bool
    {
        return $this->db->execute("DELETE FROM jobs WHERE status = 'failed' AND public_id = ?", [$publicId]) === 1;
    }

    /**
     * @param list<string> $params
     */
    private function requeue(string $condition, array $params): int
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // last_error is kept until the next attempt finishes, so the cause stays visible.
        return $this->db->execute(
            "UPDATE jobs
                SET status = 'queued', attempts = 0, available_at = ?, reservation_token = NULL,
                    reserved_until = NULL, started_at = NULL, finished_at = NULL, updated_at = ?
              WHERE status = 'failed' AND " . $condition,
            [$now, $now, ...$params],
        );
    }
}
