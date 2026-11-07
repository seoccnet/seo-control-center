<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Indexing\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\Indexing\IndexingService;

final readonly class ScheduleInspectionsJob implements JobHandler
{
    public function __construct(private IndexingService $service) {}
    public function handle(array $payload, JobContext $context): void
    {
        $this->service->schedule();
    }
}
