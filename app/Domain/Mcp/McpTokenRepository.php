<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Mcp;

interface McpTokenRepository
{
    /**
     * @param list<McpScope> $scopes
     * @param list<int>|null $projectIds
     * @param string $tokenHash SHA-256 of the plaintext token (32 raw bytes)
     */
    public function create(int $workspaceId, int $userId, string $name, string $tokenHash, string $prefix, array $scopes, ?array $projectIds, int $rateLimitPerMinute, ?string $expiresAt): McpToken;

    public function findByHash(string $tokenHash): ?McpToken;

    public function findById(int $tokenId): ?McpToken;

    /**
     * @return list<McpToken> newest first
     */
    public function forWorkspace(int $workspaceId): array;

    public function findInWorkspace(int $workspaceId, string $publicId): ?McpToken;

    public function revoke(int $tokenId): void;

    public function touch(int $tokenId): void;
}
