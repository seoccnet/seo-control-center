<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\IndexNow\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\IndexNow\IndexNowService;

/**
 * Submits a list of URLs a user entered.
 */
final readonly class SubmitIndexNowJob implements JobHandler
{
    public function __construct(private IndexNowService $service) {}

    public function handle(array $payload, JobContext $context): void
    {
        $projectId = $payload['project_id'] ?? null;
        $urls = $payload['urls'] ?? null;
        if (!is_int($projectId) || !is_array($urls)) {
            throw new PermanentJobException('Invalid IndexNow payload.');
        }
        $userId = is_int($payload['user_id'] ?? null) ? $payload['user_id'] : null;
        $this->service->runSubmit($projectId, array_values(array_filter($urls, is_string(...))), 'manual', $userId);
    }
}
