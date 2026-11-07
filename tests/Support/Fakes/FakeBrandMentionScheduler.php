<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Research\BrandMentionScheduler;

final class FakeBrandMentionScheduler implements BrandMentionScheduler
{
    /** @var list<array{project: Project, check: string, step: int}> */ public array $calls = [];
    public function fetch(Project $project, string $checkId, int $step): void
    {
        $this->calls[] = ['project' => $project, 'check' => $checkId, 'step' => $step];
    }
}
