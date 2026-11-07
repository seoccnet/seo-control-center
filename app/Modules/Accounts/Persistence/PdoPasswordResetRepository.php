<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Accounts\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\PasswordResetRepository;

final readonly class PdoPasswordResetRepository implements PasswordResetRepository
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function create(int $userId, string $tokenHash, string $expiresAt, ?string $ip): void
    {
        $this->db->execute(
            'INSERT INTO password_resets (user_id, token_hash, created_at, expires_at, ip) VALUES (?, ?, ?, ?, ?)',
            [$userId, $tokenHash, $this->clock->now()->format('Y-m-d H:i:s'), $expiresAt, $ip],
        );
    }

    public function findValid(string $tokenHash, string $now): ?int
    {
        $userId = $this->db->fetchValue(
            'SELECT user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [$tokenHash, $now],
        );

        return $userId !== null ? (int) $userId : null;
    }

    public function invalidateForUser(int $userId, string $now): void
    {
        $this->db->execute('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [$now, $userId]);
    }
}
