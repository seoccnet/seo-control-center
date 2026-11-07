<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Integrations;

/**
 * Temporary retention of raw provider responses for diagnostics and
 * reprocessing. Never the canonical data model (INSTRUCTIONS.md §10).
 */
interface ProviderResponseStore
{
    public function store(int $workspaceId, ?int $usageId, string $provider, string $operation, int $httpStatus, string $body, \DateTimeImmutable $expiresAt): void;

    /**
     * @return int rows deleted (bounded batch)
     */
    public function purgeExpired(\DateTimeImmutable $now): int;
}
