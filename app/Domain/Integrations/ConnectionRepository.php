<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Integrations;

/**
 * Connections and their encrypted tokens. Lookups by public ID are always
 * scoped to a workspace; findById() is for background jobs that already
 * hold a trusted internal ID.
 */
interface ConnectionRepository
{
    /**
     * @return list<Connection>
     */
    public function forWorkspace(int $workspaceId): array;

    public function findInWorkspace(int $workspaceId, string $publicId): ?Connection;

    public function findById(int $id): ?Connection;

    /**
     * Creates the connection, or reactivates the existing one for the same account.
     */
    public function upsert(int $workspaceId, string $provider, string $accountEmail, string $scopes, int $createdBy): Connection;

    public function setStatus(int $connectionId, string $status, ?string $error): void;

    /**
     * @param string|null $refreshTokenEnvelope null keeps the stored refresh token
     */
    public function storeTokens(int $connectionId, ?string $refreshTokenEnvelope, string $accessTokenEnvelope, string $accessExpiresAt): void;

    /**
     * @return array{refresh: ?string, access: ?string, access_expires_at: ?string}|null encrypted envelopes
     */
    public function tokens(int $connectionId): ?array;

    public function deleteTokens(int $connectionId): void;

    /**
     * @param list<array{site_url: string, permission_level: string}> $properties
     */
    public function replaceProperties(int $connectionId, array $properties): void;

    /**
     * @return list<array{site_url: string, permission_level: string}>
     */
    public function properties(int $connectionId): array;
}
