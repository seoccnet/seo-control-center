<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\ContentInventory;

/**
 * A CMS record entered by hand or from a CSV export (ADR-042: SEOcc has no
 * CMS connection). A draft is not a public page; its address may be planned
 * or unknown. linkedUrl joins an item without its own address to a page
 * after a person confirmed the match.
 */
final readonly class ContentItem
{
    public const string DRAFT = 'draft';
    public const string SCHEDULED = 'scheduled';
    public const string PUBLISHED = 'published';
    public const string RETIRED = 'retired';

    public const array STATUSES = [self::DRAFT => 'Draft', self::SCHEDULED => 'Scheduled', self::PUBLISHED => 'Published', self::RETIRED => 'Retired'];

    public const string MANUAL = 'manual';
    public const string CSV = 'csv';

    public const int MAX_PER_PROJECT = 5000;

    public function __construct(
        public string $publicId,
        public int $projectId,
        public string $title,
        public ?string $url,
        public string $status,
        public ?string $publishOn,
        public ?string $cmsId,
        public string $source,
        public string $note,
        public ?string $linkedUrl,
        public int $version,
        public string $updatedAt,
    ) {}

    /** The address the item is matched by: its own, or the one a person linked. */
    public function matchUrl(): ?string
    {
        return $this->url ?? $this->linkedUrl;
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->publicId,
            'title' => $this->title,
            'url' => $this->url,
            'status' => $this->status,
            'publish_on' => $this->publishOn,
            'cms_id' => $this->cmsId,
            'source' => $this->source,
            'note' => $this->note,
            'linked_url' => $this->linkedUrl,
            'version' => $this->version,
            'updated_at' => $this->updatedAt,
        ];
    }
}
