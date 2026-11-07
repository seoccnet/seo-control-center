<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Forecasting;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\Clock;

final readonly class ForecastDispatchJob implements JobHandler
{
    public function __construct(private ForecastRepository $repository, private Queue $queue, private Clock $clock) {}
    public function handle(array $payload, JobContext $context): void
    {
        $date = $this->clock->now()->format('Y-m-d');
        foreach ($this->repository->dueProjects($date) as $project) {
            if ($context->secondsRemaining() < 10) {
                return;
            }
            $this->queue->dispatch('forecast.review', $project, 'analysis', idempotencyKey: 'forecast.review:' . $project['workspace'] . ':' . $project['project'] . ':' . $date, workspaceId: $project['workspace']);
        }
    }
}
