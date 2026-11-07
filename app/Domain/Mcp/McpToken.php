<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Mcp;

/**
 * An MCP access token. It acts for its creator, narrowed to one workspace,
 * its scopes and (optionally) selected projects; only a hash is stored.
 */
final readonly class McpToken
{
    /**
     * @param list<McpScope> $scopes
     * @param list<int>|null $projectIds null = every project the creator can see
     */
    public function __construct(
        public int $id,
        public string $publicId,
        public int $workspaceId,
        public int $createdBy,
        public string $name,
        public string $prefix,
        public array $scopes,
        public ?array $projectIds,
        public int $rateLimitPerMinute,
        public ?string $expiresAt,
        public ?string $revokedAt,
        public ?string $lastUsedAt,
        public string $createdAt,
    ) {}

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null && ($this->expiresAt === null || $this->expiresAt > $now->format('Y-m-d H:i:s'));
    }

    public function allows(McpScope $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function coversProject(int $projectId): bool
    {
        return $this->projectIds === null || in_array($projectId, $this->projectIds, true);
    }
}
