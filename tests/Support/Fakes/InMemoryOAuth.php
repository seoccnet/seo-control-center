<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\Mcp\OAuth\OAuthClient;
use SeoControlCenter\Modules\Mcp\OAuth\OAuthRepository;

/**
 * MCP sign-in clients, codes and refresh tokens in memory.
 */
final class InMemoryOAuth implements OAuthRepository
{
    /** @var array<int, OAuthClient> */
    public array $clients = [];

    /** @var array<string, array{clientId: int, userId: int, workspaceId: int, scopes: list<string>, redirectUri: string, codeChallenge: string, expiresAt: string, used: bool}> */
    public array $codes = [];

    /** @var array<string, array{clientId: int, userId: int, workspaceId: int, scopes: list<string>, mcpTokenId: ?int, expiresAt: string, used: bool}> */
    public array $refresh = [];

    public function registerClient(string $name, array $redirectUris, string $authMethod, ?string $secretHash, ?string $ip): OAuthClient
    {
        $id = count($this->clients) + 1;

        return $this->clients[$id] = new OAuthClient($id, Uuid::v7(), $name, $redirectUris, $authMethod, $secretHash);
    }

    public function findClient(string $clientId): ?OAuthClient
    {
        foreach ($this->clients as $client) {
            if ($client->clientId === $clientId) {
                return $client;
            }
        }

        return null;
    }

    public function touchClient(int $id): void {}

    public function createCode(string $codeHash, int $clientId, int $userId, int $workspaceId, array $scopes, string $redirectUri, string $codeChallenge, string $expiresAt): void
    {
        $this->codes[$codeHash] = ['clientId' => $clientId, 'userId' => $userId, 'workspaceId' => $workspaceId, 'scopes' => $scopes, 'redirectUri' => $redirectUri, 'codeChallenge' => $codeChallenge, 'expiresAt' => $expiresAt, 'used' => false];
    }

    public function consumeCode(string $codeHash, string $now): ?array
    {
        $code = $this->codes[$codeHash] ?? null;
        if ($code === null || $code['used'] || $code['expiresAt'] <= $now) {
            return null;
        }
        $this->codes[$codeHash]['used'] = true;

        return ['clientId' => $code['clientId'], 'userId' => $code['userId'], 'workspaceId' => $code['workspaceId'], 'scopes' => $code['scopes'], 'redirectUri' => $code['redirectUri'], 'codeChallenge' => $code['codeChallenge']];
    }

    public function createRefreshToken(string $tokenHash, int $clientId, ?int $mcpTokenId, int $userId, int $workspaceId, array $scopes, string $expiresAt): void
    {
        $this->refresh[$tokenHash] = ['clientId' => $clientId, 'userId' => $userId, 'workspaceId' => $workspaceId, 'scopes' => $scopes, 'mcpTokenId' => $mcpTokenId, 'expiresAt' => $expiresAt, 'used' => false];
    }

    public function consumeRefreshToken(string $tokenHash, string $now): ?array
    {
        $token = $this->refresh[$tokenHash] ?? null;
        if ($token === null || $token['used'] || $token['expiresAt'] <= $now) {
            return null;
        }
        $this->refresh[$tokenHash]['used'] = true;

        return ['clientId' => $token['clientId'], 'userId' => $token['userId'], 'workspaceId' => $token['workspaceId'], 'scopes' => $token['scopes'], 'mcpTokenId' => $token['mcpTokenId']];
    }

    public function deleteExpired(string $before): int
    {
        $count = 0;
        foreach ($this->codes as $hash => $code) {
            if ($code['expiresAt'] < $before) {
                unset($this->codes[$hash]);
                $count++;
            }
        }

        return $count;
    }
}
