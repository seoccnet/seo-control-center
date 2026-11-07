<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Queue;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\DatabaseException;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;

/**
 * Dispatches jobs to the MariaDB/MySQL queue (QUEUE_AND_CRON.md).
 */
final readonly class Queue
{
    public const array QUEUES = ['high', 'default', 'imports', 'rankings', 'analysis', 'reports', 'cleanup'];

    public function __construct(
        private Connection $db,
        private JobRegistry $registry,
        private Clock $clock,
    ) {}

    /**
     * Queues a job. With an idempotency key, dispatching the same operation
     * twice returns the existing job instead of creating a duplicate.
     *
     * @param array<string, mixed> $payload JSON-serializable, never secrets
     * @return string public job ID
     */
    public function dispatch(
        string $type,
        array $payload = [],
        string $queue = 'default',
        int $priority = 0,
        int $delaySeconds = 0,
        int $maxAttempts = 3,
        ?string $idempotencyKey = null,
        ?int $workspaceId = null,
    ): string {
        if (!$this->registry->has($type)) {
            throw new \InvalidArgumentException(sprintf('Unknown job type "%s".', $type));
        }
        if (!in_array($queue, self::QUEUES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown queue "%s".', $queue));
        }
        if ($idempotencyKey !== null && (strlen($idempotencyKey) > 191 || preg_match('/^[\x21-\x7e]+$/D', $idempotencyKey) !== 1)) {
            throw new \InvalidArgumentException('Idempotency keys must be 1-191 printable ASCII characters.');
        }

        $now = $this->clock->now();
        $publicId = Uuid::v7($this->clock);
        try {
            $this->db->execute(
                'INSERT INTO jobs (public_id, queue, type, payload, priority, status, attempts, max_attempts, available_at,
                                   idempotency_key, workspace_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, \'queued\', 0, ?, ?, ?, ?, ?, ?)',
                [
                    $publicId, $queue, $type, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $priority, max(1, $maxAttempts), $now->modify(sprintf('+%d seconds', max(0, $delaySeconds)))->format('Y-m-d H:i:s'),
                    $idempotencyKey, $workspaceId, $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'),
                ],
            );
        } catch (DatabaseException $e) {
            if ($idempotencyKey === null || !self::isDuplicateKey($e)) {
                throw $e;
            }
            $existing = $this->db->fetchValue('SELECT public_id FROM jobs WHERE idempotency_key = ?', [$idempotencyKey]);
            if (!is_string($existing)) {
                throw $e;
            }

            return $existing;
        }

        return $publicId;
    }

    /**
     * @return array<string, int> counts by status
     */
    public function stats(): array
    {
        $stats = ['queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status') as $row) {
            $stats[(string) $row['status']] = (int) $row['n'];
        }

        return $stats;
    }

    private static function isDuplicateKey(DatabaseException $e): bool
    {
        $previous = $e->getPrevious();

        return $previous instanceof \PDOException && ($previous->errorInfo[1] ?? null) === 1062;
    }
}
