<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\Backlinks\BacklinksService;

/**
 * Daily: queues the monthly link profile checks that are due.
 */
final readonly class ScheduleBacklinkChecksJob implements JobHandler
{
    public function __construct(private BacklinksService $service) {}

    public function handle(array $payload, JobContext $context): void
    {
        $this->service->startDue();
    }
}
