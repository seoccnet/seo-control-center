<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\PageMonitoring;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Core\Support\Uuid;

final readonly class PageMonitoringJob implements JobHandler
{
    public function __construct(private PageMonitoringService $service, private Connection $db, private bool $schedule = false) {}

    public function handle(array $payload, JobContext $context): void
    {
        if ($this->schedule) {
            $lock = new LeaseLock($this->db, 'page_monitoring.schedule', 180);
        } else {
            if (!is_int($payload['project_id'] ?? null) || !is_int($payload['workspace_id'] ?? null) || !is_string($payload['public_id'] ?? null) || !is_string($payload['url'] ?? null) || !is_string($payload['generation'] ?? null) || !Uuid::isValid($payload['generation'])) {
                throw new PermanentJobException('Invalid page monitoring payload.');
            }
            $lock = new LeaseLock($this->db, 'page_watch:' . $payload['project_id'] . ':' . hash('sha256', $payload['url']), 180);
        }
        if (!$lock->acquire()) {
            throw new \RuntimeException('Another worker is checking this page watch.');
        }
        try {
            if ($this->schedule) {
                $this->service->schedule();
            } else {
                $this->service->run($payload['project_id'], $payload['workspace_id'], $payload['public_id'], $payload['url'], $payload['generation'], $context->jobId);
            }
        } finally {
            $lock->release();
        }
    }
}
