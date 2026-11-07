<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

/**
 * Status of a project's rank checks, shown on the Rankings page.
 */
final readonly class RankingSyncState
{
    public const string PENDING = 'pending';
    public const string RUNNING = 'running';
    public const string OK = 'ok';
    public const string ERROR = 'error';

    public function __construct(
        public string $status,
        public ?string $lastError,
        public ?string $lastCheckedOn,
        public ?string $lastSuccessAt,
    ) {}
}
