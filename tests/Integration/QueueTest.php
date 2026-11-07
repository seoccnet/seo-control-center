<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use DateTimeImmutable;
use Psr\Log\NullLogger;
use RuntimeException;
use SeoControlCenter\Core\Database\Migrations\Migrator;
use SeoControlCenter\Core\Logging\LogContext;
use SeoControlCenter\Core\Logging\Redactor;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\PdoQueueMonitor;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Queue\ScheduledTask;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\Queue\Worker;
use SeoControlCenter\Core\Support\FrozenClock;

/**
 * TESTING.md -> Queue: no double claiming, retries, failures, idempotency.
 */
final class QueueTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private JobRegistry $registry;

    private Queue $queue;

    /** @var list<string> */
    public static array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, [dirname(__DIR__, 2) . '/database/migrations'], new NullLogger()))->migrate();
        self::$ran = [];
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-09-26 10:00:00 UTC'));
        $this->registry = new JobRegistry();
        $this->registry->register('test.ok', static fn(): JobHandler => new class implements JobHandler {
            public function handle(array $payload, JobContext $context): void
            {
                QueueTest::$ran[] = (string) ($payload['name'] ?? '');
            }
        });
        $this->registry->register('test.flaky', static fn(): JobHandler => new class implements JobHandler {
            public function handle(array $payload, JobContext $context): void
            {
                throw new RuntimeException('temporary outage, token=secret123');
            }
        });
        $this->registry->register('test.permanent', static fn(): JobHandler => new class implements JobHandler {
            public function handle(array $payload, JobContext $context): void
            {
                throw new PermanentJobException('invalid credentials');
            }
        });
        $this->queue = new Queue($this->db, $this->registry, $this->clock);
    }

    public function testRunsJobsByQueuePriorityThenFifo(): void
    {
        $this->queue->dispatch('test.ok', ['name' => 'default-low'], 'default');
        $this->queue->dispatch('test.ok', ['name' => 'default-high'], 'default', priority: 10);
        $this->queue->dispatch('test.ok', ['name' => 'high-queue'], 'high');

        self::assertSame(3, $this->worker()->work(Queue::QUEUES, 10));
        self::assertSame(['high-queue', 'default-high', 'default-low'], self::$ran);
        self::assertSame(3, $this->queue->stats()['completed']);
    }

    public function testDelayedJobsWait(): void
    {
        $this->queue->dispatch('test.ok', ['name' => 'later'], delaySeconds: 60);
        self::assertFalse($this->worker()->runNext(Queue::QUEUES));

        $this->clock->advance('+61 seconds');
        self::assertTrue($this->worker()->runNext(Queue::QUEUES));
    }

    public function testIdempotencyKeyPreventsDuplicates(): void
    {
        $a = $this->queue->dispatch('test.ok', [], idempotencyKey: 'dfs:metrics:p1:2026-09-26');
        $b = $this->queue->dispatch('test.ok', [], idempotencyKey: 'dfs:metrics:p1:2026-09-26');

        self::assertSame($a, $b);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM jobs'));
    }

    public function testRetryWithBackoffThenFail(): void
    {
        $this->queue->dispatch('test.flaky', maxAttempts: 2);
        $worker = $this->worker();

        $worker->runNext(Queue::QUEUES);
        $row = $this->db->fetchOne('SELECT status, attempts, available_at, last_error FROM jobs');
        self::assertSame('queued', $row['status'] ?? null);
        self::assertSame('2026-09-26 10:00:30', $row['available_at'] ?? null);
        self::assertStringNotContainsString('secret123', (string) ($row['last_error'] ?? ''), 'Errors are redacted.');

        self::assertFalse($worker->runNext(Queue::QUEUES), 'Not before the backoff.');
        $this->clock->advance('+31 seconds');
        $worker->runNext(Queue::QUEUES);
        self::assertSame('failed', $this->db->fetchValue('SELECT status FROM jobs'));
    }

    public function testPermanentFailureIsNotRetried(): void
    {
        $this->queue->dispatch('test.permanent', maxAttempts: 5);
        $this->worker()->runNext(Queue::QUEUES);

        self::assertSame(['failed', 1], array_values($this->db->fetchOne('SELECT status, attempts FROM jobs') ?? []));
    }

    public function testQueueMonitorListsRetriesAndDismissesFailedJobs(): void
    {
        $first = $this->queue->dispatch('test.permanent');
        $second = $this->queue->dispatch('test.permanent');
        $this->queue->dispatch('test.ok', ['name' => 'x'], delaySeconds: 3600);
        $this->worker()->runNext(Queue::QUEUES);
        $this->worker()->runNext(Queue::QUEUES);
        $monitor = new PdoQueueMonitor($this->db, $this->clock);

        self::assertSame(['queued' => 1, 'running' => 0, 'completed' => 0, 'failed' => 2], $monitor->stats());
        self::assertSame(2, $monitor->failedCount());
        $failed = $monitor->failed();
        self::assertCount(2, $failed);
        self::assertSame('test.permanent', $failed[0]->type);
        self::assertStringContainsString('invalid credentials', (string) $failed[0]->error);

        self::assertTrue($monitor->retry($first));
        self::assertFalse($monitor->retry($first), 'Only failed jobs can be retried.');
        self::assertSame(['queued', 0, null], array_values($this->db->fetchOne('SELECT status, attempts, finished_at FROM jobs WHERE public_id = ?', [$first]) ?? []));
        self::assertTrue($monitor->dismiss($second));
        self::assertFalse($monitor->dismiss($first), 'Queued jobs cannot be dismissed.');
        self::assertSame(0, $monitor->failedCount());

        $this->worker()->runNext(Queue::QUEUES);
        self::assertSame(1, $monitor->retryAll());
    }

    public function testJobsOfAFeatureTurnedOffAreSkippedNotFailed(): void
    {
        $this->registry->requireFeature('test.ok', \SeoControlCenter\Core\Features\Feature::RankTracking);
        $features = new \SeoControlCenter\Tests\Support\Fakes\FixedFeatures(['rank_tracking']);
        $worker = new Worker($this->db, $this->registry, $this->clock, new NullLogger(), new LogContext(), new Redactor(), 120, new \SeoControlCenter\Core\Features\FeatureManager($features));
        $this->queue->dispatch('test.ok', ['name' => 'skipped']);
        $worker->runNext(Queue::QUEUES);

        self::assertSame([], self::$ran);
        self::assertSame('completed', $this->db->fetchValue('SELECT status FROM jobs'));

        $features->off = [];
        $this->queue->dispatch('test.ok', ['name' => 'runs']);
        $worker->runNext(Queue::QUEUES);
        self::assertSame(['runs'], self::$ran);
    }

    public function testTwoWorkersNeverClaimTheSameJob(): void
    {
        $this->queue->dispatch('test.ok', ['name' => 'one']);
        $this->queue->dispatch('test.ok', ['name' => 'two']);

        // Worker A holds a row lock on the first job (as if mid-claim).
        $other = $this->newConnection();
        $other->pdo()->beginTransaction();
        $locked = $other->fetchValue("SELECT id FROM jobs WHERE status = 'queued' ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED");

        // Worker B skips the locked row and takes the other one.
        self::assertTrue($this->worker()->runNext(Queue::QUEUES));
        self::assertSame(['two'], self::$ran);
        $other->pdo()->rollBack();

        self::assertSame(1, (int) $locked);
    }

    public function testAClaimLocksOnlyTheJobItTakes(): void
    {
        // Worker A runs its real claim inside a transaction that stays open while its handler runs,
        // so A's claim locks are still held when worker B polls. A claim that sorted and locked every
        // due job would leave B with nothing to do.
        $other = $this->newConnection();
        $workerA = new Worker($other, $this->registry, $this->clock, new NullLogger(), new LogContext(), new Redactor());
        $workerB = $this->worker();
        $this->registry->register('test.nested', static fn(): JobHandler => new class ($workerB) implements JobHandler {
            public function __construct(private readonly Worker $other) {}

            public function handle(array $payload, JobContext $context): void
            {
                QueueTest::$ran[] = 'a';
                QueueTest::$ran[] = $this->other->runNext(Queue::QUEUES) ? 'b-claimed' : 'b-starved';
            }
        });
        $this->queue->dispatch('test.nested');
        $this->queue->dispatch('test.ok', ['name' => 'two']);
        $this->queue->dispatch('test.ok', ['name' => 'three'], 'imports');

        $other->transaction(static fn(): bool => $workerA->runNext(Queue::QUEUES));

        self::assertSame(['a', 'two', 'b-claimed'], self::$ran);
        $other->disconnect();
    }

    public function testWorkerStopsWhenItsSlotLeaseIsLost(): void
    {
        $this->queue->dispatch('test.ok', ['name' => 'one']);
        $this->queue->dispatch('test.ok', ['name' => 'two']);

        $processed = $this->worker()->work(Queue::QUEUES, 30, keepAlive: static fn(): bool => false);

        self::assertSame(1, $processed);
        self::assertSame(['one'], self::$ran);
    }

    public function testFailedDispatchLeavesTheSlotForTheNextRunAndOtherTasksStillRun(): void
    {
        $scheduler = new Scheduler($this->db, $this->queue, $this->clock);
        $scheduler->add(new ScheduledTask('test.broken', '* * * * *', 'test.unregistered'));
        $scheduler->add(new ScheduledTask('test.fine', '* * * * *', 'test.ok'));

        try {
            $scheduler->enqueueDue(0);
            self::fail('The dispatch failure must be reported.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(['test.fine'], array_column($this->db->fetchAll('SELECT task_name FROM schedule_runs'), 'task_name'));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM jobs'));
    }

    public function testExpiredReservationIsReleasedAndStaleWorkerCannotOverwrite(): void
    {
        $this->queue->dispatch('test.ok', ['name' => 'x']);
        // Simulate a crashed worker: running with an expired lease.
        $this->db->execute("UPDATE jobs SET status = 'running', attempts = 1, reservation_token = 'dead', reserved_until = '2026-09-26 09:00:00'");

        self::assertSame(1, $this->worker()->releaseExpiredReservations());
        self::assertSame('queued', $this->db->fetchValue('SELECT status FROM jobs'));
        self::assertTrue($this->worker()->runNext(Queue::QUEUES));
        self::assertSame('completed', $this->db->fetchValue('SELECT status FROM jobs'));
    }

    public function testSchedulerEnqueuesEachSlotOnce(): void
    {
        $scheduler = new Scheduler($this->db, $this->queue, $this->clock);
        $scheduler->add(new ScheduledTask('test.every-minute', '* * * * *', 'test.ok'));

        $first = $scheduler->enqueueDue(0);
        $second = $scheduler->enqueueDue(0); // overlapping cron run, same minute
        $overlapping = (new Scheduler($this->db, $this->queue, $this->clock));
        $overlapping->add(new ScheduledTask('test.every-minute', '* * * * *', 'test.ok'));
        $third = $overlapping->enqueueDue(0);

        self::assertSame(['test.every-minute'], $first);
        self::assertSame([], $second);
        self::assertSame([], $third);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM jobs'));

        $this->clock->advance('+1 minute');
        self::assertSame(['test.every-minute'], $scheduler->enqueueDue(0));
    }

    public function testLeaseRenewalRightAfterTheClaimSucceeds(): void
    {
        // A handler that checks renewLease() immediately (same second as the claim) must not be
        // told it lost its reservation; otherwise it re-dispatches itself without doing any work.
        $this->registry->register('test.renew', static fn(): JobHandler => new class implements JobHandler {
            public function handle(array $payload, JobContext $context): void
            {
                QueueTest::$ran[] = $context->renewLease() ? 'renewed' : 'lost';
                QueueTest::$ran[] = $context->renewLease() ? 'renewed' : 'lost';
            }
        });
        $this->queue->dispatch('test.renew');

        $this->worker()->work(Queue::QUEUES, 30);

        self::assertSame(['renewed', 'renewed'], self::$ran);
    }

    public function testFiveMinuteCronCatchesUpTasksBetweenRuns(): void
    {
        // Shared hosts often run cron every 5 minutes; a task at :41 falls between runs at :40 and :45.
        $scheduler = new Scheduler($this->db, $this->queue, $this->clock);
        $scheduler->add(new ScheduledTask('test.at-41', '41 * * * *', 'test.ok'));

        $this->clock->advance('+40 minutes'); // 10:40
        self::assertSame([], $scheduler->enqueueDue(10));
        $this->clock->advance('+5 minutes');  // 10:45
        self::assertSame([], $scheduler->enqueueDue(2), 'A 2-minute look-back misses the slot.');
        self::assertSame(['test.at-41'], $scheduler->enqueueDue(10), 'Two intervals of look-back find it.');
        $this->clock->advance('+5 minutes');  // 10:50: the slot is still in the window but already claimed
        self::assertSame([], $scheduler->enqueueDue(10));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM jobs'));
    }

    private function worker(): Worker
    {
        return new Worker($this->db, $this->registry, $this->clock, new NullLogger(), new LogContext(), new Redactor());
    }
}
