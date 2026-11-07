<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\Alerts\DigestService;

/**
 * Scheduled (daily, acts on Mondays): queues the weekly digests.
 */
final readonly class DispatchDigestsJob implements JobHandler
{
    public function __construct(private DigestService $service) {}

    public function handle(array $payload, JobContext $context): void
    {
        $this->service->dispatchDue();
    }
}
