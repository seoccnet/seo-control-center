<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

/**
 * One crawl of a project's site and its summary.
 */
final readonly class SiteAudit
{
    public const array ACTIVE = ['queued', 'running'];

    public function __construct(
        public int $id,
        public string $publicId,
        public int $projectId,
        public int $workspaceId,
        public string $status,
        public string $trigger,
        public string $startUrl,
        public int $maxPages,
        public int $pagesCrawled,
        public int $pagesFound,
        public ?int $healthScore,
        public int $errors,
        public int $warnings,
        public int $notices,
        public ?string $error,
        public string $createdAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public string $updatedAt,
    ) {}

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
