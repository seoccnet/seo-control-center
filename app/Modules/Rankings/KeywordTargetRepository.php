<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings;

/**
 * The page a project wants to rank for each tracked keyword (1.8.0).
 * Keywords are addressed by public ID within the project.
 */
interface KeywordTargetRepository
{
    /**
     * @return array<string, string> keyword public ID => target URL
     */
    public function targets(int $projectId): array;

    /**
     * Sets (or, with null, clears) targets of keywords of the project;
     * unknown keywords are skipped.
     *
     * @param array<string, ?string> $targets keyword public ID => URL or null
     * @return int keywords changed
     */
    public function set(int $projectId, array $targets, int $userId): int;
}
