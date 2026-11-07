<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\SiteAudit\RenderedAuditService;
use SeoControlCenter\Modules\SiteAudit\SiteAuditScheduler;

final readonly class RenderSiteAuditJob implements JobHandler
{
    public function __construct(private RenderedAuditService $service, private SiteAuditScheduler $scheduler, private Connection $db) {}

    public function handle(array $payload, JobContext $context): void
    {
        $projectId = $payload['project_id'] ?? null;
        $workspaceId = $payload['workspace_id'] ?? null;
        $requestId = $payload['request_id'] ?? null;
        if (!is_int($projectId) || $projectId < 1 || !is_int($workspaceId) || $workspaceId < 1 || !is_string($requestId) || !Uuid::isValid($requestId)) {
            throw new PermanentJobException('Invalid rendered audit payload.');
        }
        if ($context->secondsRemaining() <= 35) {
            $this->scheduler->render($projectId, $workspaceId, $requestId, 60);
            return;
        }
        $lock = new LeaseLock($this->db, 'site_audit.render:' . $projectId, 180);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Another worker is checking this rendered page.');
        }
        try {
            if (!$context->renewLease()) {
                throw new \RuntimeException('Rendered audit queue lease expired before the provider request.');
            }
            $this->service->run($projectId, $workspaceId, $requestId, $context->attempt >= $context->maxAttempts);
        } finally {
            $lock->release();
        }
    }
}
