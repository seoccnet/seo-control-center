<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Mcp\McpToken;
use SeoControlCenter\Domain\Mcp\McpTokenRepository;

final class InMemoryMcpTokens implements McpTokenRepository
{
    /** @var array<int, McpToken> */
    public array $tokens = [];

    /** @var array<int, string> token ID => hash */
    public array $hashes = [];

    public function __construct(private readonly Clock $clock) {}

    public function create(int $workspaceId, int $userId, string $name, string $tokenHash, string $prefix, array $scopes, ?array $projectIds, int $rateLimitPerMinute, ?string $expiresAt): McpToken
    {
        $id = count($this->tokens) + 1;
        $this->hashes[$id] = $tokenHash;

        return $this->tokens[$id] = new McpToken($id, Uuid::v7($this->clock), $workspaceId, $userId, $name, $prefix, $scopes, $projectIds, $rateLimitPerMinute, $expiresAt, null, null, $this->now());
    }

    public function findByHash(string $tokenHash): ?McpToken
    {
        $id = array_search($tokenHash, $this->hashes, true);

        return $id === false ? null : $this->tokens[$id];
    }

    public function findById(int $tokenId): ?McpToken
    {
        return $this->tokens[$tokenId] ?? null;
    }

    public function forWorkspace(int $workspaceId): array
    {
        return array_values(array_reverse(array_filter($this->tokens, static fn(McpToken $t): bool => $t->workspaceId === $workspaceId)));
    }

    public function findInWorkspace(int $workspaceId, string $publicId): ?McpToken
    {
        foreach ($this->tokens as $token) {
            if ($token->workspaceId === $workspaceId && $token->publicId === $publicId) {
                return $token;
            }
        }

        return null;
    }

    public function revoke(int $tokenId): void
    {
        $this->replace($tokenId, revokedAt: $this->now());
    }

    public function touch(int $tokenId): void
    {
        $this->replace($tokenId, lastUsedAt: $this->now());
    }

    public function expire(int $tokenId): void
    {
        $this->replace($tokenId, expiresAt: '2000-01-01 00:00:00');
    }

    private function replace(int $tokenId, ?string $revokedAt = null, ?string $lastUsedAt = null, ?string $expiresAt = null): void
    {
        $t = $this->tokens[$tokenId];
        $this->tokens[$tokenId] = new McpToken($t->id, $t->publicId, $t->workspaceId, $t->createdBy, $t->name, $t->prefix, $t->scopes, $t->projectIds, $t->rateLimitPerMinute, $expiresAt ?? $t->expiresAt, $revokedAt ?? $t->revokedAt, $lastUsedAt ?? $t->lastUsedAt, $t->createdAt);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
