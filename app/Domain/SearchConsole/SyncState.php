<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

/**
 * Import progress of one project.
 */
final readonly class SyncState
{
    public const string PENDING = 'pending';
    public const string IMPORTING = 'importing';
    public const string OK = 'ok';
    public const string ERROR = 'error';

    public function __construct(
        public int $projectId,
        public string $siteUrl,
        public string $status,
        public ?string $totalsRefreshedAt,
        public ?string $lastSuccessAt,
        public ?string $lastError,
    ) {}

    public static function fresh(int $projectId, string $siteUrl): self
    {
        return new self($projectId, $siteUrl, self::PENDING, null, null, null);
    }

    public function with(
        ?string $status = null,
        ?string $totalsRefreshedAt = null,
        ?string $lastSuccessAt = null,
        ?string $lastError = null,
        bool $clearError = false,
    ): self {
        return new self(
            $this->projectId,
            $this->siteUrl,
            $status ?? $this->status,
            $totalsRefreshedAt ?? $this->totalsRefreshedAt,
            $lastSuccessAt ?? $this->lastSuccessAt,
            $clearError ? null : ($lastError ?? $this->lastError),
        );
    }
}
