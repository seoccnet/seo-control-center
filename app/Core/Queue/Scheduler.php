<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Queue;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;

/**
 * Enqueues due scheduled tasks exactly once per slot, even when cron runs
 * overlap (QUEUE_AND_CRON.md -> Scheduled jobs).
 */
final class Scheduler
{
    /** @var array<string, ScheduledTask> */
    private array $tasks = [];

    public function __construct(
        private readonly Connection $db,
        private readonly Queue $queue,
        private readonly Clock $clock,
    ) {}

    public function add(ScheduledTask $task): void
    {
        if (isset($this->tasks[$task->name])) {
            throw new \InvalidArgumentException(sprintf('Scheduled task "%s" is already registered.', $task->name));
        }
        $this->tasks[$task->name] = $task;
    }

    /**
     * @return list<ScheduledTask>
     */
    public function tasks(): array
    {
        return array_values($this->tasks);
    }

    /**
     * Enqueues tasks due in the current minute and, to tolerate a skipped
     * cron run, in the preceding $catchUpMinutes minutes.
     *
     * @return list<string> names of tasks enqueued
     */
    public function enqueueDue(int $catchUpMinutes = 2): array
    {
        $now = $this->clock->now();
        $minuteStart = $now->setTime((int) $now->format('G'), (int) $now->format('i'));
        $enqueued = [];
        $failure = null;

        for ($offset = $catchUpMinutes; $offset >= 0; --$offset) {
            $slot = $minuteStart->modify(sprintf('-%d minutes', $offset));
            foreach ($this->tasks as $task) {
                if (!$task->schedule->isDue($slot)) {
                    continue;
                }
                // Claim the slot and queue the job together: if the dispatch fails, the slot
                // stays unclaimed and the next cron run (which looks back) tries again.
                try {
                    $claimed = $this->db->transaction(function (Connection $db) use ($task, $slot, $now): bool {
                        $inserted = $db->execute(
                            'INSERT IGNORE INTO schedule_runs (task_name, slot_at, created_at) VALUES (?, ?, ?)',
                            [$task->name, $slot->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')],
                        );
                        if ($inserted !== 1) {
                            return false;
                        }
                        $this->queue->dispatch($task->jobType, $task->payload, $task->queue, maxAttempts: 3);

                        return true;
                    });
                } catch (\Throwable $e) {
                    // One broken task must not keep the others from being scheduled.
                    $failure ??= $e;
                    continue;
                }
                if ($claimed) {
                    $enqueued[] = $task->name;
                }
            }
        }
        if ($failure !== null) {
            throw $failure;
        }

        return $enqueued;
    }
}
