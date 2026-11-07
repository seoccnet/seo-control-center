<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\LabTests;

/** Queues work on one lab run (ADR-040, F2). */
interface LabTestScheduler
{
    public function queue(int $runId, int $workspaceId, int $delaySeconds = 0): void;
}
