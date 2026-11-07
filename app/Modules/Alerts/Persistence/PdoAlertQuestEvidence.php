<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from alert settings (ADR-035). Indexed existence checks only. */
final readonly class PdoAlertQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'alerts.preferences' => static fn(EvidenceScope $s): array => ['SELECT 1 FROM notification_settings WHERE user_id = ?', [$s->userId]],
            'alerts.thresholds' => static fn(EvidenceScope $s): ?array => $s->hasProjects() ? ['SELECT 1 FROM project_alert_thresholds WHERE ' . self::projects($s), $s->projectIds] : null,
        ];
    }
}
