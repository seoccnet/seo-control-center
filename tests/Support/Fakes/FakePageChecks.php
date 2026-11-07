<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\PageMonitoring\PageCheckScheduler;

/** Records immediate page checks instead of queueing them. */
final class FakePageChecks implements PageCheckScheduler
{
    /** @var list<array{project_id: int, url: string}> */
    public array $checks = [];

    public function checkNow(Project $project, string $url): void
    {
        $this->checks[] = ['project_id' => $project->id, 'url' => $url];
    }
}
