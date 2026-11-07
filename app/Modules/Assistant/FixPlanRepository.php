<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant;

/**
 * Fix plans are looked up with their workspace and user: private to the user who asked for them.
 *
 * @phpstan-import-type FixRow from FixPlan
 */
interface FixPlanRepository
{
    /**
     * @param list<FixRow> $rows
     */
    public function save(int $workspaceId, int $projectId, int $userId, string $title, array $rows): FixPlan;

    public function find(int $workspaceId, int $userId, string $publicId): ?FixPlan;
}
