<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp;

use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Mcp\McpToken;

/**
 * An authenticated MCP request: the token and the user it acts for.
 */
final readonly class McpAccess
{
    public function __construct(
        public McpToken $token,
        public User $user,
    ) {}

    /**
     * Access for a signed-in user inside the app (the AI assistant, ADR-028):
     * every read scope, every project the user can see. Not stored, never a bearer token.
     * Write scopes are left out: the assistant proposes its few writes and
     * the user confirms each one in the chat (ADR-028, ADR-036).
     */
    public static function forSession(User $user, int $workspaceId, \DateTimeImmutable $now): self
    {
        return new self(
            new McpToken(0, '00000000-0000-7000-8000-000000000000', $workspaceId, $user->id, 'AI assistant', 'session', McpScope::reads(), null, 0, null, null, null, $now->format('Y-m-d H:i:s')),
            $user,
        );
    }
}
