<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages\Jobs;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Modules\Pages\PageExportScheduler;

/** Queues Page library export chunks on the reports queue. */
final readonly class PageExportJobs implements PageExportScheduler
{
    public const string EXPORT = 'pages.export';
    public const string CLEANUP = 'pages.exports.cleanup';

    public function __construct(private Queue $queue) {}

    public function export(int $exportId, int $workspaceId, int $rowCount): void
    {
        $this->queue->dispatch(self::EXPORT, ['export_id' => $exportId], 'reports', idempotencyKey: sprintf('pages.export:%d:%d', $exportId, $rowCount), workspaceId: $workspaceId);
    }
}
