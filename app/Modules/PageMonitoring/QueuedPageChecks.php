<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\PageMonitoring;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Projects\Project;

/**
 * Queues an immediate page check with the same job as the weekly schedule.
 */
final readonly class QueuedPageChecks implements PageCheckScheduler
{
    public function __construct(private PageMonitoringRepository $data, private Queue $queue, private Clock $clock) {}

    public function checkNow(Project $project, string $url): void
    {
        $watch = $this->data->find($project->id, $url);
        if ($watch === null) {
            return;
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $generation = (string) $watch['generation'];
        // One immediate check per watch and hour, whatever triggers it.
        $this->queue->dispatch('page_monitoring.check', ['project_id' => $project->id, 'workspace_id' => $project->workspaceId, 'public_id' => $project->publicId, 'url' => $url, 'generation' => $generation], 'analysis', idempotencyKey: 'page-watch:' . $generation . ':now:' . $this->clock->now()->format('YmdH'), workspaceId: $project->workspaceId);
        $this->data->attempted($project->id, $url, $generation, $now, null);
    }
}
