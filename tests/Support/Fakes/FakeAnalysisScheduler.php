<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Comparison\AnalysisScheduler;

final class FakeAnalysisScheduler implements AnalysisScheduler
{
    /** @var list<string> */
    public array $analyses = [];

    /** @var list<array{project_id: int, workspace_id: int, analysis_id: string}> */
    public array $refreshes = [];

    public function analyze(string $analysisId, int $workspaceId): void
    {
        $this->analyses[] = $analysisId;
    }

    public function refreshOpportunities(int $projectId, int $workspaceId, string $analysisId): void
    {
        $this->refreshes[] = ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'analysis_id' => $analysisId];
    }
}
