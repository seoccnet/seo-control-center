<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\Alerts\DigestService;

/**
 * Builds and e-mails one weekly digest.
 */
final readonly class SendDigestJob implements JobHandler
{
    public function __construct(private DigestService $service) {}

    public function handle(array $payload, JobContext $context): void
    {
        if (!is_int($payload['user'] ?? null) || !is_int($payload['workspace'] ?? null) || !is_string($payload['week'] ?? null)) {
            throw new PermanentJobException('Invalid digest payload.');
        }
        $this->service->send($payload['user'], $payload['workspace'], $payload['week']);
    }
}
