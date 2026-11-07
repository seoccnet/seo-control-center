<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO;

/**
 * A workspace's DataForSEO account. Never carries the API password.
 */
final readonly class DataForSeoProfile
{
    public function __construct(
        public int $workspaceId,
        public string $login,
        public string $status,
        public ?string $lastError,
        public ?float $balance,
        public ?string $checkedAt,
        public string $createdAt,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
