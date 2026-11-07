<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

/**
 * Queues the crawl of an audit.
 */
interface SiteAuditScheduler
{
    public function crawl(int $auditId, int $workspaceId): void;

    public function render(int $projectId, int $workspaceId, string $requestId, int $delaySeconds = 0): void;
}
