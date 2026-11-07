<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\Opportunities\OpportunityAnalyzer;

/**
 * Recalculates one project's opportunities from stored data.
 */
final readonly class AnalyzeOpportunitiesJob implements JobHandler
{
    public const string TYPE = 'opportunities.analyze';
    public const string QUEUE = 'analysis';

    public function __construct(private OpportunityAnalyzer $analyzer) {}

    public function handle(array $payload, JobContext $context): void
    {
        $projectId = $payload['project_id'] ?? null;
        $workspaceId = $payload['workspace_id'] ?? null;
        if (!is_int($projectId) || $projectId < 1 || !is_int($workspaceId) || $workspaceId < 1) {
            throw new PermanentJobException('Invalid project_id or workspace_id.');
        }
        $this->analyzer->analyze($projectId, $workspaceId);
    }
}
