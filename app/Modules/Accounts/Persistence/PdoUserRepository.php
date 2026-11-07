<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Accounts\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Accounts\Email;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Accounts\UserCredentials;
use SeoControlCenter\Domain\Accounts\UserOverview;
use SeoControlCenter\Domain\Accounts\UserRepository;

final readonly class PdoUserRepository implements UserRepository
{
    private const string COLUMNS = 'id, public_id, email, name, system_role, status';

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function findById(int $id): ?User
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?', [$id]);

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function findByEmail(Email $email): ?User
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM users WHERE email = ?', [$email->value]);

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function credentialsByEmail(Email $email): ?UserCredentials
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ', password_hash FROM users WHERE email = ?', [$email->value]);

        return $row !== null ? new UserCredentials($this->hydrate($row), (string) $row['password_hash']) : null;
    }

    public function create(Email $email, string $name, string $passwordHash, SystemRole $systemRole): User
    {
        $now = $this->now();
        $publicId = Uuid::v7($this->clock);
        $this->db->execute(
            'INSERT INTO users (public_id, email, name, password_hash, system_role, status, password_changed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, \'active\', ?, ?, ?)',
            [$publicId, $email->value, $name, $passwordHash, $systemRole->value, $now, $now, $now],
        );

        return new User($this->db->lastInsertId(), $publicId, $email->value, $name, $systemRole, true);
    }

    public function updateProfile(int $userId, string $name, Email $email): void
    {
        $this->db->execute(
            'UPDATE users SET name = ?, email = ?, updated_at = ? WHERE id = ?',
            [$name, $email->value, $this->now(), $userId],
        );
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $now = $this->now();
        $this->db->execute(
            'UPDATE users SET password_hash = ?, password_changed_at = ?, updated_at = ? WHERE id = ?',
            [$passwordHash, $now, $now, $userId],
        );
    }

    public function recordLogin(int $userId): void
    {
        $this->db->execute('UPDATE users SET last_login_at = ? WHERE id = ?', [$this->now(), $userId]);
    }

    public function overview(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT u.id, u.public_id, u.email, u.name, u.system_role, u.status, u.last_login_at,
                    EXISTS (SELECT 1 FROM user_mfa_methods m WHERE m.user_id = u.id AND m.type = 'totp') AS mfa,
                    (SELECT GROUP_CONCAT(w.name ORDER BY w.name SEPARATOR '\n')
                       FROM workspace_users wu JOIN workspaces w ON w.id = wu.workspace_id
                      WHERE wu.user_id = u.id AND w.deleted_at IS NULL) AS workspaces
               FROM users u
              ORDER BY u.status, u.name, u.id
              LIMIT 2000",
        );

        return array_map(fn(array $row): UserOverview => new UserOverview(
            $this->hydrate($row),
            $row['last_login_at'] !== null ? (string) $row['last_login_at'] : null,
            (bool) $row['mfa'],
            $row['workspaces'] !== null && $row['workspaces'] !== '' ? explode("\n", (string) $row['workspaces']) : [],
        ), $rows);
    }

    public function setActive(int $userId, bool $active): void
    {
        $this->db->execute('UPDATE users SET status = ?, updated_at = ? WHERE id = ?', [$active ? 'active' : 'disabled', $this->now(), $userId]);
    }

    public function setSystemRole(int $userId, SystemRole $role): void
    {
        $this->db->execute('UPDATE users SET system_role = ?, updated_at = ? WHERE id = ?', [$role->value, $this->now(), $userId]);
    }

    public function countActiveAdmins(): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM users WHERE system_role = 'admin' AND status = 'active'");
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): User
    {
        return new User(
            (int) $row['id'],
            (string) $row['public_id'],
            (string) $row['email'],
            (string) $row['name'],
            SystemRole::from((string) $row['system_role']),
            $row['status'] === 'active',
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
