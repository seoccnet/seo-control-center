<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports\Jobs;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Modules\Reports\ReportScheduler;

/**
 * Queues report jobs; a scheduled period is queued once per project.
 */
final readonly class ReportJobs implements ReportScheduler
{
    public const string DISPATCH = 'reports.dispatch';
    public const string SEND = 'reports.send';

    public function __construct(private Queue $queue) {}

    public function send(int $workspaceId, string $projectPublicId, string $frequency, string $periodTo, array $recipients, string $kind): void
    {
        $this->queue->dispatch(
            self::SEND,
            ['workspace_id' => $workspaceId, 'project' => $projectPublicId, 'frequency' => $frequency, 'to' => $periodTo, 'recipients' => array_values($recipients), 'kind' => $kind],
            idempotencyKey: $kind === 'scheduled' ? sprintf('reports.send:%s:%s:%s', $projectPublicId, $frequency, $periodTo) : null,
            workspaceId: $workspaceId,
        );
    }
}
