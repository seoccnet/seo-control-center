<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Queue;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Support\SystemState;
use Throwable;

/**
 * The single cron entry point (`php bin/cron.php`, QUEUE_AND_CRON.md -> Cron):
 * 1. scheduler lock → release expired reservations, enqueue due tasks → release lock
 * 2. take a free worker slot → run jobs for a bounded time → release slot
 * 3. record a heartbeat for System Health
 */
final readonly class CronRunner
{
    public function __construct(
        private Connection $db,
        private Scheduler $scheduler,
        private Worker $worker,
        private SystemState $state,
        private LoggerInterface $logger,
        private int $maxSeconds = 50,
        private int $workerSlots = 2,
        private ?int $memoryLimitBytes = null,
        private int $intervalMinutes = 1,
    ) {}

    /**
     * @return array{scheduled: list<string>, released: int, processed: int, slot: ?int}
     */
    public function run(): array
    {
        $started = microtime(true);
        $scheduled = [];
        $released = 0;

        $schedulerLock = new LeaseLock($this->db, 'scheduler', 55);
        if ($schedulerLock->acquire()) {
            try {
                $released = $this->worker->releaseExpiredReservations();
                // Look back two intervals so tasks between two runs are never skipped (slots are claimed once).
                $scheduled = $this->scheduler->enqueueDue(max(2, 2 * $this->intervalMinutes));
            } catch (Throwable $e) {
                $this->logger->error('Scheduler step failed', ['exception' => $e]);
            } finally {
                $schedulerLock->release();
            }
        }

        $processed = 0;
        $slotNumber = null;
        for ($slot = 1; $slot <= $this->workerSlots; ++$slot) {
            $lock = new LeaseLock($this->db, 'worker:' . $slot, $this->maxSeconds + 150);
            if (!$lock->acquire()) {
                continue;
            }
            $slotNumber = $slot;
            try {
                $processed = $this->worker->work(Queue::QUEUES, $this->maxSeconds, 100, $this->memoryLimitBytes, $lock->renew(...));
            } finally {
                $lock->release();
            }
            break;
        }

        $this->state->set('cron.heartbeat', [
            'at' => gmdate('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'processed' => $processed,
            'worker_slot' => $slotNumber,
        ]);

        return ['scheduled' => $scheduled, 'released' => $released, 'processed' => $processed, 'slot' => $slotNumber];
    }
}
