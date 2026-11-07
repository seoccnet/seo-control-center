<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Comparison;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Domain\Comparison\AnalysisScheduler;
use SeoControlCenter\Modules\Opportunities\Jobs\AnalyzeOpportunitiesJob;

final readonly class QueuedAnalysisScheduler implements AnalysisScheduler
{
    public function __construct(private Queue $queue) {}

    public function analyze(string $analysisId, int $workspaceId): void
    {
        $this->queue->dispatch(ComparisonService::JOB, ['analysis_id' => $analysisId], 'analysis', idempotencyKey: 'comparison:' . $analysisId, workspaceId: $workspaceId);
    }

    public function refreshOpportunities(int $projectId, int $workspaceId, string $analysisId): void
    {
        $this->queue->dispatch(AnalyzeOpportunitiesJob::TYPE, ['project_id' => $projectId, 'workspace_id' => $workspaceId], AnalyzeOpportunitiesJob::QUEUE, idempotencyKey: 'opportunities.analyze:comparison:' . $analysisId, workspaceId: $workspaceId);
    }
}
