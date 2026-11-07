<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\System\Jobs;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Session\SessionConfig;
use SeoControlCenter\Core\Support\Clock;

/**
 * Hourly housekeeping: expired sessions (ADR-019), rate-limit buckets,
 * expired password-reset tokens, old scheduler slots and finished jobs.
 * Deletes in bounded batches.
 */
final readonly class SystemCleanupJob implements JobHandler
{
    public const string TYPE = 'system.cleanup';

    private const int BATCH = 2000;

    public function __construct(
        private Connection $db,
        private SessionConfig $sessions,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $now = $this->clock->now();
        $fmt = static fn(\DateTimeImmutable $t): string => $t->format('Y-m-d H:i:s');
        // Each DELETE uses a range index and removes at most BATCH rows, so locks stay short.
        // A backlog (for example rate-limit buckets after a login flood) drains in repeated
        // batches until it is gone or the run's time is nearly used up.
        $deadline = time() + max(5, min(60, $context->secondsRemaining() - 10));

        $counts = [];
        foreach ([
            'sessions' => ['DELETE FROM sessions WHERE expires_at < ?', [$fmt($now)]],
            'idle_sessions' => ['DELETE FROM sessions WHERE last_activity_at < ?', [$fmt($now->modify(sprintf('-%d seconds', $this->sessions->idleTimeoutSeconds)))]],
            'rate_limit_buckets' => ['DELETE FROM rate_limit_buckets WHERE expires_at < ?', [$fmt($now)]],
            'password_resets' => ['DELETE FROM password_resets WHERE expires_at < ?', [$fmt($now->modify('-1 day'))]],
            'schedule_runs' => ['DELETE FROM schedule_runs WHERE created_at < ?', [$fmt($now->modify('-2 days'))]],
            'completed_jobs' => ["DELETE FROM jobs WHERE status = 'completed' AND finished_at < ?", [$fmt($now->modify('-7 days'))]],
            'failed_jobs' => ["DELETE FROM jobs WHERE status = 'failed' AND finished_at < ?", [$fmt($now->modify('-30 days'))]],
        ] as $name => [$sql, $params]) {
            $counts[$name] = 0;
            do {
                $deleted = $this->db->execute($sql . ' LIMIT ' . self::BATCH, $params);
                $counts[$name] += $deleted;
            } while ($deleted === self::BATCH && time() < $deadline);
        }

        $this->logger->info('System cleanup finished', $counts);
    }
}
