<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages;

/** Queues the next chunk of a Page library export. */
interface PageExportScheduler
{
    /**
     * $rowCount is the progress so far; together with the export ID it makes
     * the dispatch idempotent, so a retried job cannot queue a second copy.
     */
    public function export(int $exportId, int $workspaceId, int $rowCount): void;
}
