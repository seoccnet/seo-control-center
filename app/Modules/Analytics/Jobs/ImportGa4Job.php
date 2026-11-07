<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\Analytics\Ga4Service;

final readonly class ImportGa4Job implements JobHandler
{
    public function __construct(private Ga4Service $service, private Connection $db) {}
    public function handle(array $payload, JobContext $context): void
    {
        if (!is_int($payload['project_id'] ?? null) || !is_int($payload['workspace_id'] ?? null) || !is_string($payload['project_public_id'] ?? null)) {
            throw new PermanentJobException('Invalid GA4 import payload.');
        }
        $days = $payload['days'] ?? 28;
        if (!is_int($days) || !in_array($days, [28, 90, 180, 365], true)) {
            throw new PermanentJobException('Invalid GA4 history window.');
        }
        $lock = new LeaseLock($this->db, 'ga4.import:' . $payload['project_id'], 180);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Another worker is importing this GA4 property.');
        }
        try {
            $this->service->run($payload['project_id'], $payload['workspace_id'], $payload['project_public_id'], $days);
        } catch (IntegrationException $e) {
            if (!$e->retryable) {
                throw new PermanentJobException($e->getMessage());
            } throw $e;
        } finally {
            $lock->release();
        }
    }
}
