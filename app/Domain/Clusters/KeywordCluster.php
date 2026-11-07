<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Clusters;

/**
 * A saved keyword cluster (ADR-037): searches one page can target together,
 * the page that owns them and where several pages overlap. Only public IDs
 * leave the server.
 */
final readonly class KeywordCluster
{
    public const string OWNED = 'owned';
    public const string CONTESTED = 'contested';
    public const string UNOWNED = 'unowned';

    /**
     * @param list<array{keyword: string, demand: float}> $members up to 100, by demand
     * @param list<array{url: string, clicks: int, impressions: int, share: float, best_position: ?float}> $candidates up to 5 pages
     * @param list<array<string, mixed>> $overlap queries shown by more than one page (OverlapClassifier)
     */
    public function __construct(
        public string $publicId,
        public string $label,
        public int $size,
        public float $demand,
        public array $members,
        public string $status,
        public ?string $ownerUrl,
        public ?string $ownerSource,
        public ?string $inferredUrl,
        public string $ownerReason,
        public array $candidates,
        public array $overlap,
        public int $overlapFlagged,
        public ?string $manualOwnerUrl,
        public ?string $manualSetAt,
        public ?string $via,
        public int $revision,
        public ?string $retiredAt,
        public string $computedAt,
    ) {}

    /** @return list<string> */
    public function keywords(): array
    {
        return array_column($this->members, 'keyword');
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'cluster_id' => $this->publicId,
            'label' => $this->label,
            'size' => $this->size,
            'demand' => round($this->demand, 1),
            'status' => $this->status,
            'owner_url' => $this->ownerUrl,
            'owner_source' => $this->ownerSource,
            'inferred_url' => $this->inferredUrl,
            'owner_reason' => $this->ownerReason,
            'overlap_flagged' => $this->overlapFlagged,
            'retired' => $this->retiredAt !== null,
            'version' => $this->revision,
        ];
    }
}
