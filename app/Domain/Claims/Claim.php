<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Claims;

/**
 * One factual claim of a content brief with the source a person checked it
 * against (ADR-042). The status is always a person's verdict; automated
 * checks are flags (ClaimFlags), never a status.
 */
final readonly class Claim
{
    public const string UNREVIEWED = 'unreviewed';
    public const string SUPPORTED = 'supported';
    public const string PARTLY = 'partly_supported';
    public const string CONTRADICTED = 'contradicted';
    public const string OUTDATED = 'outdated';
    public const string UNVERIFIABLE = 'unverifiable';

    public const array STATUSES = [
        self::UNREVIEWED => 'Unreviewed',
        self::SUPPORTED => 'Supported',
        self::PARTLY => 'Partly supported',
        self::CONTRADICTED => 'Contradicted',
        self::OUTDATED => 'Outdated',
        self::UNVERIFIABLE => 'Unverifiable',
    ];

    /** Scope fields: the exact product a claim or a source is about. */
    public const array SCOPE = [
        'breeder' => 'Breeder or brand',
        'cultivar' => 'Cultivar or product',
        'version' => 'Product version',
        'market' => 'Market',
        'sample' => 'Research sample',
    ];

    public const int MAX_PER_BRIEF = 100;

    /**
     * @param array<string, string> $claimScope keys of SCOPE, empty values left out
     * @param array<string, string> $sourceScope keys of SCOPE, empty values left out
     */
    public function __construct(
        public string $publicId,
        public string $briefId,
        public string $claim,
        public string $subject,
        public array $claimScope,
        public ?string $sourceUrl,
        public string $publisher,
        public ?string $checkedOn,
        public string $passage,
        public array $sourceScope,
        public string $status,
        public string $note,
        public ?int $reviewedBy,
        public ?string $reviewedAt,
        public int $version,
        public string $updatedAt,
    ) {}

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->publicId,
            'brief_id' => $this->briefId,
            'claim' => $this->claim,
            'subject' => $this->subject,
            'claim_scope' => $this->claimScope,
            'source_url' => $this->sourceUrl,
            'publisher' => $this->publisher,
            'checked_on' => $this->checkedOn,
            'passage' => $this->passage,
            'source_scope' => $this->sourceScope,
            'status' => $this->status,
            'note' => $this->note,
            'reviewed_at' => $this->reviewedAt,
            'version' => $this->version,
            'updated_at' => $this->updatedAt,
        ];
    }
}
