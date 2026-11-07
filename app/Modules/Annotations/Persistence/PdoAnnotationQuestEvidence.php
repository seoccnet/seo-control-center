<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Annotations\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Annotations\ImpactWindow;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from the Change Timeline (ADR-035). Indexed existence checks only. */
final readonly class PdoAnnotationQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'annotations.recorded' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM seo_annotations WHERE workspace_id = ? AND created_by = ? AND deleted_at IS NULL AND ' . self::projects($s), [$s->workspaceId, $s->userId, ...$s->projectIds]] : null,
            'annotations.noted' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM seo_annotations WHERE workspace_id = ? AND created_by = ? AND deleted_at IS NULL AND note <> \'\' AND ' . self::projects($s), [$s->workspaceId, $s->userId, ...$s->projectIds]] : null,
            'annotations.window' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM seo_annotations WHERE workspace_id = ? AND created_by = ? AND deleted_at IS NULL AND changed_on <= ? AND ' . self::projects($s), [$s->workspaceId, $s->userId, self::comparisonReady($s), ...$s->projectIds]] : null,
            'annotations.recorded-this-week' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM seo_annotations WHERE workspace_id = ? AND created_by = ? AND created_at >= ? AND ' . self::projects($s), [$s->workspaceId, $s->userId, $s->weekStart . ' 00:00:00', ...$s->projectIds]] : null,
        ];
    }

    /** Last change day whose default before/after comparison (28 full days) is available today. */
    private static function comparisonReady(EvidenceScope $s): string
    {
        return new \DateTimeImmutable($s->today)->modify('-' . (ImpactWindow::COMPARISON_DAYS + 1) . ' days')->format('Y-m-d');
    }
}
