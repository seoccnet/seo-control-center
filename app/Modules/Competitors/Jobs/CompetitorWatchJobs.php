<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Competitors\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Modules\Competitors\CompetitorWatchScheduler;
use SeoControlCenter\Modules\Competitors\CompetitorWatchService;

/** Queues competitor sitemap reads (1.6.0), one job per competitor and day. */
final readonly class CompetitorWatchJobs implements CompetitorWatchScheduler
{
    public const string DISPATCH = 'competitors.watch_dispatch';
    public const string CHECK = 'competitors.watch_check';

    public function __construct(private Queue $queue) {}

    public function check(int $competitorId, int $projectId, int $workspaceId, string $domain, string $today): void
    {
        $this->queue->dispatch(self::CHECK, ['competitor_id' => $competitorId, 'project_id' => $projectId, 'workspace_id' => $workspaceId, 'domain' => $domain], 'analysis', idempotencyKey: sprintf('competitor_watch:%d:%s', $competitorId, $today), workspaceId: $workspaceId);
    }

    public static function handler(CompetitorWatchService $service, bool $dispatch): JobHandler
    {
        return new readonly class ($service, $dispatch) implements JobHandler {
            public function __construct(private CompetitorWatchService $service, private bool $dispatch) {}

            public function handle(array $payload, JobContext $context): void
            {
                if ($this->dispatch) {
                    $this->service->dispatch();

                    return;
                }
                $competitorId = $payload['competitor_id'] ?? null;
                $projectId = $payload['project_id'] ?? null;
                $workspaceId = $payload['workspace_id'] ?? null;
                $domain = $payload['domain'] ?? null;
                if (!is_int($competitorId) || !is_int($projectId) || !is_int($workspaceId) || !is_string($domain)) {
                    throw new PermanentJobException('Invalid competitor watch payload.');
                }
                $this->service->check($competitorId, $projectId, $workspaceId, $domain);
            }
        };
    }
}
