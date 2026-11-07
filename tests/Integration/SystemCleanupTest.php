<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use DateTimeImmutable;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Database\Migrations\Migrator;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Session\SessionConfig;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Modules\System\Jobs\SystemCleanupJob;

/**
 * QUEUE_AND_CRON.md -> system.cleanup: bounded batches that still drain a backlog.
 */
final class SystemCleanupTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, [dirname(__DIR__, 2) . '/database/migrations'], new NullLogger()))->migrate();
    }

    public function testDrainsABacklogLargerThanOneBatchAndKeepsLiveRows(): void
    {
        // A login flood leaves thousands of expired buckets; one hourly run must clear them all.
        $values = [];
        for ($i = 0; $i < 4500; ++$i) {
            $values[] = sprintf("('%s', 1, '2026-10-10 08:00:00', '2026-10-10 08:10:00')", hash('sha256', 'old' . $i));
        }
        $this->db->statement('INSERT INTO rate_limit_buckets (bucket_key, hits, window_start, expires_at) VALUES ' . implode(', ', $values));
        $this->db->execute(
            "INSERT INTO rate_limit_buckets (bucket_key, hits, window_start, expires_at) VALUES (?, 1, '2026-10-10 09:59:00', '2026-10-10 10:09:00')",
            [hash('sha256', 'live')],
        );
        // An idle session (no activity for longer than the idle timeout) that has not reached its absolute expiry.
        $this->db->execute(
            "INSERT INTO sessions (id, user_id, data, created_at, last_activity_at, expires_at)
             VALUES (?, NULL, '{}', '2026-10-10 06:00:00', '2026-10-10 07:00:00', '2026-10-17 06:00:00')",
            [str_repeat('b', 64)],
        );

        $job = new SystemCleanupJob($this->db, new SessionConfig(), new FrozenClock(new DateTimeImmutable('2026-10-10 10:00:00 UTC')), new NullLogger());
        $job->handle([], new JobContext('job', SystemCleanupJob::TYPE, 1, 3, static fn(): bool => true, time() + 50));

        self::assertSame([hash('sha256', 'live')], array_column($this->db->fetchAll('SELECT bucket_key FROM rate_limit_buckets'), 'bucket_key'));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sessions'));
    }
}
