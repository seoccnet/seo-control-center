<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\Reports\ReportService;

/**
 * Builds and e-mails one report.
 */
final readonly class SendReportJob implements JobHandler
{
    public function __construct(private ReportService $service) {}

    public function handle(array $payload, JobContext $context): void
    {
        $recipients = $payload['recipients'] ?? null;
        if (!is_int($payload['workspace_id'] ?? null) || !is_string($payload['project'] ?? null) || !is_string($payload['frequency'] ?? null)
            || !is_string($payload['to'] ?? null) || !is_array($recipients) || !is_string($payload['kind'] ?? null)) {
            throw new PermanentJobException('Invalid report payload.');
        }
        $this->service->send($payload['workspace_id'], $payload['project'], $payload['frequency'], $payload['to'], array_values(array_filter($recipients, is_string(...))), $payload['kind']);
    }
}
