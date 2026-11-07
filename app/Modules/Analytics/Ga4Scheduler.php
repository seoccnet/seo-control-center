<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics;

interface Ga4Scheduler
{
    public function import(int $projectId, int $workspaceId, string $publicId, int $days = 28): void;

    /** Queues the read-only realtime check of an owner's test conversion (ADR-042). */
    public function check(string $checkId, int $workspaceId): void;
}
