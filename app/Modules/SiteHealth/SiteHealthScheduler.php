<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteHealth;

/** Queues site health checks (1.3.0). */
interface SiteHealthScheduler
{
    /**
     * @param string $domain the project's primary domain at dispatch time
     * @param 'uptime'|'daily' $kind
     * @param string $slot makes the idempotency key unique per time slot, so a check is queued once per slot
     */
    public function check(int $projectId, int $workspaceId, string $domain, string $kind, string $slot): void;
}
