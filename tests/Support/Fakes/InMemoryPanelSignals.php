<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Opportunities\PanelSignalReader;
use SeoControlCenter\Domain\Opportunities\PanelSnapshot;
use SeoControlCenter\Domain\Shared\DateRange;

final class InMemoryPanelSignals implements PanelSignalReader
{
    /** @var array<int, array{workspace: int, snapshot: PanelSnapshot}> */
    public array $projects = [];

    public function snapshot(int $projectId, int $workspaceId, DateRange $range, string $asOf): PanelSnapshot
    {
        $row = $this->projects[$projectId] ?? null;
        return $row !== null && $row['workspace'] === $workspaceId ? $row['snapshot'] : new PanelSnapshot();
    }
}
