<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\SiteAudit\SiteAuditService;

/**
 * Daily: starts the weekly audits that are due.
 */
final readonly class DispatchSiteAuditsJob implements JobHandler
{
    public function __construct(private SiteAuditService $service) {}

    public function handle(array $payload, JobContext $context): void
    {
        $this->service->startDue();
    }
}
