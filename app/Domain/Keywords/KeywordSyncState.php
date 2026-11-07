<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Keywords;

/**
 * Status of a project's keyword metrics updates, shown on the Keywords page.
 */
final readonly class KeywordSyncState
{
    public const string PENDING = 'pending';
    public const string RUNNING = 'running';
    public const string OK = 'ok';
    public const string ERROR = 'error';

    public function __construct(
        public string $status,
        public ?string $lastError,
        public ?string $requestedAt,
        public ?string $lastSuccessAt,
    ) {}
}
