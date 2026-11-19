<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks;

/**
 * The project's link outreach list (1.8.0): one entry per domain.
 *
 * @phpstan-type Entry array{domain: string, source: string, pageUrl: ?string, targetUrl: ?string, status: string, note: ?string, addedAt: string, updatedAt: string}
 */
interface OutreachRepository
{
    public const int MAX = 2000;

    public const array SOURCES = ['mention', 'lost', 'broken', 'manual'];
    public const array STATUSES = ['to_contact', 'contacted', 'won', 'declined'];

    /**
     * @return list<Entry> most recently changed first
     */
    public function entries(int $projectId): array;

    public function count(int $projectId): int;

    /**
     * @return bool false when the domain is already listed
     */
    public function add(int $projectId, int $workspaceId, string $domain, string $source, ?string $pageUrl, ?string $targetUrl, int $userId): bool;

    /**
     * @return bool false when the domain is not listed
     */
    public function update(int $projectId, string $domain, string $status, ?string $note): bool;

    public function remove(int $projectId, string $domain): bool;
}
