<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\Alerts\AlertService;

/**
 * Scheduled (hourly): records new alerts and queues their e-mails.
 */
final readonly class ScanAlertsJob implements JobHandler
{
    public function __construct(private AlertService $service) {}

    public function handle(array $payload, JobContext $context): void
    {
        $this->service->scan();
    }
}
