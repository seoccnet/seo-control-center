<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\PageMonitoring\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from page watches (ADR-035). Indexed existence checks only. */
final readonly class PdoPageMonitoringQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'page-monitoring.watched' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM page_watches WHERE ' . self::projects($s), $s->projectIds] : null,
            'page-monitoring.checked' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM page_watches WHERE checked_at IS NOT NULL AND ' . self::projects($s), $s->projectIds] : null,
        ];
    }
}
