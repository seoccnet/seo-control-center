<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Investigations;

/**
 * A saved research notebook of a project (ADR-038, D3): the question being
 * investigated, its dated sources, the checks done, known limitations,
 * notes and, once concluded, the conclusion. Only public IDs leave the
 * server.
 */
final readonly class Investigation
{
    public const string OPEN = 'open';
    public const string CONCLUDED = 'concluded';
    public const string ARCHIVED = 'archived';
    public const array STATUSES = [self::OPEN, self::CONCLUDED, self::ARCHIVED];

    /**
     * @param list<InvestigationItem> $items oldest first; empty in lists
     * @param array<string, int> $counts items per kind
     */
    public function __construct(
        public string $publicId,
        public int $projectId,
        public int $workspaceId,
        public string $title,
        public ?string $question,
        public string $status,
        public ?string $conclusion,
        public ?string $via,
        public int $revision,
        public string $createdAt,
        public string $updatedAt,
        public array $items = [],
        public array $counts = [],
    ) {}

    /** @return array<string, mixed> */
    public function present(bool $withItems = false): array
    {
        $data = [
            'investigation_id' => $this->publicId,
            'title' => $this->title,
            'question' => $this->question,
            'status' => $this->status,
            'conclusion' => $this->conclusion,
            'items' => array_sum($this->counts),
            'counts' => $this->counts,
            'via' => $this->via,
            'version' => $this->revision,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
        if ($withItems) {
            $data['entries'] = array_map(static fn(InvestigationItem $i): array => $i->present(), $this->items);
        }

        return $data;
    }
}
