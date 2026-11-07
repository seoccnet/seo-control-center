<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Rankings\RankCheckScheduler;
use SeoControlCenter\Domain\Rankings\RankingRepository;

/**
 * Hourly: queues a check for every project with tracking switched on whose
 * next check is due today (UTC). A day that is still incomplete (time
 * budget, retryable error, budget raised later) is picked up again.
 */
final readonly class ScheduleRankChecksJob implements JobHandler
{
    public const string TYPE = 'rankings.schedule';

    public function __construct(
        private RankingRepository $rankings,
        private RankCheckScheduler $scheduler,
        private Clock $clock,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $today = $this->clock->now()->format('Y-m-d');
        foreach ($this->rankings->dueProjects($today) as $project) {
            $this->scheduler->requestCheck($project['project_id'], $project['workspace_id'], $today);
        }
    }
}
