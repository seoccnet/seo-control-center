<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SerpHistory;

/**
 * Stored results-page change events (ADR-039, E1), always per project.
 */
interface SerpEventRepository
{
    /**
     * Stores events once: an event already stored for the same keyword,
     * device, day, kind and subject is left as it is.
     *
     * @param list<array{keyword_id: int, previous_on: string, kind: string, subject: string, detail: array<string, mixed>}> $events
     * @return int events newly stored
     */
    public function record(int $projectId, int $workspaceId, string $device, string $checkedOn, array $events): int;

    /**
     * Newest first.
     *
     * @param array{kinds?: list<string>, q?: string, subject?: string, from?: string, to?: string} $filters
     * @return list<SerpEvent>
     */
    public function list(int $projectId, string $device, array $filters, int $limit, int $offset): array;

    /** @param array{kinds?: list<string>, q?: string, subject?: string, from?: string, to?: string} $filters */
    public function count(int $projectId, string $device, array $filters): int;

    /**
     * Events per kind on or after $since.
     *
     * @return array<string, int>
     */
    public function counts(int $projectId, string $device, string $since): array;

    /**
     * Per tracked competitor domain on or after $since: new URLs, keywords
     * where it entered and left the top 10.
     *
     * @return array<string, array{new_urls: int, entered: int, left: int}>
     */
    public function competitors(int $projectId, string $device, string $since): array;

    public function hasAny(int $projectId): bool;

    public function prune(string $before): int;
}
