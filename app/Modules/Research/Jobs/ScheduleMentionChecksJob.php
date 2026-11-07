<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\Research\ResearchService;

/**
 * Daily: queues the weekly AI mention checks that are due.
 */
final readonly class ScheduleMentionChecksJob implements JobHandler
{
    public function __construct(private ResearchService $service) {}

    public function handle(array $payload, JobContext $context): void
    {
        $this->service->startDueMentions();
    }
}
