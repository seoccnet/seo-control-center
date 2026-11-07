<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

/**
 * Read-only check whether the work behind a quest step exists in saved data.
 * Implementations run indexed existence queries only: no provider calls, no
 * jobs, no live Google queries.
 */
interface QuestEvidence
{
    public function has(string $signal, EvidenceScope $scope): bool;
}
