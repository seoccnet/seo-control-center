<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from site audits (ADR-035). Indexed existence checks only. */
final readonly class PdoSiteAuditQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'site-audit.started' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM site_audits WHERE workspace_id = ? AND started_by = ? AND ' . self::projects($s), [$s->workspaceId, $s->userId, ...$s->projectIds]] : null,
            'site-audit.finished' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM site_audits WHERE workspace_id = ? AND status = \'completed\' AND ' . self::projects($s), [$s->workspaceId, ...$s->projectIds]] : null,
        ];
    }
}
