<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteHealth\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\SiteHealth\SiteHealthService;

/** Dispatches due site health checks, or runs one. */
final readonly class SiteHealthJob implements JobHandler
{
    public function __construct(private SiteHealthService $health, private bool $dispatch) {}

    public function handle(array $payload, JobContext $context): void
    {
        if ($this->dispatch) {
            $this->health->dispatch();

            return;
        }
        $projectId = $payload['project_id'] ?? null;
        $workspaceId = $payload['workspace_id'] ?? null;
        $domain = $payload['domain'] ?? null;
        $kind = $payload['kind'] ?? null;
        if (!is_int($projectId) || !is_int($workspaceId) || !is_string($domain) || !in_array($kind, ['uptime', 'daily'], true)) {
            throw new PermanentJobException('Invalid site health payload.');
        }
        $this->health->check($projectId, $workspaceId, $domain, $kind);
    }
}
