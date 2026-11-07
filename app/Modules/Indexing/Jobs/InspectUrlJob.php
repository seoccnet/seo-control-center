<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Indexing\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\Indexing\IndexingService;

final readonly class InspectUrlJob implements JobHandler
{
    public function __construct(private IndexingService $service, private Connection $db) {}
    public function handle(array $payload, JobContext $context): void
    {
        if (!is_int($payload['project_id'] ?? null) || !is_int($payload['workspace_id'] ?? null) || !is_string($payload['public_id'] ?? null) || !is_string($payload['binding'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $payload['binding']) !== 1 || !is_string($payload['url'] ?? null)) {
            throw new PermanentJobException('Invalid URL inspection payload.');
        }
        $lock = new LeaseLock($this->db, 'inspection:' . $payload['project_id'] . ':' . hash('sha256', $payload['url']), 180);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Another worker is inspecting this URL.');
        }
        try {
            $this->service->run($payload['project_id'], $payload['workspace_id'], $payload['public_id'], $payload['binding'], $payload['url'], $context->jobId);
        } catch (IntegrationException $e) {
            if (!$e->retryable) {
                throw new PermanentJobException($e->getMessage());
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
