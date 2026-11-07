<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Shared\DateRange;

interface PanelSignalReader
{
    /** Workspace/project scoped, local reads only; bounded source snapshots. */
    public function snapshot(int $projectId, int $workspaceId, DateRange $range, string $asOf): PanelSnapshot;
}
