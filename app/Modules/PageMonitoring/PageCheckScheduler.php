<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\PageMonitoring;

use SeoControlCenter\Domain\Projects\Project;

/**
 * Queues one check of a watched page now, outside the weekly rhythm: the
 * "before" snapshot when a watch is added or a change is recorded (ADR-039, E2).
 */
interface PageCheckScheduler
{
    public function checkNow(Project $project, string $url): void;
}
