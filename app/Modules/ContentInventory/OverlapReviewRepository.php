<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentInventory;

/**
 * People's decisions on overlap candidates (ADR-042), and the crawled page
 * text the near-duplicate check reads. Always scoped by project.
 *
 * @phpstan-type Review array{key: string, kind: string, members: list<string>, decision: string, retained_url: ?string, note: string, version: int, decided_by: ?int, decided_at: string}
 */
interface OverlapReviewRepository
{
    /** @return array<string, Review> per candidate key */
    public function reviews(int $projectId): array;

    /**
     * Records a decision when $version is still current (0 for a candidate never decided).
     *
     * @param list<string> $members
     */
    public function decide(int $workspaceId, int $projectId, string $key, string $kind, array $members, string $decision, ?string $retainedUrl, string $note, int $version, ?int $userId): bool;

    /**
     * Body text and title of the pages of the newest completed site audit, at most $limit pages.
     *
     * @return array{audit_at: ?string, pages: list<array{url: string, title: ?string, text: string}>}
     */
    public function pageTexts(int $projectId, int $limit): array;

    /**
     * Addresses of the newest completed site audit that are product pages: an
     * Open Graph product type or Product markup (0.44.1, finding 39).
     *
     * @phpstan-impure
     * @return list<string>
     */
    public function productUrls(int $projectId): array;
}
