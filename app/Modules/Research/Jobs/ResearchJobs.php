<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research\Jobs;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Modules\Research\ResearchScheduler;

/**
 * Queues research lookups on the analysis queue.
 */
final readonly class ResearchJobs implements ResearchScheduler
{
    public const string FETCH = 'research.fetch';
    public const string SCHEDULE_MENTIONS = 'research.mentions';

    public function __construct(private Queue $queue) {}

    public function fetch(int $projectId, int $workspaceId, string $kind, string $subject): void
    {
        $this->queue->dispatch(self::FETCH, ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'kind' => $kind, 'subject' => $subject], 'analysis', workspaceId: $workspaceId);
    }
}
