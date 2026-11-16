<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\Reports\ShareLink;
use SeoControlCenter\Modules\Reports\ShareLinkRepository;

/** Read-only client links in memory; project public IDs come from the directory, as the PDO join does. */
final class InMemoryShareLinks implements ShareLinkRepository
{
    /** @var array<int, ShareLink> */
    public array $links = [];
    /** @var array<int, string> link id => token hash */
    public array $hashes = [];

    public function __construct(private readonly Clock $clock, private readonly InMemoryDirectory $directory) {}

    public function create(int $workspaceId, int $projectId, string $tokenHash, string $tokenHint, string $label, string $expiresAt, int $userId, string $now): ShareLink
    {
        $id = count($this->links) + 1;
        $this->links[$id] = new ShareLink($id, Uuid::v7($this->clock), $workspaceId, $projectId, $this->directory->projects[$projectId]->publicId, $tokenHint, $label, $expiresAt, null, 0, null, $now);
        $this->hashes[$id] = $tokenHash;

        return $this->links[$id];
    }

    public function byTokenHash(string $tokenHash): ?ShareLink
    {
        $id = array_search($tokenHash, $this->hashes, true);

        return $id === false || isset($this->directory->deleted[$this->links[$id]->projectId]) ? null : $this->links[$id];
    }

    public function forProject(int $projectId, int $limit): array
    {
        return array_slice(array_values(array_filter(array_reverse($this->links), static fn(ShareLink $l): bool => $l->projectId === $projectId)), 0, $limit);
    }

    public function find(int $projectId, string $publicId): ?ShareLink
    {
        foreach ($this->links as $link) {
            if ($link->projectId === $projectId && $link->publicId === $publicId) {
                return $link;
            }
        }

        return null;
    }

    public function revoke(int $id, string $now): void
    {
        $this->with($id, ['revokedAt' => $this->links[$id]->revokedAt ?? $now]);
    }

    public function recordView(int $id, string $now): void
    {
        $this->with($id, ['views' => $this->links[$id]->views + 1, 'lastViewedAt' => $now]);
    }

    public function countActive(int $projectId, string $now): int
    {
        return count(array_filter($this->links, static fn(ShareLink $l): bool => $l->projectId === $projectId && $l->activeAt($now)));
    }

    /** @param array<string, mixed> $changes */
    private function with(int $id, array $changes): void
    {
        $this->links[$id] = new ShareLink(...[...get_object_vars($this->links[$id]), ...$changes]);
    }
}
