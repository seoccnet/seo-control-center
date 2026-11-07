<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentBriefs\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from content briefs (ADR-035). Indexed existence checks only. */
final readonly class PdoBriefQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'briefs.created' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM content_briefs WHERE workspace_id = ? AND created_by = ? AND deleted_at IS NULL AND ' . self::projects($s), [$s->workspaceId, $s->userId, ...$s->projectIds]] : null,
            'briefs.created-this-week' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM content_briefs WHERE workspace_id = ? AND created_by = ? AND created_at >= ? AND ' . self::projects($s), [$s->workspaceId, $s->userId, $s->weekStart . ' 00:00:00', ...$s->projectIds]] : null,
        ];
    }
}
