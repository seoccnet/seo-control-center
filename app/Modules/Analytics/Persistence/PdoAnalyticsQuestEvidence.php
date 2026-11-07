<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from Google Analytics 4 (ADR-035). Indexed existence checks only. */
final readonly class PdoAnalyticsQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'ga4.connected' => static fn(EvidenceScope $s): array => ['SELECT 1 FROM ga4_settings WHERE workspace_id = ?', [$s->workspaceId]],
            'ga4.linked' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM ga4_project_properties WHERE ' . self::projects($s), $s->projectIds] : null,
            'ga4.business-events' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM business_events WHERE workspace_id = ? AND ' . self::projects($s), [$s->workspaceId, ...$s->projectIds]] : null,
            'ga4.business-event-observed' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM ga4_event_days d JOIN business_events e ON e.project_id = d.project_id AND e.event_name = d.event_name WHERE d.total_count > 0 AND e.workspace_id = ? AND ' . self::projects($s, 'd.project_id'), [$s->workspaceId, ...$s->projectIds]] : null,
        ];
    }
}
