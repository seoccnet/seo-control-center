<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Investigations;

/**
 * One dated entry of an investigation: a source (where the evidence comes
 * from and when it was observed), a check that was done, a limitation of
 * the evidence, or a note. A reference points to stored data (an analysis,
 * a research lookup, an opportunity, a topic, a page or a URL).
 */
final readonly class InvestigationItem
{
    public const string SOURCE = 'source';
    public const string CHECK = 'check';
    public const string LIMITATION = 'limitation';
    public const string NOTE = 'note';
    public const array KINDS = [self::SOURCE, self::CHECK, self::LIMITATION, self::NOTE];
    public const array REF_TYPES = ['analysis', 'research', 'opportunity', 'topic', 'page', 'data_quality', 'url'];

    public function __construct(
        public string $publicId,
        public string $kind,
        public ?string $refType,
        public ?string $refId,
        public string $label,
        public ?string $detail,
        public ?string $observedOn,
        public ?string $via,
        public string $createdAt,
    ) {}

    /** @return array<string, mixed> */
    public function present(): array
    {
        return ['item_id' => $this->publicId, 'kind' => $this->kind, 'ref_type' => $this->refType, 'ref_id' => $this->refId, 'label' => $this->label, 'detail' => $this->detail, 'observed_on' => $this->observedOn, 'via' => $this->via, 'created_at' => $this->createdAt];
    }
}
