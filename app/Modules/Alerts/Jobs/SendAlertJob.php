<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\Alerts\AlertService;

/**
 * E-mails one alert to the members who want it.
 */
final readonly class SendAlertJob implements JobHandler
{
    public function __construct(private AlertService $service) {}

    public function handle(array $payload, JobContext $context): void
    {
        if (!is_int($payload['alert'] ?? null)) {
            throw new PermanentJobException('Invalid alert payload.');
        }
        $this->service->send($payload['alert']);
    }
}
