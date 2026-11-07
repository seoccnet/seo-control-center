<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from backlink snapshots (ADR-035). Indexed existence checks only. */
final readonly class PdoBacklinkQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'backlinks.snapshot' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM backlink_history WHERE ' . self::projects($s), $s->projectIds] : null,
        ];
    }
}
