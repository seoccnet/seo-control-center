<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research;

/**
 * Queues a research lookup.
 */
interface ResearchScheduler
{
    public function fetch(int $projectId, int $workspaceId, string $kind, string $subject): void;
}
