<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Integrations\Connection;
use SeoControlCenter\Domain\Integrations\ConnectionRepository;
use SeoControlCenter\Domain\Integrations\OAuthClientCredentials;
use SeoControlCenter\Domain\Integrations\OAuthClientRepository;
use SeoControlCenter\Domain\SearchConsole\ProjectPropertyLink;
use SeoControlCenter\Domain\SearchConsole\ProjectPropertyRepository;

/**
 * In-memory connections, OAuth client settings and project links.
 * Mirrors the PDO repositories; those are verified against MySQL in tests/Integration.
 */
final class InMemoryIntegrations implements ConnectionRepository, OAuthClientRepository, ProjectPropertyRepository
{
    /** @var array<int, Connection> */
    public array $connections = [];

    /** @var array<int, array{refresh: ?string, access: ?string, access_expires_at: ?string}> */
    public array $tokens = [];

    /** @var array<int, list<array{site_url: string, permission_level: string}>> */
    public array $properties = [];

    /** @var array<string, OAuthClientCredentials> */
    public array $clients = [];

    /** @var array<int, ProjectPropertyLink> */
    public array $links = [];

    private int $nextId = 1000;

    public function forWorkspace(int $workspaceId): array
    {
        return array_values(array_filter($this->connections, static fn(Connection $c): bool => $c->workspaceId === $workspaceId && $c->status !== 'revoked'));
    }

    public function findInWorkspace(int $workspaceId, string $publicId): ?Connection
    {
        foreach ($this->connections as $c) {
            if ($c->workspaceId === $workspaceId && $c->publicId === $publicId) {
                return $c;
            }
        }

        return null;
    }

    public function findById(int $id): ?Connection
    {
        return $this->connections[$id] ?? null;
    }

    public function upsert(int $workspaceId, string $provider, string $accountEmail, string $scopes, int $createdBy): Connection
    {
        foreach ($this->connections as $c) {
            if ($c->workspaceId === $workspaceId && $c->provider === $provider && $c->accountEmail === $accountEmail) {
                return $this->connections[$c->id] = new Connection($c->id, $c->publicId, $workspaceId, $provider, $accountEmail, 'active', null, $c->createdAt);
            }
        }
        $id = $this->nextId++;

        return $this->connections[$id] = new Connection($id, Uuid::v7(), $workspaceId, $provider, $accountEmail, 'active', null, '2026-09-26 10:00:00');
    }

    public function setStatus(int $connectionId, string $status, ?string $error): void
    {
        $c = $this->connections[$connectionId];
        $this->connections[$connectionId] = new Connection($c->id, $c->publicId, $c->workspaceId, $c->provider, $c->accountEmail, $status, $error, $c->createdAt);
    }

    public function storeTokens(int $connectionId, ?string $refreshTokenEnvelope, string $accessTokenEnvelope, string $accessExpiresAt): void
    {
        $this->tokens[$connectionId] = [
            'refresh' => $refreshTokenEnvelope ?? ($this->tokens[$connectionId]['refresh'] ?? null),
            'access' => $accessTokenEnvelope,
            'access_expires_at' => $accessExpiresAt,
        ];
    }

    public function tokens(int $connectionId): ?array
    {
        return $this->tokens[$connectionId] ?? null;
    }

    public function deleteTokens(int $connectionId): void
    {
        unset($this->tokens[$connectionId]);
    }

    public function replaceProperties(int $connectionId, array $properties): void
    {
        $this->properties[$connectionId] = $properties;
    }

    public function properties(int $connectionId): array
    {
        return $this->properties[$connectionId] ?? [];
    }

    public function get(string $provider): ?OAuthClientCredentials
    {
        return $this->clients[$provider] ?? null;
    }

    public function save(string $provider, OAuthClientCredentials $credentials, int $userId): void
    {
        $this->clients[$provider] = $credentials;
    }

    public function linkFor(int $projectId): ?ProjectPropertyLink
    {
        return $this->links[$projectId] ?? null;
    }

    public function link(int $projectId, int $connectionId, string $siteUrl, int $userId): void
    {
        $this->links[$projectId] = new ProjectPropertyLink($projectId, $connectionId, $siteUrl, '2026-09-26 10:00:00');
    }

    public function unlink(int $projectId): void
    {
        unset($this->links[$projectId]);
    }

    public function unlinkConnection(int $connectionId): int
    {
        $count = 0;
        foreach ($this->links as $projectId => $link) {
            if ($link->connectionId === $connectionId) {
                unset($this->links[$projectId]);
                ++$count;
            }
        }

        return $count;
    }

    /** @var array<int, int> project id => workspace id, for linkedProjects() */
    public array $projectWorkspaces = [];

    public function linkedProjects(): array
    {
        $result = [];
        foreach (array_keys($this->links) as $projectId) {
            $result[] = ['projectId' => $projectId, 'workspaceId' => $this->projectWorkspaces[$projectId] ?? 0];
        }

        return $result;
    }
}
