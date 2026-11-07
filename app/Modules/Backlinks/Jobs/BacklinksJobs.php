<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks\Jobs;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Modules\Backlinks\BacklinksScheduler;

/**
 * Queues backlink lookups on the analysis queue.
 */
final readonly class BacklinksJobs implements BacklinksScheduler
{
    public const string FETCH = 'backlinks.fetch';
    public const string SCHEDULE = 'backlinks.schedule';

    public function __construct(private Queue $queue) {}

    public function fetch(int $projectId, int $workspaceId, string $kind): void
    {
        $this->queue->dispatch(self::FETCH, ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'kind' => $kind], 'analysis', workspaceId: $workspaceId);
    }
}
