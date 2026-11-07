<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\WebVitals\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\WebVitals\WebVitalsService;

final readonly class FetchWebVitalsJob implements JobHandler
{
    public function __construct(private WebVitalsService $service, private Connection $db) {}
    public function handle(array $payload, JobContext $context): void
    {
        if ($context->secondsRemaining() <= 35 || !$context->renewLease()) {
            throw new IntegrationException('Not enough cron time remains for a field-data request.', true);
        }
        if (!is_int($payload['project_id'] ?? null) || !is_int($payload['workspace_id'] ?? null) || !is_string($payload['project_public_id'] ?? null) || !is_string($payload['url'] ?? null)) {
            throw new PermanentJobException('Invalid Web Vitals payload.');
        }
        $device = $payload['form_factor'] ?? 'PHONE';
        if (!is_string($device) || !in_array($device, ['PHONE', 'DESKTOP'], true)) {
            throw new PermanentJobException('Invalid CrUX device.');
        }
        $lock = new LeaseLock($this->db, 'crux.fetch:' . $payload['project_id'] . ':' . hash('sha256', $payload['url']) . ':' . $device, 180);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Another worker is checking this URL.');
        }
        try {
            $this->service->run($payload['project_id'], $payload['workspace_id'], $payload['project_public_id'], $payload['url'], $device);
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
