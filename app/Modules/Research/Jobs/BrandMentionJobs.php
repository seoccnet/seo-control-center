<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research\Jobs;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Research\BrandMentionScheduler;

final readonly class BrandMentionJobs implements BrandMentionScheduler
{
    public const string FETCH = 'research.brand.fetch';
    public const string SCHEDULE = 'research.brand.schedule';
    public function __construct(private Queue $queue) {}
    public function fetch(Project $project, string $checkId, int $step): void
    {
        $this->queue->dispatch(self::FETCH, ['workspace_id' => $project->workspaceId, 'project' => $project->publicId, 'check' => $checkId, 'step' => $step], 'analysis', maxAttempts: 1, idempotencyKey: 'brand:' . $checkId . ':' . $step, workspaceId: $project->workspaceId);
    }
}
