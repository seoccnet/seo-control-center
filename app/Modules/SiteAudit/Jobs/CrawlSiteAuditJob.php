<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Modules\SiteAudit\SiteAuditService;

/**
 * Crawls the next pages of one audit. Holds a per-audit lease lock, stops
 * before the cron run's time budget ends and queues a continuation.
 */
final readonly class CrawlSiteAuditJob implements JobHandler
{
    /** One bounded HTTP request plus storage. A 70s reserve starved the default 50s worker. */
    private const int RESERVE_SECONDS = 35;
    private const int CONTINUE_AFTER_SECONDS = 30;

    public function __construct(
        private SiteAuditService $service,
        private Connection $db,
        private Queue $queue,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $auditId = $payload['audit_id'] ?? null;
        if (!is_int($auditId) || $auditId < 1) {
            throw new PermanentJobException('Invalid audit_id.');
        }
        $lock = new LeaseLock($this->db, 'site_audit:' . $auditId, 180);
        if (!$lock->acquire()) {
            return; // Another run is crawling this audit and queues its own continuation.
        }
        try {
            $done = $this->service->crawl(
                $auditId,
                static fn(): bool => $context->secondsRemaining() > self::RESERVE_SECONDS && $lock->renew() && $context->renewLease(),
            );
        } finally {
            $lock->release();
        }
        if (!$done) {
            $workspaceId = is_int($payload['workspace_id'] ?? null) ? $payload['workspace_id'] : null;
            $this->queue->dispatch(SiteAuditJobs::CRAWL, $payload, SiteAuditJobs::QUEUE, delaySeconds: self::CONTINUE_AFTER_SECONDS, workspaceId: $workspaceId);
        }
    }
}
