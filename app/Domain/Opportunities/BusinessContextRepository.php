<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/**
 * The project's business profile and the team's business-potential
 * ratings of single keywords. The caller has authorized the project.
 */
interface BusinessContextRepository
{
    public function find(int $projectId): ?BusinessContext;

    public function save(int $projectId, BusinessContext $context, int $userId): void;

    public function delete(int $projectId): void;

    /** @return array<string, int> normalized keyword => rating 0-3 */
    public function ratings(int $projectId): array;

    /** Sets a keyword's rating, or removes it with null (back to the rules). */
    public function rate(int $projectId, string $keyword, ?int $score, int $userId): void;
}
