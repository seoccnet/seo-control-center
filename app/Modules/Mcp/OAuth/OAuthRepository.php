<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\OAuth;

/**
 * Registered OAuth clients, authorization codes and refresh tokens of the
 * MCP sign-in. Codes and refresh tokens are looked up by their SHA-256
 * hash and consumed atomically: each can be used once.
 *
 * @phpstan-type Grant array{clientId: int, userId: int, workspaceId: int, scopes: list<string>, redirectUri: string, codeChallenge: string}
 * @phpstan-type Refresh array{clientId: int, userId: int, workspaceId: int, scopes: list<string>, mcpTokenId: ?int}
 */
interface OAuthRepository
{
    /**
     * @param list<string> $redirectUris
     */
    public function registerClient(string $name, array $redirectUris, string $authMethod, ?string $secretHash, ?string $ip): OAuthClient;

    public function findClient(string $clientId): ?OAuthClient;

    public function touchClient(int $id): void;

    /**
     * @param list<string> $scopes
     */
    public function createCode(string $codeHash, int $clientId, int $userId, int $workspaceId, array $scopes, string $redirectUri, string $codeChallenge, string $expiresAt): void;

    /**
     * Marks the code as used and returns it, or null when unknown, used or expired.
     *
     * @return Grant|null
     */
    public function consumeCode(string $codeHash, string $now): ?array;

    /**
     * @param list<string> $scopes
     */
    public function createRefreshToken(string $tokenHash, int $clientId, ?int $mcpTokenId, int $userId, int $workspaceId, array $scopes, string $expiresAt): void;

    /**
     * Marks the refresh token as used and returns it, or null when unknown, used or expired.
     *
     * @return Refresh|null
     */
    public function consumeRefreshToken(string $tokenHash, string $now): ?array;

    /**
     * Deletes codes and refresh tokens that expired before $before.
     */
    public function deleteExpired(string $before): int;
}
