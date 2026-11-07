<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Queue;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Logging\LogContext;
use SeoControlCenter\Core\Logging\Redactor;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use Throwable;

/**
 * Claims and runs jobs for a bounded time (QUEUE_AND_CRON.md -> Atomic job claiming).
 */
final readonly class Worker
{
    public function __construct(
        private Connection $db,
        private JobRegistry $registry,
        private Clock $clock,
        private LoggerInterface $logger,
        private LogContext $logContext,
        private Redactor $redactor,
        private int $leaseSeconds = 120,
        private ?FeatureManager $features = null,
    ) {}

    /**
     * Runs jobs until the time, job-count or memory budget is used up.
     *
     * @param list<string> $queues in priority order
     * @param (\Closure(): bool)|null $keepAlive called between jobs (renews the worker slot); false stops the run
     * @return int number of jobs processed
     */
    public function work(array $queues, int $maxSeconds, int $maxJobs = 100, ?int $memoryLimitBytes = null, ?\Closure $keepAlive = null): int
    {
        $deadline = time() + $maxSeconds;
        $processed = 0;
        while ($processed < $maxJobs && time() < $deadline) {
            if ($memoryLimitBytes !== null && memory_get_usage(true) > $memoryLimitBytes) {
                $this->logger->notice('Worker stopping: soft memory limit reached');
                break;
            }
            if ($processed > 0 && $keepAlive !== null && !$keepAlive()) {
                $this->logger->warning('Worker stopping: its worker slot lease was lost');
                break;
            }
            if (!$this->runNext($queues, $deadline)) {
                break; // nothing to do
            }
            ++$processed;
        }

        return $processed;
    }

    /**
     * Claims and runs one job. Returns false when no job is available.
     *
     * @param list<string> $queues
     */
    public function runNext(array $queues, ?int $deadline = null): bool
    {
        $claimed = $this->claim($queues);
        if ($claimed === null) {
            return false;
        }
        [$id, $publicId, $type, $payload, $attempt, $maxAttempts, $token] = $claimed;

        $this->logContext->set('job_id', $publicId);
        $context = new JobContext(
            $publicId,
            $type,
            $attempt,
            $maxAttempts,
            fn(): bool => $this->renew($id, $token),
            $deadline ?? time() + $this->leaseSeconds,
        );

        $feature = $this->registry->requiredFeature($type);
        if ($feature !== null && $this->features !== null && !$this->features->enabled($feature)) {
            // Not a failure: the work resumes by itself once the feature is on again.
            $this->logger->info('Job {type} skipped: {feature} is turned off on this installation', ['type' => $type, 'feature' => $feature->value]);
            $this->finish($id, $token, 'completed', null, null);
            $this->logContext->remove('job_id');

            return true;
        }

        try {
            $payloadData = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
            $this->registry->handler($type)->handle(is_array($payloadData) ? $payloadData : [], $context);
            $this->finish($id, $token, 'completed', null, null);
        } catch (PermanentJobException $e) {
            $this->logger->error('Job {type} failed permanently', ['type' => $type, 'exception' => $e]);
            $this->finish($id, $token, 'failed', $e, null);
        } catch (Throwable $e) {
            if ($attempt >= $maxAttempts) {
                $this->logger->error('Job {type} failed after {attempts} attempts', ['type' => $type, 'attempts' => $attempt, 'exception' => $e]);
                $this->finish($id, $token, 'failed', $e, null);
            } else {
                $delay = self::backoffSeconds($attempt, $e);
                $this->logger->warning('Job {type} failed, retrying in {delay}s', ['type' => $type, 'delay' => $delay, 'exception' => $e]);
                $this->finish($id, $token, 'queued', $e, $delay);
            }
        } finally {
            $this->logContext->remove('job_id');
        }

        return true;
    }

    /**
     * Returns crashed or timed-out jobs to the queue (or fails them when
     * attempts are exhausted). Called by the cron runner before working.
     */
    public function releaseExpiredReservations(): int
    {
        $now = $this->now();

        return $this->db->execute(
            "UPDATE jobs
                SET status = IF(attempts >= max_attempts, 'failed', 'queued'),
                    finished_at = IF(attempts >= max_attempts, ?, NULL),
                    last_error = 'Reservation expired (worker stopped or timed out).',
                    reservation_token = NULL, reserved_until = NULL, updated_at = ?
              WHERE status = 'running' AND reserved_until < ?",
            [$now, $now, $now],
        );
    }

    public static function backoffSeconds(int $attempt, ?Throwable $error = null): int
    {
        $backoff = min(3600, 30 * (2 ** max(0, $attempt - 1)));
        // A provider's Retry-After is a minimum wait, not a replacement for backoff.
        return max($backoff, $error instanceof IntegrationException ? ($error->retryAfterSeconds ?? 0) : 0);
    }

    /**
     * @param list<string> $queues
     * @return array{0: int, 1: string, 2: string, 3: string, 4: int, 5: int, 6: string}|null
     */
    private function claim(array $queues): ?array
    {
        if ($queues === []) {
            return null;
        }
        $now = $this->now();
        foreach ($this->candidates($queues, $now) as [$queue, $priority]) {
            $claimed = $this->claimFrom($queue, $priority);
            if ($claimed !== null) {
                return $claimed;
            }
        }

        return null;
    }

    /**
     * Queue/priority pairs with a due job, in claim order (queue order, then
     * priority). One loose index scan without locks, so an empty poll is cheap.
     *
     * @param list<string> $queues
     * @return list<array{0: string, 1: int}>
     */
    private function candidates(array $queues, string $now): array
    {
        $placeholders = implode(', ', array_fill(0, count($queues), '?'));
        $rows = $this->db->fetchAll(
            "SELECT queue, priority, MIN(available_at) AS due
               FROM jobs
              WHERE status = 'queued' AND queue IN ({$placeholders})
              GROUP BY queue, priority",
            $queues,
        );
        $rank = array_flip($queues);
        $candidates = [];
        foreach ($rows as $row) {
            if ((string) $row['due'] <= $now && isset($rank[(string) $row['queue']])) {
                $candidates[] = [(string) $row['queue'], (int) $row['priority']];
            }
        }
        usort($candidates, static fn(array $a, array $b): int => [$rank[$a[0]], -$a[1]] <=> [$rank[$b[0]], -$b[1]]);

        return $candidates;
    }

    /**
     * Claims the oldest due job of one queue and priority. Equality on every
     * leading column of jobs_claim_order means the row is read in index order
     * and only that row is locked; rows another worker holds are skipped.
     *
     * @return array{0: int, 1: string, 2: string, 3: string, 4: int, 5: int, 6: string}|null
     */
    private function claimFrom(string $queue, int $priority): ?array
    {
        $token = bin2hex(random_bytes(16));

        return $this->db->transaction(function (Connection $db) use ($queue, $priority, $token): ?array {
            $now = $this->now();
            $row = $db->fetchOne(
                "SELECT id, public_id, type, payload, attempts, max_attempts
                   FROM jobs
                  WHERE status = 'queued' AND queue = ? AND priority = ? AND available_at <= ?
                  ORDER BY available_at, id
                  LIMIT 1
                  FOR UPDATE SKIP LOCKED",
                [$queue, $priority, $now],
            );
            if ($row === null) {
                return null;
            }
            $attempt = (int) $row['attempts'] + 1;
            $db->execute(
                "UPDATE jobs SET status = 'running', attempts = ?, reservation_token = ?, reserved_until = ?,
                        started_at = ?, updated_at = ?
                  WHERE id = ?",
                [$attempt, $token, $this->now($this->leaseSeconds), $now, $now, (int) $row['id']],
            );

            return [(int) $row['id'], (string) $row['public_id'], (string) $row['type'], (string) $row['payload'], $attempt, (int) $row['max_attempts'], $token];
        });
    }

    private function renew(int $id, string $token): bool
    {
        $this->db->execute(
            "UPDATE jobs SET reserved_until = ?, updated_at = ? WHERE id = ? AND reservation_token = ? AND status = 'running'",
            [$this->now($this->leaseSeconds), $this->now(), $id, $token],
        );

        // MySQL reports 0 affected rows when the values did not change (a renewal within the
        // same second as the claim), so verify the reservation explicitly, like LeaseLock::renew().
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM jobs WHERE id = ? AND reservation_token = ? AND status = 'running'",
            [$id, $token],
        ) === 1;
    }

    private function finish(int $id, string $token, string $status, ?Throwable $error, ?int $retryDelay): void
    {
        $message = $error !== null
            ? mb_substr($this->redactor->redactString($error::class . ': ' . $error->getMessage()), 0, 1000)
            : null;

        // Only the worker still holding the reservation may record the result.
        $this->db->execute(
            'UPDATE jobs
                SET status = ?, last_error = ?, reservation_token = NULL, reserved_until = NULL,
                    available_at = COALESCE(?, available_at), finished_at = ?, updated_at = ?
              WHERE id = ? AND reservation_token = ?',
            [
                $status,
                $message,
                $retryDelay !== null ? $this->now($retryDelay) : null,
                $status === 'queued' ? null : $this->now(),
                $this->now(),
                $id,
                $token,
            ],
        );
    }

    private function now(int $plusSeconds = 0): string
    {
        return $this->clock->now()->modify(sprintf('+%d seconds', $plusSeconds))->format('Y-m-d H:i:s');
    }
}
