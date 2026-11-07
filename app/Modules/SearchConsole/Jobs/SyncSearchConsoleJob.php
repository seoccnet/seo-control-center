<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Domain\SearchConsole\ImportScheduler;
use SeoControlCenter\Domain\SearchConsole\ProjectPropertyRepository;

/**
 * Scheduled: requests an import for every project linked to a property,
 * so newly finalized days arrive without anyone opening the dashboard.
 */
final readonly class SyncSearchConsoleJob implements JobHandler
{
    public const string TYPE = 'search_console.sync';

    public function __construct(
        private ProjectPropertyRepository $links,
        private ImportScheduler $imports,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        foreach ($this->links->linkedProjects() as $project) {
            $this->imports->requestImport($project['projectId'], $project['workspaceId']);
        }
    }
}
