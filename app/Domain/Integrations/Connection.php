<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Integrations;

/**
 * A workspace-owned connection to an external account (e.g. a Google account).
 * Never carries tokens; those are read through the token store only.
 */
final readonly class Connection
{
    public function __construct(
        public int $id,
        public string $publicId,
        public int $workspaceId,
        public string $provider,
        public string $accountEmail,
        public string $status,
        public ?string $lastError,
        public string $createdAt,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
