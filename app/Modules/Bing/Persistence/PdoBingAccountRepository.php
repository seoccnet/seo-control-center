<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing\Persistence;

use SensitiveParameter;
use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Bing\BingAccount;
use SeoControlCenter\Modules\Bing\BingAccountRepository;

final readonly class PdoBingAccountRepository implements BingAccountRepository
{
    public function __construct(
        private Connection $db,
        private Encrypter $encrypter,
        private Clock $clock,
    ) {}

    public function find(int $workspaceId): ?BingAccount
    {
        $row = $this->db->fetchOne('SELECT workspace_id, sites, status, last_error, checked_at, created_at FROM bing_profiles WHERE workspace_id = ?', [$workspaceId]);
        if ($row === null) {
            return null;
        }
        $sites = json_decode((string) $row['sites'], true);

        return new BingAccount(
            (int) $row['workspace_id'],
            (string) $row['status'],
            $row['last_error'] !== null ? (string) $row['last_error'] : null,
            is_array($sites) ? array_values(array_filter($sites, is_string(...))) : [],
            $row['checked_at'] !== null ? (string) $row['checked_at'] : null,
            (string) $row['created_at'],
        );
    }

    public function apiKey(int $workspaceId): ?string
    {
        $value = $this->db->fetchValue('SELECT api_key FROM bing_profiles WHERE workspace_id = ?', [$workspaceId]);

        return $value === null ? null : $this->encrypter->decrypt((string) $value, self::context($workspaceId));
    }

    public function save(int $workspaceId, #[SensitiveParameter] string $apiKey, array $sites, int $userId): void
    {
        $now = $this->now();
        $this->db->execute(
            "INSERT INTO bing_profiles (workspace_id, api_key, sites, status, last_error, checked_at, created_by, created_at, updated_at)
             VALUES (?, ?, ?, 'active', NULL, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE api_key = VALUES(api_key), sites = VALUES(sites), status = 'active', last_error = NULL,
                 checked_at = VALUES(checked_at), updated_at = VALUES(updated_at)",
            [$workspaceId, $this->encrypter->encrypt($apiKey, self::context($workspaceId)), self::json($sites), $now, $userId, $now, $now],
        );
    }

    public function updateSites(int $workspaceId, array $sites): void
    {
        $now = $this->now();
        $this->db->execute(
            "UPDATE bing_profiles SET sites = ?, status = 'active', last_error = NULL, checked_at = ?, updated_at = ? WHERE workspace_id = ?",
            [self::json($sites), $now, $now, $workspaceId],
        );
    }

    public function setError(int $workspaceId, string $error): void
    {
        $this->db->execute(
            "UPDATE bing_profiles SET status = 'error', last_error = ?, updated_at = ? WHERE workspace_id = ?",
            [mb_substr($error, 0, 500), $this->now(), $workspaceId],
        );
    }

    public function delete(int $workspaceId): void
    {
        $this->db->execute('DELETE FROM bing_profiles WHERE workspace_id = ?', [$workspaceId]);
    }

    public static function context(int $workspaceId): string
    {
        return 'bing.api_key:' . $workspaceId;
    }

    /**
     * @param list<string> $sites
     */
    private static function json(array $sites): string
    {
        return json_encode(array_values($sites), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
