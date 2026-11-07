<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from local tracking and branches (ADR-035). Indexed existence checks only. */
final readonly class PdoLocalQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'local.setup' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM local_settings WHERE ' . self::projects($s) . ' UNION ALL SELECT 1 FROM local_locations WHERE ' . self::projects($s), [...$s->projectIds, ...$s->projectIds]] : null,
            'local.checked' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM local_rankings WHERE ' . self::projects($s) . ' UNION ALL SELECT 1 FROM local_location_checks WHERE ' . self::projects($s), [...$s->projectIds, ...$s->projectIds]] : null,
        ];
    }
}
