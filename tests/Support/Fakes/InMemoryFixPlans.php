<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\Assistant\FixPlan;
use SeoControlCenter\Modules\Assistant\FixPlanRepository;

/**
 * AI fix plans in memory.
 */
final class InMemoryFixPlans implements FixPlanRepository
{
    /** @var list<FixPlan> */
    public array $plans = [];

    public function save(int $workspaceId, int $projectId, int $userId, string $title, array $rows): FixPlan
    {
        return $this->plans[] = new FixPlan(Uuid::v7(), $workspaceId, $projectId, $userId, $title, $rows, '2026-10-03 12:00:00');
    }

    public function find(int $workspaceId, int $userId, string $publicId): ?FixPlan
    {
        foreach ($this->plans as $plan) {
            if ($plan->workspaceId === $workspaceId && $plan->userId === $userId && $plan->publicId === $publicId) {
                return $plan;
            }
        }

        return null;
    }
}
