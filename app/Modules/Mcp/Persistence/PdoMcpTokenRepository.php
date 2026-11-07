<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Mcp\McpToken;
use SeoControlCenter\Domain\Mcp\McpTokenRepository;

final readonly class PdoMcpTokenRepository implements McpTokenRepository
{
    private const string COLUMNS = 'id, public_id, workspace_id, created_by, name, token_prefix, scopes, project_ids, rate_limit, expires_at, revoked_at, last_used_at, created_at';

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function create(int $workspaceId, int $userId, string $name, string $tokenHash, string $prefix, array $scopes, ?array $projectIds, int $rateLimitPerMinute, ?string $expiresAt): McpToken
    {
        $publicId = Uuid::v7($this->clock);
        $this->db->execute(
            'INSERT INTO mcp_tokens (public_id, workspace_id, created_by, name, token_hash, token_prefix, scopes, project_ids, rate_limit, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $publicId,
                $workspaceId,
                $userId,
                mb_substr($name, 0, 120),
                $tokenHash,
                $prefix,
                implode(',', array_map(static fn(McpScope $s): string => $s->value, $scopes)),
                $projectIds !== null ? json_encode(array_values($projectIds), JSON_THROW_ON_ERROR) : null,
                $rateLimitPerMinute,
                $expiresAt,
                $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );

        return $this->findInWorkspace($workspaceId, $publicId) ?? throw new \RuntimeException('Token was not stored.');
    }

    public function findByHash(string $tokenHash): ?McpToken
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM mcp_tokens WHERE token_hash = ?', [$tokenHash]);

        return $row === null ? null : self::token($row);
    }

    public function findById(int $tokenId): ?McpToken
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM mcp_tokens WHERE id = ?', [$tokenId]);

        return $row === null ? null : self::token($row);
    }

    public function forWorkspace(int $workspaceId): array
    {
        return array_map(self::token(...), $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM mcp_tokens WHERE workspace_id = ? ORDER BY created_at DESC, id DESC',
            [$workspaceId],
        ));
    }

    public function findInWorkspace(int $workspaceId, string $publicId): ?McpToken
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM mcp_tokens WHERE workspace_id = ? AND public_id = ?', [$workspaceId, $publicId]);

        return $row === null ? null : self::token($row);
    }

    public function revoke(int $tokenId): void
    {
        $this->db->execute('UPDATE mcp_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [$this->clock->now()->format('Y-m-d H:i:s'), $tokenId]);
    }

    public function touch(int $tokenId): void
    {
        // At most one write per minute and token.
        $now = $this->clock->now();
        $this->db->execute(
            'UPDATE mcp_tokens SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)',
            [$now->format('Y-m-d H:i:s'), $tokenId, $now->modify('-1 minute')->format('Y-m-d H:i:s')],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function token(array $row): McpToken
    {
        $scopes = [];
        foreach (explode(',', (string) $row['scopes']) as $value) {
            $scope = McpScope::tryFrom($value);
            if ($scope !== null) {
                $scopes[] = $scope;
            }
        }
        $projects = null;
        if ($row['project_ids'] !== null) {
            $decoded = json_decode((string) $row['project_ids'], true);
            $projects = array_values(array_map('intval', array_filter(is_array($decoded) ? $decoded : [], 'is_int')));
        }

        return new McpToken(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            (int) $row['created_by'],
            (string) $row['name'],
            (string) $row['token_prefix'],
            $scopes,
            $projects,
            (int) $row['rate_limit'],
            $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
            $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
            $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
            (string) $row['created_at'],
        );
    }
}
