<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from AI assistant conversations (ADR-035). Indexed existence checks only. */
final readonly class PdoAssistantQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'assistant.asked' => static fn(EvidenceScope $s): array => ['SELECT 1 FROM assistant_conversations WHERE workspace_id = ? AND user_id = ?', [$s->workspaceId, $s->userId]],
        ];
    }
}
