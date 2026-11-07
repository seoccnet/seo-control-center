<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research;

use SeoControlCenter\Domain\Projects\Project;

interface BrandMentionScheduler
{
    public function fetch(Project $project, string $checkId, int $step): void;
}
