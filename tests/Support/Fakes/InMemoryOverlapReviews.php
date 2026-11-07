<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\ContentInventory\OverlapReviewRepository;

/** Overlap decisions and crawled page text for HTTP tests (ADR-042). */
final class InMemoryOverlapReviews implements OverlapReviewRepository
{
    /** @var array<int, array<string, array{key: string, kind: string, members: list<string>, decision: string, retained_url: ?string, note: string, version: int, decided_by: ?int, decided_at: string}>> */
    private array $reviews = [];

    /** @var array<int, list<array{url: string, title: ?string, text: string}>> */
    public array $pages = [];

    public function __construct(private readonly Clock $clock) {}

    public function reviews(int $projectId): array
    {
        return $this->reviews[$projectId] ?? [];
    }

    public function decide(int $workspaceId, int $projectId, string $key, string $kind, array $members, string $decision, ?string $retainedUrl, string $note, int $version, ?int $userId): bool
    {
        $current = $this->reviews[$projectId][$key]['version'] ?? 0;
        if ($current !== $version) {
            return false;
        }
        $this->reviews[$projectId][$key] = ['key' => $key, 'kind' => $kind, 'members' => $members, 'decision' => $decision, 'retained_url' => $retainedUrl, 'note' => $note, 'version' => $version + 1, 'decided_by' => $userId, 'decided_at' => $this->clock->now()->format('Y-m-d H:i:s')];

        return true;
    }

    public function pageTexts(int $projectId, int $limit): array
    {
        return ['audit_at' => isset($this->pages[$projectId]) ? '2026-10-20 10:00:00' : null, 'pages' => array_slice($this->pages[$projectId] ?? [], 0, $limit)];
    }

    /** @var array<int, list<string>> product page addresses per project */
    public array $products = [];

    public int $productReads = 0;

    public function productUrls(int $projectId): array
    {
        ++$this->productReads;

        return $this->products[$projectId] ?? [];
    }
}
