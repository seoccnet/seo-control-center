<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\Local\LocationService;

final readonly class LocationJob implements JobHandler
{
    public function __construct(private LocationService $service, private Connection $db) {}
    public function handle(array $payload, JobContext $context): void
    {
        if (!is_int($payload['project_id'] ?? null) || !is_int($payload['workspace_id'] ?? null) || !is_string($payload['public_id'] ?? null) || !is_string($payload['location'] ?? null) || !in_array($payload['mode'] ?? null, ['check', 'reviews', 'poll', 'grid'], true)) {
            throw new PermanentJobException('Invalid local location job.');
        }
        if ($payload['mode'] === 'grid' && !is_string($payload['grid'] ?? null)) {
            throw new PermanentJobException('Missing branch grid identity.');
        }
        $lock = new LeaseLock($this->db, 'local.location:' . $payload['project_id'] . ':' . $payload['location'], 180);
        if (!$lock->acquire()) {
            if ($payload['mode'] === 'grid' && $context->attempt === $context->maxAttempts) {
                $this->service->failGrid($payload['project_id'], $payload['location'], 'The worker could not obtain the branch lock. Review job history before trying again.', is_string($payload['grid'] ?? null) ? $payload['grid'] : null);
            }
            throw new \RuntimeException('Another worker is checking this location.');
        }
        try {
            $this->service->run($payload['project_id'], $payload['workspace_id'], $payload['public_id'], $payload['location'], $payload['mode'], is_string($payload['grid'] ?? null) ? $payload['grid'] : null);
        } catch (\SeoControlCenter\Domain\Integrations\BudgetExceededException $e) {
            if ($payload['mode'] === 'grid') {
                $this->service->failGrid($payload['project_id'], $payload['location'], $e->getMessage(), is_string($payload['grid'] ?? null) ? $payload['grid'] : null);
            }
            throw new PermanentJobException($e->getMessage());
        } catch (IntegrationException $e) {
            if (!$e->retryable || $context->attempt === $context->maxAttempts) {
                if ($payload['mode'] === 'grid') {
                    $this->service->failGrid($payload['project_id'], $payload['location'], $e->getMessage(), is_string($payload['grid'] ?? null) ? $payload['grid'] : null);
                } elseif ($payload['mode'] !== 'check') {
                    $this->service->failReviews($payload['project_id'], $payload['location']);
                }
                throw new PermanentJobException($e->getMessage());
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($payload['mode'] === 'grid' && $context->attempt === $context->maxAttempts) {
                $this->service->failGrid($payload['project_id'], $payload['location'], 'The grid could not finish. Review job history before trying again.', is_string($payload['grid'] ?? null) ? $payload['grid'] : null);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
