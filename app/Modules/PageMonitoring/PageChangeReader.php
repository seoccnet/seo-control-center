<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\PageMonitoring;

interface PageChangeReader
{
    /** @return list<array<string, mixed>> */
    public function changes(int $projectId, int $page = 1, ?string $since = null): array;

    /**
     * Detected changes of one page (same host and path) between two UTC times, oldest first
     * (ADR-039, E2: verified outcomes and overlap flags).
     *
     * @return list<array{public_id: string, detected_at: string, fields: list<string>}>
     */
    public function changesFor(int $projectId, string $url, string $from, string $to): array;
}
