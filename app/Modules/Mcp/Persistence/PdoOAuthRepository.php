<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\Mcp\OAuth\OAuthClient;
use SeoControlCenter\Modules\Mcp\OAuth\OAuthRepository;

final readonly class PdoOAuthRepository implements OAuthRepository
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function registerClient(string $name, array $redirectUris, string $authMethod, ?string $secretHash, ?string $ip): OAuthClient
    {
        $clientId = Uuid::v7($this->clock);
        $this->db->execute(
            'INSERT INTO mcp_oauth_clients (client_id, name, redirect_uris, auth_method, secret_hash, created_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$clientId, mb_substr($name, 0, 120), json_encode($redirectUris, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $authMethod, $secretHash, $ip !== null ? substr($ip, 0, 45) : null, $this->now()],
        );

        return new OAuthClient($this->db->lastInsertId(), $clientId, $name, $redirectUris, $authMethod, $secretHash);
    }

    public function findClient(string $clientId): ?OAuthClient
    {
        if (!Uuid::isValid($clientId)) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT id, client_id, name, redirect_uris, auth_method, secret_hash FROM mcp_oauth_clients WHERE client_id = ?', [$clientId]);
        if ($row === null) {
            return null;
        }
        $uris = json_decode((string) $row['redirect_uris'], true);

        return new OAuthClient(
            (int) $row['id'],
            (string) $row['client_id'],
            (string) $row['name'],
            is_array($uris) ? array_values(array_filter($uris, is_string(...))) : [],
            (string) $row['auth_method'],
            $row['secret_hash'] !== null ? (string) $row['secret_hash'] : null,
        );
    }

    public function touchClient(int $id): void
    {
        $this->db->execute('UPDATE mcp_oauth_clients SET last_used_at = ? WHERE id = ?', [$this->now(), $id]);
    }

    public function createCode(string $codeHash, int $clientId, int $userId, int $workspaceId, array $scopes, string $redirectUri, string $codeChallenge, string $expiresAt): void
    {
        $this->db->execute(
            'INSERT INTO mcp_oauth_codes (code_hash, client_id, user_id, workspace_id, scopes, redirect_uri, code_challenge, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$codeHash, $clientId, $userId, $workspaceId, json_encode($scopes, JSON_THROW_ON_ERROR), mb_substr($redirectUri, 0, 500), $codeChallenge, $expiresAt],
        );
    }

    public function consumeCode(string $codeHash, string $now): ?array
    {
        if ($this->db->execute('UPDATE mcp_oauth_codes SET used_at = ? WHERE code_hash = ? AND used_at IS NULL AND expires_at > ?', [$now, $codeHash, $now]) !== 1) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT client_id, user_id, workspace_id, scopes, redirect_uri, code_challenge FROM mcp_oauth_codes WHERE code_hash = ?', [$codeHash]);

        return $row === null ? null : [
            'clientId' => (int) $row['client_id'],
            'userId' => (int) $row['user_id'],
            'workspaceId' => (int) $row['workspace_id'],
            'scopes' => self::list($row['scopes']),
            'redirectUri' => (string) $row['redirect_uri'],
            'codeChallenge' => (string) $row['code_challenge'],
        ];
    }

    public function createRefreshToken(string $tokenHash, int $clientId, ?int $mcpTokenId, int $userId, int $workspaceId, array $scopes, string $expiresAt): void
    {
        $this->db->execute(
            'INSERT INTO mcp_oauth_refresh_tokens (token_hash, client_id, mcp_token_id, user_id, workspace_id, scopes, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$tokenHash, $clientId, $mcpTokenId, $userId, $workspaceId, json_encode($scopes, JSON_THROW_ON_ERROR), $expiresAt, $this->now()],
        );
    }

    public function consumeRefreshToken(string $tokenHash, string $now): ?array
    {
        if ($this->db->execute('UPDATE mcp_oauth_refresh_tokens SET used_at = ? WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [$now, $tokenHash, $now]) !== 1) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT client_id, user_id, workspace_id, scopes, mcp_token_id FROM mcp_oauth_refresh_tokens WHERE token_hash = ?', [$tokenHash]);

        return $row === null ? null : [
            'clientId' => (int) $row['client_id'],
            'userId' => (int) $row['user_id'],
            'workspaceId' => (int) $row['workspace_id'],
            'scopes' => self::list($row['scopes']),
            'mcpTokenId' => $row['mcp_token_id'] !== null ? (int) $row['mcp_token_id'] : null,
        ];
    }

    public function deleteExpired(string $before): int
    {
        return $this->db->execute('DELETE FROM mcp_oauth_codes WHERE expires_at < ?', [$before])
            + $this->db->execute('DELETE FROM mcp_oauth_refresh_tokens WHERE expires_at < ?', [$before]);
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $json): array
    {
        $values = json_decode((string) $json, true);

        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
