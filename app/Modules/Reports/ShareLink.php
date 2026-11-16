<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports;

/** A read-only client link to a project's report (1.3.0). The token itself is never stored. */
final readonly class ShareLink
{
    public function __construct(
        public int $id,
        public string $publicId,
        public int $workspaceId,
        public int $projectId,
        public string $projectPublicId,
        public string $tokenHint,
        public string $label,
        public string $expiresAt,
        public ?string $revokedAt,
        public int $views,
        public ?string $lastViewedAt,
        public string $createdAt,
    ) {}

    public function activeAt(string $now): bool
    {
        return $this->revokedAt === null && $this->expiresAt > $now;
    }
}
