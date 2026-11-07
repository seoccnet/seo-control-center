<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;

/**
 * Every 6 hours: queues an analysis per project (after new Search Console
 * days and rank checks have arrived). One job per project and window.
 */
final readonly class ScheduleOpportunityAnalysisJob implements JobHandler
{
    public const string TYPE = 'opportunities.schedule';
    private const int WINDOW_SECONDS = 6 * 3600;

    public function __construct(
        private OpportunityRepository $opportunities,
        private Queue $queue,
        private Clock $clock,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $window = intdiv($this->clock->now()->getTimestamp(), self::WINDOW_SECONDS);
        foreach ($this->opportunities->projectsToAnalyze() as $project) {
            $this->queue->dispatch(
                AnalyzeOpportunitiesJob::TYPE,
                $project,
                AnalyzeOpportunitiesJob::QUEUE,
                idempotencyKey: sprintf('opportunities.analyze:%d:%d', $project['project_id'], $window),
                workspaceId: $project['workspace_id'],
            );
        }
    }
}
