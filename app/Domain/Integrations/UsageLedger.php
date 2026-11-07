<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Integrations;

/**
 * Cost accounting and budget enforcement for paid provider requests
 * (INTEGRATIONS.md -> DataForSEO). Every paid request is reserved before it
 * is sent and settled with the provider-reported cost afterwards, so
 * concurrent workers cannot overspend a budget together.
 */
interface UsageLedger
{
    public function budget(int $workspaceId, string $provider): ApiBudget;

    public function saveBudget(int $workspaceId, string $provider, ApiBudget $budget, int $userId): void;

    /**
     * Atomically checks the budget and records an open reservation.
     *
     * @throws BudgetExceededException
     */
    public function reserve(int $workspaceId, ?int $projectId, string $provider, string $operation, float $estimatedCost): UsageReservation;

    public function settle(UsageReservation $reservation, float $cost, ?string $providerTaskId = null): void;

    /**
     * The request was not sent (or certainly not charged): nothing is spent.
     */
    public function release(UsageReservation $reservation): void;

    public function summary(int $workspaceId, string $provider): UsageSummary;

    /**
     * Spending per UTC day and operation since a date (open reservations count with their estimate).
     *
     * @return list<array{date: string, operation: string, cost: float, requests: int}> oldest first
     */
    public function dailySpend(int $workspaceId, string $provider, string $sinceDate): array;

    /**
     * Settles reservations left open by a crashed process with their estimated cost.
     *
     * @return int number of reservations closed
     */
    public function closeStale(\DateTimeImmutable $olderThan): int;
}
