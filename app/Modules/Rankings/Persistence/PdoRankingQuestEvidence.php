<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from rank checks (ADR-035). Indexed existence checks only. */
final readonly class PdoRankingQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'rankings.first-check' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM ranking_daily WHERE ' . self::projects($s), $s->projectIds] : null,
            'rankings.second-check' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM ranking_daily WHERE ' . self::projects($s) . ' GROUP BY project_id, device HAVING COUNT(*) >= 2', $s->projectIds] : null,
        ];
    }
}
