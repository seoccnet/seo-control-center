<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

/**
 * Requests a background rank check of a project for one day.
 */
interface RankCheckScheduler
{
    public function requestCheck(int $projectId, int $workspaceId, string $date): void;
}
