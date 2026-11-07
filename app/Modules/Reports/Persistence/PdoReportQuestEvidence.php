<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Reports\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from report schedules and deliveries (ADR-035). Indexed existence checks only. */
final readonly class PdoReportQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'reports.scheduled' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM report_schedules WHERE workspace_id = ? AND enabled = 1 AND updated_by = ? AND ' . self::projects($s), [$s->workspaceId, $s->userId, ...$s->projectIds]] : null,
            'reports.delivered' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM report_deliveries WHERE error IS NULL AND ' . self::projects($s), $s->projectIds] : null,
        ];
    }
}
