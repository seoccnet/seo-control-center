<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Integrations\ApiBudget;
use SeoControlCenter\Domain\Integrations\BudgetPolicy;
use SeoControlCenter\Domain\Integrations\ProviderResponseStore;
use SeoControlCenter\Domain\Integrations\UsageLedger;
use SeoControlCenter\Domain\Integrations\UsageReservation;
use SeoControlCenter\Domain\Integrations\UsageSummary;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfile;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository;

/**
 * In-memory DataForSEO profiles, usage ledger and raw response store.
 */
final class InMemoryDataForSeo implements DataForSeoProfileRepository, UsageLedger, ProviderResponseStore
{
    /** @var array<int, DataForSeoProfile> */
    public array $profiles = [];

    /** @var array<int, DataForSeoCredentials> */
    public array $credentials = [];

    /** @var array<string, ApiBudget> */
    public array $budgets = [];

    /** @var array<int, array{workspace_id: int, project_id: ?int, provider: string, operation: string, status: string, estimated: float, cost: ?float, task_id: ?string, created_at: string}> */
    public array $usage = [];

    /** @var list<array{workspace_id: int, usage_id: ?int, operation: string, status: int, body: string, expires_at: string}> */
    public array $responses = [];

    public function __construct(private readonly Clock $clock) {}

    public function find(int $workspaceId): ?DataForSeoProfile
    {
        return $this->profiles[$workspaceId] ?? null;
    }

    public function credentials(int $workspaceId): ?DataForSeoCredentials
    {
        return $this->credentials[$workspaceId] ?? null;
    }

    public function save(int $workspaceId, DataForSeoCredentials $credentials, ?float $balance, int $userId): void
    {
        $now = $this->now();
        $this->credentials[$workspaceId] = $credentials;
        $this->profiles[$workspaceId] = new DataForSeoProfile($workspaceId, $credentials->login, 'active', null, $balance, $now, $this->profiles[$workspaceId]->createdAt ?? $now);
    }

    public function recordCheck(int $workspaceId, ?float $balance): void
    {
        $p = $this->profiles[$workspaceId] ?? null;
        if ($p !== null) {
            $this->profiles[$workspaceId] = new DataForSeoProfile($workspaceId, $p->login, 'active', null, $balance, $this->now(), $p->createdAt);
        }
    }

    public function setError(int $workspaceId, string $error): void
    {
        $p = $this->profiles[$workspaceId] ?? null;
        if ($p !== null) {
            $this->profiles[$workspaceId] = new DataForSeoProfile($workspaceId, $p->login, 'error', $error, $p->balance, $p->checkedAt, $p->createdAt);
        }
    }

    public function delete(int $workspaceId): void
    {
        unset($this->profiles[$workspaceId], $this->credentials[$workspaceId]);
    }

    public function budget(int $workspaceId, string $provider): ApiBudget
    {
        return $this->budgets[$workspaceId . ':' . $provider] ?? ApiBudget::unlimited();
    }

    public function saveBudget(int $workspaceId, string $provider, ApiBudget $budget, int $userId): void
    {
        $this->budgets[$workspaceId . ':' . $provider] = $budget;
    }

    public function reserve(int $workspaceId, ?int $projectId, string $provider, string $operation, float $estimatedCost): UsageReservation
    {
        BudgetPolicy::check($this->budget($workspaceId, $provider), $this->summary($workspaceId, $provider), $estimatedCost);
        $id = count($this->usage) + 1;
        $this->usage[$id] = [
            'workspace_id' => $workspaceId,
            'project_id' => $projectId,
            'provider' => $provider,
            'operation' => $operation,
            'status' => 'reserved',
            'estimated' => $estimatedCost,
            'cost' => null,
            'task_id' => null,
            'created_at' => $this->now(),
        ];

        return new UsageReservation($id, $workspaceId, $provider, $operation, $estimatedCost);
    }

    public function settle(UsageReservation $reservation, float $cost, ?string $providerTaskId = null): void
    {
        if (($this->usage[$reservation->id]['status'] ?? null) === 'reserved') {
            $this->usage[$reservation->id]['status'] = 'charged';
            $this->usage[$reservation->id]['cost'] = max(0.0, $cost);
            $this->usage[$reservation->id]['task_id'] = $providerTaskId;
        }
    }

    public function release(UsageReservation $reservation): void
    {
        if (($this->usage[$reservation->id]['status'] ?? null) === 'reserved') {
            $this->usage[$reservation->id]['status'] = 'released';
            $this->usage[$reservation->id]['cost'] = 0.0;
        }
    }

    public function summary(int $workspaceId, string $provider): UsageSummary
    {
        $now = $this->clock->now();
        $day = $now->format('Y-m-d 00:00:00');
        $month = $now->format('Y-m-01 00:00:00');
        $today = $monthTotal = 0.0;
        $requestsToday = $requestsMonth = 0;
        foreach ($this->usage as $row) {
            if ($row['workspace_id'] !== $workspaceId || $row['provider'] !== $provider || $row['status'] === 'released' || $row['created_at'] < $month) {
                continue;
            }
            $amount = $row['cost'] ?? $row['estimated'];
            $monthTotal += $amount;
            ++$requestsMonth;
            if ($row['created_at'] >= $day) {
                $today += $amount;
                ++$requestsToday;
            }
        }

        return new UsageSummary($today, $monthTotal, $requestsToday, $requestsMonth);
    }

    public function dailySpend(int $workspaceId, string $provider, string $sinceDate): array
    {
        $groups = [];
        foreach ($this->usage as $row) {
            if ($row['workspace_id'] !== $workspaceId || $row['provider'] !== $provider || $row['status'] === 'released' || $row['created_at'] < $sinceDate . ' 00:00:00') {
                continue;
            }
            $key = substr($row['created_at'], 0, 10) . '|' . $row['operation'];
            $groups[$key] ??= ['date' => substr($row['created_at'], 0, 10), 'operation' => $row['operation'], 'cost' => 0.0, 'requests' => 0];
            $groups[$key]['cost'] += $row['cost'] ?? $row['estimated'];
            ++$groups[$key]['requests'];
        }
        ksort($groups);

        return array_values($groups);
    }

    public function closeStale(\DateTimeImmutable $olderThan): int
    {
        $closed = 0;
        foreach ($this->usage as $id => $row) {
            if ($row['status'] === 'reserved' && $row['created_at'] < $olderThan->format('Y-m-d H:i:s')) {
                $this->usage[$id]['status'] = 'charged';
                $this->usage[$id]['cost'] = $row['estimated'];
                ++$closed;
            }
        }

        return $closed;
    }

    public function store(int $workspaceId, ?int $usageId, string $provider, string $operation, int $httpStatus, string $body, \DateTimeImmutable $expiresAt): void
    {
        $this->responses[] = [
            'workspace_id' => $workspaceId,
            'usage_id' => $usageId,
            'operation' => $operation,
            'status' => $httpStatus,
            'body' => $body,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ];
    }

    public function purgeExpired(\DateTimeImmutable $now): int
    {
        $before = count($this->responses);
        $this->responses = array_values(array_filter($this->responses, static fn(array $r): bool => $r['expires_at'] >= $now->format('Y-m-d H:i:s')));

        return $before - count($this->responses);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
