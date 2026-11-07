<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Forecasting;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Domain\Projects\ProjectRepository;

final readonly class ForecastReviewJob implements JobHandler
{
    public function __construct(private ProjectRepository $projects, private ForecastService $service) {}
    public function handle(array $payload, JobContext $context): void
    {
        if (!is_int($payload['workspace'] ?? null) || !is_int($payload['project'] ?? null) || !is_string($payload['publicId'] ?? null)) {
            return;
        }
        $project = $this->projects->findInWorkspace($payload['workspace'], $payload['publicId']);
        if ($project !== null && $project->id === $payload['project']) {
            $this->service->runTracking($project, $context);
        }
    }
}
