<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\Bing\BingDataRepository;
use SeoControlCenter\Modules\Bing\BingImportScheduler;

/**
 * Scheduled: requests an import for every project linked to a Bing site.
 */
final readonly class SyncBingJob implements JobHandler
{
    public const string TYPE = 'bing.sync';

    public function __construct(
        private BingDataRepository $data,
        private BingImportScheduler $imports,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        foreach ($this->data->linkedProjects() as $project) {
            $this->imports->requestImport($project['projectId'], $project['workspaceId']);
        }
    }
}
