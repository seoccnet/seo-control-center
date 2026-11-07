<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\AiVisibility\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\AiVisibility\AiCrawlerService;

/**
 * Daily: queues the AI crawler check of every project not checked this week.
 */
final readonly class DispatchAiCrawlerChecksJob implements JobHandler
{
    public function __construct(private AiCrawlerService $service) {}

    public function handle(array $payload, JobContext $context): void
    {
        $this->service->dispatchDue();
    }
}
