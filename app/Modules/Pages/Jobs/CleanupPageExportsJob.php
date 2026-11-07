<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\Pages\PageExportService;

/** Hourly: removes expired Page library exports and fails stalled ones. */
final readonly class CleanupPageExportsJob implements JobHandler
{
    public function __construct(private PageExportService $exports) {}

    public function handle(array $payload, JobContext $context): void
    {
        $this->exports->cleanup();
    }
}
