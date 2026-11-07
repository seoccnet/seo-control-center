<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ChangeBundles;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Domain\Projects\Project;

final readonly class QueuedBundleChecks implements BundleCheckScheduler
{
    public function __construct(private Queue $queue) {}

    public function check(Project $project, string $bundleId, string $purpose): void
    {
        $this->queue->dispatch('change_bundles.check', ['project_id' => $project->id, 'workspace_id' => $project->workspaceId, 'public_id' => $project->publicId, 'bundle_id' => $bundleId, 'purpose' => $purpose], 'analysis', maxAttempts: 2, workspaceId: $project->workspaceId);
    }
}
