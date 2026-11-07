<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local;

use SeoControlCenter\Domain\Local\LocalArea;

/**
 * A project's local SEO setup and the state of its checks.
 */
final readonly class LocalSettings
{
    public function __construct(
        public int $projectId,
        public int $workspaceId,
        public string $businessName,
        public LocalArea $area,
        public string $language,
        public bool $weekly,
        public ?string $cid,
        public string $status,
        public ?string $lastError,
        public ?string $lastCheckedOn,
    ) {}

    public function isQueued(): bool
    {
        return $this->status === 'queued';
    }
}
