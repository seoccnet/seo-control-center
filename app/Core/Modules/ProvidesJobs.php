<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Modules;

use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\Scheduler;

/**
 * Optional module capability: background job types and scheduled tasks.
 * Called lazily, only when the queue or scheduler is used.
 */
interface ProvidesJobs
{
    public function registerJobs(JobRegistry $jobs, Container $container): void;

    public function registerSchedule(Scheduler $scheduler): void;
}
