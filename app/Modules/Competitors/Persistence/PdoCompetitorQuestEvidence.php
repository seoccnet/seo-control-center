<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Competitors\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from competitors (ADR-035). Indexed existence checks only. */
final readonly class PdoCompetitorQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'competitors.added' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM competitors WHERE created_by = ? AND ' . self::projects($s), [$s->userId, ...$s->projectIds]] : null,
            'competitors.positions' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM competitor_daily d JOIN competitors c ON c.id = d.competitor_id WHERE ' . self::projects($s, 'c.project_id'), $s->projectIds] : null,
        ];
    }
}
