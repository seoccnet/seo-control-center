<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Tasks;

use SeoControlCenter\Domain\Projects\Project;

interface TaskRepository
{
    public function find(Project $project, string $opportunityId): ?SeoTask;
    /** @return list<SeoTask> */
    public function list(Project $project, TaskFilter $filter, string $today, int $offset): array;
    public function count(Project $project, TaskFilter $filter, string $today): int;
    /** Creates only for revision zero, otherwise updates the exact matching revision. */
    public function save(Project $project, string $opportunityId, TaskInput $input, int $revision, ?string $via = null): bool;
}
