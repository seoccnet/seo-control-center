<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Persistence;

use SeoControlCenter\Core\Database\Connection as Db;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Integrations\Connection;
use SeoControlCenter\Domain\Integrations\ConnectionRepository;

final readonly class PdoConnectionRepository implements ConnectionRepository
{
    private const string COLUMNS = 'id, public_id, workspace_id, provider, account_email, status, last_error, created_at';

    public function __construct(
        private Db $db,
        private Clock $clock,
    ) {}

    public function forWorkspace(int $workspaceId): array
    {
        return array_map($this->hydrate(...), $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . " FROM connections WHERE workspace_id = ? AND status <> 'revoked' ORDER BY provider, account_email",
            [$workspaceId],
        ));
    }

    public function findInWorkspace(int $workspaceId, string $publicId): ?Connection
    {
        if (!Uuid::isValid($publicId)) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM connections WHERE workspace_id = ? AND public_id = ?', [$workspaceId, $publicId]);

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?Connection
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM connections WHERE id = ?', [$id]);

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function upsert(int $workspaceId, string $provider, string $accountEmail, string $scopes, int $createdBy): Connection
    {
        $now = $this->now();
        $this->db->execute(
            "INSERT INTO connections (public_id, workspace_id, provider, account_email, scopes, status, last_error, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'active', NULL, ?, ?, ?)
             ON DUPLICATE KEY UPDATE scopes = VALUES(scopes), status = 'active', last_error = NULL, updated_at = VALUES(updated_at)",
            [Uuid::v7($this->clock), $workspaceId, $provider, $accountEmail, $scopes, $createdBy, $now, $now],
        );
        $row = $this->db->fetchOne(
            'SELECT ' . self::COLUMNS . ' FROM connections WHERE workspace_id = ? AND provider = ? AND account_email = ?',
            [$workspaceId, $provider, $accountEmail],
        );
        if ($row === null) {
            throw new \RuntimeException('Connection could not be stored.');
        }

        return $this->hydrate($row);
    }

    public function setStatus(int $connectionId, string $status, ?string $error): void
    {
        $this->db->execute('UPDATE connections SET status = ?, last_error = ?, updated_at = ? WHERE id = ?', [$status, $error, $this->now(), $connectionId]);
    }

    public function storeTokens(int $connectionId, ?string $refreshTokenEnvelope, string $accessTokenEnvelope, string $accessExpiresAt): void
    {
        $this->db->execute(
            'INSERT INTO connection_tokens (connection_id, refresh_token, access_token, access_expires_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE refresh_token = COALESCE(VALUES(refresh_token), refresh_token),
                 access_token = VALUES(access_token), access_expires_at = VALUES(access_expires_at), updated_at = VALUES(updated_at)',
            [$connectionId, $refreshTokenEnvelope, $accessTokenEnvelope, $accessExpiresAt, $this->now()],
        );
    }

    public function tokens(int $connectionId): ?array
    {
        $row = $this->db->fetchOne('SELECT refresh_token, access_token, access_expires_at FROM connection_tokens WHERE connection_id = ?', [$connectionId]);
        if ($row === null) {
            return null;
        }

        return [
            'refresh' => $row['refresh_token'] !== null ? (string) $row['refresh_token'] : null,
            'access' => $row['access_token'] !== null ? (string) $row['access_token'] : null,
            'access_expires_at' => $row['access_expires_at'] !== null ? (string) $row['access_expires_at'] : null,
        ];
    }

    public function deleteTokens(int $connectionId): void
    {
        $this->db->execute('DELETE FROM connection_tokens WHERE connection_id = ?', [$connectionId]);
    }

    public function replaceProperties(int $connectionId, array $properties): void
    {
        $this->db->transaction(function (Db $db) use ($connectionId, $properties): void {
            $db->execute('DELETE FROM google_properties WHERE connection_id = ?', [$connectionId]);
            $now = $this->now();
            foreach ($properties as $property) {
                $db->execute(
                    'INSERT INTO google_properties (connection_id, site_url, permission_level, discovered_at) VALUES (?, ?, ?, ?)',
                    [$connectionId, $property['site_url'], $property['permission_level'], $now],
                );
            }
        });
    }

    public function properties(int $connectionId): array
    {
        return array_map(static fn(array $r): array => [
            'site_url' => (string) $r['site_url'],
            'permission_level' => (string) $r['permission_level'],
        ], $this->db->fetchAll('SELECT site_url, permission_level FROM google_properties WHERE connection_id = ? ORDER BY site_url', [$connectionId]));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Connection
    {
        return new Connection(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            (string) $row['provider'],
            (string) $row['account_email'],
            (string) $row['status'],
            $row['last_error'] !== null ? (string) $row['last_error'] : null,
            (string) $row['created_at'],
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
