<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Integrations;

/**
 * An open ledger entry for a paid provider request.
 */
final readonly class UsageReservation
{
    public function __construct(
        public int $id,
        public int $workspaceId,
        public string $provider,
        public string $operation,
        public float $estimatedCost,
    ) {}
}
