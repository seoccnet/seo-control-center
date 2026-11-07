<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Jobs;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Modules\SiteAudit\SiteAuditScheduler;

/**
 * Queues site audit work on the analysis queue.
 */
final readonly class SiteAuditJobs implements SiteAuditScheduler
{
    public const string CRAWL = 'site_audit.crawl';
    public const string DISPATCH = 'site_audit.dispatch';
    public const string RENDER = 'site_audit.render';
    public const string QUEUE = 'analysis';

    public function __construct(private Queue $queue) {}

    public function crawl(int $auditId, int $workspaceId): void
    {
        $this->queue->dispatch(self::CRAWL, ['audit_id' => $auditId, 'workspace_id' => $workspaceId], self::QUEUE, idempotencyKey: 'site_audit.crawl:' . $auditId . ':start', workspaceId: $workspaceId);
    }

    public function render(int $projectId, int $workspaceId, string $requestId, int $delaySeconds = 0): void
    {
        $this->queue->dispatch(self::RENDER, ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'request_id' => $requestId], self::QUEUE, delaySeconds: $delaySeconds, workspaceId: $workspaceId);
    }
}
