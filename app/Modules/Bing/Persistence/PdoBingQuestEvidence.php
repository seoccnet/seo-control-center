<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from Bing Webmaster Tools (ADR-035). Indexed existence checks only. */
final readonly class PdoBingQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'bing.connected' => static fn(EvidenceScope $s): array => ['SELECT 1 FROM bing_profiles WHERE workspace_id = ?', [$s->workspaceId]],
            'bing.linked' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM bing_project_sites WHERE workspace_id = ? AND ' . self::projects($s), [$s->workspaceId, ...$s->projectIds]] : null,
        ];
    }
}
