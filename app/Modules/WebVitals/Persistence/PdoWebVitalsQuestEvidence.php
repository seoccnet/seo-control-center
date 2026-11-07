<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\WebVitals\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from Core Web Vitals (ADR-035). Indexed existence checks only. */
final readonly class PdoWebVitalsQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'web-vitals.key' => static fn(EvidenceScope $s): array => ['SELECT 1 FROM crux_settings WHERE workspace_id = ?', [$s->workspaceId]],
            'web-vitals.watched' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM web_vitals_watches WHERE ' . self::projects($s), $s->projectIds] : null,
        ];
    }
}
