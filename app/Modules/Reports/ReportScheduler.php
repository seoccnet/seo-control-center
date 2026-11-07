<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports;

/**
 * Queues the sending of one report.
 */
interface ReportScheduler
{
    /**
     * @param list<string> $recipients
     * @param string $kind "scheduled" or "test"
     */
    public function send(int $workspaceId, string $projectPublicId, string $frequency, string $periodTo, array $recipients, string $kind): void;
}
