<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\Analytics\BusinessMeasurementService;

/** Reads GA4's realtime report for an owner's test conversion (ADR-042). Read-only. */
final readonly class EventCheckJob implements JobHandler
{
    public function __construct(private BusinessMeasurementService $service) {}

    public function handle(array $payload, JobContext $context): void
    {
        $id = $payload['check_id'] ?? null;
        if (!is_string($id) || !Uuid::isValid($id)) {
            throw new PermanentJobException('Invalid event check payload.');
        }
        $this->service->runCheck($id);
    }
}
