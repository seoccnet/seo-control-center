<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ChangeBundles\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\ChangeBundles\ChangeBundleService;

final readonly class BundleCheckJob implements JobHandler
{
    public function __construct(private ChangeBundleService $bundles) {}

    public function handle(array $payload, JobContext $context): void
    {
        if (!is_int($payload['project_id'] ?? null) || !is_int($payload['workspace_id'] ?? null) || !is_string($payload['public_id'] ?? null) || !is_string($payload['bundle_id'] ?? null) || !Uuid::isValid($payload['bundle_id']) || !in_array($payload['purpose'] ?? null, ChangeBundleService::PURPOSES, true)) {
            throw new PermanentJobException('Invalid change bundle check payload.');
        }
        $this->bundles->runCheck($payload['project_id'], $payload['workspace_id'], $payload['public_id'], $payload['bundle_id'], $payload['purpose']);
    }
}
