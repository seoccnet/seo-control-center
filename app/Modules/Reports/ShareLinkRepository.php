<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports;

/** Read-only client links (1.3.0). */
interface ShareLinkRepository
{
    public function create(int $workspaceId, int $projectId, string $tokenHash, string $tokenHint, string $label, string $expiresAt, int $userId, string $now): ShareLink;

    /** The link with this token hash, with its project's public id; null when unknown or the project is deleted. */
    public function byTokenHash(string $tokenHash): ?ShareLink;

    /** @return list<ShareLink> newest first */
    public function forProject(int $projectId, int $limit): array;

    public function find(int $projectId, string $publicId): ?ShareLink;

    public function revoke(int $id, string $now): void;

    public function recordView(int $id, string $now): void;

    public function countActive(int $projectId, string $now): int;
}
