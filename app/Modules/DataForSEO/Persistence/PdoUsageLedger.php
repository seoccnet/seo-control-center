<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Integrations\ApiBudget;
use SeoControlCenter\Domain\Integrations\BudgetPolicy;
use SeoControlCenter\Domain\Integrations\UsageLedger;
use SeoControlCenter\Domain\Integrations\UsageReservation;
use SeoControlCenter\Domain\Integrations\UsageSummary;

/**
 * Ledger in `api_usage` with limits in `api_budgets`. A reservation locks
 * the workspace's budget row (SELECT ... FOR UPDATE), so the check and the
 * insert are atomic across concurrent workers.
 */
final readonly class PdoUsageLedger implements UsageLedger
{
    private const int STALE_BATCH = 1000;

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function budget(int $workspaceId, string $provider): ApiBudget
    {
        $row = $this->db->fetchOne(
            'SELECT daily_limit, monthly_limit, operation_limit FROM api_budgets WHERE workspace_id = ? AND provider = ?',
            [$workspaceId, $provider],
        );

        return $row === null ? ApiBudget::unlimited() : self::budgetFrom($row);
    }

    public function saveBudget(int $workspaceId, string $provider, ApiBudget $budget, int $userId): void
    {
        $this->db->execute(
            'INSERT INTO api_budgets (workspace_id, provider, daily_limit, monthly_limit, operation_limit, updated_by, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE daily_limit = VALUES(daily_limit), monthly_limit = VALUES(monthly_limit),
                 operation_limit = VALUES(operation_limit), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$workspaceId, $provider, $budget->dailyLimit, $budget->monthlyLimit, $budget->operationLimit, $userId, $this->now()],
        );
    }

    public function reserve(int $workspaceId, ?int $projectId, string $provider, string $operation, float $estimatedCost): UsageReservation
    {
        return $this->db->transaction(function (Connection $db) use ($workspaceId, $projectId, $provider, $operation, $estimatedCost): UsageReservation {
            // Make sure the lock row exists, then hold it until commit.
            $db->execute(
                'INSERT IGNORE INTO api_budgets (workspace_id, provider, updated_at) VALUES (?, ?, ?)',
                [$workspaceId, $provider, $this->now()],
            );
            $row = $db->fetchOne(
                'SELECT daily_limit, monthly_limit, operation_limit FROM api_budgets WHERE workspace_id = ? AND provider = ? FOR UPDATE',
                [$workspaceId, $provider],
            );
            $budget = $row === null ? ApiBudget::unlimited() : self::budgetFrom($row);
            BudgetPolicy::check($budget, $this->summary($workspaceId, $provider), $estimatedCost);

            $db->execute(
                "INSERT INTO api_usage (workspace_id, project_id, provider, operation, status, estimated_cost, created_at)
                 VALUES (?, ?, ?, ?, 'reserved', ?, ?)",
                [$workspaceId, $projectId, $provider, $operation, $estimatedCost, $this->now()],
            );

            return new UsageReservation($db->lastInsertId(), $workspaceId, $provider, $operation, $estimatedCost);
        });
    }

    public function settle(UsageReservation $reservation, float $cost, ?string $providerTaskId = null): void
    {
        $this->db->execute(
            "UPDATE api_usage SET status = 'charged', cost = ?, provider_task_id = ?, settled_at = ? WHERE id = ? AND status = 'reserved'",
            [max(0.0, $cost), $providerTaskId !== null ? mb_substr($providerTaskId, 0, 64) : null, $this->now(), $reservation->id],
        );
    }

    public function release(UsageReservation $reservation): void
    {
        $this->db->execute(
            "UPDATE api_usage SET status = 'released', cost = 0, settled_at = ? WHERE id = ? AND status = 'reserved'",
            [$this->now(), $reservation->id],
        );
    }

    public function summary(int $workspaceId, string $provider): UsageSummary
    {
        $now = $this->clock->now();
        $dayStart = $now->format('Y-m-d 00:00:00');
        // Open reservations count with their estimate until settled.
        $row = $this->db->fetchOne(
            "SELECT
                 COALESCE(SUM(CASE WHEN created_at >= ? THEN COALESCE(cost, estimated_cost) ELSE 0 END), 0) AS today,
                 COALESCE(SUM(COALESCE(cost, estimated_cost)), 0) AS month,
                 COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) AS requests_today,
                 COUNT(*) AS requests_month
             FROM api_usage
             WHERE workspace_id = ? AND provider = ? AND created_at >= ? AND status IN ('reserved', 'charged')",
            [$dayStart, $dayStart, $workspaceId, $provider, $now->format('Y-m-01 00:00:00')],
        );

        return new UsageSummary(
            (float) ($row['today'] ?? 0),
            (float) ($row['month'] ?? 0),
            (int) ($row['requests_today'] ?? 0),
            (int) ($row['requests_month'] ?? 0),
        );
    }

    public function dailySpend(int $workspaceId, string $provider, string $sinceDate): array
    {
        return array_map(
            static fn(array $row): array => ['date' => (string) $row['day'], 'operation' => (string) $row['operation'], 'cost' => (float) $row['cost'], 'requests' => (int) $row['requests']],
            $this->db->fetchAll(
                "SELECT DATE(created_at) AS day, operation, SUM(COALESCE(cost, estimated_cost)) AS cost, COUNT(*) AS requests
                 FROM api_usage
                 WHERE workspace_id = ? AND provider = ? AND created_at >= ? AND status IN ('reserved', 'charged')
                 GROUP BY DATE(created_at), operation ORDER BY day, operation",
                [$workspaceId, $provider, $sinceDate . ' 00:00:00'],
            ),
        );
    }

    public function closeStale(\DateTimeImmutable $olderThan): int
    {
        return $this->db->execute(
            "UPDATE api_usage SET status = 'charged', cost = estimated_cost, settled_at = ?
             WHERE status = 'reserved' AND created_at < ? LIMIT " . self::STALE_BATCH,
            [$this->now(), $olderThan->format('Y-m-d H:i:s')],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function budgetFrom(array $row): ApiBudget
    {
        $value = static fn(mixed $v): ?float => $v === null ? null : (float) $v;

        return new ApiBudget($value($row['daily_limit']), $value($row['monthly_limit']), $value($row['operation_limit']));
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
