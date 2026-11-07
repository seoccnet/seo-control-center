<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentBriefs;

use SeoControlCenter\Domain\Projects\Project;

interface BriefRepository
{
    /** @return list<ContentBrief> */
    public function list(Project $project, string $status, string $search, int $limit, int $offset): array;
    public function count(Project $project, string $status, string $search): int;
    public function find(Project $project, string $publicId): ?ContentBrief;
    /** @param list<string> $evidence */
    public function create(Project $project, BriefInput $input, array $evidence, int $userId, ?string $via = null): ContentBrief;
    /** Atomic optimistic write. False means the revision no longer matches. */
    public function update(Project $project, string $publicId, BriefInput $input, int $revision, ?string $via = null): bool;
    public function remove(Project $project, string $publicId, int $revision): bool;
}
