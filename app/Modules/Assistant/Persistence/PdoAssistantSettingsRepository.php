<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant\Persistence;

use SensitiveParameter;
use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Assistant\AssistantSettings;
use SeoControlCenter\Modules\Assistant\AssistantSettingsRepository;

final readonly class PdoAssistantSettingsRepository implements AssistantSettingsRepository
{
    public function __construct(
        private Connection $db,
        private Encrypter $encrypter,
        private Clock $clock,
    ) {}

    public function find(int $workspaceId): ?AssistantSettings
    {
        $row = $this->db->fetchOne('SELECT workspace_id, provider, model, provider_workspace, status, last_error, created_at FROM assistant_settings WHERE workspace_id = ?', [$workspaceId]);

        return $row === null ? null : new AssistantSettings(
            (int) $row['workspace_id'],
            (string) $row['provider'],
            (string) $row['model'],
            (string) $row['status'],
            $row['last_error'] !== null ? (string) $row['last_error'] : null,
            (string) $row['created_at'],
            $row['provider_workspace'] !== null ? (string) $row['provider_workspace'] : null,
        );
    }

    public function apiKey(int $workspaceId): ?string
    {
        $value = $this->db->fetchValue('SELECT api_key FROM assistant_settings WHERE workspace_id = ?', [$workspaceId]);

        return $value === null ? null : $this->encrypter->decrypt((string) $value, self::context($workspaceId));
    }

    public function save(int $workspaceId, string $provider, #[SensitiveParameter] string $apiKey, string $model, int $userId, ?string $providerWorkspace = null): void
    {
        $now = $this->now();
        $this->db->execute(
            "INSERT INTO assistant_settings (workspace_id, provider, api_key, model, provider_workspace, status, last_error, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'active', NULL, ?, ?, ?)
             ON DUPLICATE KEY UPDATE provider = VALUES(provider), api_key = VALUES(api_key), model = VALUES(model), provider_workspace = VALUES(provider_workspace),
                                     status = 'active', last_error = NULL, updated_at = VALUES(updated_at)",
            [$workspaceId, $provider, $this->encrypter->encrypt($apiKey, self::context($workspaceId)), $model, $providerWorkspace, $userId, $now, $now],
        );
    }

    public function setModel(int $workspaceId, string $model): void
    {
        $this->db->execute('UPDATE assistant_settings SET model = ?, updated_at = ? WHERE workspace_id = ?', [$model, $this->now(), $workspaceId]);
    }

    public function setError(int $workspaceId, ?string $error): void
    {
        $this->db->execute(
            'UPDATE assistant_settings SET status = ?, last_error = ?, updated_at = ? WHERE workspace_id = ?',
            [$error === null ? 'active' : 'error', $error !== null ? mb_substr($error, 0, 500) : null, $this->now(), $workspaceId],
        );
    }

    public function delete(int $workspaceId): void
    {
        $this->db->execute('DELETE FROM assistant_settings WHERE workspace_id = ?', [$workspaceId]);
    }

    public static function context(int $workspaceId): string
    {
        return 'assistant.key.' . $workspaceId;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
