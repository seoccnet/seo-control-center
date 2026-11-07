<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Persistence;

use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfile;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository;

final readonly class PdoDataForSeoProfileRepository implements DataForSeoProfileRepository
{
    public function __construct(
        private Connection $db,
        private Encrypter $encrypter,
        private Clock $clock,
    ) {}

    public function find(int $workspaceId): ?DataForSeoProfile
    {
        $row = $this->db->fetchOne(
            'SELECT workspace_id, login, status, last_error, balance, checked_at, created_at FROM dataforseo_profiles WHERE workspace_id = ?',
            [$workspaceId],
        );

        return $row === null ? null : new DataForSeoProfile(
            (int) $row['workspace_id'],
            (string) $row['login'],
            (string) $row['status'],
            $row['last_error'] !== null ? (string) $row['last_error'] : null,
            $row['balance'] !== null ? (float) $row['balance'] : null,
            $row['checked_at'] !== null ? (string) $row['checked_at'] : null,
            (string) $row['created_at'],
        );
    }

    public function credentials(int $workspaceId): ?DataForSeoCredentials
    {
        $row = $this->db->fetchOne('SELECT login, password FROM dataforseo_profiles WHERE workspace_id = ?', [$workspaceId]);
        if ($row === null) {
            return null;
        }

        return new DataForSeoCredentials(
            (string) $row['login'],
            $this->encrypter->decrypt((string) $row['password'], self::context($workspaceId)),
        );
    }

    public function save(int $workspaceId, DataForSeoCredentials $credentials, ?float $balance, int $userId): void
    {
        $now = $this->now();
        $this->db->execute(
            "INSERT INTO dataforseo_profiles (workspace_id, login, password, status, last_error, balance, checked_at, created_by, created_at, updated_at)
             VALUES (?, ?, ?, 'active', NULL, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE login = VALUES(login), password = VALUES(password), status = 'active', last_error = NULL,
                 balance = VALUES(balance), checked_at = VALUES(checked_at), updated_at = VALUES(updated_at)",
            [
                $workspaceId,
                $credentials->login,
                $this->encrypter->encrypt($credentials->password, self::context($workspaceId)),
                $balance,
                $now,
                $userId,
                $now,
                $now,
            ],
        );
    }

    public function recordCheck(int $workspaceId, ?float $balance): void
    {
        $now = $this->now();
        $this->db->execute(
            "UPDATE dataforseo_profiles SET status = 'active', last_error = NULL, balance = ?, checked_at = ?, updated_at = ? WHERE workspace_id = ?",
            [$balance, $now, $now, $workspaceId],
        );
    }

    public function setError(int $workspaceId, string $error): void
    {
        $this->db->execute(
            "UPDATE dataforseo_profiles SET status = 'error', last_error = ?, updated_at = ? WHERE workspace_id = ?",
            [mb_substr($error, 0, 500), $this->now(), $workspaceId],
        );
    }

    public function delete(int $workspaceId): void
    {
        $this->db->execute('DELETE FROM dataforseo_profiles WHERE workspace_id = ?', [$workspaceId]);
    }

    public static function context(int $workspaceId): string
    {
        return 'dataforseo.password:' . $workspaceId;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
