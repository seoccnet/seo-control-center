<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\AiVisibility\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\AiVisibility\AiCrawlerService;

/**
 * One project's robots.txt and llms.txt check (0.43.0).
 */
final readonly class CheckAiCrawlersJob implements JobHandler
{
    public function __construct(private AiCrawlerService $service) {}

    public function handle(array $payload, JobContext $context): void
    {
        if (!is_int($payload['project_id'] ?? null) || !is_int($payload['workspace_id'] ?? null) || !is_string($payload['project_public_id'] ?? null)) {
            throw new PermanentJobException('Invalid AI crawler check payload.');
        }
        $this->service->run($payload['project_id'], $payload['workspace_id'], $payload['project_public_id']);
    }
}
