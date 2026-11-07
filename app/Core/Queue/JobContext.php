<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Queue;

/**
 * Information about the running job, plus a way to extend the lease for
 * handlers that do several bounded steps.
 */
final readonly class JobContext
{
    /**
     * @param \Closure(): bool $renew
     */
    public function __construct(
        public string $jobId,
        public string $type,
        public int $attempt,
        public int $maxAttempts,
        private \Closure $renew,
        private int $deadline,
    ) {}

    /**
     * Extends the reservation. Returns false if the job was taken over.
     */
    public function renewLease(): bool
    {
        return ($this->renew)();
    }

    /**
     * Seconds left in this worker run; handlers should stop and re-dispatch
     * a continuation before it reaches zero.
     */
    public function secondsRemaining(): int
    {
        return max(0, $this->deadline - time());
    }
}
