<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from opportunities, business context and team tasks (ADR-035). Indexed existence checks only. */
final readonly class PdoOpportunityQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'opportunities.analyzed' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM project_opportunity_runs WHERE ' . self::projects($s), $s->projectIds] : null,
            'opportunities.acted' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM opportunity_status_history h JOIN opportunities o ON o.id = h.opportunity_id WHERE h.changed_by = ? AND ' . self::projects($s, 'o.project_id'), [$s->userId, ...$s->projectIds]] : null,
            'opportunities.completed' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM opportunity_status_history h JOIN opportunities o ON o.id = h.opportunity_id WHERE h.changed_by = ? AND h.to_status = \'done\' AND ' . self::projects($s, 'o.project_id'), [$s->userId, ...$s->projectIds]] : null,
            'opportunities.acted-this-week' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM opportunity_status_history h JOIN opportunities o ON o.id = h.opportunity_id WHERE h.changed_by = ? AND h.created_at >= ? AND ' . self::projects($s, 'o.project_id'), [$s->userId, $s->weekStart . ' 00:00:00', ...$s->projectIds]] : null,
            'opportunities.outcome-measured' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM opportunity_outcomes WHERE workspace_id = ? AND measured_at IS NOT NULL AND ' . self::projects($s), [$s->workspaceId, ...$s->projectIds]] : null,
            'opportunities.business-profile' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM project_business_profiles WHERE updated_by = ? AND ' . self::projects($s), [$s->userId, ...$s->projectIds]] : null,
            'opportunities.keyword-rated' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM opportunity_keyword_ratings WHERE updated_by = ? AND ' . self::projects($s), [$s->userId, ...$s->projectIds]] : null,
            'opportunities.serps' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM keyword_serps WHERE ' . self::projects($s), $s->projectIds] : null,
            'tasks.created' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM opportunity_tasks WHERE workspace_id = ? AND ' . self::projects($s), [$s->workspaceId, ...$s->projectIds]] : null,
            'tasks.brief-linked' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM opportunity_tasks WHERE workspace_id = ? AND brief IS NOT NULL AND ' . self::projects($s), [$s->workspaceId, ...$s->projectIds]] : null,
        ];
    }
}
