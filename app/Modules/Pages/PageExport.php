<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages;

/**
 * A queued CSV export of matching Page library URLs, written in chunks to a
 * private file. Only the public ID leaves the server.
 */
final readonly class PageExport
{
    public const string QUEUED = 'queued';
    public const string RUNNING = 'running';
    public const string READY = 'ready';
    public const string FAILED = 'failed';

    public function __construct(
        public int $id,
        public string $publicId,
        public int $projectId,
        public int $workspaceId,
        public ?int $requestedBy,
        public string $search,
        public string $sort,
        public string $rangeFrom,
        public string $rangeTo,
        public string $status,
        public int $totalUrls,
        public int $rowCount,
        public int $fileBytes,
        public ?int $cursorKey,
        public ?string $cursorUrl,
        public ?string $error,
        public string $createdAt,
        public string $updatedAt,
        public ?string $completedAt,
        public ?string $expiresAt,
    ) {}

    public function active(): bool
    {
        return $this->status === self::QUEUED || $this->status === self::RUNNING;
    }

    /** @return array{key: int, url: string}|null */
    public function cursor(): ?array
    {
        return $this->cursorUrl === null ? null : ['key' => $this->cursorKey ?? 0, 'url' => $this->cursorUrl];
    }

    public function sortLabel(): string
    {
        return match ($this->sort) {
            'google_clicks' => 'Google clicks · highest first',
            'bing_clicks' => 'Bing clicks · highest first',
            'impressions' => 'Google impressions · highest first',
            default => 'URL A–Z',
        };
    }
}
