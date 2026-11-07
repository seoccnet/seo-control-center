<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ChangeBundles;

use SeoControlCenter\Domain\Projects\Project;

/** Queues a live-page read for a bundle: its baseline, the version guard, or verification. */
interface BundleCheckScheduler
{
    public function check(Project $project, string $bundleId, string $purpose): void;
}
