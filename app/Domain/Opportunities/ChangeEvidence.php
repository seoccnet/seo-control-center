<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/**
 * What else changed around a completed opportunity (ADR-039, E2): detected
 * changes of a watched page and recorded change notes, read from storage.
 */
interface ChangeEvidence
{
    /**
     * Detected changes of one page (same host and path) between two UTC times, oldest first.
     *
     * @return list<array{public_id: string, detected_at: string, fields: list<string>}>
     */
    public function pageChanges(int $projectId, string $url, string $from, string $to): array;

    /**
     * Change notes dated between two days: site-wide ones and, with $url,
     * those of that exact page.
     *
     * @return list<array{id: string, date: string, title: string, url: ?string}>
     */
    public function notes(int $projectId, int $workspaceId, ?string $url, string $from, string $to): array;
}
