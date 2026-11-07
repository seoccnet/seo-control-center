<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Persistence;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Modules\Shared\Persistence\SqlQuestEvidence;

/** Quest Log evidence from MCP tokens (ADR-035). Indexed existence checks only. */
final readonly class PdoMcpQuestEvidence extends SqlQuestEvidence
{
    protected function queries(): array
    {
        return [
            'mcp.token' => static fn(EvidenceScope $s): array => ['SELECT 1 FROM mcp_tokens WHERE workspace_id = ? AND created_by = ?', [$s->workspaceId, $s->userId]],
            'mcp.used' => static fn(EvidenceScope $s): array => ['SELECT 1 FROM mcp_tokens WHERE workspace_id = ? AND created_by = ? AND last_used_at IS NOT NULL', [$s->workspaceId, $s->userId]],
        ];
    }
}
