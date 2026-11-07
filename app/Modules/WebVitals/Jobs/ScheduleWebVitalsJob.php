<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\WebVitals\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\WebVitals\WebVitalsService;

final readonly class ScheduleWebVitalsJob implements JobHandler
{
    public function __construct(private WebVitalsService $service, private Connection $db) {}
    public function handle(array $payload, JobContext $context): void
    {
        $lock = new LeaseLock($this->db, 'crux.schedule', 180);
        if (!$lock->acquire()) {
            return;
        }
        try {
            $this->service->dispatchDue();
        } finally {
            $lock->release();
        }
    }
}
