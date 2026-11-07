<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\System\Jobs;

use SeoControlCenter\Core\Crypto\SecretRotator;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\Queue;

/**
 * Re-encrypts stored secrets with the active key after a key rotation
 * (SECURITY.md -> Rotation). Works in batches and re-dispatches itself
 * until nothing is left.
 */
final readonly class ReencryptSecretsJob implements JobHandler
{
    public const string TYPE = 'crypto.reencrypt';

    public function __construct(
        private SecretRotator $rotator,
        private Queue $queue,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        do {
            $done = $this->rotator->rotateBatch(200);
            $context->renewLease();
        } while ($done > 0 && $context->secondsRemaining() > 10);

        if ($done > 0) {
            $this->queue->dispatch(self::TYPE, [], 'cleanup');
        }
    }
}
