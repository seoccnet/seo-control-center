<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\Reports\ReportService;

/**
 * Scheduled (hourly): queues every report whose period is due.
 */
final readonly class DispatchReportsJob implements JobHandler
{
    public function __construct(private ReportService $service) {}

    public function handle(array $payload, JobContext $context): void
    {
        $this->service->dispatchDue();
    }
}
