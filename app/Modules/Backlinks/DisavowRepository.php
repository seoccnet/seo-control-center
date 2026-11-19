<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks;

/**
 * The domains a project disavows (1.7.0). Google replaces the whole list on
 * every upload, so the file is always written from all of them.
 */
interface DisavowRepository
{
    /** At most this many domains per project. */
    public const int MAX = 5000;

    /**
     * @return list<array{domain: string, spamScore: ?int, addedAt: string}> by domain
     */
    public function domains(int $projectId): array;

    /**
     * Adds domains not on the list yet, up to MAX in all.
     *
     * @param array<string, ?int> $domains domain => spam score when known
     * @return int domains added
     */
    public function add(int $projectId, int $workspaceId, array $domains, int $userId): int;

    /**
     * @param list<string> $domains
     * @return int domains removed
     */
    public function remove(int $projectId, array $domains): int;
}
