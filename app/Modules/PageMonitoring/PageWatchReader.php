<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\PageMonitoring;

use SeoControlCenter\Domain\Projects\Project;

/**
 * Read access to watched pages and their detected changes, scoped to the
 * project's workspace (for MCP and Ask AI).
 */
interface PageWatchReader
{
    /** @return list<array<string, mixed>> up to 50 watches */
    public function watches(Project $project): array;

    public function countHistory(Project $project, string $search, string $kind): int;

    /** @return list<array<string, mixed>> 25 changes per page, newest first */
    public function history(Project $project, string $search, string $kind, int $page): array;

    /** @return array<string, mixed>|null */
    public function change(Project $project, string $id): ?array;
}
