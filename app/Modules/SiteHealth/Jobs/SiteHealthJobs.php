<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteHealth\Jobs;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Modules\SiteHealth\SiteHealthScheduler;

/** Queues site health checks on the analysis queue. */
final readonly class SiteHealthJobs implements SiteHealthScheduler
{
    public const string DISPATCH = 'site_health.dispatch';
    public const string CHECK = 'site_health.check';

    public function __construct(private Queue $queue) {}

    public function check(int $projectId, int $workspaceId, string $domain, string $kind, string $slot): void
    {
        $this->queue->dispatch(self::CHECK, ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'domain' => $domain, 'kind' => $kind], 'analysis', idempotencyKey: sprintf('site_health:%d:%s:%s', $projectId, $kind, $slot), workspaceId: $workspaceId);
    }
}
