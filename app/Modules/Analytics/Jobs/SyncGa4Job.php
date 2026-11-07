<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Modules\Analytics\Ga4Service;

final readonly class SyncGa4Job implements JobHandler
{
    public function __construct(private Ga4Service $service) {}
    public function handle(array $payload, JobContext $context): void
    {
        $this->service->schedule();
    }
}
