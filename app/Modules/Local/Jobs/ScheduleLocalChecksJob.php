<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\Local\LocalService;

/**
 * Daily: queues the weekly local checks that are due.
 */
final readonly class ScheduleLocalChecksJob implements JobHandler
{
    public function __construct(private LocalService $service) {}

    public function handle(array $payload, JobContext $context): void
    {
        $this->service->startDue();
    }
}
