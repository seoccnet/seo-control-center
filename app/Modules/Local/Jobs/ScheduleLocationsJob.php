<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\Local\LocationService;

final readonly class ScheduleLocationsJob implements JobHandler
{
    public function __construct(private LocationService $service) {}
    public function handle(array $payload, JobContext $context): void
    {
        $this->service->startDue();
    }
}
