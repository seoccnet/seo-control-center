<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Indexing\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from URL inspections (ADR-035). Indexed existence checks only. */
final readonly class PdoIndexingQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'indexing.inspected' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM google_inspections WHERE ' . self::projects($s), $s->projectIds] : null,
        ];
    }
}
